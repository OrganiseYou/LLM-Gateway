<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use Closure;
use Exception;
use Generator;
use ReflectionFunction;
use ReflectionGenerator;
use ReflectionObject;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Every form in which a host can print, store or inspect what a gateway gave it. A test searches these texts for
 * the key it signed in with: none of them may hold it.
 */
final class OutputForms
{
    /**
     * Every form in which a host can print or store an exception, its trace with it.
     *
     * @return array<string, string>
     */
    public static function ofException(Throwable $exception): array
    {
        try {
            $serialized = serialize($exception);
        } catch (Exception) {
            // A trace that holds the arguments of the test runner's calls cannot be stored: closures are among them.
            $serialized = '';
        }

        return [
            'getMessage' => $exception->getMessage(),
            'string cast' => (string) $exception,
            'json_encode' => (string) json_encode($exception, JSON_PARTIAL_OUTPUT_ON_ERROR),
            'var_export' => var_export($exception, true),
            'print_r' => print_r($exception, true),
            'var_dump' => self::dumped($exception),
            'serialize' => $serialized,
            'getTraceAsString' => $exception->getTraceAsString(),
            'getTrace' => print_r($exception->getTrace(), true),
        ];
    }

    /**
     * Every form in which a host can print, store or inspect a value: the output functions, the array cast and a
     * read of every property through reflection, as a debugger does, down to the variables a closure holds.
     *
     * @return array<string, string>
     */
    public static function ofValue(mixed $value): array
    {
        $forms = [
            'var_export' => self::guarded('var_export', $value),
            'var_dump' => self::dumped($value),
            'print_r' => print_r($value, true),
            'json_encode' => self::guarded('json_encode', $value),
            'serialize' => self::guarded('serialize', $value),
            'debug_zval_dump' => self::zvalDumped($value),
        ];

        if ($value instanceof Stringable) {
            $forms['string cast'] = (string) $value;
        }

        if (is_object($value)) {
            $forms['array cast, var_dump'] = self::dumped((array) $value);
            $forms['array cast, print_r'] = print_r((array) $value, true);
            $forms['array cast, var_export'] = self::guarded('var_export', (array) $value);
            $forms['array cast, serialize'] = self::guarded('serialize', (array) $value);
            $forms['get_mangled_object_vars'] = self::dumped(get_mangled_object_vars($value));
            $forms['reflection'] = self::reflectionRead($value);
        }

        return $forms;
    }

    /**
     * Reads every property of an object the way a debugger does, and whatever those values hold in turn.
     */
    public static function reflectionRead(mixed $value, int $depth = 0): string
    {
        if ($depth > 6) {
            return '';
        }
        if (is_array($value)) {
            $out = '';
            foreach ($value as $item) {
                $out .= self::reflectionRead($item, $depth + 1);
            }

            return $out;
        }
        if (! is_object($value)) {
            return is_scalar($value) ? (string) $value . "\n" : '';
        }

        $out = self::dumped($value);

        if ($value instanceof Closure) {
            $function = new ReflectionFunction($value);
            $out .= self::reflectionRead($function->getStaticVariables(), $depth + 1);
            $out .= self::reflectionRead($function->getClosureUsedVariables(), $depth + 1);
        }
        if ($value instanceof Generator) {
            try {
                $generator = new ReflectionGenerator($value);
                $out .= print_r($generator->getTrace(), true);
                $out .= self::reflectionRead($generator->getThis(), $depth + 1);
            } catch (Throwable) {
                // A generator that has ended holds nothing to read.
            }
        }
        if ($value instanceof UnitEnum) {
            return $out;
        }

        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            if ($property->isInitialized($value)) {
                $out .= self::reflectionRead($property->getValue($value), $depth + 1);
            }
        }

        return $out;
    }

    /**
     * The place of the word in the output forms that hold it, without the word: one line per form, with what
     * stands before it. Empty when no form holds it. The word itself is never in what this returns: a test runner
     * keeps the message of a failure, and a later test that prints its own trace would find the word there.
     *
     * @param array<string, string> $forms
     * @param string $word what to look for, in any case: an error lower-cases the code of a provider, and a key that
     *                     came back as that code is still the key. A key is also found by a part that survives a cut
     * @return list<string>
     */
    public static function holding(array $forms, string $word): array
    {
        $found = [];
        foreach ($forms as $form => $output) {
            $at = stripos($output, $word);
            if ($at !== false) {
                $before = (string) preg_replace('/\s+/', ' ', substr($output, max(0, $at - 200), min($at, 200)));
                $found[] = $form . ' shows it, after: …' . substr($before, 0, max(0, strlen($before) - 8));
            }
        }

        return $found;
    }

    private static function dumped(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }

    private static function zvalDumped(mixed $value): string
    {
        ob_start();
        debug_zval_dump($value);

        return (string) ob_get_clean();
    }

    /**
     * A form that refuses is safe, as long as the refusal does not hold the value either.
     */
    private static function guarded(string $form, mixed $value): string
    {
        try {
            return match ($form) {
                'var_export' => var_export($value, true),
                'json_encode' => (string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR),
                default => serialize($value),
            };
        } catch (Throwable $refused) {
            return $refused::class . ': ' . $refused->getMessage() . "\n" . $refused->getTraceAsString();
        }
    }
}
