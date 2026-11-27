<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Contract;

use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Testing\FakeGatewaySubject;

/**
 * The fake gateway, told that its model has no tools, no structured answers, no streaming of its own, no model
 * list and takes no temperature, passes the shared test set too: it refuses what a real gateway would refuse.
 */
final class FakeGatewayWithFewCapabilitiesContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new FakeGatewaySubject(new Capabilities(
            toolCalling: Support::None,
            forcedToolCall: Support::None,
            parallelToolCalls: Support::None,
            structuredOutput: Support::None,
            toolsWithStructuredOutput: Support::None,
            streaming: Support::Emulated,
            modelListing: Support::None,
            contextLength: null,
            temperature: Support::None,
        ));
    }
}
