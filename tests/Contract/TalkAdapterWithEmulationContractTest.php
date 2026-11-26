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
 * The same outside adapter for a server that takes no schema and does not stream: the structured answer is asked
 * for in the system text and the stream is the whole answer as one text event.
 */
final class TalkAdapterWithEmulationContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(Talk::preset(['structured' => 'emulated', 'streams' => false, 'max_tool_name' => 32]), new BearerKey(self::MARKER_KEY), new TalkFixtures(32), 'model-a', $retry);
    }
}
