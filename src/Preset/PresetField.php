<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Preset;

use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\InvalidValue;

/**
 * One setting the customer fills in for a preset. Never a secret: a secret is a credential.
 */
final readonly class PresetField
{
    /**
     * The request headers a setting can never be sent in, in small letters. The sign-in: a setting is no
     * credential. What moves a request or says where it came from: the host of a request never changes. The
     * connection and the body: the transport frames a request. What changes the body of the response: the adapter
     * reads the response it asked for. The header of a header key is refused by the preset, which knows its name.
     */
    private const array RESERVED_HEADERS = [
        'authorization', 'cookie', 'cookie2', 'set-cookie', 'origin',
        'host', 'forwarded', 'via', 'max-forwards', 'x-host', 'x-original-host', 'x-original-url', 'x-rewrite-url', 'x-real-ip',
        'x-http-method', 'x-http-method-override', 'x-method-override',
        'connection', 'keep-alive', 'upgrade', 'http2-settings', 'te', 'trailer', 'transfer-encoding', 'expect',
        'content-length', 'content-type', 'content-encoding', 'content-range', 'content-md5',
        'accept', 'accept-encoding', 'range',
    ];

    /** Every header whose name starts with one of these is refused too, whatever follows. */
    private const array RESERVED_PREFIXES = ['proxy-', 'sec-', 'x-forwarded-'];

    /**
     * @param string $key lowercase letters, digits and underscores, starting with a letter, at most 32 characters
     * @param string $label what the customer reads next to the field
     * @param string|null $pattern a regular expression without delimiters; a value must match it from its first to
     *                             its last character
     * @param string|null $header the name of a request header this setting is sent in with every request of the
     *                            gateway, when the customer filled it in: an HTTP token without an underscore, not
     *                            digits only, and not a header of the sign-in, the address or the connection. Such a field must have
     *                            a pattern. The value is no secret: it shows in the attempt reports
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public ?string $pattern = null,
        public ?string $header = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $key) !== 1) {
            throw new InvalidValue('The key of a field must match ^[a-z][a-z0-9_]{0,31}$.');
        }
        if ($label === '') {
            throw new InvalidValue('The label of a field must not be empty.');
        }
        if ($pattern !== null && ($pattern === '' || ! self::compiles($pattern))) {
            throw new InvalidValue(
                'The pattern of a field must be a regular expression without delimiters that can be compiled.',
            );
        }
        if ($header !== null) {
            // A name of digits only would become a number as the key of the headers of a request.
            if (! Rules::isHttpToken($header) || str_contains($header, '_') || ctype_digit($header)) {
                throw new InvalidValue('The header of a field must be an HTTP token without an underscore, and not digits only.');
            }
            $lowered = strtolower($header);
            $reserved = in_array($lowered, self::RESERVED_HEADERS, true);
            foreach (self::RESERVED_PREFIXES as $prefix) {
                $reserved = $reserved || str_starts_with($lowered, $prefix);
            }
            if ($reserved) {
                throw new InvalidValue('The header of a field must not be a header of the sign-in, the address or the connection.');
            }
            if ($pattern === null) {
                throw new InvalidValue('A field that is sent as a header must have a pattern.');
            }
        }
    }

    /**
     * Whether a value has the form this field asks for. A field without a pattern accepts every value.
     */
    public function accepts(string $value): bool
    {
        return $this->pattern === null || preg_match(self::regex($this->pattern), $value) === 1;
    }

    private static function compiles(string $pattern): bool
    {
        // A pattern that cannot be compiled gives a warning and false; the warning is not for the host to see.
        set_error_handler(fn(): bool => true);
        try {
            return preg_match(self::regex($pattern), '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The whole value must match: the pattern is anchored here, so a preset cannot forget it.
     */
    private static function regex(string $pattern): string
    {
        return "\x01^(?:" . $pattern . ")$\x01D";
    }
}
