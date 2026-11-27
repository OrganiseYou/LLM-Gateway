<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\ErrorBody;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// From a failed response to its reason, in the chat-completions adapter (docs/design/adapters.md, sections 2.1 and
// 5.6): the status decides, except for a status 429, which the type in the body can turn into "no credit" or into
// "the provider is overloaded". The error bodies are written by hand from the shapes the provider files record.

/**
 * What a call ends in when the server first gives this failed response and would then give an answer, with one
 * retry allowed: the error or null, how many requests were sent, and the waits.
 *
 * @return array{?GatewayException, int, list<float>}
 */
function afterAFailure(HttpResponse $failed, bool $asStream = false): array
{
    $clock = new FakeClock();
    $transport = new FakeTransport($clock);
    $transport->willReturn($failed);
    if ($asStream) {
        $transport->willStream(200, [], ChatCompletions::chunk(['content' => 'Hello.'], 'stop'), ChatCompletions::DONE);
    } else {
        $transport->willReturn(ChatCompletions::answered());
    }
    $gateway = ChatCompletions::gateway($transport, retry: new RetryPolicy([1.0], 60.0), clock: $clock);

    $error = null;
    if ($asStream) {
        $events = Talk::events($gateway->stream(ChatCompletions::request()));
        $last = $events[count($events) - 1];
        $error = $last instanceof StreamFailed ? $last->error : null;
    } else {
        try {
            $gateway->complete(ChatCompletions::request());
        } catch (GatewayException $thrown) {
            $error = $thrown;
        }
    }

    return [$error, count($transport->requests()), $clock->sleeps()];
}

/**
 * An error body with an error object.
 *
 * @param array<string, mixed> $error
 */
function errorObject(array $error): string
{
    return ChatCompletions::json(['error' => ['message' => 'A sentence of the provider that is never shown.', ...$error]]);
}

