<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * One reason a structured answer is unusable.
 */
final readonly class StructuredIssue
{
    /**
     * @param string|null $path a JSON pointer ("/" for the root); given for a schema mismatch only
     */
    public function __construct(
        public StructuredIssueKind $kind,
        public string $message,
        public ?string $path,
    ) {
        if ($message === '') {
            throw new InvalidValue('A structured issue needs a message.');
        }
        if ($kind === StructuredIssueKind::SchemaMismatch && $path === null) {
            throw new InvalidValue('A schema mismatch needs a path.');
        }
        if ($kind !== StructuredIssueKind::SchemaMismatch && $path !== null) {
            throw new InvalidValue('Only a schema mismatch takes a path.');
        }
    }

    public function reason(): string
    {
        return $this->path === null ? $this->message : $this->path . ': ' . $this->message;
    }
}
