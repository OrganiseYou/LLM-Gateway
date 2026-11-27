<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\Dialect;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Preset\ModelOverride;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\Talk;

// The dialect options of the chat-completions adapter (docs/design/adapters.md, section 2.2): every value of every
// option does what the table says, the default is the first value, and a value outside the list is not_configured
// before anything is sent.

/**
 * A request with the tool "lookup" and one more tool.
 */
function requestWithTwoTools(?ToolChoice $choice = null): Request
{
    return new Request(
        ChatCompletions::MODEL,
        [Message::user('Where to?')],
        30.0,
        [ChatCompletions::lookup(), ChatCompletions::lookup('book')],
        $choice ?? ToolChoice::auto(),
    );
}

describe('max_tokens_field', function (): void {
    it('names the field of the maximum of output tokens', function (array $options, string $field, string $other): void {
        $request = new Request(ChatCompletions::MODEL, [Message::user('Hi.')], 30.0, maxOutputTokens: 50);
        $body = ChatCompletions::bodyFor($request, $options);

        expect($body[$field])->toBe(50)
            ->and($body)->not->toHaveKey($other);
    })->with([
        'the default' => [[], 'max_completion_tokens', 'max_tokens'],
        'max_completion_tokens' => [['max_tokens_field' => 'max_completion_tokens'], 'max_completion_tokens', 'max_tokens'],
        'max_tokens' => [['max_tokens_field' => 'max_tokens'], 'max_tokens', 'max_completion_tokens'],
    ]);

    it('sends neither field when the request gives no maximum', function (): void {
        $body = ChatCompletions::bodyFor(ChatCompletions::request());

        expect($body)->not->toHaveKey('max_completion_tokens')
            ->and($body)->not->toHaveKey('max_tokens');
    });
});

describe('system_role and system_messages', function (): void {
    it('sends a system message with the role of the option', function (array $options, string $role): void {
        $body = ChatCompletions::bodyFor(ChatCompletions::request(Message::system('Be brief.'), Message::user('Hi.')), $options);

        expect($body['messages'])->toBe([['role' => $role, 'content' => 'Be brief.'], ['role' => 'user', 'content' => 'Hi.']]);
    })->with([
        'the default' => [[], 'system'],
        'system' => [['system_role' => 'system'], 'system'],
        'developer' => [['system_role' => 'developer'], 'developer'],
    ]);

    it('sends one message per system message, or one message with the texts joined by a blank line', function (array $options, array $system): void {
        $body = ChatCompletions::bodyFor(ChatCompletions::request(Message::system('Be brief.'), Message::system('Answer in Dutch.'), Message::user('Hi.')), $options);

        expect($body['messages'])->toBe([...$system, ['role' => 'user', 'content' => 'Hi.']]);
    })->with([
        'the default' => [[], [['role' => 'system', 'content' => 'Be brief.'], ['role' => 'system', 'content' => 'Answer in Dutch.']]],
        'separate' => [['system_messages' => 'separate'], [['role' => 'system', 'content' => 'Be brief.'], ['role' => 'system', 'content' => 'Answer in Dutch.']]],
        'joined' => [['system_messages' => 'joined'], [['role' => 'system', 'content' => "Be brief.\n\nAnswer in Dutch."]]],
        'joined, with the other role' => [['system_messages' => 'joined', 'system_role' => 'developer'], [['role' => 'developer', 'content' => "Be brief.\n\nAnswer in Dutch."]]],
    ]);

    it('sends no system message when the request has none, also when they are joined', function (): void {
        expect(ChatCompletions::bodyFor(ChatCompletions::request(), ['system_messages' => 'joined'])['messages'])->toBe([['role' => 'user', 'content' => 'Say hello.']]);
    });
});

