<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\StructuredAnswerChecker;
use OrganiseYou\LlmGateway\Contract\ContentPart;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplier;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\Stream\StreamEvent;
use OrganiseYou\LlmGateway\Testing\AdapterFixtures;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;
use OrganiseYou\LlmGateway\Transport\AttemptObserver;
use OrganiseYou\LlmGateway\Transport\Clock;
use OrganiseYou\LlmGateway\Transport\Transport;

// LR-14 and the project guide: the library never runs a tool. It hands a tool call to the caller's executor and
// takes the result back as a message. More widely: the library runs no code of the caller except through the
// interfaces it documents for that. So nothing in src takes a callable, calls through a variable or builds a class
// whose name it was handed; and the interfaces of src are a fixed list, so a new way into the caller's code is a
// decision someone wrote down here.

/**
 * The interfaces of the library. A host implements the first group; the library calls them where the design says.
 *
 * @return array<string, string> interface => what the library does with it
 */
function interfacesOfTheLibrary(): array
{
    return [
        Gateway::class => 'what the library gives the caller; the adapters and the fake implement it',
        Transport::class => 'the caller\'s way out: called once per attempt (design 12.1)',
        Clock::class => 'the caller\'s clock: asked for the time and for a wait (design 8.4)',
        AttemptObserver::class => 'the caller\'s log: told before and after every attempt, and what it throws is dropped (design 12.6)',
        TokenSupplier::class => 'the caller\'s token supplier: asked once per attempt, in BearerToken::token() alone (design 11.2)',
        Credentials::class => 'the kinds of credentials; the adapter base takes only the four of the library',
        ContentPart::class => 'a marker: the kinds of content of a message',
        StreamEvent::class => 'a marker: the events of a stream',
        ContractSubject::class => 'a gateway under the shared test set, in a test',
        AdapterFixtures::class => 'the responses of a format for the shared test set, in a test',
        // The tool loop (task LG-22) adds the one seam through which a tool runs: an interface the caller
        // implements, in src/Loop. Until then it does not exist, and nothing in src can run a tool.
        'OrganiseYou\LlmGateway\Loop\ToolExecutor' => 'the caller\'s tools: the loop hands it one tool call and gets the result back (LR-14)',
    ];
}

/**
 * The places in src that run code they were handed, on purpose: each is one call in one file. A second finding of
 * the same kind in the same file is not covered by the entry and fails.
 *
 * @return array<string, string> "file: finding" without the line => why
 */
function callsOfHandedCodeThatAreMeant(): array
{
    return [
        'Adapter/Shared/StructuredAnswerChecker.php: a callable type' => 'the error handler the host had installed, as PHP returns it from set_error_handler() (G50)',
        'Adapter/Shared/StructuredAnswerChecker.php: a call through $ofHost' => 'hands a warning that is not the validator\'s own on to that handler of the host (G50)',
        'GatewayFactory.php: a class built from an expression' => 'builds the adapter the preset names; Preset refuses a class that is no adapter (design 13.3)',
    ];
}

/**
 * The functions of PHP that call a callback, with the place of the callback among their arguments, from 0.
 *
 * @return array<string, int>
 */
function functionsThatTakeACallback(): array
{
    return [
        'array_map' => 0, 'array_filter' => 1, 'array_walk' => 1, 'array_walk_recursive' => 1, 'array_reduce' => 1,
        'usort' => 1, 'uasort' => 1, 'uksort' => 1, 'array_find' => 1, 'array_find_key' => 1, 'array_any' => 1,
        'array_all' => 1, 'preg_replace_callback' => 1, 'iterator_apply' => 1, 'array_udiff' => 2,
        'array_uintersect' => 2, 'set_error_handler' => 0, 'set_exception_handler' => 0,
    ];
}

/**
 * Whether the argument that starts at this token is code the file writes itself: a closure, an arrow function, or
 * a first-class callable of a function or of a method of its own class (`strtolower(...)`, `self::clean(...)`),
 * what a method of its own class returns (`self::handler($a)`), or no callback (`null`).
 * Anything else (a variable, a property, a string, an array) can be code that was handed in.
 *
 * @param list<PhpToken> $tokens
 */
