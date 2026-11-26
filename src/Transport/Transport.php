<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

/**
 * The only way out of the library: every request goes through an object of this kind, given by the host.
 *
 * A transport must send exactly the request it gets, must not follow a redirect, must not change the host,
 * honours the request's timeout when it can, and returns every status, also 4xx and 5xx, as a response. The host
 * puts its outbound rule, its tracing and its redaction here.
 */
interface Transport
{
    /**
     * @throws TransportException
     */
    public function send(HttpRequest $request): HttpResponse;

    /**
     * Sends the request and hands out the body as chunks while it arrives.
     *
     * @throws TransportException also while the chunks are read
     */
    public function stream(HttpRequest $request): HttpStreamResponse;
}
