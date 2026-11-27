<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\Internal\SchemaCheckTooCostly;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\SchemaReferenceLoop;
use OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplierFailed;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\ForeignFault;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\WrongUse;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;
use OrganiseYou\LlmGateway\Transport\TransportException;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

// Decision G63, LR-26: whatever the library throws keeps no argument of any call in its trace. PHP has no switch
// for one exception: only the setting zend.exception_ignore_args for the whole process, which is the host's. And a
// parameter marked as sensitive is hidden from the printed forms of a trace only: the trace holds a
// SensitiveParameterValue, whose getValue() gives the argument back. So the library throws only its own exception
// classes, and each of them takes the arguments out of its own trace in its constructor. A throw site is plain:
// `throw new InvalidValue('…')`. It cannot be forgotten, because nothing in src may build a PHP exception itself.

/**
 * The three classes the library throws in place of the PHP class each extends.
 *
 * @return list<class-string<Throwable>>
 */
function plainExceptionsOfTheLibrary(): array
{
    return [InvalidValue::class, WrongUse::class, ForeignFault::class];
}

/**
 * The other classes of src that can be thrown and extend none of the three, and why.
 *
 * @return array<class-string<Throwable>, string>
 */
function exceptionsWithAParentOfTheirOwn(): array
{
    return [
        GatewayException::class => 'the one error of a gateway call: a host catches it by its own name, and as a RuntimeException',
        TransportException::class => 'the one error of a transport, which a transport of a host builds too: a RuntimeException that may chain what the host caught',
        TokenSupplierFailed::class => 'a failed sign-in, caught by its own name in the adapter base: a RuntimeException, not a refused value or a wrong use',
        SchemaCheckTooCostly::class => 'internal: thrown by the loop guard and caught by the structured check, it never leaves the library',
        SchemaReferenceLoop::class => 'internal: thrown by the loop guard and caught by the structured check, it never leaves the library',
    ];
}

/**
 * The places in src that build a PHP exception themselves, and why. Each entry covers one finding in one file.
 *
 * @return array<string, string> "file: finding" => why
 */
function phpExceptionsBuiltOnPurpose(): array
{
    return [
        'Testing/ContractTestCase.php: new Exception' => 'never thrown: built to see whether PHP keeps the arguments of calls in a trace here, which only a plain exception shows',
    ];
}

/**
 * Every class of src that can be thrown.
 *
 * @return array<string, class-string<Throwable>> the path under src => the class
 */
function throwablesOfTheLibrary(string $src): array
{
    $throwables = [];
    foreach (SourceCode::classesOfTheLibrary($src) as $file => $class) {
        if (is_subclass_of($class, Throwable::class)) {
            $throwables[$file] = $class;
        }
    }

    return $throwables;
}

/**
 * The exceptions and errors a piece of PHP code builds that are not classes of the library. A name is read as the
 * `use` lines of the code spell it out, so an alias does not hide a class. Found are: a class whose name ends in
 * Exception or Error, a class PHP or an extension brings that can be thrown, and whatever stands after `throw new`.
 *
 * @param list<string> $ofTheLibrary the short names of the library's own classes that can be thrown
 * @return list<string> one line per finding, with the line number in the code
 */
