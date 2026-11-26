<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Credentials;

use OrganiseYou\LlmGateway\Contract\Secret;

/**
 * A sign-in: the secret and how it is presented. Everything that is not secret (an address, a deployment, a
 * project, a region, a version) is a setting beside it, not a part of it.
 */
interface Credentials
{
    public function kind(): CredentialKind;

    /**
     * The secret values this sign-in sends, for the host to register with its redactor before the first request.
     * Asking for them sends nothing and asks no supplier: a token from a supplier is the host's own value, and the
     * host registers it where it makes it.
     *
     * @return list<Secret>
     */
    public function secrets(): array;
}
