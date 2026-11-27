<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Response;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Readme\ReadmeExampleContractTest;
use OrganiseYou\LlmGateway\Tests\Support\ReadmeSample;
use OrganiseYou\LlmGateway\Tests\Support\ReadmeSamples;
use OrganiseYou\LlmGateway\Tests\Support\RecordingPsr18Client;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use PHPUnit\Framework\SkippedTest;

// The README is part of the library: a sample that is marked as running must run against the code in src today,
// and print what the README says it prints. How a sample is executed, and why that way, is written on
// OrganiseYou\LlmGateway\Tests\Support\ReadmeSamples.
//
// The marker of a sample is an HTML comment on the line before its fence:
//
//     <!-- example: runs -->                          executed by the tests below
//     <!-- example: coming -->                        shows something that is not built yet; never executed
//     <!-- example: coming, placeholder: SomeName -->  the same, with a made-up class name in the code

/**
 * A sample for a test of the runner itself. It starts far below the last line of the README, so that it shares no
 * line with a sample of the README.
 */
function sampleOfCode(string $code, int $line = 100_000): ReadmeSample
{
    return new ReadmeSample($line, ReadmeSample::RUNS, [], $code);
}

/**
 * A count the README writes as a word.
 */
function numberOfWord(string $word): ?int
{
    $words = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10,
    ];

    return $words[strtolower($word)] ?? null;
}

describe('the samples of the README', function (): void {
    it('are found, and every one says whether it runs today or is not built yet', function (): void {
        $samples = ReadmeSamples::all();
        $withoutMarker = array_map(
            fn(ReadmeSample $sample): string => $sample->name(),
            array_filter($samples, fn(ReadmeSample $sample): bool => $sample->marker === null),
        );

        expect(count($samples))->toBeGreaterThan(10, 'the test must have found the samples')
            ->and(array_values($withoutMarker))->toBe([]);
    });

    it('stand in the one form of fence the tests can execute', function (): void {
        $problems = [];
        foreach (ReadmeSamples::all() as $sample) {
            if ($sample->fenceProblem !== null) {
                $problems[] = $sample->name() . ': ' . $sample->fenceProblem;
            }
        }

        expect($problems)->toBe([]);
    });

    it('have no marker that stands anywhere else than on the line before a sample', function (): void {
        $text = ReadmeSamples::text();
        $markers = preg_match_all('/^.*<!--\s*example\b.*$/mi', $text);
        $onASample = count(array_filter(ReadmeSamples::all(), fn(ReadmeSample $sample): bool => $sample->marker !== null));

        expect($markers)->toBe($onASample);
    });

    it('runs as the README shows it', function (ReadmeSample $sample): void {
        expect(ReadmeSamples::run($sample)->problems)->toBe([], $sample->name());
    })->with(function (): Generator {
        foreach (ReadmeSamples::all() as $sample) {
            if ($sample->marker === ReadmeSample::RUNS) {
                yield 'line ' . $sample->line => [$sample];
            }
        }
    });

    it('names no placeholder on a sample that runs: a class that is made up cannot run', function (): void {
        foreach (ReadmeSamples::all() as $sample) {
            if ($sample->marker === ReadmeSample::RUNS) {
                expect($sample->placeholders)->toBe([], $sample->name());
            }
        }
    });

    it('still waits for something where it is marked as not built yet', function (ReadmeSample $sample): void {
        $exists = fn(string $class): bool => class_exists($class) || interface_exists($class) || enum_exists($class);
        $missing = array_values(array_filter($sample->usedClasses(), fn(string $class): bool => ! $exists($class)));

        // A sample that waits for nothing has arrived: mark it as "runs", and it is executed from then on.
        expect([...$missing, ...$sample->placeholders])->not->toBe(
            [],
            $sample->name() . ' is marked as not built yet, but every class it uses exists and it names no placeholder: mark it as "runs"',
        );

        $src = dirname(__DIR__, 2) . '/src';
        $shortNames = array_map(SourceCode::shortName(...), array_values(SourceCode::classesOfTheLibrary($src)));
        $prose = ReadmeSamples::prose();
        foreach ($sample->placeholders as $placeholder) {
            expect(preg_match('/\b' . preg_quote($placeholder, '/') . '\b/', $sample->code))->toBe(1, $placeholder . ' is named as a placeholder of ' . $sample->name() . ' and is not in its code')
                ->and(in_array($placeholder, array_map(fn(string $class): string => substr((string) strrchr('\\' . $class, '\\'), 1), $sample->usedClasses()), true))
                ->toBeFalse($placeholder . ' is a placeholder and is imported with a use line in ' . $sample->name())
                ->and($exists($placeholder) || $exists(ReadmeSamples::NAMESPACE . '\\' . $placeholder) || in_array(strtolower($placeholder), $shortNames, true))
                ->toBeFalse('a class ' . $placeholder . ' exists now: it is no placeholder any more in ' . $sample->name())
                ->and(preg_match('/`' . preg_quote($placeholder, '/') . '`\s+is\s+a\s+placeholder\b/', $prose))
                ->toBe(1, 'the README must say in words that `' . $placeholder . '` is a placeholder');
        }
    })->with(function (): Generator {
        foreach (ReadmeSamples::all() as $sample) {
            if ($sample->marker === ReadmeSample::COMING) {
                yield 'line ' . $sample->line => [$sample];
            }
        }
    });

    it('has samples of both kinds, so both rules above have something to check', function (): void {
        $markers = array_count_values(array_map(fn(ReadmeSample $sample): string => (string) $sample->marker, ReadmeSamples::all()));

        expect($markers[ReadmeSample::RUNS] ?? 0)->toBeGreaterThan(10)
            ->and($markers[ReadmeSample::COMING] ?? 0)->toBeGreaterThan(0);
    });
});

