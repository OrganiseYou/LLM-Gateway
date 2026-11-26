<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;

/**
 * A class that extends the adapter base and cannot be built: a preset must not name it.
 */
abstract readonly class UnfinishedAdapter extends AbstractAdapter {}
