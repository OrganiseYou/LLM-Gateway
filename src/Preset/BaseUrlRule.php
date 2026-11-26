<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Preset;

/**
 * Who gives the address every request of a gateway starts with.
 */
enum BaseUrlRule: string
{
    /** Only the address of the preset. */
    case Fixed = 'fixed';

    /** The preset has a default that the customer may replace. */
    case CustomerMay = 'customer_may';

    /** The preset has no default: the customer gives the address. */
    case CustomerMust = 'customer_must';
}
