<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Tests\Support\SourceCode;

// LR-01 and LR-25: the library keeps nothing between two calls and reads nothing the host did not hand over. No
// code in src/ may read the environment, a setting of PHP or a file, write a file, or keep a value in a static
// property or a static variable.

/**
 * The places in a piece of PHP code that read the environment or a file, write a file, or keep state outside an
 * object. Comments and the text of strings are not code and are not searched.
 *
 * @return list<string> one line per finding, with the line number in the code
 */
function environmentAndStateUses(string $code): array
{
    // The environment of the process and the settings of PHP.
    $environment = [
        'getenv', 'putenv', 'apache_getenv', 'apache_setenv', 'ini_get', 'ini_get_all', 'ini_set', 'ini_alter',
        'ini_restore', 'get_cfg_var', 'php_ini_loaded_file', 'php_ini_scanned_files', 'parse_ini_file',
        'get_include_path', 'set_include_path', 'filter_input', 'filter_input_array', 'getcwd', 'chdir', 'getopt',
        'readline', 'define',
    ];
    $variables = [
        '$_ENV', '$_SERVER', '$_GET', '$_POST', '$_COOKIE', '$_FILES', '$_REQUEST', '$_SESSION', '$GLOBALS', '$argv',
        '$argc',
    ];
    // These take a name. Only a spelled-out php://memory is known not to be a file: php://temp writes one when it grows.
    $byName = ['file_get_contents', 'fopen', 'file', 'readfile', 'file_put_contents'];
    // These touch the file system whatever they are given.
    $files = [
        'tempnam', 'tmpfile', 'mkdir', 'rmdir', 'touch', 'unlink', 'rename', 'copy', 'chmod', 'chown', 'chgrp',
        'symlink', 'link', 'move_uploaded_file', 'error_log', 'file_exists', 'is_file', 'is_dir', 'is_readable',
        'is_writable', 'is_writeable', 'filemtime', 'filesize', 'stat', 'lstat', 'glob', 'scandir', 'opendir',
        'realpath', 'sys_get_temp_dir', 'session_start', 'syslog', 'openlog', 'fileperms', 'filectime', 'fileatime',
        'is_link', 'is_executable', 'readlink', 'dir',
    ];
    // These take a handle. Only a handle the same code opened on a stream in memory is known not to be a file.
    $byHandle = [
        'fread', 'fgets', 'fgetc', 'fgetcsv', 'fscanf', 'fpassthru', 'stream_get_contents', 'stream_get_line',
        'fwrite', 'fputs', 'fputcsv', 'fprintf', 'vfprintf', 'ftruncate', 'fflush', 'flock',
    ];
    // Classes whose objects read or write a file, a folder or a store outside the process.
    $classes = [
        'splfileobject', 'spltempfileobject', 'directoryiterator', 'filesystemiterator', 'recursivedirectoryiterator',
        'globiterator', 'pdo', 'sqlite3',
    ];

    $tokens = SourceCode::tokens($code);

    // The variables that are given a stream in memory: $stream = fopen('php://memory', 'r+');
    $inMemory = [];
    foreach ($tokens as $index => $token) {
        if ($token->is(T_VARIABLE)
            && ($tokens[$index + 1] ?? null)?->text === '='
            && isset($tokens[$index + 2])
            && SourceCode::calledFunction($tokens, $index + 2) === 'fopen'
            && SourceCode::isInMemoryStream($tokens[$index + 4] ?? null, false)) {
            $inMemory[$token->text] = true;
        }
    }

    $found = [];
    foreach ($tokens as $index => $token) {
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        if ($token->is(T_VARIABLE) && in_array($token->text, $variables, true)) {
            $found[] = 'line ' . $token->line . ': ' . $token->text;

            continue;
        }

        if ($token->is(T_GLOBAL)) {
            $found[] = 'line ' . $token->line . ': a global variable';

            continue;
        }

        if ($token->is(T_STATIC)) {
            $variable = staticVariableAfter($tokens, $index);
            if ($variable !== null && ($previous === null || ! $previous->is([T_NEW, T_INSTANCEOF]))) {
                $found[] = 'line ' . $token->line . ': static ' . $variable;
            }

            continue;
        }

        if ($token->is(T_NEW) && $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $argument = ($tokens[$index + 2] ?? null)?->text === '(' ? $tokens[$index + 3] ?? null : null;
            if (in_array(SourceCode::shortName($next->text), $classes, true) && ! SourceCode::isInMemoryStream($argument, false)) {
                $found[] = 'line ' . $token->line . ': new ' . $next->text;
            }

            continue;
        }

        if ($token->is([T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
            // Also of a spelled-out path: it reads a file, and every class of the library is found by its name.
            $found[] = 'line ' . $token->line . ': ' . strtolower($token->text);

            continue;
        }

        $function = SourceCode::calledFunction($tokens, $index);
        if ($function === null) {
            continue;
        }

        $argument = $tokens[$index + 2] ?? null;
        $flagged = in_array($function, $environment, true)
            || str_starts_with($function, 'apcu_')
            || in_array($function, $files, true)
            || (in_array($function, $byName, true) && ! SourceCode::isInMemoryStream($argument, false))
            || (in_array($function, $byHandle, true) && ! isHandleInMemory($tokens, $index, $inMemory));

        if ($flagged) {
            $found[] = 'line ' . $token->line . ': ' . $function . '()';
        }
    }

    return $found;
}

/**
 * The variable a `static` keyword declares, as a static property or a static variable, or null when the keyword
 * does something else: a static method or closure, `static::`, a return type.
 *
 * @param list<PhpToken> $tokens
 */
function staticVariableAfter(array $tokens, int $index): ?string
{
    $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_FINAL, T_VAR, T_PUBLIC_SET, T_PROTECTED_SET, T_PRIVATE_SET];
    $typeParts = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE];
    $typeMarks = ['?', '|', '&', '(', ')'];

    for ($at = $index + 1; isset($tokens[$at]); $at++) {
        $token = $tokens[$at];
        if ($token->is(T_VARIABLE)) {
            return $token->text;
        }
        if (! $token->is($modifiers) && ! $token->is($typeParts) && ! in_array($token->text, $typeMarks, true)) {
            return null;
        }
    }

    return null;
}

