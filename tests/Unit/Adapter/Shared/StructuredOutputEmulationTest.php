<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\StructuredOutputEmulation;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;

describe('the instructions for a model without structured output', function (): void {
    it('are this fixed text, followed by the schema as JSON on one line', function (): void {
        $format = new ResponseFormat(JsonSchema::fromArray([
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ]), 'verdict');

        expect((new StructuredOutputEmulation())->instructions($format))->toBe(
            'Answer with exactly one JSON object that is valid against the JSON Schema below. '
            . 'Give only that JSON object: no text before or after it, no explanation and no Markdown code fence.'
            . "\n\n"
            . "JSON Schema:\n"
            . '{"type":"object","properties":{"city":{"type":"string"}},"required":["city"]}',
        );
    });

    it('hold the description of the response format between the fixed text and the schema, when the caller gave one', function (): void {
        $format = new ResponseFormat(JsonSchema::fromArray(['type' => 'object']), 'verdict', "  The city the question is about.\nOne city only.\n");

        expect((new StructuredOutputEmulation())->instructions($format))->toBe(
            'Answer with exactly one JSON object that is valid against the JSON Schema below. '
            . 'Give only that JSON object: no text before or after it, no explanation and no Markdown code fence.'
            . "\n\n"
            . "What the JSON object is for:\n"
            . "The city the question is about.\nOne city only."
            . "\n\n"
            . "JSON Schema:\n"
            . '{"type":"object"}',
        );
    });

    it('leave the description out when it is empty or only whitespace', function (): void {
        $schema = JsonSchema::fromArray(['type' => 'object']);
        $emulation = new StructuredOutputEmulation();

        $text = $emulation->instructions(new ResponseFormat($schema, 'verdict', " \n\t "));

        expect($text)->toBe($emulation->instructions(new ResponseFormat($schema, 'verdict')))
            ->and($text)->not->toContain('What the JSON object is for');
    });

    it('carry the schema unchanged: an empty object stays an object, a slash and a letter with an accent stay as written', function (): void {
        $format = new ResponseFormat(JsonSchema::fromArray([
            'type' => 'object',
            'properties' => ['café/bar' => []],
        ]));

        expect((new StructuredOutputEmulation())->instructions($format))
            ->toEndWith("JSON Schema:\n" . '{"type":"object","properties":{"café/bar":{}}}');
    });

    it('do not hold the name of the response format, and are the same every time', function (): void {
        $schema = JsonSchema::fromArray(['type' => 'object']);
        $emulation = new StructuredOutputEmulation();

        $text = $emulation->instructions(new ResponseFormat($schema, 'zebra', 'What it is for.'));

        expect($text)->toBe($emulation->instructions(new ResponseFormat($schema, 'two', 'What it is for.')))
            ->and($text)->toBe((new StructuredOutputEmulation())->instructions(new ResponseFormat($schema, 'answer', 'What it is for.')))
            ->and($text)->not->toContain('zebra');
    });

    it('come from a class that is final and keeps nothing', function (): void {
        $reflection = new ReflectionClass(StructuredOutputEmulation::class);

        expect($reflection->isFinal())->toBeTrue()
            ->and($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->getProperties())->toBe([])
            ->and($reflection->getConstructor())->toBeNull();
    });
});
