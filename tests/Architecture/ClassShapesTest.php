<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\LoopGuardContext;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\LoopGuardValidator;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\SchemaCheckTooCostly;
use OrganiseYou\LlmGateway\Adapter\Shared\Internal\SchemaReferenceLoop;
use OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplierFailed;
use OrganiseYou\LlmGateway\Contract\ForeignFault;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Contract\WrongUse;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Testing\AttemptRecorder;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeGateway;
use OrganiseYou\LlmGateway\Testing\FakeGatewaySubject;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Testing\OutputForms;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;
use OrganiseYou\LlmGateway\Transport\TransportException;

// The project guide: value objects are `final readonly`, closed lists are enums, nothing keeps state. Here the rule
// is turned around so that no class can be missed: EVERY class under src is final and readonly, unless it stands in
// the list below with the shape it has instead and the reason. A new class that is neither fails this test.

/**
 * The classes of src that are not `final readonly`, on purpose.
 *
 * @return array<class-string, array{string, string}> class => [the shape it must have, why]
 */
function classesThatAreNotFinalReadonly(): array
{
    $exception = 'an exception: PHP keeps its message and its trace in properties that are written when it is built';
    $forTests = 'lives in a test and holds what the test scripted or recorded: one object, never static';
    $extendsValidator = 'extends a class of the JSON Schema validator that is not readonly';

    return [
        AbstractAdapter::class => ['abstract readonly', 'the base an adapter extends; every adapter is readonly through it'],
        GatewayException::class => ['final', $exception],
        InvalidValue::class => ['final', $exception],
        WrongUse::class => ['final', $exception],
        ForeignFault::class => ['final', $exception],
        TransportException::class => ['final', $exception],
        TokenSupplierFailed::class => ['final', $exception],
        SchemaCheckTooCostly::class => ['final', $exception],
        SchemaReferenceLoop::class => ['final', $exception],
        LoopGuardContext::class => ['final', $extendsValidator],
        LoopGuardValidator::class => ['final', $extendsValidator],
        Secret::class => ['final', 'keeps its value in a paused generator, which a readonly property cannot hide better; it has no property that can be written'],
        Rules::class => ['final', 'only static methods that answer yes or no: no object of it is ever built'],
        GatewayFactory::class => ['final', 'only static methods: no object of it is ever built'],
        OutputForms::class => ['final', 'only static methods: no object of it is ever built'],
        FakeGateway::class => ['final', $forTests],
        FakeTransport::class => ['final', $forTests],
        FakeClock::class => ['final', $forTests],
        AttemptRecorder::class => ['final', $forTests],
        FakeGatewaySubject::class => ['final', $forTests],
        ContractTestCase::class => ['abstract', 'the shared test set: a test of an adapter extends it, and the test case of the test framework it extends is not readonly'],
    ];
}

/**
 * The shape of a class in the words of the list above.
 *
 * @template T of object
 * @param ReflectionClass<T> $class
 */
function shapeOf(ReflectionClass $class): string
{
    return trim(($class->isAbstract() ? 'abstract ' : '') . ($class->isFinal() ? 'final ' : '') . ($class->isReadOnly() ? 'readonly' : ''));
}

it('has only final readonly classes in src, but for the listed ones', function (): void {
    $exceptions = classesThatAreNotFinalReadonly();
    $wrong = [];
    $checked = 0;

    foreach (SourceCode::classesOfTheLibrary(dirname(__DIR__, 2) . '/src') as $class) {
        $reflection = new ReflectionClass($class);
        if ($reflection->isInterface() || $reflection->isEnum()) {
            continue;
        }
        $checked++;

        $expected = $exceptions[$class][0] ?? 'final readonly';
        if (shapeOf($reflection) !== $expected) {
            $wrong[] = $class . ' is "' . shapeOf($reflection) . '" and must be "' . $expected . '"';
        }
    }

    expect($checked)->toBeGreaterThan(40, 'the rule must have found the classes')
        ->and($wrong)->toBe([]);
});

