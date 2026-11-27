<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// How the chat-completions adapter reads a whole answer (docs/design/adapters.md, sections 2.1 and 5.2): tolerantly.
// A field that is missing, null or of another type ends in a typed error or is ignored, never in a warning.

/**
 * The answer to a request with the tool "lookup", given this message of the server.
 *
 * @param array<string, mixed> $message
 * @param array<string, bool|int|string> $options
 */
function answerWithTools(array $message, ?string $finish = 'tool_calls', array $options = []): Answer
{
    $request = new Request(ChatCompletions::MODEL, [Message::user('Where to?')], 30.0, [ChatCompletions::lookup()]);

    return ChatCompletions::answerTo(ChatCompletions::answered($message, $finish), $options, $request);
}

describe('the text', function (): void {
    it('is the content when that is a string', function (): void {
        $answer = ChatCompletions::answerTo(ChatCompletions::answered());

        expect($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop)
            ->and($answer->model)->toBe('example-chat-1-0001')
            ->and($answer->usage)->toEqual(new Usage(3, 2))
            ->and($answer->toolCalls)->toBe([])
            ->and($answer->opaque)->toBeNull()
            ->and($answer->structured)->toBeNull();
    });

    it('is the joined text entries when the content is a list, and no entry of another kind', function (): void {
        $content = [
            ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'The user greets.']]],
            ['type' => 'text', 'text' => 'Hel'],
            ['type' => 'reference', 'reference_ids' => [1]],
            ['type' => 'text', 'text' => 'lo.'],
            ['text' => ' Bye.'],
            ['type' => 'text', 'text' => 7],
            'a string that is no entry',
            null,
        ];

        expect(ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => $content]))->text)->toBe('Hello. Bye.');
    });

    it('keeps reasoning beside the message out of the text, under either of its two names', function (array $message): void {
        $answer = ChatCompletions::answerTo(ChatCompletions::answered($message));

        expect($answer->text)->toBe('Hello.')
            // Nothing asks it back on a server without a reasoning carrier: it is not kept.
            ->and($answer->opaque)->toBeNull();
    })->with([
        'reasoning_content' => [['role' => 'assistant', 'content' => 'Hello.', 'reasoning_content' => 'The user greets.']],
        'reasoning' => [['role' => 'assistant', 'content' => 'Hello.', 'reasoning' => 'The user greets.']],
    ]);

    it('is the model of the request when the answer names none', function (): void {
        $response = new HttpResponse(200, [], ChatCompletions::json(['choices' => [['message' => ['content' => 'Hello.']]]]));
        $answer = ChatCompletions::answerTo($response);

        expect($answer->text)->toBe('Hello.')
            ->and($answer->model)->toBe(ChatCompletions::MODEL)
            ->and($answer->finishReason)->toBe(FinishReason::Other)
            ->and($answer->usage)->toEqual(new Usage(null, null));
    });

    it('ignores fields it does not know', function (): void {
        $response = ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hello.', 'audio' => null, 'annotations' => []], 'stop', [
            'system_fingerprint' => 'fp_example',
            'service_tier' => 'default',
            'citations' => ['https://docs.example.test/a'],
            'stats' => ['tokens_per_second' => 31.5],
            'model_info' => ['context_length' => 4096],
            'a_field_of_next_year' => ['nested' => [1, 2, 3]],
        ]);

        expect(ChatCompletions::answerTo($response)->text)->toBe('Hello.');
    });
});

