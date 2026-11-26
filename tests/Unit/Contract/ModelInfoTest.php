<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\ModelInfo;

describe('ModelInfo', function (): void {
    it('keeps every field', function (): void {
        $model = new ModelInfo('model-a-2026', 'Model A', 200000, ['model-a', 'model-a-latest'], true);

        expect($model->id)->toBe('model-a-2026')
            ->and($model->name)->toBe('Model A')
            ->and($model->contextLength)->toBe(200000)
            ->and($model->aliases)->toBe(['model-a', 'model-a-latest'])
            ->and($model->deprecated)->toBeTrue();
    });

    it('defaults to no name, no context length, no aliases and not deprecated', function (): void {
        $model = new ModelInfo('model-a');

        expect($model->name)->toBeNull()
            ->and($model->contextLength)->toBeNull()
            ->and($model->aliases)->toBe([])
            ->and($model->deprecated)->toBeFalse();
    });

    it('accepts an id with a slash, a colon and a dot', function (string $id): void {
        expect((new ModelInfo($id))->id)->toBe($id);
    })->with(['vendor/model-a', 'model-a:free', 'model-a-1.5', 'modèle-a']);

    it('throws when the id breaks the model-name rule', function (string $id): void {
        new ModelInfo($id);
    })->with([
        'empty' => [''],
        'a line break' => ["model-a\n"],
        'a tab' => ["model\ta"],
        'a null byte' => ["model\0a"],
        'invalid UTF-8' => ["model\xff"],
    ])->throws(InvalidArgumentException::class);

    it('throws when the name is an empty string', function (): void {
        new ModelInfo('model-a', '');
    })->throws(InvalidArgumentException::class);

    it('throws when the name holds a control character or is not valid UTF-8', function (string $name): void {
        new ModelInfo('model-a', $name);
    })->with([
        'a line break' => ["Model\nA"],
        'a tab' => ["Model\tA"],
        'a null byte' => ["Model\0A"],
        'invalid UTF-8' => ["Model\xff"],
    ])->throws(InvalidArgumentException::class);

    it('accepts a name with spaces and letters outside ASCII', function (): void {
        expect((new ModelInfo('model-a', 'Modèle A (2026)'))->name)->toBe('Modèle A (2026)');
    });

    it('accepts a context length of 1', function (): void {
        expect((new ModelInfo('model-a', contextLength: 1))->contextLength)->toBe(1);
    });

    it('throws when contextLength is below 1', function (int $contextLength): void {
        new ModelInfo('model-a', contextLength: $contextLength);
    })->with([0, -1])->throws(InvalidArgumentException::class);

    it('throws when an alias is empty', function (): void {
        new ModelInfo('model-a', aliases: ['model-a-latest', '']);
    })->throws(InvalidArgumentException::class);

    it('throws when an alias breaks the model-name rule', function (string $alias): void {
        new ModelInfo('model-a', aliases: ['model-a-latest', $alias]);
    })->with([
        'a line break' => ["model-a\n"],
        'a tab' => ["model\ta"],
        'a null byte' => ["model\0a"],
        'invalid UTF-8' => ["model\xff"],
    ])->throws(InvalidArgumentException::class);

    it('accepts an alias with a slash, a colon and a dot', function (): void {
        $aliases = ['vendor/model-a', 'model-a:free', 'model-a-1.5'];

        expect((new ModelInfo('model-a', aliases: $aliases))->aliases)->toBe($aliases);
    });

    it('throws when an alias is not a string', function (mixed $alias): void {
        new ModelInfo('model-a', aliases: untyped(['model-a-latest', $alias]));
    })->with([
        'an integer' => [1],
        'null' => [null],
        'a list' => [['model-a']],
    ])->throws(InvalidArgumentException::class);

    it('throws when the aliases are not a list', function (): void {
        new ModelInfo('model-a', aliases: untyped(['first' => 'model-a-latest']));
    })->throws(InvalidArgumentException::class);
});
