<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Anthropic;

use OrganiseYou\LlmGateway\Adapter\Shared\RawJson;
use SensitiveParameter;

/**
 * The content of an answer that holds thinking: `thinking` blocks with their `signature`, and `redacted_thinking`
 * blocks. The format wants them back unchanged with the assistant message of the next round, so the whole list of
 * content blocks is kept, as JSON text and never as decoded values: what goes back is what came (RawJson::plain()
 * says what one spelling means).
 *
 * Only a list of the four kinds of block the provider file documents for text chat is kept: `text`, `tool_use`,
 * `thinking` and `redacted_thinking`, with at least one of the last two, and each block with exactly the members
 * that file documents for its kind, of the type it documents (MEMBERS). A content of text and tool calls alone has
 * a neutral form and needs no keeping; a content with a block of another kind, with a member no page names, with a
 * member of another type or with an object that holds a name twice is read for its text and its tool calls, and
 * is not kept. The same rule decides what goes back: a kept list comes from wherever the caller stores its
 * messages, and only what the format documents is ever copied from it.
 *
 * A whole answer holds the list as one value. A stream delivers it block by block: the start of a block, then its
 * text, its thinking, its signature or the JSON text of its tool input in pieces. The pieces are joined here into
 * the list a whole answer would have held. The provider file documents both shapes; that a list joined this way is
 * accepted back is not confirmed.
 *
 * A list is measured as text before anything decodes it or cuts it into its blocks, on the way in and on the way
 * back, and in a stream piece by piece: at most 1 MiB, and at most 10,000 objects and lists, the limits the adapter
 * base has for an opaque part. The base cannot count for this list: sealed as one string, it is one string to the
 * base. A list over a limit is not kept, and a stream stops keeping as soon as its pieces pass one; the text and
 * the tool calls of the answer are read all the same.
 *
 * @phpstan-type Block array{kind: string, members: array<array-key, string>, text: string, thinking: string, mark: string, json: string}
 * @phpstan-type Pieces array{blocks: array<int, Block>, bytes: int, containers: int}
 */
