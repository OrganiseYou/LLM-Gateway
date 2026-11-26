<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract\Credentials;

/**
 * The ways a sign-in can be presented.
 */
enum CredentialKind: string
{
    case None = 'none';
    case BearerKey = 'bearer_key';
    case HeaderKey = 'header_key';
    case BearerToken = 'bearer_token';
}
