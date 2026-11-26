<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Credentials;

use OrganiseYou\LlmGateway\Contract\Secret;

/**
 * Gives a short-lived token. Written by the host: the library asks before every attempt and keeps nothing, so
 * keeping a token until it runs out is the supplier's work. It must not block for long: its time counts against
 * the deadline of the call.
 *
 * The supplier is also the place to register a fresh token with the redactor of the host: the secrets of a token
 * credential are an empty list, because no token is known before the supplier is asked, and the library keeps
 * none.
 */
interface TokenSupplier
{
    /**
     * The token alone, without a scheme word before it, without a control character and without a space or tab at
     * its start or end.
     */
    public function token(): Secret;
}
