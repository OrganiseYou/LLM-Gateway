<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\ChatCompletionsAdapter;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
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
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Presets\Mistral;
use OrganiseYou\LlmGateway\Tests\Presets\MistralEu;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletionsFixtures;
use OrganiseYou\LlmGateway\Tests\Support\PresetProof;
use OrganiseYou\LlmGateway\Tests\Support\RecordingObserver;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// The two entries `mistral` and `mistral-eu` (docs/design/adapters.md, section 9, rows 13 and 14), defined under
// tests/Presets (decision G140). Every value is checked against the design and docs/providers/mistral.md; the
// answers are the adapter's own fixtures of the content-entries set, which were written for these options.

const MISTRAL_MODEL = 'mistral-large-latest';

const MISTRAL_STORAGE = 'No request option against storing: zero data retention is an account setting, "available only with pay-as-you-go and only for stateless API calls", asked from Mistral\'s support; Mistral\'s help-centre articles on training add that on the free mode input and output may be used for training unless that is switched off in the Admin panel.';

/**
 * @return array<string, array{Preset, string}> the entry and its base URL, by id
 */
function mistralEntries(): array
{
    return [
        'mistral' => [Mistral::preset(), 'https://api.mistral.ai/v1'],
        'mistral-eu' => [MistralEu::preset(), 'https://api.eu.mistral.ai/v1'],
    ];
}

function mistralRequest(Message ...$messages): Request
{
    return new Request(MISTRAL_MODEL, $messages === [] ? [Message::user('Say hello.')] : array_values($messages), 30.0, [ChatCompletions::lookup()]);
}

function mistralFixtures(): ChatCompletionsFixtures
{
    return new ChatCompletionsFixtures(ChatCompletionsFixtures::CONTENT_ENTRIES);
}

