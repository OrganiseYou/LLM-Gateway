<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Cohere;

use OrganiseYou\LlmGateway\Adapter\Shared\SchemaShape;
use OrganiseYou\LlmGateway\Contract\JsonSchema;

/**
 * Decides how the schema of a response format goes to the structured-output mode of the Chat v2 format
 * (`response_format` of the type `json_object` with a schema), by the policy of the adapter map
 * (`docs/design/adapters.md`, section 5.9, row A4).
 *
 * The caller's schema is never changed, and the adapter base checks the answer against it in full. What is sent is
 * the caller's schema, or a looser copy: the constraint keywords the mode refuses are taken out, and `oneOf` is
 * written as `anyOf`. A copy that could refuse an answer the caller's schema allows is never sent, and neither is
 * a schema the mode refuses for its shape: such a request takes the emulated route, with the schema in the system
 * text.
 *
 * The lists are those of `docs/providers/cohere.md`, "Structured output: schema rules".
 */
final readonly class OutputSchema
{
    /** The keywords the mode refuses and that only narrow: they are taken out of the copy. */
    private const array TAKEN_OUT = ['minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'uniqueItems', 'not'];

    /** The only values of `format` the mode accepts. */
    private const array FORMATS = ['date-time', 'uuid', 'date', 'time'];

    /** What a pattern must not hold: the two anchors and the two lookaheads. */
    private const array REFUSED_IN_A_PATTERN = ['^', '$', '?=', '?!'];

    /**
     * The schema to send, or null when this request must take the emulated route.
     */
    public static function forTheNativeMode(JsonSchema $schema): ?JsonSchema
    {
        $shape = new SchemaShape();

        // `format` is accepted with four values only. A schema that holds any other value loses the keyword
        // everywhere: a copy without it is looser, never tighter.
        $refused = self::TAKEN_OUT;
        foreach ($shape->valuesOf($schema, 'format') as $format) {
            if (! in_array($format, self::FORMATS, true)) {
                $refused[] = 'format';

                break;
            }
        }

        $used = $shape->uses($schema, [...$refused, 'oneOf']);
        $copy = $schema;
        if ($used !== []) {
            // Under `not`, under `if`, in a branch of `oneOf` and in the other turned places, taking a keyword out
            // tightens: then no copy is sent.
            if ($shape->mayTighten($schema, $used)) {
                return null;
            }

            // `not` goes in the same call as the constraint keywords, so nothing under it is rewritten first.
            $copy = $shape->without($schema, array_values(array_diff($used, ['oneOf'])));
            if (in_array('oneOf', $used, true)) {
                $copy = $shape->renamed($copy, 'oneOf', 'anyOf');
            }
            if ($shape->uses($copy, [...$refused, 'oneOf']) !== []) {
                return null;
            }
        }

        // The shape rules, asked of the copy that would be sent: no `allOf`, and at least one required property in
        // every object. The third rule of the mode, an object at the top, holds for every schema of the contract.
        if ($shape->uses($copy, ['allOf']) !== [] || ! $shape->everyObjectHasARequiredProperty($copy)) {
            return null;
        }
        foreach ($shape->valuesOf($copy, 'pattern') as $pattern) {
            if (! is_string($pattern)) {
                return null;
            }
            foreach (self::REFUSED_IN_A_PATTERN as $token) {
                if (str_contains($pattern, $token)) {
                    return null;
                }
            }
        }

        return $copy;
    }
}
