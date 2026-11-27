<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Gemini\Reading;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// How the generate-content adapter reads a whole answer (docs/design/adapters.md, sections 4.2 and 5.2): the text,
// the tool calls, the finish reason in both spellings, the usage, a blocked prompt, and everything a body can hold
// that it does not expect.

/**
 * The error of a call that gets this response.
 */
function generateErrorFor(HttpResponse $response): OrganiseYou\LlmGateway\Contract\GatewayException
{
    return Talk::errorOf(fn() => GenerateContent::answerTo($response));
}

describe('the text of an answer of the generate-content adapter', function (): void {
    it('is the text of the parts, joined, without the parts that are a thought', function (): void {
        $answer = GenerateContent::answerTo(GenerateContent::answered([
            GenerateContent::text('Thinking about it.', ['thought' => true]),
            GenerateContent::text('Hel'),
            GenerateContent::text('lo.', ['thought' => false]),
        ]));

        expect($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop)
            ->and($answer->toolCalls)->toBe([])
            ->and($answer->opaque)->toBeNull();
    });

    it('names the model the response names, and the model of the request when it names none', function (): void {
        expect(GenerateContent::answerTo(GenerateContent::answered(more: ['modelVersion' => 'example-generate-1-001']))->model)->toBe('example-generate-1-001')
            ->and(GenerateContent::answerTo(GenerateContent::answered(more: ['modelVersion' => null]))->model)->toBe(GenerateContent::MODEL)
            ->and(GenerateContent::answerTo(GenerateContent::answered(more: ['modelVersion' => ['x']]))->model)->toBe(GenerateContent::MODEL);
    });

    it('reads the first candidate and no other', function (): void {
        $body = GenerateContent::body();
        $body['candidates'][] = ['index' => 1, 'content' => ['role' => 'model', 'parts' => [GenerateContent::text('Another.')]], 'finishReason' => 'MAX_TOKENS'];

        $answer = GenerateContent::answerTo(new HttpResponse(200, [], GenerateContent::json($body)));

        expect($answer->text)->toBe('Hello.')
            ->and($answer->finishReason)->toBe(FinishReason::Stop);
    });

    it('reads past a part of a kind it does not know, a part that is no object and a member of another type', function (): void {
        $answer = GenerateContent::answerTo(GenerateContent::answered([
            ['inlineData' => ['mimeType' => 'image/png', 'data' => 'AAAA']],
            'a bare string',
            7,
            null,
            ['text' => ['not' => 'a string']],
            ['text' => 'Hello.', 'someNewMember' => ['x' => 1]],
            ['functionCall' => 'no object'],
            ['executableCode' => ['code' => 'x']],
        ], 'STOP', ['someNewField' => ['a' => 1], 'promptFeedback' => ['safetyRatings' => []]]));

        expect($answer->text)->toBe('Hello.')
            ->and($answer->toolCalls)->toBe([]);
    });
});

