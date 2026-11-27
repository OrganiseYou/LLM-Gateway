<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Contract;

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\MessagesFixtures;

/**
 * A server of the Messages format with the defaults of the format: every capability native, and a temperature is
 * taken. Its preset sets only what every preset on this adapter must set: the version of the API (the earlier of the
 * two the provider file records, on which a request may carry a temperature) and the maximum of output tokens for a
 * request that gives none. The key travels as a bearer.
 */
final class MessagesContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            Messages::preset(Messages::EARLIER_VERSION),
            new BearerKey(self::MARKER_KEY),
            new MessagesFixtures(),
            Messages::MODEL,
            $retry,
        );
    }
}
