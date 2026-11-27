<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

use OrganiseYou\LlmGateway\Contract\InvalidValue;
use SensitiveParameter;

/**
 * One HTTP response with its whole body.
 */
final readonly class HttpResponse
{
    /** @var array<string, string> names lower-cased; several values of one header joined with ", " */
    public array $headers;

    /**
     * @param int $status 100 to 599
     * @param array<array-key, string|array<array-key, string>> $headers names in any case; a value or its values
     */
    public function __construct(
        public int $status,
        #[SensitiveParameter]
        array $headers = [],
        #[SensitiveParameter]
        public string $body = '',
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidValue('The HTTP status must be between 100 and 599.');
        }

        $this->headers = self::normalise($headers);
    }

    /**
     * The value of a header, looked up without case; null when the response has no such header.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
        ];
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string>
     */
    private static function normalise(#[SensitiveParameter] array $headers): array
    {
        $normalised = [];
        foreach ($headers as $name => $value) {
            // PHP turns a header name of digits only into an integer key.
            $name = strtolower((string) $name);
            if ($name === '') {
                throw new InvalidValue('A header name must not be empty.');
            }

            foreach (is_array($value) ? $value : [$value] as $single) {
                if (! is_string($single)) {
                    throw new InvalidValue('A header value must be a string or a list of strings.');
                }
                $normalised[$name] = isset($normalised[$name]) ? $normalised[$name] . ', ' . $single : $single;
            }
        }

        return $normalised;
    }
}
