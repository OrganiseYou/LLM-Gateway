<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Anthropic\ErrorBody;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// From a failed response to its reason, in the Messages adapter (docs/design/adapters.md, sections 4.1 and 5.6): the
// status decides, except for a status 429 whose code says the spend cap was reached. The code is the error code of
// the body when it has one, otherwise its type. The header x-should-retry with the word false stops a retry. The
// error bodies are written by hand from the shape docs/providers/anthropic.md records.

/**
 * What a call ends in when the server first gives this failed response and would then give an answer, with one
 * retry allowed: the error or null, how many requests were sent, and the waits.
 *
 * @return array{?GatewayException, int, list<float>}
 */
function afterAMessagesFailure(HttpResponse $failed, bool $asStream = false, bool $models = false): array
{
    $clock = new FakeClock();
    $transport = new FakeTransport($clock);
    $transport->willReturn($failed);
    if ($models) {
        $transport->willReturn(new HttpResponse(200, [], '{"data":[]}'));
    } elseif ($asStream) {
        $transport->willStream(200, [], ...Messages::saying());
    } else {
        $transport->willReturn(Messages::answered());
    }
    $gateway = Messages::gateway($transport, retry: new RetryPolicy([1.0], 60.0), clock: $clock);

    $error = null;
    if ($asStream) {
        $events = Talk::events($gateway->stream(Messages::request()));
        $last = $events[count($events) - 1];
        $error = $last instanceof StreamFailed ? $last->error : null;
    } else {
        try {
            $models ? $gateway->models(30.0) : $gateway->complete(Messages::request());
        } catch (GatewayException $thrown) {
            $error = $thrown;
        }
    }

    return [$error, count($transport->requests()), $clock->sleeps()];
}

describe('the error table of the Messages format', function (): void {
    it('gives every documented status and type its reason, with the type as the code', function (int $status, string $type, ErrorReason $reason, bool $retried): void {
        foreach ([false, true] as $asStream) {
            [$error, $requests, $waits] = afterAMessagesFailure(Messages::failed($status, $type), $asStream);

            if ($retried) {
                expect($error)->toBeNull($status . ' is tried again')
                    ->and($requests)->toBe(2)
                    ->and($waits)->toBe([1.0]);

                // With no retry left, the failure is the error.
                $transport = (new FakeTransport())->willReturn(Messages::failed($status, $type));
                $error = Talk::errorOf(fn() => Messages::gateway($transport)->complete(Messages::request()));
            } else {
                expect($requests)->toBe(1, $status . ' is not tried again')
                    ->and($waits)->toBe([]);
            }

            expect($error)->not->toBeNull();
            expect($error?->reason)->toBe($reason)
                ->and($error?->providerCode)->toBe($type)
                ->and($error?->httpStatus)->toBe($status)
                ->and($error?->detail)->toBeNull();
        }
    })->with([
        'a refused request' => [400, 'invalid_request_error', ErrorReason::Rejected, false],
        'a bad key' => [401, 'authentication_error', ErrorReason::CredentialsRejected, false],
        'a billing problem' => [402, 'billing_error', ErrorReason::Rejected, false],
        'a key that may not use the resource' => [403, 'permission_error', ErrorReason::CredentialsRejected, false],
        'not found' => [404, 'not_found_error', ErrorReason::Rejected, false],
        'a conflict' => [409, 'conflict_error', ErrorReason::Rejected, false],
        'a body that is too large' => [413, 'request_too_large', ErrorReason::Rejected, false],
        'a rate limit' => [429, 'rate_limit_error', ErrorReason::RateLimited, true],
        'a failure of the provider' => [500, 'api_error', ErrorReason::Unavailable, true],
        'a timeout' => [504, 'timeout_error', ErrorReason::Unavailable, true],
        'a timeout with another status' => [500, 'timeout_error', ErrorReason::Unavailable, true],
        'overloaded' => [529, 'overloaded_error', ErrorReason::Unavailable, true],
        'overloaded with another status' => [503, 'overloaded_error', ErrorReason::Unavailable, true],
        'a type of a later version, by its status' => [422, 'some_new_error', ErrorReason::Rejected, false],
    ]);

    it('takes the error code of the body as the code when it has one, and the type otherwise', function (string $body, ?string $code): void {
        [$error] = afterAMessagesFailure(new HttpResponse(400, [], $body));

        expect($error?->reason)->toBe(ErrorReason::Rejected)
            ->and($error?->providerCode)->toBe($code)
            ->and(ErrorBody::code(ErrorBody::decoded($body)))->toBe($code);
    })->with([
        'a type and a code' => [Messages::errorBody('invalid_request_error', 'organization_on_hold'), 'organization_on_hold'],
        'only a type' => [Messages::errorBody('invalid_request_error'), 'invalid_request_error'],
        'details without a code' => ['{"type":"error","error":{"type":"invalid_request_error","message":"No.","details":{}}}', 'invalid_request_error'],
        'details that are null' => ['{"type":"error","error":{"type":"invalid_request_error","message":"No.","details":null}}', 'invalid_request_error'],
        'a code that is a number' => ['{"type":"error","error":{"type":"invalid_request_error","details":{"error_code":42}}}', 'invalid_request_error'],
        'an empty code' => ['{"type":"error","error":{"type":"invalid_request_error","details":{"error_code":""}}}', 'invalid_request_error'],
        'never the message' => ['{"type":"error","error":{"message":"the_words_of_the_provider"},"request_id":"req_example01"}', null],
        'an error that is a word' => ['{"type":"error","error":"invalid_request_error"}', null],
        'a type that is a list' => ['{"type":"error","error":{"type":["invalid_request_error"]}}', null],
        'a body that is no JSON' => ['<html>Bad Request</html>', null],
        'an empty body' => ['', null],
        'a JSON list' => ['[1,2]', null],
    ]);

    it('reads the reason from the status when the body cannot be read', function (int $status, string $body, ErrorReason $reason): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse($status, [], $body));
        $error = Talk::errorOf(fn() => Messages::gateway($transport)->complete(Messages::request()));

        expect($error->reason)->toBe($reason)
            ->and($error->providerCode)->toBeNull()
            ->and($error->httpStatus)->toBe($status);
    })->with([
        [529, '<html>Overloaded</html>', ErrorReason::Unavailable],
        [503, '', ErrorReason::Unavailable],
        [429, 'slow down', ErrorReason::RateLimited],
        [401, '{"type":"error"}', ErrorReason::CredentialsRejected],
        [404, '{"error":null}', ErrorReason::Rejected],
    ]);
});

