<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Tests\Support\SourceCode;

// LR-28: the library opens no network connection by itself. Every request goes through the transport the host
// gives. Outside src/Transport no code may call a function that connects, read or open a file by a name it does not
// spell out, or build an HTTP client.

/**
 * The calls in a piece of PHP code that open a connection, or could. Comments and the text of strings are not
 * code and are not searched.
 *
 * @return list<string> one line per finding, with the line number in the code
 */
function connectionOpeners(string $code): array
{
    $always = [
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'stream_socket_pair',
        'ftp_connect', 'ftp_ssl_connect', 'ssh2_connect', 'mail', 'mb_send_mail', 'dns_get_record', 'dns_check_record',
        'dns_get_mx', 'checkdnsrr', 'getmxrr', 'gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'get_headers',
        'stream_context_create', 'proc_open', 'popen', 'exec', 'shell_exec', 'system', 'passthru',
    ];
    // These take a file name or a URL. Only a spelled-out in-memory stream is known not to be an address.
    $byName = [
        'file_get_contents', 'fopen', 'file', 'readfile', 'copy', 'file_put_contents', 'simplexml_load_file',
        'get_meta_tags', 'opendir', 'scandir', 'hash_file', 'hash_hmac_file', 'md5_file', 'sha1_file', 'getimagesize',
        'exif_read_data', 'parse_ini_file', 'highlight_file', 'show_source',
    ];
    // Classes whose objects read from a name that can be a URL.
    $classes = ['splfileobject', 'domdocument', 'xmlreader', 'soapclient'];
    // Static makers of the same kind: Dom\HTMLDocument::createFromFile(), XMLReader::open(), fromUri().
    $staticMakers = ['createfromfile', 'fromuri'];

    $tokens = SourceCode::tokens($code);

    $found = [];
    $inBackticks = false;
    foreach ($tokens as $index => $token) {
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        if ($token->is(T_NEW) && $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $class = SourceCode::shortName($next->text);
            if ($class === 'client' || str_ends_with($class, 'httpclient') || in_array($class, $classes, true)) {
                $found[] = 'line ' . $token->line . ': new ' . $next->text;
            }

            continue;
        }

        if ($token->text === '`') {
            // One finding per command: the first backtick opens it, the second closes it.
            $inBackticks = ! $inBackticks;
            if ($inBackticks) {
                $found[] = 'line ' . $token->line . ': a shell command in backticks';
            }

            continue;
        }

        if ($token->is([T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
            if (SourceCode::includesPathNotSpelledOut($tokens, $index)) {
                $found[] = 'line ' . $token->line . ': ' . strtolower($token->text) . ' of a path that is not spelled out';
            }

            continue;
        }

        if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || $next === null || $next->text !== '(') {
            continue;
        }
        if ($previous !== null && $previous->is(T_DOUBLE_COLON) && in_array(strtolower($token->text), $staticMakers, true)) {
            $found[] = 'line ' . $token->line . ': ::' . $token->text . '()';

            continue;
        }
        $owner = $tokens[$index - 2] ?? null;
        if ($previous !== null && $previous->is(T_DOUBLE_COLON) && $owner !== null
            && in_array(SourceCode::shortName($owner->text), $classes, true)) {
            $found[] = 'line ' . $token->line . ': ' . $owner->text . '::' . $token->text . '()';

            continue;
        }
        $function = SourceCode::calledFunction($tokens, $index);
        if ($function === null) {
            continue; // a method, or the declaration of one: not the PHP function of that name
        }
        if (str_starts_with($function, 'curl_') || str_starts_with($function, 'socket_') || in_array($function, $always, true)) {
            $found[] = 'line ' . $token->line . ': ' . $function . '()';

            continue;
        }
        if (in_array($function, $byName, true)) {
            if (! SourceCode::isInMemoryStream($tokens[$index + 2] ?? null)) {
                $found[] = 'line ' . $token->line . ': ' . $function . '()';
            }
        }
    }

    return $found;
}

/**
 * The places in a piece of PHP code that give the JSON Schema validator a way to fetch a schema: a resolver, a file,
 * a folder or a protocol registered for references, or a validator built without a loader, which then makes its own
 * resolver. Without one of these a reference to another document is never followed.
 *
 * @return list<string> one line per finding, with the line number in the code
 */
function schemaFetchers(string $code): array
{
    // Methods of the validator, its loader and its resolver that name a source of schemas or validate by address.
    $methods = [
        'setresolver', 'registerfile', 'registerprefix', 'registerprotocol', 'registerprotocoldir', 'registerraw',
        'setbaseuri', 'urivalidation', 'loadschemabyid',
    ];

    $tokens = SourceCode::tokens($code);

    $found = [];
    foreach ($tokens as $index => $token) {
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && SourceCode::shortName($token->text) === 'schemaresolver') {
            $found[] = 'line ' . $token->line . ': ' . $token->text;

            continue;
        }

        if ($token->is(T_NEW) && $next !== null && $next->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && str_ends_with(SourceCode::shortName($next->text), 'validator')) {
            $open = $tokens[$index + 2] ?? null;
            $first = $tokens[$index + 3] ?? null;
            if ($open === null || $open->text !== '(' || $first === null || $first->text === ')' || strtolower($first->text) === 'null') {
                $found[] = 'line ' . $token->line . ': new ' . $next->text . ' without a loader';
            }

            continue;
        }

        if ($token->is(T_STRING) && $next !== null && $next->text === '('
            && $previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
            && in_array(strtolower($token->text), $methods, true)) {
            $found[] = 'line ' . $token->line . ': ->' . $token->text . '()';
        }
    }

    return $found;
}

