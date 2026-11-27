<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Testing\AdapterFixtures;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\NotApplicable;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use RuntimeException;

/**
 * The hand-written responses of the chat-completions format, for the shared test set. There are five sets, each
 * in its own folder under tests/fixtures/openai-compatible; its README says what each set shows.
 */
final readonly class ChatCompletionsFixtures implements AdapterFixtures
{
    /** The plain shape with the extra fields the provider files record; the usage of a stream in its own chunk. */
    public const string PLAIN = 'default';

    /** A small server: few fields, no tool calls, no stream, a flat error body. */
    public const string MINIMAL = 'minimal';

    /** A server that writes its reasoning beside the answer and wants it back with that answer. */
    public const string REASONING = 'reasoning';

    /** A small server that streams, is not asked for the usage of a stream and reports none there. */
    public const string STREAM_WITHOUT_USAGE = 'stream-without-usage';

    /**
     * A server whose answer holds its reasoning as an entry of the content and wants the whole content back; its
     * tool-call arguments are a string in one answer and an object in another.
     */
    public const string CONTENT_ENTRIES = 'content-entries';

    /**
     * @param string $set one of the five constants
     */
    public function __construct(private string $set = self::PLAIN)
    {
        if (! in_array($set, [self::PLAIN, self::MINIMAL, self::REASONING, self::STREAM_WITHOUT_USAGE, self::CONTENT_ENTRIES], true)) {
            throw new RuntimeException('There is no such set of fixtures.');
        }
    }

    public function answer(ContractFixture $fixture): HttpResponse|NotApplicable|null
    {
        $name = $this->name($fixture);
        if ($name instanceof NotApplicable || $name === null) {
            return $name;
        }

        return new HttpResponse(200, ['Content-Type' => 'application/json'], $this->read($name . '.json'));
    }

    public function stream(ContractFixture $fixture): array|NotApplicable|null
    {
        $name = $this->name($fixture);
        if ($name instanceof NotApplicable || $name === null || $this->set === self::MINIMAL) {
            return $name instanceof NotApplicable ? $name : null;
        }

        return self::chunks($this->read($name . '.sse'));
    }

    public function failed(int $status, string $echo): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => 'application/json'], $this->filled('failed.json', $echo, $status));
    }

    public function retryForbidden(HttpResponse $failed): NotApplicable
    {
        return new NotApplicable('no provider file documents a header or a field of this format that says a failed request must not be tried again');
    }

    public function errorInBody(string $echo): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], $this->filled('error_in_body.json', $echo, 500));
    }

    public function errorInStream(string $echo): array|NotApplicable
    {
        if ($this->set === self::MINIMAL) {
            return new NotApplicable('this server does not stream');
        }

        return [$this->filled('error_in_stream.sse', $echo, 500)];
    }

    public function requestTheFormatCannotCarry(Request $request): Request|NotApplicable
    {
        if ($request->tools === []) {
            return new NotApplicable('the only value this format cannot carry is a tool name outside its rule, and this server takes no tools');
        }

        // Letters only, and one more than the 64 the format allows.
        $tools = $request->tools;
        $tools[] = new ToolDefinition(str_repeat('n', 65), 'A tool with a name that is too long.', $tools[0]->parameters);

        return $request->withTools($tools);
    }

    /**
     * The file of a case without its ending; NotApplicable where this set has no such answer; null where the server
     * of this set cannot give it at all.
     */
    private function name(ContractFixture $fixture): string|NotApplicable|null
    {
        $withTools = in_array($this->set, [self::PLAIN, self::REASONING, self::CONTENT_ENTRIES], true);

        return match ($fixture) {
            ContractFixture::PlainAnswer => 'plain_answer',
            ContractFixture::ToolCall, ContractFixture::ToolCallBesideResponseFormat => $withTools ? 'tool_call' : null,
            ContractFixture::ParallelToolCalls => $withTools ? 'parallel_tool_calls' : null,
            ContractFixture::GarbledToolCall => $withTools ? 'garbled_tool_call' : null,
            ContractFixture::StructuredValid => 'structured_valid',
            ContractFixture::StructuredInvalid => 'structured_invalid',
            ContractFixture::CutOff => 'cut_off',
            ContractFixture::EmptyAnswer => 'empty_answer',
            ContractFixture::ToolCallWithOpaqueData => $this->set === self::REASONING || $this->set === self::CONTENT_ENTRIES
                ? 'tool_call_with_opaque_data'
                : new NotApplicable('this server asks nothing back with an answer: its preset sets no reasoning carrier'),
            ContractFixture::Refused => $this->set === self::PLAIN
                ? 'refused'
                : new NotApplicable('the servers this set stands for document no field for a refusal of the model'),
            ContractFixture::Withheld => $this->set === self::PLAIN
                ? 'withheld'
                : new NotApplicable('the servers this set stands for document no finish reason for withheld output'),
        };
    }

    /**
     * One event per chunk: an event ends with a blank line.
     *
     * @return list<string>|null
     */
    private static function chunks(string $events): ?array
    {
        $chunks = preg_split('/(?<=\n\n)/', $events, -1, PREG_SPLIT_NO_EMPTY);

        return $chunks === false ? null : $chunks;
    }

    private function filled(string $file, string $echo, int $status): string
    {
        // A text inside JSON: written as JSON writes a string, without its quotes.
        $written = substr((string) json_encode($echo, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1);

        return str_replace(['"{{status}}"', '{{echo}}'], [(string) $status, $written], $this->read($file));
    }

    private function read(string $file): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/fixtures/openai-compatible/' . $this->set . '/' . $file);
        if ($content === false) {
            throw new RuntimeException('The fixture ' . $file . ' could not be read.');
        }

        return $content;
    }
}
