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
 * The same outside adapter for a server without tools, without structured answers, without streaming, without a
 * model list and whose model takes no temperature: what it cannot do is refused, and the scenarios for it are skipped with that reason.
 */
final class TalkAdapterWithFewCapabilitiesContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(Talk::preset(['tools' => 'none', 'structured' => 'none', 'streams' => false, 'lists_models' => false, 'temperature' => 'none']), new BearerKey(self::MARKER_KEY), new TalkFixtures(), 'model-a', $retry);
    }
}