function phpThrowablesBuilt(string $code, array $ofTheLibrary): array
{
    $tokens = SourceCode::tokens($code);
    $own = array_map(strtolower(...), $ofTheLibrary);

    // The names the `use` lines give: the last part of a name, or its alias, to the full name.
    $imported = [];
    foreach ($tokens as $index => $token) {
        $name = $tokens[$index + 1] ?? null;
        $after = $tokens[$index + 2] ?? null;
        $alias = $tokens[$index + 3] ?? null;
        if (! $token->is(T_USE) || $name === null || ! $name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }
        if ($after !== null && $after->is(T_AS) && $alias !== null) {
            $imported[strtolower($alias->text)] = ltrim($name->text, '\\');
        } else {
            $imported[SourceCode::shortName($name->text)] = ltrim($name->text, '\\');
        }
    }

    $found = [];
    foreach ($tokens as $index => $token) {
        $name = $tokens[$index + 1] ?? null;
        if (! $token->is(T_NEW) || $name === null || ! $name->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        // The class the name stands for: its first part is read through the `use` lines.
        $written = ltrim($name->text, '\\');
        $first = strtolower((string) strstr($written . '\\', '\\', true));
        $class = $name->is(T_NAME_FULLY_QUALIFIED) || ! isset($imported[$first])
            ? $written
            : $imported[$first] . substr($written, strlen($first));

        $short = SourceCode::shortName($class);
        $ofTheLibrary = in_array($short, $own, true) && (! str_contains($class, '\\') || str_starts_with($class, 'OrganiseYou\\LlmGateway\\'));
        if ($ofTheLibrary) {
            continue;
        }

        $builtIn = class_exists($class, false) && (new ReflectionClass($class))->isInternal() && is_subclass_of($class, Throwable::class);
        $thrown = ($tokens[$index - 1] ?? null)?->is(T_THROW) === true;
        if ($builtIn || $thrown || preg_match('/(exception|error)$/', $short) === 1) {
            $found[] = 'line ' . $token->line . ': new ' . $name->text . ($class === $written ? '' : ' (' . $class . ')');
        }
    }

    return $found;
}

/**
 * One object of every class of src that can be thrown, built below a call whose arguments hold the marker.
 *
 * @return array<class-string<Throwable>, Throwable>
 */
function oneOfEveryThrowable(#[SensitiveParameter] string $secret, string $body): array
{
    return [
        InvalidValue::class => new InvalidValue('made up'),
        WrongUse::class => new WrongUse('made up'),
        ForeignFault::class => new ForeignFault('made up'),
        GatewayException::class => new GatewayException(ErrorReason::Timeout, 'trace-1'),
        TransportException::class => new TransportException(TransportFailure::ConnectionFailed, 'made up'),
        TokenSupplierFailed::class => TokenSupplierFailed::threw('RuntimeException'),
        SchemaCheckTooCostly::class => new SchemaCheckTooCostly(),
        SchemaReferenceLoop::class => new SchemaReferenceLoop(),
    ];
}

it('builds no exception or error of PHP anywhere in src, but for the listed places', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $own = array_map(SourceCode::shortName(...), array_values(throwablesOfTheLibrary($src)));
    $meant = phpExceptionsBuiltOnPurpose();
    $used = [];

    $files = SourceCode::phpFilesUnder($src);
    expect(count($files))->toBeGreaterThan(40, 'the rule must have found the source files');

    $findings = [];
    foreach ($files as $path) {
        $file = substr($path, strlen($src) + 1);
        foreach (phpThrowablesBuilt((string) file_get_contents($path), $own) as $finding) {
            $key = $file . ': ' . substr($finding, (int) strpos($finding, 'new '));
            if (isset($meant[$key]) && ! isset($used[$key])) {
                $used[$key] = true;

                continue;
            }
            $findings[] = $file . ' ' . $finding;
        }
    }

    expect($findings)->toBe([])
        ->and(array_values(array_diff(array_keys($meant), array_keys($used))))->toBe([], 'every listed place must still be needed');
});

it('has every class of src that can be thrown extend one of the three, or be listed with its reason', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $three = plainExceptionsOfTheLibrary();
    $listed = exceptionsWithAParentOfTheirOwn();

    $throwables = array_values(throwablesOfTheLibrary($src));
    expect(count($throwables))->toBeGreaterThanOrEqual(8, 'the rule must have found the exceptions of the library');

    $notListed = [];
    foreach ($throwables as $class) {
        $isOneOfThem = in_array($class, $three, true) || array_filter($three, fn(string $parent): bool => is_subclass_of($class, $parent)) !== [];
        if (! $isOneOfThem && ! isset($listed[$class])) {
            $notListed[] = $class;
        }
    }

    expect($notListed)->toBe([])
        ->and(array_values(array_diff(array_keys($listed), $throwables)))->toBe([], 'every listed class must exist in src');
    foreach ($listed as $class => $why) {
        expect(strlen($why))->toBeGreaterThan(30, $class . ' needs a reason');
    }
});

it('gives the three the PHP class a host already catches, final, with a text and nothing chained', function (string $class, string $parent): void {
    assert(class_exists($class));
    $reflection = new ReflectionClass($class);
    $parameters = $reflection->getConstructor()?->getParameters() ?? [];

    expect(is_subclass_of($class, $parent))->toBeTrue()
        ->and($reflection->getParentClass() === false ? null : $reflection->getParentClass()->getName())->toBe($parent)
        ->and($reflection->isFinal())->toBeTrue()
        ->and($reflection->getNamespaceName())->toBe('OrganiseYou\LlmGateway\Contract')
        ->and(array_map(fn(ReflectionParameter $parameter): string => $parameter->getName(), $parameters))->toBe(['message']);

    $thrown = new $class('made up');
    assert($thrown instanceof Throwable);

    expect($thrown->getMessage())->toBe('made up')
        ->and($thrown->getPrevious())->toBeNull()
        ->and($thrown->getCode())->toBe(0)
        ->and($thrown->getFile())->toBe(__FILE__);
})->with([
    [InvalidValue::class, InvalidArgumentException::class],
    [WrongUse::class, LogicException::class],
    [ForeignFault::class, Error::class],
]);

