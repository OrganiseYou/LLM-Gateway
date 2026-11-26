<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * A response for tests that remembers every read of its headers: "*" for all of them, else the name asked. It can
 * also answer with a status or with headers no PSR-7 class would accept, or throw from a method, to stand for a
 * client that breaks the rules.
 */
final class HeaderSpyResponse implements ResponseInterface
{
    /** @var list<string> */
    public array $headersRead = [];

    /**
     * @param int|null $status not null: the status to give, whatever the inner response says
     * @param array<mixed>|null $headers not null: what getHeaders() gives
     * @param array<string, Throwable> $faults what "getStatusCode", "getHeaders" or "getBody" throws
     */
    public function __construct(
        private readonly ResponseInterface $inner,
        private readonly ?int $status = null,
        private readonly ?array $headers = null,
        private readonly array $faults = [],
    ) {}

    public function getProtocolVersion(): string
    {
        return $this->inner->getProtocolVersion();
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        return new self($this->inner->withProtocolVersion($version));
    }

    public function getHeaders(): array
    {
        $this->headersRead[] = '*';

        if (isset($this->faults['getHeaders'])) {
            throw $this->faults['getHeaders'];
        }

        /** @var array<array<string>> $headers the wrong shapes are the point of the override */
        $headers = $this->headers ?? $this->inner->getHeaders();

        return $headers;
    }

    public function hasHeader(string $name): bool
    {
        $this->headersRead[] = $name;

        return $this->inner->hasHeader($name);
    }

    public function getHeader(string $name): array
    {
        $this->headersRead[] = $name;

        return $this->inner->getHeader($name);
    }

    public function getHeaderLine(string $name): string
    {
        $this->headersRead[] = $name;

        return $this->inner->getHeaderLine($name);
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        return new self($this->inner->withHeader($name, $value));
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        return new self($this->inner->withAddedHeader($name, $value));
    }

    public function withoutHeader(string $name): MessageInterface
    {
        return new self($this->inner->withoutHeader($name));
    }

    public function getBody(): StreamInterface
    {
        if (isset($this->faults['getBody'])) {
            throw $this->faults['getBody'];
        }

        return $this->inner->getBody();
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        return new self($this->inner->withBody($body));
    }

    public function getStatusCode(): int
    {
        if (isset($this->faults['getStatusCode'])) {
            throw $this->faults['getStatusCode'];
        }

        return $this->status ?? $this->inner->getStatusCode();
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        return new self($this->inner->withStatus($code, $reasonPhrase));
    }

    public function getReasonPhrase(): string
    {
        return $this->inner->getReasonPhrase();
    }
}
