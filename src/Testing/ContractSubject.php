<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use LogicException;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\Request;
use SensitiveParameter;

/**
 * One gateway under the shared test set, with what plays its provider. A subject serves one scenario: it is built,
 * told what the provider will do, and thrown away.
 *
 * The library has two: AdapterSubject for a gateway that sends HTTP requests through a transport, and
 * FakeGatewaySubject for the fake gateway.
 */
interface ContractSubject
{
    public function gateway(): Gateway;

    /**
     * The model the set asks for.
     */
    public function model(): string;

    /**
     * Whether a call of this gateway is made of HTTP attempts. Without them there is nothing to retry and no time
     * to run out: the set skips those scenarios, and says so.
     */
    public function makesAttempts(): bool;

    /**
     * The provider answers the next request with this.
     *
     * @param bool $asStream the request will come from stream(); a gateway that streams natively then gets the
     *                       answer as a stream
     * @throws LogicException when there is no fixture for it, or the fixtures say it does not apply: ask
     *                        cannotGive() first
     */
    public function gives(ContractFixture $fixture, bool $asStream): void;

    /**
     * Why the provider of this gateway can never give this answer, in the words of whoever wrote its fixtures; null
     * when it can be given. Only an answer with opaque data may not apply, and then for the whole response and for
     * the stream alike.
     *
     * @throws LogicException when the fixtures say it for one of the two and not for the other, or for another case
     */
    public function cannotGive(ContractFixture $fixture, bool $asStream): ?string;

    /**
     * The next request fails this way.
     *
     * @param int|null $retryAfterSeconds the wait the provider asks for, when it asks for one
     * @param bool $asStream the request will come from stream()
     * @param string|null $echo the words the provider repeats in its error, as its message and as its own code;
     *                          null: the key of the set
     * @throws LogicException when the failure cannot be played: ask cannotPlay() first
     */
    public function fails(ContractFailure $failure, ?int $retryAfterSeconds = null, bool $asStream = false, ?string $echo = null): void;

    /**
     * Why this failure cannot happen with this gateway, in the words of whoever wrote its fixtures; null when it
     * can be played.
     */
    public function cannotPlay(ContractFailure $failure, bool $asStream): ?string;

    /**
     * The request, changed so that the gateway cannot carry it and must refuse it as rejected; or why no such
     * request exists for this gateway.
     */
    public function requestItCannotCarry(Request $request): Request|NotApplicable;

    /**
     * Whether a request so far held this text in its address, its headers or its body. A gateway that makes no
     * attempts has no provider: it answers whether a request it received so far held the text in an opaque part
     * of a message, the one thing of a request that is meant for a provider alone.
     */
    public function sentToTheProvider(string $text): bool;

    /**
     * The provider answers the next request, which comes from stream(), with the stream of the plain answer, and
     * the connection breaks after its first piece of text arrived.
     */
    public function breaksAfterTheFirstText(): void;

    /**
     * The next thing the provider does takes this long. Nothing waits: the clock of the subject moves.
     */
    public function delays(float $seconds): void;

    /**
     * How many requests reached the provider so far.
     */
    public function requestsReceived(): int;

    /**
     * Every wait between two attempts so far, in seconds.
     *
     * @return list<float>
     */
    public function waits(): array;

    /**
     * The timeout each request was sent with, in seconds. Empty for a gateway that makes no attempts.
     *
     * @return list<float>
     */
    public function timeoutsGiven(): array;

    /**
     * Whether a request so far was signed with this key. A gateway that makes no attempts answers false.
     */
    public function signedInWith(#[SensitiveParameter] string $key): bool;

    /**
     * Everything besides the result of a call that a host can log: each request as the reports of the attempts
     * show it, in every output form. The responses are not in it: they are the provider's words, and the host
     * redacts them itself.
     *
     * @return array<string, string>
     */
    public function logged(): array;
}
