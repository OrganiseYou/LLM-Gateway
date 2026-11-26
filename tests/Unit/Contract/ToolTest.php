<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Contract\ToolChoiceMode;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;

function toolSchema(): JsonSchema
{
    return JsonSchema::fromArray(['type' => 'object', 'properties' => []]);
}

dataset('invalid names', [
    'empty' => [''],
    'a space' => ['get weather'],
    'a tab' => ["get\tweather"],
    'a line break' => ["get\nweather"],
    'a no-break space' => ["get\u{00A0}weather"],
    'a line separator' => ["get\u{2028}weather"],
    'a null byte' => ["get\0weather"],
    'a delete character' => ["get\x7Fweather"],
    'not valid UTF-8' => ["get\xE9"],
]);

describe('ToolDefinition', function (): void {
    it('keeps its fields', function (): void {
        $schema = toolSchema();
        $tool = new ToolDefinition('get_weather', 'Looks up the weather.', $schema);

        expect($tool->name)->toBe('get_weather')
            ->and($tool->description)->toBe('Looks up the weather.')
            ->and($tool->parameters)->toBe($schema);
    });

    it('has no length limit on the name and allows an empty description', function (): void {
        $tool = new ToolDefinition(str_repeat('n', 500), '', toolSchema());

        expect(strlen($tool->name))->toBe(500);
    });

    it('throws when the name is empty or holds whitespace or a control character', function (string $name): void {
        new ToolDefinition($name, '', toolSchema());
    })->with('invalid names')->throws(InvalidArgumentException::class);

    it('throws when the description is not valid UTF-8', function (): void {
        new ToolDefinition('get_weather', "caf\xE9", toolSchema());
    })->throws(InvalidArgumentException::class);
});

describe('ToolCall', function (): void {
    it('holds decoded arguments', function (): void {
        $call = ToolCall::decoded('call_0', 'get_weather', ['city' => 'Utrecht'], '{"city":"Utrecht"}');

        expect($call->id)->toBe('call_0')
            ->and($call->name)->toBe('get_weather')
            ->and($call->arguments)->toBe(['city' => 'Utrecht'])
            ->and($call->argumentsError)->toBeNull()
            ->and($call->rawArguments)->toBe('{"city":"Utrecht"}')
            ->and($call->isReadable())->toBeTrue();
    });

    it('holds unreadable arguments as a value, not an exception', function (): void {
        $call = ToolCall::unreadable('call_0', 'get_weather', 'the arguments are not JSON', '{"city":');

        expect($call->arguments)->toBeNull()
            ->and($call->argumentsError)->toBe('the arguments are not JSON')
            ->and($call->rawArguments)->toBe('{"city":')
            ->and($call->isReadable())->toBeFalse();
    });

    it('allows empty arguments, which are an empty object', function (): void {
        expect(ToolCall::decoded('call_0', 'ping', [], '{}')->isReadable())->toBeTrue();
    });

    it('does not check the name, which a model may garble', function (string $name): void {
        expect(ToolCall::decoded('call_0', $name, [], '{}')->name)->toBe($name);
    })->with(['empty' => [''], 'with a space' => ['get weather'], 'with a line break' => ["get\nweather"]]);

    it('rule 1: throws when the id is empty', function (): void {
        ToolCall::decoded('', 'get_weather', [], '{}');
    })->throws(InvalidArgumentException::class);

    it('rule 2: throws when arguments and argumentsError are both null', function (): void {
        new ToolCall('call_0', 'get_weather', null, null, '');
    })->throws(InvalidArgumentException::class);

    it('rule 2: throws when arguments and argumentsError are both given', function (): void {
        new ToolCall('call_0', 'get_weather', ['city' => 'Utrecht'], 'unreadable', '{"city":"Utrecht"}');
    })->throws(InvalidArgumentException::class);

    it('rule 3: throws when argumentsError is an empty string', function (): void {
        ToolCall::unreadable('call_0', 'get_weather', '', '{"city":');
    })->throws(InvalidArgumentException::class);

    it('rule 4: throws when arguments is a non-empty list', function (): void {
        ToolCall::decoded('call_0', 'get_weather', untyped(['Utrecht']), '["Utrecht"]');
    })->throws(InvalidArgumentException::class);
});

describe('ToolChoice', function (): void {
    it('has the four modes', function (): void {
        expect(array_map(fn(ToolChoiceMode $mode): string => $mode->value, ToolChoiceMode::cases()))
            ->toBe(['auto', 'none', 'required', 'tool']);
    });

    it('builds each mode through its named constructor', function (): void {
        expect(ToolChoice::auto())->toEqual(new ToolChoice(ToolChoiceMode::Auto))
            ->and(ToolChoice::none())->toEqual(new ToolChoice(ToolChoiceMode::None))
            ->and(ToolChoice::required())->toEqual(new ToolChoice(ToolChoiceMode::Required))
            ->and(ToolChoice::tool('get_weather'))->toEqual(new ToolChoice(ToolChoiceMode::Tool, 'get_weather'))
            ->and(ToolChoice::auto()->toolName)->toBeNull();
    });

    it('throws for mode tool with a null or empty tool name', function (?string $toolName): void {
        new ToolChoice(ToolChoiceMode::Tool, $toolName);
    })->with(['null' => [null], 'empty' => ['']])->throws(InvalidArgumentException::class);

    it('throws for any other mode with a tool name', function (ToolChoiceMode $mode): void {
        new ToolChoice($mode, 'get_weather');
    })->with([ToolChoiceMode::Auto, ToolChoiceMode::None, ToolChoiceMode::Required])->throws(InvalidArgumentException::class);
});

describe('ResponseFormat', function (): void {
    it('defaults the name to answer and the description to empty', function (): void {
        $schema = toolSchema();
        $format = new ResponseFormat($schema);

        expect($format->schema)->toBe($schema)
            ->and($format->name)->toBe('answer')
            ->and($format->description)->toBe('');
    });

    it('keeps a given name and description', function (): void {
        $format = new ResponseFormat(toolSchema(), 'invoice', 'The fields of an invoice.');

        expect($format->name)->toBe('invoice')
            ->and($format->description)->toBe('The fields of an invoice.');
    });

    it('throws when the name is empty or holds whitespace or a control character', function (string $name): void {
        new ResponseFormat(toolSchema(), $name);
    })->with('invalid names')->throws(InvalidArgumentException::class);

    it('throws when the description is not valid UTF-8', function (): void {
        new ResponseFormat(toolSchema(), 'answer', "caf\xE9");
    })->throws(InvalidArgumentException::class);
});