describe('a status 429', function (): void {
    it('with a type that means "no credit" is rejected, with the code, and is not tried again', function (string $body, string $code, bool $asStream): void {
        // The response even asks for a wait: a caller that waited would never get through.
        [$error, $requests, $waits] = afterAFailure(new HttpResponse(429, ['Retry-After' => '7', 'retry-after-ms' => '7000'], $body), $asStream);

        expect($error?->reason)->toBe(ErrorReason::Rejected)
            ->and($error?->httpStatus)->toBe(429)
            ->and($error?->providerCode)->toBe($code)
            ->and($error?->retryAfterSeconds)->toBeNull()
            ->and($error?->attempts)->toBe(1)
            ->and($requests)->toBe(1)
            ->and($waits)->toBe([]);
    })->with([
        'insufficient_quota, with a code beside it' => [errorObject(['type' => 'insufficient_quota', 'param' => null, 'code' => 'credit_balance_exhausted']), 'credit_balance_exhausted', false],
        'insufficient_quota, without a code' => [errorObject(['type' => 'insufficient_quota', 'param' => null, 'code' => null]), 'insufficient_quota', false],
        'insufficient_quota, as a stream' => [errorObject(['type' => 'insufficient_quota', 'code' => 'organization_spend_limit_exceeded']), 'organization_spend_limit_exceeded', true],
        'exceeded_current_quota_error' => [errorObject(['type' => 'exceeded_current_quota_error']), 'exceeded_current_quota_error', false],
        'exceeded_current_quota_error, as a stream' => [errorObject(['type' => 'exceeded_current_quota_error']), 'exceeded_current_quota_error', true],
        'insufficient_quota, as a flat type' => ['{"object":"error","message":"No credit.","type":"insufficient_quota","param":null,"code":"2308"}', 'insufficient_quota', false],
        'exceeded_current_quota_error, as a flat type' => ['{"type":"exceeded_current_quota_error","message":"No credit."}', 'exceeded_current_quota_error', false],
    ]);

    it('with a type that means "the provider is overloaded" is unavailable, and is tried again', function (string $body, string $code, bool $asStream): void {
        [$error, $requests, $waits] = afterAFailure(new HttpResponse(429, [], $body), $asStream);
        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($waits)->toBe([1.0]);

        // And with no retry allowed it is the error of the call, with its own reason.
        $transport = (new FakeTransport())->willReturn(new HttpResponse(429, ['Retry-After' => '3'], $body));
        $last = Talk::errorOf(fn() => ChatCompletions::gateway($transport)->complete(ChatCompletions::request()));
        expect($last->reason)->toBe(ErrorReason::Unavailable)
            ->and($last->httpStatus)->toBe(429)
            ->and($last->providerCode)->toBe($code)
            ->and($last->retryAfterSeconds)->toBe(3);
    })->with([
        'engine_overloaded_error' => [errorObject(['type' => 'engine_overloaded_error']), 'engine_overloaded_error', false],
        'engine_overloaded_error, as a stream' => [errorObject(['type' => 'engine_overloaded_error']), 'engine_overloaded_error', true],
        'backend_out_of_capacity, as a flat type' => ['{"object":"error","message":"No capacity.","type":"backend_out_of_capacity","param":null,"code":"3505","raw_status_code":429}', 'backend_out_of_capacity', false],
        'backend_out_of_capacity, in an error object' => [errorObject(['type' => 'backend_out_of_capacity', 'code' => '3801']), 'backend_out_of_capacity', false],
    ]);

    it('with any other body is the limit of the customer: rate_limited, and tried again', function (string $body, ?string $code): void {
        [$error, $requests, $waits] = afterAFailure(new HttpResponse(429, [], $body));
        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($waits)->toBe([1.0]);

        $transport = (new FakeTransport())->willReturn(new HttpResponse(429, [], $body));
        $last = Talk::errorOf(fn() => ChatCompletions::gateway($transport)->complete(ChatCompletions::request()));
        expect($last->reason)->toBe(ErrorReason::RateLimited)
            ->and($last->providerCode)->toBe($code);
    })->with([
        'a rate limit with a code' => [errorObject(['type' => 'rate_limit_error', 'code' => 'slow_down']), 'slow_down'],
        'a rate limit with a type only' => [errorObject(['type' => 'rate_limit_reached_error']), 'rate_limit_reached_error'],
        'a rate limit as a flat body: a monthly limit there cannot be told from it' => ['{"object":"error","message":"Rate limit.","type":"rate_limited","param":null,"code":"1300","raw_status_code":429}', 'rate_limited'],
        'a flat body with a word under error' => ['{"id":null,"code":429,"error":"too_many_requests","detail":"Wait."}', 'too_many_requests'],
        'no body' => ['', null],
        'a body that is no JSON' => ['Too Many Requests', null],
        // The two lists are read from the type alone: a word of them under another name changes nothing.
        'a "no credit" word as the code, not as the type' => [errorObject(['type' => 'rate_limit_error', 'code' => 'insufficient_quota']), 'insufficient_quota'],
        'a "no credit" word in the message' => ['{"error":{"message":"insufficient_quota"}}', null],
        'a "no credit" word in other letters' => [errorObject(['type' => 'Insufficient_Quota']), 'insufficient_quota'],
        'a type that is no word' => ['{"error":{"type":["insufficient_quota"]}}', null],
    ]);

    it('keeps the two word lists to that status: elsewhere the status decides', function (int $status, string $type, ErrorReason $reason): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse($status, [], errorObject(['type' => $type])));
        $error = Talk::errorOf(fn() => ChatCompletions::gateway($transport)->complete(ChatCompletions::request()));

        expect($error->reason)->toBe($reason)
            ->and($error->providerCode)->toBe($type);
    })->with([
        'no credit under a 400' => [400, 'insufficient_quota', ErrorReason::Rejected],
        'no credit under a 503' => [503, 'insufficient_quota', ErrorReason::Unavailable],
        'overloaded under a 400' => [400, 'engine_overloaded_error', ErrorReason::Rejected],
        'overloaded under a 401' => [401, 'backend_out_of_capacity', ErrorReason::CredentialsRejected],
    ]);
});

