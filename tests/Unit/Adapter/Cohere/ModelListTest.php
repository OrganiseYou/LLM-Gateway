<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// The model list of the Chat v2 adapter (docs/design/adapters.md, section 10): one request for one page of the
// models that chat, under the base URL, and nothing of a response ever becomes an address.

/**
 * A successful response of the model list with this body.
 *
 * @param array<mixed> $body
 */
function chatV2Models(array $body): HttpResponse
{
    return new HttpResponse(200, ['Content-Type' => 'application/json'], ChatV2::json($body));
}

describe('the model list of the Chat v2 adapter', function (): void {
    it('asks for one page of the largest size, of the models that chat, under the base URL, signed in as every request, without a body', function (): void {
        $transport = (new FakeTransport())->willReturn(chatV2Models(['models' => []]));
        ChatV2::gateway($transport)->models(10.0);
        $sent = $transport->requests()[0];

        expect($sent->method)->toBe('GET')
            // The list is under another version of the path than the chat: both are under the base URL.
            ->and($sent->url)->toBe('https://llm.example.test/v1/models?endpoint=chat&page_size=1000')
            ->and($sent->body)->toBe('')
            ->and($sent->headers)->toBe([])
            ->and($sent->secretHeaders['Authorization']->reveal())->toBe('Bearer key-of-the-test');
    });

    it('reads the name as the id, the context length, also when it is written as a double, and whether the model is deprecated', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"models":['
            . '{"name":"example-chat-a-03-2025","is_deprecated":false,"endpoints":["chat"],"default_endpoints":[],"finetuned":false,"context_length":256000.0,"tokenizer_url":"https://other.example.test/tokenizer.json","features":["tools"],"sampling_defaults":{"temperature":0.3}},'
            . '{"name":"example-chat","is_deprecated":true,"context_length":128000},'
            . '{"name":"example-vault/model:1"}'
            . '],"next_page_token":"page-2"}'));
        $models = ChatV2::gateway($transport)->models(10.0);

        expect($models)->toEqual([
            new ModelInfo('example-chat-a-03-2025', null, 256000),
            new ModelInfo('example-chat', null, 128000, [], true),
            new ModelInfo('example-vault/model:1'),
        ]);
    });

    it('skips an entry without a usable name and anything that is no entry, and reads past a context length that is none', function (): void {
        $transport = (new FakeTransport())->willReturn(chatV2Models(['models' => [
            'example-as-a-word', 7, null, [], ['id' => 'example-with-an-id'], ['name' => ''], ['name' => 7], ['name' => ['example']], ['name' => "example\nwith a line end"],
            ['name' => 'example-a', 'context_length' => 0, 'is_deprecated' => 'yes'],
            ['name' => 'example-b', 'context_length' => -5],
            ['name' => 'example-c', 'context_length' => 4096.5],
            ['name' => 'example-d', 'context_length' => '4096', 'is_deprecated' => 1],
            ['name' => 'example-e', 'context_length' => 1.0e30],
        ]]));
        $models = ChatV2::gateway($transport)->models(10.0);

        expect(array_map(fn(ModelInfo $model): string => $model->id, $models))->toBe(['example-a', 'example-b', 'example-c', 'example-d', 'example-e']);
        foreach ($models as $model) {
            expect($model->contextLength)->toBeNull()
                ->and($model->deprecated)->toBeFalse();
        }
    });

    it('never asks for a next page and never follows anything a response names: one request, whatever the list says', function (mixed $token): void {
        $transport = (new FakeTransport())
            ->willReturn(chatV2Models([
                'models' => [['name' => 'example-chat-1', 'tokenizer_url' => 'https://other.example.test/tokenizer.json']],
                'next_page_token' => $token,
                'next' => 'https://other.example.test/v1/models?page_token=x',
                'next_page_url' => 'https://other.example.test/next',
            ]))
            ->willReturn(chatV2Models(['models' => [['name' => 'example-chat-2']]]));

        $models = ChatV2::gateway($transport, retry: new RetryPolicy([1.0], 60.0))->models(10.0);

        expect($models)->toHaveCount(1)
            ->and($transport->requests())->toHaveCount(1)
            ->and($transport->remaining())->toBe(1)
            ->and($transport->requests()[0]->url)->toBe('https://llm.example.test/v1/models?endpoint=chat&page_size=1000');
    })->with([
        'a token' => ['page-2'],
        'a token that is an address' => ['https://other.example.test/v1/models?page_size=1000'],
        'a token that would change the query' => ['x&page_size=1&endpoint=embed'],
        'a token of another type' => [['a' => 1]],
    ]);

    it('gives an empty list for an account without models', function (): void {
        $transport = (new FakeTransport())->willReturn(chatV2Models(['models' => []]));

        expect(ChatV2::gateway($transport)->models(10.0))->toBe([]);
    });

    it('fails as an error of the provider for a body that holds no model list', function (string $body, string $detail): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], $body));
        $error = Talk::errorOf(fn() => ChatV2::gateway($transport)->models(10.0));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->detail)->toBe($detail)
            ->and($error->providerCode)->toBeNull();
    })->with([
        'no JSON' => ['<html>models</html>', 'the body is not JSON'],
        'an empty body' => ['', 'the body is not JSON'],
        'a number' => ['7', 'the body is not JSON'],
        'an empty object' => ['{}', 'the response holds no model list'],
        'a list under another name' => ['{"data":[{"name":"example-chat-1"}]}', 'the response holds no model list'],
        'a bare list' => ['[{"name":"example-chat-1"}]', 'the response holds no model list'],
        'models that are an object' => ['{"models":{"a":{"name":"example-chat-1"}}}', 'the response holds no model list'],
        'models that are a word' => ['{"models":"none"}', 'the response holds no model list'],
        'the error body of the format' => ['{"id":"synthetic-error-1","message":"invalid api token"}', 'the response holds an error'],
    ]);

    it('does not take a message for an error when the body holds a list beside it', function (): void {
        $transport = (new FakeTransport())->willReturn(chatV2Models(['message' => 'Some models are hidden.', 'models' => [['name' => 'example-chat-1']]]));

        expect(ChatV2::gateway($transport)->models(10.0))->toHaveCount(1);
    });

    it('is refused as not supported, and nothing is sent, where the preset says the models cannot be listed', function (): void {
        $transport = new FakeTransport();
        $error = Talk::errorOf(fn() => ChatV2::gateway($transport, ['model_listing' => 'none'])->models(10.0));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($transport->requests())->toBe([]);
    });

    it('reads a failed list as every failed response, and tries a list that may be tried again once more', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::failed(503))->willReturn(chatV2Models(['models' => [['name' => 'example-chat-1']]]))->willReturn(ChatV2::failed(401, 'invalid api token'));
        $gateway = ChatV2::gateway($transport, retry: new RetryPolicy([1.0], 60.0));

        expect($gateway->models(10.0))->toHaveCount(1)
            ->and(Talk::errorOf(fn() => $gateway->models(10.0))->reason)->toBe(ErrorReason::CredentialsRejected);
    });
});
