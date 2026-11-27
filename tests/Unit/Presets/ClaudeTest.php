<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Anthropic\MessagesAdapter;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\Credentials\NoCredentials;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Presets\Claude;
use OrganiseYou\LlmGateway\Tests\Support\FixedTokenSupplier;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\MessagesFixtures;
use OrganiseYou\LlmGateway\Tests\Support\PresetProof;
use OrganiseYou\LlmGateway\Tests\Support\RecordingObserver;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// The entry `claude` (docs/design/adapters.md, section 9, row 3), defined under tests/Presets (decision G140).
// Every value is checked against the design and docs/providers/anthropic.md; the answers are the adapter's own
// fixtures under tests/fixtures/anthropic.

const CLAUDE_MODEL = 'claude-sonnet-example';

function claudeRequest(?int $maxOutputTokens = null, ?float $temperature = null): Request
{
    return new Request(CLAUDE_MODEL, [Message::user('Say hello.')], 30.0, maxOutputTokens: $maxOutputTokens, temperature: $temperature);
}

function claudePlain(): HttpResponse
{
    return PresetProof::fixture(new MessagesFixtures(), ContractFixture::PlainAnswer);
}

describe('what the entry says', function (): void {
    it('names its adapter, its fixed address, its two kinds of key, the workspace field and no option not to store', function (): void {
        $preset = Claude::preset();

        expect($preset->id)->toBe('claude')
            ->and($preset->label)->toBe('Claude (Anthropic)')
            ->and($preset->adapter)->toBe(MessagesAdapter::class)
            ->and($preset->baseUrlRule)->toBe(BaseUrlRule::Fixed)
            ->and($preset->defaultBaseUrl)->toBe('https://api.anthropic.com')
            ->and($preset->credentialKinds)->toBe([CredentialKind::BearerKey, CredentialKind::HeaderKey])
            ->and($preset->keyHeader)->toBe('x-api-key')
            ->and($preset->sendsNoStoreOption)->toBeFalse()
            ->and(count($preset->fields))->toBe(1)
            ->and([$preset->fields[0]->key, $preset->fields[0]->required, $preset->fields[0]->pattern, $preset->fields[0]->header])
            ->toBe(['workspace_id', false, 'wrkspc_[A-Za-z0-9_-]+', 'anthropic-workspace-id'])
            // Row 3: the version, the default maximum and no temperature (G104, G105).
            ->and($preset->adapterOptions)->toBe(['api_version' => '2026-10-21', 'default_max_output_tokens' => 16000, 'temperature' => 'not_accepted'])
            ->and($preset->adapterOptions)->toBe(Messages::CURRENT_VERSION);
    });

    it('has a description, a data location and a storage note, with links on the provider\'s documented domain', function (): void {
        $preset = Claude::preset();

        foreach ([$preset->description, $preset->dataLocation, $preset->storageNote] as $text) {
            expect(trim($text))->not->toBe('');
        }
        foreach ([$preset->dataLocationSource, $preset->storageNoteSource] as $link) {
            expect($link)->toBeString()
                ->and(parse_url((string) $link, PHP_URL_SCHEME))->toBe('https')
                ->and(PresetProof::host((string) $link))->toBeIn(['platform.claude.com', 'privacy.claude.com'])
                ->and(PresetProof::providerFile('anthropic'))->toContain((string) $link);
        }
    });

    // The texts are data a customer reads: a change to one of them must be deliberate, so each is pinned whole.
    it('says exactly this, with exactly these links', function (): void {
        $preset = Claude::preset();

        expect($preset->description)->toBe('Claude through Anthropic\'s own API: you need an API key from the "API keys" page of the Claude Console, and the workspace id (wrkspc_…) when the key is not tied to one workspace; thinking tokens count toward the maximum of output tokens.')
            ->and($preset->dataLocation)->toBe('No EU-only option: the provider states that for the inference location only "us" and "global" are available, that "global", the default, means "inference may run in any available geography", and that for storage "us" is the only available workspace geo.')
            ->and($preset->dataLocationSource)->toBe('https://platform.claude.com/docs/en/manage-claude/data-residency')
            ->and($preset->storageNote)->toBe('No request option against storing: zero data retention is an arrangement per organisation through Anthropic\'s sales team; Claude Fable 5.2, 5.1, 5 and Claude Mythos 5.2, 5.1, 5 require 30-day retention and are refused with a 400 for an organisation whose retention setting does not allow it; a JSON Schema for a structured answer is cached for up to 24 hours, also under zero data retention; "Retained data is never used for model training without your express permission"; and without zero data retention the privacy-centre article "How long do you store my organization\'s data" gives deletion "within 30 days of receipt or generation", with exceptions (condensed reading).')
            ->and($preset->storageNoteSource)->toBe('https://platform.claude.com/docs/en/manage-claude/api-and-data-retention');
    });

    it('quotes the provider file word for word', function (string $field, string $quote): void {
        // The provider file writes a value as code; the page's words are the same without the marks of code.
        $file = (string) preg_replace('/\s+/', ' ', str_replace('`', '', PresetProof::providerFile('anthropic')));
        $preset = Claude::preset();

        expect($file)->toContain($quote)
            ->and($field === 'dataLocation' ? $preset->dataLocation : $preset->storageNote)->toContain($quote);
    })->with([
        'the inference location' => ['dataLocation', '"us" and "global" are available'],
        'the default' => ['dataLocation', 'inference may run in any available geography'],
        'the storage' => ['dataLocation', '"us" is the only available workspace geo'],
        'the deletion' => ['storageNote', 'within 30 days of receipt or generation'],
        'the page of the deletion' => ['storageNote', 'How long do you store my organization\'s data'],
        'the models that need retention' => ['storageNote', 'Claude Fable 5.2, 5.1, 5 and Claude Mythos 5.2, 5.1, 5'],
        'the training' => ['storageNote', 'Retained data is never used for model training without your express permission'],
    ]);

    it('names no header that carries a credential at the provider in a field', function (): void {
        foreach (Claude::preset()->fields as $field) {
            expect(strtolower((string) $field->header))->not->toBeIn(['authorization', 'x-api-key']);
        }
    });

    it('takes forced tool calls away only from the models the provider file names by id', function (): void {
        $preset = Claude::preset();

        expect(count($preset->modelOverrides))->toBe(count(Claude::WITHOUT_FORCED_TOOL_CALL));
        foreach (Claude::WITHOUT_FORCED_TOOL_CALL as $model) {
            // The id stands in the provider file.
            expect(PresetProof::providerFile('anthropic'))->toContain('`' . $model . '`')
                ->and($preset->optionsFor($model)['forced_tool_call'] ?? null)->toBe('none');
        }
        expect(array_key_exists('forced_tool_call', $preset->optionsFor(CLAUDE_MODEL)))->toBeFalse();
    });
});