describe('the other statuses', function (): void {
    it('give the reason of the status, with the code of the body where it has one, and are not tried again', function (int $status, string $body, ErrorReason $reason, ?string $code): void {
        [$error, $requests, $waits] = afterAFailure(new HttpResponse($status, ['Content-Type' => 'application/json'], $body));

        expect($error?->reason)->toBe($reason)
            ->and($error?->httpStatus)->toBe($status)
            ->and($error?->providerCode)->toBe($code)
            ->and($error?->getMessage())->not->toContain('never shown')
            ->and($requests)->toBe(1)
            ->and($waits)->toBe([]);
    })->with([
        'a 402 for a billing block' => [402, '{"object":"error","message":"Out of credits.","type":"payment_required","param":null,"code":"2308","raw_status_code":402}', ErrorReason::Rejected, 'payment_required'],
        'a 402 with a code only' => [402, '{"object":"error","message":"Out of credits.","code":"2308"}', ErrorReason::Rejected, '2308'],
        'a 400 for a wrong key, without a documented body' => [400, '', ErrorReason::Rejected, null],
        'a 400 for an unknown model' => [400, '{"object":"error","message":"Invalid model: example","type":"invalid_model","param":null,"code":"1500","raw_status_code":400}', ErrorReason::Rejected, 'invalid_model'],
        'a 404 for an unknown model' => [404, errorObject(['type' => 'resource_not_found_error']), ErrorReason::Rejected, 'resource_not_found_error'],
        'a 404 without a body' => [404, '', ErrorReason::Rejected, null],
        'a 400 for a refused prompt' => [400, '{"error":{"message":"A sentence of the provider that is never shown.","type":null,"param":"prompt","code":"content_filter","status":400}}', ErrorReason::Rejected, 'content_filter'],
        'a 400 for a refused prompt, by its type' => [400, errorObject(['type' => 'content_filter']), ErrorReason::Rejected, 'content_filter'],
        'a 400 for an invalid request' => [400, errorObject(['type' => 'invalid_request_error', 'param' => 'messages', 'code' => null]), ErrorReason::Rejected, 'invalid_request_error'],
        'a 401 with an error object' => [401, errorObject(['type' => 'invalid_authentication_error']), ErrorReason::CredentialsRejected, 'invalid_authentication_error'],
        'a 401 with only a detail' => [401, '{"detail":"Invalid API Key"}', ErrorReason::CredentialsRejected, null],
        'a 401 as a flat body' => [401, '{"id":null,"code":401,"error":"unauthorized","detail":"No credentials.","doc_url":"https://docs.example.test/errors#unauthorized"}', ErrorReason::CredentialsRejected, 'unauthorized'],
        'a 403' => [403, errorObject(['type' => 'permission_denied_error']), ErrorReason::CredentialsRejected, 'permission_denied_error'],
        'a 422 with a list of fields' => [422, '{"detail":[{"loc":["body","messages"],"msg":"Field required"}]}', ErrorReason::Rejected, null],
        'a 413' => [413, '{"id":null,"code":413,"error":"payload_too_large","detail":"Too large."}', ErrorReason::Rejected, 'payload_too_large'],
    ]);

    it('are tried again when the server failed, whatever its body is', function (int $status, string $body, ?string $code): void {
        [$error, $requests, $waits] = afterAFailure(new HttpResponse($status, [], $body));
        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($waits)->toBe([1.0]);

        $transport = (new FakeTransport())->willReturn(new HttpResponse($status, [], $body));
        $last = Talk::errorOf(fn() => ChatCompletions::gateway($transport)->complete(ChatCompletions::request()));
        expect($last->reason)->toBe(ErrorReason::Unavailable)
            ->and($last->httpStatus)->toBe($status)
            ->and($last->providerCode)->toBe($code);
    })->with([
        'a 500 with an error object' => [500, errorObject(['type' => 'server_error']), 'server_error'],
        'a 503 with an error object' => [503, errorObject(['type' => 'service_unavailable_error', 'code' => 'server_is_overloaded']), 'server_is_overloaded'],
        'a 503 during maintenance' => [503, '{"detail":"Back soon.","error":"service_maintenance","mode":"full_shutdown"}', 'service_maintenance'],
        'a 503 with a plain sentence' => [503, 'API is disabled for this instance.', null],
        'a 504 with a page of HTML' => [504, '<html><head><title>504 Gateway Time-out</title></head><body><center><h1>504 Gateway Time-out</h1></center></body></html>', null],
        'a 529' => [529, '', null],
        'a 502' => [502, '', null],
        'a 408' => [408, '', null],
    ]);
});

