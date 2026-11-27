<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Contract;

use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Presets\Claude;
use OrganiseYou\LlmGateway\Tests\Support\MessagesFixtures;

/**
 * The shared test set on a gateway built from the definition under tests/Presets (decision G140), with the
 * fixtures of the Messages adapter. The key travels in the header of the entry and the workspace as its own header.
 */
final class ClaudePresetContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            Claude::preset(),
            new HeaderKey(self::MARKER_KEY),
            new MessagesFixtures(),
            'example-messages-1',
            $retry,
            ['workspace_id' => 'wrkspc_example01'],
        );
    }
}
