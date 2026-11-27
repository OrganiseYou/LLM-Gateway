<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Tests\Support\MarklessAdapter;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;
use OrganiseYou\LlmGateway\Tests\Support\TalkAdapter;

// An adapter opens an opaque part by its mark, the constant OPAQUE_FORMAT. Two adapter classes with the same mark
// would take each other's parts for their own, and nothing at run time can tell. So the marks of the adapters of
// the library differ, and they come from the range the library keeps for itself: a1 to a9. An adapter a host
// writes takes a mark outside that range.

final readonly class MarksSampleFirstAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = 'a1';
}

final readonly class MarksSampleSecondAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = 'a2';
}

final readonly class MarksSampleSameAsFirstAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = 'a1';
}

final readonly class MarksSampleOfAHostAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = 'x7';
}

/**
 * What is wrong with the marks of these adapter classes; empty when nothing is.
 *
 * @param list<class-string> $classes
 * @param bool $ofTheLibrary true: the marks must be a1 to a9; false: they must not be
 * @return list<string>
 */
function findingsOfMarks(array $classes, bool $ofTheLibrary): array
{
    $findings = [];
    $seen = [];
    foreach ($classes as $class) {
        if (! is_subclass_of($class, AbstractAdapter::class)) {
            continue;
        }
        $mark = (new ReflectionClassConstant($class, 'OPAQUE_FORMAT'))->getValue();
        if (! is_string($mark) || $mark === '') {
            continue;
        }
        if (preg_match('/^[a-z][a-z0-9]{1,2}$/D', $mark) !== 1) {
            $findings[] = $class . ': the mark is not a small letter and then one or two small letters or digits';
        }
        $reserved = preg_match('/^a[1-9]$/D', $mark) === 1;
        if ($ofTheLibrary && ! $reserved) {
            $findings[] = $class . ': an adapter of the library takes its mark from a1 to a9';
        }
        if (! $ofTheLibrary && $reserved) {
            $findings[] = $class . ': a1 to a9 are kept for the adapters of the library';
        }
        if (isset($seen[$mark])) {
            $findings[] = $class . ': the same mark as ' . $seen[$mark];
        }
        $seen[$mark] ??= $class;
    }

    return $findings;
}

it('gives every adapter of the library its own mark, from the range the library keeps', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $classes = [];
    foreach (SourceCode::classesOfTheLibrary($src) as $path => $class) {
        if (str_starts_with($path, 'Adapter/')) {
            $classes[] = $class;
        }
    }

    expect($classes)->toContain(AbstractAdapter::class)
        ->and(findingsOfMarks($classes, true))->toBe([]);
});

it('keeps the adapters under tests out of the range of the library', function (): void {
    expect(findingsOfMarks([TalkAdapter::class, MarklessAdapter::class], false))->toBe([]);
});

it('finds two adapters with the same mark, a mark outside the range and a mark in another form', function (): void {
    expect(findingsOfMarks([MarksSampleFirstAdapter::class, MarksSampleSecondAdapter::class], true))->toBe([])
        ->and(findingsOfMarks([MarksSampleFirstAdapter::class, MarksSampleSecondAdapter::class, MarksSampleSameAsFirstAdapter::class], true))
        ->toBe([MarksSampleSameAsFirstAdapter::class . ': the same mark as ' . MarksSampleFirstAdapter::class])
        ->and(findingsOfMarks([MarksSampleOfAHostAdapter::class], true))
        ->toBe([MarksSampleOfAHostAdapter::class . ': an adapter of the library takes its mark from a1 to a9'])
        ->and(findingsOfMarks([MarksSampleFirstAdapter::class], false))
        ->toBe([MarksSampleFirstAdapter::class . ': a1 to a9 are kept for the adapters of the library'])
        ->and(findingsOfMarks([MarksSampleOfAHostAdapter::class, TalkAdapter::class], false))->toBe([]);
});
