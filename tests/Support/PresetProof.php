<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Testing\AdapterFixtures;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Transport\AttemptObserver;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\NullAttemptObserver;
use ReflectionClass;
use RuntimeException;
use SensitiveParameter;

/**
 * What the tests of the preset definitions under tests/Presets share: a gateway built from a definition on the fake
 * transport, the fixtures of its adapter as the answers, and the capabilities as a list of fields.
 */
final class PresetProof
{
    /**
     * @param array<string, string> $settings
     */
    public static function gateway(
        Preset $preset,
        FakeTransport $transport,
        #[SensitiveParameter]
        Credentials $credentials,
        array $settings = [],
        ?RetryPolicy $retry = null,
        ?AttemptObserver $observer = null,
    ): Gateway {
        return GatewayFactory::create(
            $preset,
            $credentials,
            $transport,
            new GatewayConfig($retry ?? RetryPolicy::none(), null, $settings, $observer ?? new NullAttemptObserver(), new FakeClock()),
        );
    }

    /**
     * The whole answer of the adapter's own fixture for this case.
     */
    public static function fixture(AdapterFixtures $fixtures, ContractFixture $case): HttpResponse
    {
        $response = $fixtures->answer($case);
        if (! $response instanceof HttpResponse) {
            throw new RuntimeException('The fixtures have no whole answer for ' . $case->value . '.');
        }

        return $response;
    }

    /**
     * Every field of the capabilities by its name, so that two of them are compared field by field and a failure
     * names the field.
     *
     * @return array<string, mixed>
     */
    public static function fields(Capabilities $capabilities): array
    {
        $fields = [];
        foreach ((new ReflectionClass($capabilities))->getProperties() as $property) {
            $fields[$property->getName()] = $property->getValue($capabilities);
        }

        return $fields;
    }

    /**
     * The address of every request the transport received.
     *
     * @return list<string>
     */
    public static function urls(FakeTransport $transport): array
    {
        $urls = [];
        foreach ($transport->requests() as $request) {
            $urls[] = $request->url;
        }

        return $urls;
    }

    /**
     * The host of an address, in small letters.
     */
    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * The provider file under docs/providers, as text.
     */
    public static function providerFile(string $name): string
    {
        $text = file_get_contents(dirname(__DIR__, 2) . '/docs/providers/' . $name . '.md');
        if ($text === false) {
            throw new RuntimeException('The provider file ' . $name . ' could not be read.');
        }

        return $text;
    }
}
