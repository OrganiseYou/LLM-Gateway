<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Preset\PresetField;
use OrganiseYou\LlmGateway\Tests\Support\TalkAdapter;
use OrganiseYou\LlmGateway\Tests\Support\UnfinishedAdapter;

/**
 * A valid preset with some of its values replaced.
 *
 * @param array<string, mixed> $with
 */
function presetWith(array $with = []): Preset
{
    return new Preset(...untyped([
        'id' => 'made-up',
        'label' => 'Made up',
        'description' => 'A made-up server.',
        'adapter' => TalkAdapter::class,
        'baseUrlRule' => BaseUrlRule::CustomerMay,
        'defaultBaseUrl' => 'https://api.example.test/v1',
        'credentialKinds' => [CredentialKind::BearerKey],
        'keyHeader' => null,
        'fields' => [],
        'dataLocation' => 'not stated by the provider',
        'dataLocationSource' => null,
        'sendsNoStoreOption' => false,
        'adapterOptions' => [],
        ...$with,
    ]));
}

describe('BaseUrlRule', function (): void {
    it('has the three rules of the design', function (): void {
        expect(array_map(fn(BaseUrlRule $rule): string => $rule->value, BaseUrlRule::cases()))
            ->toBe(['fixed', 'customer_may', 'customer_must']);
    });
});

describe('PresetField', function (): void {
    it('holds a key, a label, whether it is required and a pattern', function (): void {
        $field = new PresetField('api_version', 'API version', true, '[0-9]{4}-[0-9]{2}-[0-9]{2}');

        expect($field->key)->toBe('api_version')
            ->and($field->label)->toBe('API version')
            ->and($field->required)->toBeTrue()
            ->and($field->pattern)->toBe('[0-9]{4}-[0-9]{2}-[0-9]{2}')
            ->and((new PresetField('region', 'Region', false))->pattern)->toBeNull();
    });

    it('refuses a key that breaks the pattern', function (string $key): void {
        expect(fn() => new PresetField($key, 'Label', true))
            ->toThrow(InvalidArgumentException::class, 'The key of a field must match');
    })->with([
        'empty' => [''],
        'starts with a digit' => ['1st'],
        'an upper-case letter' => ['Region'],
        'a hyphen' => ['api-version'],
        'longer than 32' => [str_repeat('a', 33)],
        'a line break at the end' => ["region\n"],
    ]);

    it('refuses an empty label', function (): void {
        expect(fn() => new PresetField('region', '', true))
            ->toThrow(InvalidArgumentException::class, 'The label of a field must not be empty.');
    });

    it('refuses a pattern that is empty or cannot be compiled', function (string $pattern): void {
        expect(fn() => new PresetField('region', 'Region', true, $pattern))
            ->toThrow(InvalidArgumentException::class, 'The pattern of a field must be a regular expression');
    })->with([
        'empty' => [''],
        'an open group' => ['(a'],
        'an open class' => ['[a-z'],
        'the byte the library wraps it in' => ["a\x01b"],
    ]);

    it('accepts every value without a pattern', function (): void {
        expect((new PresetField('region', 'Region', false))->accepts('anything at all'))->toBeTrue();
    });

    it('accepts only a value that matches the pattern from its first to its last character', function (string $value, bool $accepted): void {
        expect((new PresetField('region', 'Region', true, '[a-z]+-[a-z]+[0-9]|eu'))->accepts($value))->toBe($accepted);
    })->with([
        'a match' => ['europe-west4', true],
        'the other branch' => ['eu', true],
        'text before the match' => ['x/europe-west4', false],
        'text after the match' => ['europe-west4.example.test/', false],
        'the other branch with text before it' => ['x-eu', false],
        'a line break after the match' => ["europe-west4\n", false],
        'empty' => ['', false],
    ]);
});

