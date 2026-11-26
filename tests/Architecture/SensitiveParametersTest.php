<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Preset\PresetField;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;

// LR-26 and the project guide: no secret anywhere it can be read. PHP keeps the arguments of every call in the
// trace of an exception; a parameter marked #[\SensitiveParameter] is left out of it. So every parameter of a method
// in src that takes a secret, a credential, a key or a token must carry that mark.

/**
 * Whether the type or the name of a parameter says it can hold a secret.
 *
 * By type: the secret itself, a credential, a token supplier. By name: one of the words key, apikey, token,
 * secret, password, passphrase, credential, bearer, auth, authorization, jwt, cookie or signature as a word of the
 * name, in any case ($apiKey, $APIKEY, $access_token, $tokens), not as a part of another word ($keyword, $monkey,
 * $author).
 */
function looksLikeASecret(string $type, string $name): bool
{
    if (preg_match('/(?:^|[\\\\|?&(])(?:Secret|Credentials|BearerKey|HeaderKey|BearerToken|NoCredentials|TokenSupplier)(?:$|[|&)])/', $type) === 1) {
        return true;
    }

    $words = preg_split('/_+|(?<=[a-z0-9])(?=[A-Z])/', $name, -1, PREG_SPLIT_NO_EMPTY);

    return array_intersect(
        array_map(strtolower(...), $words === false ? [] : $words),
        [
            'key', 'keys', 'apikey', 'apikeys', 'token', 'tokens', 'secret', 'secrets', 'password', 'passphrase', 'credential',
            'credentials', 'bearer', 'auth', 'authorization', 'authorisation', 'jwt', 'cookie', 'cookies', 'signature',
        ],
    ) !== [];
}

/**
 * Parameters whose name holds one of the words and that can never hold a secret.
 *
 * @return array<string, string> "Class::method($parameter)" => why it is no secret
 */
function parametersThatOnlySoundSecret(): array
{
    return [
        AbstractAdapter::class . '::setting($key)' => 'the key of a field of the preset: a name such as "region", written in the preset',
        PresetField::class . '::__construct($key)' => 'the key of a field of the preset: a name, written in the preset',
        Preset::class . '::__construct($keyHeader)' => 'the name of the header a key travels in, not the key',
        Preset::class . '::__construct($credentialKinds)' => 'the kinds of credentials a preset accepts: enum cases',
        Preset::class . '::assertCredentialKinds($credentialKinds)' => 'the kinds of credentials a preset accepts: enum cases',
        Preset::class . '::assertDefaultBaseUrl($fieldsByKey)' => 'the fields of a preset, by their key: names and patterns',
        Request::class . '::__construct($maxOutputTokens)' => 'a number of tokens of text',
        Usage::class . '::__construct($inputTokens)' => 'a number of tokens of text',
        Usage::class . '::__construct($outputTokens)' => 'a number of tokens of text',
    ];
}

/**
 * Every parameter of every method the library declares, as "Class::method($parameter)" => [type, is it marked].
 *
 * @return array<string, array{string, bool}>
 */
function parametersOfTheLibrary(): array
{
    $parameters = [];
    foreach (SourceCode::classesOfTheLibrary(dirname(__DIR__, 2) . '/src') as $class) {
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                $parameters[$class . '::' . $method->getName() . '($' . $parameter->getName() . ')'] = [
                    (string) $parameter->getType(),
                    $parameter->getAttributes(SensitiveParameter::class) !== [],
                ];
            }
        }
    }

    return $parameters;
}

/**
 * The parameters of the closures and arrow functions in a piece of PHP code that can hold a secret and are not
 * marked. Reflection on the classes does not see these.
 *
 * @return list<string> one line per finding, with the line number in the code
 */
function unmarkedSecretsOfClosures(string $code): array
{
    $tokens = SourceCode::tokens($code);

    $found = [];
    foreach ($tokens as $index => $token) {
        $open = $index + 1;
        if (($tokens[$open] ?? null)?->text === '&') {
            $open++;
        }
        // A function with a name is a method or a function: reflection, or the rule that src has only classes.
        if (! $token->is([T_FUNCTION, T_FN]) || ($tokens[$open] ?? null)?->text !== '(') {
            continue;
        }

        $depth = 0;
        $type = '';
        $marked = false;
        for ($at = $open + 1; isset($tokens[$at]); $at++) {
            $part = $tokens[$at];
            if ($depth === 0 && $part->text === ')') {
                break;
            }
            if ($part->is(T_ATTRIBUTE)) {
                // The attributes of this parameter, to their closing bracket.
                for ($at++; isset($tokens[$at]) && $tokens[$at]->text !== ']'; $at++) {
                    $marked = $marked || SourceCode::shortName($tokens[$at]->text) === 'sensitiveparameter';
                }

                continue;
            }
            if (in_array($part->text, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($part->text, [')', ']'], true)) {
                $depth--;
            }
            if ($depth > 0) {
                continue;
            }
            if ($part->text === ',') {
                $type = '';
                $marked = false;
            } elseif ($part->is(T_VARIABLE)) {
                if (! $marked && looksLikeASecret($type, substr($part->text, 1))) {
                    $found[] = 'line ' . $part->line . ': ' . $part->text;
                }
                // What follows is a default value, to the next comma.
                $type = '';
                $marked = true;
            } elseif (! in_array($part->text, ['&', '...'], true) && ! $part->is(T_ELLIPSIS)) {
                $type .= $part->text;
            }
        }
    }

    return $found;
}

it('marks every parameter of a closure or an arrow function in src that can hold a secret as sensitive', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $findings = [];
    foreach (SourceCode::phpFilesUnder($src) as $path) {
        foreach (unmarkedSecretsOfClosures((string) file_get_contents($path)) as $finding) {
            $findings[] = substr($path, strlen($src) + 1) . ' ' . $finding;
        }
    }

    expect($findings)->toBe([]);
});

