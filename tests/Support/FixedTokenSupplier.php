<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplier;
use OrganiseYou\LlmGateway\Contract\Secret;
use SensitiveParameter;

/**
 * A token supplier of a host, for tests: the same token every time it is asked, and a count of how often it was.
 */
final class FixedTokenSupplier implements TokenSupplier
{
    public int $asked = 0;

    private readonly Secret $token;

    public function __construct(#[SensitiveParameter] string $token)
    {
        $this->token = new Secret($token);
    }

    public function token(): Secret
    {
        $this->asked++;

        return $this->token;
    }
}
