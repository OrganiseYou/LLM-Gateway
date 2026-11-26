<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * A response body for tests that gives one scripted piece per read: a string, or null for a read that fails. It
 * counts its reads and remembers that it was closed. A fault scripted for "read", "eof", "tell", "rewind" or
 * "close" is thrown by that method instead.
 */
final class ScriptedBody implements StreamInterface
{
    public int $reads = 0;

    public bool $closed = false;

    /** @var list<string> the methods that were called, in order */
    public array $calls = [];

    /**
     * @param list<string|null> $pieces
     * @param bool $endless true: after the last piece the body gives nothing and never ends
     * @param array<string, Throwable> $faults what a method throws, by its name
     * @param int|null $position not null: the body can be rewound and says it stands at this position
     */
    public function __construct(
        private array $pieces,
        private readonly bool $endless = false,
        private readonly array $faults = [],
        private readonly ?int $position = null,
    ) {}

    private function called(string $method): void
    {
        $this->calls[] = $method;

        if (isset($this->faults[$method])) {
            throw $this->faults[$method];
        }
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
        $this->closed = true;
        $this->called('close');
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        $this->called('tell');

        return $this->position ?? throw new RuntimeException('not seekable');
    }

    public function eof(): bool
    {
        $this->called('eof');

        return $this->pieces === [] && ! $this->endless;
    }

    public function isSeekable(): bool
    {
        return $this->position !== null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('not seekable');
    }

    public function rewind(): void
    {
        $this->called('rewind');

        if ($this->position === null) {
            throw new RuntimeException('not seekable');
        }
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new RuntimeException('not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $this->reads++;
        $this->called('read');

        if ($this->pieces === []) {
            return '';
        }

        $piece = array_shift($this->pieces);
        if ($piece === null) {
            throw new RuntimeException('the connection broke: ' . LEAK_MARKER);
        }

        return $piece;
    }

    public function getContents(): string
    {
        throw new RuntimeException('read the body in pieces');
    }

    public function getMetadata(?string $key = null)
    {
        return null;
    }
}
