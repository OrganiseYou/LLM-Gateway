<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Cohere;

use OrganiseYou\LlmGateway\Adapter\Shared\RawJson;
use SensitiveParameter;

/**
 * What an answer of the Chat v2 format holds that has no neutral form and goes back with its assistant message:
 * the tool plan (`message.tool_plan`, free text the model writes before its calls) and the `thinking` blocks of
 * its content. Neither is answer text. Both are kept as JSON text and never as decoded values: what goes back is
 * what came (RawJson::plain() says what one spelling means).
 *
 * Two things are kept, each on its own:
 *
 * - the plan, when it is a string that is not empty: the JSON text of that one string;
 * - the content, when it is a list that holds a `thinking` block and every block in it is one of the two kinds the
 *   provider file documents for an answer, with exactly the documented members of the documented type:
 *   `{"type": "text", "text": "…"}` or `{"type": "thinking", "thinking": "…"}`. A content of text alone has a
 *   neutral form and needs no keeping.
 *
 * The tool calls of an answer are not kept: the neutral call says all the format says of one (its id, its name and
 * the text of its arguments), and they go back built from the message.
 *
 * The same rule decides what goes back. A kept text comes from wherever the caller stores its messages, and only
 * what the format documents is ever copied from it: a string under `tool_plan`, and under `content` a list of those
 * two kinds of block whose text blocks, joined in their order, are the text of the message.
 *
 * A whole answer holds the two as values of its body. A stream delivers the plan in pieces and the content block
 * by block, each block in pieces; they are joined here into what a whole answer would have held. That text joined
 * this way is taken back is not confirmed.
 *
 * Everything is measured as text before anything decodes it or cuts it into its parts, on the way in and on the way
 * back, and in a stream piece by piece, each of the two on its own: at most 1 MiB for the plan, and at most 1 MiB
 * and 10,000 objects and lists for the content, the limits the adapter base has for an opaque part. The base cannot
 * count for a list that is sealed as one string. What is over its limit is not kept, and a stream stops keeping
 * that one as soon as its pieces pass the limit; the other, the text and the tool calls are read all the same. The
 * limit that applies to both together is the base's: it measures the whole part as it is sealed, where the kept
 * text is escaped once more, and returns an answer without a part that is over it.
 *
 * @phpstan-type Pieces array{plan: string, planBytes: int, planKept: bool, blocks: array<int, array{kind: string, text: string}>, blockBytes: int, listed: bool}
 */