describe('what the entries say', function (): void {
    it('names its adapter, its fixed address, the bearer key, no fields, and no option not to store', function (Preset $preset, string $baseUrl): void {
        expect($preset->adapter)->toBe(ChatCompletionsAdapter::class)
            ->and($preset->baseUrlRule)->toBe(BaseUrlRule::Fixed)
            ->and($preset->defaultBaseUrl)->toBe($baseUrl)
            ->and($preset->credentialKinds)->toBe([CredentialKind::BearerKey])
            ->and($preset->keyHeader)->toBeNull()
            ->and($preset->fields)->toBe([])
            ->and($preset->sendsNoStoreOption)->toBeFalse()
            ->and($preset->modelOverrides)->toBe([])
            // Row 13, and row 14 "as 13": only what differs from the defaults of the adapter.
            ->and($preset->adapterOptions)->toBe([
                'max_tokens_field' => 'max_tokens',
                'required_word' => 'any',
                'tool_result_name' => true,
                'reasoning_carrier' => 'content_entries',
                'stream_usage' => 'unasked',
            ])
            // The options the adapter was proven with in its own contract test of this set.
            ->and($preset->adapterOptions)->toBe(ChatCompletions::CONTENT_ENTRIES_SERVER)
            ->and(array_key_exists('store_field', $preset->adapterOptions))->toBeFalse();
    })->with(mistralEntries());

    it('has its id and label', function (): void {
        expect([Mistral::preset()->id, Mistral::preset()->label])->toBe(['mistral', 'Mistral'])
            ->and([MistralEu::preset()->id, MistralEu::preset()->label])->toBe(['mistral-eu', 'Mistral, Europe']);
    });

    it('has a description, a data location and a storage note, with links on the provider\'s documented domain', function (Preset $preset): void {
        foreach ([$preset->description, $preset->dataLocation, $preset->storageNote] as $text) {
            expect(trim($text))->not->toBe('');
        }
        foreach ([$preset->dataLocationSource, $preset->storageNoteSource] as $link) {
            expect($link)->toBeString()
                ->and(parse_url((string) $link, PHP_URL_SCHEME))->toBe('https')
                ->and(PresetProof::host((string) $link))->toBeIn(['docs.mistral.ai', 'help.mistral.ai', 'mistral.ai'])
                // The link is one the provider file records.
                ->and(PresetProof::providerFile('mistral'))->toContain((string) $link);
        }
    })->with(mistralEntries());

    // The texts are data a customer reads: a change to one of them must be deliberate, so each is pinned whole.
    it('says exactly this, with exactly these links', function (Preset $preset, string $description, string $dataLocation, string $storageNote): void {
        expect($preset->description)->toBe($description)
            ->and($preset->dataLocation)->toBe($dataLocation)
            ->and($preset->dataLocationSource)->toBe('https://docs.mistral.ai/inference/regional-inference')
            ->and($preset->storageNote)->toBe($storageNote)
            ->and($preset->storageNoteSource)->toBe('https://help.mistral.ai/en/articles/347612-can-i-activate-zero-data-retention-zdr');
    })->with([
        'mistral' => [
            Mistral::preset(),
            'Mistral\'s hosted API at its global address; you need an API key, made in the Studio console (console.mistral.ai) under "API Keys", which has an expiry date.',
            'No processing location is committed on this address: "Mistral does not commit to a specific inference location for requests sent to this endpoint." Processing in the EU is committed only on the EU address.',
            MISTRAL_STORAGE,
        ],
        'mistral-eu' => [
            MistralEu::preset(),
            'Mistral\'s hosted API at its EU address, at 1.1 times the list price; you need an API key from the Studio console (console.mistral.ai), and since the models can differ per region, ask the model list for what this address serves.',
            'Processed in data centres in EU and EFTA countries; "Regional inference does not make the Mistral control plane regional": account data, keys, billing and usage figures may be handled elsewhere.',
            MISTRAL_STORAGE,
        ],
    ]);

    it('quotes the provider file word for word', function (Preset $preset, string $field, string $quote): void {
        $file = (string) preg_replace('/\s+/', ' ', PresetProof::providerFile('mistral'));

        expect($file)->toContain($quote)
            ->and($field === 'dataLocation' ? $preset->dataLocation : $preset->storageNote)->toContain($quote);
    })->with([
        'mistral, data location' => [Mistral::preset(), 'dataLocation', 'Mistral does not commit to a specific inference location for requests sent to this endpoint.'],
        'mistral, storage' => [Mistral::preset(), 'storageNote', 'available only with pay-as-you-go and only for stateless API calls'],
        'mistral-eu, data location' => [MistralEu::preset(), 'dataLocation', 'data centres in EU and EFTA countries'],
        'mistral-eu, control plane' => [MistralEu::preset(), 'dataLocation', 'Regional inference does not make the Mistral control plane regional'],
        'mistral-eu, storage' => [MistralEu::preset(), 'storageNote', 'available only with pay-as-you-go and only for stateless API calls'],
        'mistral, training' => [Mistral::preset(), 'storageNote', 'input and output'],
    ]);

    it('names no header that carries a credential at the provider', function (Preset $preset): void {
        foreach ($preset->fields as $field) {
            expect(strtolower((string) $field->header))->not->toBe('authorization');
        }
        expect($preset->keyHeader)->toBeNull();
    })->with(mistralEntries());
});

