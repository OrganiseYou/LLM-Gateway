<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared;

use Closure;
use Error;
use Exception;
use JsonException;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Exceptions\InvalidKeywordException;
use Opis\JsonSchema\Exceptions\SchemaException;
use Opis\JsonSchema\Exceptions\UnresolvedReferenceException;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use Opis\String\UnicodeString;
use Opis\Uri\Uri;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\LoopGuardValidator;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\SchemaCheckTooCostly;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\SchemaReferenceLoop;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\StructuredIssue;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Contract\StructuredResult;
use ReflectionClass;
use SensitiveParameter;
use stdClass;
use Throwable;
use TypeError;
use ValueError;

/**
 * Decodes the text of an answer and checks it against the JSON Schema of the response format.
 *
 * An unusable answer is returned with its reasons, never thrown. The one thing thrown is a schema the validator
 * cannot use: that is the schema of the caller being wrong, not the answer.
 *
 * Nothing is kept between two calls: the validator is built for one check and dropped. The validator gets no way
 * to fetch a schema, so a reference to another document is never followed: no connection is opened and no file is
 * read, whatever the schema says.
 *
 * What an answer can cost is bounded: it may be nested 64 levels deep, at most 100 mismatches are reported, and
 * every mismatch is looked for only when the schema holds no reference and the answer holds at most 5000 values;
 * otherwise the check stops at the first mismatch, which keeps its work in step with the size of the answer. A
 * schema that refers back to itself is refused as rejected. A check that would take more references or more memory
 * than one check may use is stopped, and the answer is returned with the one reason that it could not be checked.
 * Neither ends in a process that hangs or runs out of memory.
 */