final readonly class ContentBlocks
{
    /** The most bytes of a list that is kept: the limit of an opaque part. */
    public const int LIMIT_BYTES = 1_048_576;

    /** The most objects and lists in a list that is kept: the limit of an opaque part. */
    public const int LIMIT_CONTAINERS = 10_000;

    private const string TEXT = 'text';

    private const string TOOL_USE = 'tool_use';

    private const string THINKING = 'thinking';

    private const string REDACTED_THINKING = 'redacted_thinking';

    /** The kinds of block that are kept, by the JSON text of their `type`. */
    private const array KINDS = [
        '"text"' => self::TEXT,
        '"tool_use"' => self::TOOL_USE,
        '"thinking"' => self::THINKING,
        '"redacted_thinking"' => self::REDACTED_THINKING,
    ];

    /**
     * The members of a block of each kind, as `docs/providers/anthropic.md` records them ("The shape of an answer",
     * "The shape of a tool call…"), each with the mark its JSON text starts with: a string, or an object.
     */
    private const array MEMBERS = [
        self::TEXT => ['type' => '"', 'text' => '"'],
        self::TOOL_USE => ['type' => '"', 'id' => '"', 'name' => '"', 'input' => '{'],
        self::THINKING => ['type' => '"', 'thinking' => '"', 'signature' => '"'],
        self::REDACTED_THINKING => ['type' => '"', 'data' => '"'],
    ];

    /** Which member of a block a delta of a stream adds to, by the type of the delta: the kind of block it fits, and the member. */
    private const array DELTAS = [
        'text_delta' => [self::TEXT, 'text'],
        'input_json_delta' => [self::TOOL_USE, 'json'],
        'thinking_delta' => [self::THINKING, 'thinking'],
        'signature_delta' => [self::THINKING, 'mark'],
    ];

    /**
     * Whether a decoded `content` must go back as it came: a list of blocks of the four kinds, with thinking among
     * them.
     */
    public static function mustGoBack(#[SensitiveParameter] mixed $content): bool
    {
        if (! is_array($content) || ! array_is_list($content)) {
            return false;
        }

        $thinks = false;
        foreach ($content as $block) {
            $kind = is_array($block) && is_string($block['type'] ?? null) ? (self::KINDS['"' . $block['type'] . '"'] ?? null) : null;
            if ($kind === null) {
                return false;
            }
            $thinks = $thinks || $kind === self::THINKING || $kind === self::REDACTED_THINKING;
        }

        return $thinks;
    }

    /**
     * The list as it is kept and as it is sent: in one spelling, and only when it is within the limits, holds no
     * name twice in any object, and is a list of blocks of the four kinds, each with exactly its documented members
     * of the documented type, with thinking among them. Null for anything else: nothing but such a list ever stands
     * as the content of an assistant message in place of what the adapter builds itself.
     */
    public static function kept(#[SensitiveParameter] string $json): ?string
    {
        // Measured as text first: what follows makes a string per block and per member.
        if (! self::isWithinTheLimits($json)) {
            return null;
        }
        // Checked, not decoded: JSON, and a list by the mark it starts with.
        if (! json_validate($json) || ($json[strspn($json, " \t\n\r")] ?? '') !== '[') {
            return null;
        }
        // An object that holds a name twice is read by its last value here and may be read by its first by the
        // server: a list that two readers can read differently is not kept and not sent.
        if (RawJson::hasRepeatedName($json)) {
            return null;
        }

        $list = RawJson::plain($json);
        $blocks = $list === null ? null : RawJson::items($list);
        if ($list === null || $blocks === null || $blocks === []) {
            return null;
        }

        $thinks = false;
        foreach ($blocks as $block) {
            $members = RawJson::members($block);
            $kind = $members === null ? null : (self::KINDS[$members['type'] ?? ''] ?? null);
            if ($members === null || $kind === null || count($members) !== count(self::MEMBERS[$kind])) {
                return null;
            }
            foreach (self::MEMBERS[$kind] as $name => $mark) {
                if (! str_starts_with($members[$name] ?? '', $mark)) {
                    return null;
                }
            }
            $thinks = $thinks || $kind === self::THINKING || $kind === self::REDACTED_THINKING;
        }

        return $thinks ? $list : null;
    }

    /**
     * Whether a list, as text, is within the limits of what is kept: its bytes, and its objects and lists.
     */
    public static function isWithinTheLimits(#[SensitiveParameter] string $json): bool
    {
        return strlen($json) <= self::LIMIT_BYTES && RawJson::containers($json, self::LIMIT_CONTAINERS) <= self::LIMIT_CONTAINERS;
    }

    /**
     * The blocks of a kept list, each as its JSON text, when the list is what kept() gives and says what the
     * message says: its text blocks, joined, are the text of the message, and its tool-call blocks are the tool
     * calls of the message, in their order, each with the same id, the same name and an input of the same value.
     * Null otherwise.
     *
     * A kept list comes from wherever the caller stores its messages, and nothing in it is secret: so it goes back
     * only where it agrees with the neutral message it stands for. What it then still adds to that message is
     * what has no neutral form: the thinking, its signature and the data of redacted thinking.
     *
     * @param list<array{string, string, string}> $calls of every tool call of the message: its id, its name and
     *                                                  the JSON text of the input the adapter would send for it
     * @return list<string>|null
     */
    public static function agreeing(#[SensitiveParameter] string $list, #[SensitiveParameter] string $text, #[SensitiveParameter] array $calls): ?array
    {
        if (self::kept($list) !== $list) {
            return null;
        }

        // Within the limits, so it can be decoded.
        $blocks = json_decode($list, true);
        if (! is_array($blocks)) {
            return null;
        }

        $said = '';
        $asked = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                return null;
            }
            $kind = $block['type'] ?? null;
            if ($kind === self::TEXT) {
                if (! is_string($block['text'] ?? null)) {
                    return null;
                }
                $said .= $block['text'];
            } elseif ($kind === self::TOOL_USE) {
                if (! is_string($block['id'] ?? null) || ! is_string($block['name'] ?? null)) {
                    return null;
                }
                $asked[] = [$block['id'], $block['name'], $block['input'] ?? null];
            }
        }
        if ($said !== $text || count($asked) !== count($calls)) {
            return null;
        }
        foreach ($calls as $at => [$id, $name, $input]) {
            // The inputs are compared as values: their spelling differs between an answer and a rebuilt transcript.
            if ($asked[$at][0] !== $id || $asked[$at][1] !== $name || $asked[$at][2] !== json_decode($input, true)) {
                return null;
            }
        }

        return RawJson::items($list);
    }

    /**
     * The pieces of a stream before the first one: no blocks.
     *
     * @return Pieces
     */
    public static function none(): array
    {
        return ['blocks' => [], 'bytes' => 0, 'containers' => 0];
    }

    /**
     * Adds the block a stream starts at an index. False when the text is no block of the four kinds, the index was
     * started before, or the pieces are then over the limits: the content of this stream can not be kept.
     *
     * @param Pieces $pieces
     * @param string $json the JSON text of the block as the event wrote it
     */
    public static function start(#[SensitiveParameter] array &$pieces, int $index, #[SensitiveParameter] string $json): bool
    {
        // Measured as text before it is cut into its members, with what was kept before it.
        if (isset($pieces['blocks'][$index]) || strlen($json) + $pieces['bytes'] > self::LIMIT_BYTES || ! self::isWithinTheLimits($json)) {
            return false;
        }
        // A name twice in the block: cut into its members, the first value would be lost without a word.
        if (RawJson::hasRepeatedName($json)) {
            return false;
        }

        $plain = RawJson::plain($json);
        $members = $plain === null ? null : RawJson::members($plain);
        $kind = $members === null ? null : (self::KINDS[$members['type'] ?? ''] ?? null);
        if ($plain === null || $members === null || $kind === null) {
            return false;
        }

        $pieces['blocks'][$index] = [
            'kind' => $kind,
            'members' => $members,
            'text' => self::stringOf($members, 'text'),
            'thinking' => self::stringOf($members, 'thinking'),
            'mark' => self::stringOf($members, 'signature'),
            'json' => '',
        ];
        $pieces['bytes'] += strlen($plain) + 1;
        $pieces['containers'] += RawJson::containers($plain, self::LIMIT_CONTAINERS);

        return self::fits($pieces);
    }

    /**
     * Adds a piece a delta of a stream carries to the block at its index. A delta for an index that was never
     * started, or of a type that does not fit the kind of its block, adds nothing. False when the pieces are then
     * over the limits: the content of this stream can not be kept.
     *
     * @param Pieces $pieces
     * @param string $deltaType `text_delta`, `input_json_delta`, `thinking_delta` or `signature_delta`
     */
    public static function add(#[SensitiveParameter] array &$pieces, int $index, string $deltaType, #[SensitiveParameter] string $piece): bool
    {
        [$kind, $member] = self::DELTAS[$deltaType] ?? [null, null];
        if ($member === null || ! isset($pieces['blocks'][$index]) || $pieces['blocks'][$index]['kind'] !== $kind) {
            return true;
        }

        // In place: a long text arrives in many small pieces, and none of them may copy what came before.
        $pieces['blocks'][$index][$member] .= $piece;
        if ($member === 'json') {
            // A piece of JSON text can end inside a string, so its objects and lists cannot be told from brackets
            // in a string: every bracket counts. The count is then never too low.
            $pieces['bytes'] += strlen($piece);
            $pieces['containers'] += substr_count($piece, '{') + substr_count($piece, '[');
        } else {
            $pieces['bytes'] += strlen(RawJson::string($piece)) - 2;
        }

        return self::fits($pieces);
    }

    /**
     * Whether the blocks of a stream hold thinking: only then is their list kept.
     *
     * @param Pieces $pieces
     */
    public static function holdThinking(#[SensitiveParameter] array $pieces): bool
    {
        foreach ($pieces['blocks'] as $block) {
            if ($block['kind'] === self::THINKING || $block['kind'] === self::REDACTED_THINKING) {
                return true;
            }
        }

        return false;
    }

    /**
     * The blocks as one list, in JSON text and in the order of their index: every block as it started, with what
     * its deltas added in the member it belongs to. Null when the input of a tool call is no JSON object: such a
     * block cannot go back.
     *
     * @param Pieces $pieces
     */
    public static function written(#[SensitiveParameter] array $pieces): ?string
    {
        $blocks = $pieces['blocks'];
        ksort($blocks);

        $items = [];
        foreach ($blocks as $block) {
            $members = $block['members'];
            if ($block['kind'] === self::TEXT) {
                $members['text'] = RawJson::string($block['text']);
            } elseif ($block['kind'] === self::THINKING) {
                $members['thinking'] = RawJson::string($block['thinking']);
                if ($block['mark'] !== '' || isset($members['signature'])) {
                    $members['signature'] = RawJson::string($block['mark']);
                }
            } elseif ($block['kind'] === self::TOOL_USE) {
                // No piece of input at all: the input the block started with, which the format writes as `{}`.
                $input = trim($block['json']) === '' ? ($members['input'] ?? '{}') : $block['json'];
                $input = json_validate($input) ? RawJson::plain($input) : null;
                if ($input === null || ! str_starts_with($input, '{')) {
                    return null;
                }
                $members['input'] = $input;
            }
            $items[] = RawJson::object($members);
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
     * The string under a name of a block, from its JSON text; an empty string when there is none.
     *
     * @param array<array-key, string> $members
     */
    private static function stringOf(#[SensitiveParameter] array $members, string $name): string
    {
        $value = str_starts_with($members[$name] ?? '', '"') ? json_decode($members[$name]) : null;

        return is_string($value) ? $value : '';
    }
}