/**
 * Whether the first argument of the call at this token is a variable that the same code opened on a stream in
 * memory.
 *
 * @param list<PhpToken> $tokens
 * @param array<string, true> $inMemory
 */
function isHandleInMemory(array $tokens, int $index, array $inMemory): bool
{
    $argument = $tokens[$index + 2] ?? null;
    $after = $tokens[$index + 3] ?? null;

    return $argument !== null
        && $argument->is(T_VARIABLE)
        && isset($inMemory[$argument->text])
        && $after !== null
        && in_array($after->text, [',', ')'], true);
}

it('reads no environment and no file, writes no file and keeps no static state anywhere in src', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $files = SourceCode::phpFilesUnder($src);

    expect(count($files))->toBeGreaterThan(40, 'the rule must have found the source files');

    $findings = [];
    foreach ($files as $path) {
        foreach (environmentAndStateUses((string) file_get_contents($path)) as $finding) {
            $findings[] = substr($path, strlen($src) + 1) . ' ' . $finding;
        }
    }

    expect($findings)->toBe([]);
});

it('has no class in src with a static property', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $withStatics = [];

    foreach (SourceCode::phpFilesUnder($src) as $path) {
        $class = 'OrganiseYou\\LlmGateway\\' . str_replace('/', '\\', substr($path, strlen($src) + 1, -4));
        if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
            $withStatics[] = $class . ' was not found';

            continue;
        }
        if ((new ReflectionClass($class))->getStaticProperties() !== []) {
            $withStatics[] = $class;
        }
    }

    expect($withStatics)->toBe([]);
});

