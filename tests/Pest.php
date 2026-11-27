<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Testing\OutputForms;

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
 * The test key. No output of the library may hold it, whatever the test fed in. The same key as the shared test set.
 */
const LEAK_MARKER = ContractTestCase::MARKER_KEY;

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
 * Every form in which a host can print or store an exception, and what a walk over its trace reaches behind every
 * SensitiveParameterValue in it.
 *
 * @return array<string, string>
 */
function exceptionForms(Throwable $exception): array
{
    return OutputForms::ofException($exception);
}

/**
 * Runs a test with PHP leaving out (true) or keeping (false) the arguments of calls in the traces of exceptions.
 *
 * Both settings that decide what a trace shows are set here, so the test sees the same on every machine. The
 * php.ini of the machine does not: php.ini-production leaves the arguments out and cuts a string argument in the
 * string form of a trace to nothing (zend.exception_string_param_max_len = 0), where PHP without a php.ini and
 * php.ini-development show its first 15 bytes.
 *
 * @param bool $ignoreArgs zend.exception_ignore_args for the test
 * @param callable(): void $test
 * @param int $stringLength how many bytes of a string argument the string form of a trace shows: PHP's own default
 *                          of 15, or another length for a test of that setting
 */
function withExceptionIgnoreArgs(bool $ignoreArgs, callable $test, int $stringLength = 15): void
{
    if ($stringLength < 0) {
        throw new InvalidArgumentException('The length of a string argument in a trace cannot be negative.');
    }

    $before = ini_set('zend.exception_ignore_args', $ignoreArgs ? '1' : '0');
    expect($before)->not->toBeFalse('zend.exception_ignore_args could not be set');
    $lengthBefore = ini_set('zend.exception_string_param_max_len', (string) $stringLength);
    expect($lengthBefore)->not->toBeFalse('zend.exception_string_param_max_len could not be set');

    try {
        $test();
    } finally {
        // Put back exactly what ini_set() returned.
        ini_set('zend.exception_ignore_args', (string) $before);
        ini_set('zend.exception_string_param_max_len', (string) $lengthBefore);
    }
}

/**
 * Whether a text holds the marker, for a test that proves the marker IS somewhere. Use this with `toBeTrue()` and
 * not `->toContain(LEAK_MARKER)`: a failure of that expectation carries the marker in its message, the runner keeps
 * the message, and every later test that prints a whole trace with its arguments then finds the marker in the
 * runner's own frames and fails too, which hides the one test that really failed.
 */
function holdsMarker(string $text, string $marker = LEAK_MARKER): bool
{
    return str_contains($text, $marker);
}

/**
 * Every form in which a host can print, store or inspect a value: the output functions, the array cast and a read
 * of every property through reflection, as a debugger does, down to the variables a closure holds.
 *
 * @return array<string, string>
 */
function valueForms(mixed $value): array
{
    return OutputForms::ofValue($value);
}

/**
 * Reads every property of an object the way a debugger does, and whatever those values hold in turn.
 */
function reflectionRead(mixed $value, int $depth = 0): string
{
    return OutputForms::reflectionRead($value, $depth);
}

/**
 * Fails when a frame of the trace still holds the arguments of its call, or when a walk over the trace reaches the
 * marker: through an argument, or through what stands behind a SensitiveParameterValue. PHP hides an argument that
 * is marked as sensitive from every printed form of a trace, but getValue() on what it leaves there gives it back.
 * A test that uses this runs with the arguments kept (withExceptionIgnoreArgs(false, …)): with them left out there
 * is nothing to find.
 */
function expectNoArgumentsInTrace(Throwable $thrown): void
{
    expect($thrown->getTrace())->not->toBe([]);

    foreach ($thrown->getTrace() as $at => $frame) {
        expect(array_key_exists('args', $frame))->toBeFalse('frame ' . $at . ' (' . ($frame['class'] ?? '') . '::' . $frame['function'] . ') keeps the arguments of its call');
    }

    expect(holdsMarker(reflectionRead($thrown->getTrace()), 'MARKER'))->toBeFalse('a walk over the trace reaches the secret');
    expectNoMarker(exceptionForms($thrown));
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
        $before = $at === false ? '' : ', after: …' . preg_replace('/\s+/', ' ', str_replace('sk-test-', '', substr($output, max(0, $at - 300), min($at, 300))));

        expect($at)->toBeFalse($form . ' shows the secret' . $before);
    }
}
