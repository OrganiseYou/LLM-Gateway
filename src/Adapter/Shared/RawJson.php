<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared;

use Generator;
use SensitiveParameter;

/**
 * Reads JSON as the text it is, without decoding it: the text of one value inside a larger text, the members of an
 * object and the items of a list, each as its own JSON text.
 *
 * PHP's decoder gives values, not text: decoded to arrays an empty object is an empty list, a whole number too
 * large for PHP is a float, and `1.0` and `1e2` are written again in another spelling. Data that must go back to a
 * server as it came is therefore cut out of the body as text.
 *
 * Every method expects text that PHP's decoder accepts, or a value cut out of such text by this class; for any
 * other text it gives null or a text that the next decoder refuses. Nothing here throws, and nothing loops over
 * more than the bytes it was given.
 *
 * members() and items() make one string per child, and decoding takes many times the size of a text in memory.
 * Text that a server or a stored message chose is therefore measured first, as text: its bytes, and with
 * containers() its objects and lists. at() makes no string but the one it gives.
 */
final readonly class RawJson
{
    private const string WHITESPACE = " \t\n\r";

    /** A string with as few escapes as JSON allows: also the two line ends U+2028 and U+2029 are written as they are. */
    private const int STRING = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

    /**
     * The JSON text of the value at a path, or null when there is none: a name for a member of an object, a number
     * for an item of a list. Of a name that an object holds twice the last counts, as for PHP's decoder. The text
     * is walked in place: no child but the one that is asked for is copied.
     *
     * @param list<int|string> $path
     */
    public static function at(#[SensitiveParameter] string $json, array $path): ?string
    {
        $from = strspn($json, self::WHITESPACE);
        $to = self::endOfValue($json, $from);
        foreach ($path as $step) {
            if ($to === null) {
                return null;
            }

            $found = null;
            $position = 0;
            foreach (self::children($json, $from, is_int($step) ? '[' : '{') as [$name, $start, $end]) {
                if (is_int($step)) {
                    if ($position++ === $step) {
                        $found = [$start, $end];

                        break;
                    }
                } elseif (self::isName($json, $name, $step)) {
                    // Not the first: the last of a name that is written twice.
                    $found = [$start, $end];
                }
            }
            if ($found === null) {
                return null;
            }
            [$from, $to] = $found;
        }

        return $to === null ? null : substr($json, $from, $to - $from);
    }

    /**
     * The members of an object: the JSON text of each value under its name, in the order they are written. A name
     * that is written twice has its last value and stands where that last one is written. Null when the text is
     * no object.
     *
     * @return array<array-key, string>|null
     */
    public static function members(#[SensitiveParameter] string $json): ?array
    {
        $children = self::children($json, strspn($json, self::WHITESPACE), '{');
        $members = [];
        foreach ($children as [$name, $start, $end]) {
            $decoded = $name === null ? null : json_decode(substr($json, $name[0], $name[1] - $name[0]));
            if (! is_string($decoded)) {
                return null;
            }
            // A name that came before is given its new value and moves to the end: the last one counts.
            unset($members[$decoded]);
            $members[$decoded] = substr($json, $start, $end - $start);
        }

        return $children->getReturn() ? $members : null;
    }

    /**
     * The items of a list, each as its JSON text. Null when the text is no list.
     *
     * @return list<string>|null
     */
    public static function items(#[SensitiveParameter] string $json): ?array
    {
        $children = self::children($json, strspn($json, self::WHITESPACE), '[');
        $items = [];
        foreach ($children as [, $start, $end]) {
            $items[] = substr($json, $start, $end - $start);
        }

        return $children->getReturn() ? $items : null;
    }

    /**
     * The same JSON in one spelling: no whitespace between its parts, and every string written with as few escapes
     * as JSON allows. Numbers, `true`, `false`, `null`, `{}` and `[]` stay byte for byte as they are. Every value is
     * the value it was: of a string all spellings are the same string.
     *
     * One spelling for strings is what lets a search of the text find a word a server wrote with escapes.
     */
    public static function plain(#[SensitiveParameter] string $json): ?string
    {
        $plain = '';
        $length = strlen($json);
        $at = 0;
        while ($at < $length) {
            if ($json[$at] === '"') {
                $end = self::endOfString($json, $at);
                $string = $end === null ? null : json_decode(substr($json, $at, $end - $at));
                $written = is_string($string) ? json_encode($string, self::STRING) : false;
                if ($end === null || $written === false) {
                    return null;
                }
                $plain .= $written;
                $at = $end;

                continue;
            }

            $run = strcspn($json, '"' . self::WHITESPACE, $at);
            $plain .= substr($json, $at, $run);
            $at += $run + strspn($json, self::WHITESPACE, $at + $run);
        }

        return $plain;
    }

    /**
     * A string as JSON text, in the spelling of plain().
     */
    public static function string(#[SensitiveParameter] string $text): string
    {
        $json = json_encode($text, self::STRING | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? '""' : $json;
    }

    /**
     * An object written from the JSON text of each member.
     *
     * @param array<array-key, string> $members name => JSON text
     */
    public static function object(#[SensitiveParameter] array $members): string
    {
        $written = [];
        foreach ($members as $name => $value) {
            $written[] = self::string((string) $name) . ':' . $value;
        }

        return '{' . implode(',', $written) . '}';
    }

    /**
     * A list written from the JSON text of each item.
     *
     * @param list<string> $items
     */
    public static function list(#[SensitiveParameter] array $items): string
    {
        return '[' . implode(',', $items) . ']';
    }

    /**
     * How many objects and lists a text holds, counted outside its strings by walking its bytes once; the count
     * stops one above the limit. A string that never ends is no JSON and counts as over the limit. No regular
     * expression: its limits are settings of PHP.
     *
     * @param int $limit the count is not carried on beyond this number plus one
     */
    public static function containers(#[SensitiveParameter] string $json, int $limit): int
    {
        $length = strlen($json);
        $containers = 0;
        $at = 0;
        while ($containers <= $limit) {
            $at += strcspn($json, '"[{', $at);
            if ($at >= $length) {
                return $containers;
            }
            if ($json[$at] !== '"') {
                $containers++;
                $at++;

                continue;
            }
            $end = self::endOfString($json, $at);
            if ($end === null) {
                return $limit + 1;
            }
            $at = $end;
        }

        return $containers;
    }

    /**
     * The children of the object or the list that starts at a place, one by one and without a copy: where the name
     * is written (null in a list) and where the value starts and ends. The generator returns true when it walked
     * to the closing mark, and false when the text there is no such object or list.
     *
     * @param string $open `{` for an object, `[` for a list
     * @return Generator<int, array{array{int, int}|null, int, int}, mixed, bool>
     */
    private static function children(#[SensitiveParameter] string $json, int $at, string $open): Generator
    {
        $close = $open === '{' ? '}' : ']';
        $length = strlen($json);
        if ($at >= $length || $json[$at] !== $open) {
            return false;
        }
        $at++;

        while (true) {
            $at += strspn($json, self::WHITESPACE, $at);
            if ($at >= $length) {
                return false;
            }
            if ($json[$at] === $close) {
                return true;
            }
            if ($json[$at] === ',') {
                $at++;

                continue;
            }

            $name = null;
            if ($open === '{') {
                $end = self::endOfString($json, $at);
                if ($end === null) {
                    return false;
                }
                $name = [$at, $end];
                $at = $end + strspn($json, self::WHITESPACE, $end);
                if ($at >= $length || $json[$at] !== ':') {
                    return false;
                }
                $at++;
                $at += strspn($json, self::WHITESPACE, $at);
            }

            $end = self::endOfValue($json, $at);
            if ($end === null) {
                return false;
            }
            yield [$name, $at, $end];
            $at = $end;
        }
    }

    /**
     * Whether the name written at a place is this name. A name without an escape is compared as it is written;
     * only one with a backslash is decoded.
     *
     * @param array{int, int}|null $written where the name stands, with its quotes
     */
    private static function isName(#[SensitiveParameter] string $json, ?array $written, string $name): bool
    {
        if ($written === null) {
            return false;
        }

        $length = $written[1] - $written[0];
        if (strcspn($json, "\x5c", $written[0], $length) === $length) {
            return $length === strlen($name) + 2 && substr_compare($json, $name, $written[0] + 1, $length - 2) === 0;
        }

        return json_decode(substr($json, $written[0], $length)) === $name;
    }

    /**
     * Where the value that starts here ends: the place after its last byte. Null when it does not end.
     */
    private static function endOfValue(#[SensitiveParameter] string $json, int $at): ?int
    {
        $length = strlen($json);
        if ($at >= $length) {
            return null;
        }
        if ($json[$at] === '"') {
            return self::endOfString($json, $at);
        }
        if ($json[$at] !== '{' && $json[$at] !== '[') {
            // A number or a word: it ends where the next mark or white space begins.
            $run = strcspn($json, ',]}' . self::WHITESPACE, $at);

            return $run === 0 ? null : $at + $run;
        }

        $depth = 0;
        while ($at < $length) {
            $at += strcspn($json, '"{}[]', $at);
            if ($at >= $length) {
                return null;
            }
            if ($json[$at] === '"') {
                $end = self::endOfString($json, $at);
                if ($end === null) {
                    return null;
                }
                $at = $end;

                continue;
            }
            $depth += $json[$at] === '{' || $json[$at] === '[' ? 1 : -1;
            $at++;
            if ($depth === 0) {
                return $at;
            }
        }

        return null;
    }

    /**
     * Where the string that starts here ends: the place after its closing quote. Null when this is no string or
     * it does not end.
     */
    private static function endOfString(#[SensitiveParameter] string $json, int $at): ?int
    {
        $length = strlen($json);
        if ($at >= $length || $json[$at] !== '"') {
            return null;
        }
        $at++;
        while ($at < $length) {
            $at += strcspn($json, '"\\', $at);
            if ($at >= $length) {
                return null;
            }
            if ($json[$at] === '"') {
                return $at + 1;
            }
            // A backslash and the byte it escapes.
            $at += 2;
        }

        return null;
    }
}
