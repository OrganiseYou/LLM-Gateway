<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared\Internal;

/**
 * The places of a JSON Schema where a schema stands, by the keyword that holds it.
 *
 * Anything else in a schema is data or a keyword the library does not know, and is never walked: the value of
 * "enum", "const", "default" and "examples" above all.
 *
 * @internal one list for the structured-answer checker and the schema helper, so both walk the same places
 */
final readonly class SchemaPlaces
{
    /** The keywords whose value is a map from a name to a schema. */
    public const array MAPS = ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas', 'dependencies'];

    /** The keywords whose value is one schema. */
    public const array SINGLE = [
        'items', 'additionalItems', 'additionalProperties', 'contains', 'propertyNames', 'not', 'if', 'then', 'else',
        'unevaluatedItems', 'unevaluatedProperties', 'contentSchema',
    ];

    /** The keywords whose value is a list of schemas. */
    public const array LISTS = ['allOf', 'anyOf', 'oneOf', 'prefixItems', 'items'];
}
