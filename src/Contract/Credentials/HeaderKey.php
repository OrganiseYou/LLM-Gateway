<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Credentials;

use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\Secret;
use SensitiveParameter;

/**
 * A key that is sent as the value of a header the preset names.
 */
final readonly class HeaderKey implements Credentials
{
    public Secret $key;

    /**
     * @param string $key the value of the header, as it is sent
     */
    public function __construct(#[SensitiveParameter] string $key)
    {
        if ($key === '') {
            throw new InvalidValue('The key must not be empty.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $key) === 1) {
            throw new InvalidValue('The key must not hold a control character, a line break or a tab.');
        }
        // A client trims the value of a header: the key that is sent would then differ from the one to redact.
        if (preg_match('/^[ \t]|[ \t]$/D', $key) === 1) {
            throw new InvalidValue('The key must not start or end with a space.');
        }

        $this->key = new Secret($key);
    }

    public function kind(): CredentialKind
    {
        return CredentialKind::HeaderKey;
    }

    public function secrets(): array
    {
        return [$this->key];
    }
}
