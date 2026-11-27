<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

/**
 * What happened when one sample of the README was executed.
 */
final readonly class ReadmeRun
{
    /**
     * @param list<string> $problems what was thrown, every warning, notice and deprecation, and every statement
     *                               that printed something other than the README shows; empty for a clean run
     * @param string $output everything the sample printed
     */
    public function __construct(
        public array $problems,
        public string $output,
    ) {}
}
