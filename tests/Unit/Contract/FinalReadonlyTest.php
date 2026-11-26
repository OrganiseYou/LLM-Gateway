<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ContentPart;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\StructuredIssue;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Contract\StructuredResult;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\TextPart;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoice;
use OrganiseYou\LlmGateway\Contract\ToolChoiceMode;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Contract\Usage;

it('has only final readonly value classes', function (): void {
    $classes = [
        TextPart::class,
        Message::class,
        JsonSchema::class,
        ToolDefinition::class,
        ToolCall::class,
        ToolChoice::class,
        ResponseFormat::class,
        Request::class,
        Usage::class,
        StructuredIssue::class,
        StructuredResult::class,
        Answer::class,
        Capabilities::class,
        ModelInfo::class,
    ];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue($class . ' must be final')
            ->and($reflection->isReadOnly())->toBeTrue($class . ' must be readonly');
    }
});

it('has only string-backed enums', function (): void {
    $enums = [Role::class, ToolChoiceMode::class, FinishReason::class, StructuredIssueKind::class, ErrorReason::class, Support::class];

    foreach ($enums as $enum) {
        $reflection = new ReflectionEnum($enum);

        expect($reflection->isBacked())->toBeTrue($enum . ' must be backed')
            ->and((string) $reflection->getBackingType())->toBe('string', $enum . ' must be backed by a string');
    }
});

it('has a marker interface for content parts', function (): void {
    $reflection = new ReflectionClass(ContentPart::class);

    expect($reflection->isInterface())->toBeTrue()
        ->and($reflection->getMethods())->toBe([]);
});

it('lets code outside the library build capabilities and model info', function (): void {
    foreach ([Capabilities::class, ModelInfo::class] as $class) {
        $constructor = (new ReflectionClass($class))->getConstructor();

        expect($constructor?->isPublic())->toBeTrue($class . ' must have a public constructor');
    }
});
