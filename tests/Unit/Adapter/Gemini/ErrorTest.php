<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Gemini\ErrorBody;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// How the generate-content adapter reads a failed response (docs/design/adapters.md, sections 4.2 and 5.6): the
// reason of the status, the code from the first reason among the details or from the status word, a wrong key
// under the status 400, and no sign that forbids a retry.

describe('the error table of the generate-content adapter', function (): void {
    it('gives every documented status its reason and its code, and tries again only what may be tried again', function (int $status, string $word, ?string $reason, ErrorReason $expected, string $code, bool $retried): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willReturn(GenerateContent::failed($status, $word, $reason))
            ->willReturn(GenerateContent::failed($status, $word, $reason));
        $gateway = GenerateContent::gateway($transport, retry: new RetryPolicy([1.0], 60.0), clock: $clock);

        $error = Talk::errorOf(fn() => $gateway->complete(GenerateContent::request()));

        expect($error->reason)->toBe($expected)
            ->and($error->httpStatus)->toBe($status)
            ->and($error->providerCode)->toBe($code)
            ->and($error->attempts)->toBe($retried ? 2 : 1)
            ->and($transport->requests())->toHaveCount($retried ? 2 : 1);
    })->with([
        '400, a malformed request' => [400, 'INVALID_ARGUMENT', null, ErrorReason::Rejected, 'invalid_argument', false],
        '400, a schema that is too complex' => [400, 'INVALID_ARGUMENT', 'EXAMPLE_REASON', ErrorReason::Rejected, 'example_reason', false],
        '400, a precondition' => [400, 'FAILED_PRECONDITION', null, ErrorReason::Rejected, 'failed_precondition', false],
        '400, a key that is not valid' => [400, 'INVALID_ARGUMENT', 'API_KEY_INVALID', ErrorReason::CredentialsRejected, 'api_key_invalid', false],
        '401' => [401, 'UNAUTHENTICATED', null, ErrorReason::CredentialsRejected, 'unauthenticated', false],
        '402, no credit' => [402, 'RESOURCE_EXHAUSTED', null, ErrorReason::Rejected, 'resource_exhausted', false],
        '403' => [403, 'PERMISSION_DENIED', null, ErrorReason::CredentialsRejected, 'permission_denied', false],
        '404' => [404, 'NOT_FOUND', null, ErrorReason::Rejected, 'not_found', false],
        '429, a rate limit' => [429, 'RESOURCE_EXHAUSTED', null, ErrorReason::RateLimited, 'resource_exhausted', true],
        '499, cancelled' => [499, 'CANCELLED', null, ErrorReason::Rejected, 'cancelled', false],
        '500' => [500, 'INTERNAL', null, ErrorReason::Unavailable, 'internal', true],
        '500, unknown' => [500, 'UNKNOWN', null, ErrorReason::Unavailable, 'unknown', true],
        '503' => [503, 'UNAVAILABLE', null, ErrorReason::Unavailable, 'unavailable', true],
        '504' => [504, 'DEADLINE_EXCEEDED', null, ErrorReason::Unavailable, 'deadline_exceeded', true],
    ]);

    it('reads a status 400 with the reason of a key that is not valid as a sign-in that was refused, for a completion, a stream and the model list', function (): void {
        $failed = fn(): HttpResponse => GenerateContent::failed(400, 'INVALID_ARGUMENT', 'API_KEY_INVALID');
        $transport = (new FakeTransport())->willReturn($failed())->willStream(400, [], $failed()->body)->willReturn($failed());
        $gateway = GenerateContent::gateway($transport, retry: new RetryPolicy([1.0], 60.0));

        $completed = Talk::errorOf(fn() => $gateway->complete(GenerateContent::request()));
        $streamed = Talk::failedIn(Talk::events($gateway->stream(GenerateContent::request())))->error;
        $listed = Talk::errorOf(fn() => $gateway->models(10.0));

        foreach ([$completed, $streamed, $listed] as $error) {
            expect($error->reason)->toBe(ErrorReason::CredentialsRejected)
                ->and($error->httpStatus)->toBe(400)
                ->and($error->providerCode)->toBe('api_key_invalid')
                ->and($error->attempts)->toBe(1);
        }
        expect($transport->requests())->toHaveCount(3);
    });

    it('finds that reason among several details, and only under the status 400, and only as it is written', function (): void {
        $body = fn(array $details): string => GenerateContent::json(['error' => ['code' => 400, 'message' => 'No.', 'status' => 'INVALID_ARGUMENT', 'details' => $details]]);
        $second = $body([['@type' => 'type.example.test/Help', 'links' => []], ['reason' => 'OTHER_REASON'], ['@type' => 'type.example.test/ErrorInfo', 'reason' => 'API_KEY_INVALID']]);
        $lower = $body([['reason' => 'api_key_invalid']]);

        $reasonOf = fn(int $status, string $with): ErrorReason => Talk::errorOf(fn() => GenerateContent::answerTo(new HttpResponse($status, [], $with)))->reason;

        expect($reasonOf(400, $second))->toBe(ErrorReason::CredentialsRejected)
            ->and(Talk::errorOf(fn() => GenerateContent::answerTo(new HttpResponse(400, [], $second)))->providerCode)->toBe('other_reason')
            ->and($reasonOf(400, $lower))->toBe(ErrorReason::Rejected)
            ->and($reasonOf(429, $second))->toBe(ErrorReason::RateLimited)
            ->and($reasonOf(404, $second))->toBe(ErrorReason::Rejected)
            ->and($reasonOf(500, $second))->toBe(ErrorReason::Unavailable)
            // In the message it is the words of the provider, which are never read.
            ->and($reasonOf(400, GenerateContent::errorBody(400, 'INVALID_ARGUMENT', null, 'API_KEY_INVALID')))->toBe(ErrorReason::Rejected);
    });

    it('tells a status 402 from a 429 with the same status word by the status: the first is refused for good, the second is a rate limit that is tried again', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willReturn(GenerateContent::failed(402, 'RESOURCE_EXHAUSTED', null, ['Retry-After' => '5']))
            ->willReturn(GenerateContent::failed(429, 'RESOURCE_EXHAUSTED', null, ['Retry-After' => '5']))
            ->willReturn(GenerateContent::answered());
        $gateway = GenerateContent::gateway($transport, retry: new RetryPolicy([1.0], 60.0), clock: $clock);

        $noCredit = Talk::errorOf(fn() => $gateway->complete(GenerateContent::request()));
        $afterAWait = $gateway->complete(GenerateContent::request());

        expect($noCredit->reason)->toBe(ErrorReason::Rejected)
            ->and($noCredit->attempts)->toBe(1)
            ->and($noCredit->retryAfterSeconds)->toBeNull()
            ->and($afterAWait->attempts)->toBe(2)
            ->and($clock->sleeps())->toBe([5.0]);
    });

    it('has no sign that forbids a retry: a 429 and a 503 are tried again whatever header or field comes with them', function (int $status): void {
        $body = GenerateContent::json(['error' => ['code' => $status, 'message' => 'Do not retry.', 'status' => 'UNAVAILABLE', 'retryable' => false, 'details' => [['reason' => 'DO_NOT_RETRY', 'retry' => false]]]]);
        $transport = (new FakeTransport())
            ->willReturn(new HttpResponse($status, ['x-should-retry' => 'false', 'retry' => 'never'], $body))
            ->willReturn(GenerateContent::answered());

        $answer = GenerateContent::gateway($transport, retry: new RetryPolicy([1.0], 60.0))->complete(GenerateContent::request());

        expect($answer->attempts)->toBe(2);
    })->with([[429], [503]]);

    it('takes the code from the first reason among the details, then from the status word, in lower case, and from nowhere else', function (string $body, ?string $code): void {
        $error = Talk::errorOf(fn() => GenerateContent::answerTo(new HttpResponse(400, [], $body)));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->providerCode)->toBe($code);
    })->with([
        'a reason and a status word' => ['{"error":{"code":400,"message":"No.","status":"INVALID_ARGUMENT","details":[{"reason":"FIRST_REASON"},{"reason":"SECOND_REASON"}]}}', 'first_reason'],
        'details without a reason' => ['{"error":{"status":"INVALID_ARGUMENT","details":[{"@type":"x","links":[]},"text",7]}}', 'invalid_argument'],
        'a reason that is no string' => ['{"error":{"status":"INVALID_ARGUMENT","details":[{"reason":7},{"reason":""},{"reason":["X"]}]}}', 'invalid_argument'],
        'details that are no list' => ['{"error":{"status":"FAILED_PRECONDITION","details":"none"}}', 'failed_precondition'],
        'only a status word' => ['{"error":{"code":400,"status":"INVALID_ARGUMENT"}}', 'invalid_argument'],
        'only a message and a number' => ['{"error":{"code":400,"message":"SOME_WORD"}}', null],
        'a status word that is no string' => ['{"error":{"status":400}}', null],
        'an error that is a word' => ['{"error":"INVALID_ARGUMENT"}', null],
        'a flat body' => ['{"status":"INVALID_ARGUMENT","reason":"X","code":"Y"}', null],
        'a reason that is no plain word' => ['{"error":{"status":"INVALID_ARGUMENT","details":[{"reason":"two words"}]}}', null],
        'no JSON' => ['<html>INVALID_ARGUMENT</html>', null],
        'an empty body' => ['', null],
        'a list' => ['[{"error":{"status":"INVALID_ARGUMENT"}}]', null],
    ]);

    it('never throws while it reads an error body', function (): void {
        expect(ErrorBody::decoded('7'))->toBeNull()
            ->and(ErrorBody::code(null))->toBeNull()
            ->and(ErrorBody::code([]))->toBeNull()
            ->and(ErrorBody::saysInvalidKey(null))->toBeFalse()
            ->and(ErrorBody::saysInvalidKey(['error' => ['details' => [['reason' => 'API_KEY_INVALID']]]]))->toBeTrue()
            ->and(ErrorBody::isIn(['error' => []]))->toBeFalse()
            ->and(ErrorBody::isIn(['error' => 'word']))->toBeFalse()
            ->and(ErrorBody::isIn(['error' => ['status' => 'INTERNAL']]))->toBeTrue();
    });

    it('keeps the wait of a Retry-After header, which the adapter base reads, although the provider documents none', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::failed(429, 'RESOURCE_EXHAUSTED', null, ['Retry-After' => '7']));
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport)->complete(GenerateContent::request()));

        expect($error->reason)->toBe(ErrorReason::RateLimited)
            ->and($error->retryAfterSeconds)->toBe(7);
    });

    it('reads a failed stream as the failed response it is, before any event', function (): void {
        $transport = (new FakeTransport())->willStream(503, [], GenerateContent::errorBody(503, 'UNAVAILABLE'));
        $events = Talk::events(GenerateContent::gateway($transport)->stream(GenerateContent::request()));

        expect($events)->toHaveCount(1)
            ->and(Talk::failedIn($events)->error->reason)->toBe(ErrorReason::Unavailable)
            ->and(Talk::failedIn($events)->error->providerCode)->toBe('unavailable');
    });
});
