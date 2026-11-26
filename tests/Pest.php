<?php

declare(strict_types=1);

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
