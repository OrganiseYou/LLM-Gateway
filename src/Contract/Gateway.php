<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use InvalidArgumentException;

/**
 * The one interface of the library: the same five operations, in the same shape, whoever answers.
 */
interface Gateway
{
    /**
     * One completion. An unusable structured answer is returned with its reasons, never thrown.
     *
     * @throws GatewayException on a transport or provider failure
     */
    public function complete(Request $request): Answer;

    /**
     * The same completion as events. Nothing is sent before the caller starts to iterate. A failure is never
     * thrown as a gateway exception: it is the last event, a StreamFailed.
     *
     * The timeout of the request covers the whole stream, and the clock runs while the caller handles an event:
     * the caller's own time between two events counts against it. An adapter streams natively only where the
     * provider sends server-sent events; any other way of streaming is reported as emulated, and the whole answer
     * then comes as one text event.
     *
     * @return iterable<int, Stream\StreamEvent>
     */
    public function stream(Request $request): iterable;

    /**
     * The models the credentials may use.
     *
     * @param float $timeoutSeconds for the whole call; finite and above 0
     * @param string|null $traceId letters, digits and _ . : - up to 128 characters; null: the library makes one
     * @return list<ModelInfo>
     * @throws GatewayException with the reason not_supported when the models cannot be listed
     * @throws InvalidArgumentException for a timeout or a trace id that breaks its rule
     */
    public function models(float $timeoutSeconds, ?string $traceId = null): array;

    /**
     * What this model can do through this gateway. Sends no request and cannot fail.
     *
     * @param string $model not empty, no control character
     * @throws InvalidArgumentException for a model name that breaks its rule
     */
    public function capabilities(string $model): Capabilities;

    /**
     * The connection check. A failure of the provider is an answer "no", not an exception.
     *
     * @param float $timeoutSeconds for each request of the check; finite and above 0
     * @param string|null $traceId letters, digits and _ . : - up to 128 characters; null: the library makes one
     */
    public function check(string $model, float $timeoutSeconds, ?string $traceId = null): ConnectionReport;
}
