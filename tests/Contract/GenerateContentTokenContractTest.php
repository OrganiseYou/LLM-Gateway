<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Contract;

use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Support\FixedTokenSupplier;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContentFixtures;

/**
 * A server of the generate-content format on the way in with a token: the options rows 5 and 6 of the preset
 * entries set (docs/design/adapters.md, section 9), a token from a supplier of the host, and the setting of the
 * preset that travels as a header of its own. Its fixtures are in the shapes that way in documents: a tool call
 * comes without an id, and a finish reason is written with its prefix.
 */
final class GenerateContentTokenContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            GenerateContent::preset(GenerateContent::TOKEN_ENTRY),
            new BearerToken(new FixedTokenSupplier(self::MARKER_KEY)),
            new GenerateContentFixtures(GenerateContentFixtures::TOKEN),
            GenerateContent::MODEL,
            $retry,
            ['quota_project' => 'example-project'],
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
