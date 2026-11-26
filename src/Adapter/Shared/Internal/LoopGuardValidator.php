<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared\Internal;

use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\ValidationContext;
use Opis\JsonSchema\Validator;

/**
 * The JSON Schema validator, started with the context that guards against a schema that refers back to itself and
 * keeps the limits of one check.
 *
 * @internal relies on createContext() of the validator class, which the 2.x line leaves open
 */
final class LoopGuardValidator extends Validator
{
    /**
     * @param int $follows how many references one check may follow
     * @param int $bytes how much the memory in use may grow during one check
     */
    public function __construct(
        SchemaLoader $loader,
        int $maxErrors,
        bool $stopAtFirstError,
        private readonly int $follows,
        private readonly int $bytes,
    ) {
        parent::__construct($loader, $maxErrors, $stopAtFirstError);
    }

    /**
     * @param mixed $data
     * @param array<mixed>|null $globals
     * @param array<mixed>|null $slots
     */
    public function createContext($data, ?array $globals = null, ?array $slots = null): ValidationContext
    {
        $context = new LoopGuardContext($data, $this->loader, null, null, [], null, $this->maxErrors, $this->stopAtFirstError);
        $context->limit($this->follows, $this->bytes);

        return $context;
    }
}
