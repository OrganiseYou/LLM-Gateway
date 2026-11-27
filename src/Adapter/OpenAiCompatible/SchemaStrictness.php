<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

/**
 * What is written in the `strict` flag of a response format: the values of the option `json_schema_strict`.
 */
enum SchemaStrictness: string
{
    /** The flag is not sent. */
    case Omit = 'omit';

    /** Always `false`. */
    case AlwaysFalse = 'false';

    /** `true` when the schema of the caller already fits the documented subset, otherwise `false`. */
    case WhenFits = 'when_fits';
}
