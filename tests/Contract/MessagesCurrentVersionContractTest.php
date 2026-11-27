<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Contract;

use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\MessagesFixtures;

/**
 * A server of the Messages format with the options row 3 of the preset entries sets (docs/design/adapters.md,
 * section 9): the current version of the API, the maximum of output tokens for a request that gives none, and no
 * temperature. The key travels in the header of the preset, and the workspace the customer filled in travels as a
 * header of its own. The responses are those of the other set: these options change no shape.
 */
final class MessagesCurrentVersionContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            Messages::preset(Messages::CURRENT_VERSION),
            new HeaderKey(self::MARKER_KEY),
            new MessagesFixtures(),
            Messages::MODEL,
            $retry,
            ['workspace_id' => 'wrkspc_example01'],
        );
    }
}
