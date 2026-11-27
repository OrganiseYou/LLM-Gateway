<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\Credentials\NoCredentials;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Preset\PresetField;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\TalkAdapter;

/**
 * @param list<PresetField> $fields
 */
function factoryPreset(BaseUrlRule $rule, ?string $defaultBaseUrl, array $fields = []): Preset
{
    return new Preset(
        id: 'made-up',
        label: 'Made up',
        description: 'A made-up server.',
        adapter: TalkAdapter::class,
        baseUrlRule: $rule,
        defaultBaseUrl: $defaultBaseUrl,
        credentialKinds: [CredentialKind::BearerKey, CredentialKind::None],
        keyHeader: null,
        fields: $fields,
        dataLocation: 'not stated by the provider',
        dataLocationSource: null,
        sendsNoStoreOption: false,
    );
}

/**
 * @param array<string, string> $settings
 */
function factoryConfig(?string $baseUrl = null, array $settings = []): GatewayConfig
{
    return new GatewayConfig(RetryPolicy::none(), $baseUrl, $settings);
}

/**
 * @param callable(): mixed $build
 */
function notConfigured(callable $build): GatewayException
{
    try {
        $build();
    } catch (GatewayException $refusal) {
        expect($refusal->reason)->toBe(ErrorReason::NotConfigured)
            ->and($refusal->attempts)->toBe(0)
            ->and($refusal->httpStatus)->toBeNull()
            ->and($refusal->traceId)->toMatch('/^[0-9a-f]{32}$/D');

        return $refusal;
    }

    throw new LogicException('The gateway was built.');
}