describe('a tool call', function (): void {
    it('has its arguments decoded from a string that holds a JSON object', function (): void {
        $answer = answerWithTools(ChatCompletions::calling('{"city":"Paris"}', '{ "city" : "Rome", "days": 2 }', '{}'));

        expect($answer->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($answer->text)->toBe('')
            ->and($answer->toolCalls)->toHaveCount(3)
            ->and($answer->toolCalls[0]->id)->toBe('call_1')
            ->and($answer->toolCalls[0]->name)->toBe('lookup')
            ->and($answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris'])
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"city":"Paris"}')
            ->and($answer->toolCalls[1]->arguments)->toBe(['city' => 'Rome', 'days' => 2])
            ->and($answer->toolCalls[1]->rawArguments)->toBe('{ "city" : "Rome", "days": 2 }')
            ->and($answer->toolCalls[2]->arguments)->toBe([])
            ->and($answer->toolCalls[2]->rawArguments)->toBe('{}');
    });

    it('has its arguments taken as they are when they arrive as a map', function (): void {
        $answer = answerWithTools(ChatCompletions::calling(['city' => 'Paris', 'days' => 2], []));

        expect($answer->toolCalls[0]->arguments)->toBe(['city' => 'Paris', 'days' => 2])
            ->and($answer->toolCalls[0]->rawArguments)->toBe('{"city":"Paris","days":2}')
            ->and($answer->toolCalls[1]->arguments)->toBe([])
            ->and($answer->toolCalls[1]->rawArguments)->toBe('{}');
    });

    it('is returned as not readable, and not thrown, when its arguments are no JSON object', function (mixed $arguments, string $raw): void {
        $answer = answerWithTools(ChatCompletions::calling($arguments));

        expect($answer->toolCalls)->toHaveCount(1)
            ->and($answer->toolCalls[0]->isReadable())->toBeFalse()
            ->and($answer->toolCalls[0]->argumentsError)->not->toBe('')
            ->and($answer->toolCalls[0]->rawArguments)->toBe($raw)
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls);
    })->with([
        'cut off' => ['{"city": "Par', '{"city": "Par'],
        'a list as text' => ['["Paris"]', '["Paris"]'],
        'an empty list as text' => ['[]', '[]'],
        'a string as text' => ['"Paris"', '"Paris"'],
        'null as text' => ['null', 'null'],
        'an empty text' => ['', ''],
        'text after the object' => ['{"city":"Paris"} and more', '{"city":"Paris"} and more'],
        'a list' => [['Paris', 'Rome'], '["Paris","Rome"]'],
        'a number' => [7, '7'],
        'true' => [true, 'true'],
        'null' => [null, ''],
        'an object whose names are the numbers of a list' => ['{"0":"Paris","1":"Rome"}', '{"0":"Paris","1":"Rome"}'],
    ]);

    it('checks nothing else: a whole number that arrives as a string stays a string', function (): void {
        $answer = answerWithTools(ChatCompletions::calling('{"k":"70","deep":{"n":"1"}}'));

        expect($answer->toolCalls[0]->arguments)->toBe(['k' => '70', 'deep' => ['n' => '1']]);
    });

    it('gets an id of the adapter when the server gave none, and is skipped when it holds no function', function (): void {
        $message = ['role' => 'assistant', 'content' => '', 'tool_calls' => [
            ['type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']],
            'no call at all',
            ['id' => 'call_x', 'type' => 'function'],
            ['id' => '', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Rome"}']],
            ['id' => 'abc123XYZ', 'index' => 3, 'function' => ['arguments' => '{}']],
        ]];
        $answer = answerWithTools($message);

        expect(array_map(fn($call): string => $call->id, $answer->toolCalls))->toBe(['call_0', 'call_1', 'abc123XYZ'])
            ->and($answer->toolCalls[2]->name)->toBe('');
    });

    it('gets an id that no other call of the answer has, also when the server uses the same form', function (array $ids, array $given): void {
        $calls = [];
        foreach ($ids as $id) {
            $calls[] = [...($id === null ? [] : ['id' => $id]), 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']];
        }
        $answer = answerWithTools(['role' => 'assistant', 'content' => null, 'tool_calls' => $calls]);

        expect(array_map(fn($call): string => $call->id, $answer->toolCalls))->toBe($given);
    })->with([
        'the id it would make is taken by the call before it' => [['call_1', null], ['call_1', 'call_1_2']],
        'the id it would make is taken by the call after it' => [[null, 'call_0'], ['call_0_2', 'call_0']],
        'both forms are taken' => [['call_2', 'call_2_2', null], ['call_2', 'call_2_2', 'call_2_3']],
        'none has an id' => [[null, null, null], ['call_0', 'call_1', 'call_2']],
    ]);

    it('is told by its presence: the answer ends for tool calls whatever word the server gave, unless it was cut off', function (?string $word, FinishReason $reason): void {
        expect(answerWithTools(ChatCompletions::calling('{"city":"Paris"}'), $word)->finishReason)->toBe($reason);
    })->with([
        'tool_calls' => ['tool_calls', FinishReason::ToolCalls],
        'stop' => ['stop', FinishReason::ToolCalls],
        'end_turn' => ['end_turn', FinishReason::ToolCalls],
        'content_filter' => ['content_filter', FinishReason::ToolCalls],
        'function_call' => ['function_call', FinishReason::ToolCalls],
        'tool_use' => ['tool_use', FinishReason::ToolCalls],
        'TOOL_CALL' => ['TOOL_CALL', FinishReason::ToolCalls],
        'a word nobody documents' => ['done_thinking', FinishReason::ToolCalls],
        'no word' => [null, FinishReason::ToolCalls],
        'length' => ['length', FinishReason::Length],
        'model_length' => ['model_length', FinishReason::Length],
    ]);

    it('may stand beside text, and a tool_calls field of another type is no tool call', function (): void {
        $message = ChatCompletions::calling('{"city":"Paris"}');
        $message['content'] = 'Let me look that up.';
        $answer = answerWithTools($message, 'stop');

        expect($answer->text)->toBe('Let me look that up.')
            ->and($answer->toolCalls)->toHaveCount(1);

        foreach ([null, 'lookup', 7, []] as $notAList) {
            $plain = answerWithTools(['role' => 'assistant', 'content' => 'Hello.', 'tool_calls' => $notAList], 'stop');
            expect($plain->toolCalls)->toBe([])
                ->and($plain->finishReason)->toBe(FinishReason::Stop);
        }
    });
});

describe('the finish reason', function (): void {
    it('is read from the word of the server', function (?string $word, FinishReason $reason): void {
        expect(ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hello.'], $word))->finishReason)->toBe($reason);
    })->with([
        'stop' => ['stop', FinishReason::Stop],
        'end_turn' => ['end_turn', FinishReason::Stop],
        'stop_sequence' => ['stop_sequence', FinishReason::Stop],
        'COMPLETE' => ['COMPLETE', FinishReason::Stop],
        'STOP_SEQUENCE' => ['STOP_SEQUENCE', FinishReason::Stop],
        'STOP' => ['STOP', FinishReason::Stop],
        'length' => ['length', FinishReason::Length],
        'model_length' => ['model_length', FinishReason::Length],
        'max_tokens' => ['max_tokens', FinishReason::Length],
        'model_context_window_exceeded' => ['model_context_window_exceeded', FinishReason::Length],
        'MAX_TOKENS' => ['MAX_TOKENS', FinishReason::Length],
        // The word for tool calls on an answer that holds none is not passed on: a loop would spin on it.
        'tool_calls, and no call' => ['tool_calls', FinishReason::Other],
        'function_call, and no call' => ['function_call', FinishReason::Other],
        'tool_use, and no call' => ['tool_use', FinishReason::Other],
        'TOOL_CALL, and no call' => ['TOOL_CALL', FinishReason::Other],
        'content_filter' => ['content_filter', FinishReason::ContentFilter],
        'pause_turn' => ['pause_turn', FinishReason::Other],
        'a word nobody documents' => ['eos', FinishReason::Other],
        'an empty word' => ['', FinishReason::Other],
        'no word' => [null, FinishReason::Other],
    ]);

    it('is "other" when the field has another type, and a second field stop_reason is not read', function (): void {
        foreach ([7, true, ['stop'], 0.5] as $notAWord) {
            $response = new HttpResponse(200, [], ChatCompletions::json(['choices' => [['message' => ['content' => 'Hello.'], 'finish_reason' => $notAWord, 'stop_reason' => 'length']]]));

            expect(ChatCompletions::answerTo($response)->finishReason)->toBe(FinishReason::Other);
        }

        $response = new HttpResponse(200, [], ChatCompletions::json(['choices' => [['message' => ['content' => 'Hello.'], 'finish_reason' => 'stop', 'stop_reason' => 'length']]]));
        expect(ChatCompletions::answerTo($response)->finishReason)->toBe(FinishReason::Stop);
    });

    it('is an error of the provider when the word is "error", with that word as the code, and not tried again', function (string $word): void {
        $transport = (new FakeTransport())
            ->willReturn(ChatCompletions::answered(['role' => 'assistant', 'content' => 'Half an ans'], $word))
            ->willReturn(ChatCompletions::answered());
        $gateway = ChatCompletions::gateway($transport, retry: new RetryPolicy([1.0], 60.0));

        $error = Talk::errorOf(fn() => $gateway->complete(ChatCompletions::request()));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->providerCode)->toBe('error')
            ->and($error->httpStatus)->toBe(200)
            ->and($error->attempts)->toBe(1)
            ->and($error->getMessage())->not->toContain('Half an ans')
            ->and($transport->requests())->toHaveCount(1);
    })->with(['error', 'ERROR']);

    it('returns an answer cut off before any text as an answer', function (): void {
        $answer = ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => '', 'reasoning_content' => 'A long thought.'], 'length'));

        expect($answer->text)->toBe('')
            ->and($answer->finishReason)->toBe(FinishReason::Length);
    });

    it('fails as an error of the provider for an answer that holds nothing and ended by itself', function (array $message, ?string $word): void {
        $error = Talk::errorOf(fn() => ChatCompletions::answerTo(ChatCompletions::answered($message, $word)));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->httpStatus)->toBe(200);
    })->with([
        'an empty content' => [['role' => 'assistant', 'content' => ''], 'stop'],
        'no content' => [['role' => 'assistant'], 'stop'],
        'a null content and no word' => [['role' => 'assistant', 'content' => null], null],
        'only reasoning' => [['role' => 'assistant', 'content' => null, 'reasoning_content' => 'A thought.'], 'stop'],
        'a content of another type' => [['role' => 'assistant', 'content' => 7], 'stop'],
        'the word for tool calls and no call' => [['role' => 'assistant', 'content' => null], 'tool_calls'],
    ]);
});