it('catches code that reads the environment or a file, writes a file or keeps static state', function (string $code, string ...$findings): void {
    expect(environmentAndStateUses("<?php\n" . $code))
        ->toBe(array_values(array_map(fn(string $finding): string => 'line 2: ' . $finding, $findings)));
})->with([
    'getenv' => ['$key = getenv("PROVIDER_KEY");', 'getenv()'],
    'getenv, with a leading backslash' => ['$key = \getenv("PROVIDER_KEY");', 'getenv()'],
    'getenv, in upper case' => ['$all = GETENV();', 'getenv()'],
    'getenv as a callable' => ['$read = getenv(...);', 'getenv()'],
    'putenv' => ['putenv("PROVIDER_KEY=" . $key);', 'putenv()'],
    'the environment of the web server' => ['$key = apache_getenv("PROVIDER_KEY");', 'apache_getenv()'],
    'the environment array' => ['$key = $_ENV["PROVIDER_KEY"];', '$_ENV'],
    'the server array' => ['$key = $_SERVER["PROVIDER_KEY"] ?? null;', '$_SERVER'],
    'the globals' => ['$key = $GLOBALS["key"];', '$GLOBALS'],
    'a global variable' => ['function key() { global $key; return $key; }', 'a global variable'],
    'a setting of PHP' => ['$limit = ini_get("default_socket_timeout");', 'ini_get()'],
    'a change of a setting of PHP' => ['ini_set("default_socket_timeout", "5");', 'ini_set()'],
    'a value of the ini file' => ['$value = get_cfg_var("cfg_file_path");', 'get_cfg_var()'],
    'an ini file' => ['$settings = parse_ini_file($path);', 'parse_ini_file()'],
    'file_get_contents on a file' => ['$key = file_get_contents("/run/secrets/key");', 'file_get_contents()'],
    'file_get_contents on a name it does not spell out' => ['$key = file_get_contents($path);', 'file_get_contents()'],
    'file_get_contents on standard input' => ['$key = file_get_contents("php://stdin");', 'file_get_contents()'],
    'fopen on a file' => ["\$handle = fopen(__DIR__ . '/key.txt', 'r');", 'fopen()'],
    'file' => ['$lines = file($path);', 'file()'],
    'readfile' => ['readfile($path);', 'readfile()'],
    'fread on a handle it did not open in memory' => ['$bytes = fread($handle, 100);', 'fread()'],
    'fgets on standard input' => ['$line = fgets(STDIN);', 'fgets()'],
    'a file object' => ['$file = new SplFileObject($path);', 'new SplFileObject'],
    'a file object, by its full name' => ['$file = new \SplFileObject("/etc/hosts");', 'new \SplFileObject'],
    'a folder iterator' => ['$folder = new DirectoryIterator($path);', 'new DirectoryIterator'],
    'a check that a file exists' => ['$known = file_exists($path);', 'file_exists()'],
    'an include of a path in a variable' => ['include $path;', 'include'],
    'a require of a built path' => ['require $base . "/settings.php";', 'require'],
    'a require of a spelled-out path' => ["require __DIR__ . '/data.php';", 'require'],
    'a require_once of a literal' => ["require_once 'data.php';", 'require_once'],
    'an include_once' => ["\$data = include_once(__DIR__ . '/data.php');", 'include_once'],
    'a temporary stream, which writes a file when it grows' => ["\$stream = fopen('php://temp', 'r+');", 'fopen()'],
    'a temporary stream with a limit' => ['$stream = fopen("php://temp/maxmemory:1024", "r+");', 'fopen()'],
    'a write to a temporary stream' => ["\$stream = fopen('php://temp', 'r+'); fwrite(\$stream, \$body);", 'fopen()', 'fwrite()'],
    'a file object on a temporary stream' => ["\$file = new SplFileObject('php://temp');", 'new SplFileObject'],
    'a temporary file object' => ['$file = new SplTempFileObject();', 'new SplTempFileObject'],
    'a database' => ['$store = new PDO("sqlite:" . $path);', 'new PDO'],
    'a database, by its full name' => ['$store = new \PDO($dsn);', 'new \PDO'],
    'a database file' => ['$store = new SQLite3($path);', 'new SQLite3'],
    'a value of the request' => ['$key = filter_input(INPUT_ENV, "PROVIDER_KEY");', 'filter_input()'],
    'the values of the request' => ['$all = filter_input_array(INPUT_SERVER);', 'filter_input_array()'],
    'a store shared between requests, read' => ['$answer = apcu_fetch($key);', 'apcu_fetch()'],
    'a store shared between requests, written' => ['apcu_store($key, $answer, 60);', 'apcu_store()'],
    'any other function of that store' => ['$known = APCU_EXISTS($key);', 'apcu_exists()'],
    'the working folder' => ['$folder = getcwd();', 'getcwd()'],
    'a change of the working folder' => ['chdir($folder);', 'chdir()'],
    'the options of the command line' => ['$options = getopt("k:");', 'getopt()'],
    'the arguments of the command line' => ['$key = $argv[1];', '$argv'],
    'the number of arguments of the command line' => ['$given = $argc > 1;', '$argc'],
    'a line typed by a person' => ['$key = readline("Key: ");', 'readline()'],
    'the system log' => ['syslog(LOG_INFO, $line);', 'syslog()'],
    'a constant made at run time' => ['define("PROVIDER_KEY", $key);', 'define()'],
    'the rights of a file' => ['$rights = fileperms($path);', 'fileperms()'],
    'the change time of a file' => ['$changed = filectime($path);', 'filectime()'],
    'a check for a link' => ['$link = is_link($path);', 'is_link()'],
    'a check for a program' => ['$runs = is_executable($path);', 'is_executable()'],
    'the target of a link' => ['$target = readlink($path);', 'readlink()'],
    'a folder handle' => ['$folder = dir($path);', 'dir()'],
    'file_put_contents' => ['file_put_contents($path, $answer);', 'file_put_contents()'],
    'file_put_contents on a spelled-out file' => ['file_put_contents("/tmp/cache.json", $answer);', 'file_put_contents()'],
    'fwrite on a handle it did not open in memory' => ['fwrite($handle, $answer);', 'fwrite()'],
    'fwrite on standard error' => ['fwrite(STDERR, $answer);', 'fwrite()'],
    'fwrite on a property' => ['fwrite($this->handle, $answer);', 'fwrite()'],
    'fputs' => ['fputs($handle, $answer);', 'fputs()'],
    'a temporary file name' => ['$path = tempnam("/tmp", "answer");', 'tempnam()'],
    'a temporary file' => ['$handle = tmpfile();', 'tmpfile()'],
    'a new folder' => ['mkdir($path, 0700, true);', 'mkdir()'],
    'touch' => ['touch($path);', 'touch()'],
    'unlink' => ['unlink($path);', 'unlink()'],
    'a rename' => ['rename($from, $to);', 'rename()'],
    'a log file' => ['error_log($line, 3, $path);', 'error_log()'],
    'a static property' => ['final class Cache { private static array $answers = []; }', 'static $answers'],
    'a static property without a type' => ['final class Cache { public static $answers; }', 'static $answers'],
    'a static property, static first' => ['final class Cache { static private ?array $answers = null; }', 'static $answers'],
    'a static property with a class as its type' => ['final class Cache { protected static ?\Vendor\Store $store = null; }', 'static $store'],
    'a static property with a union type' => ['final class Cache { private static int|string|null $last = null; }', 'static $last'],
    'a static property with a type in parentheses' => ['final class Cache { private static (A&B)|null $i = null; }', 'static $i'],
    'a static property with a type in parentheses, static first' => ['final class Cache { static private (A&B)|(C&D)|null $i = null; }', 'static $i'],
    'a static variable' => ['function models(): array { static $models = null; return $models ??= []; }', 'static $models'],
    'a static variable in a method' => ['final class A { public function b(): int { static $calls = 0; return ++$calls; } }', 'static $calls'],
]);