describe('the code of the provider', function (): void {
    it('is read from the first of five places that has one', function (string $body, ?string $code): void {
        expect(ErrorBody::code(ErrorBody::decoded($body)))->toBe($code);
    })->with([
        'error.code when it is a word' => ['{"error":{"code":"slow_down","type":"rate_limit_error"},"type":"flat","code":"9"}', 'slow_down'],
        'error.type when the code is a number as text' => ['{"error":{"code":"1300","type":"rate_limited"}}', 'rate_limited'],
        'error.type when the code is a number' => ['{"error":{"code":429,"type":"rate_limited"}}', 'rate_limited'],
        'error.type when the code is null' => ['{"error":{"code":null,"type":"invalid_request_error"}}', 'invalid_request_error'],
        'error.type when the code is a sentence' => ['{"error":{"code":"not a word","type":"invalid_request_error"}}', 'invalid_request_error'],
        'error.type when the code is empty' => ['{"error":{"code":"","type":"server_error"}}', 'server_error'],
        'a top-level error that is a word' => ['{"error":"unauthorized","type":"flat","code":401}', 'unauthorized'],
        'a top-level type' => ['{"object":"error","type":"invalid_model","code":"1500"}', 'invalid_model'],
        'a top-level type under an error object without a word' => ['{"error":{"message":"No."},"type":"invalid_model"}', 'invalid_model'],
        'a top-level code as text' => ['{"object":"error","message":"No.","code":"1500"}', '1500'],
        'a top-level code as a number' => ['{"message":"No.","code":402}', '402'],
        'nothing: a word under param or message, in an error object' => ['{"error":{"message":"invalid_api_key","param":"model_not_found","status":400,"inner_error":{"code":"inner"}}}', null],
        'nothing: a word under param, message, detail or object, in a flat body' => ['{"object":"error","message":"invalid_api_key","param":"model_not_found","detail":"unauthorized","raw_status_code":400}', null],
        'the code, with a word under param and message beside it' => ['{"error":{"message":"other_word","param":"third_word","code":"model_not_found","type":"invalid_request_error"}}', 'model_not_found'],
        'the type, with a word under param and message beside it' => ['{"error":{"message":"other_word","param":"third_word","code":null,"type":"invalid_request_error"}}', 'invalid_request_error'],
        'nothing: only a detail' => ['{"detail":"Invalid API Key"}', null],
        'nothing: an error object without a word' => ['{"error":{"message":"No.","param":null}}', null],
        'nothing: fields of other types' => ['{"error":{"code":["a"],"type":7},"type":false,"code":1.5}', null],
        'nothing: an empty object' => ['{}', null],
        'nothing: a list' => ['[]', null],
        'nothing: a body that is no JSON' => ['<html>502</html>', null],
        'nothing: an empty body' => ['', null],
        'nothing: a JSON string' => ['"unauthorized"', null],
    ]);

    it('is shown in small letters, and dropped when it is no plain word', function (string $body, ?string $code): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(400, [], $body));

        expect(Talk::errorOf(fn() => ChatCompletions::gateway($transport)->complete(ChatCompletions::request()))->providerCode)->toBe($code);
    })->with([
        'capitals' => ['{"error":{"type":"INVALID_REQUEST"}}', 'invalid_request'],
        'a sentence as the type' => ['{"error":{"type":"The request was not valid."}}', null],
        'a word that is too long' => ['{"error":"' . str_repeat('a', 65) . '"}', null],
    ]);
});

