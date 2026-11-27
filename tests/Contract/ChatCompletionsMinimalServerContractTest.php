<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Contract;

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletionsFixtures;

/**
 * A small server of the chat-completions format: no tool calling, a structured answer asked for in the system text, the
 * stream as the events of one whole answer, no usage asked for in a stream, and a flat error body.
 */
final class ChatCompletionsMinimalServerContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(ChatCompletions::preset(['tool_calling' => 'none', 'structured_output' => 'emulated', 'streaming' => 'emulated', 'stream_usage' => 'unasked', 'max_tokens_field' => 'max_tokens']), new BearerKey(self::MARKER_KEY), new ChatCompletionsFixtures(ChatCompletionsFixtures::MINIMAL), ChatCompletions::MODEL, $retry);
    }
}
