<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

use SensitiveParameter;

/**
 * Cuts one leading block of reasoning, `<think>…</think>`, from the text of an answer, for a server that writes its
 * reasoning into the text (the option `inline_reasoning: strip`).
 *
 * Only a block the text begins with, after white space, and only one that is closed: a text that opens a block and
 * never closes it is left as it is. The white space after the block goes with it.
 *
 * A stream is cut the same way, piece by piece, with feed() and rest(): what a stream gives in pieces is, joined,
 * what stripped() gives of the whole text.
 */
final readonly class InlineReasoning
{
    /** Nothing but white space arrived yet, or the start of what may become the opening mark. */
    public const int UNDECIDED = 0;

    /** Inside the block: nothing is given until it closes. */
    public const int INSIDE = 1;

    /** The block closed: the white space after it is dropped. */
    public const int AFTER = 2;

    /** The text of the answer: every piece is given as it comes. */
    public const int TEXT = 3;

    private const string OPEN = '<think>';

    private const string CLOSE = '</think>';

    private const string WHITE_SPACE = " \t\n\r";

    public static function stripped(#[SensitiveParameter] string $text): string
    {
        $from = ltrim($text, self::WHITE_SPACE);
        if (! str_starts_with($from, self::OPEN)) {
            return $text;
        }

        $closed = strpos($from, self::CLOSE);

        return $closed === false ? $text : ltrim(substr($from, $closed + strlen(self::CLOSE)), self::WHITE_SPACE);
    }

    /**
     * Takes the next piece of a streamed text.
     *
     * @param int $state where the stream is: one of the constants; UNDECIDED at the start
     * @param string $held what arrived and was not given yet
     * @return array{int, string, string} the new state, what is held now, and the text to give now (may be empty)
     */
    public static function feed(
        int $state,
        #[SensitiveParameter]
        string $held,
        #[SensitiveParameter]
        string $piece,
    ): array {
        if ($state === self::TEXT) {
            return [self::TEXT, '', $piece];
        }
        if ($state === self::AFTER) {
            $piece = ltrim($piece, self::WHITE_SPACE);

            return [$piece === '' ? self::AFTER : self::TEXT, '', $piece];
        }

        // Only what is new is searched, with the few bytes before it that a mark could have begun in: a long block
        // costs its own length once, however many pieces it arrives in.
        $before = strlen($held);
        $held .= $piece;

        if ($state === self::UNDECIDED) {
            // All that was held is white space and at most the first bytes of the opening mark.
            $from = max(0, $before - strlen(self::OPEN));
            $from += strspn($held, self::WHITE_SPACE, $from);
            $probe = substr($held, $from, strlen(self::OPEN));

            if (strlen($probe) < strlen(self::OPEN) && str_starts_with(self::OPEN, $probe)) {
                return [self::UNDECIDED, $held, ''];
            }
            if ($probe !== self::OPEN) {
                return [self::TEXT, '', $held];
            }
            // The block begins here: its end is looked for from here on.
            $before = $from + strlen(self::CLOSE);
        }

        $closed = strpos($held, self::CLOSE, max(0, $before - strlen(self::CLOSE)));
        if ($closed === false) {
            return [self::INSIDE, $held, ''];
        }

        $after = ltrim(substr($held, $closed + strlen(self::CLOSE)), self::WHITE_SPACE);

        return [$after === '' ? self::AFTER : self::TEXT, '', $after];
    }

    /**
     * What is still to give when the stream ends: what was held while it could become a block and did not.
     */
    public static function rest(
        int $state,
        #[SensitiveParameter]
        string $held,
    ): string {
        return $state === self::UNDECIDED || $state === self::INSIDE ? $held : '';
    }
}