/**
 * What the library itself may require: the interfaces say how to talk to a client, no package is one.
 */
function requiredPackageIsAllowed(string $package): bool
{
    return $package === 'php' || $package === 'opis/json-schema' || preg_match('/^psr\/[a-z0-9-]+$/D', $package) === 1;
}

it('opens no connection in src outside src/Transport', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $files = array_values(array_filter(
        SourceCode::phpFilesUnder($src),
        fn(string $path): bool => ! str_starts_with($path, $src . '/Transport/'),
    ));

    expect(count($files))->toBeGreaterThan(20, 'the rule must have found the source files');

    $findings = [];
    foreach ($files as $path) {
        foreach (connectionOpeners((string) file_get_contents($path)) as $finding) {
            $findings[] = substr($path, strlen($src) + 1) . ' ' . $finding;
        }
    }

    expect($findings)->toBe([]);
});

it('opens no connection in src/Transport either: the bridge only calls the client of the host', function (): void {
    $findings = [];
    foreach (SourceCode::phpFilesUnder(dirname(__DIR__, 2) . '/src/Transport') as $path) {
        foreach (connectionOpeners((string) file_get_contents($path)) as $finding) {
            $findings[] = basename($path) . ' ' . $finding;
        }
    }

    expect($findings)->toBe([]);
});

