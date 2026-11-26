<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Tests\Support\SourceCode;

// The project guide: `declare(strict_types=1);` in every file. Without it PHP turns "5" into 5 at a call, and a
// wrong value from a provider's answer would pass a typed constructor unseen.

/**
 * Whether the first statement of a PHP file switches strict types on.
 */
function declaresStrictTypes(string $code): bool
{
    // The opening tag is no token of the code: it is checked on the text itself.
    $start = array_map(fn(PhpToken $token): string => strtolower($token->text), array_slice(SourceCode::tokens($code), 0, 7));

    return str_starts_with($code, '<?php') && $start === ['declare', '(', 'strict_types', '=', '1', ')', ';'];
}

it('declares strict types in every file of src and tests', function (): void {
    $root = dirname(__DIR__, 2);
    $files = [...SourceCode::phpFilesUnder($root . '/src'), ...SourceCode::phpFilesUnder($root . '/tests')];

    expect(count($files))->toBeGreaterThan(100, 'the rule must have found the files');

    $without = [];
    foreach ($files as $path) {
        if (! declaresStrictTypes((string) file_get_contents($path))) {
            $without[] = substr($path, strlen($root) + 1);
        }
    }

    expect($without)->toBe([]);
});

it('catches a file that does not declare strict types first', function (string $code): void {
    expect(declaresStrictTypes($code))->toBeFalse();
})->with([
    'no declaration' => ["<?php\n\nnamespace A;\n\nfinal class B {}\n"],
    'strict types switched off' => ["<?php\n\ndeclare(strict_types=0);\n"],
    'another declaration' => ["<?php\n\ndeclare(ticks=1);\n"],
    'the declaration only in a comment' => ["<?php\n\n// declare(strict_types=1);\n\nnamespace A;\n"],
    'the declaration after other code' => ["<?php\n\nnamespace A;\n\ndeclare(strict_types=1);\n"],
    'text before the opening tag' => ["\n<?php\n\ndeclare(strict_types=1);\n"],
    'an empty file' => [''],
]);

it('accepts the declaration in the forms PHP takes', function (string $code): void {
    expect(declaresStrictTypes($code))->toBeTrue();
})->with([
    'as the project writes it' => ["<?php\n\ndeclare(strict_types=1);\n\nnamespace A;\n"],
    'after a comment' => ["<?php\n\n/** A file. */\n\ndeclare(strict_types=1);\n"],
    'with spaces and capitals' => ["<?php DECLARE ( strict_types = 1 ) ;\n"],
]);
