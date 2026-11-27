<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

use OrganiseYou\LlmGateway\Adapter\Shared\RawJson;
use SensitiveParameter;

/**
 * The content of an answer that is a list of entries, for a server that wants that list back with the answer
 * (`reasoning_carrier: content_entries`): a `thinking` entry before the `text` entry, a citation, or a kind of entry
 * no page names. The list is kept as JSON text, never as decoded values, so what goes back is what came: an empty
 * object stays an empty object and a number keeps its spelling (RawJson::plain() says what one spelling means).
 *
 * A whole answer holds the list as one value. A stream delivers it in pieces, a string or a list per chunk, and the
 * pieces are joined here into the list a whole answer would have held:
 *
 * - a string is a piece of text: an entry `{"type":"text","text":…}`;
 * - a piece joins the entry before it when both are `text` entries with a string as their `text` (the texts are
 *   joined), or both are `thinking` entries with a list as their `thinking` (the two lists are joined by the same
 *   rule, for their text entries); of another member both carry, the later value counts;
 * - every other entry stands on its own, as it arrived.
 *
 * The provider file documents the whole list and says in words how a stream delivers it; it shows no chunk. The
 * joining is built to those words and is not confirmed (decision G100).
 *
 * A list is measured as text before anything decodes it or cuts it into its entries, on the way in and on the way
 * back: at most 1 MiB, and at most 10,000 objects and lists, the limits the adapter base has for an opaque part.
 * The base cannot count for this list: sealed as one string, it is one string to the base. A list over a limit is
 * not kept, and a stream stops keeping its pieces as soon as they pass one; the text of the answer is read all
 * the same.
 *
 * @phpstan-type Inner array{kind: string, text: string, more: array<array-key, string>, raw: string}
 * @phpstan-type Entry array{kind: string, text: string, more: array<array-key, string>, raw: string, inner: list<Inner>}
 * @phpstan-type Pieces array{entries: list<Entry>, bytes: int, containers: int}
 */
