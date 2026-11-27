<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Internal;

use Error;
use Exception;
use ReflectionProperty;
use SensitiveParameter;
use Throwable;

/**
 * Takes the arguments of every call out of the trace of an exception or an error. The one place that writes a trace:
 * the constructors of the library's own exception classes call it on themselves, and nothing else does.
 *
 * PHP stores the arguments of every call on the stack in the trace of an exception or an error. It has no switch for
 * one exception, only the setting zend.exception_ignore_args for the whole process, and that setting is the host's.
 * print_r, var_export, var_dump and serialize show those arguments, and the string form shows their first bytes. A
 * parameter marked as sensitive is hidden from those forms only: the trace holds a SensitiveParameterValue in its
 * place, and its getValue() gives the argument back to whoever walks the trace. So the trace keeps where, never with
 * what: file, line, class and function stay.
 *
 * @internal not part of the contract: a host project must not call it, and it can change in any release
 */
final readonly class Traces
{
    /**
     * The same object, with no arguments left in its own trace. A previous exception is not touched: the library
     * chains none. Never throws: when the trace cannot be written, the exception keeps the trace PHP gave it.
     *
     * @template T of Throwable
     * @param T $thrown the exception that is being built: called as the last line of its constructor
     * @return T
     */
    public static function withoutArguments(#[SensitiveParameter] Throwable $thrown): Throwable
    {
        $trace = [];
        foreach ($thrown->getTrace() as $frame) {
            unset($frame['args']);
            $trace[] = $frame;
        }

        // The trace is a private property of the two root classes of everything that can be thrown.
        $root = $thrown instanceof Exception ? Exception::class : Error::class;

        try {
            (new ReflectionProperty($root, 'trace'))->setValue($thrown, $trace);
        } catch (Throwable) {
            // A version of PHP that no longer lets the trace be written. The exception that is being built must
            // still be the one that is thrown: a failure here would take its place, with the half-built exception
            // and every argument in its own trace, and a host's catch would no longer match. It then keeps the
            // trace PHP gave it, which is the lesser harm. This is not silent: the architecture test "keeps no
            // argument of any call in the trace of any class of src that can be thrown" builds every exception of
            // the library on every PHP version of the CI matrix and fails when an argument is left.
        }

        return $thrown;
    }
}
