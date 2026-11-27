<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Gemini;

use OrganiseYou\LlmGateway\Contract\ModelInfo;
use SensitiveParameter;

/**
 * Reads the model list of the generate-content format: `{"models": […], "nextPageToken": …}`, each entry with
 * `name` (`models/{id}`), `inputTokenLimit` and `supportedGenerationMethods`. An unknown field never breaks a
 * list. An entry is skipped when it has no id that can be put in a request, or does not say it supports the method
 * this adapter calls. The list is one page: `nextPageToken` is not read, and nothing of a response ever becomes
 * an address.
 */
final readonly class ModelList
{
    /** What the name of a model starts with in the list, and not in a request. */
    private const string NAME_PREFIX = 'models/';

    /** The method this adapter calls; an entry lists the methods its model supports. */
    private const string METHOD = 'generateContent';

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
            if (! is_array($entry) || ! is_string($name)) {
                continue;
            }

            $id = str_starts_with($name, self::NAME_PREFIX) ? substr($name, strlen(self::NAME_PREFIX)) : $name;
            $methods = $entry['supportedGenerationMethods'] ?? null;
            // The id is the model of a request, which stands in the path of its address.
            if (! Reading::isModelInAPath($id) || ! is_array($methods) || ! in_array(self::METHOD, $methods, true)) {
                continue;
            }

            $contextLength = $entry['inputTokenLimit'] ?? null;

            $models[] = new ModelInfo($id, null, is_int($contextLength) && $contextLength > 0 ? $contextLength : null);
        }

        return $models;
    }
}