describe('GatewayFactory::create', function (): void {
    it('builds the adapter the preset names and sends nothing', function (): void {
        $transport = new FakeTransport();
        $gateway = GatewayFactory::create(factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test/v1'), new BearerKey('key-1'), $transport, factoryConfig());

        expect($gateway)->toBeInstanceOf(Gateway::class)
            ->and($gateway)->toBeInstanceOf(TalkAdapter::class)
            ->and($transport->requests())->toBe([]);
    });

    it('fails as not_configured when the credential kind is not one the preset accepts', function (): void {
        $transport = new FakeTransport();
        $refusal = notConfigured(fn() => GatewayFactory::create(
            new Preset('made-up', 'Made up', '', TalkAdapter::class, BaseUrlRule::Fixed, 'https://api.example.test', [CredentialKind::BearerKey], 'X-Key', [], '', null, false),
            new HeaderKey('key-1'),
            $transport,
            factoryConfig(),
        ));

        expect($refusal->detail)->toBe('the kind of the credentials is not one this preset accepts')
            ->and($transport->requests())->toBe([]);
    });

    it('fails as not_configured for credentials of a class the library does not know', function (): void {
        $own = new class implements Credentials {
            public function kind(): CredentialKind
            {
                return CredentialKind::BearerKey;
            }

            public function secrets(): array
            {
                return [new Secret('own-key')];
            }
        };

        $refusal = notConfigured(fn() => GatewayFactory::create(factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test'), $own, new FakeTransport(), factoryConfig()));

        expect($refusal->detail)->toBe('the kind of the credentials is not one this preset accepts');
    });

    it('fails as not_configured when the customer must give a base URL and none is given', function (): void {
        $refusal = notConfigured(fn() => GatewayFactory::create(factoryPreset(BaseUrlRule::CustomerMust, null), new NoCredentials(), new FakeTransport(), factoryConfig()));

        expect($refusal->detail)->toBe('this preset has no address of its own: a base URL is required');
    });

    it('fails as not_configured when the address is fixed and a base URL is given', function (): void {
        $refusal = notConfigured(fn() => GatewayFactory::create(
            factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test'),
            new NoCredentials(),
            new FakeTransport(),
            factoryConfig('https://other.example.test'),
        ));

        expect($refusal->detail)->toBe('this preset has a fixed address: a base URL cannot be given');
    });

    it('fails as not_configured when a required field has no value, and names the key', function (array $settings): void {
        $preset = factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test', [new PresetField('api_version', 'API version', true)]);
        $refusal = notConfigured(fn() => GatewayFactory::create($preset, new NoCredentials(), new FakeTransport(), factoryConfig(null, $settings)));

        expect($refusal->detail)->toBe('the setting api_version is required');
    })->with([
        'absent' => [[]],
        'empty' => [['api_version' => '']],
    ]);

    it('fails as not_configured when a value does not match the pattern of its field, and names the key, never the value', function (): void {
        $preset = factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test', [new PresetField('api_version', 'API version', false, '[0-9]{4}-[0-9]{2}')]);
        $refusal = notConfigured(fn() => GatewayFactory::create($preset, new NoCredentials(), new FakeTransport(), factoryConfig(null, ['api_version' => 'latest-of-all'])));

        expect($refusal->detail)->toBe('the setting api_version does not have the form this preset asks for')
            ->and($refusal->getMessage())->not->toContain('latest-of-all');
    });

    it('fails as not_configured when the settings hold a key that is not a field of the preset', function (string $key, string $detail): void {
        $refusal = notConfigured(fn() => GatewayFactory::create(
            factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test'),
            new NoCredentials(),
            new FakeTransport(),
            factoryConfig(null, [$key => 'value-1']),
        ));

        expect($refusal->detail)->toBe($detail)
            ->and($refusal->getMessage())->not->toContain('value-1');
    })->with([
        'a key that looks like a field key is named' => ['region', 'the setting region is not a field of this preset'],
        'any other text is not copied' => ["Region of\nthe customer", 'the settings hold a key that is not a field of this preset'],
    ]);

    it('fails as not_configured when a setting that is sent as a header is not visible ASCII, and names the key, never the value', function (string $value): void {
        $preset = factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test', [new PresetField('workspace', 'Workspace', false, '(?s).+', 'X-Workspace')]);
        $refusal = notConfigured(fn() => GatewayFactory::create($preset, new NoCredentials(), new FakeTransport(), factoryConfig(null, ['workspace' => $value])));

        expect($refusal->detail)->toBe('the setting workspace is sent as a header and must hold only visible ASCII characters')
            ->and(str_contains($refusal->getMessage(), $value))->toBeFalse();
    })->with([
        'a space' => ['team one'],
        'a letter outside ASCII' => ['tëam'],
        'a line break and a second header' => ["team\r\nHost: other.example.org"],
    ]);

    it('lets a setting that is sent as a header pass when it is visible ASCII, and one that is not sent as a header whatever its characters', function (): void {
        $preset = factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test', [
            new PresetField('workspace', 'Workspace', false, '.+', 'X-Workspace'),
            new PresetField('note', 'Note', false, '.+'),
        ]);

        expect(GatewayFactory::resolveBaseUrl($preset, factoryConfig(null, ['workspace' => 'team-1', 'note' => 'tëam one'])))->toBe('https://api.example.test');
    });

    it('lets an optional field without a value pass', function (): void {
        $preset = factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test', [new PresetField('api_version', 'API version', false, '[0-9]+')]);

        expect(GatewayFactory::create($preset, new NoCredentials(), new FakeTransport(), factoryConfig()))->toBeInstanceOf(Gateway::class);
    });

    it('checks a gateway that is built without the factory the same way', function (): void {
        $refusal = notConfigured(fn() => new TalkAdapter(factoryPreset(BaseUrlRule::CustomerMust, null), factoryConfig(), new NoCredentials(), new FakeTransport()));

        expect($refusal->detail)->toBe('this preset has no address of its own: a base URL is required');
    });

    it('keeps no state: it has only static methods and no static property', function (): void {
        $reflection = new ReflectionClass(GatewayFactory::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->getStaticProperties())->toBe([])
            ->and($reflection->getProperties())->toBe([]);
    });
});

describe('GatewayFactory::resolveBaseUrl', function (): void {
    it('gives the default of the preset', function (): void {
        expect(GatewayFactory::resolveBaseUrl(factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test/v1'), factoryConfig()))
            ->toBe('https://api.example.test/v1');
    });

    it('gives the base URL of the host exactly as given, when the customer may or must give one', function (BaseUrlRule $rule, ?string $default): void {
        expect(GatewayFactory::resolveBaseUrl(factoryPreset($rule, $default), factoryConfig('http://my_service:8080/v1/')))
            ->toBe('http://my_service:8080/v1/');
    })->with([
        'may' => [BaseUrlRule::CustomerMay, 'https://api.example.test/v1'],
        'must' => [BaseUrlRule::CustomerMust, null],
    ]);

    it('fills the placeholders of the default from the settings', function (): void {
        $preset = factoryPreset(BaseUrlRule::Fixed, 'https://{region}-talk.example.test/projects/{project}/{region}', [
            new PresetField('region', 'Region', true, '[a-z]+-[a-z]+[0-9]'),
            new PresetField('project', 'Project', true, '[a-z][a-z0-9-]+'),
        ]);

        expect(GatewayFactory::resolveBaseUrl($preset, factoryConfig(null, ['region' => 'europe-west4', 'project' => 'my-project'])))
            ->toBe('https://europe-west4-talk.example.test/projects/my-project/europe-west4');
    });

    it('fails as not_configured when a field of a placeholder has no value', function (): void {
        $preset = factoryPreset(BaseUrlRule::Fixed, 'https://{region}.example.test', [new PresetField('region', 'Region', false, '[a-z]+')]);
        $refusal = notConfigured(fn() => GatewayFactory::resolveBaseUrl($preset, factoryConfig()));

        expect($refusal->detail)->toBe('the setting region is needed for the address');
    });

    it('never lets a setting change the host, whatever the pattern of its field allows', function (string $value): void {
        $preset = factoryPreset(BaseUrlRule::Fixed, 'https://{resource}.example.test/v1/{resource}/models', [new PresetField('resource', 'Resource', true, '.+')]);
        $refusal = notConfigured(fn() => GatewayFactory::resolveBaseUrl($preset, factoryConfig(null, ['resource' => $value])));

        expect($refusal->detail)->toBe('the setting resource holds a character that cannot be part of the address')
            ->and($refusal->getMessage())->not->toContain($value);
    })->with([
        'a slash ends the host' => ['other.example.org/'],
        'a question mark starts a query' => ['other.example.org?'],
        'a hash starts a fragment' => ['other.example.org#'],
        'an at sign makes user info' => ['user@other'],
        'a colon starts a port' => ['other.example.org:8443'],
        'a backslash' => ['other.example.org\\'],
        'a percent sign' => ['other%2eexample'],
        'a space' => ['other example'],
        'a dot alone' => ['.'],
        'two dots' => ['..'],
    ]);

    it('fails as not_configured when the filled address is no valid address', function (): void {
        $preset = factoryPreset(BaseUrlRule::Fixed, 'https://{resource}.example.test/v1', [new PresetField('resource', 'Resource', true, '.+')]);
        $refusal = notConfigured(fn() => GatewayFactory::resolveBaseUrl($preset, factoryConfig(null, ['resource' => '-']))) ;

        expect($refusal->detail)->toBe('the settings do not give a valid address');
    });

    it('fails the same way as create for a missing base URL, a base URL on a fixed preset and a wrong setting', function (): void {
        expect(notConfigured(fn() => GatewayFactory::resolveBaseUrl(factoryPreset(BaseUrlRule::CustomerMust, null), factoryConfig()))->detail)
            ->toBe('this preset has no address of its own: a base URL is required')
            ->and(notConfigured(fn() => GatewayFactory::resolveBaseUrl(factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test'), factoryConfig('https://other.example.test')))->detail)
            ->toBe('this preset has a fixed address: a base URL cannot be given')
            ->and(notConfigured(fn() => GatewayFactory::resolveBaseUrl(factoryPreset(BaseUrlRule::Fixed, 'https://api.example.test'), factoryConfig(null, ['extra' => 'x'])))->detail)
            ->toBe('the setting extra is not a field of this preset');
    });
});
