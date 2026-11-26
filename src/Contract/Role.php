<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * Who a message is from.
 */
enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
