<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\SseEvent;
use OrganiseYou\LlmGateway\Adapter\Shared\SseReader;

/**
 * The events the reader gives for these chunks, each as [event, data, id].
 *
 * @return list<array{?string, string, ?string}>
 */
function sseEvents(string ...$chunks): array
{
    $events = [];
    foreach ((new SseReader())->read(array_values($chunks)) as $event) {
        $events[] = [$event->event, $event->data, $event->id];
    }

    return $events;
}

describe('SseEvent', function (): void {
    it('is a final readonly value with an event type, data and an id', function (): void {
        $event = new SseEvent('say', '{"piece":"Hel"}', '7');
        $reflection = new ReflectionClass(SseEvent::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue()
            ->and($event->event)->toBe('say')
            ->and($event->data)->toBe('{"piece":"Hel"}')
            ->and($event->id)->toBe('7')
            ->and((new SseEvent(null, 'x'))->id)->toBeNull();
    });
});

describe('SseReader', function (): void {
    it('is final and keeps nothing between two calls', function (): void {
        $reflection = new ReflectionClass(SseReader::class);
        $reader = new SseReader();

        // The first stream is left half-way: an open event, an event type and an id.
        $first = [];
        foreach ($reader->read(["id: 9\nevent: say\ndata: one\n\nevent: other\ndata: half"]) as $event) {
            $first[] = $event->data;

            break;
        }
        $second = [];
        foreach ($reader->read(["data: two\n\n"]) as $event) {
            $second[] = [$event->event, $event->data, $event->id];
        }

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->getProperties())->toBe([])
            ->and($first)->toBe(['one'])
            ->and($second)->toBe([[null, 'two', null]]);
    });

    it('reads nothing before the first event is asked for, and no further than the event that is asked for', function (): void {
        $given = 0;
        $chunks = (function () use (&$given): Generator {
            foreach (["data: one\n\n", "data: two\n\n", "data: three\n\n"] as $chunk) {
                $given++;

                yield $chunk;
            }
        })();

        $events = (new SseReader())->read($chunks);
        expect($given)->toBe(0);

        expect($events->current()->data)->toBe('one')
            ->and($given)->toBe(1);
    });

    it('gives an event with its type, its data and its id', function (): void {
        expect(sseEvents("event: say\nid: 7\ndata: hello\n\n"))->toBe([['say', 'hello', '7']]);
    });

    it('ends a line at LF, at CR and at CR LF', function (string $lineEnd): void {
        expect(sseEvents('event: say' . $lineEnd . 'data: one' . $lineEnd . $lineEnd . 'data: two' . $lineEnd . $lineEnd))
            ->toBe([['say', 'one', null], [null, 'two', null]]);
    })->with([
        'LF' => ["\n"],
        'CR' => ["\r"],
        'CR LF' => ["\r\n"],
    ]);

    it('reads line ends of all three kinds in one stream', function (): void {
        expect(sseEvents("data: one\r\n\rdata: two\n\r\ndata: three\r\r"))
            ->toBe([[null, 'one', null], [null, 'two', null], [null, 'three', null]]);
    });

    it('gives the same events wherever the chunks are cut: inside a line, between CR and LF, inside a character', function (): void {
        $stream = "\xEF\xBB\xBF: a comment\r\nevent: say\r\ndata: h\u{00E9}llo \u{1F600}\r\ndata: second line\r\nid: 4\r\n\r\ndata: last\r\n\r\n";
        $whole = sseEvents($stream);

        expect($whole)->toBe([['say', "h\u{00E9}llo \u{1F600}\nsecond line", '4'], [null, 'last', '4']]);

        // Every cut in two, and every byte as its own chunk.
        for ($at = 1; $at < strlen($stream); $at++) {
            expect(sseEvents(substr($stream, 0, $at), substr($stream, $at)))->toBe($whole);
        }
        expect(sseEvents(...str_split($stream)))->toBe($whole);
    });

    it('gives an event as soon as its blank line is read, also when that line ends with a CR at the end of a chunk', function (): void {
        $events = (new SseReader())->read((function (): Generator {
            yield "data: one\r\r";

            throw new LogicException('The reader asked for a chunk it did not need yet.');
        })());

        expect($events->current()->data)->toBe('one');
    });

    it('takes an LF at the start of a chunk as the rest of the line end, when the chunk before ended with CR', function (): void {
        expect(sseEvents("data: one\r", "\ndata: two\r", "\n\r", "\n"))->toBe([[null, "one\ntwo", null]]);
    });

    it('forgets the CR at the end of a chunk once the next chunk has begun: a later LF at the start of a chunk is a line end of its own', function (): void {
        // The second chunk does not start with LF. The third does, and that LF is the blank line that closes the event.
        expect(sseEvents("data: one\r", "x: y\n", "\ndata: two\n\n"))->toBe([[null, 'one', null], [null, 'two', null]]);
    });

    it('searches a line that arrives in many chunks for its end once, not again from its start for every chunk', function (): void {
        $line = 'data: ' . str_repeat('a', 64 * 1024);
        $chunks = [...str_split($line, 64), "\n", "data: tail\n", "\n"];
        $bytes = strlen(implode('', $chunks));

        $scanned = 0;
        $reader = new SseReader();
        $events = [];
        foreach ((new ReflectionMethod($reader, 'events'))->invokeArgs($reader, [$chunks, &$scanned]) as $event) {
            $events[] = strlen($event->data);
        }

        // Searching from the start of the line for each of its 1025 chunks would look at more than 33 million bytes.
        expect($events)->toBe([64 * 1024 + 5])
            ->and($scanned)->toBeGreaterThan(64 * 1024)
            ->and($scanned)->toBeLessThanOrEqual($bytes);
    });

    it('ignores a comment line', function (): void {
        expect(sseEvents(": keep-alive\n\n:\ndata: one\n: between\ndata: two\n\n"))->toBe([[null, "one\ntwo", null]]);
    });

    it('joins several data lines with a line feed', function (): void {
        expect(sseEvents("data: YHOO\ndata: +2\ndata: 10\n\n"))->toBe([[null, "YHOO\n+2\n10", null]]);
    });

    it('drops one space after the colon and no more', function (): void {
        expect(sseEvents("data:test\n\ndata: test\n\ndata:  test\n\n"))
            ->toBe([[null, 'test', null], [null, 'test', null], [null, ' test', null]]);
    });

    it('takes a line without a colon as a field with an empty value', function (): void {
        expect(sseEvents("data\n\ndata\ndata\n\n"))->toBe([[null, '', null], [null, "\n", null]]);
    });

    it('gives no event for a blank line without data, and forgets the event type of it', function (): void {
        expect(sseEvents("event: say\n\n\n\ndata: one\n\n"))->toBe([[null, 'one', null]]);
    });

    it('keeps the last id until the stream changes it, and an id without a value clears it', function (): void {
        expect(sseEvents("id: 1\ndata: one\n\ndata: two\n\nid\ndata: three\n\n"))
            ->toBe([[null, 'one', '1'], [null, 'two', '1'], [null, 'three', null]]);
    });

    it('ignores an id that holds a null character', function (): void {
        expect(sseEvents("id: 1\ndata: one\n\nid: 2\0x\ndata: two\n\n"))->toBe([[null, 'one', '1'], [null, 'two', '1']]);
    });

    it('ignores the retry field and a field it does not know, and compares field names as written', function (): void {
        expect(sseEvents("retry: 3000\nfoo: bar\nData: no\nEVENT: no\ndata: one\n\n"))->toBe([[null, 'one', null]]);
    });

    it('drops one byte order mark at the start, also when it arrives byte by byte, and only there', function (): void {
        expect(sseEvents("\xEF\xBB\xBFdata: one\n\n"))->toBe([[null, 'one', null]])
            ->and(sseEvents("\xEF", "\xBB", "\xBF", "data: one\n\n"))->toBe([[null, 'one', null]])
            ->and(sseEvents("\xEF\xBB\xBF\xEF\xBB\xBFdata: one\n\n"))->toBe([])
            ->and(sseEvents("data: one\n\n\xEF\xBB\xBFdata: two\n\ndata: three\n\n"))->toBe([[null, 'one', null], [null, 'three', null]]);
    });

    it('reads a stream that starts with bytes that only look like the start of a byte order mark', function (): void {
        expect(sseEvents("\xEF", "\xBB", "data: one\n\n"))->toBe([])
            ->and(sseEvents('', 'd', "ata: one\n\n"))->toBe([[null, 'one', null]]);
    });

    it('gives the last event when the body ends without a blank line after it', function (string ...$chunks): void {
        expect(sseEvents(...$chunks))->toBe([[null, 'one', null], ['end', 'last', null]]);
    })->with([
        'after the line end of the last line' => ["data: one\n\nevent: end\ndata: last\n"],
        'inside the last line' => ["data: one\n\nevent: end\ndata: last"],
        'after a CR' => ["data: one\n\nevent: end\ndata: last\r"],
        'in a chunk of its own' => ["data: one\n\nevent: end\n", 'data: last'],
    ]);

    it('gives nothing for an empty body, for comments alone and for fields without data', function (string ...$chunks): void {
        expect(sseEvents(...$chunks))->toBe([]);
    })->with([
        'no chunk' => [],
        'empty chunks' => ['', ''],
        'a comment' => [": nothing\n"],
        'an event type alone' => ["event: say\n\n"],
        'an event type alone, at the end' => ['event: say'],
    ]);

    it('gives valid UTF-8 whatever bytes arrive', function (): void {
        $events = sseEvents("event: s\xFFy\nid: \xC3\ndata: a\xE2\x82z \xF0\x9F\x98\x80 \xC0\xAF\n\n");

        expect($events)->toHaveCount(1)
            ->and($events[0][0])->toBe("s\u{FFFD}y")
            ->and($events[0][1])->toBe("a\u{FFFD}\u{FFFD}z \u{1F600} \u{FFFD}\u{FFFD}")
            ->and($events[0][2])->toBe("\u{FFFD}")
            ->and(preg_match('//u', $events[0][1]))->toBe(1);
    });

    it('lets a failure of the chunks pass, after the events that were whole', function (): void {
        $events = (new SseReader())->read((function (): Generator {
            yield "data: one\n\ndata: ha";

            throw new RuntimeException('broken');
        })());

        $seen = [];
        $failure = null;
        try {
            foreach ($events as $event) {
                $seen[] = $event->data;
            }
        } catch (RuntimeException $thrown) {
            $failure = $thrown->getMessage();
        }

        expect($seen)->toBe(['one'])
            ->and($failure)->toBe('broken');
    });
});