it('catches a closure or an arrow function that takes a secret without the mark', function (string $code, string ...$found): void {
    expect(unmarkedSecretsOfClosures("<?php\n" . $code))->toBe(array_values(array_map(fn(string $name): string => 'line 2: ' . $name, $found)));
})->with([
    'a closure with a key' => ['$sign = function (string $apiKey): string { return "Bearer " . $apiKey; };', '$apiKey'],
    'an arrow function with a token' => ['$sign = fn(string $token): string => "Bearer " . $token;', '$token'],
    'a static closure with a secret by its type' => ['$read = static function (Secret $value, int $times): string { return $value->reveal(); };', '$value'],
    'a nullable type and a second parameter' => ['$a = fn(int $b, ?Secret $c = null, string $jwt = "") => 1;', '$c', '$jwt'],
    'a closure by reference with a cookie' => ['$a = function &(array &$cookies) { return $cookies; };', '$cookies'],
    'only the one that is not marked' => ['$a = fn(#[SensitiveParameter] string $key, string $signature) => 1;', '$signature'],
    'a variadic' => ['$a = function (string ...$tokens): void {};', '$tokens'],
]);

it('does not flag a closure that takes no secret or marks it', function (string $code): void {
    expect(unmarkedSecretsOfClosures("<?php\n" . $code))->toBe([]);
})->with([
    'no secret' => ['$a = fn(Tool $tool): string => $tool->name; $b = function (mixed $subject) use ($key): string { return ""; };'],
    'marked, by the short and by the full name' => ['$a = fn(#[SensitiveParameter] string $key) => 1; $b = function (#[\SensitiveParameter] Secret $c): void {};'],
    'a method, which reflection sees' => ['final class A { public function b(string $key): void {} }'],
    'a default value that names a secret' => ['$a = fn(string $name = "token", array $b = ["key" => 1]) => 1;'],
    'a variable used inside' => ['$a = fn(int $b): string => $token . $b;'],
]);

it('marks every parameter in src that can hold a secret as sensitive', function (): void {
    $allowed = parametersThatOnlySoundSecret();
    $parameters = parametersOfTheLibrary();
    $unmarked = [];
    $marked = 0;

    foreach ($parameters as $where => [$type, $isMarked]) {
        $name = substr($where, (int) strrpos($where, '$') + 1, -1);
        if (! looksLikeASecret($type, $name) || isset($allowed[$where])) {
            continue;
        }
        if ($isMarked) {
            $marked++;
        } else {
            $unmarked[] = $where;
        }
    }

    expect(count($parameters))->toBeGreaterThan(300, 'the rule must have found the parameters')
        ->and($marked)->toBeGreaterThan(10, 'the rule must have found the secrets that are marked')
        ->and($unmarked)->toBe([]);
});

it('lists as "only sounds secret" only a parameter that exists and is not marked', function (): void {
    $parameters = parametersOfTheLibrary();

    foreach (parametersThatOnlySoundSecret() as $where => $why) {
        expect($parameters)->toHaveKey($where)
            ->and($parameters[$where][1])->toBeFalse($where . ' is marked: it needs no entry')
            ->and(strlen($why))->toBeGreaterThan(15);
    }
});

it('takes a parameter for a secret by its type or by a word of its name', function (string $type, string $name): void {
    expect(looksLikeASecret($type, $name))->toBeTrue();
})->with([
    ['OrganiseYou\LlmGateway\Contract\Secret', 'value'],
    ['?OrganiseYou\LlmGateway\Contract\Secret', 'value'],
    ['OrganiseYou\LlmGateway\Contract\Credentials\Credentials', 'given'],
    ['OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplier', 'supplier'],
    ['OrganiseYou\LlmGateway\Contract\Secret|string', 'value'],
    ['string', 'key'],
    ['string', 'apiKey'],
    ['string', 'api_key'],
    ['?string', 'accessToken'],
    ['array', 'tokens'],
    ['string', 'clientSecret'],
    ['string', 'password'],
    ['string', 'authorization'],
    ['', 'bearer'],
    ['string', 'apikey'],
    ['string', 'APIKEY'],
    ['string', 'APIKey'],
    ['string', 'auth'],
    ['string', 'basicAuth'],
    ['string', 'passphrase'],
    ['string', 'jwt'],
    ['array', 'cookies'],
    ['string', 'sessionCookie'],
    ['string', 'signature'],
]);

it('does not take every parameter for a secret', function (string $type, string $name): void {
    expect(looksLikeASecret($type, $name))->toBeFalse();
})->with([
    ['string', 'keyword'],
    ['array', 'keywords'],
    ['string', 'monkey'],
    ['string', 'model'],
    ['OrganiseYou\LlmGateway\Contract\Request', 'request'],
    ['OrganiseYou\LlmGateway\Tests\Support\SecretaryNotes', 'notes'],
    ['string', 'tokenizer'],
    ['string', 'author'],
    ['string', 'authority'],
]);