describe('what the samples of the README declare', function (): void {
    it('gives a function that builds a gateway on a PSR-18 client, as the sample of a real provider says', function (): void {
        $connect = ReadmeSamples::declared('connect');
        $examplePreset = ReadmeSamples::declared('examplePreset');
        assert(is_callable($connect) && is_callable($examplePreset));

        $preset = $examplePreset();
        $client = new RecordingPsr18Client([
            'POST https://llm.example.test/api/talk' => new Response(200, ['Content-Type' => 'application/json'], '{"say":"Hello.","end":"done"}'),
        ]);
        $factory = new HttpFactory();

        $gateway = $connect($preset, 'test-key-not-a-real-one', $client, $factory, $factory);

        expect($preset)->toBeInstanceOf(Preset::class)
            ->and($gateway)->toBeInstanceOf(Gateway::class)
            ->and($client->requests)->toBe([]);
        assert($gateway instanceof Gateway);

        // The first call goes to the client, at the address of the preset, signed in with the key.
        $answer = $gateway->complete(new Request('example-model', [Message::user('Say hello.')], 30.0));

        expect($answer->text)->toBe('Hello.')
            ->and($client->asked())->toBe(['POST https://llm.example.test/api/talk'])
            ->and($client->requests[0]->getHeaderLine('Authorization'))->toBe('Bearer test-key-not-a-real-one');
    });

    it('gives a test class that extends the shared test set, and it is the one this suite runs', function (): void {
        $class = ReadmeSamples::declared('ExampleAdapterContractTest');

        // ReadmeExampleContractTest builds every subject of the set with the subject() of this class.
        expect(is_subclass_of($class, ContractTestCase::class))->toBeTrue()
            ->and(method_exists($class, 'subject'))->toBeTrue();
    });

    it('skips in the shared test set exactly what the README says the example skips', function (): void {
        // Every test of the set, called the way the test runner calls it, on the example of the README.
        $skipped = [];
        $tests = 0;
        foreach (get_class_methods(ReadmeExampleContractTest::class) as $method) {
            if (! str_starts_with($method, 'test')) {
                continue;
            }
            $tests++;
            try {
                (new ReflectionMethod(ReadmeExampleContractTest::class, $method))->invoke(new ReadmeExampleContractTest($method));
            } catch (SkippedTest $skip) {
                $skipped[$method] = $skip->getMessage();
            }
        }

        // The one skip that depends on a setting of PHP, which the README names apart.
        $forTheSetting = array_filter($skipped, fn(string $why): bool => str_contains($why, 'zend.exception_ignore_args'));
        $ofTheExample = count($skipped) - count($forTheSetting);
        // The example says of two things that its format does not have them: data that must go back unchanged, and a
        // response that forbids a retry.
        $ofTheFixtures = count(array_filter($skipped, fn(string $why): bool => str_starts_with($why, 'The fixtures say')));
        $forOpaqueData = count(array_filter($skipped, fn(string $why): bool => str_starts_with($why, 'The fixtures say an answer with opaque data does not apply')));
        $forNoRetry = count(array_filter($skipped, fn(string $why): bool => str_starts_with($why, 'The fixtures say a response that forbids a retry does not apply')));

        $prose = ReadmeSamples::prose();
        expect(preg_match('/streams natively skips (\w+):/', $prose, $everything))->toBe(1, 'the README must say how many tests a gateway that can do everything skips')
            ->and(preg_match('/it skips\s+those (\w+), the (\w+) tests of an answer with opaque data and the (\w+) test of a response that forbids a retry\./', $prose, $example))->toBe(1, 'the README must say how many tests its example skips');

        expect($tests)->toBeGreaterThan(40)
            ->and($forOpaqueData)->toBeGreaterThan(0)
            ->and($forNoRetry)->toBeGreaterThan(0)
            ->and($ofTheFixtures)->toBe($forOpaqueData + $forNoRetry)
            ->and(numberOfWord($everything[1] ?? ''))->toBe($ofTheExample - $ofTheFixtures)
            ->and(numberOfWord($example[1] ?? ''))->toBe($ofTheExample - $ofTheFixtures)
            ->and(numberOfWord($example[2] ?? ''))->toBe($forOpaqueData)
            ->and(numberOfWord($example[3] ?? ''))->toBe($forNoRetry);
    });

    it('marks as sensitive every parameter of the example adapter that takes a response or a piece of one', function (): void {
        $class = ReadmeSamples::declared('ExampleAdapter');
        assert(class_exists($class));

        // What is not from a response: the request of the caller and what the base or the adapter itself decides.
        $notFromAResponse = ['model', 'request', 'stream', 'timeoutSeconds', 'reason', 'key', 'at'];

        $checked = 0;
        $unmarked = [];
        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                if (in_array($parameter->getName(), $notFromAResponse, true)) {
                    continue;
                }
                $checked++;
                if ($parameter->getAttributes(SensitiveParameter::class) === []) {
                    $unmarked[] = $method->getName() . '($' . $parameter->getName() . ')';
                }
            }
        }

        expect($checked)->toBeGreaterThan(10, 'the test must have found the parameters')
            ->and($unmarked)->toBe([]);
    });

    it('reads the model listing option of a preset one way in the example adapter: for the capabilities and for the model list', function (): void {
        $examplePreset = ReadmeSamples::declared('examplePreset');
        assert(is_callable($examplePreset));
        $preset = $examplePreset();
        assert($preset instanceof Preset);

        $gatewayWith = fn(array $options, FakeTransport $transport): Gateway => GatewayFactory::create(
            $preset->withAdapterOptions($options),
            new BearerKey('test-key-not-a-real-one'),
            $transport,
            new GatewayConfig(RetryPolicy::none()),
        );
        $errorOf = function (callable $call): ?GatewayException {
            try {
                $call();
            } catch (GatewayException $error) {
                return $error;
            }

            return null;
        };

        // A value outside the list: neither the capabilities nor the model list, and nothing is sent.
        $transport = new FakeTransport();
        $wrong = $gatewayWith(['model_listing' => 'value-of-no-list'], $transport);
        expect($errorOf(fn() => $wrong->capabilities('example-model'))?->reason)->toBe(ErrorReason::NotConfigured)
            ->and($errorOf(fn() => $wrong->models(10.0))?->reason)->toBe(ErrorReason::NotConfigured)
            ->and($errorOf(fn() => $wrong->models(10.0))?->detail)->toBe($errorOf(fn() => $wrong->capabilities('example-model'))?->detail)
            ->and($transport->requests())->toBe([]);

        // None: the capability says so, and the list is refused without a request.
        $none = $gatewayWith(['model_listing' => 'none'], $transport);
        expect($none->capabilities('example-model')->modelListing->value)->toBe('none')
            ->and($errorOf(fn() => $none->models(10.0))?->reason)->toBe(ErrorReason::NotSupported)
            ->and($transport->requests())->toBe([]);

        // Native, or not set: the list is asked.
        $transport->willReturn(new HttpResponse(200, [], '{"items":[]}'))->willReturn(new HttpResponse(200, [], '{"items":[]}'));
        expect($gatewayWith(['model_listing' => 'native'], $transport)->models(10.0))->toBe([])
            ->and($gatewayWith([], $transport)->models(10.0))->toBe([])
            ->and($transport->requests())->toHaveCount(2);
    });

    it('keeps the rules of a stream in the example adapter, whatever the server sends', function (): void {
        $examplePreset = ReadmeSamples::declared('examplePreset');
        assert(is_callable($examplePreset));
        $preset = $examplePreset();
        assert($preset instanceof Preset);

        $event = fn(string $name, array $data): string => 'event: ' . $name . "\n" . 'data: ' . json_encode($data) . "\n\n";
        $streamOf = function (string ...$chunks) use ($preset): array {
            $gateway = GatewayFactory::create(
                $preset,
                new BearerKey('test-key-not-a-real-one'),
                (new FakeTransport())->willStream(200, [], ...$chunks),
                new GatewayConfig(RetryPolicy::none()),
            );

            return iterator_to_array($gateway->stream(new Request('example-model', [Message::user('Say hello.')], 30.0)), false);
        };

        // Usage twice, a tool call that completes without a start, and a tool call that starts twice.
        $events = $streamOf(
            $event('used', ['in' => 3, 'out' => 2]),
            $event('call', ['ref' => 'ref_1', 'tool' => 'lookup', 'input' => '{"city":"Paris"}']),
            $event('call_open', ['ref' => 'ref_2', 'tool' => 'lookup']),
            $event('call_open', ['ref' => 'ref_2', 'tool' => 'lookup']),
            $event('call', ['ref' => 'ref_2', 'tool' => 'lookup', 'input' => '{"city":"Rome"}']),
            $event('used', ['in' => 9, 'out' => 9]),
            $event('end', ['end' => 'calls']),
        );

        expect(array_map(fn(object $e): string => (new ReflectionClass($e))->getShortName(), $events))->toBe([
            'UsageReported', 'ToolCallStarted', 'ToolCallCompleted', 'ToolCallStarted', 'ToolCallCompleted', 'StreamFinished',
        ]);
        $last = $events[5];
        assert($last instanceof StreamFinished && $events[1] instanceof ToolCallStarted && $events[3] instanceof ToolCallStarted);
        expect($last->answer->usage)->toEqual(new Usage(3, 2))
            ->and(array_map(fn(ToolCall $call): string => $call->id, $last->answer->toolCalls))->toBe(['ref_1', 'ref_2'])
            ->and([$events[1]->index, $events[1]->id, $events[3]->index, $events[3]->id])->toBe([0, 'ref_1', 1, 'ref_2']);

        // A call that ends under another name than it started with, and a stream that ends inside a call.
        foreach ([
            [$event('call_open', ['ref' => 'ref_1', 'tool' => 'lookup']), $event('call', ['ref' => 'ref_9', 'tool' => 'lookup', 'input' => '{}']), $event('end', ['end' => 'calls'])],
            [$event('call_open', ['ref' => 'ref_1', 'tool' => 'lookup']), $event('end', ['end' => 'calls'])],
        ] as $chunks) {
            $events = $streamOf(...$chunks);
            $failed = $events[count($events) - 1];

            expect($failed)->toBeInstanceOf(StreamFailed::class);
            assert($failed instanceof StreamFailed);
            expect($failed->error->reason)->toBe(ErrorReason::ProviderError);
        }
    });

    it('refuses in the example adapter a request it cannot write as JSON, before anything is sent', function (): void {
        $examplePreset = ReadmeSamples::declared('examplePreset');
        assert(is_callable($examplePreset));
        $preset = $examplePreset();
        assert($preset instanceof Preset);
        $transport = new FakeTransport();
        $gateway = GatewayFactory::create($preset, new BearerKey('test-key-not-a-real-one'), $transport, new GatewayConfig(RetryPolicy::none()));

        $request = new Request('example-model', [
            Message::user('Look it up.'),
            Message::assistant('', ToolCall::unreadable('call_1', 'lookup', 'not valid UTF-8', "\xFF")),
            Message::toolResult('call_1', 'lookup', 'The arguments could not be read.'),
        ], 30.0);

        $error = null;
        try {
            $gateway->complete($request);
        } catch (GatewayException $thrown) {
            $error = $thrown;
        }

        expect($error?->reason)->toBe(ErrorReason::Rejected)
            ->and($error?->detail)->toBe('the request holds a value that cannot be written as JSON')
            ->and($error?->attempts)->toBe(0)
            ->and($transport->requests())->toBe([]);
    });
});