final readonly class ContentEntries
{
    /** The most bytes of a list that is kept: the limit of an opaque part. */
    public const int LIMIT_BYTES = 1_048_576;

    /** The most objects and lists in a list that is kept: the limit of an opaque part. */
    public const int LIMIT_CONTAINERS = 10_000;

    /** What a text entry is counted as beside its text: about the bytes of `{"type":"text","text":""},`. */
    private const int ENTRY_BYTES = 32;

    private const string TEXT = 'text';

    private const string THINKING = 'thinking';

    /** A text entry without a `type`: it counts as text, and is kept as it arrived. */
    private const string UNTYPED_TEXT = 'untyped_text';

    private const string OTHER = 'other';

    /**
     * Whether a decoded `content` is a list that holds more than text: an entry that is no text entry. Only such
     * a list is kept; a string, and a list of text entries, is the text of the answer and nothing else.
     */
    public static function holdsMoreThanText(#[SensitiveParameter] mixed $content): bool
    {
        if (! is_array($content) || ! array_is_list($content)) {
            return false;
        }
        foreach ($content as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? 'text') !== 'text' || ! is_string($entry['text'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The list as it is kept and as it is sent: in one spelling, and only when it is a list of objects that is not
     * empty and is within the limits. Null for anything else: nothing but such a list ever stands as the content
     * of an assistant message.
     */
    public static function kept(#[SensitiveParameter] string $json): ?string
    {
        // Measured as text first: what follows makes a string per entry.
        if (! self::isWithinTheLimits($json)) {
            return null;
        }
        // Checked, not decoded: JSON, and a list by the mark it starts with.
        if (! json_validate($json) || ($json[strspn($json, " \t\n\r")] ?? '') !== '[') {
            return null;
        }

        $list = RawJson::plain($json);
        $entries = $list === null ? null : RawJson::items($list);
        if ($list === null || $entries === null || $entries === []) {
            return null;
        }
        foreach ($entries as $entry) {
            if (! str_starts_with($entry, '{')) {
                return null;
            }
        }

        return $list;
    }

    /**
     * Whether a list, as text, is within the limits of what is kept: its bytes, and its objects and lists.
     */
    public static function isWithinTheLimits(#[SensitiveParameter] string $json): bool
    {
        return strlen($json) <= self::LIMIT_BYTES && RawJson::containers($json, self::LIMIT_CONTAINERS) <= self::LIMIT_CONTAINERS;
    }

    /**
     * The pieces of a stream before the first one: no entries.
     *
     * @return Pieces
     */
    public static function none(): array
    {
        return ['entries' => [], 'bytes' => 0, 'containers' => 0];
    }

    /**
     * Adds a piece of text of a stream. False when the pieces are then over the limits: the content of this stream
     * can not be kept.
     *
     * @param Pieces $pieces
     */
    public static function addText(#[SensitiveParameter] array &$pieces, #[SensitiveParameter] string $text): bool
    {
        if ($text !== '') {
            self::add($pieces, ['kind' => self::TEXT, 'text' => $text, 'more' => [], 'raw' => '', 'inner' => []]);
        }

        return self::fits($pieces);
    }

    /**
     * Adds the list of one chunk of a stream. False when the text is not a list of objects, or the pieces are then
     * over the limits: the content of this stream can not be kept.
     *
     * @param Pieces $pieces
     * @param string $json the JSON text of the list
     */
    public static function addList(#[SensitiveParameter] array &$pieces, #[SensitiveParameter] string $json): bool
    {
        // Measured as text before it is cut into its entries, with what was kept before it.
        if (strlen($json) + $pieces['bytes'] > self::LIMIT_BYTES || ! self::isWithinTheLimits($json)) {
            return false;
        }

        $list = RawJson::plain($json);
        $items = $list === null ? null : RawJson::items($list);
        if ($items === null) {
            return false;
        }

        foreach ($items as $item) {
            $entry = self::entry($item);
            if ($entry === null) {
                return false;
            }

            $inner = [];
            if ($entry['kind'] === self::THINKING) {
                foreach (RawJson::items($entry['more'][self::THINKING] ?? '') ?? [] as $innerItem) {
                    $innerEntry = self::entry($innerItem);
                    if ($innerEntry === null) {
                        return false;
                    }
                    // Inside a thinking entry only text is joined: a thinking entry there is kept as it arrived.
                    if ($innerEntry['kind'] === self::THINKING) {
                        $innerEntry = ['kind' => self::OTHER, 'text' => '', 'more' => [], 'raw' => $innerItem];
                    }
                    if ($innerEntry['kind'] !== self::TEXT || $innerEntry['text'] !== '' || $innerEntry['more'] !== []) {
                        $inner[] = $innerEntry;
                    }
                }
                unset($entry['more'][self::THINKING]);
            } elseif ($entry['kind'] === self::TEXT && $entry['text'] === '' && $entry['more'] === []) {
                // A text entry without text says nothing.
                continue;
            }

            self::add($pieces, [...$entry, 'inner' => $inner]);
        }

        return self::fits($pieces);
    }

    /**
     * Whether the entries of a stream hold more than text, as holdsMoreThanText() asks of a whole answer.
     *
     * @param Pieces $pieces
     */
    public static function entriesHoldMoreThanText(#[SensitiveParameter] array $pieces): bool
    {
        foreach ($pieces['entries'] as $entry) {
            if ($entry['kind'] !== self::TEXT && $entry['kind'] !== self::UNTYPED_TEXT) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entries as one list, in JSON text.
     *
     * @param Pieces $pieces
     */
    public static function written(#[SensitiveParameter] array $pieces): string
    {
        $items = [];
        foreach ($pieces['entries'] as $entry) {
            $inner = [];
            foreach ($entry['inner'] as $innerEntry) {
                $inner[] = self::writtenEntry($innerEntry, null);
            }
            $items[] = self::writtenEntry($entry, $entry['kind'] === self::THINKING ? RawJson::list($inner) : null);
        }

        return RawJson::list($items);
    }

    /**
     * @param Pieces $pieces
     */
    private static function fits(#[SensitiveParameter] array $pieces): bool
    {
        return $pieces['bytes'] <= self::LIMIT_BYTES && $pieces['containers'] <= self::LIMIT_CONTAINERS;
    }

    /**
     * One entry of a list, read from its JSON text. Null when it is no object.
     *
     * @return Inner|null
     */
    private static function entry(#[SensitiveParameter] string $json): ?array
    {
        $members = RawJson::members($json);
        if ($members === null) {
            return null;
        }

        $type = $members['type'] ?? null;
        $text = isset($members['text']) && str_starts_with($members['text'], '"') ? json_decode($members['text']) : null;

        if ($type === '"text"' && is_string($text)) {
            unset($members['type'], $members['text']);

            return ['kind' => self::TEXT, 'text' => $text, 'more' => $members, 'raw' => ''];
        }
        if (($type === null || $type === 'null') && is_string($text)) {
            return ['kind' => self::UNTYPED_TEXT, 'text' => '', 'more' => [], 'raw' => $json];
        }
        if ($type === '"thinking"' && str_starts_with($members[self::THINKING] ?? '', '[')) {
            unset($members['type']);

            // The list of the entry stays under its name until the caller has read it.
            return ['kind' => self::THINKING, 'text' => '', 'more' => $members, 'raw' => ''];
        }

        return ['kind' => self::OTHER, 'text' => '', 'more' => [], 'raw' => $json];
    }

    /**
     * Adds an entry: joined to the last one when both are text or both are thinking, otherwise after it. What it
     * adds to the list is counted: its bytes, and its objects and lists. A member that takes the place of an
     * earlier one is counted beside it, so the count is never below what written() gives.
     *
     * @param Pieces $pieces
     * @param Entry $entry
     */
    private static function add(#[SensitiveParameter] array &$pieces, #[SensitiveParameter] array $entry): void
    {
        $last = array_key_last($pieces['entries']);
        $joins = $last !== null && $pieces['entries'][$last]['kind'] === $entry['kind'] && ($entry['kind'] === self::TEXT || $entry['kind'] === self::THINKING);

        [$bytes, $containers] = self::weight($entry);
        if (! $joins) {
            $inner = $entry['inner'];
            $entry['inner'] = [];
            $pieces['entries'][] = $entry;
            $last = array_key_last($pieces['entries']);
            $bytes += self::ENTRY_BYTES;
            $containers += $entry['kind'] === self::THINKING ? 2 : ($entry['kind'] === self::TEXT ? 1 : 0);
        } else {
            $inner = $entry['inner'];
            // In place: a long text arrives in many small pieces, and none of them may copy what came before.
            $pieces['entries'][$last]['text'] .= $entry['text'];
            foreach ($entry['more'] as $name => $value) {
                $pieces['entries'][$last]['more'][$name] = $value;
            }
        }

        foreach ($inner as $innerEntry) {
            [$innerBytes, $innerContainers] = self::weight($innerEntry);
            $before = array_key_last($pieces['entries'][$last]['inner']);
            if ($before !== null && $innerEntry['kind'] === self::TEXT && $pieces['entries'][$last]['inner'][$before]['kind'] === self::TEXT) {
                $pieces['entries'][$last]['inner'][$before]['text'] .= $innerEntry['text'];
                foreach ($innerEntry['more'] as $name => $value) {
                    $pieces['entries'][$last]['inner'][$before]['more'][$name] = $value;
                }
            } else {
                $pieces['entries'][$last]['inner'][] = $innerEntry;
                $innerBytes += self::ENTRY_BYTES;
                $innerContainers += $innerEntry['kind'] === self::TEXT ? 1 : 0;
            }
            $bytes += $innerBytes;
            $containers += $innerContainers;
        }

        $pieces['bytes'] += $bytes;
        $pieces['containers'] += $containers;
    }

    /**
     * What an entry adds to a list beside the marks of the entry itself: the bytes of its text, of its other
     * members and of what is kept as it arrived, and the objects and lists in those.
     *
     * @param Inner $entry
     * @return array{int, int}
     */
    private static function weight(#[SensitiveParameter] array $entry): array
    {
        $bytes = strlen(RawJson::string($entry['text'])) + strlen($entry['raw']);
        $containers = RawJson::containers($entry['raw'], self::LIMIT_CONTAINERS);
        foreach ($entry['more'] as $name => $value) {
            $bytes += strlen((string) $name) + strlen($value) + 4;
            $containers += RawJson::containers($value, self::LIMIT_CONTAINERS);
        }

        return [$bytes, $containers];
    }

    /**
     * @param Inner $entry
     * @param string|null $thinking the list of a thinking entry, as JSON text
     */
    private static function writtenEntry(#[SensitiveParameter] array $entry, #[SensitiveParameter] ?string $thinking): string
    {
        return match ($entry['kind']) {
            // Joined with +, not spread: a member whose name is a number keeps its name.
            self::TEXT => RawJson::object(['type' => '"text"', 'text' => RawJson::string($entry['text'])] + $entry['more']),
            self::THINKING => RawJson::object(['type' => '"thinking"', 'thinking' => $thinking ?? '[]'] + $entry['more']),
            default => $entry['raw'],
        };
    }
}
