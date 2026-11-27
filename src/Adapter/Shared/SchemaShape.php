<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared;

use OrganiseYou\LlmGateway\Adapter\Shared\Internal\SchemaPlaces;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use stdClass;

/**
 * Answers questions about the shape of a JSON Schema, and gives a looser copy of one.
 *
 * An adapter uses it to decide how the schema of a response format goes to a server: as it is, as a copy without
 * the keywords the server refuses, or not at all. The lists of keywords are the adapter's own; this class knows
 * none. The caller's schema is never changed: without() and renamed() give a new one.
 *
 * A copy must never be tighter than the caller's schema. Taking a keyword out does not always loosen a schema:
 * ask mayTighten() first, and do not send the copy when it says true.
 *
 * Only the places of a schema where a schema stands are looked at, the same places the structured check walks:
 * the root, and what stands under "properties", "patternProperties", "$defs", "definitions", "dependentSchemas",
 * "dependencies", "items", "prefixItems", "additionalItems", "additionalProperties", "contains", "propertyNames",
 * "not", "if", "then", "else", "allOf", "anyOf", "oneOf", "unevaluatedItems", "unevaluatedProperties" and
 * "contentSchema". So the name of a property is never read as a keyword, and nothing under "enum", "const",
 * "default", "examples" or a keyword that is not in this list is read or changed.
 *
 * "An object" below is a schema whose "type" is "object" or a list that holds "object", or that has no "type" and
 * has "properties", "patternProperties", "additionalProperties" or "required".
 *
 * Nothing is kept between two calls. Every walk ends: a schema is a text of at most 512 levels, a reference is
 * followed to a schema at most once, and no walk uses the call stack of PHP for its depth.
 */
