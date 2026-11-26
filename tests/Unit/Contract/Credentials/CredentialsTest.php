<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\Credentials\NoCredentials;
use OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplier;
use OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplierFailed;
use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Transport\HttpRequest;

/**
 * A supplier as a careless host could write it: the token sits in a plain property, and every call is counted.
 */
function countingSupplier(): TokenSupplier
{
    return new class implements TokenSupplier {
        public int $asked = 0;

        public string $plain = LEAK_MARKER;

        public function token(): Secret
        {
            $this->asked++;

            return new Secret($this->plain . '-' . $this->asked);
        }
    };
}

/**
 * A supplier that fails the way the test says. The marker is in everything it throws.
 *
 * @param Closure(): Secret $behaviour
 */
function scriptedSupplier(Closure $behaviour): TokenSupplier
{
    return new class ($behaviour) implements TokenSupplier {
        /**
         * @param Closure(): Secret $behaviour
         */
        public function __construct(private Closure $behaviour) {}

        public function token(): Secret
        {
            return ($this->behaviour)();
        }
    };
}

/**
 * One credential of every kind, each made with the marker. Built here and not in a dataset: the test runner keeps
 * a dataset reachable from the arguments in a trace.
 *
 * @return array<string, Credentials>
 */
function credentialsWithMarker(): array
{
    return [
        'no credentials' => new NoCredentials(),
        'bearer key' => new BearerKey(LEAK_MARKER),
        'header key' => new HeaderKey(LEAK_MARKER),
        'bearer token' => new BearerToken(countingSupplier()),
    ];
}

/**
 * A key credential of the class the dataset names. The key is a sensitive parameter here too, so this frame does
 * not keep it in the trace of a refusal.
 */
function keyCredential(string $class, #[SensitiveParameter] string $key): BearerKey|HeaderKey
{
    return $class === BearerKey::class ? new BearerKey($key) : new HeaderKey($key);
}

describe('CredentialKind', function (): void {
    it('has the four kinds and no other, with neutral names', function (): void {
        expect(array_map(fn(CredentialKind $kind): string => $kind->value, CredentialKind::cases()))
            ->toBe(['none', 'bearer_key', 'header_key', 'bearer_token'])
            ->and(CredentialKind::None->value)->toBe('none')
            ->and(CredentialKind::BearerKey->value)->toBe('bearer_key')
            ->and(CredentialKind::HeaderKey->value)->toBe('header_key')
            ->and(CredentialKind::BearerToken->value)->toBe('bearer_token');
    });
});

describe('every credential', function (): void {
    it('is a final readonly class that implements the credentials interface', function (): void {
        foreach ([NoCredentials::class, BearerKey::class, HeaderKey::class, BearerToken::class] as $class) {
            $reflection = new ReflectionClass($class);

            expect($reflection->isFinal())->toBeTrue($class . ' must be final')
                ->and($reflection->isReadOnly())->toBeTrue($class . ' must be readonly')
                ->and($reflection->implementsInterface(Credentials::class))->toBeTrue($class . ' must be a credential')
                ->and($reflection->getStaticProperties())->toBe([], $class . ' must keep nothing between calls');
        }
    });

    it('says its kind', function (): void {
        $kinds = array_map(fn(Credentials $credentials): CredentialKind => $credentials->kind(), credentialsWithMarker());

        expect($kinds)->toBe([
            'no credentials' => CredentialKind::None,
            'bearer key' => CredentialKind::BearerKey,
            'header key' => CredentialKind::HeaderKey,
            'bearer token' => CredentialKind::BearerToken,
        ]);
    });

    it('marks every parameter that carries a secret as sensitive', function (): void {
        $parameters = [
            new ReflectionParameter([BearerKey::class, '__construct'], 'key'),
            new ReflectionParameter([HeaderKey::class, '__construct'], 'key'),
            new ReflectionParameter([BearerToken::class, '__construct'], 'tokens'),
            new ReflectionParameter([BearerToken::class, 'hold'], 'tokens'),
        ];

        foreach ($parameters as $parameter) {
            $where = $parameter->getDeclaringClass()?->getName() . '::' . $parameter->getDeclaringFunction()->getName();

            expect($parameter->getAttributes(SensitiveParameter::class))->toHaveCount(1, $where . ' must hide $' . $parameter->getName());
        }
    });

    it('takes a secret through its constructor only: no other method has a parameter', function (): void {
        foreach ([NoCredentials::class, BearerKey::class, HeaderKey::class, BearerToken::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->getName() !== '__construct' && $method->getName() !== 'hold') {
                    expect($method->getNumberOfParameters())->toBe(0, $class . '::' . $method->getName() . ' takes a value');
                }
            }
        }
    });

    it('does not show the secret in any output form, an array cast or a read through reflection', function (): void {
        foreach (credentialsWithMarker() as $credentials) {
            expectNoMarker(valueForms($credentials));
        }
    });

    it('does not show the secret in any output form of a copy or of a list or object that holds it', function (): void {
        foreach (credentialsWithMarker() as $credentials) {
            foreach ([clone $credentials, ['credentials' => $credentials], (object) ['credentials' => $credentials]] as $holder) {
                expectNoMarker(valueForms($holder));
            }
        }
    });

    it('does not show the secret in any output form of what it returns', function (): void {
        foreach (credentialsWithMarker() as $credentials) {
            expectNoMarker(valueForms($credentials->secrets()));
            expectNoMarker(valueForms($credentials->kind()));
        }
    });

    it('does not show the secret after it was used', function (): void {
        foreach (credentialsWithMarker() as $credentials) {
            foreach ($credentials->secrets() as $secret) {
                $secret->reveal();
            }
            if ($credentials instanceof BearerToken) {
                $credentials->token()->reveal();
            }

            expectNoMarker(valueForms($credentials));
        }
    });

    it('does not show the secret in the trace of an exception made while it is an argument', function (bool $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function (): void {
            $fail = fn(Credentials $credentials, array $all): RuntimeException => new RuntimeException('failed');

            foreach (credentialsWithMarker() as $credentials) {
                $exception = $fail($credentials, [$credentials, $credentials->secrets()]);

                expect($exception->getTrace())->not->toBe([]);
                expectNoMarker(exceptionForms($exception));
            }
        });
    })->with(['arguments kept' => [false], 'arguments left out' => [true]]);

    it('cannot be stored when it holds a secret or a supplier', function (): void {
        foreach (credentialsWithMarker() as $name => $credentials) {
            if ($credentials instanceof NoCredentials) {
                continue;
            }

            $stored = null;
            try {
                $stored = serialize($credentials);
            } catch (Exception) {
                // refused, as it must be
            }

            expect($stored)->toBeNull($name . ' was serialized');
        }
    });
});

