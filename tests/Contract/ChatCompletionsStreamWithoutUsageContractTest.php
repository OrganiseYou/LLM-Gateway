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
 * A small server of the chat-completions format that streams natively, is not asked for the usage of a stream and
 * reports none there: a whole answer tells its usage, a stream does not, and the gateway then reports none either.
 */
final class ChatCompletionsStreamWithoutUsageContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            ChatCompletions::preset(['tool_calling' => 'none', 'structured_output' => 'emulated', 'stream_usage' => 'unasked', 'max_tokens_field' => 'max_tokens']),
            new BearerKey(self::MARKER_KEY),
            new ChatCompletionsFixtures(ChatCompletionsFixtures::STREAM_WITHOUT_USAGE),
            ChatCompletions::MODEL,
            $retry,
        );
    }

    protected function reportsUsage(bool $inAStream): bool
    {
        return ! $inAStream;
    }
}
