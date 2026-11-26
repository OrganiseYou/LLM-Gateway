<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Contract\ToolChoiceMode;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;

function requestTool(string $name = 'get_weather'): ToolDefinition
{
    return new ToolDefinition($name, '', JsonSchema::fromArray(['type' => 'object']));
}

/**
 * @param array<string, mixed> $overrides
 */
function makeRequest(array $overrides = []): Request
{
    return new Request(...array_merge([
        'model' => 'model-a',
        'messages' => [Message::user('hello')],
        'timeoutSeconds' => 30.0,
    ], $overrides));
}

describe('Request', function (): void {
    it('keeps the required fields and the defaults', function (): void {
        $messages = [Message::system('be brief'), Message::user('hello')];
        $request = new Request('model-a', $messages, 30.0);

        expect($request->model)->toBe('model-a')
            ->and($request->messages)->toBe($messages)
            ->and($request->timeoutSeconds)->toBe(30.0)
            ->and($request->tools)->toBe([])
            ->and($request->toolChoice)->toEqual(new ToolChoice(ToolChoiceMode::Auto))
            ->and($request->responseFormat)->toBeNull()
            ->and($request->maxOutputTokens)->toBeNull()
            ->and($request->temperature)->toBeNull()
            ->and($request->traceId)->toBeNull();
    });

    it('keeps every optional field', function (): void {
        $tool = requestTool();
        $format = new ResponseFormat(JsonSchema::fromArray(['type' => 'object']));
        $request = new Request(
            'model-a',
            [Message::user('hello')],
            12.5,
            [$tool],
            ToolChoice::tool('get_weather'),
            $format,
            256,
            0.4,
            'trace-1',
        );

        expect($request->tools)->toBe([$tool])
            ->and($request->toolChoice->toolName)->toBe('get_weather')
            ->and($request->responseFormat)->toBe($format)
            ->and($request->maxOutputTokens)->toBe(256)
            ->and($request->temperature)->toBe(0.4)
            ->and($request->traceId)->toBe('trace-1');
    });

    it('model: allows a slash, a colon and a dot', function (): void {
        expect(makeRequest(['model' => 'group/model-a:2.5'])->model)->toBe('group/model-a:2.5');
    });

    it('model: throws when empty or when it holds a control character', function (string $model): void {
        makeRequest(['model' => $model]);
    })->with([
        'empty' => [''],
        'a line break' => ["model\na"],
        'a null byte' => ["model\0a"],
        'not valid UTF-8' => ["model\xE9"],
    ])->throws(InvalidArgumentException::class);

    it('messages: throws when empty', function (): void {
        makeRequest(['messages' => []]);
    })->throws(InvalidArgumentException::class);

    it('messages: throws when not a list', function (): void {
        makeRequest(['messages' => ['first' => Message::user('hello')]]);
    })->throws(InvalidArgumentException::class);

    it('messages: throws when it holds a value that is not a message', function (): void {
        makeRequest(['messages' => [Message::user('hello'), 'hello']]);
    })->throws(InvalidArgumentException::class);

    it('messages: throws when a system message comes after a message of another role', function (): void {
        makeRequest(['messages' => [Message::system('a'), Message::user('hello'), Message::system('b')]]);
    })->throws(InvalidArgumentException::class);

    it('messages: allows several leading system messages', function (): void {
        $request = makeRequest(['messages' => [Message::system('a'), Message::system('b'), Message::user('hello')]]);

        expect($request->messages)->toHaveCount(3);
    });

    it('timeoutSeconds: throws when not finite or not above 0', function (float $timeout): void {
        makeRequest(['timeoutSeconds' => $timeout]);
    })->with([
        'zero' => [0.0],
        'negative' => [-1.0],
        'infinite' => [INF],
        'not a number' => [NAN],
    ])->throws(InvalidArgumentException::class);

    it('tools: throws when not a list', function (): void {
        makeRequest(['tools' => ['first' => requestTool()]]);
    })->throws(InvalidArgumentException::class);

    it('tools: throws when it holds a value that is not a tool definition', function (): void {
        makeRequest(['tools' => [requestTool(), 'get_time']]);
    })->throws(InvalidArgumentException::class);

    it('tools: throws when two tools have the same name', function (): void {
        makeRequest(['tools' => [requestTool('get_weather'), requestTool('get_weather')]]);
    })->throws(InvalidArgumentException::class);

    it('toolChoice: throws for mode required or tool while tools is empty', function (ToolChoice $choice): void {
        makeRequest(['toolChoice' => $choice]);
    })->with([
        'required' => [ToolChoice::required()],
        'tool' => [ToolChoice::tool('get_weather')],
    ])->throws(InvalidArgumentException::class);

    it('toolChoice: throws for mode tool naming a tool that is not in tools', function (): void {
        makeRequest(['tools' => [requestTool('get_weather')], 'toolChoice' => ToolChoice::tool('get_time')]);
    })->throws(InvalidArgumentException::class);

    it('toolChoice: allows auto and none without tools, and required and tool with them', function (): void {
        expect(makeRequest(['toolChoice' => ToolChoice::auto()])->toolChoice->mode)->toBe(ToolChoiceMode::Auto)
            ->and(makeRequest(['toolChoice' => ToolChoice::none()])->toolChoice->mode)->toBe(ToolChoiceMode::None)
            ->and(makeRequest(['tools' => [requestTool()], 'toolChoice' => ToolChoice::required()])->toolChoice->mode)
            ->toBe(ToolChoiceMode::Required)
            ->and(makeRequest(['tools' => [requestTool()], 'toolChoice' => ToolChoice::tool('get_weather')])->toolChoice->mode)
            ->toBe(ToolChoiceMode::Tool);
    });

    it('responseFormat: throws when its name equals the name of a tool', function (): void {
        makeRequest([
            'tools' => [requestTool('answer')],
            'responseFormat' => new ResponseFormat(JsonSchema::fromArray(['type' => 'object'])),
        ]);
    })->throws(InvalidArgumentException::class);

    it('responseFormat: may be given together with tools', function (): void {
        $request = makeRequest([
            'tools' => [requestTool('get_weather')],
            'responseFormat' => new ResponseFormat(JsonSchema::fromArray(['type' => 'object'])),
        ]);

        expect($request->responseFormat?->name)->toBe('answer');
    });

    it('maxOutputTokens: throws below 1', function (int $tokens): void {
        makeRequest(['maxOutputTokens' => $tokens]);
    })->with([0, -5])->throws(InvalidArgumentException::class);

    it('maxOutputTokens: allows 1', function (): void {
        expect(makeRequest(['maxOutputTokens' => 1])->maxOutputTokens)->toBe(1);
    });

    it('temperature: throws when not finite, below 0.0 or above 1.0', function (float $temperature): void {
        makeRequest(['temperature' => $temperature]);
    })->with([
        'below 0' => [-0.1],
        'above 1' => [1.1],
        'infinite' => [INF],
        'not a number' => [NAN],
    ])->throws(InvalidArgumentException::class);

    it('temperature: allows both ends of the range', function (float $temperature): void {
        expect(makeRequest(['temperature' => $temperature])->temperature)->toBe($temperature);
    })->with([0.0, 1.0]);

    it('traceId: throws when it does not match the pattern', function (string $traceId): void {
        makeRequest(['traceId' => $traceId]);
    })->with([
        'empty' => [''],
        'a space' => ['trace 1'],
        'a slash' => ['trace/1'],
        'too long' => [str_repeat('a', 129)],
        'a trailing line break' => ["trace-1\n"],
    ])->throws(InvalidArgumentException::class);

    it('traceId: allows the pattern at its full length', function (): void {
        $traceId = 'Az09_.:-' . str_repeat('a', 120);

        expect(makeRequest(['traceId' => $traceId])->traceId)->toBe($traceId);
    });
});

