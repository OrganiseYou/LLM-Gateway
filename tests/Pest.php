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
 * Every form in which a host can print or store an exception.
 *
 * @return array<string, string>
 */
function exceptionForms(Throwable $exception): array
{
    return OutputForms::ofException($exception);
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
