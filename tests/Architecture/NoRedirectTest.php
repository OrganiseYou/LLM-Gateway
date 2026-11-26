<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Tests\Support\SourceCode;

// LR-29 and the project guide: never follow a redirect and never change the host of a request the caller approved.
//
// What the library does is tested by behaviour: a 3xx response is the failure "redirected" and its Location header
// is not read (tests/Unit/Transport/Psr18TransportTest.php); a 3xx that a transport returns is `unavailable` and is
// not retried, and an address an adapter built outside the base URL is refused before anything is sent
// (tests/Unit/Adapter/Shared/AdapterCompleteTest.php, AdapterRetryTest.php); a setting cannot move the host
// (tests/Unit/Preset/PresetTest.php, tests/Unit/GatewayFactoryTest.php); the shared test set asks every adapter for
// the same (ContractFailure::Status302 and ::Redirected).
//
// What a static rule adds to that: the code has no way to do it at all. Nothing in src reads a Location header,
// switches a client's own redirects on, or rewrites the address of a PSR-7 request after it was built.

/**
 * The places in a piece of PHP code that could follow a redirect or move a request to another address.
 *
 * @return list<string> one line per finding, with the line number in the code
 */
function redirectAndHostChanges(string $code): array
{
    // Methods of a PSR-7 request and of a URI that change where a request goes.
    $movers = ['withuri', 'withhost', 'withport', 'withscheme', 'withrequesttarget', 'withuserinfo'];
    // Words of HTTP clients for following a redirect, as a string or as a constant.
    $followers = '/^[\'"]?(location|allow_redirects|max_redirects|follow_location|followlocation|follow_redirects|curlopt_followlocation|curlopt_maxredirs|redirect)[\'"]?$/i';

    $tokens = SourceCode::tokens($code);

    $found = [];
    foreach ($tokens as $index => $token) {
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        if ($token->is(T_STRING) && $next !== null && $next->text === '('
            && $previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
            && in_array(strtolower($token->text), $movers, true)) {
            $found[] = 'line ' . $token->line . ': ->' . $token->text . '()';

            continue;
        }

        $isWord = $token->is(T_CONSTANT_ENCAPSED_STRING)
            || ($token->is(T_STRING) && ($previous === null || ! $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CONST, T_CASE, T_FUNCTION])));
        if ($isWord && preg_match($followers, $token->text) === 1) {
            $found[] = 'line ' . $token->line . ': ' . $token->text;
        }
    }

    return $found;
}

it('has no way to follow a redirect or to move a request to another address anywhere in src', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $files = SourceCode::phpFilesUnder($src);

    expect(count($files))->toBeGreaterThan(40, 'the rule must have found the source files');

    $findings = [];
    foreach ($files as $path) {
        foreach (redirectAndHostChanges((string) file_get_contents($path)) as $finding) {
            $findings[] = substr($path, strlen($src) + 1) . ' ' . $finding;
        }
    }

    expect($findings)->toBe([]);
});

it('catches code that could follow a redirect or move a request', function (string $code, string $finding): void {
    expect(redirectAndHostChanges("<?php\n" . $code))->toBe(['line 2: ' . $finding]);
})->with([
    'a read of the Location header' => ['$next = $response->header("Location");', '"Location"'],
    'a read of the location header, in lower case' => ["\$next = \$headers['location'] ?? null;", "'location'"],
    'the redirects of a client switched on' => ['$options = ["allow_redirects" => true];', '"allow_redirects"'],
    'a limit on redirects' => ["\$options = ['max_redirects' => 3];", "'max_redirects'"],
    'the option of the PHP stream' => ['$http = ["follow_location" => 1];', '"follow_location"'],
    'the option of curl' => ['$option = CURLOPT_FOLLOWLOCATION;', 'CURLOPT_FOLLOWLOCATION'],
    'a new address for a PSR-7 request' => ['$request = $request->withUri($other);', '->withUri()'],
    'a new host for a URI' => ['$uri = $uri->withHost("other.example.test");', '->withHost()'],
    'a new scheme for a URI' => ['$uri = $uri->withScheme("http");', '->withScheme()'],
    'a new request target' => ['$request = $request->withRequestTarget("https://other.example.test/");', '->withRequestTarget()'],
]);

it('does not flag code that names a redirect without following one', function (string $code): void {
    expect(redirectAndHostChanges("<?php\n" . $code))->toBe([]);
})->with([
    'the failure of a redirect' => ['throw new TransportException(TransportFailure::Redirected); enum F: string { case Redirected = "redirected"; }'],
    'a sentence of the library about it' => ['const REDIRECTED = "the server answered with a redirect, and a redirect is never followed";'],
    'a comment' => ['// The Location header is never read; allow_redirects stays off.'],
    'a status check' => ['$redirect = $status >= 300 && $status <= 399;'],
    'a header that is set on a request' => ['$request = $request->withHeader($name, $value);'],
    'where a provider keeps data' => ['final class Preset { public function __construct(public string $dataLocation) {} }'],
]);