describe('a refusal of the model', function (): void {
    it('ends the answer as a refusal, and its words are the text when there is no other', function (): void {
        $answer = ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => null, 'refusal' => 'I cannot help with that.'], 'stop'));

        expect($answer->text)->toBe('I cannot help with that.')
            ->and($answer->finishReason)->toBe(FinishReason::Refusal);
    });

    it('keeps the content as the text when the answer has both', function (): void {
        $answer = ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => 'No.', 'refusal' => 'I cannot help with that.'], 'stop'));

        expect($answer->text)->toBe('No.')
            ->and($answer->finishReason)->toBe(FinishReason::Refusal);
    });

    it('is a string that is not empty: an empty one marks no answer as refused', function (): void {
        $answer = ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hello.', 'refusal' => ''], 'stop'));

        expect($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop);

        // And an answer that holds nothing is not explained by it.
        $nothing = ChatCompletions::answered(['role' => 'assistant', 'content' => null, 'refusal' => ''], 'stop');
        expect(Talk::errorOf(fn() => ChatCompletions::answerTo($nothing))->reason)->toBe(ErrorReason::ProviderError);
    });

    it('without text is, in this format, an answer the server explains by its finish reason: returned, not thrown', function (array $message, string $word, FinishReason $reason): void {
        // The format has no refusal without words: `message.refusal` is the words. What it does have is an answer
        // without text whose finish reason says why, and that is an answer.
        $answer = ChatCompletions::answerTo(ChatCompletions::answered($message, $word));

        expect($answer->text)->toBe('')
            ->and($answer->toolCalls)->toBe([])
            ->and($answer->finishReason)->toBe($reason)
            ->and($answer->usage)->toEqual(new Usage(3, 2));
    })->with([
        'withheld, a null content' => [['role' => 'assistant', 'content' => null, 'refusal' => null], 'content_filter', FinishReason::ContentFilter],
        'withheld, an empty refusal beside it' => [['role' => 'assistant', 'content' => '', 'refusal' => ''], 'content_filter', FinishReason::ContentFilter],
        'cut off before any text' => [['role' => 'assistant', 'content' => null], 'length', FinishReason::Length],
    ]);

    it('gives a structured result that says the text is no JSON, not an exception', function (array $message, string $word, FinishReason $reason): void {
        $answer = ChatCompletions::answerTo(ChatCompletions::answered($message, $word), [], ChatCompletions::request()->withResponseFormat(ChatCompletions::trip()));

        expect($answer->finishReason)->toBe($reason)
            ->and($answer->structured?->isUsable())->toBeFalse()
            ->and($answer->structured?->reasons())->not->toBe([]);
    })->with([
        'a refusal in words' => [['role' => 'assistant', 'content' => null, 'refusal' => 'I cannot help with that.'], 'stop', FinishReason::Refusal],
        'withheld output' => [['role' => 'assistant', 'content' => null], 'content_filter', FinishReason::ContentFilter],
    ]);

    it('is no refusal when the field is null or of another type', function (mixed $refusal): void {
        $answer = ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hello.', 'refusal' => $refusal], 'stop'));

        expect($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop);
    })->with([[null], [false], [0], [['reason' => 'policy']]]);

    it('does not hide a tool call: an answer that holds one still ends for tool calls', function (): void {
        $message = ChatCompletions::calling('{"city":"Paris"}');
        $message['refusal'] = 'I would rather not.';
        $answer = answerWithTools($message, 'stop');

        expect($answer->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($answer->text)->toBe('I would rather not.');
    });
});

