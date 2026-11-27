<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

/**
 * One fenced `php` block of the README, with the marker on the line before its fence.
 */
final readonly class ReadmeSample
{
    public const string RUNS = 'runs';

    public const string COMING = 'coming';

    /**
     * @param int $line the line of the README on which the code starts: the line after the fence
     * @param string|null $marker "runs", "coming", or null when the line before the fence is no marker
     * @param list<string> $placeholders the class names the marker lists as made up: they stand for a class that
     *                                   does not exist yet
     * @param string $code the lines between the fences, as the README has them
     * @param string|null $fenceProblem why the tests cannot execute a block that is fenced this way; null for the
     *                                  one form they can: a fence at the start of the line with `php` alone after it
     */
    public function __construct(
        public int $line,
        public ?string $marker,
        public array $placeholders,
        public string $code,
        public ?string $fenceProblem = null,
    ) {}

    /**
     * Where to find the sample, for the message of a failed test.
     */
    public function name(): string
    {
        return 'the sample at line ' . $this->line . ' of README.md';
    }

    /**
     * The classes the `use` lines of the sample name.
     *
     * @return list<string>
     */
    public function usedClasses(): array
    {
        preg_match_all('/^use ([A-Za-z_][A-Za-z0-9_\\\\]*);$/m', $this->code, $matches);

        return $matches[1];
    }

    /**
     * The classes and functions the sample declares, in order. A later sample that names one of them builds on
     * this one.
     *
     * @return list<string>
     */
    public function declares(): array
    {
        preg_match_all('/^(?:(?:final|abstract|readonly) )*(?:class|interface|enum|function) ([A-Za-z_][A-Za-z0-9_]*)/m', $this->code, $matches);

        return $matches[1];
    }

    /**
     * The output the sample shows: the text after `// prints ` at the end of a statement, by the line of the README.
     *
     * @return array<int, string>
     */
    public function shownOutput(): array
    {
        $shown = [];
        foreach (explode("\n", $this->code) as $offset => $line) {
            if (preg_match('/;\s*\/\/ prints (.*)$/', $line, $match) === 1) {
                $shown[$this->line + $offset] = rtrim($match[1]);
            }
        }

        return $shown;
    }

    /**
     * The lines with a comment that says "prints" in a way the tests do not compare: in another spelling than
     * `// prints ` (a capital, no space), or not at the end of a line that is one whole `echo` or `var_dump()`
     * statement, where the output of one statement cannot be told apart.
     *
     * @return list<int>
     */
    public function misplacedOutput(): array
    {
        $misplaced = [];
        foreach (explode("\n", $this->code) as $offset => $line) {
            $saysPrints = preg_match('/(?:\/\/|#|\/\*)\s*prints\b/i', $line) === 1;
            if ($saysPrints && preg_match('/^\s*(?:echo |var_dump\()[^;]*;\s*\/\/ prints \S.*$/', $line) !== 1) {
                $misplaced[] = $this->line + $offset;
            }
        }

        return $misplaced;
    }
}
