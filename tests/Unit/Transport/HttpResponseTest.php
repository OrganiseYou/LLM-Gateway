<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\HttpStreamResponse;

describe('HttpResponse', function (): void {
    it('keeps the status, the headers and the body', function (): void {
        $response = new HttpResponse(200, ['content-type' => 'application/json'], '{"id":"answer-1"}');

        expect($response->status)->toBe(200)
            ->and($response->headers)->toBe(['content-type' => 'application/json'])
            ->and($response->body)->toBe('{"id":"answer-1"}');
    });

    it('defaults to no headers and an empty body', function (): void {
        $response = new HttpResponse(204);

        expect($response->headers)->toBe([])
            ->and($response->body)->toBe('');
    });

    it('accepts a status from 100 to 599', function (int $status): void {
        expect((new HttpResponse($status))->status)->toBe($status);
    })->with([100, 200, 302, 404, 429, 500, 599]);

    it('throws when the status is outside 100 to 599', function (int $status): void {
        new HttpResponse($status);
    })->with([0, 99, 600, -200, 1000])->throws(InvalidArgumentException::class, 'The HTTP status must be between 100 and 599.');

    it('stores header names lower-cased', function (): void {
        $response = new HttpResponse(200, ['Content-Type' => 'text/plain', 'X-REQUEST-ID' => 'request-1']);

        expect($response->headers)->toBe(['content-type' => 'text/plain', 'x-request-id' => 'request-1']);
    });

    it('joins several values of one header with a comma and a space', function (): void {
        $response = new HttpResponse(200, ['Vary' => ['Accept', 'Origin'], 'Retry-After' => ['30']]);

        expect($response->headers)->toBe(['vary' => 'Accept, Origin', 'retry-after' => '30']);
    });

    it('joins the values of one header given in two cases', function (): void {
        $response = new HttpResponse(200, untyped(['Vary' => 'Accept', 'vary' => ['Origin', 'Cookie']]));

        expect($response->headers)->toBe(['vary' => 'Accept, Origin, Cookie']);
    });

    it('leaves out a header with an empty list of values', function (): void {
        expect((new HttpResponse(200, ['Vary' => []]))->headers)->toBe([]);
    });

    it('keeps a header name of digits as a string name', function (): void {
        $response = new HttpResponse(200, untyped([123 => 'value']));

        expect($response->header('123'))->toBe('value');
    });

    it('throws when a header value is not a string or a list of strings', function (mixed $value): void {
        new HttpResponse(200, untyped(['X-Header' => $value]));
    })->with([
        'an integer' => [30],
        'null' => [null],
        'a list with an integer' => [['a', 1]],
        'a nested list' => [[['a']]],
    ])->throws(InvalidArgumentException::class, 'A header value must be a string or a list of strings.');

    it('throws when a header name is empty', function (): void {
        new HttpResponse(200, ['' => 'value']);
    })->throws(InvalidArgumentException::class, 'A header name must not be empty.');

    it('finds a header without case and gives null for one that is absent', function (): void {
        $response = new HttpResponse(429, ['Retry-After' => '30']);

        expect($response->header('retry-after'))->toBe('30')
            ->and($response->header('Retry-After'))->toBe('30')
            ->and($response->header('RETRY-AFTER'))->toBe('30')
            ->and($response->header('x-other'))->toBeNull();
    });

    it('gives its data as an array', function (): void {
        $response = new HttpResponse(404, ['Content-Type' => 'application/json'], '{"error":"unknown"}');

        expect($response->toArray())->toBe([
            'status' => 404,
            'headers' => ['content-type' => 'application/json'],
            'body' => '{"error":"unknown"}',
        ]);
    });

    it('keeps nothing of a refused response in the refusal', function (string $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function (): void {
            try {
                new HttpResponse(700, ['Set-Cookie' => LEAK_MARKER], '{"echo":"' . LEAK_MARKER . '"}');
            } catch (InvalidArgumentException $exception) {
                expectNoMarker(exceptionForms($exception));

                return;
            }

            throw new LogicException('The response was accepted.');
        });
    })->with(['arguments kept' => ['0'], 'arguments left out' => ['1']]);
});

describe('HttpStreamResponse', function (): void {
    it('keeps the status, the headers and the chunks', function (): void {
        $response = new HttpStreamResponse(200, ['Content-Type' => 'text/event-stream'], ['data: a', "\n\n"]);

        expect($response->status)->toBe(200)
            ->and($response->headers)->toBe(['content-type' => 'text/event-stream'])
            ->and($response->chunks)->toBe(['data: a', "\n\n"]);
    });

    it('does not read the chunks when it is built', function (): void {
        $read = 0;
        $chunks = (function () use (&$read): Generator {
            $read++;
            yield 'a';
            $read++;
            yield 'b';
        })();

        $response = new HttpStreamResponse(200, [], $chunks);

        expect($read)->toBe(0)
            ->and(iterator_to_array($response->chunks, false))->toBe(['a', 'b'])
            ->and($read)->toBe(2);
    });

    it('throws when the status is outside 100 to 599', function (int $status): void {
        new HttpStreamResponse($status, [], []);
    })->with([0, 99, 600])->throws(InvalidArgumentException::class, 'The HTTP status must be between 100 and 599.');

    it('stores header names lower-cased and joins several values', function (): void {
        $response = new HttpStreamResponse(200, ['Vary' => ['Accept', 'Origin'], 'X-Request-Id' => 'request-1'], []);

        expect($response->headers)->toBe(['vary' => 'Accept, Origin', 'x-request-id' => 'request-1']);
    });

    it('throws when a header value is not a string or a list of strings', function (): void {
        new HttpStreamResponse(200, untyped(['X-Header' => 30]), []);
    })->throws(InvalidArgumentException::class, 'A header value must be a string or a list of strings.');

    it('finds a header without case and gives null for one that is absent', function (): void {
        $response = new HttpStreamResponse(200, ['Content-Type' => 'text/event-stream'], []);

        expect($response->header('CONTENT-TYPE'))->toBe('text/event-stream')
            ->and($response->header('x-other'))->toBeNull();
    });

    it('carries the error body as chunks for a status outside 200 to 299', function (): void {
        $response = new HttpStreamResponse(429, ['Retry-After' => '30'], ['{"error":', '"slow down"}']);

        expect($response->status)->toBe(429)
            ->and(implode('', iterator_to_array($response->chunks, false)))->toBe('{"error":"slow down"}');
    });
});
