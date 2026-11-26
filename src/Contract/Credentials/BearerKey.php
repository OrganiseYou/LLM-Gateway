<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Credentials;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\Secret;
use SensitiveParameter;

/**
 * A key that is sent as a bearer token in the authorization header.
 */
final readonly class BearerKey implements Credentials
{
    public Secret $key;

    /**
     * @param string $key the key alone, without a scheme word before it
     */
    public function __construct(#[SensitiveParameter] string $key)
    {
        if ($key === '') {
            throw new InvalidArgumentException('The key must not be empty.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $key) === 1) {
            throw new InvalidArgumentException('The key must not hold a control character, a line break or a tab.');
        }
        // A client trims the value of a header: the key that is sent would then differ from the one to redact.
        if (preg_match('/^[ \t]|[ \t]$/D', $key) === 1) {
            throw new InvalidArgumentException('The key must not start or end with a space.');
        }

        $this->key = new Secret($key);
    }

    public function kind(): CredentialKind
    {
        return CredentialKind::BearerKey;
    }

    public function secrets(): array
    {
        return [$this->key];
    }
}
