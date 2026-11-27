<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Cohere\Reading;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatV2;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// How the Chat v2 adapter reads a whole answer (docs/design/adapters.md, sections 4.3 and 5.2): the text of its
// text blocks, the tool calls beside the content, the finish reason, the usage, and everything it reads past.

describe('the text of an answer of the Chat v2 adapter', function (): void {
    it('is the text of the text blocks, joined, without the thinking and without the tool plan', function (): void {
        $answer = ChatV2::answerTo(ChatV2::answered(
            [ChatV2::thinking('The user greets me.'), ChatV2::text('Hel'), ChatV2::text('lo.')],
            [ChatV2::call()],
            'TOOL_CALL',
            'I will look up the city.',
        ));

        expect($answer->text)->toBe('Hello.')
            ->and($answer->toolCalls)->toHaveCount(1);
    });

    it('names the model of the request: the format names none in an answer', function (): void {
        expect(ChatV2::answerTo(ChatV2::answered(more: ['model' => 'another-model']))->model)->toBe(ChatV2::MODEL);
    });

    it('reads past a block of a kind it does not know, a block that is no object and a member of another type', function (): void {
        $answer = ChatV2::answerTo(ChatV2::answered([
            'a bare string', 7, null, ['type' => 'image', 'text' => 'not text'], ['type' => 'text', 'text' => ['a' => 1]], ['text' => 'no type'],
            ['type' => 'text'], ChatV2::text('Hello.'), ['type' => 'text', 'text' => '', 'citations' => [['start' => 0]]],
        ], more: ['meta' => ['api_version' => ['version' => '2'], 'warnings' => ['x']], 'logprobs' => [], 'unknown' => ['deep' => [1, 2]]]));

        expect($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop)
            ->and($answer->opaque)->toBeNull();
    });

    it('reads a content that is a string, which the format documents for a request only, as the text', function (): void {
        expect(ChatV2::answerTo(ChatV2::answered('Hello.'))->text)->toBe('Hello.');
    });
});

