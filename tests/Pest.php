<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;

// Shared Pest configuration. No test may open a network connection: every test uses the fake transport
// and the synthetic fixtures under tests/fixtures.

/**
 * Hides a value's type from static analysis, so a test can hand a constructor the wrong kind of value on purpose
 * and prove that the constructor refuses it at run time.
 */
function untyped(mixed $value): mixed
{
    return $value;
}

/**
 * The test key. No output of the library may hold it, whatever the test fed in.
 */
const LEAK_MARKER = 'sk-test-MARKER-do-not-leak';

/**
 * Stands for the step of an adapter that turns a failed attempt into the neutral error. Everything it has at hand
 * holds the marker; nothing of it may reach the exception.
 *
 * @param array<string, string> $headers
 */
function gatewayErrorFromAttempt(string $errorBody, array $headers, Throwable $clientFailure): GatewayException
{
    return new GatewayException(ErrorReason::CredentialsRejected, 'trace-1', 401, 'invalid_api_key', null, 1);
}

/**
 * The same step, written without the neutral error: what plain PHP keeps of the arguments.
 *
 * @param array<string, string> $headers
 */
function plainErrorFromAttempt(string $errorBody, array $headers, Throwable $clientFailure): RuntimeException
{
    return new RuntimeException('credentials_rejected');
}

/**
 * Every form in which a host can print or store an exception.
 *
 * @return array<string, string>
 */
function exceptionForms(Throwable $exception): array
{
    ob_start();
    var_dump($exception);
    $dumped = (string) ob_get_clean();

    try {
        $serialized = serialize($exception);
    } catch (Exception) {
        // A trace that holds the arguments of the test runner's calls cannot be stored: closures are among them.
        $serialized = '';
    }

    return [
        'getMessage' => $exception->getMessage(),
        'string cast' => (string) $exception,
        'json_encode' => json_encode($exception, JSON_THROW_ON_ERROR),
        'var_export' => var_export($exception, true),
        'print_r' => print_r($exception, true),
        'var_dump' => $dumped,
        'serialize' => $serialized,
        'getTraceAsString' => $exception->getTraceAsString(),
        'getTrace' => print_r($exception->getTrace(), true),
    ];
}

/**
 * Runs a test with PHP keeping ("0") or leaving out ("1") the arguments of calls in the traces of exceptions.
 *
 * @param callable(): void $test
 */
function withExceptionIgnoreArgs(string $value, callable $test): void
{
    $before = ini_set('zend.exception_ignore_args', $value);
    expect($before)->not->toBeFalse('zend.exception_ignore_args could not be set');

    try {
        $test();
    } finally {
        ini_set('zend.exception_ignore_args', (string) $before);
    }
}

/**
 * Every form in which a host can print, store or inspect a value: the output functions, the array cast and a read
 * of every property through reflection, as a debugger does, down to the variables a closure holds.
 *
 * @return array<string, string>
 */
function valueForms(mixed $value): array
{
    $dump = function (mixed $subject): string {
        ob_start();
        var_dump($subject);

        return (string) ob_get_clean();
    };
    $guarded = function (callable $form): string {
        try {
            return (string) $form();
        } catch (Throwable $refused) {
            // A form that refuses is safe, as long as the refusal does not hold the value either.
            return get_class($refused) . ': ' . $refused->getMessage() . "\n" . $refused->getTraceAsString();
        }
    };

    $forms = [
        'var_export' => $guarded(fn(): string => var_export($value, true)),
        'var_dump' => $dump($value),
        'print_r' => print_r($value, true),
        'json_encode' => $guarded(fn(): string => (string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR)),
        'serialize' => $guarded(fn(): string => serialize($value)),
        'debug_zval_dump' => $guarded(function () use ($value): string {
            ob_start();
            debug_zval_dump($value);

            return (string) ob_get_clean();
        }),
    ];

    if ($value instanceof Stringable) {
        $forms['string cast'] = (string) $value;
    }

    if (is_object($value)) {
        $forms['array cast, var_dump'] = $dump((array) $value);
        $forms['array cast, print_r'] = print_r((array) $value, true);
        $forms['array cast, var_export'] = $guarded(fn(): string => var_export((array) $value, true));
        $forms['array cast, serialize'] = $guarded(fn(): string => serialize((array) $value));
        $forms['get_mangled_object_vars'] = $dump(get_mangled_object_vars($value));
        $forms['reflection'] = reflectionRead($value);
    }

    return $forms;
}

/**
 * Reads every property of an object the way a debugger does, and whatever those values hold in turn.
 */
function reflectionRead(mixed $value, int $depth = 0): string
{
    if ($depth > 6) {
        return '';
    }
    if (is_array($value)) {
        return implode('', array_map(fn(mixed $item): string => reflectionRead($item, $depth + 1), $value));
    }
    if (! is_object($value)) {
        return is_scalar($value) ? (string) $value . "\n" : '';
    }

    ob_start();
    var_dump($value);
    $out = (string) ob_get_clean();

    if ($value instanceof Closure) {
        $function = new ReflectionFunction($value);
        $out .= reflectionRead($function->getStaticVariables(), $depth + 1);
        $out .= reflectionRead($function->getClosureUsedVariables(), $depth + 1);
    }
    if ($value instanceof Generator) {
        $generator = new ReflectionGenerator($value);
        $out .= print_r($generator->getTrace(), true);
        $out .= reflectionRead($generator->getThis(), $depth + 1);
    }
    if ($value instanceof UnitEnum) {
        return $out;
    }

    foreach ((new ReflectionObject($value))->getProperties() as $property) {
        if ($property->isInitialized($value)) {
            $out .= reflectionRead($property->getValue($value), $depth + 1);
        }
    }

    return $out;
}

/**
 * Fails when one of the output forms holds the marker, or the word in its middle that survives a cut. Use this and
 * not `->not->toContain($marker, $message)`: that expectation takes every argument as a text to find, so with a
 * message it passes whatever the output holds.
 *
 * @param array<string, string> $forms
 */
function expectNoMarker(array $forms): void
{
    expect($forms)->not->toBe([]);

    foreach ($forms as $form => $output) {
        $at = strpos($output, 'MARKER');
        // What stands before it, to find the place. Never the marker itself: the runner keeps this message, and a
        // later test that prints its own trace would then find the marker in it.
        $before = $at === false ? '' : ', after: …' . preg_replace('/\s+/', ' ', str_replace('sk-test-', '', substr($output, max(0, $at - 300), 300)));

        expect($at)->toBeFalse($form . ' shows the secret' . $before);
    }
}
