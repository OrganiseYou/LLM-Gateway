<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Credentials;

use OrganiseYou\LlmGateway\Contract\Internal\Traces;
use RuntimeException;

/**
 * The token supplier of the host gave no usable token: it threw an exception, or its token cannot be sent.
 *
 * Nothing of what the supplier threw is in here: no message, no trace, no previous exception, and its own trace
 * keeps no argument of any call. The text is fixed
 * and names at most the class of what was thrown. That text must not be copied into the detail of a gateway
 * exception or into an event: the name of a class of the host can name a vendor.
 */
final class TokenSupplierFailed extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);

        // Built while the token may be on the stack: the trace keeps where, not with what.
        Traces::withoutArguments($this);
    }

    /**
     * @param string $class the class of the exception the supplier threw
     */
    public static function threw(string $class): self
    {
        // An anonymous class carries the path of its file after a null byte.
        $name = strstr($class, "\0", true);

        return new self('The token supplier failed with an exception of class ' . ($name === false ? $class : $name) . '.');
    }

    public static function gaveUnusableToken(): self
    {
        return new self(
            'The token supplier gave a token with a control character, or with a space or tab at its start or end.',
        );
    }
}