describe('assistant_content_without_text', function (): void {
    it('writes the content of an assistant message that holds only tool calls as null or as an empty string', function (array $options, ?string $content): void {
        $call = ToolCall::decoded('call_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $request = new Request(
            ChatCompletions::MODEL,
            [Message::user('Where to?'), Message::assistant('', $call), Message::toolResult('call_1', 'lookup', '{"degrees":18}'), Message::assistant('It is 18 degrees.')],
            30.0,
            [ChatCompletions::lookup()],
        );
        $messages = ChatCompletions::bodyFor($request, $options)['messages'];

        expect($messages[1])->toBe([
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"city":"Paris"}']]],
        ])
            // An assistant message with text keeps its text, whatever the option says.
            ->and($messages[3])->toBe(['role' => 'assistant', 'content' => 'It is 18 degrees.']);
    })->with([
        'the default' => [[], null],
        'null' => [['assistant_content_without_text' => 'null'], null],
        'empty_string' => [['assistant_content_without_text' => 'empty_string'], ''],
    ]);
});

describe('required_word and named_tool_choice', function (): void {
    it('writes "must call some tool" with the word of the option', function (array $options, string $word): void {
        $body = ChatCompletions::bodyFor(requestWithTwoTools(ToolChoice::required()), $options);

        expect($body['tool_choice'])->toBe($word)
            ->and($body['tools'])->toHaveCount(2);
    })->with([
        'the default' => [[], 'required'],
        'required' => [['required_word' => 'required'], 'required'],
        'any' => [['required_word' => 'any'], 'any'],
    ]);

    it('sends tool_choice only when it is not "auto", and "none" as that word', function (): void {
        expect(ChatCompletions::bodyFor(requestWithTwoTools()))->not->toHaveKey('tool_choice')
            ->and(ChatCompletions::bodyFor(requestWithTwoTools(ToolChoice::none()))['tool_choice'])->toBe('none');
    });

    it('forces one named tool with the named-function object', function (array $options): void {
        $body = ChatCompletions::bodyFor(requestWithTwoTools(ToolChoice::tool('book')), $options);

        expect($body['tool_choice'])->toBe(['type' => 'function', 'function' => ['name' => 'book']])
            ->and(array_column(array_column($body['tools'], 'function'), 'name'))->toBe(['lookup', 'book']);
    })->with([
        'the default' => [[]],
        'object' => [['named_tool_choice' => 'object']],
    ]);

    it('forces one named tool with the word for "some tool" and only that tool in the request', function (array $options, string $word): void {
        $body = ChatCompletions::bodyFor(requestWithTwoTools(ToolChoice::tool('book')), $options);

        expect($body['tool_choice'])->toBe($word)
            ->and(array_column(array_column($body['tools'], 'function'), 'name'))->toBe(['book']);

        // Without a forced tool the same server gets every tool.
        expect(ChatCompletions::bodyFor(requestWithTwoTools(ToolChoice::required()), $options)['tools'])->toHaveCount(2);
    })->with([
        'required_single' => [['named_tool_choice' => 'required_single'], 'required'],
        'required_single, with the other word' => [['named_tool_choice' => 'required_single', 'required_word' => 'any'], 'any'],
    ]);

    it('refuses a forced tool on a server that takes none, before anything is sent', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        $gateway = ChatCompletions::gateway($transport, ['named_tool_choice' => 'unsupported']);

        $error = Talk::errorOf(fn() => $gateway->complete(requestWithTwoTools(ToolChoice::tool('book'))));

        expect($error->reason)->toBe(ErrorReason::NotSupported)
            ->and($error->attempts)->toBe(0)
            ->and($transport->requests())->toBe([]);

        // The other tool choices still work there.
        $gateway->complete(requestWithTwoTools(ToolChoice::required()));
        expect(ChatCompletions::sent($transport)['tool_choice'])->toBe('required');
    });

    it('tells the caller how a tool is forced: the capability follows the option', function (array $options, Support $forced): void {
        $can = ChatCompletions::gateway(new FakeTransport(), $options)->capabilities(ChatCompletions::MODEL);

        expect($can->forcedToolCall)->toBe($forced);
    })->with([
        'the default' => [[], Support::Native],
        'object' => [['named_tool_choice' => 'object'], Support::Native],
        'required_single' => [['named_tool_choice' => 'required_single'], Support::Emulated],
        'unsupported' => [['named_tool_choice' => 'unsupported'], Support::None],
        'a server that can name a tool, and a model that cannot be forced' => [['forced_tool_call' => 'none'], Support::None],
        'the two options say the same' => [['named_tool_choice' => 'required_single', 'forced_tool_call' => 'emulated'], Support::Emulated],
        'no tool calling at all' => [['tool_calling' => 'none', 'forced_tool_call' => 'native'], Support::None],
    ]);

    it('refuses as not_configured a forced tool call the server cannot give in that way', function (array $options): void {
        $gateway = ChatCompletions::gateway(new FakeTransport(), $options);

        $error = Talk::errorOf(fn() => $gateway->capabilities(ChatCompletions::MODEL));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the option forced_tool_call of the preset does not agree with its option named_tool_choice');
    })->with([
        'native, where no tool can be named' => [['named_tool_choice' => 'unsupported', 'forced_tool_call' => 'native']],
        'native, where it is emulated' => [['named_tool_choice' => 'required_single', 'forced_tool_call' => 'native']],
        'emulated, where it is native' => [['forced_tool_call' => 'emulated']],
    ]);
});

