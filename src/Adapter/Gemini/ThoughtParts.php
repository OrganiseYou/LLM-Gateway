<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Gemini;

use OrganiseYou\LlmGateway\Adapter\Shared\RawJson;
use SensitiveParameter;

/**
 * The parts of an answer that holds a thought signature or a tool call. A signature is an opaque string the format
 * puts on a part and wants back in the same part with the next request of a tool round. A tool call carries what
 * the neutral call cannot say: whether the server itself gave it an id, and that the call is the model's own and
 * not one a caller wrote. So the whole list of parts is kept, as JSON text and never as decoded values: what goes
 * back is what came (RawJson::plain() says what one spelling means).
 *
 * Only a list of the two kinds of part the provider file documents for text chat is kept: a part with `text` and a
 * part with `functionCall`, each with nothing beside it but `thought` and `thoughtSignature`, each member of the
 * type the file documents, and with a signature or a call on at least one of them. A list of text alone has a
 * neutral form and needs no keeping; a list with a part of another kind, with a member no page names, with a
 * member of another type or with an object that holds a name twice is read for its text and its tool calls, and is
 * not kept. The same rule decides what goes back: a kept list comes from wherever the caller stores its messages,
 * and only what the format documents is ever copied from it.
 *
 * A whole answer holds the list as one value. A stream delivers it chunk by chunk, each chunk with the new parts
 * only; the parts are put one after the other here, and a text part joins the text part before it when neither
 * carries a signature and both say the same of `thought`, so that the list is the one a whole answer would have
 * held. That a list joined this way is accepted back is not confirmed.
 *
 * A list is measured as text before anything decodes it or cuts it into its parts, on the way in and on the way
 * back, and in a stream chunk by chunk: at most 1 MiB, and at most 10,000 objects and lists, the limits the adapter
 * base has for an opaque part. The base cannot count for this list: sealed as one string, it is one string to the
 * base. A list over a limit is not kept, and a stream stops keeping as soon as its parts pass one; the text and the
 * tool calls of the answer are read all the same.
 *
 * @phpstan-type Piece array{members: array<array-key, string>, text: string|null}
 * @phpstan-type Pieces array{parts: list<Piece>, bytes: int, containers: int, needed: bool}
 */
