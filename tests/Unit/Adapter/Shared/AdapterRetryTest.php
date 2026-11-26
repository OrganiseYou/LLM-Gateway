<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplier;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\BrokenTransport;
use OrganiseYou\LlmGateway\Tests\Support\RecordingObserver;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\AttemptOutcome;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

describe('retries', function (): void {
    it('retries on 429 without Retry-After, after the waits of the policy', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(429))->willReturn(Talk::failed(429))->willReturn(Talk::answered());

        $answer = Talk::gateway($transport, $clock, new RetryPolicy([0.5, 2.0, 8.0], 60.0))->complete(Talk::request());

        expect($answer->text)->toBe('Hello.')
            ->and($answer->attempts)->toBe(3)
            ->and($answer->latencyMs)->toBe(2500)
            ->and($clock->sleeps())->toBe([0.5, 2.0])
            ->and($transport->requests())->toHaveCount(3);
    });

    it('retries on 429 with Retry-After in seconds, after the wait the provider asks', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(429, 'slow_down', ['Retry-After' => '7']))->willReturn(Talk::answered());

        $answer = Talk::gateway($transport, $clock, new RetryPolicy([0.5], 10.0))->complete(Talk::request());

        expect($answer->attempts)->toBe(2)
            ->and($clock->sleeps())->toBe([7.0]);
    });

    it('retries on 429 with Retry-After as a date, after the time until that date', function (): void {
        $clock = new FakeClock(unixStart: 1_800_000_000);
        $transport = (new FakeTransport($clock))
            ->willReturn(Talk::failed(429, null, ['retry-after' => gmdate('D, d M Y H:i:s', 1_800_000_009) . ' GMT']))
            ->willReturn(Talk::answered());

        $answer = Talk::gateway($transport, $clock, new RetryPolicy([0.5], 10.0))->complete(Talk::request());

        expect($answer->attempts)->toBe(2)
            ->and($clock->sleeps())->toBe([9.0]);
    });

    it('retries at once for a Retry-After of 0 or a date that has passed', function (string $retryAfter): void {
        $clock = new FakeClock(unixStart: 1_800_000_000);
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(429, null, ['Retry-After' => $retryAfter]))->willReturn(Talk::answered());

        Talk::gateway($transport, $clock, new RetryPolicy([3.0], 10.0))->complete(Talk::request());

        expect($clock->sleeps())->toBe([0.0]);
    })->with([
        'zero' => ['0'],
        'a date in the past' => ['Wed, 21 Oct 2015 07:28:00 GMT'],
    ]);

    it('takes the wait of the policy when Retry-After cannot be read', function (string $retryAfter): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(429, null, ['Retry-After' => $retryAfter]))->willReturn(Talk::answered());

        Talk::gateway($transport, $clock, new RetryPolicy([3.0], 10.0))->complete(Talk::request());

        expect($clock->sleeps())->toBe([3.0]);
    })->with([
        'words' => ['soon'],
        'empty' => [''],
        'a negative number' => ['-5'],
        'a date in another form' => ['2026-11-26T12:00:00Z'],
        'seconds with a unit' => ['5s'],
    ]);

    it('takes a wait the format documents in the body before the header', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willReturn(new HttpResponse(429, ['Retry-After' => '9'], '{"fault":{"code":"slow_down","wait":1.5}}'))
            ->willReturn(Talk::answered());

        Talk::gateway($transport, $clock, new RetryPolicy([3.0], 10.0))->complete(Talk::request());

        expect($clock->sleeps())->toBe([1.5]);
    });

    it('retries on a server error and on 408', function (int $status): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed($status))->willReturn(Talk::answered());

        $answer = Talk::gateway($transport, $clock, new RetryPolicy([1.0], 0.0))->complete(Talk::request());

        expect($answer->attempts)->toBe(2)
            ->and($clock->sleeps())->toBe([1.0]);
    })->with([[500], [502], [503], [504], [529], [599], [408]]);

    it('waits as long as a 503 asks with Retry-After', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(503, null, ['Retry-After' => '4']))->willReturn(Talk::answered());

        Talk::gateway($transport, $clock, new RetryPolicy([1.0], 5.0))->complete(Talk::request());

        expect($clock->sleeps())->toBe([4.0]);
    });

    it('retries on a lost connection', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willFail(TransportFailure::ConnectionFailed)->willFail(TransportFailure::ConnectionFailed)->willReturn(Talk::answered());

        $answer = Talk::gateway($transport, $clock, new RetryPolicy([1.0, 2.0], 0.0))->complete(Talk::request());

        expect($answer->attempts)->toBe(3)
            ->and($clock->sleeps())->toBe([1.0, 2.0]);
    });

    it('does not retry on any other 4xx', function (int $status, ErrorReason $reason): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed($status, 'some_code', ['Retry-After' => '1']));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0], 60.0))->complete(Talk::request()));

        expect($error->reason)->toBe($reason)
            ->and($error->httpStatus)->toBe($status)
            ->and($error->providerCode)->toBe('some_code')
            ->and($error->retryAfterSeconds)->toBeNull()
            ->and($error->attempts)->toBe(1)
            ->and($error->traceId)->toBe('trace-1')
            ->and($transport->requests())->toHaveCount(1)
            ->and($clock->sleeps())->toBe([]);
    })->with([
        [400, ErrorReason::Rejected],
        [401, ErrorReason::CredentialsRejected],
        [403, ErrorReason::CredentialsRejected],
        [404, ErrorReason::Rejected],
        [409, ErrorReason::Rejected],
        [413, ErrorReason::Rejected],
        [422, ErrorReason::Rejected],
        [499, ErrorReason::Rejected],
    ]);

    it('fails at once as rate_limited when Retry-After is above the longest wait the caller accepts', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(429, 'slow_down', ['Retry-After' => '120']));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0, 1.0], 60.0))->complete(Talk::request(600.0)));

        expect($error->reason)->toBe(ErrorReason::RateLimited)
            ->and($error->httpStatus)->toBe(429)
            ->and($error->retryAfterSeconds)->toBe(120)
            ->and($error->providerCode)->toBe('slow_down')
            ->and($error->attempts)->toBe(1)
            ->and($transport->requests())->toHaveCount(1)
            ->and($clock->sleeps())->toBe([]);
    });

    it('accepts a Retry-After that is exactly the longest wait', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(429, null, ['Retry-After' => '60']))->willReturn(Talk::answered());

        Talk::gateway($transport, $clock, new RetryPolicy([1.0], 60.0))->complete(Talk::request(600.0));

        expect($clock->sleeps())->toBe([60.0]);
    });

    it('brings a Retry-After beyond every number into the range of the error', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::failed(429, null, ['Retry-After' => str_repeat('9', 400)]));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, null, new RetryPolicy([1.0], 60.0))->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::RateLimited)
            ->and($error->retryAfterSeconds)->toBe(2_147_483_647);
    });

    it('rounds a wait with a fraction up in the error', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::failed(429, null, ['Retry-After' => '2.2']));

        expect(Talk::errorOf(fn() => Talk::gateway($transport)->complete(Talk::request()))->retryAfterSeconds)->toBe(3);
    });

    it('fails with the error of the last attempt when no retry is left, with every attempt counted', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willReturn(Talk::failed(503))
            ->willFail(TransportFailure::ConnectionFailed)
            ->willReturn(Talk::failed(429, 'slow_down', ['Retry-After' => '2']));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0], 10.0))->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::RateLimited)
            ->and($error->httpStatus)->toBe(429)
            ->and($error->retryAfterSeconds)->toBe(2)
            ->and($error->attempts)->toBe(3)
            ->and($clock->sleeps())->toBe([1.0, 1.0]);
    });

    it('does not retry without a policy for it', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(503));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, RetryPolicy::none())->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::Unavailable)
            ->and($error->httpStatus)->toBe(503)
            ->and($error->attempts)->toBe(1)
            ->and($clock->sleeps())->toBe([]);
    });

    it('gives no HTTP status for a lost connection', function (): void {
        $transport = (new FakeTransport())->willFail(TransportFailure::ConnectionFailed);

        $error = Talk::errorOf(fn() => Talk::gateway($transport)->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::Unavailable)
            ->and($error->httpStatus)->toBeNull()
            ->and($error->detail)->toBe('the connection failed or broke')
            ->and($error->attempts)->toBe(1);
    });

    it('does not retry a timeout of the transport, a request the host blocked or a redirect', function (TransportFailure $failure, ErrorReason $reason): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willFail($failure);

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0], 60.0))->complete(Talk::request()));

        expect($error->reason)->toBe($reason)
            ->and($error->httpStatus)->toBeNull()
            ->and($error->attempts)->toBe(1)
            ->and($transport->requests())->toHaveCount(1)
            ->and($clock->sleeps())->toBe([]);
    })->with([
        'timed out: timeout' => [TransportFailure::TimedOut, ErrorReason::Timeout],
        'blocked: not_configured' => [TransportFailure::Blocked, ErrorReason::NotConfigured],
        'redirected: unavailable' => [TransportFailure::Redirected, ErrorReason::Unavailable],
    ]);

    it('maps a 3xx response to unavailable itself, does not retry it and never asks for its location', function (int $status): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(new HttpResponse($status, ['Location' => 'https://other.example.test/talk', 'Retry-After' => '1']));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([1.0, 1.0], 60.0))->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::Unavailable)
            ->and($error->httpStatus)->toBe($status)
            ->and($error->retryAfterSeconds)->toBeNull()
            ->and($error->detail)->toBe('the server answered with a redirect, and a redirect is never followed')
            ->and($error->attempts)->toBe(1)
            ->and($transport->requests())->toHaveCount(1)
            ->and($transport->requests()[0]->url)->toBe('https://talk.example.test/api/talk')
            ->and($clock->sleeps())->toBe([]);
    })->with([[300], [301], [302], [303], [307], [308], [399]]);

    it('maps a status below 200 to provider_error, not retried', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(101));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, null, new RetryPolicy([0.0], 0.0))->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->httpStatus)->toBe(101)
            ->and($error->attempts)->toBe(1);
    });
});

