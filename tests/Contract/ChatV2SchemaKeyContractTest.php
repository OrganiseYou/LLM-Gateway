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
 * A server of the Chat v2 format on a preset with the other value of the one option that is not confirmed:
 * `schema_key: schema`, the name the provider's guide gives where its reference gives `json_schema`. The option
 * changes one name in a request and no shape of a response, so this class runs on the same fixture files; the
 * setting that travels as a header is filled in too.
 */
final class ChatV2SchemaKeyContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            ChatV2::preset(['schema_key' => 'schema']),
            new BearerKey(self::MARKER_KEY),
            new ChatV2Fixtures(),
            ChatV2::MODEL,
            $retry,
            ['workspace' => 'example-host'],
        );
    }
}
