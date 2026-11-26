<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Contract\Credentials\TokenSupplier;
use OrganiseYou\LlmGateway\Contract\Secret;
use RuntimeException;
use TypeError;

/**
 * A token supplier of a host, for tests: a new token every time it is asked, or a failure.
 */
final class CountingTokenSupplier implements TokenSupplier
{
    public int $asked = 0;

    /** @var 'exception'|'error'|null */
    public ?string $fails = null;

    /** From which time it is asked the supplier fails with an exception; null: never. */
    public ?int $failsFromAsk = null;

    /** What the next token is, when the test wants a certain one. */
    public ?string $next = null;

    public function token(): Secret
    {
        $this->asked++;

        if ($this->fails === 'exception' || ($this->failsFromAsk !== null && $this->asked >= $this->failsFromAsk)) {
            throw new RuntimeException('The identity service of Vendor Corp said no to ' . ($this->next ?? 'the secret'));
        }
        if ($this->fails === 'error') {
            throw new TypeError('A fault in the code of the host.');
        }

        return new Secret($this->next ?? 'token-' . $this->asked);
    }
}
