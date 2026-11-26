<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use DomainException;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\HttpStreamResponse;
use OrganiseYou\LlmGateway\Transport\Transport;

/**
 * A transport that breaks its promise: it throws an exception that is not the one of a transport.
 */
final class BrokenTransport implements Transport
{
    public function send(HttpRequest $request): HttpResponse
    {
        throw new DomainException('The transport of the host has a fault of its own.');
    }

    public function stream(HttpRequest $request): HttpStreamResponse
    {
        throw new DomainException('The transport of the host has a fault of its own.');
    }
}
