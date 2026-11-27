<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// The model list reader of the chat-completions adapter (docs/design/adapters.md, section 10): one request under
// the base URL, three shapes of a list, and per entry only what the provider files record. The lists below are
// written by hand from those shapes, with made-up models.

/**
 * The models a server with these options lists, given this body.
 *
 * @param array<string, bool|int|string> $options
 * @return list<ModelInfo>
 */
function modelsIn(string $body, array $options = [], int $status = 200): array
{
    $transport = (new FakeTransport())->willReturn(new HttpResponse($status, ['Content-Type' => 'application/json'], $body));

    return ChatCompletions::gateway($transport, $options)->models(10.0);
}

describe('the request for the model list', function (): void {
    it('is one GET under the base URL, at the path of the option', function (array $options, string $address): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"object":"list","data":[]}'));
        $models = ChatCompletions::gateway($transport, $options)->models(10.0, 'trace-1');
        $sent = $transport->requests();

        expect($models)->toBe([])
            ->and($sent)->toHaveCount(1)
            ->and($sent[0]->method)->toBe('GET')
            ->and($sent[0]->url)->toBe($address)
            ->and($sent[0]->body)->toBe('')
            ->and($sent[0]->headers)->toBe(['Accept' => 'application/json'])
            ->and(array_keys($sent[0]->secretHeaders))->toBe(['Authorization'])
            ->and($sent[0]->timeoutSeconds)->toBe(10.0);
    })->with([
        'the default' => [[], 'https://llm.example.test/v1/models'],
        '/models' => [['models_path' => '/models'], 'https://llm.example.test/v1/models'],
        'a path of the server' => [['models_path' => '/model-settings'], 'https://llm.example.test/v1/model-settings'],
        'a longer path' => [['models_path' => '/instance/models'], 'https://llm.example.test/v1/instance/models'],
    ]);

    it('is not sent where the preset says the models cannot be listed', function (): void {
        $transport = new FakeTransport();
        $gateway = ChatCompletions::gateway($transport, ['model_listing' => 'none']);
        $error = Talk::errorOf(fn() => $gateway->models(10.0, 'trace-1'));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($error->traceId)->toBe('trace-1')
            ->and($error->attempts)->toBe(0)
            ->and($transport->requests())->toBe([]);
    });

    it('never follows an address a list holds: a list is one request', function (): void {
        $body = '{"object":"list","data":[{"id":"example-chat-1","url":"https://other.example.test/models/1"}],"has_more":true,"next":"https://other.example.test/v1/models?page=2","next_page_token":"p2"}';
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, ['Link' => '<https://other.example.test/v1/models?page=2>; rel="next"'], $body));

        expect(ChatCompletions::gateway($transport)->models(10.0))->toHaveCount(1)
            ->and($transport->requests())->toHaveCount(1);
    });
});

describe('the shape of a list', function (): void {
    it('is read as an object with data, an object with models, or a bare list', function (string $body): void {
        expect(modelsIn($body))->toEqual([new ModelInfo('example-chat-1'), new ModelInfo('example-chat-2')]);
    })->with([
        'data' => ['{"object":"list","data":[{"id":"example-chat-1","object":"model","created":1790000000,"owned_by":"example"},{"id":"example-chat-2","object":"model"}]}'],
        'models' => ['{"models":[{"id":"example-chat-1"},{"id":"example-chat-2"}]}'],
        'a bare list' => ['[{"id":"example-chat-1"},{"id":"example-chat-2"}]'],
        'data, beside fields nobody documents' => ['{"data":[{"id":"example-chat-1"},{"id":"example-chat-2"}],"total":2,"models":"see data","page":{"size":20}}'],
    ]);

    it('may be empty', function (string $body): void {
        expect(modelsIn($body))->toBe([]);
    })->with(['{"object":"list","data":[]}', '{"models":[]}', '[]']);

    it('is read from any successful status', function (): void {
        expect(modelsIn('{"data":[{"id":"example-chat-1"}]}', [], 203))->toEqual([new ModelInfo('example-chat-1')]);
    });

    it('is an error of the provider when the body holds no list', function (string $body, ?string $code): void {
        $error = Talk::errorOf(fn() => modelsIn($body));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->httpStatus)->toBe(200)
            ->and($error->providerCode)->toBe($code);
    })->with([
        'empty' => ['', null],
        'no JSON' => ['<html>Models</html>', null],
        'a JSON string' => ['"example-chat-1"', null],
        'an object without a list' => ['{"object":"list"}', null],
        'an empty object' => ['{}', null],
        'an empty object with white space' => [" { } \n", null],
        'data that is null' => ['{"data":null}', null],
        'data that is a word' => ['{"data":"example-chat-1","models":7}', null],
        'an error object under a successful status' => ['{"error":{"message":"No.","type":"server_error"}}', 'server_error'],
        'a flat error under a successful status' => ['{"id":null,"code":503,"error":"model_unavailable","detail":"No."}', 'model_unavailable'],
    ]);
});

