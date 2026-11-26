<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

use Error;
use Generator;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use SensitiveParameter;
use Throwable;

/**
 * The bridge from the library's transport to any PSR-18 client the host has.
 *
 * It sends each request once, as it is, and opens no connection itself. A response with a status of 300 to 399
 * is the failure "redirected": its headers are not read and no second request is made.
 *
 * What the bridge cannot see or do, and the host therefore must set on its client:
 *
 * - Redirects. A client that follows a redirect inside its own send method hands the bridge only the last
 *   response; the bridge cannot tell. The client must be set never to follow a redirect.
 * - Error statuses. The client must return a 4xx or 5xx as a response, as PSR-18 asks, and not throw for it.
 * - Time. PSR-18 has no timeout per request, so the timeout of the request is not passed on. The host sets a
 *   connect timeout and a read timeout on its client, or writes its own transport. The caller of the bridge
 *   still checks its deadline between attempts and between chunks. A read timeout of the client cannot be told
 *   from a lost connection: the bridge reports it as a failed connection, not as a timeout.
 * - Streams. Whether stream() hands out the body while it arrives depends on the client alone. A client that
 *   reads the whole body before it returns the response makes every chunk arrive together at the end; the text
 *   is the same, only later. For chunks as they arrive, the client must be set to return the response as soon
 *   as the headers are in and to read the body on demand. The bridge works either way.
 * - Reading. A read of the response body must block until there is data, the body has ended, or the client's
 *   own timeout has passed. A body whose read returns an empty text at once while nothing has arrived yet is
 *   taken for a lost connection after three such reads in a row, also when the stream is healthy.
 * - Reuse. The client must keep nothing between two requests that changes the next one: no cookies, no
 *   remembered sign-in.
 * - Spelling. The message classes of the host may write the address in their own way (the case of the scheme
 *   and the host, a default port, the percent-encoding of the query) and may trim header values. What is sent
 *   can therefore differ in spelling from the request as it is reported, never in host.
 *
 * Nothing the client or its message classes throw leaves the bridge: an exception or an error of theirs can hold
 * the request with its revealed headers, in its text or in its trace. An exception becomes a failure of the
 * transport without text. An error, which is a fault in the code and not a failure of the connection, becomes a
 * new error with a fixed text and the class name of what was thrown, so that it is seen and never retried.
 * The bridge calls only PSR interfaces and implements none.
 */