describe('the deadline of a call', function (): void {
    it('fails with the error of the attempt, without waiting, when the wait would not end before the deadline', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willDelay(6.0)->willReturn(Talk::failed(503));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([4.0], 0.0))->complete(Talk::request(10.0)));

        expect($error->reason)->toBe(ErrorReason::Unavailable)
            ->and($error->attempts)->toBe(1)
            ->and($clock->sleeps())->toBe([]);
    });

    it('still retries when the wait ends before the deadline', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willDelay(5.0)->willReturn(Talk::failed(503))->willReturn(Talk::answered());

        $answer = Talk::gateway($transport, $clock, new RetryPolicy([4.0], 0.0))->complete(Talk::request(10.0));

        expect($answer->attempts)->toBe(2)
            ->and($clock->sleeps())->toBe([4.0])
            ->and($transport->requests()[1]->timeoutSeconds)->toBe(1.0);
    });

    it('covers every attempt and every wait: a later retry that no longer fits is not made', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willDelay(3.0)->willReturn(Talk::failed(503))
            ->willDelay(3.0)->willReturn(Talk::failed(503));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([1.0, 4.0, 1.0], 0.0))->complete(Talk::request(10.0)));

        expect($error->reason)->toBe(ErrorReason::Unavailable)
            ->and($error->attempts)->toBe(2)
            ->and($clock->sleeps())->toBe([1.0]);
    });

    it('fails as timeout, with the attempts made, when the time is used up before an attempt', function (): void {
        $clock = new FakeClock();
        $transport = new FakeTransport($clock);
        // The supplier of the host takes longer than the whole call.
        $slow = new class ($clock) implements TokenSupplier {
            public function __construct(private FakeClock $clock) {}

            public function token(): Secret
            {
                $this->clock->advance(31.0);

                return new Secret('token-1');
            }
        };
        $slowGateway = Talk::gateway($transport, $clock, RetryPolicy::none(), new BearerToken($slow));

        $error = Talk::errorOf(fn() => $slowGateway->complete(Talk::request(30.0)));

        expect($error->reason)->toBe(ErrorReason::Timeout)
            ->and($error->attempts)->toBe(0)
            ->and($error->httpStatus)->toBeNull()
            ->and($error->detail)->toBe('the time of the call was used up before an attempt')
            ->and($transport->requests())->toBe([]);
    });
});

