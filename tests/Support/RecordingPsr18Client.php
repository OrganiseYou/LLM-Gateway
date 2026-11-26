<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use Closure;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A PSR-18 client for tests. It opens no connection: it records every request and answers from a map of
 * "METHOD url" to a response, an exception to throw, or a closure that makes either.
 */
final class RecordingPsr18Client implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /**
     * @param array<string, ResponseInterface|Throwable|Closure(RequestInterface): ResponseInterface> $answers
     * @param bool $record false: keep no request, for a test that prints everything the stack can reach
     */
    public function __construct(private readonly array $answers, private readonly bool $record = true) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->record) {
            $this->requests[] = $request;
        }

        $key = $request->getMethod() . ' ' . $request->getUri();
        $answer = $this->answers[$key] ?? throw new LogicException('No answer for ' . $key);

        if ($answer instanceof Closure) {
            return $answer($request);
        }
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * @return list<string> "METHOD url" of every request, in order
     */
    public function asked(): array
    {
        return array_map(
            fn(RequestInterface $request): string => $request->getMethod() . ' ' . $request->getUri(),
            $this->requests,
        );
    }
}
