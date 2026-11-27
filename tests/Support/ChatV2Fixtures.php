<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Testing\AdapterFixtures;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\NotApplicable;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use RuntimeException;

/**
 * The hand-written responses of the Chat v2 format, for the shared test set. They are under
 * tests/fixtures/cohere; the README there says what each file shows.
 */
final readonly class ChatV2Fixtures implements AdapterFixtures
{
    /** The events after which a server sends what it has: each carries a piece of the answer. */
    private const array PIECES = ['event: content-delta', 'event: tool-plan-delta', 'event: tool-call-delta'];

    public function answer(ContractFixture $fixture): HttpResponse|NotApplicable
    {
        $name = self::name($fixture);
        if ($name instanceof NotApplicable) {
            return $name;
        }

        return new HttpResponse(200, ['Content-Type' => 'application/json'], self::read($name . '.json'));
    }

    public function stream(ContractFixture $fixture): array|NotApplicable
    {
        $name = self::name($fixture);
        if ($name instanceof NotApplicable) {
            return $name;
        }

        return self::chunks(self::read($name . '.sse'));
    }

    public function failed(int $status, string $echo): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => 'application/json'], self::filled('failed.json', $echo));
    }

    public function retryForbidden(HttpResponse $failed): NotApplicable
    {
        return new NotApplicable('the provider file documents no header and no field that forbids a retry');
    }

    public function errorInBody(string $echo): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], self::filled('error_in_body.json', $echo));
    }

    public function errorInStream(string $echo): array
    {
        return [self::filled('error_in_stream.sse', $echo)];
    }

    public function requestTheFormatCannotCarry(Request $request): NotApplicable
    {
        return new NotApplicable('the provider file states no rule for a tool name and no range the neutral request can leave: the format carries every request the contract allows');
    }

    /**
     * The file of a case without its ending, or NotApplicable where the format has no such answer.
     */
    private static function name(ContractFixture $fixture): string|NotApplicable
    {
        return match ($fixture) {
            ContractFixture::PlainAnswer => 'plain_answer',
            ContractFixture::ToolCall, ContractFixture::ToolCallBesideResponseFormat => 'tool_call',
            ContractFixture::ParallelToolCalls => 'parallel_tool_calls',
            ContractFixture::GarbledToolCall => 'garbled_tool_call',
            ContractFixture::StructuredValid => 'structured_valid',
            ContractFixture::StructuredInvalid => 'structured_invalid',
            ContractFixture::CutOff => 'cut_off',
            ContractFixture::EmptyAnswer => 'empty_answer',
            ContractFixture::ToolCallWithOpaqueData => 'tool_call_with_opaque_data',
            ContractFixture::Refused => new NotApplicable('the provider file lists no finish reason and no field for an answer the model declined to give: it declines in ordinary text'),
            ContractFixture::Withheld => new NotApplicable('the provider file lists no finish reason for output the provider withheld'),
        };
    }

    /**
     * The events of a stream, cut into the chunks a server sends: a chunk ends after an event that carries a piece
     * of the answer, so the events before the first piece travel with it, and the events that end the stream are
     * the last chunk.
     *
     * @return list<string>
     */
    private static function chunks(string $events): array
    {
        $parts = preg_split('/(?<=\n\n)/', $events, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            throw new RuntimeException('The events of a fixture could not be cut.');
        }

        $chunks = [];
        $chunk = '';
        foreach ($parts as $event) {
            $chunk .= $event;
            if (in_array(strtok($event, "\n"), self::PIECES, true)) {
                $chunks[] = $chunk;
                $chunk = '';
            }
        }
        if ($chunk !== '') {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    private static function filled(string $file, string $echo): string
    {
        // A text inside JSON: written as JSON writes a string, without its quotes.
        $written = substr((string) json_encode($echo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);

        return str_replace('{{echo}}', $written, self::read($file));
    }

    private static function read(string $file): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/fixtures/cohere/' . $file);
        if ($content === false) {
            throw new RuntimeException('The fixture ' . $file . ' could not be read.');
        }

        return $content;
    }
}
