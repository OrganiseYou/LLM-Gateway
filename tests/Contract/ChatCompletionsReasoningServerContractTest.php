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
 * A server of the chat-completions format that writes its reasoning beside an answer and wants it back with that
 * answer, takes no temperature, and is made to call one named tool by being given only that tool.
 */
final class ChatCompletionsReasoningServerContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(ChatCompletions::preset(['reasoning_carrier' => 'message_field', 'temperature' => 'not_accepted', 'named_tool_choice' => 'required_single']), new BearerKey(self::MARKER_KEY), new ChatCompletionsFixtures(ChatCompletionsFixtures::REASONING), ChatCompletions::MODEL, $retry);
    }
}
