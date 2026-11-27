<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Anthropic\Reading;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// The tolerant reading of a whole answer in the Messages adapter (docs/design/adapters.md, sections 4.1 and 5.2):
// the text of the text blocks, a tool call per tool_use block, the stop reason as a neutral finish reason, the usage
// with the cache counts. A field that is missing, null or of another type never ends in a PHP warning.

describe('the text and the tool calls of an answer', function (): void {
    it('joins the text of the text blocks, and reads thinking as no text', function (): void {
        $answer = Messages::answerTo(Messages::answered([
            Messages::thinking('Not for the caller.'),
            Messages::text('Hel'),
            ['type' => 'redacted_thinking', 'data' => 'cmVkYWN0ZWQ='],
            Messages::text('lo.'),
        ]));

        expect($answer->text)->toBe('Hello.')
            ->and($answer->toolCalls)->toBe([])
            ->and($answer->finishReason)->toBe(FinishReason::Stop)
            ->and($answer->model)->toBe('example-messages-1');
    });

    it('reads a tool call from a tool_use block: its id, its name and its input as an object', function (): void {
        $answer = Messages::answerTo(new HttpResponse(200, [], '{"content":[{"type":"text","text":"One moment."},{"type":"tool_use","id":"toolu_example01","name":"lookup","input":{"city": "Paris", "days": 2, "far": 1.0}}],"stop_reason":"tool_use"}'));

        expect($answer->text)->toBe('One moment.')
            ->and($answer->toolCalls)->toHaveCount(1)
            ->and($answer->toolCalls[0]->id)->toBe('toolu_example01')
            ->and($answer->toolCalls[0]->name)->toBe('lookup')
            ->and($answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris', 'days' => 2, 'far' => 1.0])
            // As the body wrote it, in one spelling: the number keeps its form.
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"city":"Paris","days":2,"far":1.0}')
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls);
    });

    it('reads every form an input can arrive in, and none of them is an exception', function (string $input, ?array $arguments, string $raw, ?string $error): void {
        $answer = Messages::answerTo(new HttpResponse(200, [], '{"content":[{"type":"tool_use","id":"toolu_example01","name":"lookup"' . ($input === '' ? '' : ',"input":' . $input) . '}],"stop_reason":"tool_use"}'));
        $call = $answer->toolCalls[0];

        expect($call->arguments)->toBe($arguments)
            ->and($call->rawArguments)->toBe($raw)
            ->and($call->argumentsError)->toBe($error)
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($answer->toMessage()->toolCalls)->toHaveCount(1);
    })->with([
        'an object' => ['{"city":"Paris"}', ['city' => 'Paris'], '{"city":"Paris"}', null],
        'an empty object' => ['{}', [], '{}', null],
        'an empty object with a space in it' => ['{ }', [], '{}', null],
        'no input at all' => ['', [], '{}', null],
        'null' => ['null', [], '{}', null],
        'nested objects and lists' => ['{"where":{"city":"Paris","tags":[]},"empty":{}}', ['where' => ['city' => 'Paris', 'tags' => []], 'empty' => []], '{"where":{"city":"Paris","tags":[]},"empty":{}}', null],
        'an empty list' => ['[]', null, '[]', 'the arguments are not a JSON object'],
        'a list' => ['["Paris"]', null, '["Paris"]', 'the arguments are not a JSON object'],
        'an object whose names are the numbers of a list' => ['{"0":"Paris","1":"Rome"}', null, '{"0":"Paris","1":"Rome"}', 'the names of the arguments are the numbers of a list'],
        'a string that holds an object' => ['"{\"city\":\"Paris\"}"', ['city' => 'Paris'], '{"city":"Paris"}', null],
        'a string that holds a cut-off object' => ['"{\"city\": \"Par"', null, '{"city": "Par', 'the arguments are not a JSON object'],
        'a string that holds a list' => ['"[1]"', null, '[1]', 'the arguments are not a JSON object'],
        'an empty string' => ['""', [], '{}', null],
        'a number' => ['7', null, '7', 'the arguments are not a JSON object'],
        'a boolean' => ['true', null, 'true', 'the arguments are not a JSON object'],
    ]);

    it('gives a tool call without an id an id no other call of the answer has', function (): void {
        $answer = Messages::answerTo(Messages::answered([
            ['type' => 'tool_use', 'name' => 'lookup', 'input' => ['city' => 'Paris']],
            Messages::call(['city' => 'Rome'], 'call_0'),
            ['type' => 'tool_use', 'id' => 7, 'name' => 'lookup', 'input' => ['city' => 'Oslo']],
            Messages::call(['city' => 'Bern'], 'call_2'),
        ], 'tool_use'));

        expect(array_map(fn($call): string => $call->id, $answer->toolCalls))->toBe(['call_0_2', 'call_0', 'call_2_2', 'call_2']);
    });

    it('reads past a block of a kind it does not know, a block that is no object and a member of the wrong type', function (): void {
        $answer = Messages::answerTo(Messages::answered([
            ['type' => 'server_tool_use', 'id' => 'srvtoolu_example01', 'name' => 'search', 'input' => ['q' => 'x']],
            'a bare string',
            7,
            null,
            ['text' => 'A block without a type.'],
            ['type' => 'text', 'text' => ['not', 'a', 'string']],
            ['type' => 'text'],
            ['type' => ['text'], 'text' => 'A type that is a list.'],
            Messages::text('Hello.'),
            ['type' => 'tool_use', 'id' => 'toolu_example01', 'name' => ['lookup'], 'input' => ['city' => 'Paris']],
        ], 'end_turn', ['unknown_field' => ['x' => 1], 'stop_details' => 'a string', 'container' => null]));

        expect($answer->text)->toBe('Hello.')
            ->and($answer->toolCalls)->toHaveCount(1)
            ->and($answer->toolCalls[0]->name)->toBe('')
            ->and($answer->opaque)->toBeNull();
    });

    it('names the model of the request when the answer names none', function (mixed $model): void {
        expect(Messages::answerTo(Messages::answered(more: ['model' => $model]))->model)->toBe(Messages::MODEL);
    })->with([null, '', 7, [['x']]]);
});