describe('what an adapter may refine', function (): void {
    it('does not retry a server error that the format documents as a refusal', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(500, 'bad_version'));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([1.0], 0.0))->complete(Talk::request()));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->httpStatus)->toBe(500)
            ->and($error->providerCode)->toBe('bad_version')
            ->and($error->attempts)->toBe(1)
            ->and($clock->sleeps())->toBe([]);
    });

    it('retries a refusal that the format documents as a server that is busy', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(400, 'overloaded'))->willReturn(Talk::answered());

        $answer = Talk::gateway($transport, $clock, new RetryPolicy([1.0], 0.0))->complete(Talk::request());

        expect($answer->attempts)->toBe(2)
            ->and($clock->sleeps())->toBe([1.0]);
    });

    it('keeps the reason of the status when the adapter cannot read the body of the failure', function (): void {
        // The adapter decodes the body to find the code, and that throws for a page of a proxy.
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))->willReturn(new HttpResponse(502, [], '<html>Bad gateway</html>'))->willReturn(Talk::answered());

        $answer = Talk::gateway($transport, $clock, new RetryPolicy([1.0], 0.0))->complete(Talk::request());

        expect($answer->attempts)->toBe(2);
    });

    it('ends in a new error with a fixed text when the code of the adapter fails while it reads a failure', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::failed(400, 'adapter_breaks'));

        expect(fn() => Talk::gateway($transport)->complete(Talk::request()))
            ->toThrow(Error::class, 'The adapter failed with an error of class DivisionByZeroError, thrown in TalkAdapter.php on line');
    });
});

