<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Cohere;

use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use SensitiveParameter;

/**
 * Reads the model list of the Chat v2 format: `{"models": […], "next_page_token": …}`, each entry with `name`,
 * `context_length` and `is_deprecated` (`docs/providers/cohere.md`, "What a request can do"). An unknown field
 * never breaks a list, and an entry without a usable name is skipped. The list is one page: `next_page_token` is
 * not read, and nothing of a response (`tokenizer_url` among it) ever becomes an address.
 */
final readonly class ModelList
{
    /**
     * @param array<mixed> $body the decoded body of the response
     * @return list<ModelInfo>|null null when the body holds no list
     */
    public static function read(#[SensitiveParameter] array $body): ?array
    {
        $entries = $body['models'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries)) {
            return null;
        }

        $models = [];
        foreach ($entries as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            // A name is the model of a request: not empty, and without a control character.
            if (! is_array($entry) || ! is_string($name) || ! Rules::isModelName($name)) {
                continue;
            }

            $contextLength = Reading::count($entry['context_length'] ?? null);

            $models[] = new ModelInfo(
                $name,
                null,
                $contextLength !== null && $contextLength > 0 ? $contextLength : null,
                [],
                ($entry['is_deprecated'] ?? null) === true,
            );
        }

        return $models;
    }
}
