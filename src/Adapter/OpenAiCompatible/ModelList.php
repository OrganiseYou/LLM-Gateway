<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use SensitiveParameter;

/**
 * Reads the model list of a server of the chat-completions format. Three shapes are read: `{"data": […]}`,
 * `{"models": […]}` and a bare list. An unknown field never breaks a list, and an entry without a usable id is
 * skipped.
 */
final readonly class ModelList
{
    /** The fields that can hold the context length, the first that has one wins. */
    private const array CONTEXT_LENGTH_FIELDS = ['context_length', 'max_context_length', 'max_context_size'];

    /**
     * @param array<mixed> $body the decoded body of the response
     * @param bool $isAList whether the body was written as a JSON list; a decoded body cannot tell an empty list
     *                      from an empty object
     * @param string $idField the field of an entry that goes into the model of a request
     * @return list<ModelInfo>|null null when the body holds no list
     */
    public static function read(#[SensitiveParameter] array $body, bool $isAList, string $idField): ?array
    {
        if ($isAList) {
            $entries = $body;
        } elseif (is_array($body['data'] ?? null)) {
            $entries = $body['data'];
        } elseif (is_array($body['models'] ?? null)) {
            $entries = $body['models'];
        } else {
            return null;
        }

        $models = [];
        foreach ($entries as $entry) {
            $model = is_array($entry) ? self::model($entry, $idField) : null;
            if ($model !== null) {
                $models[] = $model;
            }
        }

        return $models;
    }

    /**
     * @param array<mixed> $entry
     */
    private static function model(#[SensitiveParameter] array $entry, string $idField): ?ModelInfo
    {
        $id = $entry[$idField] ?? null;
        if (! is_string($id) || ! Rules::isModelName($id)) {
            return null;
        }
        // An entry that says it cannot be used for chat is left out.
        if (($entry['chat'] ?? null) === false || ($entry['enabled'] ?? null) === false || ($entry['status'] ?? null) === 'unavailable') {
            return null;
        }

        $name = null;
        foreach (['name', 'display_name'] as $field) {
            if ($name === null && is_string($entry[$field] ?? null) && Rules::isModelName($entry[$field])) {
                $name = $entry[$field];
            }
        }

        $contextLength = null;
        foreach (self::CONTEXT_LENGTH_FIELDS as $field) {
            if ($contextLength === null && is_int($entry[$field] ?? null) && $entry[$field] > 0) {
                $contextLength = $entry[$field];
            }
        }

        $aliases = [];
        foreach (is_array($entry['aliases'] ?? null) ? $entry['aliases'] : [] as $alias) {
            if (is_string($alias) && Rules::isModelName($alias)) {
                $aliases[] = $alias;
            }
        }

        $deprecated = self::isSet($entry['deprecation'] ?? null)
            || self::isSet($entry['shutdown_date'] ?? null)
            || ($entry['is_deprecated'] ?? null) === true;

        return new ModelInfo($id, $name, $contextLength, $aliases, $deprecated);
    }

    /**
     * Whether a field that announces an end holds something: not null, not false and not an empty text.
     */
    private static function isSet(#[SensitiveParameter] mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '';
    }
}
