<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * A tool the model may ask to call.
 */
final readonly class ToolDefinition
{
    /**
     * @param string $name not empty, no whitespace, no control character; any length
     * @param JsonSchema $parameters the JSON Schema of the arguments
     */
    public function __construct(
        public string $name,
        public string $description,
        public JsonSchema $parameters,
    ) {
        if ($name === '' || preg_match('/[\p{Cc}\p{Z}]/u', $name) !== 0) {
            throw new InvalidValue(
                'A tool name must not be empty and must not hold whitespace or a control character.',
            );
        }
        if (preg_match('//u', $description) !== 1) {
            throw new InvalidValue('A tool description must be valid UTF-8.');
        }
    }
}
