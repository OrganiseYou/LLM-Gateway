<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\Internal\Traces;

/**
 * A value the library refuses: the plain InvalidArgumentException of PHP, with the arguments of every call left out
 * of its trace.
 *
 * Catch it as the PHP class it extends. The arguments are left out because PHP keeps the arguments of every call on
 * the stack in a trace, and a request, a response or a key is among them where the library throws; a parameter
 * marked as sensitive is hidden from the printed forms of a trace only, and can still be read from the trace itself.
 */
final class InvalidValue extends InvalidArgumentException
{
    /**
     * @param string $message a fixed text of the library, with at most the class name, the file base name and the line
     *                        of a fault in foreign code: never a value from a request, a response or a secret
     */
    public function __construct(string $message)
    {
        parent::__construct($message);

        Traces::withoutArguments($this);
    }
}