final readonly class StructuredAnswerChecker
{
    /** The trace id of the error for a wrong schema; the adapter base replaces it with the one of the call. */
    private const string NO_TRACE_ID = 'not-set';

    private const string SCHEMA_NOT_VALID = 'the response schema is not a valid JSON Schema';

    /** The whitespace of JSON. Nothing else is trimmed: no byte order mark, no other character. */
    private const string WHITESPACE = " \t\n\r";

    /** How many objects and lists of an answer may lie inside each other. The memory of a check grows with the
     *  square of this for a schema that refers to itself. */
    private const int MAX_DEPTH = 64;

    /** How many mismatches are reported; the validator collects one more than this for one object or one list. */
    private const int MAX_ISSUES = 100;

    /** An answer with more values than this (objects, lists, texts, numbers, …) is checked to its first mismatch. */
    private const int MAX_VALUES = 5000;

    /** The references one run of the validator may follow: this many, and this many more for every value of the
     *  answer. A schema whose work doubles with every level of the answer runs into it. */
    private const int FOLLOWS = 10_000;

    private const int FOLLOWS_PER_VALUE = 50;

    /** How much the memory in use may grow during one check, in bytes. */
    private const int MAX_MEMORY = 32 * 1024 * 1024;

    /** The one reason of an answer whose check went over the limits also when it stops at the first mismatch. */
    private const string NOT_CHECKED = 'could not be checked against the schema within the limits of one check';

    /** The keywords that make a schema refer to a schema. */
    private const array REFERENCES = ['$ref', '$dynamicRef', '$recursiveRef'];

    /** The keywords for which the validator must keep track of what every branch of a schema looked at. */
    private const array UNEVALUATED = ['unevaluatedProperties', 'unevaluatedItems'];

    /** The keywords whose value is a map from a name to a schema. */
    private const array SCHEMA_MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas', 'dependencies'];

    /** The keywords whose value is one schema. */
    private const array SCHEMAS = [
        'items', 'additionalItems', 'additionalProperties', 'contains', 'propertyNames', 'not', 'if', 'then', 'else',
        'unevaluatedItems', 'unevaluatedProperties', 'contentSchema',
    ];

    /** The keywords whose value is a list of schemas. */
    private const array SCHEMA_LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems', 'items'];

    /**
     * The steps, in this order:
     *
     * 1. The text is trimmed; when it is wrapped in one Markdown code fence, what is inside is taken.
     * 2. The finish reason is the token limit: one issue "cut off at the token limit"; the data is the decoded
     *    object when the text happens to decode, else null.
     * 3. The text is not JSON, or is nested deeper than 64 levels: one issue "not JSON: …", no data.
     * 4. The JSON is not an object: one issue at "/", "must be an object", no data.
     * 5. The object is checked against the schema: one issue per mismatch, with its JSON pointer, in the order of
     *    the validator, at most 100 and then one last issue that says so. The data is the decoded object, also
     *    when it does not match. A check that goes over its limits gives one issue at "/", "could not be checked
     *    against the schema within the limits of one check", with the data.
     *
     * The JSON is never repaired: text around it, a comment or a comma too many makes it "not JSON".
     *
     * @param string $text the text of the answer, or the raw arguments of the tool call that carried it
     * @throws GatewayException rejected, when the validator cannot use the schema. The fault is found only in
     *                          the part of the schema this answer reaches, and only after steps 2 to 4
     */
    public function check(
        #[SensitiveParameter]
        string $text,
        ResponseFormat $format,
        FinishReason $finishReason,
    ): StructuredResult {
        $json = self::withoutFence($text);

        $isJson = true;
        $decoded = null;
        $decoderSays = '';
        try {
            // The decoder counts the value inside the innermost object or list as a level of its own.
            $decoded = json_decode($json, false, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException $refused) {
            $isJson = false;
            $decoderSays = $refused->getCode() === JSON_ERROR_DEPTH
                ? sprintf('nested deeper than %d levels', self::MAX_DEPTH)
                : $refused->getMessage();
            unset($refused);
        }

        if ($finishReason === FinishReason::Length) {
            return new StructuredResult(
                $decoded instanceof stdClass ? self::asArray($json) : null,
                [new StructuredIssue(StructuredIssueKind::CutOff, 'cut off at the token limit', null)],
            );
        }

        if (! $isJson) {
            return new StructuredResult(null, [
                new StructuredIssue(StructuredIssueKind::NotJson, 'not JSON: ' . ($decoderSays === '' ? 'it cannot be decoded' : $decoderSays), null),
            ]);
        }

        if (! $decoded instanceof stdClass) {
            return new StructuredResult(null, [
                new StructuredIssue(StructuredIssueKind::SchemaMismatch, 'must be an object', '/'),
            ]);
        }

        return new StructuredResult(self::asArray($json), self::mismatches($decoded, $format->schema));
    }

    /**
     * The text without the whitespace around it and, when all of it is one Markdown code fence, without that fence.
     *
     * A fence is a first line of three or more backticks or tildes with an optional language tag, and a last line
     * that is the same run of backticks or tildes. Text before or after the fence, a fence that is not closed and
     * backticks on the same line as the JSON are left as they are.
     */
    private static function withoutFence(#[SensitiveParameter] string $text): string
    {
        $text = trim($text, self::WHITESPACE);

        $mark = match (true) {
            str_starts_with($text, '```') => '`',
            str_starts_with($text, '~~~') => '~',
            default => null,
        };
        if ($mark === null) {
            return $text;
        }

        $length = strspn($text, $mark);
        $firstLineEnds = strpos($text, "\n");
        if ($firstLineEnds === false) {
            return $text;
        }

        $tag = substr($text, $length, $firstLineEnds - $length);
        $closing = "\n" . str_repeat($mark, $length);
        if (str_contains($tag, $mark) || ! str_ends_with($text, $closing)) {
            return $text;
        }

        $inside = substr($text, $firstLineEnds + 1, max(0, strlen($text) - $firstLineEnds - 1 - $length));

        return trim($inside, self::WHITESPACE);
    }

    /**
     * @return array<string, mixed>
     */
    private static function asArray(#[SensitiveParameter] string $json): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return list<StructuredIssue>
     * @throws GatewayException rejected, when the validator cannot use the schema
     */
    private static function mismatches(#[SensitiveParameter] stdClass $answer, JsonSchema $schema): array
    {
        // A copy of the schema: the caller's is never changed.
        $schemaObject = $schema->toObject();
        self::discriminatorsFirst($schemaObject);

        // With a reference in the schema the work of a validator that looks for every mismatch can double with
        // every level of the answer; a large answer can hold more mismatches than memory. Both are checked the way
        // that stops at the first mismatch. So is an answer for which looking for every mismatch went over the
        // limits of one check: it is checked a second time.
        $values = self::valuesIn($answer);
        $ways = $values > self::MAX_VALUES || self::holdsKeyword($schemaObject, self::REFERENCES) ? [true] : [false, true];
        $tracksUnevaluated = self::holdsKeyword($schemaObject, self::UNEVALUATED);

        $error = null;
        $tooCostly = false;
        foreach ($ways as $firstOnly) {
            [$tooCostly, $error] = self::validated($answer, $schemaObject, $firstOnly, $tracksUnevaluated, $values);
            if (! $tooCostly) {
                break;
            }
        }

        if ($tooCostly) {
            // Whether the answer matches is not known. It is returned as it is, with this one reason.
            return [new StructuredIssue(StructuredIssueKind::SchemaMismatch, self::NOT_CHECKED, '/')];
        }
        if ($error === null) {
            return [];
        }

        $leaves = self::leaves($error, self::MAX_ISSUES + 1);
        $formatter = new ErrorFormatter();
        $issues = [];
        foreach (array_slice($leaves, 0, self::MAX_ISSUES) as $leaf) {
            $message = $formatter->formatErrorMessage($leaf);
            $issues[] = new StructuredIssue(
                StructuredIssueKind::SchemaMismatch,
                $message === '' ? 'does not match the schema' : $message,
                $formatter->formatErrorKey($leaf),
            );
        }
        if (count($leaves) > self::MAX_ISSUES) {
            $issues[] = new StructuredIssue(
                StructuredIssueKind::SchemaMismatch,
                sprintf('more mismatches are not listed: only the first %d are reported', self::MAX_ISSUES),
                '/',
            );
        }

        return $issues;
    }

    /**
     * One run of the validator.
     *
     * @return array{bool, ValidationError|null} whether the run went over the limits of one check, and else the
     *                                           error of the validator, or null when the answer matches
     * @throws GatewayException rejected, when the validator cannot use the schema
     */
    private static function validated(
        #[SensitiveParameter]
        stdClass $answer,
        stdClass $schema,
        bool $firstOnly,
        bool $tracksUnevaluated,
        int $values,
    ): array {
        $validator = new LoopGuardValidator(
            self::loader($tracksUnevaluated),
            // One more than is reported, so that it shows when there are more.
            $firstOnly ? 1 : self::MAX_ISSUES + 1,
            $firstOnly,
            self::FOLLOWS + self::FOLLOWS_PER_VALUE * $values,
            self::MAX_MEMORY,
        );

        $error = null;
        $instead = null;
        $tooCostly = false;

        // The handler of the host, read without changing it.
        $ofHost = set_error_handler(null);
        restore_error_handler();

        set_error_handler(self::quietFor(self::foldersOfValidator(), $ofHost));
        try {
            $error = $validator->validate($answer, $schema)->error();
        } catch (SchemaCheckTooCostly) {
            $tooCostly = true;
        } catch (Throwable $thrown) {
            // Its text and its trace can hold paths of the host and the answer: nothing of it is kept or chained.
            $instead = self::insteadOf($thrown, self::foldersOfValidator());
            unset($thrown);
        } finally {
            restore_error_handler();
        }
        if ($instead !== null) {
            throw $instead;
        }

        return [$tooCostly, $error];
    }

    /**
     * A loader of standard JSON Schema that can fetch nothing: it has no resolver, so a reference to a schema
     * outside the one it was given is not resolved. The additions of the validator that are no JSON Schema are
     * switched off: filters, mappers, URI templates, globals, slots, pragmas, "$data", the filling in of defaults
     * (it would change the answer) and the decoding of content (it would read the file type database of PHP).
     *
     * @param bool $tracksUnevaluated whether the schema uses "unevaluatedProperties" or "unevaluatedItems". Only
     *                                then does the validator keep track of what each branch looked at; with that
     *                                on it tries every branch of "anyOf" also after one matched, and a schema
     *                                with two branches that both refer back doubles its work with every level
     */
    private static function loader(bool $tracksUnevaluated): SchemaLoader
    {
        $parser = new SchemaParser([], [
            'allowFilters' => false,
            'allowMappers' => false,
            'allowTemplates' => false,
            'allowGlobals' => false,
            'allowDefaults' => false,
            'allowSlots' => false,
            'allowPragmas' => false,
            'allowDataKeyword' => false,
            'allowUnevaluated' => $tracksUnevaluated,
            'decodeContent' => false,
        ]);

        return new SchemaLoader($parser, null, false);
    }

    /**
     * Whether one of the names stands anywhere in the schema as the name of a member. A property of the answer
     * that happens to have such a name counts too: the check is then only more careful than it had to be.
     *
     * @param list<string> $keywords
     */
    private static function holdsKeyword(mixed $schema, array $keywords): bool
    {
        if ($schema instanceof stdClass) {
            $schema = get_object_vars($schema);
            foreach ($keywords as $keyword) {
                if (array_key_exists($keyword, $schema)) {
                    return true;
                }
            }
        }
        if (! is_array($schema)) {
            return false;
        }

        foreach ($schema as $part) {
            if (self::holdsKeyword($part, $keywords)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Puts, in every "properties" of the schema, the properties with "const" or "enum" before the others.
     *
     * The validator checks the properties in the order of the schema. A union of object types that tell
     * themselves apart by one property (a kind) is then refused by the wrong branches at that property, before a
     * branch goes into the lists of the answer: with the kind listed after a list that refers back, every wrong
     * branch walks the whole answer below it first, and the work multiplies with every level. The order of the
     * properties has no meaning in JSON Schema; it only decides the order in which mismatches are found.
     *
     * Only the places of a schema where a schema stands are walked, so a value of "enum", "const", "default" or
     * "examples", or a keyword the walk does not know, is left as it is.
     */
    private static function discriminatorsFirst(mixed $schema): void
    {
        if (! $schema instanceof stdClass) {
            return;
        }

        foreach (get_object_vars($schema) as $keyword => $value) {
            $keyword = (string) $keyword;

            if ($value instanceof stdClass && in_array($keyword, self::SCHEMA_MAPS, true)) {
                $members = get_object_vars($value);
                if ($keyword === 'properties') {
                    $first = [];
                    $rest = [];
                    foreach ($members as $name => $member) {
                        if ($member instanceof stdClass && (property_exists($member, 'const') || property_exists($member, 'enum'))) {
                            $first[$name] = $member;
                        } else {
                            $rest[$name] = $member;
                        }
                    }
                    if ($first !== [] && $rest !== []) {
                        $schema->properties = (object) ($first + $rest);
                    }
                }
                foreach ($members as $member) {
                    self::discriminatorsFirst($member);
                }
            } elseif ($value instanceof stdClass && in_array($keyword, self::SCHEMAS, true)) {
                self::discriminatorsFirst($value);
            } elseif (is_array($value) && in_array($keyword, self::SCHEMA_LISTS, true)) {
                foreach ($value as $member) {
                    self::discriminatorsFirst($member);
                }
            }
        }
    }

    /**
     * How many values the answer holds: every object, list, text, number, true, false and null.
     */
    private static function valuesIn(#[SensitiveParameter] stdClass $answer): int
    {
        $seen = 0;
        $open = [$answer];
        while ($open !== []) {
            $value = array_pop($open);
            $seen++;
            if ($value instanceof stdClass) {
                $value = get_object_vars($value);
            }
            if (is_array($value) && $value !== []) {
                array_push($open, ...array_values($value));
            }
        }

        return $seen;
    }

    /**
     * The first errors without errors under them, in the order of the validator.
     *
     * @return list<ValidationError> at most $limit
     */
    private static function leaves(ValidationError $error, int $limit): array
    {
        $leaves = [];
        $open = [$error];
        while ($open !== [] && count($leaves) < $limit) {
            $one = array_pop($open);
            $under = $one->subErrors();
            if ($under === []) {
                $leaves[] = $one;

                continue;
            }
            foreach (array_reverse($under) as $child) {
                if ($child instanceof ValidationError) {
                    $open[] = $child;
                }
            }
        }

        return $leaves;
    }

    /**
     * The folders of the validator and of the two packages it is built on, found from where their classes are.
     *
     * @return list<string> each with a separator at its end
     */
    private static function foldersOfValidator(): array
    {
        $folders = [];
        foreach ([Validator::class, Uri::class, UnicodeString::class] as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            if ($file !== false) {
                $folders[] = dirname($file) . DIRECTORY_SEPARATOR;
            }
        }

        return $folders;
    }

    /**
     * @param list<string> $folders
     */
    private static function isUnder(array $folders, string $file): bool
    {
        foreach ($folders as $folder) {
            if (str_starts_with($file, $folder)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The error handler for the time of one check. A warning or a deprecation raised in the code of the validator
     * (a pattern that does not compile, a number beyond every float) is no result and is swallowed: a host that
     * turns warnings into exceptions would otherwise see an answer of the model end as an error. Everything raised
     * anywhere else goes to the handler of the host, or to PHP when the host has none.
     *
     * @param list<string> $folders
     * @return Closure(int, string, string=, int=): bool
     */
    private static function quietFor(array $folders, ?callable $ofHost): Closure
    {
        return static function (int $level, string $message, string $file = '', int $line = 0) use ($folders, $ofHost): bool {
            if (self::isUnder($folders, $file)) {
                return true;
            }

            // PHP does not tell for which levels the host registered its handler, so every level is handed on.
            // The level of error_reporting() is no stand-in for it: PHP calls a handler whatever that level is,
            // and a host can lower it on purpose while its handler is in place (a test runner does).
            return $ofHost === null ? false : $ofHost($level, $message, $file, $line) !== false;
        };
    }

    /**
     * What is thrown in place of what the validator threw.
     *
     * Its own exceptions, the loop the guard found, and a type or value error raised in its own code (it raises
     * those for a keyword of a wrong type) are the schema being wrong: rejected. Any other error is a fault in
     * code, not a failure of the schema: a new plain error with a fixed text, as the adapter base gives for a fault
     * in an adapter. An exception that is not of the validator can only come from the error handler of the host,
     * and passes as it is.
     *
     * @param list<string> $folders
     */
    private static function insteadOf(#[SensitiveParameter] Throwable $thrown, array $folders): Throwable
    {
        $ofValidator = self::isUnder($folders, $thrown->getFile());

        $detail = match (true) {
            $thrown instanceof SchemaReferenceLoop => self::SCHEMA_NOT_VALID . ': a reference in it leads back to itself',
            $thrown instanceof UnresolvedReferenceException => self::SCHEMA_NOT_VALID . ': a reference in it cannot be resolved, and no other schema is ever fetched',
            $thrown instanceof InvalidKeywordException => self::SCHEMA_NOT_VALID . self::keywordIn($thrown),
            $thrown instanceof SchemaException => self::SCHEMA_NOT_VALID,
            $ofValidator && ($thrown instanceof TypeError || $thrown instanceof ValueError || $thrown instanceof Exception) => self::SCHEMA_NOT_VALID,
            default => null,
        };
        if ($detail !== null) {
            return new GatewayException(ErrorReason::Rejected, self::NO_TRACE_ID, null, null, null, 0, $detail);
        }

        if ($thrown instanceof Error) {
            // An anonymous class carries the path of its file after a null byte.
            $class = strstr($thrown::class, "\0", true);

            return new Error(sprintf(
                'The structured check failed with an error of class %s, thrown in %s on line %d.',
                $class === false ? $thrown::class : $class,
                basename($thrown->getFile()),
                $thrown->getLine(),
            ));
        }

        return $thrown;
    }

    /**
     * The keyword the validator names, when it is a plain word: never a value of the schema.
     */
    private static function keywordIn(InvalidKeywordException $thrown): string
    {
        $keyword = $thrown->keyword();

        return strlen($keyword) <= 40 && ctype_alpha(ltrim($keyword, '$'))
            ? ': the keyword ' . $keyword . ' is not valid'
            : '';
    }
}