final readonly class Psr18Transport implements Transport
{
    private const int CHUNK_BYTES = 8192;

    /** A read that gives nothing while the body has not ended, this often in a row, counts as a lost connection. */
    private const int EMPTY_READS = 3;

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        [$head, $content] = $this->exchange($request);

        $body = '';
        foreach ($this->read($content) as $chunk) {
            $body .= $chunk;
        }

        return new HttpResponse($head->status, $head->headers, $body);
    }

    public function stream(HttpRequest $request): HttpStreamResponse
    {
        [$head, $content] = $this->exchange($request);

        return new HttpStreamResponse($head->status, $head->headers, $this->read($content));
    }

    /**
     * Sends the request and gives the status and the headers of the response, checked, with its unread body.
     *
     * @return array{HttpResponse, StreamInterface}
     *
     * @throws TransportException
     */
    private function exchange(HttpRequest $request): array
    {
        $psrRequest = $this->build($request);
        if ($psrRequest === null) {
            // The host's own message classes refused the request: it can never be sent as it is.
            throw new TransportException(TransportFailure::Blocked);
        }

        $response = $this->ask($psrRequest);
        if ($response === null) {
            throw new TransportException(TransportFailure::ConnectionFailed);
        }

        $content = $this->bodyOf($response);
        if ($content === null) {
            throw new TransportException(TransportFailure::ConnectionFailed);
        }

        $status = $this->statusOf($response);
        if ($status !== null && $status >= 300 && $status <= 399) {
            $this->close($content);

            throw new TransportException(TransportFailure::Redirected);
        }

        $head = $status === null ? null : $this->headOf($status, $response);
        if ($head === null) {
            // A response that is not HTTP as the library knows it: a status outside 100 to 599, a header that
            // is no text.
            $this->close($content);

            throw new TransportException(TransportFailure::ConnectionFailed);
        }

        return [$head, $content];
    }

    /**
     * Null when the factories or the message refuse the method, the URL, a header or the body.
     */
    private function build(HttpRequest $request): ?RequestInterface
    {
        $fault = null;

        try {
            $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
            foreach ($request->allHeaders() as $name => $value) {
                $psrRequest = $psrRequest->withHeader($name, $value);
            }
            if ($request->body !== '') {
                $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
            }

            return $psrRequest;
        } catch (Throwable $thrown) {
            // Dropped: its text and its trace can hold a header value.
            $fault = $thrown instanceof Error ? $thrown::class : null;
        }

        if ($fault !== null) {
            throw self::fault($fault);
        }

        return null;
    }

    /**
     * Null when the client could not give a response. The request is a sensitive parameter: it holds the
     * revealed headers.
     */
    private function ask(#[SensitiveParameter] RequestInterface $psrRequest): ?ResponseInterface
    {
        $fault = null;

        try {
            return $this->client->sendRequest($psrRequest);
        } catch (Throwable $thrown) {
            // Dropped: its trace holds the call of the client, with the request and its headers. An exception
            // PSR-18 names stays a failure of the connection, also when it is built on an error.
            $fault = $thrown instanceof Error && ! $thrown instanceof ClientExceptionInterface ? $thrown::class : null;
        }

        if ($fault !== null) {
            throw self::fault($fault);
        }

        return null;
    }

    private function bodyOf(ResponseInterface $response): ?StreamInterface
    {
        $fault = null;

        try {
            return $response->getBody();
        } catch (Throwable $thrown) {
            $fault = $thrown instanceof Error ? $thrown::class : null;
        }

        if ($fault !== null) {
            throw self::fault($fault);
        }

        return null;
    }

    private function statusOf(ResponseInterface $response): ?int
    {
        $fault = null;

        try {
            return $response->getStatusCode();
        } catch (Throwable $thrown) {
            $fault = $thrown instanceof Error ? $thrown::class : null;
        }

        if ($fault !== null) {
            throw self::fault($fault);
        }

        return null;
    }

    /**
     * The status and the headers under the rules of the library's own response; null when they break them.
     */
    private function headOf(int $status, ResponseInterface $response): ?HttpResponse
    {
        $fault = null;

        try {
            return new HttpResponse($status, $response->getHeaders());
        } catch (Throwable $thrown) {
            $fault = $thrown instanceof Error ? $thrown::class : null;
        }

        if ($fault !== null) {
            throw self::fault($fault);
        }

        return null;
    }

    /**
     * The body in pieces of at most 8192 bytes, until its end. The body is closed when the reading ends, breaks
     * or is given up.
     *
     * @return Generator<int, string, mixed, void>
     *
     * @throws TransportException
     */
    private function read(StreamInterface $body): Generator
    {
        try {
            $emptyReads = 0;
            $broken = ! $this->rewind($body);

            while (! $broken) {
                $chunk = $this->readChunk($body);
                if ($chunk === null) {
                    $broken = true;
                } elseif ($chunk !== '') {
                    $emptyReads = 0;
                    yield $chunk;
                } else {
                    $ended = $this->ended($body);
                    if ($ended === true) {
                        return;
                    }
                    $broken = $ended === null || ++$emptyReads >= self::EMPTY_READS;
                }
            }
        } finally {
            $this->close($body);
        }

        throw new TransportException(TransportFailure::ConnectionFailed);
    }

    /**
     * A client that read the whole body may leave the body at its end: start at the beginning when that is
     * possible. False when the body cannot be used.
     */
    private function rewind(StreamInterface $body): bool
    {
        $fault = null;

        try {
            if ($body->isSeekable() && $body->tell() !== 0) {
                $body->rewind();
            }

            return true;
        } catch (Throwable $thrown) {
            $fault = $thrown instanceof Error ? $thrown::class : null;
        }

        if ($fault !== null) {
            throw self::fault($fault);
        }

        return false;
    }

    /**
     * Null when the body broke; an empty string when nothing came, which is also what the end looks like.
     */
    private function readChunk(StreamInterface $body): ?string
    {
        $fault = null;

        try {
            return $body->eof() ? '' : $body->read(self::CHUNK_BYTES);
        } catch (Throwable $thrown) {
            $fault = $thrown instanceof Error ? $thrown::class : null;
        }

        if ($fault !== null) {
            throw self::fault($fault);
        }

        return null;
    }

    /**
     * True at the end of the body, false before it, null when the body cannot say: a broken body, not a
     * complete one.
     */
    private function ended(StreamInterface $body): ?bool
    {
        $fault = null;

        try {
            return $body->eof();
        } catch (Throwable $thrown) {
            $fault = $thrown instanceof Error ? $thrown::class : null;
        }

        if ($fault !== null) {
            throw self::fault($fault);
        }

        return null;
    }

    private function close(StreamInterface $body): void
    {
        try {
            $body->close();
        } catch (Throwable) {
            // Nothing more to read either way, and closing must not hide the result of the reading.
        }
    }

    /**
     * The error that stands for a fault in the code of the client or of its message classes. It is made here,
     * outside the catch that dropped the original, and gets only a class name: nothing of the original, its
     * text or its trace, is in it.
     *
     * @param class-string $class
     */
    private static function fault(string $class): Error
    {
        // An anonymous class carries the path of its file after a null byte.
        $name = strstr($class, "\0", true);

        return new Error(
            'The HTTP client or its message classes failed with an error of class ' . ($name === false ? $class : $name) . '.',
        );
    }
}