describe('the provider code', function (): void {
    it('is kept lower-cased when it is a plain word, and dropped otherwise', function (string $code, ?string $kept): void {
        $transport = (new FakeTransport())->willReturn(Talk::failed(400, $code));

        expect(Talk::errorOf(fn() => Talk::gateway($transport)->complete(Talk::request()))->providerCode)->toBe($kept);
    })->with([
        'a plain word' => ['Invalid_Request.Error-1', 'invalid_request.error-1'],
        'a sentence' => ['The request was wrong', null],
        'longer than 64 characters' => [str_repeat('a', 65), null],
    ]);

    it('is dropped when it equals the key, compared without case', function (string $code): void {
        $transport = (new FakeTransport())->willReturn(Talk::failed(401, $code))->willReturn(Talk::failed(401, $code));

        $bearer = Talk::errorOf(fn() => Talk::gateway($transport, credentials: new BearerKey('Key-Of.The_Host-1'))->complete(Talk::request()));
        $header = Talk::errorOf(fn() => Talk::gateway($transport, credentials: new HeaderKey('Key-Of.The_Host-1'))->complete(Talk::request()));

        expect($bearer->reason)->toBe(ErrorReason::CredentialsRejected)
            ->and($bearer->providerCode)->toBeNull()
            ->and($bearer->getMessage())->toBe('credentials_rejected (HTTP 401)')
            ->and($header->providerCode)->toBeNull();
    })->with([
        'as it is' => ['Key-Of.The_Host-1'],
        'lower-cased' => ['key-of.the_host-1'],
        'upper-cased' => ['KEY-OF.THE_HOST-1'],
    ]);

    it('is dropped when it equals the token of the supplier that this attempt used', function (): void {
        $tokens = Talk::tokens();
        $transport = (new FakeTransport())
            ->willReturn(Talk::failed(503))
            ->willReturn(Talk::failed(401, 'TOKEN-2'))
            ->willReturn(new HttpResponse(200, [], '{"fault":{"code":"token-3"}}'))
            ->willReturn(Talk::failed(401, 'token-1'));
        $gateway = Talk::gateway($transport, null, new RetryPolicy([0.0], 0.0), new BearerToken($tokens));

        $statusError = Talk::errorOf(fn() => $gateway->complete(Talk::request()));
        $bodyError = Talk::errorOf(fn() => $gateway->complete(Talk::request()));
        $otherToken = Talk::errorOf(fn() => $gateway->complete(Talk::request()));

        expect($statusError->providerCode)->toBeNull()
            ->and($bodyError->reason)->toBe(ErrorReason::ProviderError)
            ->and($bodyError->providerCode)->toBeNull()
            // A token that is not in use any more is not known to the library: it keeps none.
            ->and($otherToken->providerCode)->toBe('token-1');
    });
});

