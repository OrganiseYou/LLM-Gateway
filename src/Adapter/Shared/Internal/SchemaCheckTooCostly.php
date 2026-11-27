<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared\Internal;

use OrganiseYou\LlmGateway\Contract\Internal\Traces;
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

        // Built inside the validator, with the answer on the stack: the trace keeps where, not with what.
        Traces::withoutArguments($this);
    }
}
