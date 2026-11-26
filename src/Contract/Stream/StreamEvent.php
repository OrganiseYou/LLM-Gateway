<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Stream;

/**
 * One event of a streamed completion. A stream ends with exactly one StreamFinished or one StreamFailed.
 */
interface StreamEvent {}
