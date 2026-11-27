<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Anthropic;

use OrganiseYou\LlmGateway\Adapter\Shared\SchemaShape;
use OrganiseYou\LlmGateway\Contract\JsonSchema;

/**
 * Decides how the schema of a response format goes to the structured-output mode of the Messages format
 * (`output_config.format`), by the policy of the adapter map (`docs/design/adapters.md`, section 5.9, row A2).
 *
 * The caller's schema is never changed, and the adapter base checks the answer against it in full. What is sent is
 * the caller's schema, or a looser copy: the constraint keywords the mode refuses are taken out, and `oneOf` is
 * written as `anyOf`. A copy that could refuse an answer the caller's schema allows is never sent, and neither is
 * a schema the mode refuses for its shape: such a request takes the emulated route, with the schema in the system
 * text.
 *
 * The keyword lists are those of `docs/providers/anthropic.md`, "What a request can do" and "Structured output".
 */
final readonly class OutputSchema
{
    /** The keywords the mode refuses and that only narrow: they are taken out of the copy. */
    private const array TAKEN_OUT = ['minimum', 'maximum', 'multipleOf', 'minLength', 'maxLength', 'maxItems', 'uniqueItems', 'not'];

    /**
     * What a pattern must not hold: a backreference (by number, or by name in the spellings `\k<name>`,
     * `(?P=name)` and `\g…`) or a lookaround.
     */
    private const string REFUSED_IN_A_PATTERN = '/\\\\[1-9]|\\\\[kg][<{\'0-9-]|\(\?P=|\(\?<?[=!]/';

    /**
     * The schema to send, or null when this request must take the emulated route.
     */
    public static function forTheNativeMode(JsonSchema $schema): ?JsonSchema
    {
        $shape = new SchemaShape();

        // `minItems` is accepted as 0 or 1 only. A schema that holds any other value loses the keyword everywhere:
        // a copy without it is looser, never tighter.
        $refused = self::TAKEN_OUT;
        foreach ($shape->valuesOf($schema, 'minItems') as $least) {
            if ($least !== 0 && $least !== 1) {
                $refused[] = 'minItems';

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

        // The shape rules, asked of the copy that would be sent.
        if ($shape->isRecursive($copy) || ! $shape->everyObjectIsClosed($copy)) {
            return null;
        }
        foreach ($shape->valuesOf($copy, '$ref') as $reference) {
            // A reference to another document.
            if (! is_string($reference) || ! str_starts_with($reference, '#')) {
                return null;
            }
        }
        foreach ($shape->valuesOf($copy, 'enum') as $values) {
            if (! is_array($values)) {
                return null;
            }
            foreach ($values as $value) {
                if (is_array($value)) {
                    return null;
                }
            }
        }
        foreach ($shape->valuesOf($copy, 'pattern') as $pattern) {
            if (! is_string($pattern) || preg_match(self::REFUSED_IN_A_PATTERN, $pattern) === 1) {
                return null;
            }
        }

        return $copy;
    }
}