describe('a gateway built from an entry', function (): void {
    it('reports the capabilities of section 6.2, field by field', function (Preset $preset): void {
        $capabilities = PresetProof::gateway($preset, new FakeTransport(), new BearerKey('key-of-the-test'))->capabilities(MISTRAL_MODEL);

        expect(PresetProof::fields($capabilities))->toBe([
            'toolCalling' => Support::Native,
            'forcedToolCall' => Support::Native,
            'parallelToolCalls' => Support::Native,
            'structuredOutput' => Support::Native,
            'toolsWithStructuredOutput' => Support::Emulated,
            'streaming' => Support::Native,
            'modelListing' => Support::Native,
            'contextLength' => null,
            'temperature' => Support::Native,
        ]);
    })->with(mistralEntries());

    it('sends to its own address with the bearer key, and gets an answer', function (Preset $preset, string $baseUrl): void {
        $transport = (new FakeTransport())->willReturn(PresetProof::fixture(mistralFixtures(), ContractFixture::PlainAnswer));

        $answer = PresetProof::gateway($preset, $transport, new BearerKey('key-of-the-test'))->complete(mistralRequest());
        $sent = $transport->requests()[0];

        expect($answer->text)->toBe('Hello.')
            ->and($sent->method)->toBe('POST')
            ->and($sent->url)->toBe($baseUrl . '/chat/completions')
            ->and($sent->allHeaders()['Authorization'] ?? null)->toBe('Bearer key-of-the-test')
            ->and(array_keys($sent->secretHeaders))->toBe(['Authorization']);
    })->with(mistralEntries());

    it('sends the maximum of output tokens as max_tokens, and no store field', function (Preset $preset): void {
        $transport = (new FakeTransport())->willReturn(PresetProof::fixture(mistralFixtures(), ContractFixture::PlainAnswer));
        $request = new Request(MISTRAL_MODEL, [Message::user('Say hello.')], 30.0, maxOutputTokens: 100);

        PresetProof::gateway($preset, $transport, new BearerKey('key-of-the-test'))->complete($request);
        $body = json_decode($transport->requests()[0]->body, true);

        expect($body['max_tokens'] ?? null)->toBe(100)
            ->and(array_key_exists('max_completion_tokens', $body))->toBeFalse()
            ->and(array_key_exists('store', $body))->toBeFalse();
    })->with(mistralEntries());

    it('lists its models at its own address', function (Preset $preset, string $baseUrl): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"object":"list","data":[{"id":"example-large-1","max_context_length":32768}]}'));

        $models = PresetProof::gateway($preset, $transport, new BearerKey('key-of-the-test'))->models(10.0);

        expect($models[0]->id)->toBe('example-large-1')
            ->and($transport->requests()[0]->url)->toBe($baseUrl . '/models');
    })->with(mistralEntries());

    it('takes no other credentials, and no base URL of the customer', function (Preset $preset): void {
        foreach ([new HeaderKey('key-of-the-test'), new NoCredentials()] as $credentials) {
            expect(fn() => PresetProof::gateway($preset, new FakeTransport(), $credentials))
                ->toThrow(GatewayException::class);
        }

        // A made-up host, the host of the other entry, and the host of another provider: none can be given.
        foreach (['https://elsewhere.example.test/v1', 'https://api.mistral.ai/v1', 'https://api.eu.mistral.ai/v1', 'https://api.anthropic.com'] as $baseUrl) {
            try {
                GatewayFactory::resolveBaseUrl($preset, new GatewayConfig(RetryPolicy::none(), $baseUrl));
                $reason = null;
            } catch (GatewayException $refusal) {
                $reason = $refusal->reason;
            }
            expect($reason)->toBe(ErrorReason::NotConfigured, $baseUrl);
        }
    })->with(mistralEntries());
});

describe('the host of each entry', function (): void {
    it('never sends to the host of the other entry, also after a 401 and after a redirect to it', function (Preset $preset, string $baseUrl): void {
        $other = PresetProof::host($baseUrl) === 'api.mistral.ai' ? 'https://api.eu.mistral.ai/v1' : 'https://api.mistral.ai/v1';
        $plain = PresetProof::fixture(mistralFixtures(), ContractFixture::PlainAnswer);
        $transport = (new FakeTransport())
            ->willReturn(new HttpResponse(401, ['Content-Type' => 'application/json'], '{"detail":"Invalid API Key"}'))
            ->willReturn($plain)
            ->willReturn(new HttpResponse(307, ['Location' => $other . '/chat/completions'], ''))
            ->willReturn(new HttpResponse(301, ['Location' => $other . '/chat/completions'], ''))
            ->willReturn($plain);
        $gateway = PresetProof::gateway($preset, $transport, new BearerKey('key-of-the-test'), retry: new RetryPolicy([1.0, 1.0], 5.0));

        $reasons = [];
        foreach (range(1, 4) as $call) {
            try {
                $gateway->complete(mistralRequest());
                $reasons[] = 'answer';
            } catch (GatewayException $failure) {
                $reasons[] = $failure->reason->value;
            }
        }

        // A redirect is never followed and never retried: it fails as unavailable, and the next call goes to the
        // same address as before.
        expect($reasons)->toBe(['credentials_rejected', 'answer', 'unavailable', 'unavailable'])
            ->and($transport->remaining())->toBe(1);
        foreach (PresetProof::urls($transport) as $url) {
            expect(PresetProof::host($url))->toBe(PresetProof::host($baseUrl))
                ->and($url)->toBe($baseUrl . '/chat/completions');
        }
        expect(count($transport->requests()))->toBe(4);
    })->with(mistralEntries());

    it('has a host of its own', function (): void {
        expect(PresetProof::host((string) Mistral::preset()->defaultBaseUrl))->toBe('api.mistral.ai')
            ->and(PresetProof::host((string) MistralEu::preset()->defaultBaseUrl))->toBe('api.eu.mistral.ai');
    });
});