describe('the end of an answer', function (): void {
    it('maps every stop reason of the format', function (?string $stop, FinishReason $reason): void {
        expect(Messages::answerTo(Messages::answered(null, $stop))->finishReason)->toBe($reason);
    })->with([
        ['end_turn', FinishReason::Stop],
        ['stop_sequence', FinishReason::Stop],
        ['max_tokens', FinishReason::Length],
        ['model_context_window_exceeded', FinishReason::Length],
        ['refusal', FinishReason::Refusal],
        ['pause_turn', FinishReason::Other],
        ['a_word_of_a_later_version', FinishReason::Other],
        ['END_TURN', FinishReason::Other],
        [null, FinishReason::Other],
    ]);

    it('gives the word for tool use on an answer without a tool call as "other", not as tool calls', function (): void {
        $answer = Messages::answerTo(Messages::answered([Messages::text('Hello.')], 'tool_use'));

        expect($answer->finishReason)->toBe(FinishReason::Other)
            ->and($answer->toolCalls)->toBe([])
            ->and(Reading::finishReason('tool_use', false))->toBe(FinishReason::Other);
    });

    it('fails as provider_error for the word for tool use on an answer that holds nothing at all', function (): void {
        $error = Talk::errorOf(fn() => Messages::answerTo(Messages::answered([], 'tool_use')));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->detail)->toBe('the answer has no text and no tool call');
    });

    it('ends an answer that holds a tool call for tool calls whatever the stop reason, unless it was cut off', function (?string $stop, FinishReason $reason): void {
        expect(Messages::answerTo(Messages::answered([Messages::call()], $stop))->finishReason)->toBe($reason);
    })->with([
        ['tool_use', FinishReason::ToolCalls],
        ['end_turn', FinishReason::ToolCalls],
        ['pause_turn', FinishReason::ToolCalls],
        ['refusal', FinishReason::ToolCalls],
        [null, FinishReason::ToolCalls],
        ['max_tokens', FinishReason::Length],
        ['model_context_window_exceeded', FinishReason::Length],
    ]);

    it('returns a refusal without text as an answer with the finish reason "refusal", not as an error', function (array $content): void {
        $answer = Messages::answerTo(Messages::answered(array_values($content), 'refusal', ['stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'Words of the provider that may change.']]));

        expect($answer->finishReason)->toBe(FinishReason::Refusal)
            ->and($answer->text)->toBe('')
            ->and($answer->toolCalls)->toBe([])
            ->and($answer->usage)->toEqual(new Usage(3, 2));
    })->with([
        'no content block' => [[]],
        'an empty text block' => [[['type' => 'text', 'text' => '']]],
    ]);

    it('returns a refusal with text with that text', function (): void {
        $answer = Messages::answerTo(Messages::answered([Messages::text('I cannot help with that.')], 'refusal', ['stop_details' => ['type' => 'refusal', 'category' => null, 'explanation' => null]]));

        expect($answer->finishReason)->toBe(FinishReason::Refusal)
            ->and($answer->text)->toBe('I cannot help with that.');
    });

    it('returns an answer that was cut off before any output as an answer', function (): void {
        $answer = Messages::answerTo(Messages::answered([], 'max_tokens'));

        expect($answer->finishReason)->toBe(FinishReason::Length)
            ->and($answer->text)->toBe('');
    });
});

describe('the usage of an answer', function (): void {
    it('counts the input as the input tokens plus the two cache counts, and the output as it is', function (mixed $usage, ?int $input, ?int $output): void {
        expect(Messages::answerTo(Messages::answered(more: ['usage' => $usage]))->usage)->toEqual(new Usage($input, $output));
    })->with([
        'the two counts' => [['input_tokens' => 40, 'output_tokens' => 25], 40, 25],
        'with both cache counts' => [['input_tokens' => 5, 'cache_creation_input_tokens' => 100, 'cache_read_input_tokens' => 1000, 'output_tokens' => 25], 1105, 25],
        'with cache counts that are null' => [['input_tokens' => 5, 'cache_creation_input_tokens' => null, 'cache_read_input_tokens' => null, 'output_tokens' => 25], 5, 25],
        'with every other documented field' => [['input_tokens' => 5, 'cache_creation' => ['ephemeral_5m_input_tokens' => 3], 'output_tokens' => 25, 'output_tokens_details' => ['thinking_tokens' => 20], 'server_tool_use' => null, 'service_tier' => 'standard', 'inference_geo' => 'global'], 5, 25],
        'cache counts without the input tokens: the input is not known' => [['cache_read_input_tokens' => 9, 'output_tokens' => 25], null, 25],
        'only the input' => [['input_tokens' => 40], 40, null],
        'counts of 0' => [['input_tokens' => 0, 'output_tokens' => 0], 0, 0],
        'counts as text' => [['input_tokens' => '40', 'output_tokens' => '25'], null, null],
        'counts with a fraction' => [['input_tokens' => 40.5, 'output_tokens' => 25.5], null, null],
        'counts below 0' => [['input_tokens' => -1, 'output_tokens' => -2], null, null],
        'the largest whole number, alone' => [['input_tokens' => PHP_INT_MAX, 'output_tokens' => PHP_INT_MAX], PHP_INT_MAX, PHP_INT_MAX],
        'a sum past the largest whole number: the input is not known' => [['input_tokens' => PHP_INT_MAX, 'cache_read_input_tokens' => 1, 'output_tokens' => 2], null, 2],
        'a sum of three counts past the largest whole number' => [['input_tokens' => PHP_INT_MAX - 1, 'cache_creation_input_tokens' => 1, 'cache_read_input_tokens' => 1, 'output_tokens' => 2], null, 2],
        'a sum that is exactly the largest whole number' => [['input_tokens' => PHP_INT_MAX - 2, 'cache_creation_input_tokens' => 1, 'cache_read_input_tokens' => 1, 'output_tokens' => 2], PHP_INT_MAX, 2],
        'a count too large for a whole number, which arrives as a float' => [['input_tokens' => 1.0e30, 'output_tokens' => 2], null, 2],
        'no usage' => [null, null, null],
        'a usage that is a word' => ['none', null, null],
        'an empty usage' => [[], null, null],
    ]);
});

describe('a body that is no answer', function (): void {
    it('fails as provider_error with a detail of the library, never a PHP warning', function (string $body, string $detail): void {
        $error = Talk::errorOf(fn() => Messages::answerTo(new HttpResponse(200, [], $body)));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->detail)->toBe($detail)
            ->and($error->httpStatus)->toBe(200)
            ->and($error->providerCode)->toBeNull();
    })->with([
        'an empty body' => ['', 'the body is not a JSON object'],
        'text that is no JSON' => ['<html>busy</html>', 'the body is not a JSON object'],
        'a JSON string' => ['"Hello."', 'the body is not a JSON object'],
        'a JSON list' => ['[{"type":"text","text":"Hello."}]', 'the body is not a JSON object'],
        'an empty object' => ['{}', 'the body is not a JSON object'],
        'an object without content' => ['{"id":"msg_example01","stop_reason":"end_turn"}', 'the response holds no answer'],
        'a content that is a string' => ['{"content":"Hello.","stop_reason":"end_turn"}', 'the response holds no answer'],
        'a content that is null' => ['{"content":null,"stop_reason":"end_turn"}', 'the response holds no answer'],
        'a content without text and without a call' => ['{"content":[{"type":"thinking","thinking":"Hm.","signature":"c2ln"}],"stop_reason":"end_turn"}', 'the answer has no text and no tool call'],
    ]);

    it('reads an error body under a successful status as provider_error with the code of the provider', function (): void {
        $error = Talk::errorOf(fn() => Messages::answerTo(new HttpResponse(200, [], Messages::errorBody('overloaded_error'))));
        $withCode = Talk::errorOf(fn() => Messages::answerTo(new HttpResponse(200, [], Messages::errorBody('rate_limit_error', 'ramp_limit_exceeded'))));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->detail)->toBe('the response holds an error')
            ->and($error->providerCode)->toBe('overloaded_error')
            ->and($error->attempts)->toBe(1)
            ->and($withCode->providerCode)->toBe('ramp_limit_exceeded');
    });

    it('returns an answer that has an error member beside its content', function (): void {
        $answer = Messages::answerTo(Messages::answered(more: ['error' => ['type' => 'api_error', 'message' => 'No.']]));

        expect($answer->text)->toBe('Hello.');
    });

    it('reads a content that is an object and not a list, without a warning', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], '{"content":{"a":{"type":"text","text":"Hello."},"b":{"type":"tool_use","id":"toolu_example01","name":"lookup","input":{"city":"Paris"}}},"stop_reason":"tool_use"}'));
        $answer = Messages::gateway($transport)->complete(Messages::request());

        expect($answer->text)->toBe('Hello.')
            ->and($answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris'])
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"city":"Paris"}');
    });
});

describe('a whole answer of very many tool calls', function (): void {
    // The ids that are taken are kept as a set. No bound on time here: the test says the answer is right.
    it('gives every one of 30,000 calls its own id, in order, also the calls without an id', function (): void {
        $blocks = [];
        for ($at = 0; $at < 30_000; $at++) {
            // Every other call has no id, and the call after it has the id the adapter would have given it.
            $blocks[] = $at % 2 === 0
                ? ['type' => 'tool_use', 'name' => 'lookup', 'input' => ['n' => $at]]
                : Messages::call(['n' => $at], 'call_' . ($at - 1));
        }
        $answer = Messages::answerTo(Messages::answered($blocks, 'tool_use'));
        $ids = array_map(fn($call): string => $call->id, $answer->toolCalls);

        expect($answer->toolCalls)->toHaveCount(30_000)
            ->and(count(array_unique($ids)))->toBe(30_000)
            ->and(array_slice($ids, 0, 4))->toBe(['call_0_2', 'call_0', 'call_2_2', 'call_2'])
            ->and($answer->toolCalls[29_999]->arguments)->toBe(['n' => 29_999])
            ->and($answer->toolCalls[29_999]->rawArguments)->toBe('{"n":29999}');
    });
});
