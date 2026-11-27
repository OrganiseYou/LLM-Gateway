<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\Transport;
use SensitiveParameter;

/**
 * Turns a preset, credentials, a transport and the host's configuration into a gateway. Sends nothing and keeps
 * nothing.
 */
final class GatewayFactory
{
    /**
     * The checks run in the constructor of the adapter base, so a gateway that is built without this factory is
     * checked the same way.
     *
     * @throws GatewayException with the reason not_configured, when the credential kind does not fit the preset or
     *                          the configuration does not fit it (see resolveBaseUrl)
     */
    public static function create(
        Preset $preset,
        #[SensitiveParameter]
        Credentials $credentials,
        Transport $transport,
        GatewayConfig $config,
    ): Gateway {
        return new ($preset->adapter)($preset, $config, $credentials, $transport);
    }

    /**
     * The address every request of the gateway starts with: the host's own when it gave one, else the default of
     * the preset with its placeholders filled from the settings. The host can run its outbound rule on it before
     * the first call.
     *
     * @throws GatewayException with the reason not_configured, when the preset needs a base URL and none is given,
     *                          takes none and one is given, a required setting is missing, a setting does not have
     *                          the form its field asks for, a setting that is sent as a header holds a character
     *                          that is not visible ASCII, or a setting is not a field of the preset
     */
    public static function resolveBaseUrl(Preset $preset, GatewayConfig $config): string
    {
        if ($preset->baseUrlRule === BaseUrlRule::CustomerMust && $config->baseUrl === null) {
            throw self::notConfigured('this preset has no address of its own: a base URL is required');
        }
        if ($preset->baseUrlRule === BaseUrlRule::Fixed && $config->baseUrl !== null) {
            throw self::notConfigured('this preset has a fixed address: a base URL cannot be given');
        }

        $known = [];
        foreach ($preset->fields as $field) {
            $known[$field->key] = true;
            $value = $config->settings[$field->key] ?? '';
            if ($value === '') {
                if ($field->required) {
                    throw self::notConfigured('the setting ' . $field->key . ' is required');
                }

                continue;
            }
            if (! $field->accepts($value)) {
                throw self::notConfigured('the setting ' . $field->key . ' does not have the form this preset asks for');
            }
            // A setting that is sent as a header: no space, no control character and nothing outside ASCII, whatever
            // the pattern of the field allows. The detail names the key, never the value.
            if ($field->header !== null && preg_match('/^[\x21-\x7e]+$/D', $value) !== 1) {
                throw self::notConfigured('the setting ' . $field->key . ' is sent as a header and must hold only visible ASCII characters');
            }
        }
        foreach ($config->settings as $key => $value) {
            if (! isset($known[$key])) {
                // The key is the host's text, not a field of the preset: it is named only when it looks like a key.
                throw self::notConfigured(preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) === 1
                    ? 'the setting ' . $key . ' is not a field of this preset'
                    : 'the settings hold a key that is not a field of this preset');
            }
        }

        if ($config->baseUrl !== null) {
            return $config->baseUrl;
        }

        $url = (string) $preset->defaultBaseUrl;
        foreach ($preset->placeholders() as $key) {
            $value = $config->settings[$key] ?? '';
            if ($value === '') {
                throw self::notConfigured('the setting ' . $key . ' is needed for the address');
            }
            // Whatever the pattern of the field allows: nothing that ends a host or starts a path, a query or a port,
            // and no dot segment.
            if (preg_match('/^[A-Za-z0-9._~-]+$/D', $value) !== 1 || $value === '.' || $value === '..') {
                throw self::notConfigured('the setting ' . $key . ' holds a character that cannot be part of the address');
            }
            $url = str_replace('{' . $key . '}', $value, $url);
        }

        if (! self::isAddress($url)) {
            throw self::notConfigured('the settings do not give a valid address');
        }

        return $url;
    }

    private static function isAddress(#[SensitiveParameter] string $url): bool
    {
        try {
            new HttpRequest('GET', $url, [], [], 1.0);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private static function notConfigured(string $detail): GatewayException
    {
        return new GatewayException(ErrorReason::NotConfigured, bin2hex(random_bytes(16)), null, null, null, 0, $detail);
    }
}
