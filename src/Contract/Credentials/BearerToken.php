<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Credentials;

use Error;
use Generator;
use OrganiseYou\LlmGateway\Contract\Secret;
use SensitiveParameter;
use Throwable;

/**
 * A short-lived token that is sent as a bearer token in the authorization header. The token comes from a supplier
 * of the host and is asked again for every attempt; nothing of it is kept here.
 *
 * The supplier is the host's own object and may hold a secret in a plain property. It is therefore kept the way a
 * secret keeps its value: as the local variable of a paused generator, which no output function, array cast or
 * reflection read shows.
 */
final readonly class BearerToken implements Credentials
{
    /** @var Generator<int, TokenSupplier, mixed, never> */
    private Generator $holder;

    public function __construct(#[SensitiveParameter] TokenSupplier $tokens)
    {
        $this->holder = self::hold($tokens);
    }

    public function kind(): CredentialKind
    {
        return CredentialKind::BearerToken;
    }

    /**
     * Always empty: a token is not known before the supplier is asked, and the supplier is not asked here.
     */
    public function secrets(): array
    {
        return [];
    }

    /**
     * Asks the supplier for a token, now. The one place where the supplier is called.
     *
     * Whatever the supplier throws is not passed on, chained or copied: its message and its trace can hold the
     * token. What is thrown here has a fixed text with at most the class of what was thrown.
     *
     * @throws TokenSupplierFailed when the supplier throws an exception, or gives a token that cannot be sent as
     *                             it is: one with a control character, or with a space or tab at its start or end
     * @throws Error when the supplier fails with an error: a fault in the code of the host, not a failed sign-in
     */
    public function token(): Secret
    {
        $token = null;
        $thrownClass = null;
        $isError = false;

        try {
            $token = $this->holder->current()->token();
            $value = $token->reveal();
            if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1 || preg_match('/^[ \t]|[ \t]$/D', $value) === 1) {
                $token = null;
            }
            unset($value);
        } catch (Throwable $thrown) {
            $token = null;
            $thrownClass = $thrown::class;
            $isError = $thrown instanceof Error;
            unset($thrown);
        }

        // Thrown here and not in the catch: nothing of what the supplier threw is at hand any more.
        if ($thrownClass !== null) {
            if ($isError) {
                // An anonymous class carries the path of its file after a null byte.
                $name = strstr($thrownClass, "\0", true);

                throw new Error('The token supplier failed with an error of class ' . ($name === false ? $thrownClass : $name) . '.');
            }

            throw TokenSupplierFailed::threw($thrownClass);
        }
        if ($token === null) {
            throw TokenSupplierFailed::gaveUnusableToken();
        }

        return $token;
    }

    /**
     * Never ends and never moves on: every read of the current value gives the supplier, also for a copy of the
     * object, which shares the holder.
     *
     * @return Generator<int, TokenSupplier, mixed, never>
     */
    private static function hold(#[SensitiveParameter] TokenSupplier $tokens): Generator
    {
        for (;;) {
            yield $tokens;
        }
    }
}
