<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Testing\ContractFailure;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\NotApplicable;
use SensitiveParameter;

/**
 * A subject of the shared test set whose gateway breaks one rule on purpose; everything else is the sound subject.
 */
final class BrokenSubject implements ContractSubject
{
    private readonly BrokenGateway $gateway;

    /**
     * @param Capabilities|null $says the capabilities the broken gateway states, when not those of the sound one
     */
    public function __construct(private readonly ContractSubject $sound, string $fault, ?Capabilities $says = null)
    {
        $this->gateway = new BrokenGateway($sound->gateway(), $fault, $says);
    }

    public function gateway(): Gateway
    {
        return $this->gateway;
    }

    public function model(): string
    {
        return $this->sound->model();
    }

    public function makesAttempts(): bool
    {
        return $this->sound->makesAttempts();
    }

    public function gives(ContractFixture $fixture, bool $asStream): void
    {
        $this->sound->gives($fixture, $asStream);
    }

    public function cannotGive(ContractFixture $fixture, bool $asStream): ?string
    {
        return $this->sound->cannotGive($fixture, $asStream);
    }

    public function fails(ContractFailure $failure, ?int $retryAfterSeconds = null, bool $asStream = false, ?string $echo = null): void
    {
        $this->sound->fails($failure, $retryAfterSeconds, $asStream, $echo);
    }

    public function cannotPlay(ContractFailure $failure, bool $asStream): ?string
    {
        return $this->sound->cannotPlay($failure, $asStream);
    }

    public function requestItCannotCarry(Request $request): Request|NotApplicable
    {
        return $this->sound->requestItCannotCarry($request);
    }

    public function sentToTheProvider(string $text): bool
    {
        return $this->sound->sentToTheProvider($text);
    }

    public function breaksAfterTheFirstText(): void
    {
        $this->sound->breaksAfterTheFirstText();
    }

    public function delays(float $seconds): void
    {
        $this->sound->delays($seconds);
    }

    public function requestsReceived(): int
    {
        return $this->sound->requestsReceived();
    }

    public function waits(): array
    {
        return $this->sound->waits();
    }

    public function timeoutsGiven(): array
    {
        return $this->sound->timeoutsGiven();
    }

    public function signedInWith(#[SensitiveParameter] string $key): bool
    {
        return $this->sound->signedInWith($key);
    }

    public function logged(): array
    {
        return $this->sound->logged();
    }
}