final readonly class ThoughtParts
{
    /** The most bytes of a list that is kept: the limit of an opaque part. */
    public const int LIMIT_BYTES = 1_048_576;

    /** The most objects and lists in a list that is kept: the limit of an opaque part. */
    public const int LIMIT_CONTAINERS = 10_000;

    /**
     * The members of a `functionCall`, as `docs/providers/gemini.md` records them ("The shape of a tool call in an
     * answer"), each with the mark its JSON text starts with: a string, or an object.
     */
    private const array CALL_MEMBERS = ['id' => '"', 'name' => '"', 'args' => '{'];

    /**
     * Whether the decoded parts of an answer hold a thought signature or a tool call: only then is their list kept.
     */
    public static function mustGoBack(#[SensitiveParameter] mixed $parts): bool
    {
        if (! is_array($parts) || ! array_is_list($parts)) {
            return false;
        }
        foreach ($parts as $part) {
            if (is_array($part) && (is_string($part['thoughtSignature'] ?? null) || is_array($part['functionCall'] ?? null))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The list as it is kept and as it is sent: in one spelling, and only when it is within the limits, holds no
     * name twice in any object, and is a list of parts of the two kinds, each with exactly the members the format
     * documents, of the documented type, with a signature or a call on at least one of them. Null for anything
     * else: nothing but such a list ever stands as the parts of a model turn in place of what the adapter builds
     * itself.
     */
    public static function kept(#[SensitiveParameter] string $json): ?string
    {
        $parts = self::partsOf($json);
        if ($parts === null) {
            return null;
        }

        foreach ($parts[1] as $members) {
            if (isset($members['thoughtSignature']) || isset($members['functionCall'])) {
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * Whether a list, as text, is within the limits of what is kept: its bytes, and its objects and lists.
     */
    public static function isWithinTheLimits(#[SensitiveParameter] string $json): bool
    {
        return strlen($json) <= self::LIMIT_BYTES && RawJson::containers($json, self::LIMIT_CONTAINERS) <= self::LIMIT_CONTAINERS;
    }

    /**
     * The parts of a kept list, each as its JSON text, with the ids the server itself gave to its calls, when the
     * list is what kept() gives and says what the message says. The list is walked once, in its order: its text
     * parts that are no thought, joined, are the text of the message, and its call parts are the tool calls of the
     * message, one after the other, each with the same name, the same arguments and the same id; a call the server
     * gave no id must have the id this adapter makes for such a call. Null otherwise.
     *
     * The arguments are compared as JSON text in one spelling, not as decoded values: decoded, two whole numbers too
     * large for PHP are the same float, and an empty object is an empty list. So arguments that a caller wrote
     * again in another spelling of a number, or with its members in another order, do not agree, and the message
     * is then built from its neutral fields.
     *
     * A kept list comes from wherever the caller stores its messages, and nothing in it is secret: so it goes back
     * only where it agrees with the neutral message it stands for. What it then still adds to that message is
     * what has no neutral form: a signature and the part it stands on, a thought and its text, where the text
     * stands among the calls and over how many parts it is cut, and which calls carry an id of the server.
     *
     * @param list<array{string, string, string}> $calls of every tool call of the message: its id, its name and
     *                                                  the JSON text of the arguments the adapter would send for it
     * @return array{list<string>, array<array-key, true>}|null the parts, and the ids that are the server's own
     */
    public static function agreeing(#[SensitiveParameter] string $list, #[SensitiveParameter] string $text, #[SensitiveParameter] array $calls): ?array
    {
        if (self::kept($list) !== $list) {
            return null;
        }
        // Within the limits and in one spelling: cut into its parts, each into its members.
        $parts = self::partsOf($list);
        $items = RawJson::items($list);
        if ($parts === null || $items === null) {
            return null;
        }

        $said = '';
        $at = 0;
        $given = [];
        foreach ($parts[1] as $members) {
            if (isset($members['text'])) {
                $piece = json_decode($members['text']);
                if (! is_string($piece)) {
                    return null;
                }
                $said .= ($members['thought'] ?? 'false') === 'true' ? '' : $piece;

                continue;
            }

            $call = RawJson::members($members['functionCall'] ?? '');
            $expected = $calls[$at++] ?? null;
            if ($call === null || $expected === null) {
                return null;
            }
            [$id, $name, $arguments] = $expected;
            if (json_decode($call['name'] ?? 'null') !== $name || ($call['args'] ?? '{}') !== RawJson::plain($arguments)) {
                return null;
            }
            $itsId = isset($call['id']) ? json_decode($call['id']) : null;
            if (is_string($itsId) ? $itsId !== $id : ! Reading::isAMadeId($id)) {
                return null;
            }
            if (is_string($itsId)) {
                $given[$itsId] = true;
            }
        }

        return $said === $text && $at === count($calls) ? [$items, $given] : null;
    }

    /**
     * The pieces of a stream before the first one: no parts.
     *
     * @return Pieces
     */
    public static function none(): array
    {
        return ['parts' => [], 'bytes' => 0, 'containers' => 0, 'needed' => false];
    }

    /**
     * Adds the parts of one chunk of a stream. False when the text is no list of parts of the two kinds, or the
     * pieces are then over the limits: the parts of this stream can not be kept.
     *
     * @param Pieces $pieces
     * @param string $json the JSON text of the parts of the chunk, as the chunk wrote it
     */
    public static function add(#[SensitiveParameter] array &$pieces, #[SensitiveParameter] string $json): bool
    {
        // Measured as text before it is cut into its parts, with what was kept before it.
        if (strlen($json) + $pieces['bytes'] > self::LIMIT_BYTES) {
            return false;
        }
        $parts = self::partsOf($json);
        if ($parts === null) {
            return false;
        }

        foreach ($parts[1] as $members) {
            $decoded = isset($members['text']) ? json_decode($members['text']) : null;
            if (isset($members['text']) && ! is_string($decoded)) {
                return false;
            }
            $text = is_string($decoded) ? $decoded : null;

            $last = array_key_last($pieces['parts']);
            if (is_string($text) && $last !== null && self::joins($pieces['parts'][$last], $members)) {
                // In place: a long text arrives in many small pieces, and none of them may copy what came before.
                $pieces['parts'][$last]['text'] .= $text;
                $pieces['bytes'] += strlen($members['text']) - 2;

                continue;
            }

            $written = RawJson::object($members);
            $pieces['parts'][] = ['members' => $members, 'text' => $text];
            $pieces['bytes'] += strlen($written) + 1;
            $pieces['containers'] += RawJson::containers($written, self::LIMIT_CONTAINERS);
            $pieces['needed'] = $pieces['needed'] || isset($members['thoughtSignature']) || isset($members['functionCall']);
        }

        return $pieces['bytes'] <= self::LIMIT_BYTES && $pieces['containers'] <= self::LIMIT_CONTAINERS;
    }

    /**
     * Whether the parts of a stream hold a signature or a tool call: only then is their list kept.
     *
     * @param Pieces $pieces
     */
    public static function holdWhatGoesBack(#[SensitiveParameter] array $pieces): bool
    {
        return $pieces['needed'];
    }

    /**
     * The parts of a stream as one list, in JSON text and in the order they arrived: every part as it was written,
     * a text part with the texts that joined it.
     *
     * @param Pieces $pieces
     */
    public static function written(#[SensitiveParameter] array $pieces): string
    {
        $items = [];
        foreach ($pieces['parts'] as $piece) {
            $members = $piece['members'];
            if ($piece['text'] !== null) {
                $members['text'] = RawJson::string($piece['text']);
            }
            $items[] = RawJson::object($members);
        }

        return RawJson::list($items);
    }

    /**
     * A list of parts in one spelling, and the members of each part as JSON text; null when the text is over the
     * limits, is no JSON list, holds a name twice in any object, is empty, or holds something that is no part of
     * the two kinds.
     *
     * @return array{string, list<array<array-key, string>>}|null
     */
    private static function partsOf(#[SensitiveParameter] string $json): ?array
    {
        // Measured as text first: what follows makes a string per part and per member.
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

        $parts = [];
        foreach ($items as $item) {
            $members = RawJson::members($item);
            if ($members === null || ! self::isAPart($members)) {
                return null;
            }
            $parts[] = $members;
        }

        return [$list, $parts];
    }

    /**
     * Whether the members of an object are those of a part that is kept: `text` (a string) or `functionCall` (an
     * object with the documented members), one of the two, and beside it nothing but `thought` (true or false) and
     * `thoughtSignature` (a string). A call that says it is a thought is not kept: the provider file says what a
     * text that is a thought means, and nothing of a call that is one.
     *
     * @param array<array-key, string> $members name => JSON text, in one spelling
     */
    private static function isAPart(#[SensitiveParameter] array $members): bool
    {
        $holds = 0;
        foreach ($members as $name => $value) {
            $fits = match ((string) $name) {
                'text', 'thoughtSignature' => str_starts_with($value, '"'),
                'functionCall' => self::isACall($value),
                'thought' => $value === 'true' || $value === 'false',
                default => false,
            };
            if (! $fits) {
                return false;
            }
            $holds += in_array((string) $name, ['text', 'functionCall'], true) ? 1 : 0;
        }

        return $holds === 1 && ! (isset($members['functionCall']) && ($members['thought'] ?? 'false') === 'true');
    }

    /**
     * Whether a JSON text is a `functionCall` with only its documented members: a name, and at most an id that is
     * not empty and arguments that are an object.
     */
    private static function isACall(#[SensitiveParameter] string $json): bool
    {
        $members = RawJson::members($json);
        if ($members === null || ! isset($members['name']) || ($members['id'] ?? '') === '""') {
            return false;
        }
        foreach ($members as $name => $value) {
            $mark = self::CALL_MEMBERS[(string) $name] ?? null;
            if ($mark === null || ! str_starts_with($value, $mark)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a text part of a stream joins the part before it: both are text, neither carries a signature, and
     * both say the same of `thought`.
     *
     * @param Piece $before
     * @param array<array-key, string> $members
     */
    private static function joins(#[SensitiveParameter] array $before, #[SensitiveParameter] array $members): bool
    {
        return $before['text'] !== null
            && ! isset($before['members']['thoughtSignature'])
            && ! isset($members['thoughtSignature'])
            && ($before['members']['thought'] ?? 'false') === ($members['thought'] ?? 'false');
    }
}
