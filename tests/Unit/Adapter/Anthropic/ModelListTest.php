<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Anthropic\ModelList;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// The model list of the Messages adapter (docs/design/adapters.md, section 10): one request for the largest page
// the format documents, `data[]` with `id`, `display_name` and `max_input_tokens`. An unknown field never breaks a
// list, and an entry without a usable id is skipped.

/**
 * The models the adapter reads from a body with this `data`.
 *
 * @param list<mixed> $data
 * @return list<ModelInfo>
 */
function messagesModelsFrom(array $data): array
{
    $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], Messages::json(['data' => $data, 'has_more' => false, 'first_id' => 'a', 'last_id' => 'b'])));

    return Messages::gateway($transport)->models(10.0);
}

describe('the request for the model list of the Messages adapter', function (): void {
    it('asks one page of the largest size at the models path under the base URL, with the version and the sign-in', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"data":[]}'));
        Messages::gateway($transport, Messages::CURRENT_VERSION, credentials: new HeaderKey('key-of-the-test'))->models(10.0);

        $sent = $transport->requests()[0];

        expect($transport->requests())->toHaveCount(1)
            ->and($sent->method)->toBe('GET')
            ->and($sent->url)->toBe('https://llm.example.test/v1/models?limit=1000')
            ->and($sent->headers)->toBe(['anthropic-version' => '2026-10-21'])
            ->and(array_keys($sent->secretHeaders))->toBe(['x-api-key'])
            ->and($sent->body)->toBe('');
    });

    it('reads one page and does not follow the mark that there are more', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"data":[{"id":"example-messages-1"}],"has_more":true,"first_id":"example-messages-1","last_id":"example-messages-1"}'));
        $models = Messages::gateway($transport)->models(10.0);

        expect($models)->toHaveCount(1)
            ->and($transport->requests())->toHaveCount(1);
    });

    it('is refused as not_supported, and nothing is sent, where the preset says the models cannot be listed', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"data":[]}'));
        $error = Talk::errorOf(fn() => Messages::gateway($transport, [...Messages::EARLIER_VERSION, 'model_listing' => 'none'])->models(10.0, 'trace-1'));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($error->traceId)->toBe('trace-1')
            ->and($transport->requests())->toBe([]);
    });

    it('fails as not_configured for a value of the option outside its list', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"data":[]}'));
        $error = Talk::errorOf(fn() => Messages::gateway($transport, [...Messages::EARLIER_VERSION, 'model_listing' => 'emulated'])->models(10.0));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the option model_listing of the preset has a value outside its list')
            ->and($transport->requests())->toBe([]);
    });
});

