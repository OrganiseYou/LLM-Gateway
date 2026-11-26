<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * The answer to one question of the connection check.
 */
enum CheckStatus: string
{
    case Yes = 'yes';
    case No = 'no';
    case NotSupported = 'not_supported';
    case NotRun = 'not_run';
}