final readonly class ToolPlan
{
    /** The most bytes of what is kept: the limit of an opaque part. */
    public const int LIMIT_BYTES = 1_048_576;

    /** The most objects and lists in a content that is kept: the limit of an opaque part. */
    public const int LIMIT_CONTAINERS = 10_000;

    private const string TEXT = 'text';

    private const string THINKING = 'thinking';

    /**
     * The plan as it is kept and as it is sent: the JSON text of one string that is not empty, in one spelling and
     * within the limit of bytes. Null for anything else.
     */
    public static function plan(#[SensitiveParameter] string $json): ?string
    {
        // Measured as text first, then checked without decoding: JSON, and a string by the mark it starts with.
        if (strlen($json) > self::LIMIT_BYTES || ! json_validate($json) || ($json[strspn($json, " \t\n\r")] ?? '') !== '"') {
            return null;
        }
        $plain = RawJson::plain($json);

        return $plain === null || $plain === '""' ? null : $plain;
    }

    /**
     * The content as it is kept and as it is sent: in one spelling, and only when it is within the limits, holds no
     * name twice in any object, and is a list of blocks of the two kinds, each with exactly its documented members
     * of the documented type, with a `thinking` block among them. Null for anything else: nothing but such a list
     * ever stands as the content of an assistant message in place of what the adapter builds itself.
     */
    public static function content(#[SensitiveParameter] string $json): ?string
    {
        $blocks = self::blocksOf($json);
        if ($blocks === null) {
            return null;
        }
        foreach ($blocks[1] as [$kind]) {
            if ($kind === self::THINKING) {
                return $blocks[0];
            }
        }

        return null;
    }

    /**
     * Whether a text is within the limits of what is kept: its bytes, and its objects and lists.
     */
    public static function isWithinTheLimits(#[SensitiveParameter] string $json): bool
    {
        return strlen($json) <= self::LIMIT_BYTES && RawJson::containers($json, self::LIMIT_CONTAINERS) <= self::LIMIT_CONTAINERS;
    }

    /**
     * Whether a decoded `content` holds a `thinking` block: only then is its list kept.
     */
    public static function holdsThinking(#[SensitiveParameter] mixed $content): bool
    {
        if (! is_array($content) || ! array_is_list($content)) {
            return false;
        }
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === self::THINKING) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a sealed content adds to an assistant message, as JSON text: the plan, the content, or both. Given only
     * when the sealed content holds nothing but the members `tool_plan` and `content`, each a string that is
     * exactly the text this class keeps (one spelling, the documented shape), the two together are within the
     * limits, and the content says what the message says: its text blocks, joined in their order, are the text of
     * the message. Null otherwise, and then nothing of the part is sent.
     *
     * The texts are compared as text in one spelling, not as decoded values. A kept text comes from wherever the
     * caller stores its messages, and nothing in it is secret: so it goes back only where it agrees with the
     * neutral message it stands for. What it then still adds to that message is what has no neutral form: the
     * plan, the thinking, and over how many blocks the text is cut.
     *
     * @param array<mixed> $sealed what the adapter base opened
     * @return array{tool_plan: string|null, content: string|null}|null
     */
    public static function agreeing(#[SensitiveParameter] array $sealed, #[SensitiveParameter] string $text): ?array
    {
        $plan = $sealed['tool_plan'] ?? null;
        $content = $sealed['content'] ?? null;
        if (array_diff(array_keys($sealed), ['tool_plan', 'content']) !== [] || ($plan === null && $content === null)) {
            return null;
        }
        if (($plan !== null && ! is_string($plan)) || ($content !== null && ! is_string($content))) {
            return null;
        }
        // Measured together before either is looked at. A part the base opened is within its 1 MiB already, which
        // this cannot pass; the check keeps the method safe on its own.
        if (strlen((string) $plan) + strlen((string) $content) > self::LIMIT_BYTES) {
            return null;
        }
        if ($plan !== null && self::plan($plan) !== $plan) {
            return null;
        }
        if ($content !== null) {
            $blocks = self::content($content) === $content ? self::blocksOf($content) : null;
            if ($blocks === null) {
                return null;
            }
            $said = '';
            foreach ($blocks[1] as [$kind, $value]) {
                $piece = $kind === self::TEXT ? json_decode($value) : '';
                if (! is_string($piece)) {
                    return null;
                }
                $said .= $piece;
            }
            if ($said !== $text) {
                return null;
            }
        }

        return ['tool_plan' => $plan, 'content' => $content];
    }

    /**
     * The pieces of a stream before the first one: no plan and no blocks.
     *
     * @return Pieces
     */
    public static function none(): array
    {
        return ['plan' => '', 'planBytes' => 0, 'planKept' => true, 'blocks' => [], 'blockBytes' => 0, 'listed' => true];
    }

    /**
     * Adds a piece of the plan. When the plan is then over the limit of bytes it is let go and no later piece is
     * kept; the blocks are not touched.
     *
     * @param Pieces $pieces
     */
    public static function addToPlan(#[SensitiveParameter] array &$pieces, #[SensitiveParameter] string $piece): void
    {
        if (! $pieces['planKept']) {
            return;
        }
        // The two quotes of the plan count once, with its first piece that holds anything.
        $quotes = $pieces['plan'] === '' && $piece !== '' ? 2 : 0;
        // In place: a long plan arrives in many small pieces, and none of them may copy what came before.
        $pieces['plan'] .= $piece;
        $pieces['planBytes'] += strlen(RawJson::string($piece)) - 2 + $quotes;
        if ($pieces['planBytes'] > self::LIMIT_BYTES) {
            $pieces['plan'] = '';
            $pieces['planKept'] = false;
        }
    }

    /**
     * Adds the block a stream starts at an index, with the text it starts with. A block of a kind that is none of
     * the two, an index that was started before, more blocks than a kept list may hold and a list that passes the
     * limit of bytes end the keeping of the content: its blocks are let go. The plan is not touched.
     *
     * @param Pieces $pieces
     */
    public static function start(#[SensitiveParameter] array &$pieces, int $index, string $kind, #[SensitiveParameter] string $text): void
    {
        if (! $pieces['listed']) {
            return;
        }
        // A list of blocks is one list and an object per block.
        if (isset($pieces['blocks'][$index]) || ! in_array($kind, [self::TEXT, self::THINKING], true) || count($pieces['blocks']) + 2 > self::LIMIT_CONTAINERS) {
            self::stopListing($pieces);

            return;
        }
        // The two brackets of the list with its first block, a comma before every later one.
        $around = $pieces['blocks'] === [] ? 2 : 1;
        $pieces['blocks'][$index] = ['kind' => $kind, 'text' => $text];

        // What the block takes in the list: `{"type":"<kind>","<kind>":` and `}` (15 bytes and the kind twice), and
        // its text as JSON writes it. Exact: the count is what written() gives.
        self::growList($pieces, strlen(RawJson::string($text)) + strlen($kind) * 2 + 15 + $around);
    }

    /**
     * Adds a piece of text, or of thinking, to the block at an index. A piece for an index that was never started
     * adds nothing.
     *
     * @param Pieces $pieces
     */
    public static function add(#[SensitiveParameter] array &$pieces, int $index, #[SensitiveParameter] string $piece): void
    {
        if (! $pieces['listed'] || ! isset($pieces['blocks'][$index])) {
            return;
        }
        $pieces['blocks'][$index]['text'] .= $piece;
        self::growList($pieces, strlen(RawJson::string($piece)) - 2);
    }

    /**
     * What a stream held that is kept, as the content to seal: the plan when it is not empty, and the list of
     * blocks, in the order of their index, when one of them is thinking. Empty when nothing is kept. Each is held
     * to its own limit; the limit of the whole part is the adapter base's, which measures the part as it is sealed.
     *
     * @param Pieces $pieces
     * @return array<string, string>
     */
    public static function sealable(#[SensitiveParameter] array $pieces): array
    {
        $kept = [];
        $plan = ! $pieces['planKept'] || $pieces['plan'] === '' ? null : self::plan(RawJson::string($pieces['plan']));
        if ($plan !== null) {
            $kept['tool_plan'] = $plan;
        }

        if (! $pieces['listed']) {
            return $kept;
        }
        $blocks = $pieces['blocks'];
        ksort($blocks);
        $items = [];
        $thinks = false;
        foreach ($blocks as $block) {
            $thinks = $thinks || $block['kind'] === self::THINKING;
            $items[] = RawJson::object(['type' => '"' . $block['kind'] . '"', $block['kind'] => RawJson::string($block['text'])]);
        }
        $content = $thinks ? self::content(RawJson::list($items)) : null;
        if ($content !== null) {
            $kept['content'] = $content;
        }

        return $kept;
    }

    /**
     * Counts the bytes a block or a piece adds to the list, and ends the keeping of the content when the list
     * passes the limit.
     *
     * @param Pieces $pieces
     */
    private static function growList(#[SensitiveParameter] array &$pieces, int $bytes): void
    {
        $pieces['blockBytes'] += $bytes;
        if ($pieces['blockBytes'] > self::LIMIT_BYTES) {
            self::stopListing($pieces);
        }
    }

    /**
     * Ends the keeping of the content of a stream: its blocks are let go at once, and no later block is kept.
     *
     * @param Pieces $pieces
     */
    private static function stopListing(#[SensitiveParameter] array &$pieces): void
    {
        $pieces['listed'] = false;
        $pieces['blocks'] = [];
    }

    /**
     * A content in one spelling, and of each block its kind and the JSON text of its string; null when the text is
     * over the limits, is no JSON list, holds a name twice in any object, is empty, or holds something that is no
     * block of the two kinds with exactly its two members.
     *
     * @return array{string, list<array{string, string}>}|null
     */
    private static function blocksOf(#[SensitiveParameter] string $json): ?array
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
        $items = $list === null ? null : RawJson::items($list);
        if ($list === null || $items === null || $items === []) {
            return null;
        }

        $blocks = [];
        foreach ($items as $item) {
            $members = RawJson::members($item) ?? [];
            $kind = match ($members['type'] ?? null) {
                '"text"' => self::TEXT,
                '"thinking"' => self::THINKING,
                default => null,
            };
            if ($kind === null || count($members) !== 2 || ! str_starts_with($members[$kind] ?? '', '"')) {
                return null;
            }
            $blocks[] = [$kind, $members[$kind]];
        }

        return [$list, $blocks];
    }
}