describe('an error member beside an answer', function (): void {
    it('does not throw the answer away: an error counts only where the body holds no choice', function (string $error): void {
        $response = new HttpResponse(200, [], '{"choices":[{"message":{"content":"Hello."},"finish_reason":"stop"}],"error":' . $error . '}');
        $answer = ChatCompletions::answerTo($response);

        expect($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop);
    })->with([
        'an error object' => ['{"message":"A warning of the server.","type":"server_error"}'],
        'a word' => ['"model_timeout"'],
        'null' => ['null'],
    ]);
});

describe('the fields of a content filter', function (): void {
    it('returns withheld output as an answer without text, and reads past the results of the filter', function (): void {
        $response = new HttpResponse(200, [], ChatCompletions::json([
            'id' => 'chatcmpl-example-1',
            'model' => 'example-chat-1-0001',
            'prompt_filter_results' => [['prompt_index' => 0, 'content_filter_results' => ['hate' => ['filtered' => false, 'severity' => 'safe']]]],
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => null],
                'finish_reason' => 'content_filter',
                'content_filter_results' => ['violence' => ['filtered' => true, 'severity' => 'medium']],
            ]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 0],
        ]));
        $answer = ChatCompletions::answerTo($response);

        expect($answer->text)->toBe('')
            ->and($answer->finishReason)->toBe(FinishReason::ContentFilter)
            ->and($answer->usage)->toEqual(new Usage(3, 0));
    });

    it('returns the part that arrived when the filter cut a longer answer', function (): void {
        $answer = ChatCompletions::answerTo(ChatCompletions::answered(['role' => 'assistant', 'content' => 'The first part'], 'content_filter'));

        expect($answer->text)->toBe('The first part')
            ->and($answer->finishReason)->toBe(FinishReason::ContentFilter);
    });

    it('ignores the word that the filter did not run: the answer is returned', function (): void {
        $response = new HttpResponse(200, [], ChatCompletions::json([
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Hello.'],
                'finish_reason' => 'stop',
                'content_filter_results' => ['error' => ['code' => 'content_filter_error', 'message' => 'The contents are not filtered']],
            ]],
        ]));
        $answer = ChatCompletions::answerTo($response);

        expect($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop);
    });
});

