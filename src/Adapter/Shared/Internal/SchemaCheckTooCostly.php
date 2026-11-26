<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared\Internal;

use RuntimeException;

/**
 * Checking an answer against a JSON Schema took more steps or more memory than one check may use.
 *
 * @internal thrown by the loop guard and caught by the structured-answer checker; it never leaves the library
 */
final class SchemaCheckTooCostly extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The check of the answer against the schema went over its limits.');
    }
}