describe('NoCredentials', function (): void {
    it('has no secret and no field', function (): void {
        $credentials = new NoCredentials();

        expect($credentials->secrets())->toBe([])
            ->and((array) $credentials)->toBe([])
            ->and(json_encode($credentials))->toBe('{}');
    });
});

describe('BearerKey and HeaderKey', function (): void {
    it('wraps the key in a secret and gives exactly that secret to redact', function (string $class): void {
        $credentials = keyCredential($class, LEAK_MARKER);

        expect($credentials->key)->toBeInstanceOf(Secret::class)
            ->and($credentials->key->reveal())->toBe(LEAK_MARKER)
            ->and($credentials->secrets())->toBe([$credentials->key])
            ->and($credentials->secrets()[0]->reveal())->toBe(LEAK_MARKER);
    })->with([BearerKey::class, HeaderKey::class]);

    it('keeps a key with any other bytes as it is', function (string $class, string $key): void {
        expect(keyCredential($class, $key)->key->reveal())->toBe($key);
    })->with([BearerKey::class, HeaderKey::class])->with([
        'one character' => ['k'],
        'spaces inside' => ['key with  spaces'],
        'every visible ASCII character' => [implode('', array_map(chr(...), range(0x21, 0x7e)))],
        'bytes from 0x80 to 0xff' => [implode('', array_map(chr(...), range(0x80, 0xff)))],
        'not UTF-8' => ["key\xff"],
        'long' => [str_repeat('k', 10000)],
    ]);

    it('shows a mask as JSON', function (string $class): void {
        expect(json_encode(keyCredential($class, LEAK_MARKER)))->toBe('{"key":"[secret]"}');
    })->with([BearerKey::class, HeaderKey::class]);

    it('lets a redactor that knows the secrets clean the header that is sent', function (): void {
        $bearer = new BearerKey(LEAK_MARKER);
        $header = new HeaderKey(LEAK_MARKER . '-2');

        $request = new HttpRequest('POST', 'https://api.example.test/v1/chat', ['Content-Type' => 'application/json'], [
            'Authorization' => new Secret('Bearer ' . $bearer->key->reveal()),
            'X-Example-Key' => $header->key,
        ], 5.0, '{}');

        $registered = array_map(
            fn(Secret $secret): string => $secret->reveal(),
            [...$header->secrets(), ...$bearer->secrets()],
        );
        $sent = $request->allHeaders();

        expect($sent['X-Example-Key'])->toBe($registered[0])
            ->and($sent['Authorization'])->toBe('Bearer ' . $registered[1]);

        $redacted = array_map(fn(string $value): string => str_replace($registered, '[redacted]', $value), $sent);

        expect($redacted['Authorization'])->toBe('Bearer [redacted]')
            ->and($redacted['X-Example-Key'])->toBe('[redacted]');
        expectNoMarker($redacted);
    });

    it('refuses an empty key', function (string $class): void {
        expect(fn() => keyCredential($class, ''))->toThrow(InvalidArgumentException::class, 'The key must not be empty.');
    })->with([BearerKey::class, HeaderKey::class]);

    it('refuses a key with a control character, without the key in the refusal', function (string $class, string $before, string $after): void {
        withExceptionIgnoreArgs(false, function () use ($class, $before, $after): void {
            $refusal = null;
            try {
                keyCredential($class, $before . LEAK_MARKER . $after);
            } catch (InvalidArgumentException $exception) {
                $refusal = $exception;
            }

            expect($refusal)->toBeInstanceOf(InvalidArgumentException::class)
                ->and($refusal?->getMessage())->toBe('The key must not hold a control character, a line break or a tab.');
            expectNoMarker(exceptionForms($refusal ?? new LogicException('The key was accepted.')));
        });
    })->with([BearerKey::class, HeaderKey::class])->with([
        'a line feed at the end' => ['', "\n"],
        'a carriage return at the end' => ['', "\r"],
        'a line feed at the start' => ["\n", ''],
        'a header smuggled in after the key' => ['', "\r\nX-Injected: 1"],
        'a carriage return in the middle' => ["key\r", 'key'],
        'a null byte' => ['', "\x00"],
        'a null byte in the middle' => ["key\x00", 'key'],
        'a tab in the middle' => ["key\t", 'key'],
        'a tab at the end' => ['', "\t"],
        'a tab at the start' => ["\t", ''],
        'the lowest control character' => ["\x01", ''],
        'the highest control character below the space' => ['', "\x1f"],
        'an escape' => ["\x1b[0m", ''],
        'a delete character' => ['', "\x7f"],
    ]);

    it('refuses a key that starts or ends with a space, which a client would trim away', function (string $class, string $before, string $after): void {
        withExceptionIgnoreArgs(false, function () use ($class, $before, $after): void {
            $refusal = null;
            try {
                keyCredential($class, $before . LEAK_MARKER . $after);
            } catch (InvalidArgumentException $exception) {
                $refusal = $exception;
            }

            expect($refusal)->toBeInstanceOf(InvalidArgumentException::class)
                ->and($refusal?->getMessage())->toBe('The key must not start or end with a space.');
            expectNoMarker(exceptionForms($refusal ?? new LogicException('The key was accepted.')));
        });
    })->with([BearerKey::class, HeaderKey::class])->with([
        'a space at the end' => ['', ' '],
        'a space at the start' => [' ', ''],
        'spaces on both sides' => ['  ', '  '],
    ]);

    it('refuses a key of one space', function (string $class): void {
        expect(fn() => keyCredential($class, ' '))->toThrow(InvalidArgumentException::class, 'The key must not start or end with a space.');
    })->with([BearerKey::class, HeaderKey::class]);

    it('hides the key in the trace of its refusal, with the arguments of calls kept and left out', function (string $class, bool $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function () use ($class, $ignoreArgs): void {
            try {
                keyCredential($class, LEAK_MARKER . "\n");
            } catch (InvalidArgumentException $exception) {
                $frame = $exception->getTrace()[0];

                expect($frame['function'])->toBe('__construct')
                    ->and($frame['class'] ?? null)->toBe($class);
                if (! $ignoreArgs) {
                    expect($frame['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);
                } else {
                    expect($frame)->not->toHaveKey('args');
                }
                expectNoMarker(exceptionForms($exception));

                return;
            }

            throw new LogicException('The key was accepted.');
        });
    })->with([BearerKey::class, HeaderKey::class])->with(['arguments kept' => [false], 'arguments left out' => [true]]);

    it('refuses a value that is not a string', function (): void {
        expect(fn() => new BearerKey(untyped(null)))->toThrow(TypeError::class)
            ->and(fn() => new HeaderKey(untyped(null)))->toThrow(TypeError::class)
            ->and(fn() => new BearerKey(untyped(new Secret('k'))))->toThrow(TypeError::class)
            ->and(fn() => new HeaderKey(untyped(new Secret('k'))))->toThrow(TypeError::class);
    });
});

describe('TokenSupplierFailed', function (): void {
    it('is a final runtime exception that only the library builds, with a fixed text and nothing chained', function (): void {
        $reflection = new ReflectionClass(TokenSupplierFailed::class);
        $failure = TokenSupplierFailed::threw('Vendor\\Sdk\\TokenError');

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->getConstructor()?->isPrivate())->toBeTrue()
            ->and($reflection->getStaticProperties())->toBe([])
            ->and($failure)->toBeInstanceOf(RuntimeException::class)
            ->and($failure->getMessage())->toBe('The token supplier failed with an exception of class Vendor\\Sdk\\TokenError.')
            ->and($failure->getPrevious())->toBeNull()
            ->and(TokenSupplierFailed::gaveUnusableToken()->getPrevious())->toBeNull();
    });
});

