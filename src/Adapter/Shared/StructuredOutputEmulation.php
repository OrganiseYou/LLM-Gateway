<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared;

use OrganiseYou\LlmGateway\Contract\ResponseFormat;

/**
 * The instructions with which an adapter gets a structured answer from a model that has no structured output of
 * its own: the schema goes into the system text, and the answer is checked like every other.
 *
 * An adapter that reports structured output as "emulated" adds the instructions as the last of the system text,
 * after the system messages of the caller and before the first message of another role, and sends no response
 * format of its own:
 *
 *     $system = [];
 *     foreach ($request->messages as $message) {
 *         if ($message->role === Role::System) {
 *             $system[] = $message->text();
 *         }
 *     }
 *     if ($request->responseFormat !== null) {
 *         $system[] = (new StructuredOutputEmulation())->instructions($request->responseFormat);
 *     }
 *     // … $system goes where the format wants its system text
 *
 * The instructions carry the description of the response format, which is the caller's guidance for the model and
 * reaches it on every other route too; they do not carry its name.
 *
 * It does nothing more when it reads the answer: the adapter base checks the text against the schema, so the
 * caller sees the same structured result as with a model that has structured output.
 *
 * An adapter that emulates through a forced tool call does not use this class: it builds the tool from the name,
 * the description and the schema of the response format, and hands the raw arguments of that call to the
 * structured-answer checker.
 */
final readonly class StructuredOutputEmulation
{
    private const string ASK = 'Answer with exactly one JSON object that is valid against the JSON Schema below. '
        . 'Give only that JSON object: no text before or after it, no explanation and no Markdown code fence.';

    /**
     * Fixed text that asks for one JSON object matching the schema and nothing else, then the description of the
     * response format when the caller gave one, then the schema as JSON on one line. The name of the response
     * format is not in it.
     */
    public function instructions(ResponseFormat $format): string
    {
        $description = trim($format->description);

        return self::ASK
            . ($description === '' ? '' : "\n\nWhat the JSON object is for:\n" . $description)
            . "\n\nJSON Schema:\n"
            . $format->schema->json;
    }
}