it('declares one class in every file of src, the one the file is named after, and no anonymous class', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $wrong = [];
    $files = SourceCode::phpFilesUnder($src);

    foreach ($files as $path) {
        $declared = SourceCode::declaredIn((string) file_get_contents($path));
        if ($declared !== [basename($path, '.php')]) {
            $wrong[] = substr($path, strlen($src) + 1) . ' declares: ' . implode(', ', $declared);
        }
    }

    // With one class per file, named after it, the rules above have seen every class there is.
    expect(count($files))->toBe(count(SourceCode::classesOfTheLibrary($src)))
        ->and($wrong)->toBe([]);
});

it('sees every class a file declares, also a second one and one without a name', function (string $code, array $declared): void {
    expect(SourceCode::declaredIn("<?php\n" . $code))->toBe($declared);
})->with([
    'one class' => ['final readonly class Usage {}', ['Usage']],
    'a second class in the file' => ['final readonly class Usage {} class Cache { public array $kept = []; }', ['Usage', 'Cache']],
    'an anonymous class' => ['final class A { public function b(): object { return new class { public array $kept = []; }; } }', ['A', 'an anonymous class']],
    'an anonymous class with arguments, readonly' => ['$a = new readonly class ($b) { public function __construct(public int $c) {} };', ['an anonymous class']],
    'an interface, an enum and a trait' => ['interface A {} enum B: string { case C = "c"; } trait D {}', ['A', 'B', 'D']],
    'a class name as a value declares nothing' => ['$a = Usage::class; $b = new Usage(1, 2); $c = $d::class;', []],
]);

it('lists as an exception only a class that exists, is in src and needs to be one', function (): void {
    $classes = SourceCode::classesOfTheLibrary(dirname(__DIR__, 2) . '/src');

    foreach (classesThatAreNotFinalReadonly() as $class => [$shape, $why]) {
        expect(in_array($class, $classes, true))->toBeTrue($class . ' is not a class of src')
            ->and($shape)->not->toBe('final readonly', $class . ' needs no exception')
            ->and($shape)->toBeIn(['final', 'abstract', 'abstract readonly'])
            ->and(strlen($why))->toBeGreaterThan(20, $class . ' needs a reason');
    }
});

it('keeps no static property in any class of src, and no property that can be written in a class without state', function (): void {
    $stateless = [Rules::class, GatewayFactory::class, OutputForms::class, Secret::class];
    $found = [];

    foreach (SourceCode::classesOfTheLibrary(dirname(__DIR__, 2) . '/src') as $class) {
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($property->isStatic()) {
                $found[] = $class . '::$' . $property->getName() . ' is static';
            } elseif (in_array($class, $stateless, true) && ! $property->isReadOnly()) {
                $found[] = $class . '::$' . $property->getName() . ' can be written';
            }
        }
    }

    expect($found)->toBe([]);
});

it('has only string-backed enums in src', function (): void {
    $enums = 0;
    foreach (SourceCode::classesOfTheLibrary(dirname(__DIR__, 2) . '/src') as $class) {
        if (! enum_exists($class)) {
            continue;
        }
        $enums++;
        $reflection = new ReflectionEnum($class);

        expect($reflection->isBacked())->toBeTrue($class . ' must be backed')
            ->and((string) $reflection->getBackingType())->toBe('string', $class . ' must be backed by a string');
    }

    expect($enums)->toBeGreaterThan(10, 'the rule must have found the enums');
});

it('tells the shape of a class as the list writes it', function (): void {
    expect(shapeOf(new ReflectionClass(AbstractAdapter::class)))->toBe('abstract readonly')
        ->and(shapeOf(new ReflectionClass(FakeGateway::class)))->toBe('final')
        ->and(shapeOf(new ReflectionClass(ArrayObject::class)))->toBe('')
        ->and(shapeOf(new ReflectionClass(OrganiseYou\LlmGateway\Contract\Usage::class)))->toBe('final readonly');
});