describe('a gateway built from the entry', function (): void {
    it('reports the capabilities of section 6.2, field by field', function (string $model, Support $forced): void {
        $capabilities = PresetProof::gateway(Claude::preset(), new FakeTransport(), new BearerKey('key-of-the-test'))->capabilities($model);

        expect(PresetProof::fields($capabilities))->toBe([
            'toolCalling' => Support::Native,
            'forcedToolCall' => $forced,
            'parallelToolCalls' => Support::Native,
            'structuredOutput' => Support::Native,
            'toolsWithStructuredOutput' => Support::Native,
            'streaming' => Support::Native,
            'modelListing' => Support::Native,
            'contextLength' => null,
            'temperature' => Support::None,
        ]);
    })->with([
        'a model the entry has no override for' => [CLAUDE_MODEL, Support::Native],
        'Claude Fable 5.2' => ['claude-fable-5-2', Support::None],
        'Claude Mythos 5.2' => ['claude-mythos-5-2', Support::None],
        'Claude Opus 5.5' => ['claude-opus-5-5', Support::None],
        'Claude Sonnet 5.5' => ['claude-sonnet-5-5', Support::None],
        'Claude Fable 5.1' => ['claude-fable-5-1', Support::None],
        'Claude Mythos 5.1' => ['claude-mythos-5-1', Support::None],
        // An id is a pinned snapshot: a longer name is no named model.
        'a name that only starts with one' => ['claude-opus-5-5-preview', Support::Native],
    ]);

    it('sends to its own address with the bearer key and the version header, and gets an answer', function (): void {
        $transport = (new FakeTransport())->willReturn(claudePlain());

        $answer = PresetProof::gateway(Claude::preset(), $transport, new BearerKey('key-of-the-test'))->complete(claudeRequest());
        $sent = $transport->requests()[0];

        expect($answer->text)->toBe('Hello.')
            ->and($sent->method)->toBe('POST')
            ->and($sent->url)->toBe('https://api.anthropic.com/v1/messages')
            ->and($sent->allHeaders()['Authorization'] ?? null)->toBe('Bearer key-of-the-test')
            ->and(array_keys($sent->secretHeaders))->toBe(['Authorization'])
            ->and(array_change_key_case($sent->headers)['anthropic-version'] ?? null)->toBe('2026-10-21')
            ->and(array_key_exists('anthropic-workspace-id', array_change_key_case($sent->headers)))->toBeFalse();
    });

    it('sends a header key in x-api-key, and the workspace the customer filled in', function (): void {
        $transport = (new FakeTransport())->willReturn(claudePlain());

        PresetProof::gateway(Claude::preset(), $transport, new HeaderKey('key-of-the-test'), ['workspace_id' => 'wrkspc_example01'])->complete(claudeRequest());
        $sent = $transport->requests()[0];

        expect(array_keys($sent->secretHeaders))->toBe(['x-api-key'])
            ->and($sent->allHeaders()['x-api-key'])->toBe('key-of-the-test')
            ->and(array_change_key_case($sent->headers)['anthropic-workspace-id'] ?? null)->toBe('wrkspc_example01')
            ->and(array_change_key_case($sent->headers)['anthropic-version'] ?? null)->toBe('2026-10-21');
    });

    it('refuses a workspace that does not have the form of one, before anything is sent', function (): void {
        $transport = new FakeTransport();

        expect(fn() => PresetProof::gateway(Claude::preset(), $transport, new BearerKey('key-of-the-test'), ['workspace_id' => 'other-workspace']))
            ->toThrow(GatewayException::class);
        expect($transport->requests())->toBe([]);
    });

    it('takes no base URL of the customer, also not the host of another provider', function (): void {
        foreach (['https://elsewhere.example.test', 'https://api.anthropic.com', 'https://api.mistral.ai/v1', 'https://api.eu.mistral.ai/v1'] as $baseUrl) {
            try {
                GatewayFactory::resolveBaseUrl(Claude::preset(), new GatewayConfig(RetryPolicy::none(), $baseUrl));
                $reason = null;
            } catch (GatewayException $refusal) {
                $reason = $refusal->reason;
            }
            expect($reason)->toBe(ErrorReason::NotConfigured, $baseUrl);
        }
    });

    it('takes no other kind of credentials', function (): void {
        foreach ([new NoCredentials(), new BearerToken(new FixedTokenSupplier('token-of-the-test'))] as $credentials) {
            expect(fn() => PresetProof::gateway(Claude::preset(), new FakeTransport(), $credentials))->toThrow(GatewayException::class);
        }
    });

    it('sends a request without a maximum with the default of the entry, and one with a maximum with that', function (?int $given, int $sent): void {
        $transport = (new FakeTransport())->willReturn(claudePlain());

        PresetProof::gateway(Claude::preset(), $transport, new BearerKey('key-of-the-test'))->complete(claudeRequest($given));

        expect(json_decode($transport->requests()[0]->body, true)['max_tokens'] ?? null)->toBe($sent);
    })->with([
        'no maximum' => [null, 16000],
        'a maximum' => [500, 500],
    ]);

    it('refuses a temperature as not_supported and sends nothing, and never sends one', function (): void {
        $transport = (new FakeTransport())->willReturn(claudePlain());
        $gateway = PresetProof::gateway(Claude::preset(), $transport, new BearerKey('key-of-the-test'));

        try {
            $gateway->complete(claudeRequest(temperature: 0.5));
            $reason = null;
        } catch (GatewayException $refusal) {
            $reason = $refusal->reason;
        }
        $gateway->complete(claudeRequest());

        expect($reason)->toBe(ErrorReason::NotSupported)
            ->and(count($transport->requests()))->toBe(1)
            ->and(array_key_exists('temperature', json_decode($transport->requests()[0]->body, true)))->toBeFalse();
    });

    it('forces a named tool on a model without an override, and refuses it on a model the provider names', function (): void {
        $request = fn(string $model): Request => new Request($model, [Message::user('Where to?')], 30.0, [Messages::lookup()], ToolChoice::tool('lookup'));
        $transport = (new FakeTransport())->willReturn(PresetProof::fixture(new MessagesFixtures(), ContractFixture::ToolCall));
        $gateway = PresetProof::gateway(Claude::preset(), $transport, new BearerKey('key-of-the-test'));

        $gateway->complete($request(CLAUDE_MODEL));
        try {
            $gateway->complete($request('claude-opus-5-5'));
            $reason = null;
        } catch (GatewayException $refusal) {
            $reason = $refusal->reason;
        }

        expect(json_decode($transport->requests()[0]->body, true)['tool_choice'] ?? null)->toBe(['type' => 'tool', 'name' => 'lookup'])
            ->and($reason)->toBe(ErrorReason::NotSupported)
            ->and(count($transport->requests()))->toBe(1);
    });

    it('lists its models at its own address', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"data":[{"id":"example-messages-1","display_name":"Example","max_input_tokens":200000}],"has_more":false}'));

        $models = PresetProof::gateway(Claude::preset(), $transport, new BearerKey('key-of-the-test'))->models(10.0);

        expect($models[0]->id)->toBe('example-messages-1')
            ->and(PresetProof::host($transport->requests()[0]->url))->toBe('api.anthropic.com')
            ->and(parse_url($transport->requests()[0]->url, PHP_URL_PATH))->toBe('/v1/models');
    });

    it('never sends to another host, also after a 401 and after a redirect', function (): void {
        $transport = (new FakeTransport())
            ->willReturn(Messages::failed(401, 'authentication_error'))
            ->willReturn(new HttpResponse(307, ['Location' => 'https://elsewhere.example.test/v1/messages'], ''))
            ->willReturn(claudePlain());
        $gateway = PresetProof::gateway(Claude::preset(), $transport, new BearerKey('key-of-the-test'), retry: new RetryPolicy([1.0, 1.0], 5.0));

        $reasons = [];
        foreach (range(1, 3) as $call) {
            try {
                $gateway->complete(claudeRequest());
                $reasons[] = 'answer';
            } catch (GatewayException $failure) {
                $reasons[] = $failure->reason->value;
            }
        }

        expect($reasons)->toBe(['credentials_rejected', 'unavailable', 'answer'])
            ->and(PresetProof::urls($transport))->toBe(array_fill(0, 3, 'https://api.anthropic.com/v1/messages'));
    });
});

describe('the key', function (): void {
    it('is in no output of the entry, of a gateway built from it, of its answer or of its failure', function (): void {
        $preset = Claude::preset();
        $observer = new RecordingObserver();
        $transport = (new FakeTransport())
            ->willReturn(claudePlain())
            ->willReturn(new HttpResponse(401, ['X-Echo' => LEAK_MARKER], Messages::errorBody('authentication_error', null, 'Invalid key ' . LEAK_MARKER)));
        $gateway = PresetProof::gateway($preset, $transport, new HeaderKey(LEAK_MARKER), ['workspace_id' => 'wrkspc_example01'], observer: $observer);

        $answer = $gateway->complete(claudeRequest());
        $failure = null;
        try {
            $gateway->complete(claudeRequest());
        } catch (GatewayException $thrown) {
            $failure = $thrown;
        }

        expect($failure)->not->toBeNull();
        expectNoMarker([
            'var_export of the entry' => var_export($preset, true),
            'json_encode of the entry' => (string) json_encode($preset),
            ...valueForms($gateway),
            ...valueForms($answer),
            ...exceptionForms($failure ?? new RuntimeException()),
            ...array_merge(...array_map(fn($report): array => valueForms($report->request), $observer->started)),
        ]);
    });
});
