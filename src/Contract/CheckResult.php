<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use SensitiveParameter;

/**
 * One answer of the connection check, with the reason and the provider's own code when the answer is "no".
 */
final readonly class CheckResult
{
    /** The provider's own code, lower-cased, when it is a plain word; otherwise null. */
    public ?string $providerCode;

    /**
     * @param ErrorReason|null $reason only with the status "no"
     * @param string|null $providerCode only with the status "no"; kept only when, lower-cased, it matches
     *                                  ^[a-z0-9_.-]{1,64}$
     */
    public function __construct(
        public CheckStatus $status,
        public ?ErrorReason $reason = null,
        #[SensitiveParameter]
        ?string $providerCode = null,
    ) {
        if ($reason !== null && $status !== CheckStatus::No) {
            throw new InvalidArgumentException('A reason goes only with the status no.');
        }
        if ($providerCode !== null && $status !== CheckStatus::No) {
            throw new InvalidArgumentException('A provider code goes only with the status no.');
        }

        $this->providerCode = Rules::plainProviderCode($providerCode);
    }
}
