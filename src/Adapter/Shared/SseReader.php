<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared;

use Generator;
use SensitiveParameter;

/**
 * Cuts raw chunks of a server-sent-events stream into events. Nothing is kept between two calls of read().
 *
 * It follows the rules for interpreting an event stream of the WHATWG HTML specification (section 9.2.6, read
 * 2026-11-26): a line ends with CR LF, LF or CR; one byte order mark at the start is dropped; a line that starts
 * with a colon is a comment; one space after the colon of a field is dropped; every "data" line adds its value and
 * a line feed, and the last line feed is dropped when the event is given; an event without a "data" line is not
 * given; an "id" with a null character is ignored and an id stays until the stream changes it; "retry" and unknown
 * fields are ignored; field names are compared as they are written.
 *
 * Two things differ from that text, on purpose. A browser drops an event that is not closed by a blank line when
 * the stream ends; this reader gives it, because here a body that ends is complete (a broken connection is an
 * exception of the transport, not an end) and a server that leaves out the last blank line would otherwise lose
 * its last event. And bytes that are not valid UTF-8 become U+FFFD one byte at a time, which can give more
 * replacement characters for one broken sequence than a browser shows.
 */
final readonly class SseReader
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @param iterable<string> $chunks raw bytes, cut anywhere: inside a line, inside a character, between CR and LF
     * @return Generator<int, SseEvent, mixed, void>
     */
    public function read(#[SensitiveParameter] iterable $chunks): Generator
    {
        $scannedBytes = 0;

        return $this->events($chunks, $scannedBytes);
    }

    /**
     * @param iterable<string> $chunks
     * @param int $scannedBytes counts every byte that is searched for a line end: a line that arrives in many
     *                          chunks is searched once, not again from its start for every chunk
     * @return Generator<int, SseEvent, mixed, void>
     */
    private function events(#[SensitiveParameter] iterable $chunks, int &$scannedBytes): Generator
    {
        $buffer = '';
        // How many bytes at the start of the buffer are known to hold no line end.
        $known = 0;
        $atStart = true;
        $skipLineFeed = false;
        $data = '';
        $type = '';
        $lastId = '';

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            if ($atStart) {
                if (strlen($buffer) < 3 && str_starts_with(self::BOM, $buffer)) {
                    continue; // these bytes can still become a byte order mark
                }
                if (str_starts_with($buffer, self::BOM)) {
                    $buffer = substr($buffer, 3);
                }
                $atStart = false;
            }
            if ($skipLineFeed && $buffer !== '') {
                // The chunk before ended with CR: its line is given already, and an LF now belongs to that line end.
                if ($buffer[0] === "\n") {
                    $buffer = substr($buffer, 1);
                }
                $skipLineFeed = false;
            }

            $offset = 0;
            $from = $known;
            $length = strlen($buffer);
            for (;;) {
                $span = strcspn($buffer, "\r\n", $from);
                $scannedBytes += $span;
                $end = $from + $span;
                if ($end >= $length) {
                    break; // the line has not ended yet
                }

                $line = substr($buffer, $offset, $end - $offset);
                $offset = $end + 1;
                if ($buffer[$end] === "\r") {
                    if ($offset >= $length) {
                        $skipLineFeed = true;
                    } elseif ($buffer[$offset] === "\n") {
                        $offset++;
                    }
                }
                $from = $offset;

                $event = self::line($line, $data, $type, $lastId);
                if ($event !== null) {
                    yield $event;
                }
            }
            if ($offset > 0) {
                $buffer = substr($buffer, $offset);
            }
            $known = strlen($buffer);
        }

        // The body ended. A last line without a line end is still a line, and what was gathered is still an event.
        if ($buffer !== '') {
            $event = self::line($buffer, $data, $type, $lastId);
            if ($event !== null) {
                yield $event;
            }
        }
        $event = self::line('', $data, $type, $lastId);
        if ($event !== null) {
            yield $event;
        }
    }

    /**
     * Takes one line into the event that is being gathered. A blank line closes the event and gives it, when it has
     * data.
     */
    private static function line(
        #[SensitiveParameter]
        string $line,
        #[SensitiveParameter]
        string &$data,
        #[SensitiveParameter]
        string &$type,
        #[SensitiveParameter]
        string &$lastId,
    ): ?SseEvent {
        if ($line === '') {
            $gathered = $data;
            $gatheredType = $type;
            $data = '';
            $type = '';
            if ($gathered === '') {
                return null;
            }

            return new SseEvent(
                $gatheredType === '' ? null : self::utf8($gatheredType),
                self::utf8(substr($gathered, 0, -1)),
                $lastId === '' ? null : self::utf8($lastId),
            );
        }
        if ($line[0] === ':') {
            return null;
        }

        $colon = strpos($line, ':');
        $field = $colon === false ? $line : substr($line, 0, $colon);
        $value = $colon === false ? '' : substr($line, $colon + 1);
        if ($value !== '' && $value[0] === ' ') {
            $value = substr($value, 1);
        }

        if ($field === 'data') {
            $data .= $value . "\n";
        } elseif ($field === 'event') {
            $type = $value;
        } elseif ($field === 'id' && ! str_contains($value, "\0")) {
            $lastId = $value;
        }

        return null;
    }

    /**
     * The text as valid UTF-8: every byte that is not part of a valid character becomes U+FFFD.
     */
    private static function utf8(#[SensitiveParameter] string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/[\x00-\x7F]+|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}'
            . '|\xF4[\x80-\x8F][\x80-\xBF]{2}|([\x80-\xFF])/',
            fn(array $match): string => isset($match[1]) ? "\u{FFFD}" : $match[0],
            $text,
        );
    }
}