describe('the wait a failed response asks for', function (): void {
    it('is kept when it comes in retry-after-ms, which is in milliseconds', function (string $milliseconds, float $wait): void {
        [$error, $requests, $waits] = afterAFailure(new HttpResponse(429, ['retry-after-ms' => $milliseconds], errorObject(['type' => 'rate_limit_error'])));

        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($waits)->toBe([$wait]);
    })->with([
        'two and a half seconds' => ['2500', 2.5],
        'seven seconds' => ['7000', 7.0],
        'a fraction of a millisecond' => ['250.5', 0.2505],
        'nothing to wait' => ['0', 0.0],
        'with white space around it' => [' 1500 ', 1.5],
    ]);

    it('is kept from retry-after-ms for a server that is unavailable too, and as a stream', function (): void {
        [$error, $requests, $waits] = afterAFailure(new HttpResponse(503, ['Retry-After-Ms' => '4000'], ''), true);

        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($waits)->toBe([4.0]);
    });

    it('is told in whole seconds, rounded up, when it is longer than the caller allows', function (): void {
        [$error, $requests, $waits] = afterAFailure(new HttpResponse(429, ['retry-after-ms' => '120500'], ''));

        expect($error?->reason)->toBe(ErrorReason::RateLimited)
            ->and($error?->retryAfterSeconds)->toBe(121)
            ->and($requests)->toBe(1)
            ->and($waits)->toBe([]);
    });

    it('is the one of Retry-After, in seconds, when the response has both', function (): void {
        [, , $waits] = afterAFailure(new HttpResponse(429, ['Retry-After' => '3', 'retry-after-ms' => '9000'], ''));

        expect($waits)->toBe([3.0]);
    });

    it('is the one of the retry policy when neither header tells a wait that can be read', function (array $headers): void {
        [$error, $requests, $waits] = afterAFailure(new HttpResponse(429, $headers, ''));

        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($waits)->toBe([1.0]);
    })->with([
        'no header' => [[]],
        'milliseconds that are no number' => [['retry-after-ms' => 'soon']],
        'milliseconds below 0' => [['retry-after-ms' => '-500']],
        'milliseconds with a unit' => [['retry-after-ms' => '500ms']],
        'an empty header' => [['retry-after-ms' => '']],
        // The format of these reset values is not documented: they are not read.
        'only the reset values of a rate limit' => [['x-ratelimit-reset-requests' => '6m0s', 'x-ratelimit-reset-tokens' => '1s', 'x-ratelimit-remaining-requests' => '0']],
    ]);
});

describe('a 429 that forbids a retry', function (): void {
    it('is the one whose body says the account is out of credit: it fails at once, tells no wait and keeps its code', function (): void {
        $body = errorObject(['type' => 'insufficient_quota', 'code' => 'project_spend_limit_exceeded']);
        $clock = new FakeClock();
        $transport = new FakeTransport($clock);
        // Enough answers for three attempts, and a policy that allows them.
        $transport->willReturn(new HttpResponse(429, ['Retry-After' => '2', 'retry-after-ms' => '2000'], $body))->willReturn(ChatCompletions::answered())->willReturn(ChatCompletions::answered());
        $gateway = ChatCompletions::gateway($transport, retry: new RetryPolicy([1.0, 2.0], 60.0), clock: $clock);

        $error = Talk::errorOf(fn() => $gateway->complete(ChatCompletions::request()));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->httpStatus)->toBe(429)
            ->and($error->providerCode)->toBe('project_spend_limit_exceeded')
            ->and($error->retryAfterSeconds)->toBeNull()
            ->and($error->attempts)->toBe(1)
            ->and($transport->requests())->toHaveCount(1)
            ->and($transport->remaining())->toBe(2)
            ->and($clock->sleeps())->toBe([]);
    });

    it('is no other: this format documents no header and no field that forbids a retry, so every other 429 is tried again', function (): void {
        // A header another format documents for this is no sign here.
        [$error, $requests, $waits] = afterAFailure(new HttpResponse(429, ['x-should-retry' => 'false'], errorObject(['type' => 'rate_limit_error'])));

        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($waits)->toBe([1.0]);
    });
});

