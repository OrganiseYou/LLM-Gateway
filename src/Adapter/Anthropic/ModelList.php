<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Anthropic;

use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use SensitiveParameter;

/**
 * Reads the model list of the Messages format: `{"data": […], "has_more": …}`, each entry with `id`,
 * `display_name` and `max_input_tokens`. An unknown field never breaks a list, and an entry without a usable id is
 * skipped. The list is one page; `has_more` is not followed.
 */
final readonly class ModelList
{
    /**
     * @param array<mixed> $body the decoded body of the response
     * @return list<ModelInfo>|null null when the body holds no list
     */
    public static function read(#[SensitiveParameter] array $body): ?array
    {
        if (! is_array($body['data'] ?? null)) {
            return null;
        }

        $models = [];
        foreach ($body['data'] as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (! is_array($entry) || ! is_string($id) || ! Rules::isModelName($id)) {
                continue;
            }

            $name = $entry['display_name'] ?? null;
            $contextLength = $entry['max_input_tokens'] ?? null;

            $models[] = new ModelInfo(
                $id,
                is_string($name) && Rules::isModelName($name) ? $name : null,
                is_int($contextLength) && $contextLength > 0 ? $contextLength : null,
            );
        }

        return $models;
    }
}