describe('a provider code or a detail that holds a credential', function (): void {
    it('is dropped when the key stands anywhere in the code, in any case', function (string $before, string $after, bool $upper): void {
        $key = 'Key-Of.The_Host-1';
        $code = $before . ($upper ? strtoupper($key) : $key) . $after;
        $tokens = Talk::tokens();
        $tokens->next = $key;
        $transport = (new FakeTransport())
            ->willReturn(Talk::failed(401, $code))
            ->willReturn(Talk::failed(401, $code))
            ->willReturn(Talk::failed(401, $code))
            ->willReturn(new HttpResponse(200, [], Talk::json(['fault' => ['code' => $code]])))
            ->willStream(403, [], Talk::json(['fault' => ['code' => $code]]))
            ->willStream(200, [], Talk::event('say', ['piece' => 'Hi']), Talk::event('fault', ['fault' => ['code' => $code]]));

        $errors = [
            'a bearer key' => Talk::errorOf(fn() => Talk::gateway($transport, credentials: new BearerKey($key))->complete(Talk::request())),
            'a header key' => Talk::errorOf(fn() => Talk::gateway($transport, credentials: new HeaderKey($key))->complete(Talk::request())),
            'a token of the supplier' => Talk::errorOf(fn() => Talk::gateway($transport, credentials: new BearerToken($tokens))->complete(Talk::request())),
            'an error inside a 200' => Talk::errorOf(fn() => Talk::gateway($transport, credentials: new BearerKey($key))->complete(Talk::request())),
            'a stream that is refused' => Talk::failedIn(Talk::events(Talk::gateway($transport, credentials: new BearerKey($key))->stream(Talk::request())))->error,
            'a stream that fails' => Talk::failedIn(Talk::events(Talk::gateway($transport, credentials: new HeaderKey($key))->stream(Talk::request())))->error,
        ];

        expect($transport->remaining())->toBe(0);
        foreach ($errors as $case => $error) {
            expect($error->providerCode)->toBeNull($case)
                ->and(stripos($error->getMessage() . json_encode($error), 'the_host'))->toBeFalse($case);
        }
    })->with([
        'before it' => ['x-', '', false],
        'after it' => ['', '-x', false],
        'around it' => ['invalid_', '.rejected', false],
        'a scheme word before it' => ['bearer.', '', false],
        'in another case' => ['invalid_', '', true],
    ]);

    it('keeps a code that only shares letters with the key', function (): void {
        $transport = (new FakeTransport())->willReturn(Talk::failed(401, 'key-of.the_host'));

        expect(Talk::errorOf(fn() => Talk::gateway($transport, credentials: new BearerKey('Key-Of.The_Host-1'))->complete(Talk::request()))->providerCode)
            ->toBe('key-of.the_host');
    });

    it('drops the detail an adapter wrote when it holds the key or the token in use, and keeps any other', function (): void {
        $tokens = Talk::tokens();
        $transport = (new FakeTransport())
            ->willReturn(new HttpResponse(200, [], Talk::json(['fault' => ['words' => 'the key KEY-1-of-the-host was refused']])))
            ->willReturn(new HttpResponse(200, [], Talk::json(['fault' => ['words' => 'the value Token-1 was refused']])))
            ->willStream(200, [], Talk::event('fault', ['fault' => ['words' => 'no: key-1-of-the-host']]))
            ->willReturn(new HttpResponse(200, [], Talk::json(['fault' => ['words' => 'the model is busy']])));

        $key = Talk::errorOf(fn() => Talk::gateway($transport, credentials: new BearerKey('key-1-of-the-host'))->complete(Talk::request()));
        $token = Talk::errorOf(fn() => Talk::gateway($transport, credentials: new BearerToken($tokens))->complete(Talk::request()));
        $stream = Talk::failedIn(Talk::events(Talk::gateway($transport, credentials: new HeaderKey('key-1-of-the-host'))->stream(Talk::request())))->error;
        $other = Talk::errorOf(fn() => Talk::gateway($transport, credentials: new BearerKey('key-1-of-the-host'))->complete(Talk::request()));

        expect($key->reason)->toBe(ErrorReason::ProviderError)
            ->and($key->detail)->toBeNull()
            ->and($key->getMessage())->toBe('provider_error (HTTP 200)')
            ->and($token->detail)->toBeNull()
            ->and($stream->detail)->toBeNull()
            ->and($other->detail)->toBe('the model is busy');
    });
});

describe('a wait the response tells in a form that is no wait', function (): void {
    it('takes the wait of the policy when the adapter reads a wait that is not a number', function (): void {
        $clock = new FakeClock();
        $transport = (new FakeTransport($clock))
            ->willReturn(new HttpResponse(429, [], '{"fault":{"wait":"not a number"}}'))
            ->willReturn(new HttpResponse(429, [], '{"fault":{"wait":"not a number"}}'));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([3.0], 10.0))->complete(Talk::request()));

        expect($clock->sleeps())->toBe([3.0])
            ->and($error->reason)->toBe(ErrorReason::RateLimited)
            ->and($error->retryAfterSeconds)->toBeNull()
            ->and($error->attempts)->toBe(2);
    });

    it('takes the wait of the policy for a Retry-After date that does not exist', function (string $date): void {
        $clock = new FakeClock(unixStart: 1_800_000_000);
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(429, null, ['Retry-After' => $date]))->willReturn(Talk::answered());

        Talk::gateway($transport, $clock, new RetryPolicy([3.0], 1_000_000_000.0))->complete(Talk::request(2_000_000_000.0));

        expect($clock->sleeps())->toBe([3.0]);
    })->with([
        '31 February' => ['Sun, 31 Feb 2030 10:00:00 GMT'],
        'day 00' => ['Sun, 00 Mar 2030 10:00:00 GMT'],
        'hour 24' => ['Sun, 03 Mar 2030 24:00:00 GMT'],
        'minute 60' => ['Sun, 03 Mar 2030 10:60:00 GMT'],
    ]);
});

