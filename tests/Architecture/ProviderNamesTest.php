<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Tests\Support\SourceCode;

// LR-04 and the project guide: no provider name outside its adapter and its preset. A caller asks capabilities,
// never who answers. The names are those of the fifteen providers of decision G3 and of their vendors and products.
// This file is the one place in the code where they are listed.

/**
 * The names, each with how it is looked for.
 *
 * - "anywhere": the letters in any case, also inside a longer name (`OpenAiCompatibleAdapter`, `'mistral-eu'`).
 *   For names that are part of no ordinary word.
 * - "word": the name as a word or as a hump of a name: `cohere`, `Cohere`, `COHERE`, `CohereAdapter`,
 *   `$cohere_key`, but not `coherent`, `entrance` or `Claudette`. For short names that longer words contain. Known
 *   false alarm, kept because the rule is worth more: the plain verbs "cohere" and "grok" are found too; write
 *   another word.
 * - "phrase": a name of two words, in any case, written together or with a space, an underscore, a hyphen or a
 *   dot between them (`OpenAi`, `open_ai`, "Open AI", `x.ai`), and not followed by a small letter, so "open air"
 *   is not found.
 * - "ordinary": a name that is also a plain English word. In code and in strings it is looked for as a "word",
 *   because there it can only be meant as the product (a preset id, a class). In a comment only its capitalised
 *   form counts: prose may speak of a paradigm, it may not speak of Paradigm.
 *
 * The products are those that G3 and docs/providers/overview.md name: the chat products, and the text models of
 * the providers without a hosted API (Poro, Viking, Helium). Left out on purpose: names of model families that are
 * everyday words and that neither of the two lists ("command", "large"), and "silo" and "studio" alone; their
 * vendors are caught by the names below. When task LG-10 writes a file per provider, the names it finds belong
 * here.
 *
 * @return array<string, array{string, list<string>}> provider of G3 => [how, the names]
 */
function providerNames(): array
{
    return [
        'OpenAI Platform (ChatGPT)' => ['anywhere', ['chatgpt']],
        'OpenAI Platform, the vendor' => ['phrase', ['open ai']],
        'OpenAI Platform, its model family' => ['word', ['gpt']],
        'Claude (Anthropic), the vendor' => ['anywhere', ['anthropic']],
        'Claude (Anthropic)' => ['word', ['claude']],
        'Gemini (Google Cloud / Vertex AI)' => ['anywhere', ['gemini', 'google', 'vertexai']],
        'Gemini, the cloud product' => ['word', ['vertex']],
        'Microsoft Copilot / Azure OpenAI' => ['anywhere', ['microsoft', 'copilot', 'azure']],
        'Microsoft, its identity service' => ['word', ['entra']],
        'Cohere' => ['word', ['cohere']],
        'Aleph Alpha (PhariaAI)' => ['phrase', ['aleph alpha']],
        'Aleph Alpha, its product' => ['anywhere', ['pharia']],
        'Silo AI (AMD)' => ['phrase', ['silo ai']],
        'Silo AI, its text models' => ['word', ['poro']],
        'Silo AI, a text model that is also a plain word' => ['ordinary', ['viking']],
        'Silo AI, the vendor' => ['word', ['amd']],
        'LightOn (Paradigm)' => ['word', ['LightOn', 'lightOn', 'light_on', 'light-on']],
        'LightOn, its product' => ['ordinary', ['paradigm']],
        'Pleias' => ['anywhere', ['pleias']],
        'Mistral' => ['anywhere', ['mistral']],
        'Kyutai' => ['anywhere', ['kyutai']],
        'Kyutai, its text model, also a plain word' => ['ordinary', ['helium']],
        'STACKIT' => ['anywhere', ['stackit']],
        'Grok' => ['word', ['grok']],
        'Grok, the vendor' => ['phrase', ['x ai']],
        'Kimi' => ['word', ['kimi']],
        'Kimi, the vendor' => ['anywhere', ['moonshot']],
        'LM Studio' => ['phrase', ['lm studio']],
    ];
}

/**
 * The pattern that finds a name as a word or as a hump of a longer name, in its three spellings.
 */
function providerWordPattern(string $name, bool $capitalisedOnly = false): string
{
    $lower = strtolower($name);

    $spellings = [];
    foreach (array_unique([$name, ucfirst($lower), strtoupper($name), $lower]) as $spelling) {
        if ($capitalisedOnly && $spelling === $lower) {
            continue;
        }
        $spellings[] = match (true) {
            // All capitals: a word of its own, as in a constant.
            $spelling === strtoupper($name) => '(?<![A-Za-z])' . $spelling . '(?![A-Za-z])',
            // A capital first: after a small letter it is a hump of a longer name.
            $spelling[0] !== $lower[0] => '(?<![A-Z])' . $spelling . '(?![a-z])',
            default => '(?<![A-Za-z])' . $spelling . '(?![a-z])',
        };
    }

    return '/' . implode('|', $spellings) . '/';
}