it('reports every use in a file, with its line', function (): void {
    $code = <<<'PHP'
        <?php

        final class Settings
        {
            private static ?string $key = null;

            public static function key(): string
            {
                return self::$key ??= (string) (getenv('PROVIDER_KEY') ?: file_get_contents(__DIR__ . '/key'));
            }
        }
        PHP;

    expect(environmentAndStateUses($code))->toBe(['line 5: static $key', 'line 9: getenv()', 'line 9: file_get_contents()']);
});

it('does not flag code that reads no environment, touches no file and keeps no static state', function (string $code): void {
    expect(environmentAndStateUses("<?php\n" . $code))->toBe([]);
})->with([
    'a comment that names a function' => ['// never getenv() or $_ENV or file_put_contents($path) here'],
    'a doc comment' => ['/** No static $cache and no tmpfile(). */'],
    'a string that names a function' => ['$rule = "getenv(\'KEY\') and static \$cache"; $other = \'$_ENV\';'],
    'a method of the same name' => ['$host->getenv("KEY"); Files::unlink($name); $a?->touch(); $b->file($c);'],
    'a method declared with the same name' => ['interface Files { public function unlink(string $name): void; public function file(): string; }'],
    'a stream in memory' => ["\$stream = fopen('php://memory', 'r+'); \$other = fopen(\"php://memory\", 'w+');"],
    'a write to a stream in memory' => ["\$stream = fopen('php://memory', 'r+'); fwrite(\$stream, \$body); rewind(\$stream); \$all = fread(\$stream, 10);"],
    'a file object in memory' => ["\$file = new SplFileObject('php://memory', 'r+'); \$info = new SplFileInfo(\$path);"],
    'a static method' => ['final class A { public static function make(): self { return new self(); } }'],
    'a static method, static first' => ['final class A { static public function make(): static { return new static(); } }'],
    'a static closure and a static arrow function' => ['$a = static function (int $b) use ($c): int { return $b; }; $d = static fn(int $e): int => $e;'],
    'a call through static' => ['final class A { public function b(): int { return static::c($this->d) + static::E; } }'],
    'a return type of static' => ['interface A { public function b(int $c): static; public function d(): ?static; }'],
    'a return type of static before a body' => ['final class A { public function b(): static { $c = 1; return $this; } }'],
    'a return type of static on an arrow function' => ['$a = fn(): static => $this;'],
    'an instance check against static' => ['final class A { public function b(object $c, int $d): int { return $c instanceof static ? $d : 0; } }'],
    'a new static with an argument' => ['final class A { public function b(int $c): static { return new static($c); } }'],
    'a constant, an enum case and a readonly property' => ['final class A { private const string B = "b"; public function __construct(public readonly int $c) {} }'],
    'a constant or a property of that name' => ['$a = self::GETENV; $b = $this->file; $c = FILE_APPEND; $d = $this->static;'],
    'a variable with a look-alike name' => ['$_ENVIRONMENT = 1; $_SERVERS = 2; $env = 3; $server = 4;'],
    'a method or a constant named like the new ones' => ['$a->define("b"); $c->dir(); Store::apcu_fetch($d); $e = self::DIR; $f = $this->argv;'],
    'a variable with a name that only starts the same' => ['$argvs = 1; $argcount = 2; $arguments = 3;'],
    'a class whose name only holds the word' => ['$a = new PdoLikeName(); $b = new SQLite3Result(); $c = new SplFileInfo($d);'],
    'a constant declared in a class' => ['final class A { public const int DEFINE = 1; private const string DIR = "d"; }'],
    'a call through a variable, which the rule leaves alone' => ['$function($path); $object->$method($path);'],
    'the clock and the pause, which are not state' => ['$now = hrtime(true); usleep(1000); $json = json_encode($value);'],
]);

