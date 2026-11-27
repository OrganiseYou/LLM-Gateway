<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use JsonException;
use stdClass;

/**
 * A JSON Schema with root type object, kept as JSON text so an empty object stays an object.
 *
 * Only the root is checked here. Whether the text is a valid JSON Schema is found when it is used.
 */
final readonly class JsonSchema
{
    private const int ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Keywords whose value is a map from a name to a schema. The map is a JSON object whatever its keys are, and
     * an empty array in it is the empty schema.
     */
    private const array SCHEMA_MAP_KEYWORDS = [
        'properties',
        'patternProperties',
        '$defs',
        'definitions',
        'dependentSchemas',
    ];

    /**
     * Keywords whose value is a JSON object that does not hold only schemas: its entries are left as they are,
     * except that an entry of "dependencies" with names as keys is a schema.
     */
    private const array PLAIN_MAP_KEYWORDS = [
        'dependentRequired',
        'dependencies',
        '$vocabulary',
    ];

    /**
     * Keywords whose value is data, not a schema: nothing under them is touched.
     */
    private const array DATA_KEYWORDS = [
        'enum',
        'const',
        'default',
        'examples',
    ];

    private const int MAX_DEPTH = 512;

    /**
     * @param string $json the schema in canonical JSON text
     */
    private function __construct(public string $json) {}

    public static function fromJson(string $json): self
    {
        try {
            $root = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidValue('The schema is not valid JSON.');
        }

        if (! $root instanceof stdClass) {
            throw new InvalidValue('The root of the schema must be a JSON object.');
        }
        if (! property_exists($root, 'type') || $root->type !== 'object') {
            throw new InvalidValue('The root of the schema must have "type": "object".');
        }

        try {
            return new self(json_encode($root, self::ENCODE_FLAGS));
        } catch (JsonException) {
            throw new InvalidValue('The schema cannot be encoded as JSON.');
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    public static function fromArray(array $schema): self
    {
        if ($schema === []) {
            return self::fromJson('{}');
        }
        if (self::isList($schema)) {
            throw new InvalidValue('The schema must be a JSON object, not a list.');
        }

        try {
            $json = json_encode(self::schema($schema, 1), self::ENCODE_FLAGS);
        } catch (JsonException) {
            throw new InvalidValue('The schema cannot be encoded as JSON.');
        }

        return self::fromJson($json);
    }

    /**
     * The schema as a PHP array, for reading it.
     *
     * An empty JSON object comes back as an empty array, which encodes as a JSON list. Build a request body from
     * toObject() or from the JSON text, never from this array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var array<string, mixed> $schema */
        $schema = json_decode($this->json, true, 512, JSON_THROW_ON_ERROR);

        return $schema;
    }

    /**
     * The form a JSON Schema validator reads.
     */
    public function toObject(): stdClass
    {
        /** @var stdClass $schema */
        $schema = json_decode($this->json, false, 512, JSON_THROW_ON_ERROR);

        return $schema;
    }

    /**
     * @param array<mixed> $value
     */
    private static function isList(array $value): bool
    {
        return array_is_list($value);
    }

    /**
     * @param array<mixed> $schema a schema, or a list of schemas
     * @return array<mixed>
     */
    private static function schema(array $schema, int $depth): array
    {
        self::assertDepth($depth);

        foreach ($schema as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $keyword = (string) $key;
            if (in_array($keyword, self::DATA_KEYWORDS, true)) {
                continue;
            }

            $schema[$key] = match (true) {
                in_array($keyword, self::SCHEMA_MAP_KEYWORDS, true) => self::schemaMap($value, $depth + 1),
                in_array($keyword, self::PLAIN_MAP_KEYWORDS, true) => self::plainMap($value, $depth + 1),
                default => self::schema($value, $depth + 1),
            };
        }

        return $schema;
    }

    /**
     * @param array<mixed> $map
     */
    private static function schemaMap(array $map, int $depth): stdClass
    {
        self::assertDepth($depth);

        foreach ($map as $name => $entry) {
            if (is_array($entry)) {
                $map[$name] = $entry === [] ? new stdClass() : self::schema($entry, $depth + 1);
            }
        }

        return (object) $map;
    }

    /**
     * @param array<mixed> $map
     */
    private static function plainMap(array $map, int $depth): stdClass
    {
        self::assertDepth($depth);

        foreach ($map as $name => $entry) {
            if (is_array($entry) && $entry !== [] && ! array_is_list($entry)) {
                $map[$name] = self::schema($entry, $depth + 1);
            }
        }

        return (object) $map;
    }

    private static function assertDepth(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidValue(
                sprintf('The schema is nested deeper than %d levels.', self::MAX_DEPTH),
            );
        }
    }
}