describe('the spend cap', function (): void {
    it('ends a 429 whose code says the spend cap was reached as rejected, with that code, not tried again and with no wait', function (bool $asStream): void {
        $failed = Messages::failed(429, 'rate_limit_error', ['retry-after' => '30'], 'enforced_spend_limit_reached');
        [$error, $requests, $waits] = afterAMessagesFailure($failed, $asStream);

        expect($error?->reason)->toBe(ErrorReason::Rejected)
            ->and($error?->providerCode)->toBe('enforced_spend_limit_reached')
            ->and($error?->httpStatus)->toBe(429)
            ->and($error?->retryAfterSeconds)->toBeNull()
            ->and($error?->attempts)->toBe(1)
            ->and($requests)->toBe(1)
            ->and($waits)->toBe([]);
    })->with(['through complete()' => [false], 'through stream()' => [true]]);

    it('keeps a 429 with another code as a rate limit that is tried again', function (?string $code): void {
        [$error, $requests, $waits] = afterAMessagesFailure(Messages::failed(429, 'rate_limit_error', [], $code));

        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($waits)->toBe([1.0]);
    })->with(['the code of a ramp limit' => ['ramp_limit_exceeded'], 'no code' => [null], 'a code of a later version' => ['some_new_limit']]);

    it('reads that code only for a status 429', function (): void {
        [$error, $requests] = afterAMessagesFailure(Messages::failed(500, 'api_error', [], 'enforced_spend_limit_reached'));

        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and(ErrorBody::saysSpendLimit(ErrorBody::decoded(Messages::errorBody('rate_limit_error', 'enforced_spend_limit_reached'))))->toBeTrue()
            ->and(ErrorBody::saysSpendLimit(ErrorBody::decoded(Messages::errorBody('rate_limit_error'))))->toBeFalse()
            ->and(ErrorBody::saysSpendLimit(null))->toBeFalse();
    });
});

