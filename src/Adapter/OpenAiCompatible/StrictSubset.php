<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

use OrganiseYou\LlmGateway\Adapter\Shared\SchemaShape;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use SensitiveParameter;
use stdClass;

/**
 * Whether the schema of a caller already fits the subset of JSON Schema that a server of the chat-completions format
 * takes with `strict: true`. The adapter sends that flag as true only then (`json_schema_strict: when_fits`), and
 * never changes the schema to make it fit.
 *
 * The subset is an allow-list: a schema fits only when every keyword it uses, every type, every format and every
 * reference is one the provider's page names as accepted. Anything the page does not name (an annotation such as
 * `description` too) means the flag is not sent as true: it is a promise, and an unknown keyword may break it.
 *
 * Two subsets are documented. The standard one: `docs/providers/openai.md`, "Structured output: the schema subset".
 * The narrow one: `docs/providers/azure-openai.md`, "What a request can do"; it is the standard one without the
 * limits on a string, a number and a list that its page refuses, and with lower limits on size.
 */
final readonly class StrictSubset
{
    /** The keywords that give a schema its shape; the same in both subsets. */
    private const array SHAPE = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'const', 'anyOf', '$defs', '$ref'];

    /** The limits the standard subset accepts: on a string, on a number and on a list. */
    private const array LIMITS = ['pattern', 'format', 'multipleOf', 'maximum', 'exclusiveMaximum', 'minimum', 'exclusiveMinimum', 'minItems', 'maxItems'];

    /** Of those, what the narrow subset still accepts: its page refuses the others. */
    private const array LIMITS_WHEN_NARROW = ['exclusiveMaximum', 'exclusiveMinimum'];

    private const array TYPES = ['string', 'number', 'boolean', 'integer', 'object', 'array', 'null'];

    private const array FORMATS = ['date-time', 'time', 'date', 'duration', 'email', 'hostname', 'ipv4', 'ipv6', 'uuid'];

    private const int DEPTH = 10;

    private const int DEPTH_WHEN_NARROW = 5;

    private const int PROPERTIES = 5000;

    private const int PROPERTIES_WHEN_NARROW = 100;

    /** The most characters of all property names, definition names, enum values and const values together. */
    private const int CHARACTERS_OF_NAMES_AND_VALUES = 120_000;

    /** The most enum values in the whole schema. */
    private const int ENUM_VALUES = 1000;

    /** One enum of strings with more values than this … */
    private const int LARGE_ENUM = 250;

    /** … may hold this many characters at most. */
    private const int CHARACTERS_OF_A_LARGE_ENUM = 15_000;

    /**
     * @param bool $narrow true: the narrow subset; false: the standard one
     */
    public function fits(JsonSchema $schema, bool $narrow): bool
    {
        $shape = new SchemaShape();
        $root = $schema->toObject();

        // The root is an object and may not be a choice between schemas.
        if (property_exists($root, 'anyOf')) {
            return false;
        }
        if (! self::usesOnlyWhatIsAccepted($root, [...self::SHAPE, ...($narrow ? self::LIMITS_WHEN_NARROW : self::LIMITS)])) {
            return false;
        }
        if (! $shape->everyPropertyIsRequired($schema) || ! $shape->everyObjectIsClosed($schema)) {
            return false;
        }
        if ($shape->depth($schema) > ($narrow ? self::DEPTH_WHEN_NARROW : self::DEPTH)) {
            return false;
        }
        if ($shape->propertyCount($schema) > ($narrow ? self::PROPERTIES_WHEN_NARROW : self::PROPERTIES)) {
            return false;
        }

        return self::isSmallEnough($shape, $schema);
    }

    /**
     * Whether every schema in the schema uses only accepted keywords, with values in the accepted form. The walk
     * follows the accepted keywords that hold schemas, and those alone: a schema under any other keyword is under
     * a keyword that is refused already.
     *
     * @param list<string> $accepted
     */
    private static function usesOnlyWhatIsAccepted(stdClass $root, array $accepted): bool
    {
        $open = [$root];
        while ($open !== []) {
            $schema = array_pop($open);
            $members = get_object_vars($schema);

            foreach ($members as $keyword => $value) {
                if (! in_array((string) $keyword, $accepted, true)) {
                    return false;
                }
            }
            if (array_key_exists('type', $members)) {
                foreach (is_array($members['type']) ? $members['type'] : [$members['type']] as $type) {
                    if (! in_array($type, self::TYPES, true)) {
                        return false;
                    }
                }
            }
            if (array_key_exists('format', $members) && ! in_array($members['format'], self::FORMATS, true)) {
                return false;
            }
            // A reference to the schema itself or to one of its definitions; never to another document.
            if (array_key_exists('$ref', $members) && (! is_string($members['$ref']) || preg_match('/^#(?:\/\$defs\/[^\/]+)?$/D', $members['$ref']) !== 1)) {
                return false;
            }
            if (array_key_exists('additionalProperties', $members) && $members['additionalProperties'] !== false) {
                return false;
            }
            foreach (['enum', 'required'] as $list) {
                if (array_key_exists($list, $members) && ! is_array($members[$list])) {
                    return false;
                }
            }

            // The schemas below this one.
            $below = [];
            foreach (['properties', '$defs'] as $named) {
                if (array_key_exists($named, $members)) {
                    if (! $members[$named] instanceof stdClass) {
                        return false;
                    }
                    $below = [...$below, ...array_values(get_object_vars($members[$named]))];
                }
            }
            if (array_key_exists('items', $members)) {
                $below[] = $members['items'];
            }
            if (array_key_exists('anyOf', $members)) {
                if (! is_array($members['anyOf']) || $members['anyOf'] === []) {
                    return false;
                }
                $below = [...$below, ...$members['anyOf']];
            }
            foreach ($below as $inner) {
                // A schema that is `true`, `false` or a list is not in the subset.
                if (! $inner instanceof stdClass) {
                    return false;
                }
                $open[] = $inner;
            }
        }

        return true;
    }

    /**
     * The limits on the size of the names and the values of a schema.
     */
    private static function isSmallEnough(SchemaShape $shape, JsonSchema $schema): bool
    {
        $characters = 0;
        foreach (['properties', '$defs'] as $keyword) {
            foreach ($shape->valuesOf($schema, $keyword) as $named) {
                foreach (is_array($named) ? array_keys($named) : [] as $name) {
                    $characters += self::length((string) $name);
                }
            }
        }
        foreach ($shape->valuesOf($schema, 'const') as $value) {
            $characters += self::length($value);
        }

        $enumValues = 0;
        foreach ($shape->valuesOf($schema, 'enum') as $values) {
            if (! is_array($values)) {
                continue;
            }

            $inThisEnum = 0;
            $onlyStrings = true;
            foreach ($values as $value) {
                $inThisEnum += self::length($value);
                $onlyStrings = $onlyStrings && is_string($value);
            }
            if ($onlyStrings && count($values) > self::LARGE_ENUM && $inThisEnum > self::CHARACTERS_OF_A_LARGE_ENUM) {
                return false;
            }

            $enumValues += count($values);
            $characters += $inThisEnum;
        }

        return $enumValues <= self::ENUM_VALUES && $characters <= self::CHARACTERS_OF_NAMES_AND_VALUES;
    }

    /**
     * The characters of a name or a value: of a string as it is, of anything else as it is written in JSON.
     */
    private static function length(#[SensitiveParameter] mixed $value): int
    {
        if (! is_string($value)) {
            $value = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        // Counted without an extension: every byte that does not continue a character begins one.
        return (int) preg_match_all('/[^\x80-\xBF]/', $value);
    }
}
