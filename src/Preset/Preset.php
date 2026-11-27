<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Preset;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use ReflectionClass;
use SensitiveParameter;

/**
 * The data of one provider: which adapter speaks to it, where, with which sign-in and with which settings of the
 * customer. Data only, no logic. A host project can build its own.
 */
final readonly class Preset
{
    /**
     * @param string $id lowercase letters, digits and hyphens, starting with a letter, at most 64 characters
     * @param string $description what the customer needs, in one plain sentence
     * @param class-string<AbstractAdapter> $adapter
     * @param string|null $defaultBaseUrl absolute, http or https, without user info, query or fragment; may hold
     *                                    {key} placeholders that name fields
     * @param list<CredentialKind> $credentialKinds the kinds this preset accepts
     * @param string|null $keyHeader the header a header key is sent in
     * @param list<PresetField> $fields
     * @param string $dataLocation where the provider processes data, as its documentation states it
     * @param string|null $dataLocationSource the link to that statement
     * @param bool $sendsNoStoreOption true when the adapter sends the provider's option not to store the conversation
     * @param array<string, bool|int|string> $adapterOptions differences between servers of one format
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public string $adapter,
        public BaseUrlRule $baseUrlRule,
        public ?string $defaultBaseUrl,
        public array $credentialKinds,
        public ?string $keyHeader,
        public array $fields,
        public string $dataLocation,
        public ?string $dataLocationSource,
        public bool $sendsNoStoreOption,
        public array $adapterOptions = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $id) !== 1) {
            throw new InvalidValue('The id of a preset must match ^[a-z][a-z0-9-]{0,63}$.');
        }
        if ($label === '') {
            throw new InvalidValue('The label of a preset must not be empty.');
        }
        if (! is_subclass_of($adapter, AbstractAdapter::class) || ! (new ReflectionClass($adapter))->isInstantiable()) {
            throw new InvalidValue('The adapter of a preset must be a class that extends the adapter base and can be built.');
        }
        if ($baseUrlRule === BaseUrlRule::CustomerMust && $defaultBaseUrl !== null) {
            throw new InvalidValue('A preset whose customer must give the base URL has no default base URL.');
        }
        if ($baseUrlRule !== BaseUrlRule::CustomerMust && $defaultBaseUrl === null) {
            throw new InvalidValue('A preset needs a default base URL unless the customer must give one.');
        }

        self::assertCredentialKinds($credentialKinds);

        if ($keyHeader === null && in_array(CredentialKind::HeaderKey, $credentialKinds, true)) {
            throw new InvalidValue('A preset that accepts a header key must name the header.');
        }
        if ($keyHeader !== null && ! Rules::isHttpToken($keyHeader)) {
            throw new InvalidValue('The key header of a preset must be an HTTP token.');
        }

        $fieldsByKey = self::fieldsByKey($fields);

        if ($defaultBaseUrl !== null) {
            self::assertDefaultBaseUrl($defaultBaseUrl, $fieldsByKey);
        }

        self::assertAdapterOptions($adapterOptions);
    }

    /**
     * The keys of the fields that the default base URL uses in a placeholder, in the order they appear.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        if ($this->defaultBaseUrl === null) {
            return [];
        }

        preg_match_all('/\{([a-z][a-z0-9_]{0,31})\}/', $this->defaultBaseUrl, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @param array<mixed> $adapterOptions
     */
    private static function assertAdapterOptions(array $adapterOptions): void
    {
        foreach ($adapterOptions as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidValue('The name of an adapter option must be a string.');
            }
            if (! is_bool($value) && ! is_int($value) && ! is_string($value)) {
                throw new InvalidValue('An adapter option must be a boolean, an integer or a string.');
            }
        }
    }

    /**
     * @param array<mixed> $credentialKinds
     */
    private static function assertCredentialKinds(array $credentialKinds): void
    {
        if ($credentialKinds === []) {
            throw new InvalidValue('A preset must accept at least one credential kind.');
        }
        if (! array_is_list($credentialKinds)) {
            throw new InvalidValue('The credential kinds of a preset must be a list.');
        }
        foreach ($credentialKinds as $kind) {
            if (! $kind instanceof CredentialKind) {
                throw new InvalidValue('The credential kinds of a preset hold a value that is not a credential kind.');
            }
        }
    }

    /**
     * @param array<mixed> $fields
     * @return array<string, PresetField>
     */
    private static function fieldsByKey(array $fields): array
    {
        if (! array_is_list($fields)) {
            throw new InvalidValue('The fields of a preset must be a list.');
        }

        $byKey = [];
        foreach ($fields as $field) {
            if (! $field instanceof PresetField) {
                throw new InvalidValue('The fields of a preset hold a value that is not a field.');
            }
            if (isset($byKey[$field->key])) {
                throw new InvalidValue('Two fields of a preset have the same key.');
            }
            $byKey[$field->key] = $field;
        }

        return $byKey;
    }

    /**
     * @param array<string, PresetField> $fieldsByKey
     */
    private static function assertDefaultBaseUrl(#[SensitiveParameter] string $url, array $fieldsByKey): void
    {
        preg_match_all('/\{([^{}]*)\}/', $url, $matches);
        foreach ($matches[1] as $key) {
            if (! isset($fieldsByKey[$key])) {
                throw new InvalidValue('A placeholder in the default base URL must name a field of the preset.');
            }
            if ($fieldsByKey[$key]->pattern === null) {
                throw new InvalidValue('A field that is used in the default base URL must have a pattern.');
            }
        }

        // After the last placeholder in the host come at most the rest of its label, a dot and a fixed domain of
        // two labels or more, so that a setting can never choose the registrable domain.
        if (preg_match('/^[a-z]+:\/\/([^\/?#:]*)/Di', $url, $host) === 1
            && str_contains($host[1], '{')
            && preg_match('/\}[A-Za-z0-9-]*\.[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+$/D', $host[1]) !== 1) {
            throw new InvalidValue(
                'A placeholder in the host of the default base URL must be followed by a dot and a fixed domain of two labels.',
            );
        }

        // With every placeholder filled, what is left must be an address.
        $filled = (string) preg_replace('/\{[^{}]*\}/', '1', $url);
        $valid = strpbrk($filled, '{}?') === false;
        if ($valid) {
            try {
                new HttpRequest('GET', $filled, [], [], 1.0);
            } catch (InvalidArgumentException) {
                $valid = false;
            }
        }
        if (! $valid) {
            throw new InvalidValue(
                'The default base URL must be an absolute http or https URL with a host, without user info, query or fragment.',
            );
        }
    }
}
