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
 * The hand-written responses of the made-up "talk" format, for the shared test set: what a host project writes for
 * its own adapter. The files are under tests/fixtures/talk.
 */
final readonly class TalkFixtures implements AdapterFixtures
{
    /**
     * @param int $longestToolName the option "max_tool_name" of the preset under test; 0: the server has no limit
     */
    public function __construct(private int $longestToolName = 0) {}

    public function answer(ContractFixture $fixture): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], self::read(self::name($fixture) . '.json'));
    }

    public function stream(ContractFixture $fixture): ?array
    {
        // One event per chunk: an event ends with a blank line.
        $chunks = preg_split('/(?<=\n\n)/', self::read(self::name($fixture) . '.sse'), -1, PREG_SPLIT_NO_EMPTY);

        return $chunks === false ? null : $chunks;
    }

    public function failed(int $status, string $echo): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => 'application/json'], str_replace('{{echo}}', $echo, self::read('failed.json')));
    }

    public function errorInBody(string $echo): HttpResponse
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], str_replace('{{echo}}', $echo, self::read('error_in_body.json')));
    }

    public function errorInStream(string $echo): array
    {
        return [str_replace('{{echo}}', $echo, self::read('error_in_stream.sse'))];
    }

    public function requestTheFormatCannotCarry(Request $request): Request|NotApplicable
    {
        if ($this->longestToolName < 1 || $request->tools === []) {
            return new NotApplicable('the only value this format cannot always carry is a long tool name, and this server sets no limit on it or takes no tools');
        }

        $tools = $request->tools;
        $tools[] = new ToolDefinition(str_repeat('n', $this->longestToolName + 1), 'A tool with a name that is too long.', $tools[0]->parameters);

        return $request->withTools($tools);
    }

    private static function name(ContractFixture $fixture): string
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
        };
    }

    private static function read(string $file): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/fixtures/talk/' . $file);
        if ($content === false) {
            throw new RuntimeException('The fixture ' . $file . ' could not be read.');
        }

        return $content;
    }
}