describe('Preset', function (): void {
    it('holds the data of a provider', function (): void {
        $field = new PresetField('region', 'Region', true, '[a-z0-9-]+');
        $preset = presetWith([
            'defaultBaseUrl' => 'https://{region}.example.test/v1',
            'credentialKinds' => [CredentialKind::HeaderKey, CredentialKind::None],
            'keyHeader' => 'X-Api-Key',
            'fields' => [$field],
            'dataLocation' => 'In the region the customer chooses.',
            'dataLocationSource' => 'https://docs.example.test/data',
            'sendsNoStoreOption' => true,
            'adapterOptions' => ['lists_models' => false, 'max_tool_name' => 64, 'flavour' => 'plain'],
        ]);

        expect($preset->id)->toBe('made-up')
            ->and($preset->label)->toBe('Made up')
            ->and($preset->description)->toBe('A made-up server.')
            ->and($preset->adapter)->toBe(TalkAdapter::class)
            ->and($preset->baseUrlRule)->toBe(BaseUrlRule::CustomerMay)
            ->and($preset->defaultBaseUrl)->toBe('https://{region}.example.test/v1')
            ->and($preset->credentialKinds)->toBe([CredentialKind::HeaderKey, CredentialKind::None])
            ->and($preset->keyHeader)->toBe('X-Api-Key')
            ->and($preset->fields)->toBe([$field])
            ->and($preset->dataLocation)->toBe('In the region the customer chooses.')
            ->and($preset->dataLocationSource)->toBe('https://docs.example.test/data')
            ->and($preset->sendsNoStoreOption)->toBeTrue()
            ->and($preset->adapterOptions)->toBe(['lists_models' => false, 'max_tool_name' => 64, 'flavour' => 'plain'])
            ->and($preset->placeholders())->toBe(['region']);
    });

    it('has a public constructor, so a host project can build its own preset', function (): void {
        expect((new ReflectionClass(Preset::class))->getConstructor()?->isPublic())->toBeTrue();
    });

    it('refuses an id that breaks the pattern', function (string $id): void {
        expect(fn() => presetWith(['id' => $id]))
            ->toThrow(InvalidArgumentException::class, 'The id of a preset must match');
    })->with([
        'empty' => [''],
        'starts with a digit' => ['1st'],
        'an upper-case letter' => ['Made'],
        'an underscore' => ['made_up'],
        'longer than 64' => [str_repeat('a', 65)],
        'a line break at the end' => ["made-up\n"],
    ]);

    it('refuses an empty label', function (): void {
        expect(fn() => presetWith(['label' => '']))
            ->toThrow(InvalidArgumentException::class, 'The label of a preset must not be empty.');
    });

    it('refuses an adapter that is not a class that extends the adapter base and can be built', function (string $adapter): void {
        expect(fn() => presetWith(['adapter' => $adapter]))
            ->toThrow(InvalidArgumentException::class, 'The adapter of a preset must be a class that extends the adapter base');
    })->with([
        'no class' => ['No\\Such\\Adapter'],
        'another class' => [stdClass::class],
        'the abstract base itself' => [AbstractAdapter::class],
        'a class that extends the base and cannot be built' => [UnfinishedAdapter::class],
    ]);

    it('refuses a missing default base URL while the rule is fixed or the customer may replace it', function (BaseUrlRule $rule): void {
        expect(fn() => presetWith(['baseUrlRule' => $rule, 'defaultBaseUrl' => null]))
            ->toThrow(InvalidArgumentException::class, 'A preset needs a default base URL unless the customer must give one.');
    })->with([[BaseUrlRule::Fixed], [BaseUrlRule::CustomerMay]]);

    it('refuses a default base URL while the customer must give the address', function (): void {
        expect(fn() => presetWith(['baseUrlRule' => BaseUrlRule::CustomerMust]))
            ->toThrow(InvalidArgumentException::class, 'A preset whose customer must give the base URL has no default base URL.');
    });

    it('takes no default base URL while the customer must give the address', function (): void {
        expect(presetWith(['baseUrlRule' => BaseUrlRule::CustomerMust, 'defaultBaseUrl' => null])->defaultBaseUrl)->toBeNull();
    });

    it('refuses a default base URL that is not an absolute http or https URL', function (string $url): void {
        expect(fn() => presetWith(['defaultBaseUrl' => $url]))
            ->toThrow(InvalidArgumentException::class, 'The default base URL must be an absolute http or https URL');
    })->with([
        'relative' => ['/v1'],
        'another scheme' => ['ftp://api.example.test'],
        'no host' => ['https:///v1'],
        'user info' => ['https://user@api.example.test/v1'],
        'a query' => ['https://api.example.test/v1?version=1'],
        'a fragment' => ['https://api.example.test/v1#top'],
        'a space' => ['https://api.example.test/v 1'],
        'an open brace' => ['https://api.example.test/{v1'],
    ]);

    it('refuses no credential kinds', function (): void {
        expect(fn() => presetWith(['credentialKinds' => []]))
            ->toThrow(InvalidArgumentException::class, 'A preset must accept at least one credential kind.');
    });

    it('refuses credential kinds that are not a list of kinds', function (mixed $kinds, string $message): void {
        expect(fn() => presetWith(['credentialKinds' => $kinds]))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'not a list' => [['first' => CredentialKind::None], 'The credential kinds of a preset must be a list.'],
        'another value' => [['bearer_key'], 'The credential kinds of a preset hold a value that is not a credential kind.'],
    ]);

    it('refuses a header key among the kinds without the name of the header', function (): void {
        expect(fn() => presetWith(['credentialKinds' => [CredentialKind::BearerKey, CredentialKind::HeaderKey]]))
            ->toThrow(InvalidArgumentException::class, 'A preset that accepts a header key must name the header.');
    });

    it('refuses a key header that is not an HTTP token', function (string $header): void {
        expect(fn() => presetWith(['credentialKinds' => [CredentialKind::HeaderKey], 'keyHeader' => $header]))
            ->toThrow(InvalidArgumentException::class, 'The key header of a preset must be an HTTP token.');
    })->with([
        'empty' => [''],
        'a space' => ['X Api Key'],
        'a colon' => ['X-Api-Key:'],
        'a line break at the end' => ["X-Api-Key\n"],
    ]);

    it('refuses fields that are not a list of fields', function (mixed $fields, string $message): void {
        expect(fn() => presetWith(['fields' => $fields]))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'not a list' => [['first' => new PresetField('region', 'Region', true)], 'The fields of a preset must be a list.'],
        'another value' => [['region'], 'The fields of a preset hold a value that is not a field.'],
    ]);

    it('refuses two fields with the same key', function (): void {
        expect(fn() => presetWith(['fields' => [new PresetField('region', 'Region', true), new PresetField('region', 'Again', false)]]))
            ->toThrow(InvalidArgumentException::class, 'Two fields of a preset have the same key.');
    });

    it('refuses a placeholder in the default base URL that names no field', function (string $url): void {
        expect(fn() => presetWith(['defaultBaseUrl' => $url, 'fields' => [new PresetField('region', 'Region', true, '[a-z]+')]]))
            ->toThrow(InvalidArgumentException::class, 'A placeholder in the default base URL must name a field of the preset.');
    })->with([
        'an unknown key' => ['https://{project}.example.test'],
        'an empty placeholder' => ['https://api.example.test/{}'],
        'a key in another case' => ['https://{Region}.example.test'],
    ]);

    it('refuses a placeholder in the host that is not followed by a dot and a fixed domain: a setting never chooses the domain', function (string $url): void {
        expect(fn() => presetWith(['defaultBaseUrl' => $url, 'fields' => [new PresetField('x', 'X', true, '[a-z.]+')]]))
            ->toThrow(InvalidArgumentException::class, 'A placeholder in the host of the default base URL must be followed by a dot and a fixed domain');
    })->with([
        'the host ends with it' => ['https://api.example.test{x}'],
        'the host ends with it, after a dot' => ['https://api.{x}/v1'],
        'it is the whole host' => ['https://{x}/v1'],
        'it is the whole host, before a port' => ['https://{x}:8443/v1'],
        'one label follows' => ['https://{x}.test/v1'],
        'it is the last of two' => ['https://{x}.example.test.{x}'],
        'no dot before the domain' => ['https://{x}example-test/v1'],
    ]);

    it('takes a placeholder in the host that is followed by a dot and a fixed domain, and any placeholder in the path', function (string $url): void {
        expect(presetWith(['defaultBaseUrl' => $url, 'fields' => [new PresetField('x', 'X', true, '[a-z.]+')]])->placeholders())->toBe(['x']);
    })->with([
        'a label of the host' => ['https://{x}.example.test/'],
        'the start of a label' => ['https://{x}-api.example.test/v1'],
        'twice in the host' => ['https://{x}.{x}.example.test'],
        'before a port' => ['https://{x}.example.test:8443/v1'],
        'in the path alone' => ['https://api.example.test/{x}'],
    ]);

    it('refuses a field used in a placeholder that has no pattern', function (): void {
        expect(fn() => presetWith(['defaultBaseUrl' => 'https://{region}.example.test', 'fields' => [new PresetField('region', 'Region', true)]]))
            ->toThrow(InvalidArgumentException::class, 'A field that is used in the default base URL must have a pattern.');
    });

    it('refuses an adapter option that is not a boolean, an integer or a string', function (mixed $value): void {
        expect(fn() => presetWith(['adapterOptions' => ['option' => $value]]))
            ->toThrow(InvalidArgumentException::class, 'An adapter option must be a boolean, an integer or a string.');
    })->with([
        'null' => [null],
        'a float' => [1.5],
        'an array' => [['a']],
    ]);

    it('refuses an adapter option without a name', function (): void {
        expect(fn() => presetWith(['adapterOptions' => [true]]))
            ->toThrow(InvalidArgumentException::class, 'The name of an adapter option must be a string.');
    });
});
