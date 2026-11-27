<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * The JSON Schema the answer must match. The name is needed because a structured answer may travel as a tool call.
 */
final readonly class ResponseFormat
{
    /**
     * @param string $name not empty, no whitespace, no control character; any length
     */
    public function __construct(
        public JsonSchema $schema,
        public string $name = 'answer',
        public string $description = '',
    ) {
        if ($name === '' || preg_match('/[\p{Cc}\p{Z}]/u', $name) !== 0) {
            throw new InvalidValue(
                'A response format name must not be empty and must not hold whitespace or a control character.',
            );
        }
        if (preg_match('//u', $description) !== 1) {
            throw new InvalidValue('A response format description must be valid UTF-8.');
        }
    }
}