it('catches code that opens a connection', function (string $code, string $finding): void {
    expect(connectionOpeners("<?php\n" . $code))->toBe(['line 2: ' . $finding]);
})->with([
    'curl' => ['$handle = curl_init($url);', 'curl_init()'],
    'curl, with a leading backslash' => ['\curl_exec($handle);', 'curl_exec()'],
    'curl, in upper case' => ['CURL_SETOPT($handle, 1, 2);', 'curl_setopt()'],
    'curl multi' => ['$multi = curl_multi_init ();', 'curl_multi_init()'],
    'a socket' => ['$socket = fsockopen("api.example.test", 443);', 'fsockopen()'],
    'a persistent socket' => ['$socket = pfsockopen($host, 443);', 'pfsockopen()'],
    'a stream socket' => ['$socket = stream_socket_client("tls://api.example.test:443");', 'stream_socket_client()'],
    'file_get_contents on a URL' => ['$body = file_get_contents("https://api.example.test/v1/models");', 'file_get_contents()'],
    'file_get_contents on a name it does not spell out' => ['$body = file_get_contents($url);', 'file_get_contents()'],
    'file_get_contents on a file' => ['$body = file_get_contents("/etc/hosts");', 'file_get_contents()'],
    'fopen on a URL' => ["\$stream = fopen('http://api.example.test', 'r');", 'fopen()'],
    'fopen on a name it does not spell out' => ['$stream = fopen($base . "/v1", "r");', 'fopen()'],
    'fopen on a wrapper that is not in memory' => ["\$stream = fopen('php://stdin', 'r');", 'fopen()'],
    'file on a URL' => ['$lines = file("https://api.example.test");', 'file()'],
    'readfile' => ['readfile($url);', 'readfile()'],
    'a stream context' => ['$context = stream_context_create(["http" => []]);', 'stream_context_create()'],
    'a new client' => ['$client = new Client(["base_uri" => $url]);', 'new Client'],
    'a new client, by its full name' => ['$client = new \Vendor\Http\Client();', 'new \Vendor\Http\Client'],
    'a new client, by a relative name' => ['$client = new Http\Client;', 'new Http\Client'],
    'a new HTTP client' => ['$client = new CurlHttpClient();', 'new CurlHttpClient'],
    'a socket of the sockets extension' => ['$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);', 'socket_create()'],
    'a connect of the sockets extension' => ['socket_connect($socket, $host, 443);', 'socket_connect()'],
    'any other function of the sockets extension' => ['socket_sendto($socket, $data, 1, 0, $host, 53);', 'socket_sendto()'],
    'a pair of stream sockets' => ['$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);', 'stream_socket_pair()'],
    'a name lookup' => ['$address = gethostbyname("api.example.test");', 'gethostbyname()'],
    'a name lookup for all addresses' => ['$addresses = gethostbynamel($host);', 'gethostbynamel()'],
    'a DNS check' => ['$known = checkdnsrr($host, "MX");', 'checkdnsrr()'],
    'a DNS query' => ['$records = dns_get_record($host);', 'dns_get_record()'],
    'a mail' => ['mail($to, $subject, $text);', 'mail()'],
    'a multibyte mail' => ['mb_send_mail($to, $subject, $text);', 'mb_send_mail()'],
    'a file object' => ['$file = new SplFileObject($url);', 'new SplFileObject'],
    'a file object, by its full name' => ['$file = new \SplFileObject("https://api.example.test");', 'new \SplFileObject'],
    'a hash of a file' => ['$hash = hash_file("sha256", $url);', 'hash_file()'],
    'an MD5 of a file' => ['$hash = md5_file($url);', 'md5_file()'],
    'a SHA-1 of a file' => ['$hash = sha1_file("https://api.example.test");', 'sha1_file()'],
    'the size of an image' => ['$size = getimagesize($url);', 'getimagesize()'],
    'an XML file' => ['$xml = simplexml_load_file($url);', 'simplexml_load_file()'],
    'a DOM document, which can load a URL' => ['$document = new DOMDocument();', 'new DOMDocument'],
    'a DOM document loaded statically' => ['$document = DOMDocument::load($url);', 'DOMDocument::load()'],
    'a DOM document made from a file' => ['$document = \Dom\HTMLDocument::createFromFile($url);', '::createFromFile()'],
    'an XML reader' => ['$reader = XMLReader::open($url);', 'XMLReader::open()'],
    'an include of a path in a variable' => ['include $path;', 'include of a path that is not spelled out'],
    'a require of a URL' => ['require "https://api.example.test/code.php";', 'require of a path that is not spelled out'],
    'a require_once of a built path' => ['require_once $base . "/code.php";', 'require_once of a path that is not spelled out'],
    'an include_once of a call' => ['include_once path("code.php");', 'include_once of a path that is not spelled out'],
    'a shell command' => ['$out = shell_exec("curl https://api.example.test");', 'shell_exec()'],
    'a shell command in backticks' => ['$out = `curl https://api.example.test`;', 'a shell command in backticks'],
]);

it('reports every opener of a file, with its line', function (): void {
    $code = <<<'PHP'
        <?php

        final class Fetcher
        {
            public function fetch(string $url): string
            {
                $handle = curl_init($url);
                curl_exec($handle);

                return (string) file_get_contents($url);
            }
        }
        PHP;

    expect(connectionOpeners($code))->toBe(['line 7: curl_init()', 'line 8: curl_exec()', 'line 10: file_get_contents()']);
});

it('does not flag code that opens nothing', function (string $code): void {
    expect(connectionOpeners("<?php\n" . $code))->toBe([]);
})->with([
    'a comment that names a function' => ['// never curl_init() or file_get_contents($url) here'],
    'a doc comment' => ['/** No new Client() and no fsockopen(). */'],
    'a string that names a function' => ['$rule = "curl_exec(\$handle) and new Client()";'],
    'a method of the same name' => ['$transport->fopen($name); Files::file_get_contents($name); $a?->curl_init();'],
    'a method declared with the same name' => ['interface Files { public function fopen(string $name): mixed; }'],
    'a stream in memory' => ["\$stream = fopen('php://memory', 'r+'); \$other = fopen(\"php://temp\", 'r+');"],
    'a class whose name only holds the word' => ['$a = new ClientError(); $b = new RecordingClientList(); $c = new Request();'],
    'a constant or a property of that name' => ['$a = self::CURL_INIT; $b = $this->file; $c = FILE_APPEND;'],
    'a require of a spelled-out path' => ["require __DIR__ . '/data.php'; require_once 'data.php'; include (__DIR__ . '/a.php');"],
    'a method named like a socket function' => ['$this->socket_create(); $pool->mail($to); Lookup::gethostbyname($host);'],
    'a call through a variable, which the rule leaves alone' => ['$function($url); $object->$method($url);'],
    'a file info object, which opens nothing' => ['$info = new SplFileInfo($path); $other = new DOMException();'],
    'the PSR-18 interface as a type' => ['function send(ClientInterface $client, Client $other): void {}'],
]);