describe('the tool calls of an answer of the generate-content adapter', function (): void {
    it('reads a call anywhere among the parts, with its id, its name and its arguments as an object', function (): void {
        $answer = GenerateContent::answerTo(GenerateContent::answered([
            GenerateContent::call(['city' => 'Paris']),
            GenerateContent::text('Let me look.'),
            GenerateContent::call(['city' => 'Rome'], 'fc-example02', 'weather'),
        ]));

        expect($answer->text)->toBe('Let me look.')
            ->and($answer->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($answer->toolCalls)->toEqual([
                ToolCall::decoded('fc-example01', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'),
                ToolCall::decoded('fc-example02', 'weather', ['city' => 'Rome'], '{"city":"Rome"}'),
            ]);
    });

    it('gives a call without an id the id of its place among the calls, and one no other call of the answer has', function (): void {
        $answer = GenerateContent::answerTo(GenerateContent::answered([
            GenerateContent::call([], null),
            GenerateContent::call([], 'call-2'),
            GenerateContent::call([], null),
            GenerateContent::call([], ''),
            GenerateContent::call([], 'call-3'),
            ['functionCall' => ['id' => 7, 'name' => 'lookup']],
        ]));

        expect(array_map(fn(ToolCall $call): string => $call->id, $answer->toolCalls))->toBe(['call-0', 'call-2', 'call-2-2', 'call-3-2', 'call-3', 'call-5']);
    });

    it('keeps the ids of very many calls apart', function (): void {
        $parts = [];
        for ($at = 0; $at < 20000; $at++) {
            $parts[] = GenerateContent::call([], $at % 2 === 0 ? null : 'call-' . ($at - 1));
        }
        $answer = GenerateContent::answerTo(GenerateContent::answered($parts));

        $ids = array_map(fn(ToolCall $call): string => $call->id, $answer->toolCalls);
        expect($ids)->toHaveCount(20000)
            ->and(array_unique($ids))->toHaveCount(20000);
    });

    it('fails an answer with a call that names no tool, whole or as a stream: such a call cannot be handed on', function (array $call): void {
        $error = Talk::errorOf(fn() => GenerateContent::answerTo(GenerateContent::answered([GenerateContent::text(), ['functionCall' => $call]])));
        $failed = Talk::failedIn(GenerateContent::eventsOf([GenerateContent::chunk([GenerateContent::text()]), GenerateContent::chunk([['functionCall' => $call]], 'STOP')]));

        foreach ([$error, $failed->error] as $thrown) {
            expect($thrown->reason)->toBe(ErrorReason::ProviderError)
                ->and($thrown->detail)->toBe('a tool call of the answer names no tool')
                ->and($thrown->attempts)->toBe(1);
        }
        expect($failed->textSoFar)->toBe('Hello.');
    })->with([
        'no name' => [['args' => ['city' => 'Paris']]],
        'an empty name' => [['id' => 'fc-example01', 'name' => '', 'args' => []]],
        'a name that is no string' => [['name' => 7]],
        'nothing' => [[]],
    ]);

    it('keeps the arguments as the body wrote them: an empty object, a number in its spelling, in one spelling of whitespace', function (): void {
        $body = str_replace('"ARGS"', '{ "n" : 1.0, "big": 12345678901234567890, "empty": {}, "list": [] }', GenerateContent::json(GenerateContent::body([GenerateContent::call('ARGS')])));
        $call = GenerateContent::answerTo(new HttpResponse(200, [], $body))->toolCalls[0];

        expect($call->rawArguments)->toBe('{"n":1.0,"big":12345678901234567890,"empty":{},"list":[]}')
            ->and($call->isReadable())->toBeTrue()
            ->and($call->arguments['empty'] ?? null)->toBe([]);
    });

    it('reads a call without arguments as a call with an empty object', function (mixed $part): void {
        $call = GenerateContent::answerTo(GenerateContent::answered([$part]))->toolCalls[0];

        expect($call->arguments)->toBe([])
            ->and($call->rawArguments)->toBe('{}');
    })->with([
        'no arguments' => [['functionCall' => ['name' => 'lookup']]],
        'null' => [['functionCall' => ['name' => 'lookup', 'args' => null]]],
        'an empty object' => [['functionCall' => ['name' => 'lookup', 'args' => (object) []]]],
    ]);

    it('returns a call whose arguments are no object as a call that cannot be read, never as an error', function (mixed $arguments, string $raw): void {
        $call = GenerateContent::answerTo(GenerateContent::answered([GenerateContent::call($arguments)]))->toolCalls[0];

        expect($call->isReadable())->toBeFalse()
            ->and($call->argumentsError)->not->toBe('')
            ->and($call->rawArguments)->toBe($raw);
    })->with([
        'a cut-off text' => ['{"city": "Par', '{"city": "Par'],
        'a list' => [['Paris', 'Rome'], '["Paris","Rome"]'],
        'a number' => [7, '7'],
        'true' => [true, 'true'],
        'an empty text' => ['', '""'],
        'a text that holds a list' => ['[1]', '[1]'],
        'an object whose names are the numbers of a list' => [['0' => 'a', '1' => 'b'], '["a","b"]'],
    ]);

    it('reads arguments that arrive as a text that holds an object, which no page documents', function (): void {
        $call = GenerateContent::answerTo(GenerateContent::answered([GenerateContent::call('{ "city": "Paris" }')]))->toolCalls[0];

        expect($call->arguments)->toBe(['city' => 'Paris'])
            ->and($call->rawArguments)->toBe('{"city":"Paris"}');
    });
});

describe('the finish reason of an answer of the generate-content adapter', function (): void {
    it('reads both spellings of a finish reason, with and without the prefix', function (string $word, FinishReason $reason): void {
        foreach ([$word, 'FINISH_REASON_' . $word] as $spelling) {
            expect(GenerateContent::answerTo(GenerateContent::answered(null, $spelling))->finishReason)->toBe($reason, $spelling);
        }
    })->with([
        ['STOP', FinishReason::Stop],
        ['MAX_TOKENS', FinishReason::Length],
        ['SAFETY', FinishReason::ContentFilter],
        ['RECITATION', FinishReason::ContentFilter],
        ['BLOCKLIST', FinishReason::ContentFilter],
        ['PROHIBITED_CONTENT', FinishReason::ContentFilter],
        ['SPII', FinishReason::ContentFilter],
        ['MODEL_ARMOR', FinishReason::ContentFilter],
        ['LANGUAGE', FinishReason::Other],
        ['CONTINUATION', FinishReason::Other],
        ['TOO_MANY_TOOL_CALLS', FinishReason::Other],
        ['OTHER', FinishReason::Other],
        ['UNSPECIFIED', FinishReason::Other],
        ['ESCALATION', FinishReason::Other],
        ['PUP_LIMITED_DISABLED', FinishReason::Other],
        ['IMAGE_SAFETY', FinishReason::Other],
        ['NO_IMAGE', FinishReason::Other],
        ['A_WORD_OF_A_LATER_VERSION', FinishReason::Other],
    ]);

    it('reads no finish reason, and one of another type, as "other"', function (mixed $finish): void {
        $body = GenerateContent::body();
        $body['candidates'][0]['finishReason'] = $finish;

        expect(GenerateContent::answerTo(new HttpResponse(200, [], GenerateContent::json($body)))->finishReason)->toBe(FinishReason::Other);
    })->with([[null], [''], [7], [['STOP']], ['stop'], ['FINISH_REASON_'], ['FINISH_REASON_UNSPECIFIED']]);

    it('ends an answer that holds a tool call for tool calls whatever word came with it, unless it was cut off', function (string $word, FinishReason $reason): void {
        expect(GenerateContent::answerTo(GenerateContent::answered([GenerateContent::call()], $word))->finishReason)->toBe($reason);
    })->with([
        ['STOP', FinishReason::ToolCalls],
        ['FINISH_REASON_STOP', FinishReason::ToolCalls],
        ['OTHER', FinishReason::ToolCalls],
        ['SAFETY', FinishReason::ToolCalls],
        ['MAX_TOKENS', FinishReason::Length],
        ['FINISH_REASON_MAX_TOKENS', FinishReason::Length],
    ]);

    it('has no finish word for a tool call: a word that sounds like one, on an answer without a call, is "other" and never "tool calls"', function (string $word): void {
        expect(GenerateContent::answerTo(GenerateContent::answered(null, $word))->finishReason)->toBe(FinishReason::Other)
            ->and(Reading::finishReason($word, false))->toBe(FinishReason::Other);
    })->with([['TOOL_CALL'], ['TOOL_CALLS'], ['FUNCTION_CALL'], ['FINISH_REASON_TOOL_CALLS'], ['tool_calls']]);

    it('throws a finish reason that says the answer is a fault as an error of the provider, with the word in lower case and in one spelling as its code', function (string $word, string $code): void {
        foreach ([$word, 'FINISH_REASON_' . $word] as $spelling) {
            // Also beside text and beside a call: there is no answer to hand on.
            foreach ([null, [GenerateContent::call()]] as $parts) {
                $transport = (new FakeTransport())->willReturn(GenerateContent::answered($parts, $spelling));
                $error = Talk::errorOf(fn() => GenerateContent::gateway($transport, retry: new OrganiseYou\LlmGateway\Contract\RetryPolicy([1.0], 60.0))->complete(GenerateContent::request()));

                expect($error->reason)->toBe(ErrorReason::ProviderError, $spelling)
                    ->and($error->providerCode)->toBe($code)
                    ->and($error->httpStatus)->toBe(200)
                    ->and($error->detail)->toBe('the answer ended as a fault of the model')
                    ->and($transport->requests())->toHaveCount(1);
            }
        }
    })->with([
        ['MALFORMED_FUNCTION_CALL', 'malformed_function_call'],
        ['UNEXPECTED_TOOL_CALL', 'unexpected_tool_call'],
        ['MALFORMED_RESPONSE', 'malformed_response'],
        ['MISSING_THOUGHT_SIGNATURE', 'missing_thought_signature'],
    ]);

    it('returns an answer the provider withheld, without any text, as an answer and not as an error', function (string $word, array $candidate): void {
        $body = GenerateContent::body();
        $body['candidates'] = [[...$candidate, 'finishReason' => $word]];

        $answer = GenerateContent::answerTo(new HttpResponse(200, [], GenerateContent::json($body)));

        expect($answer->text)->toBe('')
            ->and($answer->toolCalls)->toBe([])
            ->and($answer->finishReason)->toBe(FinishReason::ContentFilter)
            ->and($answer->usage->inputTokens)->toBe(3);
    })->with([
        'no content at all' => ['SAFETY', ['index' => 0]],
        'a content without parts' => ['FINISH_REASON_SAFETY', ['index' => 0, 'content' => ['role' => 'model']]],
        'an empty list of parts' => ['RECITATION', ['index' => 0, 'content' => ['role' => 'model', 'parts' => []]]],
        'a content that is null' => ['PROHIBITED_CONTENT', ['index' => 0, 'content' => null]],
        'a part with an empty text' => ['SPII', ['index' => 0, 'content' => ['role' => 'model', 'parts' => [['text' => '']]]]],
    ]);

    it('returns an answer that was cut off before any text as an answer, and fails one that ended by itself without anything', function (): void {
        $body = GenerateContent::body([]);

        expect(GenerateContent::answerTo(GenerateContent::answered([], 'MAX_TOKENS'))->finishReason)->toBe(FinishReason::Length)
            ->and(generateErrorFor(new HttpResponse(200, [], GenerateContent::json($body)))->reason)->toBe(ErrorReason::ProviderError);
    });
});

describe('a prompt the provider refused, in an answer of the generate-content adapter', function (): void {
    it('is "rejected" with the block reason in lower case as its code, a detail in the words of the library, and no second attempt', function (array $feedback, string $code, array $more): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], GenerateContent::json(['promptFeedback' => $feedback, 'usageMetadata' => ['promptTokenCount' => 3], ...$more])));
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport, retry: new OrganiseYou\LlmGateway\Contract\RetryPolicy([1.0], 60.0))->complete(GenerateContent::request()));

        expect($error->reason)->toBe(ErrorReason::Rejected)
            ->and($error->providerCode)->toBe($code)
            ->and($error->httpStatus)->toBe(200)
            ->and($error->attempts)->toBe(1)
            ->and($error->detail)->toBe('the prompt was refused before an answer was made')
            ->and(preg_match('/gemini|google|vertex|safety|block|candidate|generate/i', (string) $error->detail))->toBe(0)
            ->and($transport->requests())->toHaveCount(1);
    })->with([
        'no candidates member' => [['blockReason' => 'SAFETY', 'safetyRatings' => []], 'safety', []],
        'an empty list of candidates' => [['blockReason' => 'PROHIBITED_CONTENT'], 'prohibited_content', ['candidates' => []]],
        'candidates that are null' => [['blockReason' => 'BLOCKLIST'], 'blocklist', ['candidates' => null]],
        'another reason' => [['blockReason' => 'OTHER'], 'other', []],
        'with the words of the provider beside it' => [['blockReason' => 'JAILBREAK', 'blockReasonMessage' => 'The words of the provider.'], 'jailbreak', []],
        'a reason of the second way in' => [['blockReason' => 'MODEL_ARMOR'], 'model_armor', []],
        'a reason of a later version' => [['blockReason' => 'SOMETHING_NEW'], 'something_new', []],
    ]);

    it('is not read from the unset value of a block reason, nor from one of another type: such a body holds no answer', function (mixed $reason): void {
        $error = generateErrorFor(new HttpResponse(200, [], GenerateContent::json(['promptFeedback' => ['blockReason' => $reason]])));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->providerCode)->toBeNull()
            ->and($error->detail)->toBe('the response holds no answer');
    })->with([['BLOCK_REASON_UNSPECIFIED'], ['BLOCKED_REASON_UNSPECIFIED'], [''], [null], [7], [['SAFETY']]]);

    it('does not throw an answer away for a block reason that stands beside it', function (): void {
        $answer = GenerateContent::answerTo(GenerateContent::answered(more: ['promptFeedback' => ['blockReason' => 'SAFETY']]));

        expect($answer->text)->toBe('Hello.');
    });
});

