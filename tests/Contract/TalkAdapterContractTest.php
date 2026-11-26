<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Contract;

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Tests\Support\TalkFixtures;

/**
 * An adapter and a preset that are defined outside the library, under tests/, pass the shared test set: a server of the
 * made-up "talk" format that can do everything natively.
 */
final class TalkAdapterContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(Talk::preset(['max_tool_name' => 32]), new BearerKey(self::MARKER_KEY), new TalkFixtures(32), 'model-a', $retry);
    }
}