describe('the time of a call that is used up between two attempts', function (): void {
    it('ends the call as timeout before the token supplier is asked again', function (): void {
        $clock = new FakeClock();
        $tokens = Talk::tokens();
        // An observer of the host that takes a long time after the attempt.
        $observer = new RecordingObserver(clock: $clock, secondsAfter: 100.0);
        $transport = (new FakeTransport($clock))->willReturn(Talk::failed(503));

        $error = Talk::errorOf(fn() => Talk::gateway($transport, $clock, new RetryPolicy([1.0], 0.0), new BearerToken($tokens), $observer)->complete(Talk::request(30.0)));

        expect($error->reason)->toBe(ErrorReason::Timeout)
            ->and($error->attempts)->toBe(1)
            ->and($tokens->asked)->toBe(1)
            ->and($transport->requests())->toHaveCount(1)
            ->and($observer->started)->toHaveCount(1);
    });
});

describe('a fault in the code of an adapter or of a transport', function (): void {
    it('is reported as a finished attempt before the new error is thrown, once, with the class, the file and the line of the fault', function (): void {
        $observer = new RecordingObserver();
        $transport = (new FakeTransport())
            ->willReturn(Talk::failed(400, 'adapter_breaks'))
            ->willReturn(Talk::failed(400, 'adapter_breaks'))
            ->willStream(400, [], '{"fault":{"code":"adapter_breaks"}}')
            ->willReturn(new HttpResponse(200, [], '{"explode":"error"}'))
            ->willStream(200, [], Talk::event('say', ['explode' => 'error']));
        $gateway = Talk::gateway($transport, null, new RetryPolicy([0.0], 0.0), observer: $observer);

        $messages = [];
        foreach ([
            fn() => $gateway->complete(Talk::request()),
            fn() => $gateway->models(5.0),
            fn() => Talk::events($gateway->stream(Talk::request())),
            fn() => $gateway->complete(Talk::request()),
            fn() => Talk::events($gateway->stream(Talk::request())),
        ] as $call) {
            try {
                $call();
            } catch (Error $error) {
                $messages[] = $error->getMessage();
                expect($error->getPrevious())->toBeNull();
            }
        }

        expect($messages)->toHaveCount(5);
        foreach ($messages as $message) {
            expect($message)->toMatch('/^The adapter failed with an error of class DivisionByZeroError, thrown in TalkAdapter\.php on line [0-9]+\.$/D');
        }
        expect($observer->started)->toHaveCount(5)
            ->and($observer->finished)->toHaveCount(5)
            ->and($transport->remaining())->toBe(0);
        foreach ($observer->finished as $finished) {
            expect($finished->outcome)->toBe(AttemptOutcome::HttpError)
                ->and($finished->willRetry)->toBeFalse();
        }
    });

    it('lets an exception of a transport that is not its own pass unchanged, after the report of the attempt', function (): void {
        $observer = new RecordingObserver();
        $gateway = Talk::gateway(new BrokenTransport(), null, new RetryPolicy([0.0], 0.0), observer: $observer);

        $thrown = [];
        foreach ([
            fn() => $gateway->complete(Talk::request()),
            fn() => $gateway->models(5.0),
            fn() => Talk::events($gateway->stream(Talk::request())),
        ] as $call) {
            try {
                $call();
            } catch (DomainException $exception) {
                $thrown[] = $exception->getMessage() . ' ' . basename($exception->getFile());
            }
        }

        expect($thrown)->toBe(array_fill(0, 3, 'The transport of the host has a fault of its own. BrokenTransport.php'))
            ->and($observer->started)->toHaveCount(3)
            ->and($observer->finished)->toHaveCount(3);
        foreach ($observer->finished as $finished) {
            expect($finished->outcome)->toBe(AttemptOutcome::TransportError)
                ->and($finished->response)->toBeNull()
                ->and($finished->willRetry)->toBeFalse();
        }
    });
});