function isACallbackOfItsOwn(array $tokens, int $start): bool
{
    $at = $start;
    if ($tokens[$at]->is(T_STATIC)) {
        $at++;
    }
    if (($tokens[$at] ?? null)?->is([T_FUNCTION, T_FN]) === true) {
        return true;
    }

    // No callback at all, or what a method of its own class made: `self::handler($a)`.
    if (strtolower($tokens[$start]->text) === 'null' && in_array(($tokens[$start + 1] ?? null)?->text, [',', ')'], true)) {
        return true;
    }
    if (in_array(strtolower($tokens[$start]->text), ['self', 'static'], true)
        && ($tokens[$start + 1] ?? null)?->is(T_DOUBLE_COLON) === true
        && ($tokens[$start + 2] ?? null)?->is(T_STRING) === true
        && ($tokens[$start + 3] ?? null)?->text === '(') {
        return true;
    }

    $at = $start;
    if ($tokens[$at]->is([T_STRING, T_STATIC, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && ($tokens[$at + 1] ?? null)?->is(T_DOUBLE_COLON) === true) {
        $at += 2;
    }

    return ($tokens[$at] ?? null)?->is([T_STRING, T_NAME_FULLY_QUALIFIED]) === true
        && ($tokens[$at + 1] ?? null)?->text === '('
        && ($tokens[$at + 2] ?? null)?->is(T_ELLIPSIS) === true
        && ($tokens[$at + 3] ?? null)?->text === ')';
}

/**
 * The token at which the argument with this place starts, in the call whose opening parenthesis is at $open; null
 * when the call has fewer arguments.
 *
 * @param list<PhpToken> $tokens
 */
function argumentStart(array $tokens, int $open, int $place): ?int
{
    $depth = 0;
    $argument = 0;
    for ($at = $open + 1; isset($tokens[$at]); $at++) {
        $text = $tokens[$at]->text;
        if ($depth === 0 && $text === ')') {
            return null;
        }
        if ($depth === 0 && $argument === $place) {
            return $at;
        }
        if (in_array($text, ['(', '[', '{'], true) || $tokens[$at]->is(T_ATTRIBUTE)) {
            $depth++;
        } elseif (in_array($text, [')', ']', '}'], true)) {
            $depth--;
        } elseif ($depth === 0 && $text === ',') {
            $argument++;
        }
    }

    return null;
}

/**
 * The places in a piece of PHP code that take or run code handed to it: a callable type, a call through a
 * variable or through an expression, a class or a method named by a variable, the PHP functions that call a
 * callable they are given by name, and a callback handed to a function such as array_map() or usort() that the
 * code did not write on the spot. A closure the code writes itself and hands to array_map() is its own code and is
 * not found.
 *
 * @return list<string> one line per finding, with the line number in the code
 */
function callsOfHandedCode(string $code): array
{
    $byName = [
        'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'is_callable',
        'func_overload', 'register_shutdown_function', 'register_tick_function', 'spl_autoload_register',
        'eval', 'assert', 'create_function',
    ];
    $methods = ['__invoke', 'call', 'bindto', 'fromcallable', 'newinstance', 'newinstanceargs', 'invoke', 'invokeargs'];

    $tokens = SourceCode::tokens($code);

    $found = [];
    foreach ($tokens as $index => $token) {
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;
        $line = 'line ' . $token->line . ': ';

        if ($token->is(T_CALLABLE)) {
            $found[] = $line . 'a callable type';

            continue;
        }
        if ($token->is(T_EVAL)) {
            $found[] = $line . 'eval()';

            continue;
        }
        if ($token->is(T_VARIABLE) && $next !== null && $next->text === '(' && ($previous === null || ! $previous->is(T_NEW))) {
            $found[] = $line . ($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
                ? 'a method named by ' . $token->text
                : 'a call through ' . $token->text);

            continue;
        }
        if ($token->is(T_NEW) && $next !== null && ($next->is(T_VARIABLE) || $next->text === '(')) {
            $found[] = $line . ($next->is(T_VARIABLE) ? 'a class named by ' . $next->text : 'a class built from an expression');

            continue;
        }
        if ($token->text === ')' && $next !== null && $next->text === '(' && ! isNewWithExpression($tokens, $index)) {
            $found[] = $line . 'a call of what an expression gives';

            continue;
        }
        if ($token->is(T_STRING) && $next !== null && $next->text === '('
            && $previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
            && in_array(strtolower($token->text), $methods, true)) {
            $found[] = $line . '->' . $token->text . '()';

            continue;
        }

        $function = SourceCode::calledFunction($tokens, $index);
        if ($function !== null && in_array($function, $byName, true)) {
            $found[] = $line . $function . '()';

            continue;
        }

        $place = $function === null ? null : functionsThatTakeACallback()[$function] ?? null;
        if ($function !== null && $place !== null) {
            $start = argumentStart($tokens, $index + 1, $place);
            // A first-class callable of the function itself, array_map(...), hands nothing in here.
            $itself = ($tokens[$index + 2] ?? null)?->is(T_ELLIPSIS) === true;
            if ($start !== null && ! $itself && ! isACallbackOfItsOwn($tokens, $start)) {
                $found[] = $line . 'a callback of ' . $function . '() that is not written there';
            }
        }
    }

    return $found;
}

/**
 * Whether the closing parenthesis at this token ends the expression of `new (…)`: what follows are the arguments
 * of the constructor, and the `new` itself is already a finding.
 *
 * @param list<PhpToken> $tokens
 */
function isNewWithExpression(array $tokens, int $closing): bool
{
    $depth = 0;
    for ($at = $closing; $at >= 0; $at--) {
        if ($tokens[$at]->text === ')') {
            $depth++;
        } elseif ($tokens[$at]->text === '(') {
            $depth--;
            if ($depth === 0) {
                return ($tokens[$at - 1] ?? null)?->is(T_NEW) === true;
            }
        }
    }

    return false;
}

/**
 * The findings of one file that no entry of the list covers. One entry covers one call: the second finding of the
 * same kind in the same file is a finding.
 *
 * @param list<string> $found
 * @param array<string, string> $meant
 * @param array<string, true> $used the entries that covered a finding so far; added to
 * @return list<string>
 */
function findingsThatAreNotMeant(string $file, array $found, array $meant, array &$used): array
{
    $findings = [];
    foreach ($found as $finding) {
        $what = $file . ': ' . preg_replace('/^line \d+: /', '', $finding);
        if (isset($meant[$what]) && ! isset($used[$what])) {
            $used[$what] = true;
        } else {
            $findings[] = $file . ' ' . $finding;
        }
    }

    return $findings;
}

it('covers one call with one entry of the list: a second call of the same kind in the same file fails', function (): void {
    $meant = ['Checker.php: a call through $ofHost' => 'the handler of the host'];
    $used = [];

    $once = findingsThatAreNotMeant('Checker.php', callsOfHandedCode("<?php\n\$ofHost(\$level);"), $meant, $used);
    $usedOnce = $used;
    $used = [];
    $twice = findingsThatAreNotMeant('Checker.php', callsOfHandedCode("<?php\n\$ofHost(\$level);\n\$ofHost(\$other);"), $meant, $used);
    $used = [];
    $elsewhere = findingsThatAreNotMeant('Other.php', callsOfHandedCode("<?php\n\$ofHost(\$level);"), $meant, $used);

    expect($once)->toBe([])
        ->and(array_keys($usedOnce))->toBe(['Checker.php: a call through $ofHost'])
        ->and($twice)->toBe(['Checker.php line 3: a call through $ofHost'])
        ->and($elsewhere)->toBe(['Other.php line 2: a call through $ofHost']);
});

it('runs no code it was handed anywhere in src, but for the listed places', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $meant = callsOfHandedCodeThatAreMeant();
    $used = [];
    $findings = [];

    foreach (SourceCode::phpFilesUnder($src) as $path) {
        $file = substr($path, strlen($src) + 1);
        $findings = [...$findings, ...findingsThatAreNotMeant($file, callsOfHandedCode((string) file_get_contents($path)), $meant, $used)];
    }

    expect($findings)->toBe([])
        ->and(array_keys($used))->toEqualCanonicalizing(array_keys($meant), 'an entry of the list of meant places matches nothing any more');
});

it('takes no callable and no closure as a parameter or a property anywhere in src, but for the error handler of the host', function (): void {
    $found = [];
    foreach (SourceCode::classesOfTheLibrary(dirname(__DIR__, 2) . '/src') as $class) {
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class && preg_match('/\b(callable|Closure)\b/', (string) $property->getType()) === 1) {
                $found[] = $class . '::$' . $property->getName();
            }
        }
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                if (preg_match('/\b(callable|Closure)\b/', (string) $parameter->getType()) === 1) {
                    $found[] = $class . '::' . $method->getName() . '($' . $parameter->getName() . ')';
                }
            }
        }
    }

    expect($found)->toBe([StructuredAnswerChecker::class . '::quietFor($ofHost)']);
});