function fullRequest(): Request
{
    return new Request(
        'model-a',
        [Message::system('be brief'), Message::user('hello')],
        12.5,
        [requestTool('get_weather'), requestTool('get_time')],
        ToolChoice::tool('get_weather'),
        new ResponseFormat(JsonSchema::fromArray(['type' => 'object'])),
        64,
        0.2,
        'trace-1',
    );
}

/**
 * @param list<string> $changed the fields the wither is meant to change
 */
function expectOtherFieldsCarriedOver(Request $before, Request $after, array $changed): void
{
    $fields = array_keys(get_object_vars($before));

    expect($fields)->toHaveCount(9)
        ->and($after)->not->toBe($before);

    foreach (array_diff($fields, $changed) as $field) {
        expect($after->{$field})->toBe($before->{$field}, $field . ' must be carried over');
    }
    foreach ($changed as $field) {
        expect($after->{$field})->not->toBe($before->{$field}, $field . ' must be changed');
    }
}

describe('Request withers', function (): void {
    it('withMessages changes the messages and carries every other field over', function (): void {
        $request = fullRequest();
        $messages = [Message::user('hello'), Message::assistant('hi')];
        $changed = $request->withMessages($messages);

        expect($changed->messages)->toBe($messages)
            ->and($request->messages)->toHaveCount(2);
        expectOtherFieldsCarriedOver($request, $changed, ['messages']);
    });

    it('withMessages validates', function (): void {
        makeRequest()->withMessages([]);
    })->throws(InvalidArgumentException::class);

    it('withTools changes the tools and carries every other field over', function (): void {
        $request = fullRequest();
        $tools = [requestTool('get_weather')];
        $changed = $request->withTools($tools);

        expect($changed->tools)->toBe($tools)
            ->and($request->tools)->toHaveCount(2);
        expectOtherFieldsCarriedOver($request, $changed, ['tools']);
    });

    it('withTools validates', function (): void {
        makeRequest(['tools' => [requestTool()], 'toolChoice' => ToolChoice::required()])->withTools([]);
    })->throws(InvalidArgumentException::class);

    it('withToolChoice changes the tool choice and carries every other field over', function (): void {
        $request = fullRequest();
        $choice = ToolChoice::required();
        $changed = $request->withToolChoice($choice);

        expect($changed->toolChoice)->toBe($choice)
            ->and($request->toolChoice->mode)->toBe(ToolChoiceMode::Tool);
        expectOtherFieldsCarriedOver($request, $changed, ['toolChoice']);
    });

    it('withToolChoice validates', function (): void {
        makeRequest()->withToolChoice(ToolChoice::required());
    })->throws(InvalidArgumentException::class);

    it('withResponseFormat changes the response format and carries every other field over', function (): void {
        $request = fullRequest();
        $format = new ResponseFormat(JsonSchema::fromArray(['type' => 'object']), 'invoice');
        $changed = $request->withResponseFormat($format);

        expect($changed->responseFormat)->toBe($format)
            ->and($request->responseFormat?->name)->toBe('answer');
        expectOtherFieldsCarriedOver($request, $changed, ['responseFormat']);
    });

    it('withResponseFormat clears the response format and carries every other field over', function (): void {
        $request = fullRequest();
        $changed = $request->withResponseFormat(null);

        expect($changed->responseFormat)->toBeNull();
        expectOtherFieldsCarriedOver($request, $changed, ['responseFormat']);
    });

    it('withResponseFormat validates', function (): void {
        makeRequest(['tools' => [requestTool('answer')]])
            ->withResponseFormat(new ResponseFormat(JsonSchema::fromArray(['type' => 'object'])));
    })->throws(InvalidArgumentException::class);
});
