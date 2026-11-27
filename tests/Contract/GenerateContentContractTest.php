<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Contract;

use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContentFixtures;

/**
 * A server of the generate-content format on the way in with a key: the options row 4 of the preset entries sets
 * (docs/design/adapters.md, section 9), and the key in the header of the preset. Its fixtures are in the shapes
 * that way in documents: every tool call has an id, and a finish reason is the bare word.
 */
final class GenerateContentContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            GenerateContent::preset(GenerateContent::KEY_ENTRY),
            new HeaderKey(self::MARKER_KEY),
            new GenerateContentFixtures(GenerateContentFixtures::KEY_IN_A_HEADER),
            GenerateContent::MODEL,
            $retry,
        );
    }

    /**
     * The format gives a tool call an id or none, and the neutral call cannot say which: the parts of every answer
     * with a tool call travel in its opaque part.
     */
    protected function keepsDataWithEveryToolCall(): bool
    {
        return true;
    }
}
