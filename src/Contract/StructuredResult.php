<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * The structured part of an answer: the decoded data and the reasons it is unusable. An unusable structured answer
 * is returned, never thrown.
 */
final readonly class StructuredResult
{
    /**
     * @param array<string, mixed>|null $data the decoded answer, or null when it could not be decoded; kept when it
     *                                        decoded but does not match
     * @param list<StructuredIssue> $issues why the answer is unusable; empty when it is usable
     */
    public function __construct(
        public ?array $data,
        public array $issues,
    ) {
        self::assertIssues($issues);

        if ($data === null && $issues === []) {
            throw new InvalidValue('A structured result without data needs at least one issue.');
        }
    }

    public function isUsable(): bool
    {
        return $this->data !== null && $this->issues === [];
    }

    /**
     * @return list<string> the reason of every issue, in order
     */
    public function reasons(): array
    {
        return array_map(static fn(StructuredIssue $issue): string => $issue->reason(), $this->issues);
    }

    /**
     * @param array<mixed> $issues
     */
    private static function assertIssues(array $issues): void
    {
        if (! array_is_list($issues)) {
            throw new InvalidValue('The issues of a structured result must be a list.');
        }
        foreach ($issues as $issue) {
            if (! $issue instanceof StructuredIssue) {
                throw new InvalidValue('The issues of a structured result hold a value that is not an issue.');
            }
        }
    }
}