it('has no interface in src besides the listed ones, so a new way into the code of the caller is written down', function (): void {
    $listed = interfacesOfTheLibrary();
    $found = [];
    foreach (SourceCode::classesOfTheLibrary(dirname(__DIR__, 2) . '/src') as $class) {
        if (interface_exists($class)) {
            $found[] = $class;
        }
    }

    expect(array_values(array_diff($found, array_keys($listed))))->toBe([])
        ->and(array_values(array_diff(array_keys($listed), $found)))->toBeIn([[], ['OrganiseYou\LlmGateway\Loop\ToolExecutor']]);
});

it('has nothing that can run a tool before the tool loop exists, and only the loop may hold its executor', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $mentions = [];
    foreach (SourceCode::phpFilesUnder($src) as $path) {
        $file = substr($path, strlen($src) + 1);
        foreach (SourceCode::tokens((string) file_get_contents($path)) as $token) {
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && SourceCode::shortName($token->text) === 'toolexecutor' && ! str_starts_with($file, 'Loop/')) {
                $mentions[] = $file . ' line ' . $token->line;
            }
        }
    }

    expect($mentions)->toBe([]);
});

it('catches code that runs what it was handed', function (string $code, string $finding): void {
    expect(callsOfHandedCode("<?php\n" . $code))->toBe(['line 2: ' . $finding]);
})->with([
    'a callable parameter' => ['function run(callable $tool): void {}', 'a callable type'],
    'a nullable callable property' => ['final class A { private ?callable $tool; }', 'a callable type'],
    'a call through a variable' => ['$result = $tool($arguments);', 'a call through $tool'],
    'a call through a variable, first-class' => ['$run = $tool(...);', 'a call through $tool'],
    'a call through a property' => ['$result = ($this->tool)($arguments);', 'a call of what an expression gives'],
    'a call through an array entry' => ['$result = ($tools[$name])($arguments);', 'a call of what an expression gives'],
    'a method named by a variable' => ['$result = $tools->$name($arguments);', 'a method named by $name'],
    'a static method named by a variable' => ['$result = Tools::$name($arguments);', 'a method named by $name'],
    'a class named by a variable' => ['$tool = new $class();', 'a class named by $class'],
    'a class built from an expression' => ['$tool = new ($this->tools[$name])($arguments);', 'a class built from an expression'],
    'call_user_func' => ['$result = call_user_func($this->tool, $arguments);', 'call_user_func()'],
    'call_user_func_array' => ['$result = \call_user_func_array([$tools, $name], $arguments);', 'call_user_func_array()'],
    'an invokable object' => ['$result = $this->tool->__invoke($arguments);', '->__invoke()'],
    'a closure called with another object' => ['$result = $this->tool->call($scope, $arguments);', '->call()'],
    'a closure made from a callable' => ['$run = Closure::fromCallable($this->tool);', '->fromCallable()'],
    'a method run through reflection' => ['$result = $method->invoke($tools, $arguments);', '->invoke()'],
    'a class built through reflection' => ['$tool = $class->newInstance();', '->newInstance()'],
    'eval' => ['eval($code);', 'eval()'],
    'a function at the end of the process' => ['register_shutdown_function([$this, "flush"]);', 'register_shutdown_function()'],
    'a callback in a variable, for array_map' => ['$results = array_map($tool, $calls);', 'a callback of array_map() that is not written there'],
    'a callback in a property, for array_filter' => ['$kept = array_filter($calls, $this->keep);', 'a callback of array_filter() that is not written there'],
    'a callback by its name, for usort' => ['usort($calls, "compare_calls");', 'a callback of usort() that is not written there'],
    'a callback as an array, for array_walk' => ['array_walk($calls, [$tools, "run"]);', 'a callback of array_walk() that is not written there'],
    'a callback in a variable, for array_reduce' => ['$sum = array_reduce(fold($calls), $fold, 0);', 'a callback of array_reduce() that is not written there'],
    'a callback in a variable, for uasort and uksort' => ['uasort($a, $this->order[0]);', 'a callback of uasort() that is not written there'],
    'a callback for a pattern' => ['$text = preg_replace_callback("/a(b)/", $replace, $text);', 'a callback of preg_replace_callback() that is not written there'],
    'a callback for an iterator' => ['iterator_apply($calls, $tool, [$calls]);', 'a callback of iterator_apply() that is not written there'],
    'a first-class callable of a variable' => ['$results = array_map($tools->run(...), $calls);', 'a callback of array_map() that is not written there'],
    'an error handler that was handed in' => ['set_error_handler($handler);', 'a callback of set_error_handler() that is not written there'],
]);

