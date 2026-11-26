<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * The call of the gateway an HTTP attempt belongs to.
 */
enum Operation: string
{
    case Complete = 'complete';
    case Stream = 'stream';
    case Models = 'models';
    case Check = 'check';
}
