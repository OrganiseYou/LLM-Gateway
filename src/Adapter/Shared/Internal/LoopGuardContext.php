<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared\Internal;

use Opis\JsonSchema\Schema;
use Opis\JsonSchema\ValidationContext;
use Opis\JsonSchema\Variables;

/**
 * The context of one check, with a guard against a schema that refers back to itself and with the limits of one
 * check.
 *
 * The validator makes a new context every time it follows a reference. When the schema that holds the reference is
 * already being checked further up, at the same place of the answer, following it again can only repeat for ever:
 * the validator would recurse until PHP stops the process. The guard throws instead. A schema that refers to itself
 * one level deeper in the answer (a list, a tree) is at another place each time and is not stopped.
 *
 * A schema can also make the work of a check double with every level of the answer without ever repeating itself
 * (two branches that both refer to the same schema). So the references followed in one check are counted, and the
 * memory the check holds is measured every time the validator builds an error, which is what its memory goes to.
 * Over either limit the guard throws.
 *
 * It lives for one check and holds nothing after it.
 *
 * @internal relies on create(), newInstance() and rootData() of the validator's context class, which the 2.x line
 *           leaves open; with the additions of the validator switched off, rootData() is called only where an
 *           error is built
 */
final class LoopGuardContext extends ValidationContext
{
    /**
     * The place of the answer at which the reference that made this context was followed.
     *
     * @var array<int, int|string>
     */
    private array $followedAt = [];

    /** How many times, above this context, the names of an object were checked in place of its value. */
    private int $namesDeep = 0;

    /** The first context of the check, which keeps its limits; null in that context itself. */
    private ?self $first = null;

    /** The references that may still be followed. Kept in the first context only. */
    private int $followsLeft = PHP_INT_MAX;

    /** The memory use at which the check stops, as memory_get_usage() gives it. Kept in the first context only. */
    private int $memoryCeiling = PHP_INT_MAX;

    /**
     * Sets the limits of the check that starts with this context.
     *
     * @param int $follows how many references may be followed
     * @param int $bytes how much the memory in use may grow from now on
     */
    public function limit(int $follows, int $bytes): void
    {
        $this->followsLeft = $follows;
        $this->memoryCeiling = memory_get_usage() + $bytes;
    }

    /**
     * The validator asks for this where it builds an error.
     *
     * @return mixed
     * @throws SchemaCheckTooCostly when the check holds more memory than it may
     */
    public function rootData()
    {
        if (memory_get_usage() > ($this->first ?? $this)->memoryCeiling) {
            throw new SchemaCheckTooCostly();
        }

        return parent::rootData();
    }

    /**
     * @param array<mixed>|null $slots
     * @throws SchemaReferenceLoop when the schema that refers is already being checked at this place of the answer
     * @throws SchemaCheckTooCostly when more references were followed than the check may follow
     */
    public function create(
        Schema $sender,
        ?Variables $mapper = null,
        ?Variables $globals = null,
        ?array $slots = null,
        ?int $maxErrors = null,
        ?bool $stop_at_first_error = null,
    ): self {
        $first = $this->first ?? $this;
        if (--$first->followsLeft < 0) {
            throw new SchemaCheckTooCostly();
        }

        $path = $this->fullDataPath();

        for ($context = $this; $context instanceof self; $context = $context->parent) {
            if ($context->sender === $sender && $context->namesDeep === $this->namesDeep && $context->followedAt === $path) {
                throw new SchemaReferenceLoop();
            }
        }

        $child = new self(
            $this->currentData(),
            $this->loader,
            $this,
            $sender,
            $this->globals,
            $slots ?? $this->slots,
            $maxErrors ?? $this->maxErrors,
            $stop_at_first_error ?? $this->stopAtFirstError,
        );
        $child->first = $first;
        $child->followedAt = $path;
        $child->namesDeep = $this->namesDeep;

        return $child;
    }

    /**
     * The context for the name of a property, checked as a value of its own. It stays guarded.
     *
     * @param mixed $data
     * @param array<mixed>|null $globals
     * @param array<mixed>|null $slots
     */
    public function newInstance(
        $data,
        ?Schema $sender,
        ?array $globals = null,
        ?array $slots = null,
        ?int $max_errors = null,
        ?bool $stop_at_first_error = null,
    ): self {
        $child = new self(
            $data,
            $this->loader,
            $this,
            $sender,
            $globals ?? $this->globals,
            $slots ?? $this->slots,
            $max_errors ?? $this->maxErrors,
            $stop_at_first_error ?? $this->stopAtFirstError,
        );
        $child->first = $this->first ?? $this;
        $child->namesDeep = $this->namesDeep + 1;

        return $child;
    }
}