describe('an entry of a list', function (): void {
    it('gives its id, its name, its context length, its aliases and whether it is deprecated', function (): void {
        $body = ChatCompletions::json(['object' => 'list', 'data' => [
            // Ids only, and an end date on one.
            ['id' => 'example-chat-1', 'object' => 'model', 'created' => 1790000000, 'owned_by' => 'example'],
            ['id' => 'example-chat-0', 'object' => 'model', 'created' => 1780000000, 'owned_by' => 'example', 'shutdown_date' => '2027-01-31'],
            // A context length that may be null, aliases, and fields that are left alone.
            ['id' => 'example-big-2', 'aliases' => ['example-big', 'example-big-latest'], 'object' => 'model', 'context_length' => 1000000, 'capabilities' => ['reasoning_effort' => ['low', 'high']], 'prompt_text_token_price' => 20000],
            ['id' => 'example-big-1', 'aliases' => [], 'context_length' => null],
            // A name, another name for the context length, and an announced end.
            ['id' => 'example-large-2512', 'name' => 'Example Large', 'description' => 'The large one.', 'max_context_length' => 131072, 'aliases' => ['example-large-latest'], 'deprecation' => null, 'default_model_temperature' => 0.3, 'capabilities' => ['function_calling' => true]],
            ['id' => 'example-small-2409', 'name' => 'Example Small', 'max_context_length' => 32768, 'deprecation' => '2027-03-01T00:00:00Z', 'deprecation_replacement_model' => 'example-small-2603'],
            ['id' => 'example-flagged', 'display_name' => 'Example Flagged', 'is_deprecated' => true, 'supports_reasoning' => true],
        ]]);

        expect(modelsIn($body))->toEqual([
            new ModelInfo('example-chat-1'),
            new ModelInfo('example-chat-0', deprecated: true),
            new ModelInfo('example-big-2', null, 1000000, ['example-big', 'example-big-latest']),
            new ModelInfo('example-big-1'),
            new ModelInfo('example-large-2512', 'Example Large', 131072, ['example-large-latest']),
            new ModelInfo('example-small-2409', 'Example Small', 32768, [], true),
            new ModelInfo('example-flagged', 'Example Flagged', null, [], true),
        ]);
    });

    it('takes its id from the field of the option, and leaves out an entry that cannot be used for chat', function (): void {
        // The list of an installation in its own shape: the name is what a request sends.
        $body = ChatCompletions::json([
            ['name' => 'example-instruct-8b', 'status' => 'available', 'chat' => true, 'max_context_size' => 8192, 'maximum_completion_tokens' => 2048, 'multimodal' => false, 'description' => 'A small one.'],
            ['name' => 'example-embed-1', 'status' => 'available', 'chat' => false, 'embedding_type' => 'semantic'],
            ['name' => 'example-instruct-70b', 'status' => 'unavailable', 'chat' => true, 'max_context_size' => 32768],
            ['name' => 'example-vision-32b', 'status' => 'available', 'chat' => true, 'max_context_size' => 32768, 'multimodal' => true],
        ]);

        expect(modelsIn($body, ['models_path' => '/model-settings', 'model_id_field' => 'name']))->toEqual([
            new ModelInfo('example-instruct-8b', 'example-instruct-8b', 8192),
            new ModelInfo('example-vision-32b', 'example-vision-32b', 32768),
        ]);
    });

    it('takes a technical name as its id, beside an id that is no model name, and leaves out a model that is switched off', function (): void {
        $body = ChatCompletions::json([
            ['id' => '0b0e7c2a-1111-4222-8333-444455556666', 'name' => 'Example Frontier', 'technical_name' => 'example-ft5', 'model_type' => 'Large Language Model', 'enabled' => true, 'health' => 'ok'],
            ['id' => '0b0e7c2a-1111-4222-8333-444455557777', 'name' => 'Example Old', 'technical_name' => 'example-old', 'model_type' => 'Large Language Model', 'enabled' => false],
        ]);

        expect(modelsIn($body, ['models_path' => '/instance/models', 'model_id_field' => 'technical_name']))->toEqual([new ModelInfo('example-ft5', 'Example Frontier')]);
    });

    it('is skipped without a usable id, and never breaks the list', function (): void {
        $body = ChatCompletions::json(['data' => [
            ['id' => 'example-chat-1'],
            ['object' => 'model'],
            ['id' => ''],
            ['id' => null],
            ['id' => 7],
            ['id' => ['example']],
            ['id' => "example\nchat"],
            'example-chat-9',
            null,
            [],
            ['id' => 'example-chat-2'],
        ]]);

        expect(modelsIn($body))->toEqual([new ModelInfo('example-chat-1'), new ModelInfo('example-chat-2')]);
    });

    it('ignores a field of a type it does not expect instead of failing', function (): void {
        $body = ChatCompletions::json(['data' => [[
            'id' => 'example-chat-1',
            'name' => ['en' => 'Example'],
            'display_name' => "Example\tChat",
            'context_length' => '128000',
            'max_context_length' => 0,
            'max_context_size' => -5,
            'aliases' => ['example-latest', 7, null, '', ['nested'], "bad\nalias", 'example-stable'],
            'deprecation' => '',
            'shutdown_date' => false,
            'is_deprecated' => 'yes',
            'chat' => 'no',
            'enabled' => 0,
            'status' => ['unavailable'],
        ], [
            'id' => 'example-chat-2',
            'aliases' => 'example-two',
            'context_length' => 4096.5,
            'max_context_length' => 8192,
        ]]]);

        expect(modelsIn($body))->toEqual([
            new ModelInfo('example-chat-1', null, null, ['example-latest', 'example-stable']),
            new ModelInfo('example-chat-2', null, 8192),
        ]);
    });

    it('takes the first context length that is a whole number above 0', function (array $entry, ?int $contextLength): void {
        expect(modelsIn(ChatCompletions::json(['data' => [['id' => 'example-chat-1', ...$entry]]]))[0]->contextLength)->toBe($contextLength);
    })->with([
        'context_length' => [['context_length' => 1000, 'max_context_length' => 2000, 'max_context_size' => 3000], 1000],
        'max_context_length' => [['context_length' => null, 'max_context_length' => 2000, 'max_context_size' => 3000], 2000],
        'max_context_size' => [['max_context_size' => 3000], 3000],
        'none' => [['context_window' => 4000, 'loaded_instances' => [['config' => ['context_length' => 4096]]]], null],
    ]);
});
