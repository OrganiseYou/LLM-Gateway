<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Contract;

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2Fixtures;

/**
 * A server of the Chat v2 format with the defaults of the format, as row 8 of the preset entries has them
 * (docs/design/adapters.md, section 9: no option): one named tool and tools beside a response format are
 * emulated, everything else is native, and the schema of a response format stands under `json_schema`. The key
 * travels as a bearer.
 */
final class ChatV2ContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            ChatV2::preset(),
            new BearerKey(self::MARKER_KEY),
            new ChatV2Fixtures(),
            ChatV2::MODEL,
            $retry,
        );
    }
}