describe('the reading of the model list of the Messages adapter', function (): void {
    it('reads the id, the display name and the context length of the documented shape, in the order of the list', function (): void {
        $models = messagesModelsFrom([
            ['type' => 'model', 'id' => 'example-large-5', 'display_name' => 'Example Large 5', 'created_at' => '2026-02-19T00:00:00Z', 'lifecycle' => 'active', 'max_input_tokens' => 1000000, 'max_tokens' => 128000, 'capabilities' => ['structured_outputs' => ['supported' => true]]],
            ['type' => 'model', 'id' => 'example-small-4-20251001', 'display_name' => 'Example Small 4', 'created_at' => '2025-10-01T00:00:00Z', 'max_input_tokens' => 200000, 'max_tokens' => 64000],
        ]);

        expect($models)->toEqual([
            new ModelInfo('example-large-5', 'Example Large 5', 1000000),
            new ModelInfo('example-small-4-20251001', 'Example Small 4', 200000),
        ]);
    });

    it('never breaks on a field it does not know or a field of the wrong type', function (array $entry, ?string $name, ?int $contextLength): void {
        $models = messagesModelsFrom([$entry]);

        expect($models)->toHaveCount(1)
            ->and($models[0]->id)->toBe('example-messages-1')
            ->and($models[0]->name)->toBe($name)
            ->and($models[0]->contextLength)->toBe($contextLength)
            ->and($models[0]->aliases)->toBe([])
            ->and($models[0]->deprecated)->toBeFalse();
    })->with([
        'only an id' => [['id' => 'example-messages-1'], null, null],
        'a name that is a list' => [['id' => 'example-messages-1', 'display_name' => ['x']], null, null],
        'a name with a line end in it' => [['id' => 'example-messages-1', 'display_name' => "Example\nModel"], null, null],
        'an empty name' => [['id' => 'example-messages-1', 'display_name' => ''], null, null],
        'a context length as text' => [['id' => 'example-messages-1', 'display_name' => 'Example', 'max_input_tokens' => '200000'], 'Example', null],
        'a context length of 0' => [['id' => 'example-messages-1', 'max_input_tokens' => 0], null, null],
        'a context length with a fraction' => [['id' => 'example-messages-1', 'max_input_tokens' => 200000.5], null, null],
        'a lifecycle in any word: it is not read' => [['id' => 'example-messages-1', 'lifecycle' => 'deprecated', 'max_input_tokens' => 9], null, 9],
        'fields of a later version' => [['id' => 'example-messages-1', 'something_new' => ['a' => [1, 2]], 'max_tokens' => null], null, null],
    ]);

    it('skips an entry without a usable id, and keeps the others', function (): void {
        $models = messagesModelsFrom([
            ['display_name' => 'No id'],
            ['id' => ''],
            ['id' => 7],
            ['id' => "example\nmodel"],
            ['id' => ['example']],
            'a string',
            null,
            7,
            ['id' => 'example-messages-1'],
        ]);

        expect(array_map(fn(ModelInfo $model): string => $model->id, $models))->toBe(['example-messages-1']);
    });

    it('gives an empty list for an account without models', function (string $body): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], $body));

        expect(Messages::gateway($transport)->models(10.0))->toBe([]);
    })->with(['{"data":[],"has_more":false,"first_id":null,"last_id":null}', '{"data":{}}']);

    it('fails as provider_error for a body that holds no list', function (string $body, string $detail, ?string $code): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], $body));
        $error = Talk::errorOf(fn() => Messages::gateway($transport)->models(10.0));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->detail)->toBe($detail)
            ->and($error->providerCode)->toBe($code)
            ->and($error->httpStatus)->toBe(200);
    })->with([
        'no JSON' => ['<html></html>', 'the body is not JSON', null],
        'an empty body' => ['', 'the body is not JSON', null],
        'a JSON string' => ['"models"', 'the body is not JSON', null],
        'an object without data' => ['{"models":[{"id":"example-messages-1"}]}', 'the response holds no model list', null],
        'data that is null' => ['{"data":null}', 'the response holds no model list', null],
        'data that is a word' => ['{"data":"none"}', 'the response holds no model list', null],
        'a bare list' => ['[{"id":"example-messages-1"}]', 'the response holds no model list', null],
        'an error body' => [Messages::errorBody('api_error'), 'the response holds an error', 'api_error'],
        'an error body with a code' => [Messages::errorBody('invalid_request_error', 'organization_on_hold'), 'the response holds an error', 'organization_on_hold'],
    ]);

    it('reads a list that has an error member beside it', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"data":[{"id":"example-messages-1"}],"error":{"type":"api_error"}}'));

        expect(Messages::gateway($transport)->models(10.0))->toHaveCount(1);
    });

    it('gives a failed request the reason of its status and the code of the provider', function (int $status, string $type, ?string $code, ErrorReason $reason, string $shown): void {
        $transport = (new FakeTransport())->willReturn(Messages::failed($status, $type, [], $code));
        $error = Talk::errorOf(fn() => Messages::gateway($transport)->models(10.0));

        expect($error->reason)->toBe($reason)
            ->and($error->providerCode)->toBe($shown)
            ->and($error->httpStatus)->toBe($status);
    })->with([
        [401, 'authentication_error', null, ErrorReason::CredentialsRejected, 'authentication_error'],
        [400, 'invalid_request_error', 'organization_on_hold', ErrorReason::Rejected, 'organization_on_hold'],
        [404, 'not_found_error', null, ErrorReason::Rejected, 'not_found_error'],
        [529, 'overloaded_error', null, ErrorReason::Unavailable, 'overloaded_error'],
    ]);

    it('reads a decoded body by itself', function (): void {
        expect(ModelList::read(['data' => [['id' => 'example-messages-1', 'display_name' => 'Example', 'max_input_tokens' => 5]]]))->toEqual([new ModelInfo('example-messages-1', 'Example', 5)])
            ->and(ModelList::read(['data' => []]))->toBe([])
            ->and(ModelList::read([]))->toBeNull()
            ->and(ModelList::read(['data' => 'x']))->toBeNull();
    });
});