it('does not flag the code the library writes itself', function (string $code): void {
    expect(callsOfHandedCode("<?php\n" . $code))->toBe([]);
})->with([
    'a method of an interface the caller implements' => ['$response = $this->transport->send($request); $token = $supplier->token();'],
    'a closure of its own, handed to a function of PHP' => ['$names = array_map(fn(Tool $tool): string => $tool->name, $tools); usort($a, static fn($b, $c) => $b <=> $c);'],
    'a closure of its own with a body' => ['$dump = function (mixed $subject) use ($depth): string { return (string) $subject; };'],
    'a first-class callable of a function of PHP' => ['$lower = array_map(strtolower(...), $words); $kept = array_filter($items, \is_array(...));'],
    'a first-class callable of a method of its own class' => ['$clean = array_map(self::clean(...), $words); usort($a, static::order(...));'],
    'a static closure and a closure with a body' => ['usort($a, static function ($b, $c): int { return $b <=> $c; }); array_walk($a, function (&$b): void { $b++; });'],
    'a function that filters without a callback' => ['$kept = array_filter($items); $same = array_map(...);'],
    'a nested call before the callback' => ['$sum = array_reduce(array_merge($a, [$b, $c]), fn(int $d, int $e): int => $d + $e, 0);'],
    'no handler, and one a method of its own class makes' => ['$before = set_error_handler(null); set_error_handler(self::quietFor($folders, $before));'],
    'a method of that name' => ['$results = $list->array_map($tool); $sorted = Sorter::usort($a, $order);'],
    'a new object of a named class, with and without arguments' => ['$a = new Answer($text); $b = new self(); $c = new static($d); $e = new class ($f) {};'],
    'a condition followed by a parenthesis' => ['if ($a) { ($b)->c(); } $d = (int) ($e);'],
    'a comment and a string that name them' => ['// never call_user_func($tool) or $tool($arguments)' . "\n" . '$rule = "callable $tool and new $class()";'],
    'a method or a constant of that name on something else' => ['$a = self::CALL; $b = $this->invoke; $c = $d->callable;'],
    'a variable that is not called' => ['$tool = $tools[$name]; return ($tool);'],
]);