describe('the tool calls of an answer of the Chat v2 adapter', function (): void {
    it('reads the calls beside the content, each with its id, its name and its arguments from the string that holds them', function (): void {
        $answer = ChatV2::answerTo(ChatV2::answered(null, [ChatV2::call('{"city":"Paris"}', 'lookup_a'), ChatV2::call('{ "city" : "Rome", "n": 1.0 }', 'lookup_b', 'book')]));

        expect($answer->text)->toBe('')
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($answer->toolCalls[0])->toEqual(ToolCall::decoded('lookup_a', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'))
            // The text of the arguments is kept as it arrived.
            ->and($answer->toolCalls[1])->toEqual(ToolCall::decoded('lookup_b', 'book', ['city' => 'Rome', 'n' => 1.0], '{ "city" : "Rome", "n": 1.0 }'))
            ->and($answer->opaque)->toBeNull();
    });

    it('returns a call whose arguments hold no JSON object as a call that cannot be read, never as an error', function (mixed $arguments, string $raw): void {
        $call = ChatV2::answerTo(ChatV2::answered(null, [ChatV2::call($arguments)]))->toolCalls[0];

        expect($call->isReadable())->toBeFalse()
            ->and($call->argumentsError)->toBe('the arguments are not a JSON object')
            ->and($call->rawArguments)->toBe($raw);
    })->with([
        'cut off' => ['{"city": "Par', '{"city": "Par'],
        'a list' => ['["Paris"]', '["Paris"]'],
        'a number' => ['7', '7'],
        'a word' => ['Paris', 'Paris'],
        'an empty string' => ['', ''],
        'no arguments at all' => [null, ''],
        'a decoded list' => [['Paris'], '["Paris"]'],
        'a decoded number' => [7, '7'],
    ]);

    it('reads arguments that arrive as an object, which no page documents, and an object with numbered names as not readable', function (): void {
        $answer = ChatV2::answerTo(new HttpResponse(200, [], '{"finish_reason":"TOOL_CALL","message":{"role":"assistant","tool_calls":['
            . '{"id":"a","type":"function","function":{"name":"lookup","arguments":{"city":"Paris"}}},'
            . '{"id":"b","type":"function","function":{"name":"lookup","arguments":{}}},'
            . '{"id":"c","type":"function","function":{"name":"lookup","arguments":"{\"0\":\"x\",\"1\":\"y\"}"}}]}}'));

        expect($answer->toolCalls[0])->toEqual(ToolCall::decoded('a', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'))
            ->and($answer->toolCalls[1])->toEqual(ToolCall::decoded('b', 'lookup', [], '{}'))
            ->and($answer->toolCalls[2]->argumentsError)->toBe('the names of the arguments are the numbers of a list');
    });

    it('gives a call without an id the id of its place among the calls, and one no other call of the answer has', function (): void {
        $answer = ChatV2::answerTo(ChatV2::answered(null, [
            ChatV2::call('{}', null), ChatV2::call('{}', 'call_0'), ChatV2::call('{}', 'call_0_2'), ChatV2::call('{}', ''), ChatV2::call('{}', null),
        ]));

        expect(array_map(fn(ToolCall $call): string => $call->id, $answer->toolCalls))->toBe(['call_0_3', 'call_0', 'call_0_2', 'call_3', 'call_4']);
    });

    it('keeps the ids of very many calls apart, as a set of the ids that are taken', function (): void {
        $calls = [];
        for ($at = 0; $at < 3000; $at++) {
            $calls[] = ChatV2::call('{}', $at % 2 === 0 ? null : 'call_' . ($at - 1));
        }
        $ids = array_map(fn(ToolCall $call): string => $call->id, ChatV2::answerTo(ChatV2::answered(null, $calls))->toolCalls);

        expect($ids)->toHaveCount(3000)
            ->and(array_unique($ids))->toHaveCount(3000)
            ->and($ids[0])->toBe('call_0_2')
            ->and($ids[1])->toBe('call_0');
    });

    it('fails an answer with a call that names no tool, whole or as a stream: a call without a name is not a call of a tool named ""', function (array $function): void {
        $call = ['id' => 'lookup_a', 'type' => 'function', 'function' => $function];
        $whole = Talk::errorOf(fn() => ChatV2::answerTo(ChatV2::answered(null, [ChatV2::call(), $call])));
        $streamed = ChatV2::eventsOf([ChatV2::started(), ...ChatV2::calling(), ChatV2::event('tool-call-start', ['index' => 1, 'delta' => ['message' => ['tool_calls' => $call]]]), ChatV2::ended('TOOL_CALL')]);

        expect($whole->reason)->toBe(ErrorReason::ProviderError)
            ->and($whole->detail)->toBe('a tool call of the answer names no tool')
            ->and($whole->attempts)->toBe(1)
            ->and(Talk::failedIn($streamed)->error->detail)->toBe('a tool call of the answer names no tool')
            ->and(Talk::failedIn($streamed)->toolCallsSoFar)->toHaveCount(1);
    })->with([
        'no name' => [['arguments' => '{}']],
        'an empty name' => [['name' => '', 'arguments' => '{}']],
        'a name of another type' => [['name' => ['lookup'], 'arguments' => '{}']],
        'no function' => [[]],
    ]);

    it('reads past an entry of the calls that is no object, and calls that are neither a list nor an object, the same whole and as a stream', function (array $entries): void {
        $whole = ChatV2::answerTo(ChatV2::answered(null, [...array_values($entries), ChatV2::call()]));
        $streamed = Talk::finishedIn(ChatV2::eventsOf([
            ChatV2::started(),
            ...array_map(fn(mixed $entry): string => ChatV2::event('tool-call-start', ['index' => 9, 'delta' => ['message' => ['tool_calls' => [$entry]]]]), array_values($entries)),
            ...ChatV2::calling(),
            ChatV2::ended('TOOL_CALL'),
        ]))->answer;

        expect($whole->toolCalls)->toEqual([ToolCall::decoded('lookup_example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')])
            ->and($streamed->toolCalls)->toEqual($whole->toolCalls)
            ->and(ChatV2::answerTo(ChatV2::answered(more: ['message' => ['role' => 'assistant', 'content' => [ChatV2::text()], 'tool_calls' => 'none']]))->toolCalls)->toBe([]);
    })->with([
        'a word, a number and null' => [['lookup', 7, null]],
        'a list' => [[['a']]],
        'a list that holds a call' => [[[ChatV2::call('{}', 'lookup_inner')]]],
        'an empty value' => [[[]]],
    ]);

    it('reads calls that are one object instead of a list as one call, the same whole and as a stream', function (): void {
        $body = ChatV2::body(null, [ChatV2::call()]);
        $body['message']['tool_calls'] = ChatV2::call();
        $whole = ChatV2::answerTo(new HttpResponse(200, [], ChatV2::json($body)));
        $streamed = Talk::finishedIn(ChatV2::eventsOf([
            ChatV2::started(),
            ChatV2::event('tool-call-start', ['index' => 0, 'delta' => ['message' => ['tool_calls' => [ChatV2::call()]]]]),
            ChatV2::callEnd(),
            ChatV2::ended('TOOL_CALL'),
        ]))->answer;

        expect($whole->toolCalls)->toEqual([ToolCall::decoded('lookup_example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')])
            ->and($streamed->toolCalls)->toEqual($whole->toolCalls);
    });
});

describe('the finish reason of an answer of the Chat v2 adapter', function (): void {
    it('reads every word the format documents for an answer', function (string $word, FinishReason $reason): void {
        expect(ChatV2::answerTo(ChatV2::answered(finish: $word))->finishReason)->toBe($reason);
    })->with([
        ['COMPLETE', FinishReason::Stop],
        ['STOP_SEQUENCE', FinishReason::Stop],
        ['MAX_TOKENS', FinishReason::Length],
    ]);

    it('reads no finish reason, one of another type and a word it does not know as "other"', function (mixed $finish): void {
        expect(ChatV2::answerTo(ChatV2::answered(more: ['finish_reason' => $finish]))->finishReason)->toBe(FinishReason::Other);
    })->with([[null], [7], [['COMPLETE']], ['complete'], ['CONTENT_BLOCKED'], ['']]);

    it('ends an answer that holds a tool call for tool calls whatever word came with it, unless it was cut off', function (?string $word, FinishReason $reason): void {
        expect(ChatV2::answerTo(ChatV2::answered(null, [ChatV2::call()], $word))->finishReason)->toBe($reason);
    })->with([
        ['TOOL_CALL', FinishReason::ToolCalls],
        ['COMPLETE', FinishReason::ToolCalls],
        ['SOMETHING_NEW', FinishReason::ToolCalls],
        [null, FinishReason::ToolCalls],
        ['MAX_TOKENS', FinishReason::Length],
    ]);

    it('gives the word for tool calls on an answer without a call as "other", never as tool calls with an empty list', function (): void {
        $answer = ChatV2::answerTo(ChatV2::answered(finish: 'TOOL_CALL'));
        $streamed = Talk::finishedIn(ChatV2::eventsOf([ChatV2::started(), ChatV2::blockStart(), ChatV2::piece('Hello.'), ChatV2::blockEnd(), ChatV2::ended('TOOL_CALL')]))->answer;

        expect($answer->finishReason)->toBe(FinishReason::Other)
            ->and($answer->toolCalls)->toBe([])
            ->and($streamed->finishReason)->toBe(FinishReason::Other);
    });

    it('throws a finish reason that says the generation failed as an error of the provider, with the word in lower case as its code, and does not try again', function (string $word, string $code): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered(finish: $word))->willReturn(ChatV2::answered());
        $error = Talk::errorOf(fn() => ChatV2::gateway($transport, retry: new RetryPolicy([1.0], 60.0))->complete(ChatV2::request()));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->providerCode)->toBe($code)
            ->and($error->detail)->toBe('the provider says it could not make the answer')
            ->and($error->httpStatus)->toBe(200)
            ->and($error->attempts)->toBe(1)
            ->and($transport->requests())->toHaveCount(1);
    })->with([['ERROR', 'error'], ['TIMEOUT', 'timeout']]);

    it('has no word and no field for an answer the model declined to give or the provider withheld: declining is ordinary text, and an answer without text is one only when it was cut off', function (): void {
        $declined = ChatV2::answerTo(ChatV2::answered([ChatV2::text('I am sorry, but I cannot help with that.')]));
        $cutOff = ChatV2::answerTo(ChatV2::answered([], finish: 'MAX_TOKENS'));
        $nothing = Talk::errorOf(fn() => ChatV2::answerTo(ChatV2::answered([])));
        $nothingKnown = Talk::errorOf(fn() => ChatV2::answerTo(ChatV2::answered([ChatV2::text('')], finish: 'REFUSAL', more: ['refusal' => 'No.'])));

        expect($declined->finishReason)->toBe(FinishReason::Stop)
            ->and($declined->text)->toBe('I am sorry, but I cannot help with that.')
            ->and($cutOff->finishReason)->toBe(FinishReason::Length)
            ->and($cutOff->text)->toBe('')
            ->and($nothing->reason)->toBe(ErrorReason::ProviderError)
            ->and($nothingKnown->reason)->toBe(ErrorReason::ProviderError);
    });
});

describe('the usage of an answer of the Chat v2 adapter', function (): void {
    it('reads the tokens the model counted, not the billing figures beside them', function (): void {
        $answer = ChatV2::answerTo(ChatV2::answered(more: ['usage' => ['billed_units' => ['input_tokens' => 5, 'output_tokens' => 9, 'search_units' => 1], 'tokens' => ['input_tokens' => 71, 'output_tokens' => 12], 'cached_tokens' => 40]]));

        expect($answer->usage->inputTokens)->toBe(71)
            ->and($answer->usage->outputTokens)->toBe(12);
    });

    it('reads a count the format writes as a double, such as 12.0, as the whole number 12', function (): void {
        $answer = ChatV2::answerTo(new HttpResponse(200, [], '{"finish_reason":"COMPLETE","message":{"role":"assistant","content":[{"type":"text","text":"Hello."}]},"usage":{"tokens":{"input_tokens":12.0,"output_tokens":3.0e1}}}'));

        expect($answer->usage->inputTokens)->toBe(12)
            ->and($answer->usage->outputTokens)->toBe(30);
    });

    it('does not read a count that is no whole number of 0 or more: each is then not known, and the other count stands', function (string $written): void {
        $answer = ChatV2::answerTo(new HttpResponse(200, [], '{"finish_reason":"COMPLETE","message":{"role":"assistant","content":[{"type":"text","text":"Hello."}]},"usage":{"tokens":{"input_tokens":' . $written . ',"output_tokens":2}}}'));

        expect($answer->usage->inputTokens)->toBeNull()
            ->and($answer->usage->outputTokens)->toBe(2);
    })->with([
        'a fraction' => ['12.5'],
        'a negative number' => ['-1'],
        'a negative double' => ['-12.0'],
        'a double too large for a whole number' => ['1.0e19'],
        'a whole number too large for PHP' => ['92233720368547758070'],
        'a number beyond every double' => ['1e400'],
        'a number as text' => ['"12"'],
        'a word' => ['"many"'],
        'null' => ['null'],
        'true' => ['true'],
        'a list' => ['[12]'],
        'an object' => ['{"value":12}'],
    ]);

    it('reads the largest counts as they are, and adds nothing up: the format gives each count as one number', function (): void {
        $answer = ChatV2::answerTo(new HttpResponse(200, [], '{"finish_reason":"COMPLETE","message":{"role":"assistant","content":[{"type":"text","text":"Hello."}]},"usage":{"tokens":{"input_tokens":' . PHP_INT_MAX . ',"output_tokens":' . PHP_INT_MAX . '},"cached_tokens":' . PHP_INT_MAX . '}}'));

        expect($answer->usage->inputTokens)->toBe(PHP_INT_MAX)
            ->and($answer->usage->outputTokens)->toBe(PHP_INT_MAX)
            ->and(Reading::count(9.0e18))->toBe(9000000000000000000)
            ->and(Reading::count(9.3e18))->toBeNull()
            ->and(Reading::count(0.0))->toBe(0)
            ->and(Reading::count(-0.0))->toBe(0)
            ->and(Reading::count(NAN))->toBeNull()
            ->and(Reading::count(INF))->toBeNull();
    });

    it('reports no usage for an answer that tells none, or tells it where only one page of the provider shows it', function (mixed $usage, array $more): void {
        $answer = ChatV2::answerTo(ChatV2::answered(more: ['usage' => $usage, ...$more]));

        expect($answer->usage->inputTokens)->toBeNull()
            ->and($answer->usage->outputTokens)->toBeNull();
    })->with([
        'null' => [null, []],
        'a word' => ['none', []],
        'a list' => [[1, 2], []],
        'no tokens' => [['billed_units' => ['input_tokens' => 3, 'output_tokens' => 2]], []],
        'tokens of another type' => [['tokens' => 5], []],
        'under meta, as the guide shows it' => [null, ['meta' => ['tokens' => ['input_tokens' => 3, 'output_tokens' => 2]]]],
    ]);
});

describe('a body the Chat v2 adapter does not expect', function (): void {
    it('ends as an error of the provider, never as a warning or a type error', function (string $body, string $detail): void {
        $error = Talk::errorOf(fn() => ChatV2::answerTo(new HttpResponse(200, [], $body)));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->detail)->toBe($detail)
            ->and($error->providerCode)->toBeNull()
            ->and($error->attempts)->toBe(1);
    })->with([
        'an empty body' => ['', 'the body is not a JSON object'],
        'no JSON' => ['<html>Hello.</html>', 'the body is not a JSON object'],
        'a list' => ['[{"message":{"content":[{"type":"text","text":"Hello."}]}}]', 'the body is not a JSON object'],
        'an empty list' => ['[]', 'the body is not a JSON object'],
        'a number' => ['7', 'the body is not a JSON object'],
        'a string' => ['"Hello."', 'the body is not a JSON object'],
        'an empty object' => ['{}', 'the response holds no answer'],
        'no message' => ['{"id":"synthetic-answer-1","finish_reason":"COMPLETE"}', 'the response holds no answer'],
        'a message that is null' => ['{"finish_reason":"COMPLETE","message":null}', 'the response holds no answer'],
        'a message that is a list' => ['{"finish_reason":"COMPLETE","message":[{"content":"Hello."}]}', 'the response holds no answer'],
        'a message that is a number' => ['{"finish_reason":"COMPLETE","message":7}', 'the response holds no answer'],
        'the error body of the format' => ['{"id":"synthetic-error-1","message":"invalid request"}', 'the response holds an error'],
    ]);

    it('reads a message whose content or tool calls are of another type as an answer without them', function (string $message): void {
        $error = Talk::errorOf(fn() => ChatV2::answerTo(new HttpResponse(200, [], '{"finish_reason":"COMPLETE","message":' . $message . '}')));
        $cutOff = ChatV2::answerTo(new HttpResponse(200, [], '{"finish_reason":"MAX_TOKENS","message":' . $message . '}'));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($cutOff->text)->toBe('')
            ->and($cutOff->toolCalls)->toBe([]);
    })->with([
        ['{}'],
        ['{"role":"assistant"}'],
        ['{"content":7,"tool_calls":7,"tool_plan":7}'],
        ['{"content":{"type":"text","text":"Hello."},"tool_calls":"a"}'],
        ['{"content":null,"tool_calls":null,"tool_plan":null}'],
        ['{"content":[[{"type":"text","text":"Hello."}]]}'],
    ]);
});