describe('the usage of an answer of the generate-content adapter', function (): void {
    it('reads the prompt count as the input and the candidates count plus the thoughts count as the output', function (array $usage, ?int $input, ?int $output): void {
        $answer = GenerateContent::answerTo(GenerateContent::answered(more: ['usageMetadata' => $usage]));

        expect($answer->usage->inputTokens)->toBe($input)
            ->and($answer->usage->outputTokens)->toBe($output);
    })->with([
        'both output counts' => [['promptTokenCount' => 40, 'candidatesTokenCount' => 12, 'thoughtsTokenCount' => 5, 'totalTokenCount' => 57], 40, 17],
        'no thoughts' => [['promptTokenCount' => 3, 'candidatesTokenCount' => 2], 3, 2],
        'thoughts alone' => [['promptTokenCount' => 3, 'thoughtsTokenCount' => 9], 3, 9],
        'no output count' => [['promptTokenCount' => 3, 'totalTokenCount' => 3], 3, null],
        'no input count' => [['candidatesTokenCount' => 2], null, 2],
        'other counts are not added' => [['promptTokenCount' => 3, 'candidatesTokenCount' => 2, 'toolUsePromptTokenCount' => 50, 'cachedContentTokenCount' => 60, 'totalTokenCount' => 999], 3, 2],
        'nothing' => [[], null, null],
        'counts that are no whole number of 0 or more' => [['promptTokenCount' => -1, 'candidatesTokenCount' => '2', 'thoughtsTokenCount' => 1.5], null, null],
        'one count of another type beside a good one' => [['promptTokenCount' => 3, 'candidatesTokenCount' => 2, 'thoughtsTokenCount' => null], 3, 2],
        'zero' => [['promptTokenCount' => 0, 'candidatesTokenCount' => 0, 'thoughtsTokenCount' => 0], 0, 0],
    ]);

    it('reads usage that is no object as no usage', function (mixed $usage): void {
        $answer = GenerateContent::answerTo(GenerateContent::answered(more: ['usageMetadata' => $usage]));

        expect($answer->usage->inputTokens)->toBeNull()
            ->and($answer->usage->outputTokens)->toBeNull();
    })->with([[null], ['3'], [7], [[3, 2]]]);

    it('does not report a sum of the two output counts that is too large for a whole number: the output is then not known', function (): void {
        $answer = GenerateContent::answerTo(GenerateContent::answered(more: ['usageMetadata' => ['promptTokenCount' => PHP_INT_MAX, 'candidatesTokenCount' => PHP_INT_MAX, 'thoughtsTokenCount' => 1]]));

        expect($answer->usage->inputTokens)->toBe(PHP_INT_MAX)
            ->and($answer->usage->outputTokens)->toBeNull()
            ->and(Reading::usage(['candidatesTokenCount' => PHP_INT_MAX, 'thoughtsTokenCount' => PHP_INT_MAX])->outputTokens)->toBeNull()
            ->and(Reading::usage(['candidatesTokenCount' => PHP_INT_MAX - 1, 'thoughtsTokenCount' => 1])->outputTokens)->toBe(PHP_INT_MAX);
    });
});