/**
 * The pattern that finds a name of two words, in any case, whatever joins them.
 */
function providerPhrasePattern(string $name): string
{
    [$first, $second] = explode(' ', $name);
    // A name of one letter and a word ("x ai") is a word of its own: no letter before it. A longer one may be
    // the hump of a longer name.
    $before = strlen($first) === 1 ? '(?<![A-Za-z])' : '';

    return '/' . $before . '(?i:' . $first . ')[\s_.-]?(?i:' . $second . ')(?![a-z])/';
}

/**
 * The provider names in a piece of PHP code: in its names, its strings and its comments.
 *
 * @return list<string> one line per finding: the line in the code and the name as it was written
 */
function providerNamesIn(string $code): array
{
    $parts = SourceCode::codeAndComments($code);

    $found = [];
    foreach (providerNames() as [$how, $names]) {
        foreach ($names as $name) {
            foreach ($parts as $part => $text) {
                $pattern = match ($how) {
                    'anywhere' => '/' . $name . '/i',
                    'phrase' => providerPhrasePattern($name),
                    'word' => providerWordPattern($name),
                    default => providerWordPattern($name, $part === 'comments'),
                };
                if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                    throw new LogicException('The pattern for ' . $name . ' is not valid.');
                }
                foreach ($matches[0] as [$written, $at]) {
                    $line = substr_count($text, "\n", 0, $at) + 1;
                    $found[sprintf('%06d %s', $line, $written)] = 'line ' . $line . ': ' . $written;
                }
            }
        }
    }
    ksort($found);

    return array_values($found);
}

/**
 * The files of the library in which no provider may be named, of any kind (a JSON or a text file counts as much as
 * a PHP file): all of src, except the adapter of a format
 * (src/Adapter/<Format>, but not src/Adapter/Shared) and the preset data (src/Preset, but not its three value
 * classes). So src/Contract, src/Loop, src/Testing, src/Transport, src/Adapter/Shared and the files directly under
 * src are in it, and so is a folder that is added later.
 *
 * @return list<string> paths under src
 */
function filesWithoutProviderNames(string $src): array
{
    $neutral = [];
    foreach (SourceCode::filesUnder($src) as $path) {
        $relative = substr($path, strlen($src) + 1);
        $ofOneFormat = str_starts_with($relative, 'Adapter/') && ! str_starts_with($relative, 'Adapter/Shared/');
        $presetData = str_starts_with($relative, 'Preset/')
            && ! in_array($relative, ['Preset/Preset.php', 'Preset/PresetField.php', 'Preset/BaseUrlRule.php'], true);
        if (! $ofOneFormat && ! $presetData) {
            $neutral[] = $relative;
        }
    }

    return $neutral;
}

it('names no provider in the contract, the loop, the testing tools, the transport, the shared adapter code, the preset values and the root files', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $files = filesWithoutProviderNames($src);

    foreach (['Contract/', 'Testing/', 'Transport/', 'Adapter/Shared/', 'Preset/Preset.php', 'GatewayFactory.php'] as $expected) {
        expect(array_filter($files, fn(string $file): bool => str_starts_with($file, $expected)))
            ->not->toBe([], 'the rule must have found the files of ' . $expected);
    }

    $findings = [];
    foreach ($files as $file) {
        // The path counts too: a file of the contract may not be named after a provider.
        foreach ([...providerNamesIn('<?php // ' . $file), ...providerNamesIn((string) file_get_contents($src . '/' . $file))] as $finding) {
            $findings[] = $file . ' ' . $finding;
        }
    }

    expect($findings)->toBe([]);
});

it('leaves the adapter of one format and the preset data free to name their provider, and nothing else', function (): void {
    $src = dirname(__DIR__, 2) . '/src';
    $all = array_map(fn(string $path): string => substr($path, strlen($src) + 1), SourceCode::filesUnder($src));
    $free = array_values(array_diff($all, filesWithoutProviderNames($src)));

    foreach ($free as $file) {
        expect(preg_match('/^(Adapter\/(?!Shared\/)[A-Za-z0-9]+\/|Preset\/)/', $file))->toBe(1, $file . ' may name a provider and is no adapter of a format and no preset');
    }
    expect($free)->not->toContain('Preset/Preset.php');
});

it('fails on a sample class of the contract that names a provider', function (): void {
    $sample = (string) file_get_contents(dirname(__DIR__) . '/fixtures/architecture/ContractClassThatNamesAProvider.php.txt');

    expect($sample)->toContain('namespace OrganiseYou\LlmGateway\Contract;')
        ->and(providerNamesIn($sample))->toBe([
            'line 9: Mistral',
            'line 17: OpenAi',
            'line 17: lighton',
            'line 20: OpenAi',
        ]);
});

