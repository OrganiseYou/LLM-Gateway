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
 * A server of the chat-completions format with the defaults of the format: everything native, the usage of a stream in
 * its own chunk, an error body with an error object.
 */
final class ChatCompletionsContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(ChatCompletions::preset(), new BearerKey(self::MARKER_KEY), new ChatCompletionsFixtures(ChatCompletionsFixtures::PLAIN), ChatCompletions::MODEL, $retry);
    }
}