describe('a body the generate-content adapter does not expect', function (): void {
    it('ends as an error of the provider, never as a warning or a type error', function (string $body, string $detail): void {
        $error = generateErrorFor(new HttpResponse(200, [], $body));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->detail)->toBe($detail)
            ->and($error->providerCode)->toBeNull();
    })->with([
        'no JSON' => ['<html>no</html>', 'the body is not a JSON object'],
        'an empty body' => ['', 'the body is not a JSON object'],
        'a list' => ['[{"candidates":[]}]', 'the body is not a JSON object'],
        'a string' => ['"candidates"', 'the body is not a JSON object'],
        'an empty object' => ['{}', 'the response holds no answer'],
        'candidates that are a string' => ['{"candidates":"none"}', 'the response holds no answer'],
        'candidates that are an object' => ['{"candidates":{"first":{"content":{"parts":[{"text":"Hello."}]}}}}', 'the response holds no answer'],
        'a candidate that is no object' => ['{"candidates":["Hello."]}', 'the response holds no answer'],
        'an error that is a word' => ['{"error":"no"}', 'the response holds no answer'],
    ]);

    it('reads a candidate whose content or parts are of another type as an answer without text', function (string $candidate): void {
        $error = generateErrorFor(new HttpResponse(200, [], '{"candidates":[' . $candidate . ']}'));

        expect($error->reason)->toBe(ErrorReason::ProviderError);
    })->with([['{"content":"Hello."}'], ['{"content":{"parts":"Hello."}}'], ['{"content":{"parts":{"text":"Hello."}}}'], ['{"content":["Hello."]}'], ['{}']]);

    it('reads an error object under a successful status as an error of the provider with its code, only where there is no answer beside it', function (): void {
        $error = generateErrorFor(new HttpResponse(200, [], GenerateContent::errorBody(500, 'INTERNAL', 'EXAMPLE_REASON')));
        $withoutDetails = generateErrorFor(new HttpResponse(200, [], GenerateContent::errorBody(500, 'INTERNAL')));
        $beside = GenerateContent::answerTo(GenerateContent::answered(more: ['error' => ['code' => 500, 'status' => 'INTERNAL']]));

        expect($error->reason)->toBe(ErrorReason::ProviderError)
            ->and($error->providerCode)->toBe('example_reason')
            ->and($error->detail)->toBe('the response holds an error')
            ->and($withoutDetails->providerCode)->toBe('internal')
            ->and($beside->text)->toBe('Hello.');
    });
});
