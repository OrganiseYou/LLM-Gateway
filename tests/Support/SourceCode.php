<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads PHP code as tokens for the architecture rules. Comments and the text of strings are not code, so a rule
 * that works on these tokens does not search them.
 */
final class SourceCode
{
    /**
     * @return list<string> every PHP file under the folder, as a path
     */
    public static function phpFilesUnder(string $folder): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<PhpToken> the tokens of the code without whitespace and comments
     */
    public static function tokens(string $code): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($code),
            fn(PhpToken $token): bool => ! $token->isIgnorable(),
        ));
    }

    /**
     * The name of a class without its namespace, lower-cased.
     */
    public static function shortName(string $name): string
    {
        return strtolower(ltrim((string) strrchr('\\' . $name, '\\'), '\\'));
    }

    /**
     * The lower-cased name of the PHP function called at this token, or null when the token is no such call: a
     * method, a static method or the declaration of a function is not the PHP function of that name.
     *
     * @param list<PhpToken> $tokens
     */
    public static function calledFunction(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || $next === null || $next->text !== '(') {
            return null;
        }
        if ($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
            return null;
        }

        return strtolower(ltrim($token->text, '\\'));
    }

    /**
     * Whether the token is a string that spells out a stream in memory: the one name that is known not to be a
     * file or an address. `php://temp` opens no connection but writes a file once it grows, so a rule about files
     * does not count it.
     */
    public static function isInMemoryStream(?PhpToken $argument, bool $tempToo = true): bool
    {
        return $argument !== null
            && $argument->is(T_CONSTANT_ENCAPSED_STRING)
            && preg_match($tempToo ? '/^[\'"]php:\/\/(memory|temp)\b/D' : '/^[\'"]php:\/\/memory\b/D', $argument->text) === 1;
    }

    /**
     * Whether the include or require at this token names a path the code does not spell out. Only __DIR__ and
     * string literals without a scheme are known to be a file of the package.
     *
     * @param list<PhpToken> $tokens
     */
    public static function includesPathNotSpelledOut(array $tokens, int $index): bool
    {
        $spelledOut = true;
        for ($at = $index + 1; isset($tokens[$at]) && $tokens[$at]->text !== ';'; $at++) {
            $part = $tokens[$at];
            $literal = $part->is(T_CONSTANT_ENCAPSED_STRING) && ! str_contains($part->text, '://');
            if (! $literal && ! $part->is(T_DIR) && ! in_array($part->text, ['.', '(', ')'], true)) {
                $spelledOut = false;
            }
        }

        return ! $spelledOut || $at === $index + 1;
    }
}