it('catches a provider name', function (string $code, string ...$written): void {
    expect(providerNamesIn("<?php\n" . $code))
        ->toBe(array_values(array_map(fn(string $name): string => 'line 2: ' . $name, $written)));
})->with([
    'in a class name' => ['final class MistralQuirks {}', 'Mistral'],
    'in the middle of a class name' => ['$a = new OpenAiCompatibleAdapter();', 'OpenAi'],
    'in a string, as a preset id' => ['if ($presetId === "anthropic") {}', 'anthropic'],
    'in a string, inside a longer id' => ["\$known = ['azure-openai'];", 'azure', 'openai'],
    'in a comment' => ['// Gemini wants the system text apart.', 'Gemini'],
    'in a doc comment' => ['/** The header of Cohere. */', 'Cohere'],
    'in a variable' => ['$claudeKey = 1;', 'claude'],
    'in a variable, as a hump' => ['$theClaudeKey = 1;', 'Claude'],
    'in a constant' => ['const GROK_URL = "";', 'GROK'],
    'in a method' => ['$a->forVertex();', 'Vertex'],
    'in a wire word' => ['$body = ["anthropic_version" => $v];', 'anthropic'],
    'as two words' => ['// For Aleph Alpha and for LM Studio.', 'Aleph Alpha', 'LM Studio'],
    'the first vendor, in every spelling' => ['$a = "open_ai"; $b = "open-ai"; $c = "openai"; // Open AI and OPENAI_KEY', 'OPENAI', 'Open AI', 'open-ai', 'open_ai', 'openai'],
    'a vendor of one letter and a word' => ['$a = new XAiPreset(); $b = "xAi"; $c = "api.x.ai"; $d = "x_ai"; $e = "xai-key";', 'XAi', 'x.ai', 'xAi', 'x_ai', 'xai'],
    'a name of two humps, in every spelling' => ['$a = "light_on"; $b = $lightOn; $c = new LightOnPreset(); $d = "lighton"; $e = LIGHTON;', 'LIGHTON', 'LightOn', 'lightOn', 'light_on', 'lighton'],
    'the text models of the providers without a hosted API' => ['$a = "poro-2"; $b = "viking-33b"; $c = new HeliumPreset(); // runs Viking and Helium 1', 'Helium', 'Viking', 'poro', 'viking'],
    'as two words in a name' => ['$a = new LmStudioPreset(); $b = "aleph_alpha"; $c = new SiloAiPreset();', 'LmStudio', 'SiloAi', 'aleph_alpha'],
    'a product name' => ['$a = "chatgpt"; $b = "copilot"; $c = "pharia-1"; $d = "kimi-k2"; $e = "moonshot-v1";', 'chatgpt', 'copilot', 'kimi', 'moonshot', 'pharia'],
    'a model family' => ['$model = "gpt-5"; $other = "GPT";', 'GPT', 'gpt'],
    'a vendor' => ['// Google, Microsoft, AMD and xAI', 'AMD', 'Google', 'Microsoft', 'xAI'],
    'an identity service' => ['// a token of Entra', 'Entra'],
    'the rest of the list' => ['$all = ["pleias", "kyutai", "stackit", "cohere", "grok"];', 'cohere', 'grok', 'kyutai', 'pleias', 'stackit'],
    'a name that is also a plain word, in code' => ['$id = "paradigm"; $a = new ParadigmPreset();', 'Paradigm', 'paradigm'],
    'a name that is also a plain word, capitalised in a comment' => ['// As Paradigm documents it.', 'Paradigm'],
]);

it('finds a provider name in a file that is no PHP', function (): void {
    expect(providerNamesIn('{"presets": [' . "\n" . '"mistral", "open_ai"]}'))->toBe(['line 2: mistral', 'line 2: open_ai']);
});

it('does not take a plain word for a provider name', function (string $code): void {
    expect(providerNamesIn("<?php\n" . $code))->toBe([]);
})->with([
    'a plain word in a comment' => ['// A new paradigm for errors: the parts are coherent.'],
    'words that hold a short name' => ['// The entrance of a silo, a studio, an incoherent command; $entrance = 1; $coherent = 2;'],
    'names of people and things' => ['$a = "Claudette"; $b = "kimiko"; $c = "grokking"; $d = "vertexes"; $e = "amdahl";'],
    'neutral words of the library' => ['final class Preset { public string $provider; public string $model; public string $keyHeader; }'],
    'two plain words' => ['// Shed light on it. $lightOnly = true; $silo = "aid"; $highlightOn = 1; // in the open air'],
    'plain words that are also the names of text models, in a comment' => ['// A balloon of helium; the viking ships.'],
    'letters that only look like the vendor of one letter' => ['$maxAi = 1; $taxAid = 2; // the index ai of the matrix ai'],
]);
