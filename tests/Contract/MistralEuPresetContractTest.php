<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Contract;

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Presets\MistralEu;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletionsFixtures;

/**
 * The shared test set on a gateway built from the definition under tests/Presets (decision G140), with the
 * fixtures of the content-entries set, which were written to these options.
 */
final class MistralEuPresetContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            MistralEu::preset(),
            new BearerKey(self::MARKER_KEY),
            new ChatCompletionsFixtures(ChatCompletionsFixtures::CONTENT_ENTRIES),
            'example-large-1',
            $retry,
        );
    }
}
