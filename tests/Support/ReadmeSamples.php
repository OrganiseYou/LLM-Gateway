<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use LogicException;
use Throwable;

/**
 * Reads the PHP samples of the README and executes the ones that are marked as running today, so that the README
 * cannot say something the code does not do.
 *
 * How a sample is executed, and why this way. The text of the block is handed to eval() as the README has it, in
 * a function that has no variable of its own, so a sample sees nothing of the test and leaves nothing behind but
 * the classes and functions it declares. Nothing is written to a file: the tests write no file, and a copy of the
 * samples in the repository would be a second text that can differ from the README. Three things are put around
 * the text, none of them inside it:
 *
 * - `declare(strict_types=1);` and a namespace, on one line before it. A reader puts a sample in a file of their
 *   own that starts the same way; code handed to eval() does not take either over from the file that calls it.
 *   All samples share the one namespace, so a sample can use the class of an earlier one.
 * - Empty lines, so that a line of the sample has the number it has in the README: a failure names that line.
 * - After a statement that ends in `// prints …`, a call that takes note of what was printed since the last one.
 *
 * A sample that declares a class or a function is executed once for the whole run of the tests, and before any
 * sample that follows it in the README: the README says "after the class above" where a sample builds on another.
 * The outcome of every sample is kept, so the tests can ask for it in any order. That is state in a static
 * property, on purpose: PHP cannot declare a class twice.
 *
 * In the code of a sample `__DIR__` is the folder of this file, tests/Support.
 */
final class ReadmeSamples
{
    /** The namespace every sample is executed in. */
    public const string NAMESPACE = 'OrganiseYou\\LlmGateway\\Tests\\ReadmeSample';

    /** @var list<ReadmeSample>|null */
    private static ?array $samples = null;

    /** @var array<int, ReadmeRun> by the line of the sample */
    private static array $runs = [];

    /** @var array<int, string> what was printed since the last note, by the line of the README */
    private static array $printed = [];

    /** @var list<string> */
    private static array $mismatches = [];

    /** The code that is being executed: handed over here, so that the function that executes it has no variable. */
    private static string $code = '';

    public static function path(): string
    {
        return dirname(__DIR__, 2) . '/README.md';
    }

    /**
     * Every fenced `php` block of the README, in order.
     *
     * @return list<ReadmeSample>
     */
    public static function all(): array
    {
        return self::$samples ??= self::in(self::text());
    }

    /**
     * The whole README. The one place in the tests that reads it.
     */
    public static function text(): string
    {
        $text = file_get_contents(self::path());
        if ($text === false) {
            throw new LogicException('README.md could not be read.');
        }

        return $text;
    }

    /**
     * Every fenced block of a text in the form of the README whose info string starts with `php`, in order, in
     * every form Markdown takes for a fence: backticks or tildes, three or more, indented by up to three spaces,
     * with or without more words after `php`. Only one form can be executed (ReadmeSample::$fenceProblem says why
     * another cannot); every form is found, so that a sample cannot hide from the tests in a fence they do not read.
     *
     * @return list<ReadmeSample>
     */
    public static function in(string $text): array
    {
        return self::read($text)['samples'];
    }

    /**
     * The README without its fenced blocks: what a reader is told in words.
     */
    public static function prose(): string
    {
        return self::read(self::text())['prose'];
    }