describe('the usage', function (): void {
    it('is read at the top of the body or inside the choice, and only whole numbers of 0 or more count', function (array $body, Usage $usage): void {
        $response = new HttpResponse(200, [], ChatCompletions::json($body));

        expect(ChatCompletions::answerTo($response)->usage)->toEqual($usage);
    })->with([
        'at the top' => [['choices' => [['message' => ['content' => 'Hello.']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4, 'total_tokens' => 14]], new Usage(10, 4)],
        'inside the choice' => [['choices' => [['message' => ['content' => 'Hello.'], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]]]], new Usage(10, 4)],
        'in both places: not added up' => [['choices' => [['message' => ['content' => 'Hello.'], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]], new Usage(10, 4)],
        'with more fields' => [['choices' => [['message' => ['content' => 'Hello.']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4, 'cached_tokens' => 2, 'completion_tokens_details' => ['reasoning_tokens' => 90], 'cost_in_usd_ticks' => 1200]], new Usage(10, 4)],
        'only one of the two' => [['choices' => [['message' => ['content' => 'Hello.']]], 'usage' => ['prompt_tokens' => 10]], new Usage(10, null)],
        'none' => [['choices' => [['message' => ['content' => 'Hello.']]]], new Usage(null, null)],
        'null' => [['choices' => [['message' => ['content' => 'Hello.']]], 'usage' => null], new Usage(null, null)],
        'of another type' => [['choices' => [['message' => ['content' => 'Hello.']]], 'usage' => 'unknown'], new Usage(null, null)],
        'counts of another type' => [['choices' => [['message' => ['content' => 'Hello.']]], 'usage' => ['prompt_tokens' => '10', 'completion_tokens' => 4.5]], new Usage(null, null)],
        'a count below 0' => [['choices' => [['message' => ['content' => 'Hello.']]], 'usage' => ['prompt_tokens' => -1, 'completion_tokens' => 4]], new Usage(null, 4)],
    ]);
});

describe('a body that is no answer', function (): void {
    it('is an error of the provider, never a warning or a type error', function (string $body): void {
        $error = Talk::errorOf(fn() => ChatCompletions::answerTo(new HttpResponse(200, ['Content-Type' => 'application/json'], $body)));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->httpStatus)->toBe(200)
            ->and($error->attempts)->toBe(1)
            ->and($error->providerCode)->toBeNull();
    })->with([
        'empty' => [''],
        'no JSON' => ['<html><body>502 Bad Gateway</body></html>'],
        'cut off' => ['{"id":"chatcmpl-example-1","choices":[{"mess'],
        'a JSON string' => ['"Hello."'],
        'a JSON number' => ['7'],
        'null' => ['null'],
        'a list' => ['[{"choices":[{"message":{"content":"Hello."}}]}]'],
        'an empty object' => ['{}'],
        'no choices' => ['{"id":"chatcmpl-example-1","object":"chat.completion","usage":{"prompt_tokens":3,"completion_tokens":0}}'],
        'choices that are null' => ['{"choices":null}'],
        'choices that are a word' => ['{"choices":"none"}'],
        'no choice in the list' => ['{"choices":[]}'],
        'a choice that is a word' => ['{"choices":["stop"]}'],
        'a choice without a message' => ['{"choices":[{"index":0,"finish_reason":"stop"}]}'],
        'a message that is a word' => ['{"choices":[{"index":0,"message":"Hello."}]}'],
        'the shape of the older completions endpoint' => ['{"object":"text_completion","choices":[{"text":"Hello.","finish_reason":"length","stop_reason":null}]}'],
        'an error that is null, and no choices' => ['{"error":null}'],
    ]);

    it('is an error of the provider with the code of the body when it holds an error under a successful status', function (string $body, ?string $code): void {
        $error = Talk::errorOf(fn() => ChatCompletions::answerTo(new HttpResponse(200, [], $body)));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->providerCode)->toBe($code)
            ->and($error->detail)->toBe('the response holds an error')
            ->and($error->getMessage())->not->toContain('overloaded right now');
    })->with([
        'an error object' => ['{"error":{"message":"The server is overloaded right now.","type":"server_error","code":"server_is_overloaded"}}', 'server_is_overloaded'],
        'an error object beside an empty list of choices' => ['{"choices":[],"error":{"message":"The server is overloaded right now.","type":"server_error"}}', 'server_error'],
        'a flat body' => ['{"id":null,"code":503,"error":"model_unavailable","detail":"The server is overloaded right now."}', 'model_unavailable'],
        'an error object without a word' => ['{"error":{"message":"The server is overloaded right now."}}', null],
    ]);
});
