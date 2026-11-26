<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;

// LR-41 and the project guide: no real key, account name or customer text in the repository; fixtures are written
// by hand and are made up. The one key the tests use is the marker of the shared test set, which is no key. This
// rule searches every file under src and tests for text in the form of a key. The secret scan of the CI workflow
// does the same on every push with a longer list; this one runs with the tests, before a commit exists.

/**
 * The texts in a file that have the form of a credential.
 *
 * @param bool $anyLongToken also report any unbroken run of 32 or more letters and digits that mixes capitals,
 *                           small letters and digits, and any run of 32 or more hexadecimal digits: the forms of
 *                           most keys and tokens. For fixtures, which are data; code holds class names, patterns
 *                           and hashes of that length.
 * @return list<string> one line per finding: the line and the kind, never the text itself
 */
function keyLookAlikes(string $text, bool $anyLongToken): array
{
    $kinds = [
        'a key with the prefix sk-' => '/\bsk-[A-Za-z0-9_-]{16,}/',
        'a key with a live or test prefix' => '/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{10,}/',
        'a key with a vendor prefix' => '/\b(?:xai|gsk|hf|ghp|gho|ghs|glpat|pat|key|api)[-_][A-Za-z0-9_-]{24,}/',
        'a cloud API key' => '/\bAIza[0-9A-Za-z_-]{30,}/',
        'a cloud access key id' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
        'a signed token' => '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{4,}/',
        'a private key' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'a bearer token' => '/\bBearer\s+[A-Za-z0-9._~+\/=-]{24,}/',
    ];
    if ($anyLongToken) {
        $kinds['a long token'] = '/(?<![A-Za-z0-9+\/_-])(?=[A-Za-z0-9+\/_-]*[a-z])(?=[A-Za-z0-9+\/_-]*[A-Z])(?=[A-Za-z0-9+\/_-]*[0-9])[A-Za-z0-9+\/_-]{32,}/';
        $kinds['a long hexadecimal token'] = '/(?<![A-Za-z0-9])(?=[0-9A-Fa-f]*[0-9])(?=[0-9A-Fa-f]*[A-Fa-f])[0-9A-Fa-f]{32,}(?![A-Za-z0-9])/';
    }

    // The marker of the tests is no key. It is taken out before the search, wherever it stands.
    $text = str_replace(ContractTestCase::MARKER_KEY, '', $text);

    $found = [];
    foreach ($kinds as $kind => $pattern) {
        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new LogicException('The pattern for ' . $kind . ' is not valid.');
        }
        foreach ($matches[0] as [, $at]) {
            $line = substr_count($text, "\n", 0, $at) + 1;
            $found[sprintf('%06d %s', $line, $kind)] = 'line ' . $line . ': ' . $kind;
        }
    }
    ksort($found);

    return array_values($found);
}

it('holds nothing in the form of a real key in any fixture', function (): void {
    $fixtures = dirname(__DIR__) . '/fixtures';
    $files = SourceCode::filesUnder($fixtures);

    expect(count($files))->toBeGreaterThan(15, 'the rule must have found the fixtures');

    $findings = [];
    foreach ($files as $path) {
        foreach (keyLookAlikes((string) file_get_contents($path), true) as $finding) {
            $findings[] = substr($path, strlen($fixtures) + 1) . ' ' . $finding;
        }
    }

    expect($findings)->toBe([]);
});

it('holds nothing in the form of a real key in any file of src and tests', function (): void {
    $root = dirname(__DIR__, 2);
    $files = [...SourceCode::filesUnder($root . '/src'), ...SourceCode::filesUnder($root . '/tests')];

    expect(count($files))->toBeGreaterThan(150, 'the rule must have found the files');

    $findings = [];
    foreach ($files as $path) {
        foreach (keyLookAlikes((string) file_get_contents($path), false) as $finding) {
            $findings[] = substr($path, strlen($root) + 1) . ' ' . $finding;
        }
    }

    expect($findings)->toBe([]);
});

