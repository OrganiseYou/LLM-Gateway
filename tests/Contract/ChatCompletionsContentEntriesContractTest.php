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
 * A server of the chat-completions format whose answer holds its reasoning as an entry of the content and wants the
 * whole content back with that answer. Its preset sets the five options of row 13 of the preset entries
 * (docs/design/adapters.md, section 9), all together: the other name of the maximum of output tokens, the other
 * word for "must call some tool", the name of the tool on a tool result, the content entries as the carrier of
 * reasoning, and a stream that is not asked for its usage and reports it on its last chunk all the same.
 */
final class ChatCompletionsContentEntriesContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            ChatCompletions::preset(ChatCompletions::CONTENT_ENTRIES_SERVER),
            new BearerKey(self::MARKER_KEY),
            new ChatCompletionsFixtures(ChatCompletionsFixtures::CONTENT_ENTRIES),
            ChatCompletions::MODEL,
            $retry,
        );
    }
}
