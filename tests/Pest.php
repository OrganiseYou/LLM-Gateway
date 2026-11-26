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
