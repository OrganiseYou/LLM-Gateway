<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Cohere\ErrorBody;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// How the Chat v2 adapter reads a failed response (docs/design/adapters.md, sections 4.3 and 5.6): the format has
// no error code, so the status alone decides the reason, nothing of the body is handed on, and no response says
// that it must not be tried again.

describe('the error table of the Chat v2 adapter', function (): void {
    it('gives every documented status its reason, no code, and tries again only what may be tried again', function (int $status, string $message, ErrorReason $expected, bool $retried): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willReturn(ChatV2::failed($status, $message))
            ->willReturn(ChatV2::failed($status, $message));
        $gateway = ChatV2::gateway($transport, retry: new RetryPolicy([1.0], 60.0), clock: $clock);

        $error = Talk::errorOf(fn() => $gateway->complete(ChatV2::request()));

        expect($error->reason)->toBe($expected)
            ->and($error->httpStatus)->toBe($status)
            ->and($error->providerCode)->toBeNull()
            ->and((string) $error->detail)->not->toContain($message)
            ->and($error->getMessage())->not->toContain($message)
            ->and($error->attempts)->toBe($retried ? 2 : 1)
            ->and($transport->requests())->toHaveCount($retried ? 2 : 1);
    })->with([
        '400, an invalid body' => [400, 'invalid request: message must be at least 1 token long', ErrorReason::Rejected, false],
        '401, no key or a wrong key' => [401, 'invalid api token', ErrorReason::CredentialsRejected, false],
        '402, the billing limit' => [402, 'Please add or update your payment method', ErrorReason::Rejected, false],
        '403, forbidden' => [403, 'forbidden', ErrorReason::CredentialsRejected, false],
        '404, also the unknown model' => [404, 'model xyz not found, make sure the correct model ID was used', ErrorReason::Rejected, false],
        '422, not well formed' => [422, 'an invalid combination of fields', ErrorReason::Rejected, false],
        '429, the rate limit' => [429, 'You are past the per minute request limit', ErrorReason::RateLimited, true],
        '498, a deny-listed token' => [498, 'blocked input or output', ErrorReason::Rejected, false],
        '499, cancelled' => [499, 'request cancelled by the user', ErrorReason::Rejected, false],
        '500, an internal error' => [500, 'internal server error', ErrorReason::Unavailable, true],
        '501, not implemented' => [501, 'not implemented', ErrorReason::Unavailable, true],
        '503, unavailable' => [503, 'service unavailable', ErrorReason::Unavailable, true],
        '504, a gateway timeout' => [504, 'gateway timeout', ErrorReason::Unavailable, true],
    ]);

    it('gives the same reasons for a stream and for the model list', function (int $status, ErrorReason $expected): void {
        $transport = (new FakeTransport())->willStream($status, [], ChatV2::errorBody())->willReturn(ChatV2::failed($status));
        $gateway = ChatV2::gateway($transport);

        $streamed = Talk::events($gateway->stream(ChatV2::request()));
        $listed = Talk::errorOf(fn() => $gateway->models(10.0));

        expect($streamed)->toHaveCount(1)
            ->and(Talk::failedIn($streamed)->error->reason)->toBe($expected)
            ->and(Talk::failedIn($streamed)->error->providerCode)->toBeNull()
            ->and($listed->reason)->toBe($expected)
            ->and($listed->httpStatus)->toBe($status);
    })->with([[401, ErrorReason::CredentialsRejected], [402, ErrorReason::Rejected], [404, ErrorReason::Rejected], [429, ErrorReason::RateLimited], [498, ErrorReason::Rejected], [503, ErrorReason::Unavailable]]);

    it('ends a status 402 and a status 498 as rejected for good, with no wait, whatever header comes with them', function (int $status): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(ChatV2::failed($status, 'No.', ['Retry-After' => '5']))->willReturn(ChatV2::answered());
        $error = Talk::errorOf(fn() => ChatV2::gateway($transport, retry: new RetryPolicy([1.0], 60.0), clock: $clock)->complete(ChatV2::request()));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->attempts)->toBe(1)
            ->and($error->retryAfterSeconds)->toBeNull()
            ->and($clock->sleeps())->toBe([]);
    })->with([[402], [498]]);

    it('has no sign that forbids a retry: a 429 and a 503 are tried again whatever header or field comes with them', function (int $status): void {
        $body = ChatV2::json(['id' => 'synthetic-error-1', 'message' => 'Do not retry.', 'retryable' => false, 'should_retry' => false, 'code' => 'do_not_retry', 'type' => 'insufficient_quota']);
        $transport = (new FakeTransport())
            ->willReturn(new HttpResponse($status, ['x-should-retry' => 'false', 'retry' => 'never'], $body))
            ->willReturn(ChatV2::answered());

        $answer = ChatV2::gateway($transport, retry: new RetryPolicy([1.0], 60.0))->complete(ChatV2::request());

        expect($answer->attempts)->toBe(2);
    })->with([[429], [503]]);

    it('ends a 429 without a retry policy as a rate limit after one attempt, and keeps the wait of a Retry-After header, which the adapter base reads although the provider documents none', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::failed(429, 'You are past the per minute request limit', ['Retry-After' => '7']));
        $error = Talk::errorOf(fn() => ChatV2::gateway($transport)->complete(ChatV2::request()));

        expect($error->reason)->toBe(ErrorReason::RateLimited)
            ->and($error->attempts)->toBe(1)
            ->and($error->retryAfterSeconds)->toBe(7);
    });

    it('takes no code from a body, whatever it holds: the format documents none', function (string $body): void {
        $error = Talk::errorOf(fn() => ChatV2::answerTo(new HttpResponse(400, [], $body)));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->providerCode)->toBeNull();
    })->with([
        'the documented body' => ['{"id":"synthetic-error-1","message":"invalid_request"}'],
        'a code and a type no page names' => ['{"message":"No.","code":"invalid_request","type":"invalid_request_error","error":{"code":"x","type":"y"}}'],
        'a message that is one word' => ['{"message":"unauthorized"}'],
        'no JSON' => ['<html>bad_request</html>'],
        'an empty body' => [''],
        'a list' => ['[{"message":"No."}]'],
    ]);

    it('tells an unknown model by its status alone: it is rejected like every 404', function (): void {
        $error = Talk::errorOf(fn() => ChatV2::answerTo(ChatV2::failed(404, "model 'xyz' not found, make sure the correct model ID was used")));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->httpStatus)->toBe(404)
            ->and($error->providerCode)->toBeNull();
    });

    it('never throws while it reads an error body', function (): void {
        expect(ErrorBody::decoded('7'))->toBeNull()
            ->and(ErrorBody::decoded(''))->toBeNull()
            ->and(ErrorBody::decoded('{"message":"No."}'))->toBe(['message' => 'No.'])
            ->and(ErrorBody::isIn(['message' => 'No.']))->toBeTrue()
            ->and(ErrorBody::isIn(['message' => '']))->toBeFalse()
            ->and(ErrorBody::isIn(['message' => ['role' => 'assistant']]))->toBeFalse()
            ->and(ErrorBody::isIn(['id' => 'x']))->toBeFalse()
            ->and(ErrorBody::isIn([]))->toBeFalse();
    });
});
