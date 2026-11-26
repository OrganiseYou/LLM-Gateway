<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\SseEvent;
use OrganiseYou\LlmGateway\Adapter\Shared\SseReader;
use OrganiseYou\LlmGateway\Adapter\Shared\StructuredAnswerChecker;
use OrganiseYou\LlmGateway\Adapter\Shared\StructuredOutputEmulation;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Preset\PresetField;

it('has only final readonly classes for the configuration, the preset, the event reader and the structured check', function (): void {
    $classes = [
        GatewayConfig::class, Preset::class, PresetField::class, SseEvent::class, SseReader::class,
        StructuredAnswerChecker::class, StructuredOutputEmulation::class,
    ];

    foreach ($classes as $class) {
        $reflection = new ReflectionClass($class);

        expect($reflection->isFinal())->toBeTrue($class . ' must be final')
            ->and($reflection->isReadOnly())->toBeTrue($class . ' must be readonly')
            ->and($reflection->getStaticProperties())->toBe([], $class . ' must not keep anything between calls');
    }
});

it('has a final factory', function (): void {
    expect((new ReflectionClass(GatewayFactory::class))->isFinal())->toBeTrue();
});

it('has a string-backed enum for the base URL rule', function (): void {
    $reflection = new ReflectionEnum(BaseUrlRule::class);

    expect($reflection->isBacked())->toBeTrue()
        ->and((string) $reflection->getBackingType())->toBe('string');
});

it('has one interface with the five operations and nothing else', function (): void {
    $reflection = new ReflectionClass(Gateway::class);
    $methods = [];
    foreach ($reflection->getMethods() as $method) {
        $methods[$method->getName()] = array_map(
            fn(ReflectionParameter $parameter): string => $parameter->getType() . ' $' . $parameter->getName(),
            $method->getParameters(),
        );
    }

    expect($reflection->isInterface())->toBeTrue()
        ->and($methods)->toBe([
            'complete' => ['OrganiseYou\LlmGateway\Contract\Request $request'],
            'stream' => ['OrganiseYou\LlmGateway\Contract\Request $request'],
            'models' => ['float $timeoutSeconds', '?string $traceId'],
            'capabilities' => ['string $model'],
            'check' => ['string $model', 'float $timeoutSeconds', '?string $traceId'],
        ]);
});

it('takes no credential, no conversation and no provider in any operation of the interface', function (): void {
    $types = [];
    foreach ((new ReflectionClass(Gateway::class))->getMethods() as $method) {
        foreach ($method->getParameters() as $parameter) {
            $types[] = (string) $parameter->getType();
        }
    }

    expect(implode(' ', $types))->not->toContain('Credentials')
        ->and(implode(' ', $types))->not->toContain('Preset');
});
