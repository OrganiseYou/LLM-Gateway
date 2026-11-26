<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use FilesystemIterator;
use LogicException;
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
     * @return list<string> every file under the folder, of any kind, as a path
     */
    public static function filesUnder(string $folder): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The classes, interfaces, enums and traits a piece of PHP code declares, in order: the name, or "an anonymous
     * class" for one without a name. `Name::class` and `new Name()` declare nothing.
     *
     * @return list<string>
     */
    public static function declaredIn(string $code): array
    {
        $tokens = self::tokens($code);

        $declared = [];
        foreach ($tokens as $index => $token) {
            if (! $token->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT])) {
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            $next = $tokens[$index + 1] ?? null;
            if ($previous !== null && $previous->is(T_DOUBLE_COLON)) {
                continue;
            }
            $declared[] = $token->is(T_CLASS) && ($next === null || ! $next->is(T_STRING)) ? 'an anonymous class' : (string) $next?->text;
        }

        return $declared;
    }

    /**
     * Every class, interface and enum of the library, by the path of its file under src.
     *
     * @return array<string, class-string> the path under src => the name
     */
    public static function classesOfTheLibrary(string $src): array
    {
        $classes = [];
        foreach (self::phpFilesUnder($src) as $path) {
            $relative = substr($path, strlen($src) + 1);
            $class = 'OrganiseYou\\LlmGateway\\' . str_replace('/', '\\', substr($relative, 0, -4));
            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
                throw new LogicException('The file ' . $relative . ' does not hold the class its path names.');
            }
            $classes[$relative] = $class;
        }

        return $classes;
    }

    /**
     * The text of a piece of PHP code in two parts: its comments, and everything else (names, strings, text
     * outside PHP), each with the other part left out. A rule about words can then be stricter for what runs
     * than for what is only read.
     *
     * @return array{code: string, comments: string}
     */
    public static function codeAndComments(string $code): array
    {
        $parts = ['code' => '', 'comments' => ''];
        foreach (PhpToken::tokenize($code) as $token) {
            $isComment = $token->is([T_COMMENT, T_DOC_COMMENT]);
            $parts[$isComment ? 'comments' : 'code'] .= $token->text;
            // The line numbers of both parts stay those of the file.
            $parts[$isComment ? 'code' : 'comments'] .= str_repeat("\n", substr_count($token->text, "\n"));
        }

        return $parts;
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
