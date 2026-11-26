<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;

describe('ErrorReason', function (): void {
    it('has the eight reasons of LR-32 and no other', function (): void {
        expect(array_map(fn(ErrorReason $reason): string => $reason->value, ErrorReason::cases()))->toBe([
            'not_configured',
            'credentials_rejected',
            'rate_limited',
            'unavailable',
            'rejected',
            'provider_error',
            'timeout',
            'not_supported',
        ]);
    });

    it('names its cases after their values', function (): void {
        expect(ErrorReason::NotConfigured->value)->toBe('not_configured')
            ->and(ErrorReason::CredentialsRejected->value)->toBe('credentials_rejected')
            ->and(ErrorReason::RateLimited->value)->toBe('rate_limited')
            ->and(ErrorReason::Unavailable->value)->toBe('unavailable')
            ->and(ErrorReason::Rejected->value)->toBe('rejected')
            ->and(ErrorReason::ProviderError->value)->toBe('provider_error')
            ->and(ErrorReason::Timeout->value)->toBe('timeout')
            ->and(ErrorReason::NotSupported->value)->toBe('not_supported');
    });
});

describe('GatewayException', function (): void {
    it('is a final runtime exception that can be encoded as JSON', function (): void {
        $reflection = new ReflectionClass(GatewayException::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isSubclassOf(RuntimeException::class))->toBeTrue()
            ->and($reflection->implementsInterface(JsonSerializable::class))->toBeTrue();
    });

    it('has only public readonly properties of its own', function (): void {
        $own = array_filter(
            (new ReflectionClass(GatewayException::class))->getProperties(),
            fn(ReflectionProperty $property): bool => $property->getDeclaringClass()->getName() === GatewayException::class,
        );

        expect(array_values(array_map(fn(ReflectionProperty $property): string => $property->getName(), $own)))
            ->toEqualCanonicalizing(['reason', 'traceId', 'httpStatus', 'providerCode', 'retryAfterSeconds', 'attempts', 'detail']);

        foreach ($own as $property) {
            expect($property->isPublic())->toBeTrue($property->getName() . ' must be public')
                ->and($property->isReadOnly())->toBeTrue($property->getName() . ' must be readonly');
        }
    });

    it('keeps every field', function (): void {
        $exception = new GatewayException(ErrorReason::RateLimited, 'trace-1', 429, 'too_many_requests', 30, 3, 'the wait is longer than the policy allows');

        expect($exception->reason)->toBe(ErrorReason::RateLimited)
            ->and($exception->traceId)->toBe('trace-1')
            ->and($exception->httpStatus)->toBe(429)
            ->and($exception->providerCode)->toBe('too_many_requests')
            ->and($exception->retryAfterSeconds)->toBe(30)
            ->and($exception->attempts)->toBe(3)
            ->and($exception->detail)->toBe('the wait is longer than the policy allows');
    });

    it('defaults to no status, no code, no wait, 0 attempts and no detail', function (): void {
        $exception = new GatewayException(ErrorReason::NotSupported, 'trace-1');

        expect($exception->httpStatus)->toBeNull()
            ->and($exception->providerCode)->toBeNull()
            ->and($exception->retryAfterSeconds)->toBeNull()
            ->and($exception->attempts)->toBe(0)
            ->and($exception->detail)->toBeNull()
            ->and($exception->getCode())->toBe(0);
    });

    describe('traceId', function (): void {
        it('accepts a trace id that matches the trace-id pattern', function (string $traceId): void {
            expect((new GatewayException(ErrorReason::Timeout, $traceId))->traceId)->toBe($traceId);
        })->with(['a', 'A-b_c.d:9', str_repeat('a', 128), '0123456789abcdef0123456789abcdef']);

        it('throws when the trace id does not match the trace-id pattern', function (string $traceId): void {
            new GatewayException(ErrorReason::Timeout, $traceId);
        })->with([
            'empty' => [''],
            'too long' => [str_repeat('a', 129)],
            'a space' => ['trace 1'],
            'a slash' => ['trace/1'],
            'a trailing line break' => ["trace-1\n"],
            'a line break inside' => ["trace\n1"],
            'invalid UTF-8' => ["trace\xff"],
            'a letter outside ASCII' => ['tracé'],
        ])->throws(InvalidArgumentException::class);
    });

    describe('httpStatus', function (): void {
        it('accepts a status from 100 to 599', function (int $status): void {
            expect((new GatewayException(ErrorReason::Rejected, 'trace-1', $status))->httpStatus)->toBe($status);
        })->with([100, 200, 404, 599]);

        it('throws when the status is outside 100 to 599', function (int $status): void {
            new GatewayException(ErrorReason::Rejected, 'trace-1', $status);
        })->with([99, 600, 0, -1])->throws(InvalidArgumentException::class);
    });

    describe('providerCode', function (): void {
        it('keeps a provider code that matches the pattern', function (string $code): void {
            expect((new GatewayException(ErrorReason::Rejected, 'trace-1', 400, $code))->providerCode)->toBe($code);
        })->with(['model_not_found', 'a', 'invalid-request.v2', '429', str_repeat('a', 64)]);

        it('lower-cases a provider code before it checks the pattern', function (): void {
            $exception = new GatewayException(ErrorReason::Rejected, 'trace-1', 400, 'RESOURCE_EXHAUSTED');

            expect($exception->providerCode)->toBe('resource_exhausted');
        });

        it('makes a provider code that does not match the pattern null, without an exception', function (string $code): void {
            $exception = new GatewayException(ErrorReason::Rejected, 'trace-1', 400, $code);

            expect($exception->providerCode)->toBeNull()
                ->and($exception->getMessage())->toBe('rejected (HTTP 400)');
        })->with([
            'empty' => [''],
            'too long' => [str_repeat('a', 65)],
            'a sentence' => ['The model does not exist'],
            'a trailing line break' => ["abc\n"],
            'a line break inside' => ["abc\ndef"],
            'a tab' => ["abc\t"],
            'invalid UTF-8' => ["abc\xff"],
            'a letter outside ASCII' => ['modèle'],
            'a letter outside ASCII in upper case' => ['MODÈLE'],
            'a slash' => ['errors/quota'],
            'a colon' => ['error:quota'],
            'a header value' => ['Bearer abc'],
            'a URL' => ['https://example.test/errors'],
        ]);
    });

    describe('retryAfterSeconds', function (): void {
        it('accepts a wait with rate_limited and with unavailable', function (ErrorReason $reason): void {
            expect((new GatewayException($reason, 'trace-1', null, null, 0))->retryAfterSeconds)->toBe(0)
                ->and((new GatewayException($reason, 'trace-1', null, null, 120))->retryAfterSeconds)->toBe(120);
        })->with([ErrorReason::RateLimited, ErrorReason::Unavailable]);

        it('throws when the wait is below 0', function (): void {
            new GatewayException(ErrorReason::RateLimited, 'trace-1', 429, null, -1);
        })->throws(InvalidArgumentException::class);

        it('throws when a wait is given with a reason other than rate_limited or unavailable', function (ErrorReason $reason): void {
            new GatewayException($reason, 'trace-1', null, null, 5);
        })->with([
            ErrorReason::NotConfigured,
            ErrorReason::CredentialsRejected,
            ErrorReason::Rejected,
            ErrorReason::ProviderError,
            ErrorReason::Timeout,
            ErrorReason::NotSupported,
        ])->throws(InvalidArgumentException::class);
    });

    describe('attempts', function (): void {
        it('accepts 0 attempts for a failure before any request', function (): void {
            expect((new GatewayException(ErrorReason::NotConfigured, 'trace-1', attempts: 0))->attempts)->toBe(0);
        });

        it('throws when the attempts are below 0', function (): void {
            new GatewayException(ErrorReason::Unavailable, 'trace-1', attempts: -1);
        })->throws(InvalidArgumentException::class);
    });

    describe('detail', function (): void {
        it('accepts a detail of one line up to 200 characters', function (string $detail): void {
            expect((new GatewayException(ErrorReason::Rejected, 'trace-1', detail: $detail))->detail)->toBe($detail);
        })->with([
            'a sentence' => ['tool name is longer than this format allows'],
            '200 characters' => [str_repeat('a', 200)],
            '200 characters of two bytes each' => [str_repeat('é', 200)],
        ]);

        it('throws when the detail is longer than 200 characters', function (string $detail): void {
            new GatewayException(ErrorReason::Rejected, 'trace-1', detail: $detail);
        })->with([
            '201 characters' => [str_repeat('a', 201)],
            '201 characters of two bytes each' => [str_repeat('é', 201)],
        ])->throws(InvalidArgumentException::class);

        it('throws when the detail is empty or only whitespace', function (string $detail): void {
            new GatewayException(ErrorReason::Rejected, 'trace-1', detail: $detail);
        })->with([
            'empty' => [''],
            'spaces' => ['   '],
            'a no-break space' => ["\u{00A0}"],
            'an em space' => ["\u{2003} "],
        ])->throws(InvalidArgumentException::class);

        it('throws when the detail holds a control character, a line or paragraph separator or a format control', function (string $detail): void {
            new GatewayException(ErrorReason::Rejected, 'trace-1', detail: $detail);
        })->with([
            'a line break' => ["first\nsecond"],
            'a carriage return' => ["first\rsecond"],
            'a tab' => ["first\tsecond"],
            'a null byte' => ["first\0second"],
            'a line separator U+2028' => ["first\u{2028}second"],
            'a paragraph separator U+2029' => ["first\u{2029}second"],
            'a right-to-left override U+202E' => ["first\u{202E}second"],
            'a zero-width space U+200B' => ["first\u{200B}second"],
            'a byte order mark U+FEFF' => ["\u{FEFF}first"],
        ])->throws(InvalidArgumentException::class);

        it('throws when the detail is not valid UTF-8', function (): void {
            new GatewayException(ErrorReason::Rejected, 'trace-1', detail: "detail\xff");
        })->throws(InvalidArgumentException::class);
    });

    describe('message', function (): void {
        it('builds the message from reason, status, provider code and detail only', function (
            ErrorReason $reason,
            ?int $status,
            ?string $code,
            ?string $detail,
            string $message,
        ): void {
            $exception = new GatewayException($reason, 'trace-zzz', $status, $code, null, 7, $detail);

            expect($exception->getMessage())->toBe($message);
        })->with([
            'reason only' => [ErrorReason::Timeout, null, null, null, 'timeout'],
            'status' => [ErrorReason::Unavailable, 503, null, null, 'unavailable (HTTP 503)'],
            'code' => [ErrorReason::ProviderError, null, 'empty_answer', null, 'provider_error (code: empty_answer)'],
            'status and code' => [ErrorReason::RateLimited, 429, 'too_many_requests', null, 'rate_limited (HTTP 429, code: too_many_requests)'],
            'detail' => [ErrorReason::Rejected, null, null, 'tool name is longer than this format allows', 'rejected: tool name is longer than this format allows'],
            'all' => [ErrorReason::Rejected, 400, 'bad_schema', 'the schema was refused', 'rejected (HTTP 400, code: bad_schema): the schema was refused'],
        ]);

        it('has no way to be given a message or a previous exception', function (): void {
            $constructor = (new ReflectionClass(GatewayException::class))->getConstructor();
            assert($constructor !== null);

            expect(array_map(fn(ReflectionParameter $parameter): string => $parameter->getName(), $constructor->getParameters()))
                ->toBe(['reason', 'traceId', 'httpStatus', 'providerCode', 'retryAfterSeconds', 'attempts', 'detail'])
                ->and((new GatewayException(ErrorReason::Timeout, 'trace-1'))->getPrevious())->toBeNull();
        });
    });

    describe('jsonSerialize', function (): void {
        it('gives the seven fields under their neutral names', function (): void {
            $exception = new GatewayException(ErrorReason::RateLimited, 'trace-1', 429, 'Too_Many_Requests', 30, 3, 'the wait is longer than the policy allows');

            expect($exception->jsonSerialize())->toBe([
                'reason' => 'rate_limited',
                'trace_id' => 'trace-1',
                'http_status' => 429,
                'provider_code' => 'too_many_requests',
                'retry_after_seconds' => 30,
                'attempts' => 3,
                'detail' => 'the wait is longer than the policy allows',
            ]);
        });

        it('encodes to JSON with nulls for what is not known', function (): void {
            $json = json_encode(new GatewayException(ErrorReason::NotSupported, 'trace-1'), JSON_THROW_ON_ERROR);

            expect($json)->toBe('{"reason":"not_supported","trace_id":"trace-1","http_status":null,"provider_code":null,"retry_after_seconds":null,"attempts":0,"detail":null}');
        });
    });

    describe('secrets', function (): void {
        it('shows the marker in no form when the error text, a header and the client failure hold it', function (bool $ignoreArgs): void {
            withExceptionIgnoreArgs($ignoreArgs, function (): void {
                $exception = gatewayErrorFromAttempt(
                    '{"error":{"message":"Incorrect API key provided: ' . LEAK_MARKER . '"}}',
                    ['Authorization' => 'Bearer ' . LEAK_MARKER],
                    new RuntimeException('request with key ' . LEAK_MARKER . ' failed'),
                );

                foreach (exceptionForms($exception) as $form => $text) {
                    expect(stripos($text, LEAK_MARKER))->toBeFalse($form . ' holds the marker')
                        ->and(stripos($text, 'sk-test-MARKER'))->toBeFalse($form . ' holds the start of the marker');
                }

                expect($exception->getPrevious())->toBeNull()
                    ->and(exceptionForms($exception)['serialize'])->toContain('credentials_rejected');
            });
        })->with(['arguments kept in traces' => [false], 'arguments left out of traces' => [true]]);

        it('keeps no argument of any call in its trace', function (): void {
            withExceptionIgnoreArgs(false, function (): void {
                $exception = gatewayErrorFromAttempt(LEAK_MARKER, ['x-api-key' => LEAK_MARKER], new RuntimeException(LEAK_MARKER));

                expect($exception->getTrace())->not->toBe([]);

                foreach ($exception->getTrace() as $frame) {
                    expect($frame)->not->toHaveKey('args');
                }

                expect($exception->getTrace()[0]['function'] ?? null)->toBe('gatewayErrorFromAttempt')
                    ->and($exception->getTraceAsString())->toContain('gatewayErrorFromAttempt()');
            });
        });

        it('shows the marker in no form of the exception for a refused argument', function (Closure $build): void {
            withExceptionIgnoreArgs(false, function () use ($build): void {
                $refused = null;

                try {
                    $build();
                } catch (InvalidArgumentException $exception) {
                    $refused = $exception;
                }

                expect($refused)->toBeInstanceOf(InvalidArgumentException::class);
                assert($refused instanceof InvalidArgumentException);

                // The frames of the constructor and of the call to it. The frames above them belong to the test
                // runner, whose own objects hold the data of every test in this file, the marker among it.
                $ownFrames = array_slice($refused->getTrace(), 0, 2);
                ob_start();
                var_dump($ownFrames);
                $dumped = (string) ob_get_clean();

                $forms = [
                    'getMessage' => $refused->getMessage(),
                    'string cast' => (string) $refused,
                    'getTraceAsString' => $refused->getTraceAsString(),
                    'var_export of the frames' => var_export($ownFrames, true),
                    'print_r of the frames' => print_r($ownFrames, true),
                    'var_dump of the frames' => $dumped,
                ];

                expect($ownFrames[0]['class'] ?? null)->toBe(GatewayException::class)
                    ->and($forms['print_r of the frames'])->toContain('SensitiveParameterValue');

                foreach ($forms as $form => $text) {
                    expect(stripos($text, LEAK_MARKER))->toBeFalse($form . ' holds the marker')
                        ->and(stripos($text, 'sk-test-MARKER'))->toBeFalse($form . ' holds the start of the marker');
                }
            });
        })->with([
            'the marker in the provider code, an invalid status' => [
                fn(): GatewayException => new GatewayException(ErrorReason::RateLimited, 't-1', 999, 'Incorrect API key: ' . LEAK_MARKER, 1),
            ],
            'the marker in the detail, an invalid status' => [
                fn(): GatewayException => new GatewayException(ErrorReason::Rejected, 't-1', 999, null, null, 1, 'key ' . LEAK_MARKER . ' refused'),
            ],
            'the marker in a detail that is itself refused' => [
                fn(): GatewayException => new GatewayException(ErrorReason::Rejected, 't-1', 400, null, null, 1, "key\n" . LEAK_MARKER),
            ],
        ]);

        it('marks the provider code and the detail as sensitive parameters', function (): void {
            $constructor = (new ReflectionClass(GatewayException::class))->getConstructor();
            assert($constructor !== null);

            $sensitive = [];
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                    $sensitive[] = $parameter->getName();
                }
            }

            expect($sensitive)->toBe(['providerCode', 'detail']);
        });

        it('would show the marker in an exception of plain PHP built at the same place', function (): void {
            // The string forms cut a string argument to zend.exception_string_param_max_len bytes: 15 unless a
            // php.ini says otherwise, 0 in php.ini-production. The other forms show every argument whole, whatever
            // that setting is. No expectation here carries the marker in its failure: see holdsMarker().
            foreach ([[15, 'sk-test-MARKER-...'], [0, "plainErrorFromAttempt('...', Array"], [1000, LEAK_MARKER]] as [$length, $shown]) {
                withExceptionIgnoreArgs(false, function () use ($length, $shown): void {
                    $forms = exceptionForms(plainErrorFromAttempt(
                        LEAK_MARKER,
                        ['Authorization' => 'Bearer ' . LEAK_MARKER],
                        new RuntimeException('request with key ' . LEAK_MARKER . ' failed'),
                    ));
                    $at = ', with ' . $length . ' bytes of a string argument shown';

                    foreach (['print_r', 'var_export', 'var_dump', 'getTrace'] as $form) {
                        expect(holdsMarker($forms[$form]))->toBeTrue($form . ' shows the whole argument' . $at);
                    }
                    foreach (['getTraceAsString', 'string cast'] as $form) {
                        expect(holdsMarker($forms[$form], $shown))->toBeTrue($form . ' shows the argument cut to that length' . $at);
                        expect(holdsMarker($forms[$form], 'MARKER'))->toBe($length !== 0, $form . ' holds the word of the marker' . $at);
                    }
                }, $length);
            }
        });

        it('drops the marker when it comes as a provider code in a sentence or a header value', function (string $code): void {
            $exception = new GatewayException(ErrorReason::CredentialsRejected, 'trace-1', 401, $code, null, 1);

            expect($exception->providerCode)->toBeNull();

            foreach (exceptionForms($exception) as $form => $text) {
                expect(stripos($text, LEAK_MARKER))->toBeFalse($form . ' holds the marker');
            }
        })->with([
            'an error sentence' => ['Incorrect API key provided: ' . LEAK_MARKER],
            'a header value' => ['Bearer ' . LEAK_MARKER],
            'a line with the key after a line break' => ["invalid_api_key\n" . LEAK_MARKER],
        ]);

        it('cannot tell a bare key from a code: a provider code that is nothing but the marker is kept, lower-cased', function (): void {
            $exception = new GatewayException(ErrorReason::CredentialsRejected, 'trace-1', 401, LEAK_MARKER, null, 1);

            expect($exception->providerCode)->toBe(strtolower(LEAK_MARKER))
                ->and(holdsMarker($exception->getMessage()))->toBeFalse()
                ->and(holdsMarker($exception->getMessage(), strtolower(LEAK_MARKER)))->toBeTrue();
        });
    });
});