describe('a tool round', function (): void {
    it('sends the content list of the answer back as the adapter\'s tests show, with the name on the tool result', function (Preset $preset): void {
        $transport = (new FakeTransport())
            ->willReturn(PresetProof::fixture(mistralFixtures(), ContractFixture::ToolCallWithOpaqueData))
            ->willReturn(PresetProof::fixture(mistralFixtures(), ContractFixture::PlainAnswer));
        $gateway = PresetProof::gateway($preset, $transport, new BearerKey('key-of-the-test'));

        $first = $gateway->complete(mistralRequest(Message::user('Where to?')));
        $call = $first->toolCalls[0];
        $second = $gateway->complete(mistralRequest(Message::user('Where to?'), $first->toMessage(), Message::toolResult($call->id, $call->name, '{"degrees":18}')));
        $sent = json_decode($transport->requests()[1]->body, true)['messages'];

        expect($first->opaque)->not->toBeNull()
            ->and($second->text)->toBe('Hello.')
            ->and($sent[1])->toBe([
                'role' => 'assistant',
                'content' => [
                    ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'The user asks about one city, so the lookup tool is the way: opaque-tracer.']]],
                    ['type' => 'text', 'text' => 'One moment.'],
                ],
                'tool_calls' => [['id' => 'AbCdEf123', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']]],
            ])
            ->and($sent[2])->toBe(['role' => 'tool', 'tool_call_id' => 'AbCdEf123', 'name' => 'lookup', 'content' => '{"degrees":18}']);
    })->with(mistralEntries());

    it('asks for "any" when a call is required', function (Preset $preset): void {
        $transport = (new FakeTransport())->willReturn(PresetProof::fixture(mistralFixtures(), ContractFixture::ToolCall));
        $request = new Request(MISTRAL_MODEL, [Message::user('Where to?')], 30.0, [ChatCompletions::lookup()], ToolChoice::required());

        PresetProof::gateway($preset, $transport, new BearerKey('key-of-the-test'))->complete($request);

        expect(json_decode($transport->requests()[0]->body, true)['tool_choice'] ?? null)->toBe('any');
    })->with(mistralEntries());
});

describe('the key', function (): void {
    it('is in no output of the entry, of a gateway built from it, of its answer or of its failure', function (Preset $preset): void {
        $observer = new RecordingObserver();
        $transport = (new FakeTransport())
            ->willReturn(PresetProof::fixture(mistralFixtures(), ContractFixture::PlainAnswer))
            ->willReturn(new HttpResponse(401, ['X-Echo' => LEAK_MARKER], '{"detail":"Invalid API Key ' . LEAK_MARKER . '"}'));
        $gateway = PresetProof::gateway($preset, $transport, new BearerKey(LEAK_MARKER), observer: $observer);

        $answer = $gateway->complete(mistralRequest());
        $failure = null;
        try {
            $gateway->complete(mistralRequest());
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
    })->with(mistralEntries());
});
