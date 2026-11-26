<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Shared;

use SensitiveParameter;

/**
 * One event of a server-sent-events stream, as the reader cut it from the raw bytes.
 */
final readonly class SseEvent
{
    /**
     * @param string|null $event the event's type; null when the stream named none
     * @param string $data the data lines of the event, joined with a line feed
     * @param string|null $id the last id the stream gave, also when an earlier event gave it; null when none
     */
    public function __construct(
        public ?string $event,
        #[SensitiveParameter]
        public string $data,
        public ?string $id = null,
    ) {}
}