describe('the model list and the error of a failed request', function (): void {
    it('reads a failed model list as it reads a failed completion', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(429, [], errorObject(['type' => 'exceeded_current_quota_error'])));
        $error = Talk::errorOf(fn() => ChatCompletions::gateway($transport, retry: new RetryPolicy([1.0], 60.0))->models(10.0));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->providerCode)->toBe('exceeded_current_quota_error')
            ->and($transport->requests())->toHaveCount(1);
    });
});

describe('the three error bodies of a server with a flat error body', function (): void {
    // docs/providers/mistral.md, "Limits and shapes": a flat object with a type and a number as its code for most
    // errors, a body with only a sentence under `detail` for a 401, and a list under `detail` for a 422. The
    // sentences below are made up, and none of them may leave the library.
    $bodies = [
        'the flat body, for an unknown model' => [400, '{"object":"error","message":"A sentence of the provider that is never shown: example-large-9","type":"invalid_model","param":null,"code":"1500","raw_status_code":400}', ErrorReason::Rejected, 'invalid_model', 1],
        'the flat body, for a prompt that is too long' => [400, '{"object":"error","message":"A sentence of the provider that is never shown.","type":"invalid_request_prompt_too_long","param":null,"code":"3059","raw_status_code":400}', ErrorReason::Rejected, 'invalid_request_prompt_too_long', 1],
        'the flat body, for a billing block' => [402, '{"object":"error","message":"A sentence of the provider that is never shown.","type":"payment_required","param":null,"code":"2308","raw_status_code":402}', ErrorReason::Rejected, 'payment_required', 1],
        'the flat body with a code and no type' => [402, '{"object":"error","message":"A sentence of the provider that is never shown.","param":null,"code":"2300","raw_status_code":402}', ErrorReason::Rejected, '2300', 1],
        'the flat body, for a rate limit' => [429, '{"object":"error","message":"A sentence of the provider that is never shown.","type":"rate_limited","param":null,"code":"1300","raw_status_code":429}', ErrorReason::RateLimited, 'rate_limited', 2],
        'the flat body, for a provider without capacity' => [429, '{"object":"error","message":"A sentence of the provider that is never shown.","type":"backend_out_of_capacity","param":null,"code":"3505","raw_status_code":429}', ErrorReason::Unavailable, 'backend_out_of_capacity', 2],
        'the flat body, for a server error' => [500, '{"object":"error","message":"A sentence of the provider that is never shown.","type":"internal_server_error","param":null,"code":"1000","raw_status_code":500}', ErrorReason::Unavailable, 'internal_server_error', 2],
        'a sentence under detail, for a key that is refused' => [401, '{"detail":"A sentence of the provider that is never shown."}', ErrorReason::CredentialsRejected, null, 1],
        'a list under detail, for a field that is refused' => [422, '{"detail":[{"loc":["body","messages",0,"never_shown_field"],"msg":"A sentence of the provider that is never shown.","type":"never_shown_type","input":"never shown input"}]}', ErrorReason::Rejected, null, 1],
        'a list under detail with nothing in it' => [422, '{"detail":[]}', ErrorReason::Rejected, null, 1],
    ];

    it('give their reason and the code of the provider, and nothing of their words', function (int $status, string $body, ErrorReason $reason, ?string $code, int $requests): void {
        foreach ([false, true] as $asStream) {
            $clock = new FakeClock();
            $transport = new FakeTransport($clock);
            // The same failure twice: what is tried again fails again, and the error is the one of this body.
            $transport->willReturn(new HttpResponse($status, ['Content-Type' => 'application/json'], $body))
                ->willReturn(new HttpResponse($status, ['Content-Type' => 'application/json'], $body));
            $gateway = ChatCompletions::gateway($transport, ChatCompletions::CONTENT_ENTRIES_SERVER, new RetryPolicy([1.0], 60.0), $clock);

            if ($asStream) {
                $events = Talk::events($gateway->stream(ChatCompletions::request()));
                $error = Talk::failedIn($events)->error;
                $shown = json_encode(valueForms($events));
            } else {
                $error = Talk::errorOf(fn() => $gateway->complete(ChatCompletions::request()));
                $shown = '';
            }
            $shown .= json_encode([exceptionForms($error), valueForms($error)]);

            expect($error->reason)->toBe($reason)
                ->and($error->httpStatus)->toBe($status)
                ->and($error->providerCode)->toBe($code)
                ->and(count($transport->requests()))->toBe($requests)
                // Not a word of the body but its code: no sentence, no field name, no input.
                ->and(stripos($shown, 'never shown'))->toBeFalse()
                ->and(stripos($shown, 'never_shown'))->toBeFalse()
                ->and(stripos($shown, 'example-large-9'))->toBeFalse()
                ->and((string) $error->detail)->not->toContain((string) $code === '' ? 'never' : (string) $code);
        }
    })->with($bodies);

    it('never name a provider in what they give: the detail is a sentence of the library or nothing', function (int $status, string $body): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse($status, [], $body));
        $error = Talk::errorOf(fn() => ChatCompletions::gateway($transport, ChatCompletions::CONTENT_ENTRIES_SERVER)->complete(ChatCompletions::request()));

        expect($error->detail)->toBeNull();
    })->with(array_map(fn(array $case): array => [$case[0], $case[1]], $bodies));

    it('read the flat body as an error under a successful status too, with its code', function (): void {
        $flat = '{"object":"error","message":"A sentence of the provider that is never shown.","type":"internal_server_error","param":null,"code":"1000","raw_status_code":500}';

        $error = Talk::errorOf(fn() => ChatCompletions::answerTo(new HttpResponse(200, [], $flat), ChatCompletions::CONTENT_ENTRIES_SERVER));
        $failed = Talk::failedIn(ChatCompletions::eventsOf([ChatCompletions::chunk(['content' => 'Hel']), 'data: ' . $flat . "\n\n"], ChatCompletions::CONTENT_ENTRIES_SERVER))->error;

        foreach ([$error, $failed] as $read) {
            expect($read->reason)->toBe(ErrorReason::ProviderError)
                ->and($read->providerCode)->toBe('internal_server_error')
                ->and($read->getMessage())->not->toContain('never shown');
        }
    });

    it('do not take an answer for an error because of the word under object', function (): void {
        expect(ErrorBody::isIn(['object' => 'chat.completion', 'choices' => []]))->toBeFalse()
            ->and(ErrorBody::isIn(['object' => 'error']))->toBeTrue()
            ->and(ErrorBody::isIn(['object' => ['error']]))->toBeFalse()
            ->and(ErrorBody::isIn(['detail' => 'No.']))->toBeFalse();

        // An error member beside a choice does not throw the answer away, whatever the body calls itself.
        $answer = ChatCompletions::answerTo(ChatCompletions::answered(more: ['object' => 'error']), ChatCompletions::CONTENT_ENTRIES_SERVER);
        expect($answer->text)->toBe('Hello.');
    });

    it('ends an answer that the server ended with its word for an error as a provider error', function (): void {
        $error = Talk::errorOf(fn() => ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hel'], 'error'), ChatCompletions::CONTENT_ENTRIES_SERVER));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->providerCode)->toBe('error');
    });
});