it('gives the JSON Schema validator no way to fetch a schema anywhere in src', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $findings = [];
    $builds = 0;

    foreach (SourceCode::phpFilesUnder($src) as $path) {
        $code = (string) file_get_contents($path);
        $builds += (int) preg_match_all('/\bnew \w*Validator\(/', $code);
        foreach (schemaFetchers($code) as $finding) {
            $findings[] = substr($path, strlen($src) + 1) . ' ' . $finding;
        }
    }

    expect($builds)->toBeGreaterThan(0, 'the rule must have found the place where the validator is built')
        ->and($findings)->toBe([]);
});

it('catches code that gives the validator a way to fetch a schema', function (string $code, string $finding): void {
    expect(schemaFetchers("<?php\n" . $code))->toBe(['line 2: ' . $finding]);
})->with([
    'a validator without a loader' => ['$validator = new Validator();', 'new Validator without a loader'],
    'a validator without parentheses' => ['$validator = new Validator;', 'new Validator without a loader'],
    'a validator with null as its loader' => ['$validator = new Validator(null, 5);', 'new Validator without a loader'],
    'a class that extends the validator, without a loader' => ['$validator = new LoopGuardValidator();', 'new LoopGuardValidator without a loader'],
    'a validator by its full name' => ['$validator = new \Opis\JsonSchema\Validator();', 'new \Opis\JsonSchema\Validator without a loader'],
    'a resolver' => ['$loader = new SchemaLoader($parser, new SchemaResolver(), false);', 'SchemaResolver'],
    'a resolver that is imported' => ['use Opis\JsonSchema\Resolvers\SchemaResolver;', 'Opis\JsonSchema\Resolvers\SchemaResolver'],
    'a resolver set later' => ['$loader->setResolver($resolver);', '->setResolver()'],
    'a file registered for an address' => ['$validator->resolver()->registerFile($id, $path);', '->registerFile()'],
    'a folder registered for a prefix' => ['$resolver->registerPrefix("https://schemas.example.test/", $folder);', '->registerPrefix()'],
    'a protocol registered for references' => ['$resolver->registerProtocol("https", $fetch);', '->registerProtocol()'],
    'a folder registered for a protocol' => ['$resolver->registerProtocolDir("https", "schemas.example.test", $folder);', '->registerProtocolDir()'],
    'a schema registered for an address' => ['$resolver->registerRaw($schema, $id);', '->registerRaw()'],
    'a base address for relative references' => ['$loader->setBaseUri($uri);', '->setBaseUri()'],
    'a check against a schema named by its address' => ['$error = $validator->uriValidation($data, $uri);', '->uriValidation()'],
    'a schema loaded by its address' => ['$schema = $loader->loadSchemaById($uri);', '->loadSchemaById()'],
]);

it('does not flag a validator that is given a loader without a resolver', function (string $code): void {
    expect(schemaFetchers("<?php\n" . $code))->toBe([]);
})->with([
    'the way the library builds it' => ['$validator = new Validator(new SchemaLoader($parser, null, false), PHP_INT_MAX, false);'],
    'a loader in a variable' => ['$validator = new Validator($loader);'],
    'a class that extends the validator, with a loader' => ['$validator = new LoopGuardValidator(self::loader(), 1, true, 10, 20);'],
    'a comment and a string that name a resolver' => ['// no SchemaResolver here' . "\n" . '$rule = "new Validator() and registerFile()";'],
    'a method of that name declared by another class' => ['interface Files { public function registerFile(string $name): void; }'],
    'a class whose name only holds the word' => ['$a = new ValidatorFactory(); $b = new SchemaResolverList;'],
]);

it('requires no HTTP client: only PHP, the PSR interfaces and the JSON Schema checker', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 16, JSON_THROW_ON_ERROR);

    expect($composer)->toBeArray()->toHaveKey('require');

    $required = array_keys(is_array($composer) && is_array($composer['require']) ? $composer['require'] : []);
    $notAllowed = array_values(array_filter(
        $required,
        fn(int|string $package): bool => requiredPackageIsAllowed((string) $package) === false,
    ));

    expect($required)->not->toBe([])
        ->and($notAllowed)->toBe([]);
});

it('catches a required package that is not PHP, a PSR interface or the JSON Schema checker', function (string $package): void {
    expect(requiredPackageIsAllowed($package))->toBeFalse();
})->with([
    'vendor/http-client',
    'psr-discovery/http-client-implementations',
    'php-http/discovery',
    'ext-curl',
    'ext-sockets',
    'opis/uri',
    'psrx/http-client',
]);

it('allows PHP, the PSR interfaces and the JSON Schema checker as required packages', function (string $package): void {
    expect(requiredPackageIsAllowed($package))->toBeTrue();
})->with(['php', 'psr/http-client', 'psr/http-factory', 'psr/http-message', 'opis/json-schema']);
