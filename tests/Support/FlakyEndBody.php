<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A response body for tests that cannot say whether it has ended exactly once: at the given call the
 * question fails, before and after it is answered. Everything else is the body it wraps.
 */
final class FlakyEndBody implements StreamInterface
{
    private int $eofCalls = 0;

    public function __construct(private readonly StreamInterface $inner, private readonly int $failAtEofCall) {}

    public function __toString(): string
    {
        return $this->inner->__toString();
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        if (++$this->eofCalls === $this->failAtEofCall) {
            throw new RuntimeException('the end cannot be asked');
        }

        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }
}
