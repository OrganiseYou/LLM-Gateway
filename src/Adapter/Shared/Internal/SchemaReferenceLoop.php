<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared\Internal;

use RuntimeException;

/**
 * A JSON Schema refers back to itself without going deeper into the answer, so checking it would never end.
 *
 * @internal thrown by the loop guard and caught by the structured-answer checker; it never leaves the library
 */
final class SchemaReferenceLoop extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A reference in the schema leads back to itself.');
    }
}