describe('the header that forbids a retry', function (): void {
    it('stops the retry of a 429 and of a 5xx, keeps the reason of the status and tells no wait', function (int $status, string $type, ErrorReason $reason, string $word): void {
        foreach (['complete', 'stream', 'models'] as $operation) {
            $failed = Messages::failed($status, $type, ['x-should-retry' => $word, 'retry-after' => '7']);
            [$error, $requests, $waits] = afterAMessagesFailure($failed, $operation === 'stream', $operation === 'models');

            expect($error?->reason)->toBe($reason, $operation)
                ->and($error?->httpStatus)->toBe($status, $operation)
                ->and($error?->providerCode)->toBe($type, $operation)
                ->and($error?->attempts)->toBe(1, $operation)
                ->and($error?->retryAfterSeconds)->toBeNull($operation . ': an error that must not be tried again tells no wait')
                ->and($requests)->toBe(1, $operation)
                ->and($waits)->toBe([], $operation);
        }
    })->with([
        'a rate limit' => [429, 'rate_limit_error', ErrorReason::RateLimited, 'false'],
        'a failure of the provider' => [500, 'api_error', ErrorReason::Unavailable, 'false'],
        'unavailable' => [503, 'overloaded_error', ErrorReason::Unavailable, 'false'],
        'a timeout' => [504, 'timeout_error', ErrorReason::Unavailable, 'false'],
        'overloaded' => [529, 'overloaded_error', ErrorReason::Unavailable, 'false'],
        'the word in capitals' => [529, 'overloaded_error', ErrorReason::Unavailable, 'False'],
        'the word with spaces around it' => [429, 'rate_limit_error', ErrorReason::RateLimited, ' false '],
    ]);

    it('lets the reason decide when the header says true, says another word or is not there', function (array $headers): void {
        [$error, $requests, $waits] = afterAMessagesFailure(Messages::failed(529, 'overloaded_error', $headers));

        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($waits)->toBe([1.0]);
    })->with([
        'true' => [['x-should-retry' => 'true']],
        'another word' => [['x-should-retry' => 'no']],
        'an empty value' => [['x-should-retry' => '']],
        'no header' => [[]],
    ]);

    it('never makes a failure that is not tried again one that is', function (int $status, string $type, ErrorReason $reason): void {
        [$error, $requests] = afterAMessagesFailure(Messages::failed($status, $type, ['x-should-retry' => 'true']));

        expect($error?->reason)->toBe($reason)
            ->and($requests)->toBe(1);
    })->with([
        [400, 'invalid_request_error', ErrorReason::Rejected],
        [401, 'authentication_error', ErrorReason::CredentialsRejected],
        [409, 'conflict_error', ErrorReason::Rejected],
    ]);
});

describe('the wait a failed response asks for', function (): void {
    it('keeps the seconds of retry-after for a rate limit and for an overload', function (int $status, string $type): void {
        [$error, $requests, $waits] = afterAMessagesFailure(Messages::failed($status, $type, ['retry-after' => '7']));

        expect($error)->toBeNull()
            ->and($requests)->toBe(2)
            ->and($waits)->toBe([7.0]);
    })->with([[429, 'rate_limit_error'], [529, 'overloaded_error']]);

    it('tells the wait in the error when it is longer than the policy allows', function (): void {
        [$error, $requests] = afterAMessagesFailure(Messages::failed(429, 'rate_limit_error', ['retry-after' => '120', 'anthropic-ratelimit-requests-reset' => '2026-11-27T12:00:00Z']));

        expect($error?->reason)->toBe(ErrorReason::RateLimited)
            ->and($error?->retryAfterSeconds)->toBe(120)
            ->and($requests)->toBe(1);
    });
});

describe('the reading of an error body', function (): void {
    it('tells whether a body or the data of an event is an error', function (array $body, bool $isError): void {
        expect(ErrorBody::isIn($body))->toBe($isError);
    })->with([
        'the documented body' => [['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'No.']], true],
        'only the type' => [['type' => 'error'], true],
        'only an error object' => [['error' => ['type' => 'api_error']], true],
        'an answer' => [['type' => 'message', 'content' => []], false],
        'an error that is null' => [['type' => 'message', 'error' => null], false],
        'an empty error object' => [['error' => []], false],
        'an error that is a word' => [['error' => 'api_error'], false],
    ]);

    it('gives the reason of an error event by its type', function (array $body, ErrorReason $reason): void {
        expect(ErrorBody::reasonInAStream($body))->toBe($reason);
    })->with([
        [['error' => ['type' => 'overloaded_error']], ErrorReason::Unavailable],
        [['error' => ['type' => 'rate_limit_error']], ErrorReason::RateLimited],
        [['error' => ['type' => 'rate_limit_error', 'details' => ['error_code' => 'enforced_spend_limit_reached']]], ErrorReason::Rejected],
        [['error' => ['type' => 'overloaded_error', 'details' => ['error_code' => 'enforced_spend_limit_reached']]], ErrorReason::Unavailable],
        [['error' => ['type' => 'api_error']], ErrorReason::ProviderError],
        [['error' => 'overloaded_error'], ErrorReason::ProviderError],
        [[], ErrorReason::ProviderError],
    ]);
});