describe('BearerToken', function (): void {
    it('has no secret to give before the first request, and does not ask the supplier for one', function (): void {
        $supplier = countingSupplier();
        $credentials = new BearerToken($supplier);

        expect($credentials->secrets())->toBe([])
            ->and($credentials->kind())->toBe(CredentialKind::BearerToken)
            ->and(property_exists($supplier, 'asked') ? $supplier->asked : null)->toBe(0);
    });

    it('asks the supplier every time and keeps no token', function (): void {
        $supplier = countingSupplier();
        $credentials = new BearerToken($supplier);

        $first = $credentials->token();
        $second = $credentials->token();
        $third = (clone $credentials)->token();

        expect($first->reveal())->toBe(LEAK_MARKER . '-1')
            ->and($second->reveal())->toBe(LEAK_MARKER . '-2')
            ->and($third->reveal())->toBe(LEAK_MARKER . '-3')
            ->and(property_exists($supplier, 'asked') ? $supplier->asked : null)->toBe(3)
            ->and($credentials->secrets())->toBe([]);
    });

    it('gives the very secret the supplier gave', function (): void {
        $token = new Secret(LEAK_MARKER);

        expect((new BearerToken(scriptedSupplier(fn(): Secret => $token)))->token())->toBe($token);
    });

    it('holds the supplier in no property that a dump or reflection can read', function (): void {
        $credentials = new BearerToken(countingSupplier());

        foreach ((new ReflectionObject($credentials))->getProperties() as $property) {
            expect($property->getValue($credentials))->not->toBeInstanceOf(TokenSupplier::class);
        }
        expect(json_encode($credentials))->toBe('{}');
        expectNoMarker(valueForms($credentials));
    });

    it('throws a fresh exception with a fixed text when the supplier throws, with nothing of what was thrown', function (bool $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function (): void {
            $credentials = new BearerToken(scriptedSupplier(function (): Secret {
                $argument = fn(string $token): Exception => new DomainException('Refused the token ' . $token, 7, new LogicException($token));

                throw $argument(LEAK_MARKER);
            }));

            $failure = null;
            try {
                $credentials->token();
            } catch (Throwable $thrown) {
                $failure = $thrown;
            }

            expect($failure)->toBeInstanceOf(RuntimeException::class)
                ->and($failure === null ? null : $failure::class)->toBe(TokenSupplierFailed::class)
                ->and($failure?->getMessage())->toBe('The token supplier failed with an exception of class DomainException.')
                ->and($failure?->getPrevious())->toBeNull()
                ->and($failure?->getCode())->toBe(0);
            expectNoMarker(exceptionForms($failure ?? new LogicException('Nothing was thrown.')));
            expectNoMarker(valueForms($credentials));
        });
    })->with(['arguments kept' => [false], 'arguments left out' => [true]]);

    it('throws a fresh error with a fixed text when the supplier fails with an error: a fault in code is no failed sign-in', function (bool $ignoreArgs): void {
        withExceptionIgnoreArgs($ignoreArgs, function (): void {
            $credentials = new BearerToken(scriptedSupplier(function (): Secret {
                $typed = fn(int $token): Secret => new Secret((string) $token);

                return $typed(untyped(LEAK_MARKER));
            }));

            $failure = null;
            try {
                $credentials->token();
            } catch (Throwable $thrown) {
                $failure = $thrown;
            }

            expect($failure === null ? null : $failure::class)->toBe(Error::class)
                ->and($failure?->getMessage())->toBe('The token supplier failed with an error of class TypeError.')
                ->and($failure?->getPrevious())->toBeNull()
                ->and($failure?->getCode())->toBe(0);
            expectNoMarker(exceptionForms($failure ?? new LogicException('Nothing was thrown.')));
        });
    })->with(['arguments kept' => [false], 'arguments left out' => [true]]);

    it('names an anonymous exception class without the path of its file', function (): void {
        $credentials = new BearerToken(scriptedSupplier(function (): Secret {
            throw new class (LEAK_MARKER) extends Exception {};
        }));

        $failure = null;
        try {
            $credentials->token();
        } catch (Throwable $thrown) {
            $failure = $thrown;
        }

        expect($failure?->getMessage())->toBe('The token supplier failed with an exception of class Exception@anonymous.');
        expectNoMarker(exceptionForms($failure ?? new LogicException('Nothing was thrown.')));
    });

    it('treats a supplier that cannot make a token, because it is empty, as a supplier that failed', function (): void {
        $credentials = new BearerToken(scriptedSupplier(fn(): Secret => new Secret('')));

        expect(fn() => $credentials->token())
            ->toThrow(TokenSupplierFailed::class, 'The token supplier failed with an exception of class InvalidArgumentException.');
    });

    it('names an anonymous error class without the path of its file', function (): void {
        $credentials = new BearerToken(scriptedSupplier(function (): Secret {
            throw new class (LEAK_MARKER) extends Error {};
        }));

        $failure = null;
        try {
            $credentials->token();
        } catch (Throwable $thrown) {
            $failure = $thrown;
        }

        expect($failure === null ? null : $failure::class)->toBe(Error::class)
            ->and($failure?->getMessage())->toBe('The token supplier failed with an error of class Error@anonymous.');
        expectNoMarker(exceptionForms($failure ?? new LogicException('Nothing was thrown.')));
    });

    it('refuses a token that cannot be sent as it is, without the token in the refusal', function (string $before, string $after): void {
        foreach ([false, true] as $ignoreArgs) {
            withExceptionIgnoreArgs($ignoreArgs, function () use ($before, $after): void {
                $credentials = new BearerToken(scriptedSupplier(fn(): Secret => new Secret($before . LEAK_MARKER . $after)));

                $failure = null;
                try {
                    $credentials->token();
                } catch (Throwable $thrown) {
                    $failure = $thrown;
                }

                expect($failure === null ? null : $failure::class)->toBe(TokenSupplierFailed::class)
                    ->and($failure?->getMessage())->toBe('The token supplier gave a token with a control character, or with a space or tab at its start or end.')
                    ->and($failure?->getPrevious())->toBeNull();
                expectNoMarker(exceptionForms($failure ?? new LogicException('Nothing was thrown.')));
            });
        }
    })->with([
        'a header smuggled in after the token' => ['', "\r\nX-Injected: 1"],
        'a carriage return only' => ['', "\r"],
        'a line feed only' => ['', "\n"],
        'a carriage return in the middle' => ["token\r", 'token'],
        'a line feed in the middle' => ["token\n", 'token'],
        'a null byte' => ["token\x00", ''],
        'a tab in the middle' => ["token\t", 'token'],
        'a delete character' => ['', "\x7f"],
        'a space at the end' => ['', ' '],
        'a space at the start' => [' ', ''],
        'a tab at the end' => ['', "\t"],
        'a tab at the start' => ["\t", ''],
    ]);

    it('gives a token with spaces inside or with bytes from 0x80 on as it is', function (string $token): void {
        expect((new BearerToken(scriptedSupplier(fn(): Secret => new Secret($token))))->token()->reveal())->toBe($token);
    })->with([
        'spaces inside' => ['token with  spaces'],
        'bytes from 0x80 to 0xff' => [implode('', array_map(chr(...), range(0x80, 0xff)))],
        'every visible ASCII character' => [implode('', array_map(chr(...), range(0x21, 0x7e)))],
    ]);

    it('asks the supplier again after a failure: it remembers nothing', function (): void {
        $calls = 0;
        $credentials = new BearerToken(scriptedSupplier(function () use (&$calls): Secret {
            $calls++;
            if ($calls === 1) {
                throw new RuntimeException(LEAK_MARKER);
            }

            return new Secret('token-' . $calls);
        }));

        expect(fn() => $credentials->token())->toThrow(TokenSupplierFailed::class)
            ->and($credentials->token()->reveal())->toBe('token-2');
    });
});