describe('tool_strict and tool_result_name', function (): void {
    it('writes strict: false on every tool, or leaves strict out', function (array $options, bool $written): void {
        foreach (ChatCompletions::bodyFor(requestWithTwoTools(), $options)['tools'] as $tool) {
            expect($tool['type'])->toBe('function')
                ->and(array_keys($tool['function']))->toBe($written ? ['name', 'description', 'parameters', 'strict'] : ['name', 'description', 'parameters'])
                ->and($tool['function']['strict'] ?? null)->toBe($written ? false : null);
        }
    })->with([
        'the default' => [[], false],
        'omit' => [['tool_strict' => 'omit'], false],
        'false, as a word' => [['tool_strict' => 'false'], true],
        'false, as a boolean' => [['tool_strict' => false], true],
    ]);

    it('sends a tool message with or without the name of its tool', function (array $options, array $message): void {
        $call = ToolCall::decoded('call_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $request = new Request(ChatCompletions::MODEL, [Message::user('Where to?'), Message::assistant('', $call), Message::toolResult('call_1', 'lookup', '{"degrees":18}')], 30.0, [ChatCompletions::lookup()]);

        expect(ChatCompletions::bodyFor($request, $options)['messages'][2])->toBe($message);
    })->with([
        'the default' => [[], ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"degrees":18}']],
        'false' => [['tool_result_name' => false], ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"degrees":18}']],
        'true' => [['tool_result_name' => true], ['role' => 'tool', 'tool_call_id' => 'call_1', 'name' => 'lookup', 'content' => '{"degrees":18}']],
    ]);
});

describe('json_schema_strict, strict_subset and json_schema_key', function (): void {
    it('writes the strict flag of a response format as the option says', function (array $options, ?bool $strict): void {
        $jsonSchema = ChatCompletions::bodyFor(ChatCompletions::request()->withResponseFormat(ChatCompletions::trip()), $options)['response_format']['json_schema'];

        expect(array_key_exists('strict', $jsonSchema))->toBe($strict !== null)
            ->and($jsonSchema['strict'] ?? null)->toBe($strict);
    })->with([
        'the default' => [[], null],
        'omit' => [['json_schema_strict' => 'omit'], null],
        'false, as a word' => [['json_schema_strict' => 'false'], false],
        'false, as a boolean' => [['json_schema_strict' => false], false],
        'when_fits, and the schema fits' => [['json_schema_strict' => 'when_fits'], true],
    ]);

    it('writes strict: true only when the schema fits the subset of the option', function (array $options, bool $strict): void {
        // Fits the standard subset; the narrow one refuses a pattern on a string.
        $format = new ResponseFormat(JsonSchema::fromJson('{"type":"object","properties":{"city":{"type":"string","pattern":"^[A-Z]"}},"required":["city"],"additionalProperties":false}'), 'trip');
        $jsonSchema = ChatCompletions::bodyFor(ChatCompletions::request()->withResponseFormat($format), ['json_schema_strict' => 'when_fits', ...$options])['response_format']['json_schema'];

        expect($jsonSchema['strict'])->toBe($strict)
            // The schema itself goes as the caller wrote it, also when it does not fit.
            ->and($jsonSchema['schema']['properties']['city'])->toBe(['type' => 'string', 'pattern' => '^[A-Z]']);
    })->with([
        'the default' => [[], true],
        'standard' => [['strict_subset' => 'standard'], true],
        'narrow' => [['strict_subset' => 'narrow'], false],
    ]);

    it('holds the schema under the key of the option', function (array $options, string $member, string $other): void {
        $format = ChatCompletions::trip();
        $responseFormat = ChatCompletions::bodyFor(ChatCompletions::request()->withResponseFormat($format), $options)['response_format'];

        expect($responseFormat['type'])->toBe('json_schema')
            ->and(array_keys($responseFormat['json_schema']))->toBe(['name', 'description', $member])
            ->and($responseFormat['json_schema']['name'])->toBe('trip')
            ->and($responseFormat['json_schema']['description'])->toBe('A trip to one city.')
            ->and($responseFormat['json_schema'][$member])->toBe($format->schema->toArray())
            ->and($responseFormat['json_schema'])->not->toHaveKey($other);
    })->with([
        'the default' => [[], 'schema', 'schema_definition'],
        'schema' => [['json_schema_key' => 'schema'], 'schema', 'schema_definition'],
        'schema_definition' => [['json_schema_key' => 'schema_definition'], 'schema_definition', 'schema'],
    ]);

    it('leaves the description of a response format out when it is empty', function (): void {
        $format = new ResponseFormat(ChatCompletions::trip()->schema, 'trip');

        expect(array_keys(ChatCompletions::bodyFor(ChatCompletions::request()->withResponseFormat($format))['response_format']['json_schema']))->toBe(['name', 'schema']);
    });
});

describe('stream_usage and store_field', function (): void {
    it('asks a stream for its usage, or does not', function (array $options, bool $asked): void {
        $stream = ChatCompletions::bodyFor(ChatCompletions::request(), $options, true);

        expect($stream['stream'])->toBeTrue()
            ->and($stream['stream_options'] ?? null)->toBe($asked ? ['include_usage' => true] : null)
            // A request that is no stream never holds either field.
            ->and(array_keys(ChatCompletions::bodyFor(ChatCompletions::request(), $options)))->toBe(['model', 'messages']);
    })->with([
        'the default' => [[], true],
        'on_request' => [['stream_usage' => 'on_request'], true],
        'unasked' => [['stream_usage' => 'unasked'], false],
    ]);

    it('sends store: false, or no store at all', function (array $options, bool $sent): void {
        foreach ([false, true] as $stream) {
            $body = ChatCompletions::bodyFor(ChatCompletions::request(), $options, $stream);

            expect(array_key_exists('store', $body))->toBe($sent)
                ->and($body['store'] ?? null)->toBe($sent ? false : null);
        }
    })->with([
        'the default' => [[], false],
        'omit' => [['store_field' => 'omit'], false],
        'false, as a word' => [['store_field' => 'false'], true],
        'false, as a boolean' => [['store_field' => false], true],
    ]);
});

describe('inline_reasoning and finish_content_filter', function (): void {
    it('cuts one leading block of inline reasoning from the text of an answer, or keeps it', function (array $options, string $text): void {
        $response = ChatCompletions::answered(['role' => 'assistant', 'content' => "<think>\nThe user greets.\n</think>\n\nHello."]);

        expect(ChatCompletions::answerTo($response, $options)->text)->toBe($text);
    })->with([
        'the default' => [[], "<think>\nThe user greets.\n</think>\n\nHello."],
        'keep' => [['inline_reasoning' => 'keep'], "<think>\nThe user greets.\n</think>\n\nHello."],
        'strip' => [['inline_reasoning' => 'strip'], 'Hello.'],
    ]);

    it('reads the finish reason content_filter as withheld output, or as a reason that was not understood', function (array $options, FinishReason $reason): void {
        $response = ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hello.'], 'content_filter');

        expect(ChatCompletions::answerTo($response, $options)->finishReason)->toBe($reason);
    })->with([
        'the default' => [[], FinishReason::ContentFilter],
        'withheld' => [['finish_content_filter' => 'withheld'], FinishReason::ContentFilter],
        'unknown' => [['finish_content_filter' => 'unknown'], FinishReason::Other],
    ]);

    it('returns an answer without text as withheld only where content_filter means that', function (): void {
        $response = ChatCompletions::answered(['role' => 'assistant', 'content' => ''], 'content_filter');

        expect(ChatCompletions::answerTo($response)->finishReason)->toBe(FinishReason::ContentFilter)
            ->and(Talk::errorOf(fn() => ChatCompletions::answerTo($response, ['finish_content_filter' => 'unknown']))->reason)->toBe(ErrorReason::ProviderError);
    });
});

describe('reasoning_carrier', function (): void {
    it('takes its three values', function (array $options): void {
        expect(ChatCompletions::answerTo(ChatCompletions::answered(), $options)->text)->toBe('Hello.');
    })->with([
        'the default' => [[]],
        'none' => [['reasoning_carrier' => 'none']],
        'message_field' => [['reasoning_carrier' => 'message_field']],
        'content_entries' => [['reasoning_carrier' => 'content_entries']],
    ]);

    it('keeps what came with an answer only where the carrier says where it sits', function (array $options, bool $field, bool $entries): void {
        $beside = ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hello.', 'reasoning_content' => 'A thought.']);
        $inside = ChatCompletions::answered(['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'A thought.']]], ['type' => 'text', 'text' => 'Hello.']]]);

        expect(ChatCompletions::answerTo($beside, $options)->opaque !== null)->toBe($field)
            ->and(ChatCompletions::answerTo($inside, $options)->opaque !== null)->toBe($entries)
            ->and(ChatCompletions::answerTo($inside, $options)->text)->toBe('Hello.');
    })->with([
        'the default' => [[], false, false],
        'none' => [['reasoning_carrier' => 'none'], false, false],
        'message_field' => [['reasoning_carrier' => 'message_field'], true, false],
        'content_entries' => [['reasoning_carrier' => 'content_entries'], false, true],
    ]);
});

describe('a value outside its list', function (): void {
    $outside = [
        'max_tokens_field' => ['max_tokens_field', 'max_output_tokens'],
        'system_role' => ['system_role', 'user'],
        'system_messages' => ['system_messages', 'merged'],
        'assistant_content_without_text' => ['assistant_content_without_text', ''],
        'required_word' => ['required_word', 'must'],
        'named_tool_choice' => ['named_tool_choice', 'function'],
        'tool_strict' => ['tool_strict', 'true'],
        'tool_strict, as the boolean true' => ['tool_strict', true],
        'tool_result_name, as a word' => ['tool_result_name', 'true'],
        'tool_result_name, as a number' => ['tool_result_name', 1],
        'json_schema_strict' => ['json_schema_strict', 'true'],
        'strict_subset' => ['strict_subset', 'wide'],
        'json_schema_key' => ['json_schema_key', 'definition'],
        'stream_usage' => ['stream_usage', 'always'],
        'store_field' => ['store_field', 'true'],
        'store_field, as the boolean true' => ['store_field', true],
        'reasoning_carrier' => ['reasoning_carrier', 'header'],
        'inline_reasoning' => ['inline_reasoning', 'remove'],
        'finish_content_filter' => ['finish_content_filter', 'blocked'],
        'finish_content_filter, as a number' => ['finish_content_filter', 0],
        'models_path, without a slash' => ['models_path', 'models'],
        'models_path, on another host' => ['models_path', 'https://other.example.test/models'],
        'models_path, with a dot segment' => ['models_path', '/../models'],
        'models_path, with a query' => ['models_path', '/models?all=1'],
        'models_path, as a number' => ['models_path', 7],
        'model_id_field, empty' => ['model_id_field', ''],
        'model_id_field, as a boolean' => ['model_id_field', true],
        'a word in other letters' => ['system_role', 'System'],
    ];

    it('is not_configured for a call, and nothing is sent', function (string $option, bool|int|string $value): void {
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        $gateway = ChatCompletions::gateway($transport, [$option => $value]);
        $detail = 'the option ' . $option . ' of the preset has a value outside its list';

        $error = Talk::errorOf(fn() => $gateway->complete(ChatCompletions::request()));
        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe($detail)
            ->and($error->attempts)->toBe(0)
            ->and($error->traceId)->toBe('trace-1');

        $failed = Talk::failedIn(Talk::events($gateway->stream(ChatCompletions::request())));
        expect($failed->error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($failed->error->detail)->toBe($detail);

        expect(Talk::errorOf(fn() => $gateway->capabilities(ChatCompletions::MODEL))->reason)->toBe(ErrorReason::NotConfigured)
            ->and(Talk::errorOf(fn() => $gateway->models(10.0))->reason)->toBe(ErrorReason::NotConfigured)
            ->and($transport->requests())->toBe([]);
    })->with($outside);

    it('names the option and never the value', function (): void {
        $gateway = ChatCompletions::gateway(new FakeTransport(), ['system_role' => 'a-value-the-customer-typed']);

        expect(Talk::errorOf(fn() => $gateway->capabilities(ChatCompletions::MODEL))->getMessage())->not->toContain('a-value-the-customer-typed');
    });

    it('is told by the value object itself, which refuses to be built from it', function (): void {
        expect(Dialect::optionOutsideItsList(['system_role' => 'developer', 'store_field' => false]))->toBeNull()
            ->and(Dialect::optionOutsideItsList(['system_role' => 'developer', 'store_field' => 'yes']))->toBe('store_field')
            // The value of a capability option is the adapter base's to judge.
            ->and(Dialect::optionOutsideItsList(['structured_output' => 'sometimes']))->toBeNull()
            ->and(Dialect::hasAnUnknownOption(['structured_output' => 'sometimes', 'context_length' => 1, 'store_field' => 'yes']))->toBeFalse()
            ->and(Dialect::hasAnUnknownOption(['an_option_of_a_host' => 1]))->toBeTrue()
            ->and(fn() => new Dialect(['store_field' => 'yes']))->toThrow(InvalidArgumentException::class, 'store_field')
            ->and(fn() => new Dialect(['max_token_field' => 'max_tokens']))->toThrow(InvalidArgumentException::class);
    });

    it('is not_configured for the model list when the capability option of the list is outside its list', function (): void {
        $transport = new FakeTransport();
        $error = Talk::errorOf(fn() => ChatCompletions::gateway($transport, ['model_listing' => 'sometimes'])->models(10.0));

        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe('the option model_listing of the preset has a value outside its list')
            ->and($transport->requests())->toBe([]);
    });
});

describe('an option this adapter does not know', function (): void {
    it('is not_configured, and nothing is sent: a name written wrongly does not leave its option at the default', function (string $name): void {
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        $gateway = ChatCompletions::gateway($transport, [$name => 'max_tokens']);
        $detail = 'the preset has an adapter option this adapter does not know';

        $error = Talk::errorOf(fn() => $gateway->complete(ChatCompletions::request()));
        expect($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($error->detail)->toBe($detail)
            ->and($error->attempts)->toBe(0)
            ->and($error->getMessage())->not->toContain($name);

        expect(Talk::failedIn(Talk::events($gateway->stream(ChatCompletions::request())))->error->detail)->toBe($detail)
            ->and(Talk::errorOf(fn() => $gateway->capabilities(ChatCompletions::MODEL))->reason)->toBe(ErrorReason::NotConfigured)
            ->and(Talk::errorOf(fn() => $gateway->models(10.0))->reason)->toBe(ErrorReason::NotConfigured)
            ->and($transport->requests())->toBe([]);
    })->with([
        'a letter missing' => ['max_token_field'],
        'other letters' => ['Max_Tokens_Field'],
        'an option of another adapter' => ['api_version'],
        'a name nobody knows' => ['an_option_of_a_host'],
    ]);

    it('is found in a model override too, for the models the override fits', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        $gateway = ChatCompletions::gateway($transport, overrides: [new ModelOverride('example-odd', ['stream_usages' => 'unasked'])]);

        expect($gateway->complete(ChatCompletions::request())->text)->toBe('Hello.')
            ->and(Talk::errorOf(fn() => $gateway->capabilities('example-odd'))->reason)->toBe(ErrorReason::NotConfigured);
    });

    it('is none of the names of the adapter map: every dialect option and every capability option is known', function (): void {
        $known = [
            'max_tokens_field' => 'max_tokens', 'system_role' => 'developer', 'system_messages' => 'joined', 'assistant_content_without_text' => 'empty_string',
            'required_word' => 'any', 'named_tool_choice' => 'required_single', 'tool_strict' => 'false', 'tool_result_name' => true,
            'json_schema_strict' => 'when_fits', 'strict_subset' => 'narrow', 'json_schema_key' => 'schema_definition', 'stream_usage' => 'unasked',
            'store_field' => 'false', 'reasoning_carrier' => 'message_field', 'inline_reasoning' => 'strip', 'finish_content_filter' => 'unknown',
            'models_path' => '/instance/models', 'model_id_field' => 'technical_name',
            'tool_calling' => 'native', 'forced_tool_call' => 'emulated', 'parallel_tool_calls' => 'none', 'structured_output' => 'emulated',
            'tools_with_structured_output' => 'emulated', 'streaming' => 'emulated', 'model_listing' => 'none', 'temperature' => 'not_accepted', 'context_length' => 8192,
        ];

        expect(ChatCompletions::gateway(new FakeTransport(), $known)->capabilities(ChatCompletions::MODEL)->contextLength)->toBe(8192);
    });
});

describe('options per model', function (): void {
    it('reads every option as it is for the model of the request', function (): void {
        $overrides = [new ModelOverride('example-old-*', ['max_tokens_field' => 'max_tokens', 'system_role' => 'system', 'store_field' => 'omit'])];
        $options = ['system_role' => 'developer', 'store_field' => 'false'];
        $messages = [Message::system('Be brief.'), Message::user('Hi.')];

        $new = ChatCompletions::bodyFor(new Request('example-new-1', $messages, 30.0, maxOutputTokens: 9), $options, false, $overrides);
        $old = ChatCompletions::bodyFor(new Request('example-old-1', $messages, 30.0, maxOutputTokens: 9), $options, false, $overrides);

        expect($new['messages'][0]['role'])->toBe('developer')
            ->and($new['max_completion_tokens'])->toBe(9)
            ->and($new['store'])->toBeFalse()
            ->and($old['messages'][0]['role'])->toBe('system')
            ->and($old['max_tokens'])->toBe(9)
            ->and($old)->not->toHaveKey('store')
            ->and($old)->not->toHaveKey('max_completion_tokens');
    });

    it('fails only for the model whose override holds a value outside its list', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered());
        $gateway = ChatCompletions::gateway($transport, overrides: [new ModelOverride('example-odd', ['required_word' => 'must'])]);

        expect($gateway->complete(ChatCompletions::request())->text)->toBe('Hello.');

        $error = Talk::errorOf(fn() => $gateway->complete(new Request('example-odd', [Message::user('Hi.')], 30.0)));
        expect($error)->toBeInstanceOf(GatewayException::class)
            ->and($error->reason)->toBe(ErrorReason::NotConfigured)
            ->and($transport->requests())->toHaveCount(1);
    });
});

describe('the capabilities of the format', function (): void {
    it('are native for a preset that sets nothing, except tools with a response format', function (): void {
        $can = ChatCompletions::gateway(new FakeTransport())->capabilities('a-model-nobody-knows');

        expect($can->toolCalling)->toBe(Support::Native)
            ->and($can->forcedToolCall)->toBe(Support::Native)
            ->and($can->parallelToolCalls)->toBe(Support::Native)
            ->and($can->structuredOutput)->toBe(Support::Native)
            ->and($can->toolsWithStructuredOutput)->toBe(Support::Emulated)
            ->and($can->streaming)->toBe(Support::Native)
            ->and($can->modelListing)->toBe(Support::Native)
            ->and($can->temperature)->toBe(Support::Native)
            ->and($can->contextLength)->toBeNull();
    });

    it('are what the capability options of the preset say', function (): void {
        $can = ChatCompletions::gateway(new FakeTransport(), [
            'parallel_tool_calls' => 'none', 'structured_output' => 'emulated', 'streaming' => 'emulated', 'model_listing' => 'none',
            'temperature' => 'not_accepted', 'context_length' => 32000, 'tools_with_structured_output' => 'native',
        ])->capabilities(ChatCompletions::MODEL);

        expect($can->parallelToolCalls)->toBe(Support::None)
            ->and($can->structuredOutput)->toBe(Support::Emulated)
            ->and($can->toolsWithStructuredOutput)->toBe(Support::Native)
            ->and($can->streaming)->toBe(Support::Emulated)
            ->and($can->modelListing)->toBe(Support::None)
            ->and($can->temperature)->toBe(Support::None)
            ->and($can->contextLength)->toBe(32000);
    });
});