// LR-41: no real key in a test. A key can only come into a test from the environment of the process, so no test
// reads it: every key in a test is written in the test, and is made up.

/**
 * The findings of environmentAndStateUses() that are a way to a value of the environment of the process, as a
 * pattern. Reading a file, a setting of PHP or a static variable is not one: tests do those.
 */
function waysToTheEnvironment(): string
{
    return '/: (getenv|putenv|apache_getenv|apache_setenv|get_cfg_var|parse_ini_file|filter_input|filter_input_array|getopt|readline)\(\)$|: \$(_ENV|_SERVER|_GET|_POST|_COOKIE|_REQUEST|_FILES|_SESSION|GLOBALS|argv|argc)$/';
}

it('reads no environment anywhere in tests: a test has no way to a real key', function (): void {
    $tests = dirname(__DIR__);
    $ways = waysToTheEnvironment();

    $files = SourceCode::phpFilesUnder($tests);
    expect(count($files))->toBeGreaterThan(50, 'the rule must have found the test files');

    $findings = [];
    foreach ($files as $path) {
        foreach (environmentAndStateUses((string) file_get_contents($path)) as $finding) {
            if (preg_match($ways, $finding) === 1) {
                $findings[] = substr($path, strlen($tests) + 1) . ' ' . $finding;
            }
        }
    }

    expect($findings)->toBe([]);
});

it('knows the ways to the environment that the rule for tests looks for', function (string $code): void {
    $ways = waysToTheEnvironment();

    expect(array_filter(environmentAndStateUses("<?php\n" . $code), fn(string $finding): bool => preg_match($ways, $finding) === 1))->toHaveCount(1);
})->with([
    'getenv' => ['$key = getenv("PROVIDER_KEY");'],
    'the environment array' => ['$key = $_ENV["PROVIDER_KEY"];'],
    'the server array' => ['$key = $_SERVER["PROVIDER_KEY"] ?? "";'],
    'the arguments of the command line' => ['$key = $argv[1];'],
    'a line typed by a person' => ['$key = readline("Key: ");'],
]);
