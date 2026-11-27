<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\Usage;
use SensitiveParameter;

/**
 * The fake gateway under the shared test set: it supplies itself. What the provider "does" is scripted on the fake.
 * It makes no HTTP attempts, so the set skips the scenarios about retries, waits and time.
 */
final class FakeGatewaySubject implements ContractSubject
{
    private readonly FakeGateway $fake;

    private int $scripted = 0;

    /**
     * @param Capabilities|null $capabilities what the fake is told it can do; null: everything
     */
    public function __construct(private readonly ?Capabilities $capabilities = null)
    {
        $this->fake = new FakeGateway();
        if ($capabilities !== null) {
            $this->fake->withCapabilities($capabilities);
        }
    }

    public function gateway(): Gateway
    {
        return $this->fake;
    }

    public function model(): string
    {
        return 'model-a';
    }

    public function makesAttempts(): bool
    {
        return false;
    }

    public function gives(ContractFixture $fixture, bool $asStream): void
    {
        $answer = new Answer(
            (string) $fixture->text(),
            null,
            $fixture->toolCalls(),
            $fixture->finishReason(),
            'model-a-0001',
            new Usage(ContractFixture::INPUT_TOKENS, ContractFixture::OUTPUT_TOKENS),
            0,
            1,
            'scripted',
            // The fake makes no part of its own: this one is scripted, as a test of an application scripts it.
            $fixture === ContractFixture::ToolCallWithOpaqueData
                ? new OpaquePart('scripted data that goes back unchanged: ' . ContractFixture::OPAQUE_MARKER)
                : null,
        );

        $this->scripted++;
        if (! $asStream || $this->capabilities?->streaming === Support::Emulated) {
            $this->fake->willAnswer($answer);

            return;
        }

        $events = [];
        foreach ($answer->text === '' ? [] : str_split($answer->text, 3) as $piece) {
            $events[] = new TextDelta($piece);
        }
        foreach ($answer->toolCalls as $index => $toolCall) {
            $events[] = new ToolCallStarted($index, $toolCall->id, $toolCall->name);
            $events[] = new ToolCallCompleted($index, $toolCall);
        }
        $events[] = new UsageReported($answer->usage);
        $events[] = new StreamFinished($answer);

        $this->fake->willStream(...$events);
    }

    public function fails(ContractFailure $failure, ?int $retryAfterSeconds = null, bool $asStream = false, ?string $echo = null): void
    {
        $this->scripted++;
        $this->fake->willFail(new GatewayException($failure->reason(), 'scripted', $failure->httpStatus(), null, $retryAfterSeconds, 1));
    }

    public function breaksAfterTheFirstText(): void
    {
        $this->scripted++;
        $this->fake->willStream(
            new TextDelta('Hel'),
            new StreamFailed(new GatewayException(ErrorReason::Unavailable, 'scripted', 200, null, null, 1), 'Hel', []),
        );
    }

    public function cannotPlay(ContractFailure $failure, bool $asStream): ?string
    {
        // The fake reads no response: a failure is scripted with its reason, and nothing is ever tried again.
        return $failure === ContractFailure::RetryForbidden
            ? 'the fake gateway reads no response, so no response can tell it not to try again'
            : null;
    }

    public function requestItCannotCarry(Request $request): NotApplicable
    {
        return new NotApplicable('the fake gateway speaks no format: it takes every request the contract allows');
    }

    public function cannotGive(ContractFixture $fixture, bool $asStream): ?string
    {
        return null;
    }

    public function sentToTheProvider(string $text): bool
    {
        // The fake has no provider. What a request holds for a provider alone is the opaque part of a message.
        foreach ($this->fake->requests() as $request) {
            foreach ($request->messages as $message) {
                foreach ($message->parts as $part) {
                    if ($part instanceof OpaquePart && str_contains($part->data, $text)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function delays(float $seconds): void
    {
        // The fake has no clock: nothing takes time.
    }

    public function requestsReceived(): int
    {
        // The script is the provider of the fake: a request reached it when a scripted entry was used.
        return $this->scripted - $this->fake->remaining();
    }

    public function waits(): array
    {
        return [];
    }

    public function timeoutsGiven(): array
    {
        return [];
    }

    public function signedInWith(#[SensitiveParameter] string $key): bool
    {
        return false;
    }

    public function logged(): array
    {
        return [];
    }
}
