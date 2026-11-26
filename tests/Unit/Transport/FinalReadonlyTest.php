<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Operation;
use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Transport\AttemptFinished;
use OrganiseYou\LlmGateway\Transport\AttemptObserver;
use OrganiseYou\LlmGateway\Transport\AttemptOutcome;
use OrganiseYou\LlmGateway\Transport\AttemptStarted;
use OrganiseYou\LlmGateway\Transport\Clock;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\HttpStreamResponse;
use OrganiseYou\LlmGateway\Transport\NullAttemptObserver;
use OrganiseYou\LlmGateway\Transport\Psr18Transport;
use OrganiseYou\LlmGateway\Transport\SystemClock;
use OrganiseYou\LlmGateway\Transport\Transport;
use OrganiseYou\LlmGateway\Transport\TransportException;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

it('has only final readonly value classes in the transport', function (): void {
    foreach ([HttpRequest::class, HttpResponse::class, HttpStreamResponse::class, AttemptStarted::class, AttemptFinished::class] as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue($class . ' must be final')
            ->and($reflection->isReadOnly())->toBeTrue($class . ' must be readonly');
    }
});

it('has only final classes without state of their own in the transport', function (): void {
    foreach ([Psr18Transport::class, NullAttemptObserver::class, SystemClock::class, TransportException::class] as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue($class . ' must be final')
            ->and($reflection->getStaticProperties())->toBe([], $class . ' must not keep anything between calls');
    }
});

it('has only string-backed enums for the transport', function (): void {
    foreach ([Operation::class, TransportFailure::class, AttemptOutcome::class] as $enum) {
        $reflection = new ReflectionEnum($enum);

        expect($reflection->isBacked())->toBeTrue($enum . ' must be backed')
            ->and((string) $reflection->getBackingType())->toBe('string', $enum . ' must be backed by a string');
    }
});

it('has interfaces for the transport, the observer and the clock', function (): void {
    foreach ([Transport::class, AttemptObserver::class, Clock::class] as $interface) {
        expect((new ReflectionClass($interface))->isInterface())->toBeTrue($interface . ' must be an interface');
    }
});

it('has final fakes that are not readonly: their state lives in the test', function (): void {
    foreach ([FakeTransport::class, FakeClock::class] as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue($class . ' must be final')
            ->and($reflection->isReadOnly())->toBeFalse($class . ' holds a script')
            ->and($reflection->getStaticProperties())->toBe([], $class . ' must not keep anything between tests');
    }
});

it('marks every parameter that carries a header, a body or a secret as sensitive', function (string $class, string $method, array $expected): void {
    $sensitive = [];
    foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
        if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
            $sensitive[] = $parameter->getName();
        }
    }

    expect($sensitive)->toBe($expected);
})->with([
    'Secret' => [Secret::class, '__construct', ['value']],
    'Secret, unserialized' => [Secret::class, '__unserialize', ['data']],
    'Secret, its holder' => [Secret::class, 'hold', ['value']],
    'HttpRequest' => [HttpRequest::class, '__construct', ['url', 'headers', 'secretHeaders', 'body']],
    'HttpRequest, the URL check' => [HttpRequest::class, 'assertUrl', ['url']],
    'HttpRequest, the authority check' => [HttpRequest::class, 'assertAuthority', ['authority']],
    'HttpRequest, the header check' => [HttpRequest::class, 'assertHeaders', ['headers']],
    'HttpRequest, the secret header check' => [HttpRequest::class, 'assertSecretHeaders', ['secretHeaders', 'seen']],
    'HttpRequest, the name check' => [HttpRequest::class, 'assertName', ['name', 'seen']],
    'HttpResponse' => [HttpResponse::class, '__construct', ['headers', 'body']],
    'HttpResponse, the header rule' => [HttpResponse::class, 'normalise', ['headers']],
    'HttpStreamResponse' => [HttpStreamResponse::class, '__construct', ['headers', 'chunks']],
    'AttemptFinished' => [AttemptFinished::class, '__construct', ['response']],
    'Psr18Transport, the send step' => [Psr18Transport::class, 'ask', ['psrRequest']],
    'FakeTransport, a scripted stream' => [FakeTransport::class, 'willStream', ['headers', 'chunks']],
    'FakeTransport, the script' => [FakeTransport::class, 'add', ['headers', 'chunks']],
    'FakeTransport, the chunks' => [FakeTransport::class, 'chunks', ['chunks']],
]);

it('keeps the arguments of a refused stream response and a refused scripted stream out of the refusal', function (): void {
    withExceptionIgnoreArgs('0', function (): void {
        $refusals = [];
        foreach ([
            fn() => new HttpStreamResponse(700, ['Set-Cookie' => LEAK_MARKER], [LEAK_MARKER]),
            fn() => (new FakeTransport())->willStream(700, ['Set-Cookie' => LEAK_MARKER], LEAK_MARKER),
            fn() => new AttemptFinished(
                'preset-a',
                Operation::Complete,
                'trace-1',
                0,
                new HttpRequest('POST', 'https://api.example.test/v1', [], [], 30.0),
                new HttpResponse(401, [], '{"echo":"' . LEAK_MARKER . '"}'),
                AttemptOutcome::HttpError,
                null,
                false,
                1,
            ),
        ] as $build) {
            try {
                $build();
            } catch (InvalidArgumentException $refusal) {
                $refusals[] = $refusal;
            }
        }

        expect($refusals)->toHaveCount(3);
        foreach ($refusals as $refusal) {
            expectNoMarker(exceptionForms($refusal));
        }
    });
});
