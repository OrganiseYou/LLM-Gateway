<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use LogicException;
use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use SensitiveParameter;

/**
 * An adapter as it was written before the opaque part existed: it has no OPAQUE_FORMAT and never asks for a part.
 * It sends nothing; the tests only build it and call the helpers of the base on it. Not final: a test gives a
 * class that extends it a mark of its own.
 */
readonly class MarklessAdapter extends AbstractAdapter
{
    protected function modelCapabilities(string $model): Capabilities
    {
        return new Capabilities(Support::None, Support::None, Support::None, Support::None, Support::None, Support::Emulated, Support::None, null);
    }

    protected function completionRequest(Request $request, bool $stream): HttpRequest
    {
        throw new LogicException('This adapter sends nothing.');
    }

    protected function readCompletion(#[SensitiveParameter] HttpResponse $response, Request $request): Answer
    {
        throw new LogicException('This adapter reads nothing.');
    }

    protected function readStream(#[SensitiveParameter] iterable $events, Request $request): iterable
    {
        throw new LogicException('This adapter reads nothing.');
    }

    protected function modelsRequest(float $timeoutSeconds): ?HttpRequest
    {
        return null;
    }

    protected function readModels(#[SensitiveParameter] HttpResponse $response): array
    {
        return [];
    }
}