    /**
     * @return array{samples: list<ReadmeSample>, prose: string}
     */
    private static function read(string $text): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $text));

        $samples = [];
        $prose = [];
        $open = null;
        foreach ($lines as $index => $line) {
            if ($open === null) {
                // An opening fence: up to three spaces, three or more backticks or tildes, then the info string.
                // A fence of backticks cannot have a backtick in its info string: that is code in a line of text.
                if (preg_match('/^( {0,3})(`{3,}|~{3,})[ \t]*(.*?)[ \t]*$/', $line, $fence) === 1 && ($fence[2][0] === '~' || ! str_contains($fence[3], '`'))) {
                    $open = ['at' => $index, 'indent' => $fence[1], 'mark' => $fence[2], 'info' => $fence[3]];
                } else {
                    $prose[] = $line;
                }

                continue;
            }

            // A closing fence: the same mark, at least as long, and nothing after it.
            if (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*$/', $line, $fence) !== 1 || $fence[1][0] !== $open['mark'][0] || strlen($fence[1]) < strlen($open['mark'])) {
                continue;
            }

            if (preg_match('/^php/i', $open['info']) === 1) {
                $samples[] = self::sample($lines, $open['at'], $index, $open['indent'], $open['info']);
            }
            $open = null;
        }

        if ($open !== null && preg_match('/^php/i', $open['info']) === 1) {
            throw new LogicException('The PHP sample that starts at line ' . ($open['at'] + 2) . ' is not closed.');
        }

        return ['samples' => $samples, 'prose' => implode("\n", $prose)];
    }

    /**
     * @param list<string> $lines
     * @param int $opened the index of the line with the opening fence
     * @param int $closed the index of the line with the closing fence
     */
    private static function sample(array $lines, int $opened, int $closed, string $indent, string $info): ReadmeSample
    {
        $marker = null;
        $placeholders = [];
        if (preg_match('/^<!-- example: (runs|coming)(?:, placeholder: ([A-Za-z0-9_]+(?:, [A-Za-z0-9_]+)*))? -->$/', $lines[$opened - 1] ?? '', $match) === 1) {
            $marker = $match[1];
            $placeholders = isset($match[2]) ? explode(', ', $match[2]) : [];
        }

        $problem = match (true) {
            $indent !== '' => 'its fence is indented, so its code is too: start the fence at the start of the line',
            strtolower($info) !== 'php' => 'its fence says "' . $info . '": write `php` alone after the fence',
            default => null,
        };

        return new ReadmeSample($opened + 2, $marker, $placeholders, implode("\n", array_slice($lines, $opened + 1, $closed - $opened - 1)), $problem);
    }

    /**
     * Executes the sample, once, after every sample before it that declares a class or a function.
     */
    public static function run(ReadmeSample $sample): ReadmeRun
    {
        if ($sample->marker !== ReadmeSample::RUNS) {
            throw new LogicException('Only a sample that is marked as running is executed: ' . $sample->name() . ' is not.');
        }
        if ($sample->fenceProblem !== null) {
            throw new LogicException($sample->name() . ' cannot be executed: ' . $sample->fenceProblem . '.');
        }

        foreach (self::all() as $earlier) {
            if ($earlier->line < $sample->line && $earlier->marker === ReadmeSample::RUNS && $earlier->fenceProblem === null && $earlier->declares() !== []) {
                self::$runs[$earlier->line] ??= self::execute($earlier);
            }
        }

        return self::$runs[$sample->line] ??= self::execute($sample);
    }

    /**
     * The full name of a class or a function that a running sample of the README declares, after that sample and
     * the ones it builds on were executed.
     *
     * @throws LogicException when no running sample declares it, or the sample did not run cleanly
     */
    public static function declared(string $name): string
    {
        foreach (self::all() as $sample) {
            if ($sample->marker === ReadmeSample::RUNS && in_array($name, $sample->declares(), true)) {
                $run = self::run($sample);
                if ($run->problems !== []) {
                    throw new LogicException($sample->name() . ' did not run cleanly: ' . implode('; ', $run->problems));
                }

                return self::NAMESPACE . '\\' . $name;
            }
        }

        throw new LogicException('No running sample of the README declares ' . $name . '.');
    }

    /**
     * Called by the code of a sample after a statement whose output the README shows. Not for a test to call.
     *
     * @internal
     */
    public static function printed(int $line, string $shown): void
    {
        $printed = (string) ob_get_clean();
        ob_start();

        self::$printed[$line] = (self::$printed[$line] ?? '') . $printed;

        // var_dump() ends its output with a line break; the README shows the line without it.
        $printed = str_ends_with($printed, "\n") ? substr($printed, 0, -1) : $printed;
        if ($printed !== $shown) {
            self::$mismatches[] = 'line ' . $line . ' printed "' . $printed . '" where the README shows "' . $shown . '"';
        }
    }

    /**
     * Executes the sample now, without the samples before it and without keeping the outcome. run() is the way for
     * a sample of the README; this is for a test of the runner itself, with a sample that declares nothing.
     */
    public static function execute(ReadmeSample $sample): ReadmeRun
    {
        $shown = $sample->shownOutput();
        $problems = array_map(
            fn(int $line): string => 'line ' . $line . ' says "prints" in a comment the tests do not compare: write "// prints …" in exactly that spelling, at the end of an echo or a var_dump() statement that fits on one line',
            $sample->misplacedOutput(),
        );

        // The code as the README has it, each line on the line it has there, with a note after every statement
        // whose output the README shows.
        $lines = explode("\n", $sample->code);
        foreach ($lines as $offset => $line) {
            $at = $sample->line + $offset;
            if (isset($shown[$at])) {
                $lines[$offset] = (string) preg_replace(
                    '/;(\s*\/\/ prints .*)$/',
                    '; \\' . self::class . '::printed(' . $at . ', ' . var_export($shown[$at], true) . ');$1',
                    $line,
                );
            }
        }
        $code = 'declare(strict_types=1); namespace ' . self::NAMESPACE . ';' . str_repeat("\n", $sample->line - 1) . implode("\n", $lines);

        self::$printed = [];
        self::$mismatches = [];

        // Every warning, notice and deprecation is a problem, whatever the level of error_reporting() is.
        set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$problems): bool {
            $problems[] = self::levelName($level) . ' on line ' . $line . ': ' . $message;

            return true;
        });
        $buffers = ob_get_level();
        ob_start();

        try {
            self::evaluate($code);
        } catch (Throwable $thrown) {
            // Only the class, the text and the line: the trace is the test runner's.
            $problems[] = $thrown::class . ' on line ' . $thrown->getLine() . ': ' . $thrown->getMessage();
        } finally {
            $rest = '';
            while (ob_get_level() > $buffers) {
                $rest = ob_get_clean() . $rest;
            }
            restore_error_handler();
        }

        foreach (array_keys(array_diff_key($shown, self::$printed)) as $line) {
            $problems[] = 'line ' . $line . ' shows output and was never reached';
        }
        if ($shown !== [] && $rest !== '') {
            $problems[] = 'the sample printed "' . $rest . '" after its last statement with "// prints", and the README does not show it';
        }

        return new ReadmeRun([...$problems, ...self::$mismatches], implode('', self::$printed) . $rest);
    }

    /**
     * The one place a sample is executed: a function without a variable, so the sample starts with none.
     */
    private static function evaluate(string $code): void
    {
        self::$code = $code;

        (static function (): void {
            eval(self::$code);
        })();
    }

    private static function levelName(int $level): string
    {
        return match ($level) {
            E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'a warning',
            E_NOTICE, E_USER_NOTICE => 'a notice',
            E_DEPRECATED, E_USER_DEPRECATED => 'a deprecation',
            default => 'an error of level ' . $level,
        };
    }
}
