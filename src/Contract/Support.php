<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * How a capability is there: by itself, made up for inside the adapter, or not at all.
 */
enum Support: string
{
    case Native = 'native';
    case Emulated = 'emulated';
    case None = 'none';

    public function isAvailable(): bool
    {
        return $this !== self::None;
    }
}
