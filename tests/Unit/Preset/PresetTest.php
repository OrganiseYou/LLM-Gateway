<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\ModelOverride;
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
        'modelOverrides' => [],
        'storageNote' => '',
        'storageNoteSource' => null,
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

    it('names no header unless it is given one, and holds the one it is given', function (): void {
        expect((new PresetField('region', 'Region', false, '[a-z]+'))->header)->toBeNull()
            ->and((new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', 'X-Talk-Workspace'))->header)->toBe('X-Talk-Workspace');
    });

    it('takes the header as its last parameter, so a field written before it existed is built as before', function (): void {
        $parameters = array_map(
            fn(ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionClass(PresetField::class))->getConstructor()?->getParameters() ?? [],
        );

        expect($parameters)->toBe(['key', 'label', 'required', 'pattern', 'header']);
    });

    it('refuses a header that is not an HTTP token without an underscore', function (string $header): void {
        expect(fn() => new PresetField('workspace', 'Workspace', false, '[a-z]+', $header))
            ->toThrow(InvalidArgumentException::class, 'The header of a field must be an HTTP token without an underscore, and not digits only.');
    })->with([
        'empty' => [''],
        'a space' => ['X Talk Workspace'],
        'a colon' => ['X-Talk-Workspace:'],
        'a pseudo-header' => [':authority'],
        'a line break at the end' => ["X-Talk-Workspace\n"],
        'a line break and a second header' => ["X-Talk-Workspace\r\nHost"],
        'an underscore' => ['X_Talk_Workspace'],
        'a letter outside ASCII' => ['X-Tälk'],
        'digits only: PHP would make a number of it as the name of a header' => ['123'],
        'one digit' => ['0'],
        'digits with a zero in front' => ['007'],
    ]);

    it('takes a header with digits in it that is not digits only', function (string $header): void {
        expect((new PresetField('workspace', 'Workspace', false, '[a-z]+', $header))->header)->toBe($header);
    })->with([['X-Talk-2'], ['1-Talk'], ['12a'], ['1.5'], ['-1']]);

    it('refuses a header of the sign-in, the address or the connection, in any case of letters', function (string $header): void {
        expect(fn() => new PresetField('workspace', 'Workspace', false, '[a-z]+', $header))
            ->toThrow(InvalidArgumentException::class, 'The header of a field must not be a header of the sign-in, the address or the connection.');
    })->with([
        ['Authorization'], ['authorization'], ['AUTHORIZATION'], ['Cookie'], ['Cookie2'], ['Set-Cookie'], ['Origin'],
        ['Host'], ['host'], ['Forwarded'], ['Via'], ['Max-Forwards'], ['X-Host'], ['X-Original-Host'], ['X-Original-URL'], ['X-Rewrite-URL'], ['X-Real-IP'],
        ['X-HTTP-Method'], ['X-HTTP-Method-Override'], ['X-Method-Override'],
        ['Connection'], ['Keep-Alive'], ['Upgrade'], ['HTTP2-Settings'], ['TE'], ['Trailer'], ['Transfer-Encoding'], ['Expect'],
        ['Content-Length'], ['Content-Type'], ['Content-Encoding'], ['Content-Range'], ['Content-MD5'],
        ['Accept'], ['Accept-Encoding'], ['Range'],
        // Every name that starts with Proxy-, Sec- or X-Forwarded-, whatever follows.
        ['Proxy-Authorization'], ['Proxy-Connection'], ['proxy-anything-new'], ['Sec-Fetch-Site'], ['SEC-WebSocket-Key'], ['sec-x'],
        ['X-Forwarded-For'], ['X-Forwarded-Host'], ['X-Forwarded-Proto'], ['X-Forwarded-Port'], ['X-Forwarded-Server'], ['X-Forwarded-Prefix'], ['x-forwarded-anything-new'],
    ]);

    it('takes a header whose name only looks like a refused one', function (string $header): void {
        expect((new PresetField('workspace', 'Workspace', false, '[a-z]+', $header))->header)->toBe($header);
    })->with([['Proxy'], ['Sec'], ['Security-Scope'], ['X-Forwarded'], ['X-Hosting'], ['Accept-Language'], ['X-Talk-Authorization-Scope']]);

    it('refuses a header on a field without a pattern', function (): void {
        expect(fn() => new PresetField('workspace', 'Workspace', false, null, 'X-Talk-Workspace'))
            ->toThrow(InvalidArgumentException::class, 'A field that is sent as a header must have a pattern.');
    });

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

describe('ModelOverride', function (): void {
    it('holds a pattern and the options for the models that fit it', function (): void {
        $override = new ModelOverride('small-*', ['forced_tool_call' => 'none', 'max_tool_name' => 8, 'lists_models' => false]);

        expect($override->pattern)->toBe('small-*')
            ->and($override->adapterOptions)->toBe(['forced_tool_call' => 'none', 'max_tool_name' => 8, 'lists_models' => false])
            ->and((new ModelOverride('model-a', []))->adapterOptions)->toBe([]);
    });

    it('refuses a pattern that is empty, holds a control character or is not valid UTF-8', function (string $pattern): void {
        expect(fn() => new ModelOverride($pattern, []))
            ->toThrow(InvalidArgumentException::class, 'The pattern of a model override must not be empty and must not hold a control character.');
    })->with([
        'empty' => [''],
        'a line break at the end' => ["small-*\n"],
        'a tab' => ["small\t*"],
        'a null byte' => ["small\0*"],
        'a broken character' => ["small-\xff*"],
    ]);

    it('refuses an option that is not a boolean, an integer or a string', function (mixed $value): void {
        expect(fn() => new ModelOverride('small-*', untyped(['option' => $value])))
            ->toThrow(InvalidArgumentException::class, 'An adapter option must be a boolean, an integer or a string.');
    })->with([
        'null' => [null],
        'a float' => [1.5],
        'an array' => [['a']],
    ]);

    it('refuses an option without a name', function (): void {
        expect(fn() => new ModelOverride('small-*', untyped([true])))
            ->toThrow(InvalidArgumentException::class, 'The name of an adapter option must be a string.');
    });

    it('compares the pattern with the whole model name, with a star for any run of characters', function (string $pattern, string $model, bool $fits): void {
        expect((new ModelOverride($pattern, []))->matches($model))->toBe($fits);
    })->with([
        'the same name' => ['model-a', 'model-a', true],
        'another name' => ['model-a', 'model-b', false],
        'a name that only starts with it' => ['model-a', 'model-a-2', false],
        'a name that only ends with it' => ['model-a', 'my-model-a', false],
        'another case of letters' => ['model-a', 'Model-A', false],
        'the empty name' => ['model-a', '', false],
        'a star at the end' => ['small-*', 'small-2026', true],
        'a star at the end, for nothing' => ['small-*', 'small-', true],
        'a star at the end, another start' => ['small-*', 'large-2026', false],
        'a star at the end, text before the start' => ['small-*', 'x-small-1', false],
        'a star at the start' => ['*-mini', 'talk-5-mini', true],
        'a star at the start, text after the end' => ['*-mini', 'talk-5-mini-2', false],
        'a star in the middle' => ['talk-*-mini', 'talk-5.5-mini', true],
        'a star in the middle, for nothing' => ['talk-*-mini', 'talk--mini', true],
        'the start and the end may not share a character' => ['ab*ba', 'aba', false],
        'the start and the end, side by side' => ['ab*ba', 'abba', true],
        'two stars' => ['*opus*', 'vendor/opus-5', true],
        'two stars, the piece missing' => ['*opus*', 'vendor/sonnet-5', false],
        'pieces in order' => ['a*b*c', 'a-b-c', true],
        'pieces out of order' => ['a*b*c', 'a-c-b', false],
        'a piece that is found again after a first, wrong place' => ['a*bc*d', 'abxbcd', true],
        'a middle piece that only fits inside the end' => ['a*b*b', 'ab', false],
        'only a star' => ['*', 'anything/at:all', true],
        'only a star, the empty name' => ['*', '', true],
        'two stars side by side' => ['a**b', 'ab', true],
        'a dot is a dot' => ['talk.5', 'talk-5', false],
        'a question mark is a question mark' => ['talk-?', 'talk-5', false],
        'brackets are brackets' => ['talk-[0-9]', 'talk-5', false],
        'a star stands for a line of any characters, also a slash' => ['org/*', 'org/team/model', true],
        'letters outside ASCII' => ['modèle-*', 'modèle-é', true],
    ]);

    it('answers at once for a pattern of many stars and a long name', function (): void {
        $override = new ModelOverride(str_repeat('a*', 40) . 'b', []);

        expect($override->matches(str_repeat('a', 5000)))->toBeFalse()
            ->and($override->matches(str_repeat('a', 5000) . 'b'))->toBeTrue();
    });
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
    it('has no model overrides and no storage note unless they are given', function (): void {
        $preset = new Preset('made-up', 'Made up', 'A made-up server.', TalkAdapter::class, BaseUrlRule::CustomerMay, 'https://api.example.test/v1', [CredentialKind::BearerKey], null, [], 'not stated by the provider', null, false);

        expect($preset->adapterOptions)->toBe([])
            ->and($preset->modelOverrides)->toBe([])
            ->and($preset->storageNote)->toBe('')
            ->and($preset->storageNoteSource)->toBeNull();
    });

    it('takes the new values after the adapter options, so a preset written before they existed is built as before', function (): void {
        $parameters = array_map(
            fn(ReflectionParameter $parameter): string => $parameter->getName(),
            (new ReflectionClass(Preset::class))->getConstructor()?->getParameters() ?? [],
        );

        expect(array_slice($parameters, 12))->toBe(['adapterOptions', 'modelOverrides', 'storageNote', 'storageNoteSource']);
    });

    it('holds the model overrides, the storage note and its source', function (): void {
        $overrides = [new ModelOverride('small-*', ['streaming' => 'emulated']), new ModelOverride('*-old', ['tool_calling' => 'none'])];
        $preset = presetWith([
            'modelOverrides' => $overrides,
            'storageNote' => 'Requests are kept for 30 days unless your account has an agreement that says otherwise.',
            'storageNoteSource' => 'https://docs.example.test/data#storage',
        ]);

        expect($preset->modelOverrides)->toBe($overrides)
            ->and($preset->storageNote)->toBe('Requests are kept for 30 days unless your account has an agreement that says otherwise.')
            ->and($preset->storageNoteSource)->toBe('https://docs.example.test/data#storage');
    });

    it('refuses model overrides that are not a list of model overrides', function (mixed $overrides, string $message): void {
        expect(fn() => presetWith(['modelOverrides' => $overrides]))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'not a list' => [['first' => new ModelOverride('small-*', [])], 'The model overrides of a preset must be a list.'],
        'a pattern alone' => [['small-*'], 'The model overrides of a preset hold a value that is not a model override.'],
        'options alone' => [[['streaming' => 'emulated']], 'The model overrides of a preset hold a value that is not a model override.'],
    ]);

    it('refuses a field that is sent in the header of the key, in any case of letters', function (string $header): void {
        expect(fn() => presetWith([
            'credentialKinds' => [CredentialKind::HeaderKey],
            'keyHeader' => 'X-Api-Key',
            'fields' => [new PresetField('workspace', 'Workspace', false, '[a-z]+', $header)],
        ]))->toThrow(InvalidArgumentException::class, 'A field of a preset must not be sent in the header of the key.');
    })->with([['X-Api-Key'], ['x-api-key'], ['X-API-KEY']]);

    it('refuses two fields that are sent in the same header, in any case of letters', function (): void {
        expect(fn() => presetWith(['fields' => [
            new PresetField('workspace', 'Workspace', false, '[a-z]+', 'X-Talk-Workspace'),
            new PresetField('region', 'Region', false, '[a-z]+'),
            new PresetField('project', 'Project', false, '[a-z]+', 'x-talk-workspace'),
        ]]))->toThrow(InvalidArgumentException::class, 'Two fields of a preset are sent in the same header.');
    });

    it('takes fields that are sent in headers of their own, beside the header of the key', function (): void {
        $preset = presetWith([
            'credentialKinds' => [CredentialKind::HeaderKey],
            'keyHeader' => 'X-Api-Key',
            'fields' => [
                new PresetField('workspace', 'Workspace', false, '[a-z]+', 'X-Talk-Workspace'),
                new PresetField('project', 'Project', false, '[a-z]+', 'X-Talk-Project'),
            ],
        ]);

        expect(array_map(fn(PresetField $field): ?string => $field->header, $preset->fields))->toBe(['X-Talk-Workspace', 'X-Talk-Project']);
    });
});

describe('Preset::optionsFor()', function (): void {
    it('gives the options of the preset for a model that fits no override: an override is no closed list of models', function (): void {
        $preset = presetWith([
            'adapterOptions' => ['streaming' => 'native', 'max_tool_name' => 64],
            'modelOverrides' => [new ModelOverride('small-*', ['streaming' => 'emulated'])],
        ]);

        expect($preset->optionsFor('a-model-nobody-listed'))->toBe(['streaming' => 'native', 'max_tool_name' => 64])
            ->and(presetWith()->optionsFor('model-a'))->toBe([]);
    });

    it('lays an override that fits over the options of the preset: it replaces a value and adds one', function (): void {
        $preset = presetWith([
            'adapterOptions' => ['streaming' => 'native', 'max_tool_name' => 64],
            'modelOverrides' => [new ModelOverride('small-*', ['streaming' => 'emulated', 'tool_calling' => 'none'])],
        ]);

        expect($preset->optionsFor('small-1'))->toBe(['streaming' => 'emulated', 'max_tool_name' => 64, 'tool_calling' => 'none']);
    });

    it('lays every override that fits over the one before it, in the order of the list: the later one wins', function (): void {
        $general = new ModelOverride('small-*', ['streaming' => 'emulated', 'tool_calling' => 'none', 'max_tool_name' => 8]);
        $exact = new ModelOverride('small-2', ['tool_calling' => 'native']);
        $other = new ModelOverride('large-*', ['streaming' => 'native', 'max_tool_name' => 128]);
        $options = ['streaming' => 'native', 'max_tool_name' => 64];

        $generalFirst = presetWith(['adapterOptions' => $options, 'modelOverrides' => [$general, $other, $exact]]);
        $exactFirst = presetWith(['adapterOptions' => $options, 'modelOverrides' => [$exact, $other, $general]]);

        expect($generalFirst->optionsFor('small-2'))->toBe(['streaming' => 'emulated', 'max_tool_name' => 8, 'tool_calling' => 'native'])
            ->and($exactFirst->optionsFor('small-2'))->toBe(['streaming' => 'emulated', 'max_tool_name' => 8, 'tool_calling' => 'none'])
            ->and($generalFirst->optionsFor('small-1'))->toBe(['streaming' => 'emulated', 'max_tool_name' => 8, 'tool_calling' => 'none'])
            ->and($generalFirst->optionsFor('large-1'))->toBe(['streaming' => 'native', 'max_tool_name' => 128]);
    });

    it('keeps the type of a value an override gives: a boolean stays a boolean, and false is a value', function (): void {
        $preset = presetWith([
            'adapterOptions' => ['lists_models' => true, 'max_tool_name' => 64],
            'modelOverrides' => [new ModelOverride('small-*', ['lists_models' => false, 'max_tool_name' => 0])],
        ]);

        expect($preset->optionsFor('small-1'))->toBe(['lists_models' => false, 'max_tool_name' => 0]);
    });

    it('changes nothing of the preset', function (): void {
        $preset = presetWith([
            'adapterOptions' => ['streaming' => 'native'],
            'modelOverrides' => [new ModelOverride('small-*', ['streaming' => 'emulated'])],
        ]);
        $preset->optionsFor('small-1');

        expect($preset->adapterOptions)->toBe(['streaming' => 'native'])
            ->and($preset->optionsFor('model-a'))->toBe(['streaming' => 'native']);
    });
});

describe('Preset::withAdapterOptions()', function (): void {
    it('gives a copy with the options laid over its own, and leaves the preset as it was', function (): void {
        $preset = presetWith(['adapterOptions' => ['streaming' => 'emulated', 'max_tool_name' => 64]]);
        $raised = $preset->withAdapterOptions(['streaming' => 'native', 'model_listing' => 'none']);

        expect($raised)->not->toBe($preset)
            ->and($raised->adapterOptions)->toBe(['streaming' => 'native', 'max_tool_name' => 64, 'model_listing' => 'none'])
            ->and($preset->adapterOptions)->toBe(['streaming' => 'emulated', 'max_tool_name' => 64]);
    });

    it('keeps every other value of the preset', function (): void {
        $preset = presetWith([
            'defaultBaseUrl' => 'https://{region}.example.test/v1',
            'credentialKinds' => [CredentialKind::HeaderKey, CredentialKind::None],
            'keyHeader' => 'X-Api-Key',
            'fields' => [new PresetField('region', 'Region', true, '[a-z0-9-]+'), new PresetField('workspace', 'Workspace', false, '[a-z]+', 'X-Talk-Workspace')],
            'dataLocation' => 'In the region the customer chooses.',
            'dataLocationSource' => 'https://docs.example.test/data',
            'sendsNoStoreOption' => true,
            'adapterOptions' => ['streaming' => 'emulated'],
            'modelOverrides' => [new ModelOverride('small-*', ['tool_calling' => 'none'])],
            'storageNote' => 'Nothing is kept.',
            'storageNoteSource' => 'https://docs.example.test/data#storage',
        ]);

        $raised = get_object_vars($preset->withAdapterOptions(['streaming' => 'native']));
        $before = get_object_vars($preset);
        unset($raised['adapterOptions'], $before['adapterOptions']);

        expect($raised)->toBe($before)->and(count($before))->toBe(15);
    });

    it('leaves the model overrides above the new options: an override still wins for a model it fits', function (): void {
        $preset = presetWith([
            'adapterOptions' => ['streaming' => 'emulated'],
            'modelOverrides' => [new ModelOverride('small-*', ['streaming' => 'emulated'])],
        ])->withAdapterOptions(['streaming' => 'native']);

        expect($preset->optionsFor('model-a'))->toBe(['streaming' => 'native'])
            ->and($preset->optionsFor('small-1'))->toBe(['streaming' => 'emulated']);
    });

    it('gives an equal copy for no options', function (): void {
        $preset = presetWith(['adapterOptions' => ['streaming' => 'emulated']]);

        expect($preset->withAdapterOptions([]))->toEqual($preset);
    });

    it('refuses an option that the preset itself would refuse', function (mixed $options, string $message): void {
        expect(fn() => presetWith()->withAdapterOptions(untyped($options)))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'a float' => [['option' => 1.5], 'An adapter option must be a boolean, an integer or a string.'],
        'null' => [['option' => null], 'An adapter option must be a boolean, an integer or a string.'],
        'no name' => [[true], 'The name of an adapter option must be a string.'],
    ]);
});
