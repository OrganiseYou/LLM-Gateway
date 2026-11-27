<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Gemini\ModelList;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// The model list of the generate-content adapter (docs/design/adapters.md, section 10): one request for the
// largest page, the id without its prefix, only the models that support the method the adapter calls, and never
// a second request for what a response names.

/**
 * A response with a model list.
 *
 * @param array<string, mixed> $body
 */
function generateModels(array $body): HttpResponse
{
    return new HttpResponse(200, ['Content-Type' => 'application/json'], GenerateContent::json($body));
}

describe('the model list of the generate-content adapter', function (): void {
    it('asks for one page of the largest size under the base URL, signed in as every request, without a body', function (): void {
        $transport = (new FakeTransport())->willReturn(generateModels(['models' => []]));
        GenerateContent::gateway($transport)->models(10.0);
        $sent = $transport->requests()[0];

        expect($transport->requests())->toHaveCount(1)
            ->and($sent->method)->toBe('GET')
            ->and($sent->url)->toBe('https://llm.example.test/v1beta/models?pageSize=1000')
            ->and($sent->body)->toBe('')
            ->and($sent->headers)->toBe([])
            ->and(array_keys($sent->secretHeaders))->toBe(['x-goog-api-key']);
    });

    it('reads the id without its prefix and the input limit as the context length, of the models that support the method', function (): void {
        $transport = (new FakeTransport())->willReturn(generateModels(['models' => [
            [
                'name' => 'models/example-generate-1', 'baseModelId' => 'example-generate', 'inputTokenLimit' => 1000000, 'outputTokenLimit' => 64000,
                'supportedGenerationMethods' => ['generateContent', 'countTokens'], 'temperature' => 1.0, 'maxTemperature' => 2.0, 'thinking' => true,
                'someNewField' => ['x' => 1],
            ],
            ['name' => 'models/example-embed-1', 'inputTokenLimit' => 2048, 'supportedGenerationMethods' => ['embedContent']],
            ['name' => 'example-generate-2', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/example-generate-3', 'inputTokenLimit' => 0, 'supportedGenerationMethods' => ['streamGenerateContent', 'generateContent']],
            ['name' => 'models/example-generate-4', 'inputTokenLimit' => '1000', 'supportedGenerationMethods' => ['generateContent']],
        ]]));

        expect(GenerateContent::gateway($transport)->models(10.0))->toEqual([
            new ModelInfo('example-generate-1', null, 1000000),
            new ModelInfo('example-generate-2'),
            new ModelInfo('example-generate-3'),
            new ModelInfo('example-generate-4'),
        ]);
    });

    it('skips an entry without a usable id, an entry that does not say it supports the method, and anything that is no entry', function (): void {
        $methods = ['supportedGenerationMethods' => ['generateContent']];
        $transport = (new FakeTransport())->willReturn(generateModels(['models' => [
            'a string',
            7,
            null,
            [],
            ['displayName' => 'No name', ...$methods],
            ['name' => 7, ...$methods],
            ['name' => '', ...$methods],
            ['name' => 'models/', ...$methods],
            ['name' => 'models/tuned/example-1', ...$methods],
            ['name' => 'tunedModels/example-1', ...$methods],
            ['name' => 'models/example..one', ...$methods],
            ['name' => 'models/example one', ...$methods],
            ['name' => 'models/example-1:generateContent', ...$methods],
            ['name' => 'https://other.example.test/models/x', ...$methods],
            ['name' => "models/example\r\nx", ...$methods],
            ['name' => 'models/example-no-methods'],
            ['name' => 'models/example-methods-null', 'supportedGenerationMethods' => null],
            ['name' => 'models/example-methods-word', 'supportedGenerationMethods' => 'generateContent'],
            ['name' => 'models/example-other-method', 'supportedGenerationMethods' => ['generatecontent', 'GenerateContent']],
            ['name' => 'models/example-generate-1', ...$methods],
        ]]));

        expect(array_map(fn(ModelInfo $model): string => $model->id, GenerateContent::gateway($transport)->models(10.0)))->toBe(['example-generate-1']);
    });

    it('never asks for a next page and never follows anything a response names: one request, whatever the list says', function (mixed $token): void {
        $transport = (new FakeTransport())
            ->willReturn(generateModels([
                'models' => [['name' => 'models/example-generate-1', 'supportedGenerationMethods' => ['generateContent']]],
                'nextPageToken' => $token,
                'next' => 'https://other.example.test/v1beta/models?pageToken=x',
                'nextPageUrl' => 'https://other.example.test/next',
            ]))
            ->willReturn(generateModels(['models' => [['name' => 'models/example-generate-2', 'supportedGenerationMethods' => ['generateContent']]]]));

        $models = GenerateContent::gateway($transport, retry: new RetryPolicy([1.0], 60.0))->models(10.0);

        expect($models)->toHaveCount(1)
            ->and($transport->requests())->toHaveCount(1)
            ->and($transport->remaining())->toBe(1)
            ->and($transport->requests()[0]->url)->toBe('https://llm.example.test/v1beta/models?pageSize=1000');
    })->with([
        'a token' => ['page-2'],
        'a token that is an address' => ['https://other.example.test/v1beta/models?pageSize=1000'],
        'a token that would change the query' => ['x&pageSize=1&key=1'],
        'a token of another type' => [['a' => 1]],
    ]);

    it('gives an empty list for an account without models, and fails for a body that holds no list', function (): void {
        $transport = (new FakeTransport())->willReturn(generateModels(['models' => []]));

        expect(GenerateContent::gateway($transport)->models(10.0))->toBe([]);
    });

    it('fails as an error of the provider for a body that holds no model list', function (string $body, string $detail, ?string $code): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], $body));
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport)->models(10.0));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->detail)->toBe($detail)
            ->and($error->providerCode)->toBe($code);
    })->with([
        'no JSON' => ['<html>no</html>', 'the body is not JSON', null],
        'an empty body' => ['', 'the body is not JSON', null],
        'an empty object' => ['{}', 'the response holds no model list', null],
        'only a page token' => ['{"nextPageToken":"x"}', 'the response holds no model list', null],
        'models that are a word' => ['{"models":"none"}', 'the response holds no model list', null],
        'models that are an object' => ['{"models":{"first":{"name":"models/x"}}}', 'the response holds no model list', null],
        'the list of another format' => ['{"data":[{"id":"example-generate-1"}]}', 'the response holds no model list', null],
        'the list of the catalogue' => ['{"publisherModels":[{"name":"publishers/example/models/x"}]}', 'the response holds no model list', null],
        'an error under a successful status' => [GenerateContent::errorBody(500, 'INTERNAL', 'EXAMPLE_REASON'), 'the response holds an error', 'example_reason'],
    ]);

    it('does not take an error member for an error when the body holds a list beside it', function (): void {
        $models = ModelList::read(['error' => ['status' => 'INTERNAL'], 'models' => [['name' => 'models/example-generate-1', 'supportedGenerationMethods' => ['generateContent']]]]);

        expect($models)->toHaveCount(1);
    });

    it('is refused as not supported, and nothing is sent, where the preset says the models cannot be listed', function (): void {
        $transport = new FakeTransport();
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport, GenerateContent::TOKEN_ENTRY)->models(10.0));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($transport->requests())->toBe([]);
    });

    it('reads a failed list as every failed response', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::failed(403, 'PERMISSION_DENIED'));
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport)->models(10.0));

        expect($error->reason)->toBe(ErrorReason::CredentialsRejected)
            ->and($error->providerCode)->toBe('permission_denied');
    });
});
