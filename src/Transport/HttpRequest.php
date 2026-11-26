<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\Secret;
use SensitiveParameter;

/**
 * One HTTP request, as the transport must send it.
 *
 * A secret travels only in the secret headers. toArray() is the form for reports and logs; allHeaders() is for the
 * transport alone.
 */
final readonly class HttpRequest
{
    /**
     * @param string $method GET or POST
     * @param string $url absolute, http or https, without user info and without a fragment
     * @param array<string, string> $headers
     * @param array<string, Secret> $secretHeaders headers whose value must never be shown
     * @param float $timeoutSeconds the time left for this attempt
     */
    public function __construct(
        public string $method,
        #[SensitiveParameter]
        public string $url,
        #[SensitiveParameter]
        public array $headers,
        #[SensitiveParameter]
        public array $secretHeaders,
        public float $timeoutSeconds,
        #[SensitiveParameter]
        public string $body = '',
    ) {
        if ($method !== 'GET' && $method !== 'POST') {
            throw new InvalidArgumentException('The method must be GET or POST.');
        }
        if (! Rules::isTimeout($timeoutSeconds)) {
            throw new InvalidArgumentException('The timeout must be a finite number of seconds above 0.');
        }

        self::assertUrl($url);

        $seen = self::assertHeaders($headers);
        self::assertSecretHeaders($secretHeaders, $seen);
    }

    /**
     * The headers with the secret ones revealed. For the transport only: nothing of it may be logged or stored.
     *
     * @return array<string, string>
     */
    public function allHeaders(): array
    {
        $all = $this->headers;
        foreach ($this->secretHeaders as $name => $secret) {
            $all[$name] = $secret->reveal();
        }

        return $all;
    }

    /**
     * The form for reports and logs: every secret header shows as "[secret]".
     *
     * @return array{method: string, url: string, headers: array<string, string>, body: string}
     */
    public function toArray(): array
    {
        $headers = $this->headers;
        foreach ($this->secretHeaders as $name => $secret) {
            $headers[$name] = (string) $secret;
        }

        return [
            'method' => $this->method,
            'url' => $this->url,
            'headers' => $headers,
            'body' => $this->body,
        ];
    }

    private static function assertUrl(#[SensitiveParameter] string $url): void
    {
        if (preg_match('/^[\x21-\x7e]*$/D', $url) !== 1 || str_contains($url, '\\')) {
            throw new InvalidArgumentException(
                'The URL must hold only visible ASCII characters, without a space or a backslash.',
            );
        }
        // The authority is everything up to the first slash, question mark or hash; the end is checked below.
        if (preg_match('/^https?:\/\/([^\/?#]*)/Di', $url, $match) !== 1) {
            throw new InvalidArgumentException('The URL must be absolute and start with http:// or https://.');
        }
        if (str_contains($match[1], '@')) {
            throw new InvalidArgumentException('The URL must not hold user info.');
        }
        if (str_contains($url, '#')) {
            throw new InvalidArgumentException('The URL must not hold a fragment.');
        }

        self::assertAuthority($match[1]);
    }

    /**
     * A host and an optional port, spelled so that every client reads the same host: no percent-encoding, no
     * second colon, no open bracket.
     */
    private static function assertAuthority(#[SensitiveParameter] string $authority): void
    {
        $refusal = 'The URL must hold a host of letters, digits, dots, hyphens and underscores, or an IPv6 address in brackets, '
            . 'with at most a port from 1 to 65535 after it.';

        if (preg_match('/^(?:([A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?)|\[([0-9A-Fa-f:.]+)\])(?::([0-9]{1,5}))?$/D', $authority, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new InvalidArgumentException($refusal);
        }
        if ($match[2] !== null && filter_var($match[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new InvalidArgumentException($refusal);
        }
        if (isset($match[3]) && ((int) $match[3] < 1 || (int) $match[3] > 65535)) {
            throw new InvalidArgumentException($refusal);
        }
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, true> the names seen, lower-cased
     */
    private static function assertHeaders(#[SensitiveParameter] array $headers): array
    {
        $seen = [];
        foreach ($headers as $name => $value) {
            $lowered = self::assertName($name, $seen);
            if (! is_string($value)) {
                throw new InvalidArgumentException('A header value must be a string.');
            }
            if (strpbrk($value, "\r\n") !== false) {
                throw new InvalidArgumentException('A header value must not hold a line break.');
            }
            $seen[$lowered] = true;
        }

        return $seen;
    }

    /**
     * @param array<mixed> $secretHeaders
     * @param array<string, true> $seen
     */
    private static function assertSecretHeaders(
        #[SensitiveParameter]
        array $secretHeaders,
        #[SensitiveParameter]
        array $seen,
    ): void {
        foreach ($secretHeaders as $name => $value) {
            $lowered = self::assertName($name, $seen);
            if (! $value instanceof Secret) {
                throw new InvalidArgumentException('A secret header value must be a Secret.');
            }
            if (strpbrk($value->reveal(), "\r\n") !== false) {
                throw new InvalidArgumentException('A secret header value must not hold a line break.');
            }
            $seen[$lowered] = true;
        }
    }

    /**
     * @param array<string, true> $seen
     */
    private static function assertName(
        #[SensitiveParameter]
        int|string $name,
        #[SensitiveParameter]
        array $seen,
    ): string {
        if (! is_string($name) || ! Rules::isHttpToken($name)) {
            throw new InvalidArgumentException('A header name must be an HTTP token.');
        }

        $lowered = strtolower($name);
        if (isset($seen[$lowered])) {
            throw new InvalidArgumentException('A header name must not be given twice, compared without case.');
        }

        return $lowered;
    }
}
