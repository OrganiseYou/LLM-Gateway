<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use Generator;
use InvalidArgumentException;
use LogicException;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\HttpStreamResponse;
use OrganiseYou\LlmGateway\Transport\Transport;
use OrganiseYou\LlmGateway\Transport\TransportException;
use OrganiseYou\LlmGateway\Transport\TransportFailure;
use SensitiveParameter;

/**
 * A transport for tests: it sends nothing and gives the scripted answers in the order they were scripted.
 *
 * One scripted answer serves one call of send() or stream(), whichever comes. A scripted response read through
 * stream() gives its body as one chunk; a scripted stream read through send() gives its chunks as one body.
 * A call with nothing scripted throws a LogicException. Every request is recorded.
 */
final class FakeTransport implements Transport
{
    /**
     * @var list<array{status: int, headers: array<string, string>, chunks: list<string>, failure: ?TransportFailure, breakAfter: ?int, delay: float}>
     */
    private array $script = [];

    private float $pendingDelay = 0.0;

    /** @var list<HttpRequest> */
    private array $requests = [];

    /**
     * @param FakeClock|null $clock the clock that scripted delays move forward; needed only for willDelay()
     */
    public function __construct(private readonly ?FakeClock $clock = null) {}

    public function willReturn(HttpResponse $response): static
    {
        return $this->add($response->status, $response->headers, $response->body === '' ? [] : [$response->body], null);
    }

    /**
     * @param array<array-key, string|array<array-key, string>> $headers names in any case; a value or its values
     */
    public function willStream(
        int $status,
        #[SensitiveParameter]
        array $headers,
        #[SensitiveParameter]
        string ...$chunks,
    ): static {
        // The same rules as a real response: a wrong status or header fails here, where it was scripted.
        $checked = new HttpStreamResponse($status, $headers, []);

        return $this->add($checked->status, $checked->headers, array_values($chunks), null);
    }

    /**
     * Makes the stream scripted just before this call break with the failure after it gave this many chunks.
     */
    public function willBreakStreamAfter(int $chunks, TransportFailure $failure): static
    {
        $last = array_key_last($this->script);
        if ($last === null || $this->script[$last]['failure'] !== null || $this->script[$last]['breakAfter'] !== null) {
            throw new LogicException('Script a stream with willStream() first: there is no stream to break.');
        }
        if ($chunks < 0 || $chunks > count($this->script[$last]['chunks'])) {
            throw new InvalidArgumentException('The stream cannot break after more chunks than it has, or fewer than 0.');
        }

        $this->script[$last]['failure'] = $failure;
        $this->script[$last]['breakAfter'] = $chunks;

        return $this;
    }

    /**
     * The next call fails without a response.
     */
    public function willFail(TransportFailure $failure): static
    {
        return $this->add(0, [], [], $failure);
    }

    /**
     * Moves the clock forward by this long before the next scripted answer is given. Nothing waits.
     */
    public function willDelay(float $seconds): static
    {
        if ($this->clock === null) {
            throw new LogicException('Give the fake transport a fake clock to script a delay.');
        }
        if (! is_finite($seconds) || $seconds < 0.0) {
            throw new InvalidArgumentException('The delay must be a finite number of seconds of 0 or more.');
        }

        $this->pendingDelay += $seconds;

        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $answer = $this->next($request);

        if ($answer['failure'] !== null) {
            throw new TransportException($answer['failure']);
        }

        return new HttpResponse($answer['status'], $answer['headers'], implode('', $answer['chunks']));
    }

    public function stream(HttpRequest $request): HttpStreamResponse
    {
        $answer = $this->next($request);

        if ($answer['failure'] !== null && $answer['breakAfter'] === null) {
            throw new TransportException($answer['failure']);
        }

        return new HttpStreamResponse(
            $answer['status'],
            $answer['headers'],
            self::chunks($answer['chunks'], $answer['breakAfter'], $answer['failure']),
        );
    }

    /**
     * Every request that was sent, in order.
     *
     * @return list<HttpRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * How many scripted answers are still unused.
     */
    public function remaining(): int
    {
        return count($this->script);
    }

    /**
     * @param array<string, string> $headers
     * @param list<string> $chunks
     */
    private function add(
        int $status,
        #[SensitiveParameter]
        array $headers,
        #[SensitiveParameter]
        array $chunks,
        ?TransportFailure $failure,
    ): static {
        $this->script[] = [
            'status' => $status,
            'headers' => $headers,
            'chunks' => $chunks,
            'failure' => $failure,
            'breakAfter' => null,
            'delay' => $this->pendingDelay,
        ];
        $this->pendingDelay = 0.0;

        return $this;
    }

    /**
     * @return array{status: int, headers: array<string, string>, chunks: list<string>, failure: ?TransportFailure, breakAfter: ?int, delay: float}
     */
    private function next(HttpRequest $request): array
    {
        $this->requests[] = $request;

        $answer = array_shift($this->script);
        if ($answer === null) {
            throw new LogicException(
                'The fake transport got request ' . count($this->requests) . ' and has no scripted answer left.',
            );
        }

        if ($answer['delay'] > 0.0) {
            $this->clock?->advance($answer['delay']);
        }

        return $answer;
    }

    /**
     * @param list<string> $chunks
     * @return Generator<int, string, mixed, void>
     */
    private static function chunks(
        #[SensitiveParameter]
        array $chunks,
        ?int $breakAfter,
        ?TransportFailure $failure,
    ): Generator {
        foreach ($chunks as $index => $chunk) {
            if ($failure !== null && $index === $breakAfter) {
                throw new TransportException($failure);
            }
            yield $chunk;
        }

        if ($failure !== null) {
            throw new TransportException($failure);
        }
    }
}
