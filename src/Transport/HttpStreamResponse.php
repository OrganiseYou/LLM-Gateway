<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Transport;

use SensitiveParameter;

/**
 * One HTTP response whose body is read while it arrives.
 *
 * The chunks are raw bytes, cut anywhere, and can be read once. For a status outside 200 to 299 they are the
 * error body. Reading them can throw a TransportException.
 */
final readonly class HttpStreamResponse
{
    public int $status;

    /** @var array<string, string> names lower-cased; several values of one header joined with ", " */
    public array $headers;

    /**
     * @param int $status 100 to 599
     * @param array<array-key, string|array<array-key, string>> $headers names in any case; a value or its values
     * @param iterable<string> $chunks
     */
    public function __construct(
        int $status,
        #[SensitiveParameter]
        array $headers,
        #[SensitiveParameter]
        public iterable $chunks,
    ) {
        // One set of rules for the status and the headers of both kinds of response.
        $checked = new HttpResponse($status, $headers);

        $this->status = $checked->status;
        $this->headers = $checked->headers;
    }

    /**
     * The value of a header, looked up without case; null when the response has no such header.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
