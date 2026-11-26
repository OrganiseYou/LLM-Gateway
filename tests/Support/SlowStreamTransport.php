<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use Generator;
use LogicException;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\HttpStreamResponse;
use OrganiseYou\LlmGateway\Transport\Transport;

/**
 * A transport whose one stream takes time between its chunks, and that tells whether its chunks were let go.
 */
final class SlowStreamTransport implements Transport
{
    public int $chunksGiven = 0;

    public bool $released = false;

    /** @var list<HttpRequest> */
    public array $requests = [];

    /**
     * @param list<string> $chunks
     * @param float $secondsBeforeEachChunk how long the clock moves before every chunk arrives
     */
    public function __construct(
        private readonly FakeClock $clock,
        private readonly array $chunks,
        private readonly float $secondsBeforeEachChunk,
    ) {}

    public function send(HttpRequest $request): HttpResponse
    {
        throw new LogicException('This transport only streams.');
    }

    public function stream(HttpRequest $request): HttpStreamResponse
    {
        $this->requests[] = $request;

        return new HttpStreamResponse(200, ['Content-Type' => 'text/event-stream'], $this->give());
    }

    /**
     * @return Generator<int, string, mixed, void>
     */
    private function give(): Generator
    {
        try {
            foreach ($this->chunks as $chunk) {
                $this->clock->advance($this->secondsBeforeEachChunk);
                $this->chunksGiven++;

                yield $chunk;
            }
        } finally {
            $this->released = true;
        }
    }
}