final readonly class SchemaShape
{
    /** The keywords that exist to refer back; a schema that uses one counts as recursive without following it. */
    private const array REFERENCES_BACK = ['$dynamicRef', '$recursiveRef'];

    /** The keywords that make a schema without a "type" one that describes an object. */
    private const array OBJECT_KEYWORDS = ['properties', 'patternProperties', 'additionalProperties', 'required'];

    /** The keywords that decide what a reference means or which rules hold: without one the schema is another. */
    private const array RESOLUTION = [
        '$ref', '$dynamicRef', '$recursiveRef', '$defs', 'definitions', '$id', 'id', '$anchor', '$dynamicAnchor',
        '$recursiveAnchor', '$schema', '$vocabulary',
    ];

    /** The keywords that refuse what no other keyword of the schema looked at. */
    private const array UNEVALUATED = ['unevaluatedProperties', 'unevaluatedItems'];

    /** The keywords that look at properties or items, so that the two above leave those alone. */
    private const array EVALUATING = [
        'properties', 'patternProperties', 'additionalProperties', 'items', 'prefixItems', 'additionalItems', 'contains',
        'allOf', 'anyOf', 'oneOf', 'if', 'then', 'else', 'dependentSchemas', 'dependencies',
    ];

    /**
     * Which of these keywords stand at a place where a schema stands, in the order they were asked, each once.
     *
     * @param array<mixed> $keywords the keywords to look for; an entry that is not a string is not looked at
     * @return list<string>
     */
    public function uses(JsonSchema $schema, array $keywords): array
    {
        $keywords = self::names($keywords);
        $found = [];

        foreach (self::places($schema->toObject()) as [$place]) {
            $members = get_object_vars($place);
            foreach ($keywords as $at => $keyword) {
                if (array_key_exists($keyword, $members)) {
                    $found[$at] = $keyword;
                }
            }
        }
        ksort($found);

        return array_values($found);
    }

    /**
     * A copy of the schema without these keywords, at every place where a schema stands.
     *
     * A keyword goes with all that stands under it: without "not", the schema under "not" is gone too. The rest of
     * the copy is the caller's schema as it was: the same order, an empty object still an object, an empty list
     * still a list.
     *
     * Nothing more is promised. Whether the copy is looser than the caller's schema depends on the keywords and on
     * where they stood: mayTighten() tells.
     *
     * @param array<mixed> $keywords the keywords to take out; an entry that is not a string is not looked at
     * @throws InvalidValue when "type" is taken out: the root of a schema must keep "type": "object"
     */
    public function without(JsonSchema $schema, array $keywords): JsonSchema
    {
        $keywords = self::names($keywords);
        $root = $schema->toObject();

        // Listed before anything is taken out: a place under a keyword that goes is then changed for nothing.
        foreach (self::places($root) as [$place]) {
            foreach ($keywords as $keyword) {
                unset($place->{$keyword});
            }
        }

        return JsonSchema::fromJson(json_encode($root, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * A copy of the schema with a keyword written under another name, at every place where a schema stands.
     *
     * The value of the keyword and its place among the other keywords stay as they were, and so does the rest of
     * the schema, as with without(). A schema that already has the new name beside the old one is left as it is,
     * with both: uses() on the copy then still gives the old name, and the copy is not what the adapter wanted.
     *
     * Whether the copy is looser depends on the two keywords and on where the keyword stood; for "oneOf" written
     * as "anyOf", mayTighten() tells.
     *
     * @throws InvalidValue when the new name cannot be the name of a member, or when the root loses "type": "object"
     */
    public function renamed(JsonSchema $schema, string $keyword, string $as): JsonSchema
    {
        if (str_starts_with($as, "\0")) {
            throw new InvalidValue('The new name of a keyword cannot start with a NUL byte.');
        }

        $root = $schema->toObject();
        if ($keyword !== $as && ! str_starts_with($keyword, "\0")) {
            foreach (self::places($root) as [$place]) {
                if (! property_exists($place, $keyword) || property_exists($place, $as)) {
                    continue;
                }

                // Every member is taken out and put back in its order: an object keeps the order of writing.
                $members = [];
                foreach (get_object_vars($place) as $name => $value) {
                    $name = (string) $name;
                    $members[] = [$name === $keyword ? $as : $name, $value];
                    unset($place->{$name});
                }
                foreach ($members as [$name, $value]) {
                    $place->{$name} = $value;
                }
            }
        }

        return JsonSchema::fromJson(json_encode($root, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * The value of a keyword at every place where a schema stands and has it, in the order of the schema.
     *
     * For reading: a JSON object comes as an array with its names as keys, so an empty object and an empty list
     * are both an empty array. A value is whatever the caller wrote; nothing says it has the type the keyword asks.
     *
     * @return list<mixed>
     */
    public function valuesOf(JsonSchema $schema, string $keyword): array
    {
        $values = [];
        if (str_starts_with($keyword, "\0")) {
            return $values;
        }

        foreach (self::places($schema->toObject()) as [$place]) {
            if (property_exists($place, $keyword)) {
                $values[] = json_decode(json_encode($place->{$keyword}, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $values;
    }

    /**
     * Whether the schema can refuse an answer the caller's schema allows, after these keywords were taken out
     * with without(), and "oneOf", when it is among them, was taken out or written as "anyOf" with renamed().
     *
     * Taking out a keyword that only narrows ("minimum", "pattern", "not", …) loosens a schema where the keyword
     * stands in the open. It does the opposite where the schema it stands in is turned around or counted:
     *
     * - under "not", under "if", in a branch of "oneOf", and under "contains" beside "maxContains", unless that
     *   keyword is itself among the ones taken out; also in a schema such a place refers to with "$ref";
     * - "minContains" that is not 1 or more, which stands for "none is needed";
     * - "properties" or "patternProperties" beside "additionalProperties", "prefixItems" beside "items", and
     *   "items" beside "additionalItems": what they looked at falls to the keyword beside them;
     * - a keyword that looks at properties or items or holds schemas that do, anywhere in a schema that uses
     *   "unevaluatedProperties" or "unevaluatedItems";
     * - a keyword that decides what a reference means: "$ref", "$dynamicRef", "$recursiveRef", "$defs",
     *   "definitions", "$id", "id", "$anchor", "$dynamicAnchor", "$recursiveAnchor", "$schema", "$vocabulary".
     *
     * Two more cases are true because the copy is then not the one this method reasons about:
     *
     * - "oneOf" is among the keywords and a schema has "anyOf" beside "oneOf": renamed() leaves that schema as it
     *   is, so its branches stay branches of "oneOf";
     * - a "$ref" leads into a schema that is taken out, or that stands under "oneOf" when "oneOf" is among the
     *   keywords: the copy then refers to nothing.
     *
     * When in doubt the answer is true, as soon as one of the keywords is used anywhere: a schema with
     * "$dynamicRef" or "$recursiveRef" and a turned place; a turned place with a reference that cannot be followed
     * here; and one with a reference that can be read in two ways, because a schema has "id", an "$id" is written
     * relative to another, an address or an "$anchor" stands twice, or a pointer leads elsewhere from the nearest
     * "$id" than from the root. False means: none of the above. True means: do not send the copy.
     *
     * @param array<mixed> $keywords the keywords that are taken out; an entry that is not a string is not looked at
     */
    public function mayTighten(JsonSchema $schema, array $keywords): bool
    {
        $taken = array_fill_keys(self::names($keywords), true);
        $root = $schema->toObject();
        $places = self::places($root);

        $used = false;
        $refersFreely = false;
        $leftovers = false;
        foreach ($places as [$place]) {
            $members = get_object_vars($place);
            $used = $used || array_intersect_key($members, $taken) !== [];
            if (isset($taken['oneOf']) && array_key_exists('oneOf', $members) && array_key_exists('anyOf', $members)) {
                return true;
            }
            foreach (self::REFERENCES_BACK as $keyword) {
                $refersFreely = $refersFreely || array_key_exists($keyword, $members);
            }
            foreach (self::UNEVALUATED as $keyword) {
                $leftovers = $leftovers || (array_key_exists($keyword, $members) && ! isset($taken[$keyword]));
            }
        }
        if (! $used) {
            return false;
        }

        $turned = [];
        $gone = [];
        foreach ($places as [$place]) {
            foreach (self::RESOLUTION as $keyword) {
                if (self::goes($taken, $place, $keyword)) {
                    return true;
                }
            }
            foreach ($leftovers ? self::EVALUATING : [] as $keyword) {
                if (self::goes($taken, $place, $keyword)) {
                    return true;
                }
            }

            $least = $place->minContains ?? null;
            if (self::goes($taken, $place, 'minContains') && ! ((is_int($least) || is_float($least)) && $least >= 1)) {
                return true;
            }
            if (self::stays($taken, $place, 'additionalProperties') && (self::goes($taken, $place, 'properties') || self::goes($taken, $place, 'patternProperties'))) {
                return true;
            }
            if ((self::stays($taken, $place, 'items') && self::goes($taken, $place, 'prefixItems')) || (self::stays($taken, $place, 'additionalItems') && self::goes($taken, $place, 'items'))) {
                return true;
            }

            foreach (self::below($place) as [$keyword, $member]) {
                $turns = match ($keyword) {
                    'not', 'if', 'oneOf' => true,
                    'contains' => self::stays($taken, $place, 'maxContains'),
                    default => false,
                };
                if ($turns && ! isset($taken[$keyword])) {
                    $turned[] = $member;
                }
                // A place is listed after the place it stands in, so what is gone takes all below it along.
                if (isset($taken[$keyword]) || isset($gone[spl_object_id($place)])) {
                    $gone[spl_object_id($member)] = true;
                }
            }
        }

        $index = self::index($places);
        $resourceOf = [];
        foreach ($places as [$place, $resource]) {
            $resourceOf[spl_object_id($place)] = $resource;

            $target = $gone === [] ? null : self::referred($place, $resource, $index);
            if ($target !== null && isset($gone[spl_object_id($target)])) {
                return true;
            }
        }
        if ($turned === []) {
            return false;
        }
        if ($refersFreely) {
            return true;
        }
        $doubts = self::doubts($places);

        $seen = [];
        while ($turned !== []) {
            $place = array_pop($turned);
            $id = spl_object_id($place);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            if (array_intersect_key(get_object_vars($place), $taken) !== []) {
                return true;
            }

            foreach (self::below($place) as [$keyword, $member]) {
                if (! isset($taken[$keyword])) {
                    $turned[] = $member;
                }
            }
            $reference = $place->{'$ref'} ?? null;
            if (is_string($reference)) {
                $target = self::referred($place, $resourceOf[$id], $index);
                if ($target === null || ! self::hasOneReading($reference, $resourceOf[$id], $index, $doubts)) {
                    return true;
                }
                $turned[] = $target;
            }
        }

        return false;
    }

    /**
     * Whether a reference leads back to a schema that holds it.
     *
     * True for a "$ref" to the schema it stands in or to one around it, for schemas that refer to each other in a
     * ring, also when nothing uses them, and for every schema that uses "$dynamicRef" or "$recursiveRef". A
     * reference is followed when it names a place in this schema: "#", a JSON pointer ("#/$defs/node"), an
     * "$anchor" ("#node") or the "$id" (or "id") of a schema in it, as it is written. Any other reference is not
     * followed and does not count: another document, a place where no schema stands, and also a reference or an
     * "$id" that is written relative to an address. A schema that is recursive only in such a way is not seen.
     */
    public function isRecursive(JsonSchema $schema): bool
    {
        $root = $schema->toObject();
        $places = self::places($root);

        foreach ($places as [$place]) {
            foreach (self::REFERENCES_BACK as $keyword) {
                if (property_exists($place, $keyword)) {
                    return true;
                }
            }
        }

        return self::chains($root, $places)['loops'];
    }

    /**
     * Whether every name under every "properties" is also in the "required" beside it.
     */
    public function everyPropertyIsRequired(JsonSchema $schema): bool
    {
        foreach (self::places($schema->toObject()) as [$place]) {
            $properties = $place->properties ?? null;
            if (! $properties instanceof stdClass) {
                continue;
            }

            // Compared as keys on both sides: PHP makes the name "0" the key 0 in both lists alike.
            if (array_diff_key(get_object_vars($properties), array_fill_keys(self::required($place), true)) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every object has "additionalProperties": false.
     */
    public function everyObjectIsClosed(JsonSchema $schema): bool
    {
        foreach (self::places($schema->toObject()) as [$place]) {
            if (self::isObject($place) && ($place->additionalProperties ?? null) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every object has at least one name in its "required".
     */
    public function everyObjectHasARequiredProperty(JsonSchema $schema): bool
    {
        foreach (self::places($schema->toObject()) as [$place]) {
            if (self::isObject($place) && self::required($place) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many schemas stand inside one another at the deepest place; the root alone is 1.
     *
     * Every schema counts as a level, also a branch of "anyOf" and a schema under "$defs". A "$ref" that names a
     * place in this schema (see isRecursive()) goes on at that place without being a level itself, so a schema
     * built from definitions is as deep as the answer it describes. A reference that leads back to a schema it
     * stands in is not followed: a recursive schema is as deep as it is written, each ring once.
     */
    public function depth(JsonSchema $schema): int
    {
        $root = $schema->toObject();
        $chains = self::chains($root, self::places($root));

        $depth = [];
        foreach ($chains['order'] as $id) {
            $deepest = 1;
            foreach ($chains['steps'][$id] ?? [] as [$next, $levels]) {
                $deepest = max($deepest, $depth[$next] + $levels);
            }
            $depth[$id] = $deepest;
        }

        return $depth[spl_object_id($root)];
    }

    /**
     * How many names stand under all the "properties" of the schema together, each as often as it is written.
     */
    public function propertyCount(JsonSchema $schema): int
    {
        $count = 0;
        foreach (self::places($schema->toObject()) as [$place]) {
            $properties = $place->properties ?? null;
            if ($properties instanceof stdClass) {
                $count += count(get_object_vars($properties));
            }
        }

        return $count;
    }

    /**
     * Whether the schema has the keyword and loses it.
     *
     * @param array<int|string, true> $taken the keywords that are taken out, as keys
     */
    private static function goes(array $taken, stdClass $place, string $keyword): bool
    {
        return isset($taken[$keyword]) && property_exists($place, $keyword);
    }

    /**
     * Whether the schema has the keyword and keeps it.
     *
     * @param array<int|string, true> $taken the keywords that are taken out, as keys
     */
    private static function stays(array $taken, stdClass $place, string $keyword): bool
    {
        return ! isset($taken[$keyword]) && property_exists($place, $keyword);
    }

    /**
     * The keywords that can stand in a schema, under the place they were given at.
     *
     * A decoded JSON object has no member whose name starts with a NUL byte, and PHP refuses to touch one.
     *
     * @param array<mixed> $keywords
     * @return array<int, string>
     */
    private static function names(array $keywords): array
    {
        $names = [];
        foreach (array_values($keywords) as $at => $keyword) {
            if (is_string($keyword) && ! str_starts_with($keyword, "\0") && ! in_array($keyword, $names, true)) {
                $names[$at] = $keyword;
            }
        }

        return $names;
    }

    /**
     * Every schema of the document, the root first, each with the schema whose "$id" a reference without an
     * address of its own is read against.
     *
     * @return non-empty-list<array{stdClass, stdClass}>
     */
    private static function places(stdClass $root): array
    {
        $places = [[$root, $root]];
        $open = [];
        foreach (array_reverse(self::below($root)) as [, $member]) {
            $open[] = [$member, $root];
        }

        while ($open !== []) {
            [$place, $resource] = array_pop($open);
            $id = self::idOf($place);
            if ($id !== null && $id !== '' && $id[0] !== '#') {
                $resource = $place;
            }
            $places[] = [$place, $resource];

            foreach (array_reverse(self::below($place)) as [, $member]) {
                $open[] = [$member, $resource];
            }
        }

        return $places;
    }

    /**
     * The address a schema gives itself: "$id", or "id" as the oldest drafts wrote it.
     */
    private static function idOf(stdClass $place): ?string
    {
        $id = $place->{'$id'} ?? $place->id ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * The schemas that stand directly in this one, each with the keyword it stands under. A schema that is true
     * or false holds nothing and is left out.
     *
     * @return list<array{string, stdClass}>
     */
    private static function below(stdClass $place): array
    {
        $below = [];

        foreach (get_object_vars($place) as $keyword => $value) {
            $keyword = (string) $keyword;

            if ($value instanceof stdClass && in_array($keyword, SchemaPlaces::MAPS, true)) {
                $members = get_object_vars($value);
            } elseif ($value instanceof stdClass && in_array($keyword, SchemaPlaces::SINGLE, true)) {
                $members = [$value];
            } elseif (is_array($value) && in_array($keyword, SchemaPlaces::LISTS, true)) {
                $members = $value;
            } else {
                continue;
            }

            foreach ($members as $member) {
                if ($member instanceof stdClass) {
                    $below[] = [$keyword, $member];
                }
            }
        }

        return $below;
    }

    private static function isObject(stdClass $place): bool
    {
        if (property_exists($place, 'type')) {
            return $place->type === 'object' || (is_array($place->type) && in_array('object', $place->type, true));
        }

        foreach (self::OBJECT_KEYWORDS as $keyword) {
            if (property_exists($place, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function required(stdClass $place): array
    {
        $required = $place->required ?? null;

        return is_array($required) ? array_values(array_filter($required, is_string(...))) : [];
    }

    /**
     * Follows every schema to the schemas in it and to the schema its "$ref" names, each schema once.
     *
     * - `loops`: whether a step led back to a schema the walk was still in.
     * - `order`: the schemas by object id, each after every schema its steps lead to.
     * - `steps`: per schema the steps that did not lead back, as the schema reached and the levels the step adds
     *   (1 for a schema inside, 0 for a reference).
     *
     * The walk keeps its own list of what is open, so a chain of references as long as the schema is no deeper
     * for PHP than a flat one.
     *
     * @param non-empty-list<array{stdClass, stdClass}> $places
     * @return array{loops: bool, order: list<int>, steps: array<int, list<array{int, int}>>}
     */
    private static function chains(stdClass $root, array $places): array
    {
        $index = self::index($places);

        $next = [];
        foreach ($places as [$place, $resource]) {
            $steps = [];
            foreach (self::below($place) as [, $member]) {
                $steps[] = [$member, 1];
            }
            $target = self::referred($place, $resource, $index);
            if ($target !== null) {
                $steps[] = [$target, 0];
            }
            $next[spl_object_id($place)] = $steps;
        }

        $loops = false;
        $order = [];
        $kept = [];
        $state = [spl_object_id($root) => 1];
        $open = [[spl_object_id($root), 0]];

        while ($open !== []) {
            $top = count($open) - 1;
            [$id, $at] = $open[$top];

            if (! isset($next[$id][$at])) {
                array_pop($open);
                $state[$id] = 2;
                $order[] = $id;

                continue;
            }
            $open[$top][1] = $at + 1;

            [$member, $levels] = $next[$id][$at];
            $memberId = spl_object_id($member);
            $seen = $state[$memberId] ?? 0;
            if ($seen === 1) {
                $loops = true;

                continue;
            }

            $kept[$id][] = [$memberId, $levels];
            if ($seen === 0) {
                $state[$memberId] = 1;
                $open[] = [$memberId, 0];
            }
        }

        return ['loops' => $loops, 'order' => $order, 'steps' => $kept];
    }

    /**
     * What a reference can name: the schemas of the document by object id, by "$id" and by "$anchor".
     *
     * @param non-empty-list<array{stdClass, stdClass}> $places
     * @return array{root: stdClass, known: array<int, true>, byId: array<string, stdClass>, byAnchor: array<string, stdClass>}
     */
    private static function index(array $places): array
    {
        $known = [];
        $byId = [];
        $byAnchor = [];
        foreach ($places as [$place]) {
            $known[spl_object_id($place)] = true;

            $id = self::idOf($place);
            if ($id !== null) {
                [$address, $anchor] = array_pad(explode('#', $id, 2), 2, '');
                if ($address !== '') {
                    $byId[$address] ??= $place;
                } elseif ($anchor !== '') {
                    $byAnchor[$anchor] ??= $place;
                }
            }
            $anchor = $place->{'$anchor'} ?? null;
            if (is_string($anchor) && $anchor !== '') {
                $byAnchor[$anchor] ??= $place;
            }
        }

        return ['root' => $places[0][0], 'known' => $known, 'byId' => $byId, 'byAnchor' => $byAnchor];
    }

    /**
     * What makes a reference in this document one that two readers can read differently.
     *
     * - `oldId`: a schema has "id", an address for the oldest drafts and nothing for the later ones.
     * - `relativeId`: an "$id" is written relative to another, and this class resolves no address.
     * - `addresses`, `anchors`: how often each address and each anchor name is written.
     *
     * @param non-empty-list<array{stdClass, stdClass}> $places
     * @return array{oldId: bool, relativeId: bool, addresses: array<string, int>, anchors: array<string, int>}
     */
    private static function doubts(array $places): array
    {
        $doubts = ['oldId' => false, 'relativeId' => false, 'addresses' => [], 'anchors' => []];
        foreach ($places as [$place]) {
            $doubts['oldId'] = $doubts['oldId'] || property_exists($place, 'id');

            $names = [];
            $id = self::idOf($place);
            if ($id !== null) {
                [$address, $anchor] = array_pad(explode('#', $id, 2), 2, '');
                if ($address !== '') {
                    $doubts['addresses'][$address] = ($doubts['addresses'][$address] ?? 0) + 1;
                    $doubts['relativeId'] = $doubts['relativeId'] || ! self::isAbsolute($address);
                } elseif ($anchor !== '') {
                    $names[] = $anchor;
                }
            }
            $anchor = $place->{'$anchor'} ?? null;
            if (is_string($anchor) && $anchor !== '') {
                $names[] = $anchor;
            }
            foreach ($names as $name) {
                $doubts['anchors'][$name] = ($doubts['anchors'][$name] ?? 0) + 1;
            }
        }

        return $doubts;
    }

    private static function isAbsolute(string $address): bool
    {
        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $address) === 1;
    }

    /**
     * Whether every reader places this reference, which referred() could place, at the same schema.
     *
     * @param array{root: stdClass, known: array<int, true>, byId: array<string, stdClass>, byAnchor: array<string, stdClass>} $index
     * @param array{oldId: bool, relativeId: bool, addresses: array<string, int>, anchors: array<string, int>} $doubts
     */
    private static function hasOneReading(string $reference, stdClass $resource, array $index, array $doubts): bool
    {
        if ($doubts['oldId']) {
            return false;
        }

        [$address, $fragment] = array_pad(explode('#', $reference, 2), 2, '');
        if ($address !== '' && ($doubts['relativeId'] || ! self::isAbsolute($address) || ($doubts['addresses'][$address] ?? 0) !== 1)) {
            return false;
        }
        if ($fragment !== '' && $fragment[0] !== '/') {
            return ($doubts['anchors'][rawurldecode($fragment)] ?? 0) === 1;
        }
        if ($address !== '' || $resource === $index['root']) {
            return true;
        }

        // Without an address: the nearest "$id" and the root must lead to the same schema.
        return $fragment !== ''
            && self::pointed($resource, $fragment, $index['known']) !== null
            && self::pointed($resource, $fragment, $index['known']) === self::pointed($index['root'], $fragment, $index['known']);
    }

    /**
     * The schema of this document the "$ref" of a schema names, or null: no "$ref", another document, or a place
     * where no schema stands.
     *
     * @param array{root: stdClass, known: array<int, true>, byId: array<string, stdClass>, byAnchor: array<string, stdClass>} $index
     */
    private static function referred(stdClass $place, stdClass $resource, array $index): ?stdClass
    {
        $reference = $place->{'$ref'} ?? null;
        if (! is_string($reference)) {
            return null;
        }

        [$address, $fragment] = array_pad(explode('#', $reference, 2), 2, '');
        $start = $address === '' ? $resource : ($index['byId'][$address] ?? null);
        if ($start === null || $fragment === '') {
            return $start;
        }
        if ($fragment[0] !== '/') {
            return $index['byAnchor'][rawurldecode($fragment)] ?? null;
        }

        // A pointer is read from the schema whose address holds; one that leads nowhere there is read from the root,
        // which is where it leads for a reader that does not take the "$id" (or "id") in between for an address.
        return self::pointed($start, $fragment, $index['known'])
            ?? ($start === $index['root'] ? null : self::pointed($index['root'], $fragment, $index['known']));
    }

    /**
     * The schema a JSON pointer names, read from this schema, or null when no schema stands there.
     *
     * @param array<int, true> $known the object ids of the schemas of the document
     */
    private static function pointed(stdClass $start, string $fragment, array $known): ?stdClass
    {
        $at = $start;
        foreach (array_slice(explode('/', $fragment), 1) as $token) {
            $token = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($token));

            // One member is read, never the whole object: a schema of many definitions is not copied per step.
            if ($at instanceof stdClass && ! str_starts_with($token, "\0") && property_exists($at, $token)) {
                $at = $at->{$token};
            } elseif (is_array($at) && preg_match('/^(0|[1-9][0-9]*)$/D', $token) === 1 && array_key_exists($token, $at)) {
                $at = $at[$token];
            } else {
                return null;
            }
        }

        return $at instanceof stdClass && isset($known[spl_object_id($at)]) ? $at : null;
    }
}