describe('the runner of the samples', function (): void {
    it('reads the marker on the line before the fence, and nothing else as a marker', function (): void {
        $text = implode("\n", [
            'Text.',
            '',
            '<!-- example: runs -->',
            '```php',
            'echo 1;',
            '```',
            '',
            '<!-- example: coming, placeholder: OneName, OtherName -->',
            '```php',
            'use Vendor\Thing;',
            '',
            'final class Declared {}',
            '```',
            '',
            '<!-- example: runs -->',
            '',
            '```php',
            'echo 2;',
            '```',
            '',
            '```php',
            'echo 3;',
            '```',
            '',
            '<!-- example: someday -->',
            '```php',
            'echo 4;',
            '```',
            '',
            '```bash',
            'composer install',
            '```',
        ]);

        $samples = ReadmeSamples::in($text);

        expect(array_map(fn(ReadmeSample $sample): ?string => $sample->marker, $samples))->toBe(['runs', 'coming', null, null, null])
            ->and(array_map(fn(ReadmeSample $sample): int => $sample->line, $samples))->toBe([5, 10, 18, 22, 27])
            ->and(array_map(fn(ReadmeSample $sample): ?string => $sample->fenceProblem, $samples))->toBe([null, null, null, null, null])
            ->and($samples[0]->code)->toBe('echo 1;')
            ->and($samples[1]->placeholders)->toBe(['OneName', 'OtherName'])
            ->and($samples[1]->usedClasses())->toBe(['Vendor\Thing'])
            ->and($samples[1]->declares())->toBe(['Declared']);
    });

    it('finds a PHP sample in every form of fence, so that none hides from the rule of the marker', function (string $open, string $close, bool $canRun): void {
        $samples = ReadmeSamples::in("Text.\n\n" . $open . "\necho 'broken'\n" . $close . "\n\nMore text.\n");

        expect($samples)->toHaveCount(1)
            ->and($samples[0]->marker)->toBeNull()
            ->and($samples[0]->line)->toBe(4)
            ->and($samples[0]->fenceProblem === null)->toBe($canRun);
    })->with([
        'backticks' => ['```php', '```', true],
        'tildes' => ['~~~php', '~~~', true],
        'in capitals' => ['```PHP', '```', true],
        'a space before the word' => ['``` php', '```', true],
        'a longer fence' => ['`````php', '`````', true],
        'more words after php' => ['```php title="x.php"', '```', false],
        'a variant of the word' => ['```php8', '```', false],
        'indented' => ['  ```php', '  ```', false],
        'indented tildes with more words' => ['   ~~~php startline=3', '   ~~~', false],
    ]);

    it('does not execute a sample in a fence it cannot read as it is', function (): void {
        $sample = ReadmeSamples::in("<!-- example: runs -->\n  ```php\n  echo 1;\n  ```\n")[0];

        expect($sample->marker)->toBe('runs')
            ->and(fn() => ReadmeSamples::run($sample))->toThrow(LogicException::class, 'its fence is indented');
    });

    it('takes no other block for a PHP sample, and reads the words around the blocks', function (): void {
        $samples = ReadmeSamples::in("One.\n\n```bash\ncomposer install\n```\n\n~~~\n```php\necho 1;\n```\n~~~\n\nUse `php` and ```php``` in a line.\n\n```text\nphp\n```\n");

        expect($samples)->toBe([])
            ->and(ReadmeSamples::prose())->not->toContain('composer install')
            ->and(ReadmeSamples::prose())->toContain('## How to use it');
    });

    it('refuses a sample that is not closed', function (): void {
        expect(fn() => ReadmeSamples::in("<!-- example: runs -->\n```php\necho 1;\n"))->toThrow(LogicException::class, 'is not closed');
    });

    it('executes only a sample that is marked as running', function (): void {
        expect(fn() => ReadmeSamples::run(new ReadmeSample(100_000, ReadmeSample::COMING, [], 'echo 1;')))->toThrow(LogicException::class)
            ->and(fn() => ReadmeSamples::run(new ReadmeSample(100_000, null, [], 'echo 1;')))->toThrow(LogicException::class);
    });

    it('passes a sample that runs cleanly and prints what it shows', function (string $code, string $output): void {
        $run = ReadmeSamples::execute(sampleOfCode($code));

        expect($run->problems)->toBe([])
            ->and($run->output)->toBe($output);
    })->with([
        'no output shown: only a clean run is asked' => ["\$a = 1;\necho \$a + 1;", '2'],
        'an echo' => ["echo 'Hello.';  // prints Hello.", 'Hello.'],
        'a var_dump, whose line break is not shown' => ['var_dump(1 === 1);  // prints bool(true)', "bool(true)\n"],
        'a statement in a loop, which prints the same every time' => ["foreach ([1, 2] as \$a) {\n    echo 'x';  // prints x\n}", 'xx'],
        'a statement in a catch' => ["try {\n    throw new \\RuntimeException('no');\n} catch (\\RuntimeException \$e) {\n    echo \$e->getMessage();  // prints no\n}", 'no'],
        'a text that holds the word and a quote' => ["echo \"it's // prints\";  // prints it's // prints", "it's // prints"],
        'strict types, as in a file of a reader' => ["try {\n    strlen(5);\n} catch (\\TypeError) {\n    echo 'strict';  // prints strict\n}", 'strict'],
    ]);

    it('fails a sample that does not do what it shows', function (string $code, string $problem): void {
        $problems = ReadmeSamples::execute(sampleOfCode($code))->problems;

        expect($problems)->toHaveCount(1)
            ->and($problems[0])->toContain($problem);
    })->with([
        'another output' => ["echo 'Hello';  // prints Hello.", 'line 100000 printed "Hello" where the README shows "Hello."'],
        'another output on a later line' => ["echo 'a';  // prints a\necho 'b';  // prints c", 'line 100001 printed "b" where the README shows "c"'],
        'output between two shown statements' => ["echo 'a';  // prints a\necho 'x';\necho 'b';  // prints b", 'line 100002 printed "xb" where the README shows "b"'],
        'output after the last shown statement' => ["echo 'a';  // prints a\necho 'x';", 'the sample printed "x" after its last statement'],
        'a shown statement that is never reached' => ["if (1 > 2) {\n    echo 'a';  // prints a\n}", 'line 100001 shows output and was never reached'],
        'an exception' => ["throw new \\RuntimeException('It failed.');", 'RuntimeException on line 100000: It failed.'],
        'an error of a wrong signature' => ['new \OrganiseYou\LlmGateway\Contract\Usage(inputTokens: 1, tokensOut: 2);', 'Error on line 100000: Unknown named parameter $tokensOut'],
        'a wrong enum case' => ['$a = \OrganiseYou\LlmGateway\Contract\FinishReason::Stopped;', 'Error on line 100000: Undefined constant'],
        'a class that does not exist' => ['use OrganiseYou\LlmGateway\Preset\NoSuchClass;' . "\n\n" . 'NoSuchClass::all();', 'Error on line 100002: Class "OrganiseYou\LlmGateway\Preset\NoSuchClass" not found'],
        'code that cannot be read' => ["echo 'a'\necho 'b';", 'ParseError on line 100001'],
        'a warning' => ['$a = [];' . "\n" . '$b = $a["none"];', 'a warning on line 100001: Undefined array key "none"'],
        'a deprecation' => ["trigger_error('old', E_USER_DEPRECATED);", 'a deprecation on line 100000: old'],
        'a notice' => ["trigger_error('look', E_USER_NOTICE);", 'a notice on line 100000: look'],
        'output shown in a place where it cannot be compared' => ["foreach ([1] as \$a) { echo \$a; }  // prints 1", 'line 100000 says "prints" in a comment the tests do not compare'],
        'output shown with a capital' => ["echo 'a';  // Prints b", 'line 100000 says "prints" in a comment the tests do not compare'],
        'output shown without a space' => ["echo 'a';  //prints b", 'line 100000 says "prints" in a comment the tests do not compare'],
        'output shown in another kind of comment' => ["echo 'a';  # prints b", 'line 100000 says "prints" in a comment the tests do not compare'],
        'output shown on a line of its own' => ["echo 'a';\n// prints b", 'line 100001 says "prints" in a comment the tests do not compare'],
    ]);

    it('leaves the error handler and the output buffers of the test runner as they were', function (): void {
        $buffers = ob_get_level();
        $handler = set_error_handler(null);
        restore_error_handler();

        ReadmeSamples::execute(sampleOfCode("ob_start();\necho 'left open';\nthrow new \\RuntimeException('no');"));

        $after = set_error_handler(null);
        restore_error_handler();

        expect(ob_get_level())->toBe($buffers)
            ->and($after)->toBe($handler);
    });

    it('gives a sample no variable of the test and keeps none of the sample', function (): void {
        $run = ReadmeSamples::execute(sampleOfCode("echo implode(',', array_keys(get_defined_vars()));\n\$kept = 1;"));
        $again = ReadmeSamples::execute(sampleOfCode('echo isset($kept) ? "kept" : "gone";'));

        expect($run->output)->toBe('')
            ->and($again->output)->toBe('gone');
    });
});
