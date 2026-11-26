<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Credentials;

/**
 * No sign-in at all: nothing is sent.
 */
final readonly class NoCredentials implements Credentials
{
    public function kind(): CredentialKind
    {
        return CredentialKind::None;
    }

    public function secrets(): array
    {
        return [];
    }
}