// The samples are put together here from pieces, so that this file holds no text in the form of a key itself.
it('catches a text in the form of a key', function (string $kind, string ...$pieces): void {
    $sample = '{"note": "made up", "value": "' . implode('', $pieces) . '"}';

    expect(keyLookAlikes("{\n" . $sample . "\n}", true))->toContain('line 2: ' . $kind);
})->with([
    'a secret key' => ['a key with the prefix sk-', 'sk', '-', 'proj-', 'a1B2c3D4e5F6g7H8', 'i9J0'],
    'a short secret key' => ['a key with the prefix sk-', 'sk', '-', '0123456789abcdef'],
    'a live key' => ['a key with a live or test prefix', 'sk', '_live_', '0123456789abcdefABCD'],
    'a key with a vendor prefix' => ['a key with a vendor prefix', 'xai', '-', 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4'],
    'a token of a code host' => ['a key with a vendor prefix', 'ghp', '_', 'a1B2c3D4e5F6g7H8i9J0k1L2m3N4o5P6q7R8'],
    'a cloud API key' => ['a cloud API key', 'AI', 'za', 'SyA1b2C3d4E5f6G7h8I9j0K1l2M3n4O5p6Q'],
    'a cloud access key id' => ['a cloud access key id', 'AK', 'IA', 'ABCDEFGHIJKLMNOP'],
    'a signed token' => ['a signed token', 'ey', 'JhbGciOiJIUzI1NiJ9', '.', 'eyJzdWIiOiIxMjM0In0', '.', 'c2lnbmF0dXJl'],
    'a private key' => ['a private key', '-----BEGIN', ' RSA PRIVATE ', 'KEY-----'],
    'a bearer token' => ['a bearer token', 'Bearer', ' ', 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4'],
    'any long token' => ['a long token', 'q7Zt', 'R2mX', '9LpA', 'c4Vn', 'K8sD', 'f1Hy', 'W6bG', 'u3Je', 'T5oM', 'x0Ni'],
    'a mixed token of 32 characters' => ['a long token', 'q7Zt', 'R2mX', '9LpA', 'c4Vn', 'K8sD', 'f1Hy', 'W6bG', 'u3Je'],
    'a hexadecimal token of 32 characters' => ['a long hexadecimal token', '0f1e', '2d3c', '4b5a', '6978', '8796', 'a5b4', 'c3d2', 'e1f0'],
    'a hexadecimal token in capitals, and longer' => ['a long hexadecimal token', '0F1E', '2D3C', '4B5A', '6978', '8796', 'A5B4', 'C3D2', 'E1F0', '0F1E', '2D3C'],
]);

it('does not take made-up test data for a key', function (string $text): void {
    expect(keyLookAlikes($text, true))->toBe([]);
})->with([
    'the marker of the tests' => ['{"Authorization": "Bearer ' . ContractTestCase::MARKER_KEY . '", "key": "' . ContractTestCase::MARKER_KEY . '"}'],
    'the keys the tests make up' => ['key-of-the-test, token-1, key-of-another-test, session=abc'],
    'ids and model names' => ['{"ref": "ref_1", "by": "model-a-0001", "id": "call_0", "trace": "contract-trace-1"}'],
    'a sentence' => ['A field this format does not document, and a kind of event this format does not document.'],
    'a long word without digits or capitals' => ['a_very_long_name_of_a_setting_that_goes_on_and_on_and_on'],
    'a short hexadecimal id' => ['{"id": "0f1e2d3c4b5a6978", "colour": "#a1b2c3"}'],
    'a long number' => ['{"created": 1800000000, "big": 12345678901234567890123456789012345}'],
    'a mixed token of 31 characters' => [implode('', ['q7Zt', 'R2mX', '9LpA', 'c4Vn', 'K8sD', 'f1Hy', 'W6bG', 'u3J'])],
    'a schema' => ['{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]}'],
]);

it('reports a long token only where it is asked to', function (): void {
    $token = implode('', ['q7Zt', 'R2mX', '9LpA', 'c4Vn', 'K8sD', 'f1Hy', 'W6bG', 'u3Je', 'T5oM', 'x0Ni']);

    $hex = implode('', ['0f1e', '2d3c', '4b5a', '6978', '8796', 'a5b4', 'c3d2', 'e1f0']);

    expect(keyLookAlikes($token, false))->toBe([])
        ->and(keyLookAlikes($token, true))->toBe(['line 1: a long token'])
        ->and(keyLookAlikes($hex, false))->toBe([])
        ->and(keyLookAlikes($hex, true))->toBe(['line 1: a long hexadecimal token']);
});
