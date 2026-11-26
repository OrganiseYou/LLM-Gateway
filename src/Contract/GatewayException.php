<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use Exception;
use InvalidArgumentException;
use JsonSerializable;
use ReflectionProperty;
use RuntimeException;
use SensitiveParameter;

/**
 * The one error of a gateway call, with a fixed reason.
 *
 * Nothing that came from outside the library can reach its text: the message is built here, there is no previous
 * exception, and the trace keeps no argument of any call.
 */
final class GatewayException extends RuntimeException implements JsonSerializable
{
    /** The provider's own code, lower-cased, when it is a plain word; otherwise null. */
    public readonly ?string $providerCode;

    /**
     * @param int|null $httpStatus null when no response arrived or no request was sent
     * @param string|null $providerCode kept only when, lower-cased, it matches ^[a-z0-9_.-]{1,64}$
     * @param int|null $retryAfterSeconds only with the reasons rate_limited and unavailable
     * @param int $attempts HTTP attempts made; 0 when the failure came before any request
     * @param string|null $detail a sentence written by the library: never text of a response, a header value or a URL
     */
    public function __construct(
        public readonly ErrorReason $reason,
        public readonly string $traceId,
        public readonly ?int $httpStatus = null,
        #[SensitiveParameter]
        ?string $providerCode = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly int $attempts = 0,
        #[SensitiveParameter]
        public readonly ?string $detail = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D', $traceId) !== 1) {
            throw new InvalidArgumentException('The trace id must match ^[A-Za-z0-9_.:-]{1,128}$.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('The HTTP status must be between 100 and 599.');
        }
        if ($retryAfterSeconds !== null && $retryAfterSeconds < 0) {
            throw new InvalidArgumentException('The seconds to wait must be 0 or more.');
        }
        if ($retryAfterSeconds !== null && $reason !== ErrorReason::RateLimited && $reason !== ErrorReason::Unavailable) {
            throw new InvalidArgumentException(
                'The seconds to wait go only with the reasons rate_limited and unavailable.',
            );
        }
        if ($attempts < 0) {
            throw new InvalidArgumentException('The attempts must be 0 or more.');
        }
        if ($detail !== null) {
            self::assertDetail($detail);
        }

        $this->providerCode = self::plainCode($providerCode);

        parent::__construct(self::buildMessage($reason, $httpStatus, $this->providerCode, $detail));

        $this->dropTraceArguments();
    }

    /**
     * @return array{reason: string, trace_id: string, http_status: ?int, provider_code: ?string, retry_after_seconds: ?int, attempts: int, detail: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'reason' => $this->reason->value,
            'trace_id' => $this->traceId,
            'http_status' => $this->httpStatus,
            'provider_code' => $this->providerCode,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'attempts' => $this->attempts,
            'detail' => $this->detail,
        ];
    }

    /**
     * One line of at most 200 characters, with something to read in it. The text itself is never in the refusal.
     */
    private static function assertDetail(#[SensitiveParameter] string $detail): void
    {
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $detail) !== 0) {
            throw new InvalidArgumentException(
                'The detail must be valid UTF-8 without control characters, line separators or format controls.',
            );
        }
        if (preg_match('/[^\p{Z}]/u', $detail) !== 1) {
            throw new InvalidArgumentException('The detail must not be empty or only whitespace.');
        }
        if (preg_match('/^.{1,200}$/Du', $detail) !== 1) {
            throw new InvalidArgumentException('The detail must be 200 characters or fewer.');
        }
    }

    private static function plainCode(#[SensitiveParameter] ?string $providerCode): ?string
    {
        if ($providerCode === null) {
            return null;
        }

        $lowered = strtolower($providerCode);

        return preg_match('/^[a-z0-9_.-]{1,64}$/D', $lowered) === 1 ? $lowered : null;
    }

    private static function buildMessage(ErrorReason $reason, ?int $httpStatus, ?string $providerCode, ?string $detail): string
    {
        $facts = [];
        if ($httpStatus !== null) {
            $facts[] = 'HTTP ' . $httpStatus;
        }
        if ($providerCode !== null) {
            $facts[] = 'code: ' . $providerCode;
        }

        $message = $reason->value;
        if ($facts !== []) {
            $message .= ' (' . implode(', ', $facts) . ')';
        }
        if ($detail !== null) {
            $message .= ': ' . $detail;
        }

        return $message;
    }

    /**
     * PHP stores the arguments of every call on the stack in the trace of an exception, unless the host switched
     * that off. A request body, a header or a client's exception that was an argument somewhere would then show in
     * print_r, var_export, serialize and, cut to 15 bytes, in the string form. The trace keeps where, not with what.
     */
    private function dropTraceArguments(): void
    {
        $trace = [];
        foreach ($this->getTrace() as $frame) {
            unset($frame['args']);
            $trace[] = $frame;
        }

        (new ReflectionProperty(Exception::class, 'trace'))->setValue($this, $trace);
    }
}