it('keeps no argument of any call in the trace of any class of src that can be thrown', function (): void {
    $src = dirname(__DIR__, 2) . '/src';

    withExceptionIgnoreArgs(false, function () use ($src): void {
        $built = oneOfEveryThrowable(LEAK_MARKER, '{"echo":"' . LEAK_MARKER . '"}');

        expect(array_values(array_diff(array_values(throwablesOfTheLibrary($src)), array_keys($built))))->toBe([], 'the test must build one of every class');

        // The proof that there was something to leave out: a plain exception built in the same place keeps it.
        $plain = (fn(#[SensitiveParameter] string $secret): Exception => new Exception('made up'))(LEAK_MARKER);
        expect($plain->getTrace()[0]['args'][0] ?? null)->toBeInstanceOf(SensitiveParameterValue::class);

        foreach ($built as $class => $thrown) {
            expect($thrown::class)->toBe($class);
            expectNoArgumentsInTrace($thrown);
        }
    });
});

it('writes the trace in one place, which only the constructors of those classes call', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $throwables = array_keys(throwablesOfTheLibrary($src));

    $callers = [];
    $writers = [];
    foreach (SourceCode::phpFilesUnder($src) as $path) {
        $file = substr($path, strlen($src) + 1);
        $text = implode(' ', array_map(fn(PhpToken $token): string => $token->text, SourceCode::tokens((string) file_get_contents($path))));
        if ($file !== 'Contract/Internal/Traces.php' && str_contains($text, 'withoutArguments')) {
            expect($text)->toContain('Traces :: withoutArguments ( $this )');
            expect(substr_count($text, 'withoutArguments'))->toBe(1, $file . ' calls it once, on itself');
            $callers[] = $file;
        }
        if (str_contains($text, 'ReflectionProperty') && preg_match('/setValue|setRawValue/', $text) === 1) {
            $writers[] = $file;
        }
    }
    sort($callers);
    sort($throwables);

    expect($callers)->toBe($throwables)
        ->and($writers)->toBe(['Contract/Internal/Traces.php']);
});

it('catches an exception or an error of PHP that is built directly', function (string $code, string $finding): void {
    expect(array_map(fn(string $found): string => (string) preg_replace('/^line \d+: /', '', $found), phpThrowablesBuilt("<?php\n" . $code, ['InvalidValue', 'GatewayException'])))->toBe([$finding]);
})->with([
    'a refusal' => ["throw new InvalidArgumentException('The key must not be empty.');", 'new InvalidArgumentException'],
    'a logic exception' => ["throw new LogicException('No.');", 'new LogicException'],
    'a runtime exception' => ["throw new RuntimeException('No.');", 'new RuntimeException'],
    'an error' => ["return new Error('The client failed.');", 'new Error'],
    'an error by its full name' => ["throw new \\Error('The client failed.');", 'new \\Error'],
    'the root of all exceptions' => ["\$probe = new Exception();", 'new Exception'],
    'a type error' => ["throw new TypeError('No.');", 'new TypeError'],
    'an exception of another package' => ["throw new \\Vendor\\Sdk\\ClientException('No.');", 'new \\Vendor\\Sdk\\ClientException'],
    'an error of arithmetic' => ['throw new ArithmeticError();', 'new ArithmeticError'],
    'an exception of PHP under another name' => ["use RuntimeException as Boom;\nthrow new Boom('x');", 'new Boom (RuntimeException)'],
    'an exception of PHP under another name, kept first' => ["use LogicException as Trouble;\n\$a = new Trouble('x');", 'new Trouble (LogicException)'],
    'a class of another package that is thrown, whatever its name' => ['throw new \\Vendor\\Sdk\\Refusal($a);', 'new \\Vendor\\Sdk\\Refusal'],
    'an imported class of another package that is thrown' => ["use Vendor\\Sdk\\Refusal;\nthrow new Refusal(\$a);", 'new Refusal (Vendor\\Sdk\\Refusal)'],
    'a class of another package with the name of one of the library' => ["use Vendor\\Sdk\\InvalidValue;\nthrow new InvalidValue('x');", 'new InvalidValue (Vendor\\Sdk\\InvalidValue)'],
    'an exception of an extension' => ['throw new JsonException();', 'new JsonException'],
]);

it('does not flag a class of the library, or what is no exception', function (string $code): void {
    expect(phpThrowablesBuilt("<?php\n" . $code, ['InvalidValue', 'GatewayException']))->toBe([]);
})->with([
    'the plain class of the library' => ["throw new InvalidValue('The key must not be empty.');"],
    'the error of a gateway call' => ['throw new GatewayException(ErrorReason::Timeout, $traceId);'],
    'a class of the library that is imported' => ["use OrganiseYou\\LlmGateway\\Contract\\InvalidValue;\nthrow new InvalidValue('x');"],
    'what a method gives, thrown' => ['throw $this->failure(ErrorReason::Rejected, "no"); throw self::fault($class);'],
    'a class that only holds the word' => ['$a = new ErrorFormatter(); $b = new ExceptionList(); $c = new Secret($d);'],
    'a comment and a string' => ["// never new Error() here\n\$rule = 'new InvalidArgumentException()';"],
    'a catch and a type' => ['try { $a(); } catch (InvalidArgumentException|Error $thrown) { $b = $thrown instanceof TypeError; }'],
]);
