<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Contract\Secret;
use OrganiseYou\LlmGateway\Transport\HttpRequest;

/**
 * @param array<mixed> $headers
 * @param array<mixed> $secretHeaders
 */
function httpRequest(
    string $method = 'POST',
    string $url = 'https://api.example.test/v1/chat',
    array $headers = ['Content-Type' => 'application/json'],
    array $secretHeaders = [],
    float $timeoutSeconds = 30.0,
    string $body = '{"model":"model-a"}',
): HttpRequest {
    return new HttpRequest($method, $url, untyped($headers), untyped($secretHeaders), $timeoutSeconds, $body);
}

// No dataset in this file holds the marker: the runner keeps every dataset, and a test that prints its own trace
// with the arguments of all calls would find it there.

describe('HttpRequest', function (): void {
    it('keeps every field', function (): void {
        $key = new Secret(LEAK_MARKER);
        $request = new HttpRequest(
            'POST',
            'https://api.example.test/v1/chat?version=2',
            ['Content-Type' => 'application/json'],
            ['Authorization' => $key],
            12.5,
            '{"model":"model-a"}',
        );

        expect($request->method)->toBe('POST')
            ->and($request->url)->toBe('https://api.example.test/v1/chat?version=2')
            ->and($request->headers)->toBe(['Content-Type' => 'application/json'])
            ->and($request->secretHeaders)->toBe(['Authorization' => $key])
            ->and($request->timeoutSeconds)->toBe(12.5)
            ->and($request->body)->toBe('{"model":"model-a"}');
    });

    it('defaults to an empty body', function (): void {
        expect((new HttpRequest('GET', 'https://api.example.test/v1/models', [], [], 5.0))->body)->toBe('');
    });

    it('accepts GET and POST', function (string $method): void {
        expect(httpRequest(method: $method)->method)->toBe($method);
    })->with(['GET', 'POST']);

    it('throws when the method is not GET or POST', function (string $method): void {
        httpRequest(method: $method);
    })->with(['PUT', 'DELETE', 'PATCH', 'HEAD', 'get', 'post', '', "POST\n"])
        ->throws(InvalidArgumentException::class, 'The method must be GET or POST.');

    it('accepts an absolute http or https URL', function (string $url): void {
        expect(httpRequest(url: $url)->url)->toBe($url);
    })->with([
        'https://api.example.test',
        'https://api.example.test/',
        'http://localhost:1234/v1/chat/completions',
        'http://127.0.0.1:8080/v1',
        'http://[::1]:8080/v1',
        'https://api.example.test/v1/models?limit=10&after=model%20a',
        'HTTPS://API.EXAMPLE.TEST/v1',
        'https://xn--bcher-kva.example.test/v1',
        'https://api-1.example.test:65535/v1',
        'http://localhost:1/',
        'http://my_service:8080/v1',
        'http://my_service.internal_net/v1',
        'http://[2001:db8::1]/v1',
        'http://[::ffff:192.0.2.1]:8080/v1',
        'https://api.example.test?version=2',
        'https://api.example.test/v1/a%40b?c=%2F&d=a:b',
        'https://api.example.test/v1/models/vendor/model-a:free',
    ]);

    it('accepts a long URL', function (): void {
        $url = 'https://api.example.test/v1/models?after=' . str_repeat('a', 8000);

        expect(httpRequest(url: $url)->url)->toBe($url);
    });

    it('accepts a long body', function (): void {
        $body = str_repeat('{"text":"a"}', 100000);

        expect(httpRequest(body: $body)->body)->toBe($body);
    });

    it('throws when the URL is not absolute with the scheme http or https', function (string $url): void {
        httpRequest(url: $url);
    })->with([
        'empty' => [''],
        'a path' => ['/v1/chat'],
        'no scheme' => ['api.example.test/v1'],
        'scheme-relative' => ['//api.example.test/v1'],
        'ftp' => ['ftp://api.example.test/v1'],
        'file' => ['file:///etc/hosts'],
        'a wrapper' => ['php://memory'],
        'one slash' => ['https:/api.example.test'],
        'a scheme that only ends in http' => ['xhttp://a.test/'],
        'a scheme that only starts with http' => ['httpx://a.test/'],
        'something before the scheme' => ['see:https://a.test/'],
    ])->throws(InvalidArgumentException::class, 'The URL must be absolute and start with http:// or https://.');

    it('throws when the URL has no host', function (string $url): void {
        httpRequest(url: $url);
    })->with([
        'nothing after the scheme' => ['https://'],
        'a path at once' => ['https:///v1/chat'],
        'only a port' => ['https://:8080/v1'],
        'only a query' => ['https://?a=b'],
        'a port that is no port' => ['https://api.example.test:99999999/v1'],
    ])->throws(InvalidArgumentException::class, 'The URL must hold a host');

    it('throws when clients could read another host or port from the URL', function (string $url): void {
        httpRequest(url: $url);
    })->with([
        'an encoded at sign in the host' => ['https://a.test%40evil.test/'],
        'an encoded slash in the host' => ['https://a.test%2f.evil.test/'],
        'an encoded null byte in the host' => ['https://a.test%00.evil.test/'],
        'an open bracket' => ['https://[::1/v1'],
        'a closing bracket only' => ['https://::1]/v1'],
        'two ports' => ['https://a.test:8080:9090/x'],
        'an empty port' => ['https://api.example.test:/v1'],
        'port 0' => ['https://api.example.test:0/v1'],
        'port 65536' => ['https://api.example.test:65536/v1'],
        'a port with a letter' => ['https://api.example.test:80a/v1'],
        'a port with a sign' => ['https://api.example.test:+80/v1'],
        'an IPv6 address without brackets' => ['https://::1/v1'],
        'brackets around a name' => ['https://[api.example.test]/v1'],
        'brackets around nothing' => ['https://[]/v1'],
        'an IPv6 address that is none' => ['https://[::1::2]/v1'],
        'an IPv6 zone' => ['https://[fe80::1%25eth0]/v1'],
        'text after the bracket' => ['https://[::1]evil.test/v1'],
        'a host that starts with an underscore' => ['https://_service/v1'],
        'a host that ends with an underscore' => ['https://service_:8080/v1'],
        'a comma in the host' => ['https://a.test,evil.test/v1'],
        'a semicolon in the host' => ['https://a.test;evil.test/v1'],
        'a host that starts with a hyphen' => ['https://-a.test/v1'],
        'a host that ends with a dot' => ['https://a.test./v1'],
        'only a dot' => ['https://./v1'],
    ])->throws(InvalidArgumentException::class, 'The URL must hold a host');

    it('throws when the URL holds user info', function (string $url): void {
        httpRequest(url: $url);
    })->with([
        'a user and a password' => ['https://user:password@api.example.test/v1'],
        'a user' => ['https://user@api.example.test/v1'],
        'an empty user' => ['https://@api.example.test/v1'],
    ])->throws(InvalidArgumentException::class, 'The URL must not hold user info.');

    it('accepts an at sign in the path or the query', function (): void {
        expect(httpRequest(url: 'https://api.example.test/v1/a@b?c=d@e')->url)->toBe('https://api.example.test/v1/a@b?c=d@e');
    });

    it('throws when the URL holds a fragment', function (string $url): void {
        httpRequest(url: $url);
    })->with([
        'a fragment' => ['https://api.example.test/v1#part'],
        'an empty fragment' => ['https://api.example.test/v1#'],
        'a fragment after the host' => ['https://api.example.test#part'],
    ])->throws(InvalidArgumentException::class, 'The URL must not hold a fragment.');

    it('throws when the URL holds a space, a control character, a backslash or a byte outside ASCII', function (string $url): void {
        httpRequest(url: $url);
    })->with([
        'a space' => ['https://api.example.test/v1 /chat'],
        'a line break at the end' => ["https://api.example.test/v1\n"],
        'a line break inside' => ["https://api.example.test/v1\r\nHost: other.test"],
        'a tab' => ["https://api.example.test/\tv1"],
        'a null byte' => ["https://api.example.test/v1\0"],
        'a backslash before an at sign' => ['https://other.test\\@api.example.test/v1'],
        'a backslash as a slash' => ['https:\\\\api.example.test/v1'],
        'a letter outside ASCII' => ['https://api.exämple.test/v1'],
    ])->throws(InvalidArgumentException::class, 'The URL must hold only visible ASCII characters');

    it('accepts header names that are HTTP tokens', function (string $name): void {
        expect(httpRequest(headers: [$name => 'value'])->headers)->toBe([$name => 'value']);
    })->with(['Content-Type', 'x-request-id', 'X_Custom.Header', "a!#$%&'*+^`|~z"]);

    it('throws when a header name is not an HTTP token', function (mixed $name): void {
        httpRequest(headers: [$name => 'value']);
    })->with([
        'empty' => [''],
        'a space' => ['Content Type'],
        'a colon' => ['Content-Type:'],
        'a line break at the end' => ["Content-Type\n"],
        'a line break inside' => ["X-A\r\nX-B"],
        'a letter outside ASCII' => ['X-Clé'],
        'a list without names' => [0],
        'a name of digits only, which PHP keeps as a number' => ['123'],
    ])->throws(InvalidArgumentException::class, 'A header name must be an HTTP token.');

    it('throws when a header value holds a line break', function (string $value): void {
        httpRequest(headers: ['X-Request' => $value]);
    })->with([
        'a line feed' => ["a\nb"],
        'a carriage return' => ["a\rb"],
        'both' => ["a\r\nX-Injected: 1"],
        'at the end' => ["a\n"],
    ])->throws(InvalidArgumentException::class, 'A header value must not hold a line break.');

    it('throws when a header value is not a string', function (mixed $value): void {
        httpRequest(headers: ['X-Request' => $value]);
    })->with([
        'an integer' => [1],
        'null' => [null],
        'a list' => [['a', 'b']],
        'a secret' => [fn(): Secret => new Secret('k')],
    ])->throws(InvalidArgumentException::class, 'A header value must be a string.');

    it('throws when a header name is given twice in another case', function (): void {
        httpRequest(headers: ['Accept' => 'a', 'accept' => 'b']);
    })->throws(InvalidArgumentException::class, 'A header name must not be given twice, compared without case.');

    it('throws when a secret header name is not an HTTP token', function (mixed $name): void {
        httpRequest(secretHeaders: [$name => new Secret('k')]);
    })->with([
        'empty' => [''],
        'a space' => ['X Key'],
        'a line break at the end' => ["X-Key\n"],
        'a list without names' => [0],
    ])->throws(InvalidArgumentException::class, 'A header name must be an HTTP token.');

    it('throws when a secret header name is also a header name, compared without case', function (string $plain, string $secret): void {
        httpRequest(headers: [$plain => 'Bearer none'], secretHeaders: [$secret => new Secret('k')]);
    })->with([
        'the same' => ['Authorization', 'Authorization'],
        'another case' => ['Authorization', 'authorization'],
        'upper case' => ['x-api-key', 'X-API-KEY'],
    ])->throws(InvalidArgumentException::class, 'A header name must not be given twice, compared without case.');

    it('throws when a secret header name is given twice in another case', function (): void {
        httpRequest(secretHeaders: ['X-Key' => new Secret('a'), 'x-key' => new Secret('b')]);
    })->throws(InvalidArgumentException::class, 'A header name must not be given twice, compared without case.');

    it('throws when a secret header value is not a Secret', function (mixed $value): void {
        httpRequest(secretHeaders: ['Authorization' => $value]);
    })->with([
        'a string' => ['Bearer key-1'],
        'null' => [null],
        'a list' => [['key-1']],
    ])->throws(InvalidArgumentException::class, 'A secret header value must be a Secret.');

    it('throws when a secret header value holds a line break', function (string $value): void {
        httpRequest(secretHeaders: ['Authorization' => new Secret($value)]);
    })->with([
        'a line feed' => ["key-1\nX-Injected: 1"],
        'a carriage return' => ["key-1\r"],
    ])->throws(InvalidArgumentException::class, 'A secret header value must not hold a line break.');

    it('accepts a timeout above 0', function (float $timeout): void {
        expect(httpRequest(timeoutSeconds: $timeout)->timeoutSeconds)->toBe($timeout);
    })->with([0.001, 1.0, 600.0]);

    it('throws when the timeout is not finite or not above 0', function (float $timeout): void {
        httpRequest(timeoutSeconds: $timeout);
    })->with([
        'zero' => [0.0],
        'negative' => [-1.0],
        'infinite' => [INF],
        'not a number' => [NAN],
    ])->throws(InvalidArgumentException::class, 'The timeout must be a finite number of seconds above 0.');

    it('gives all headers with the secret ones revealed, for the transport', function (): void {
        $request = httpRequest(
            headers: ['Content-Type' => 'application/json'],
            secretHeaders: ['Authorization' => new Secret('Bearer ' . LEAK_MARKER), 'X-Api-Key' => new Secret(LEAK_MARKER)],
        );

        expect($request->allHeaders())->toBe([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . LEAK_MARKER,
            'X-Api-Key' => LEAK_MARKER,
        ]);
    });

    it('masks the secret headers in the form for reports and logs', function (): void {
        $request = httpRequest(
            method: 'POST',
            url: 'https://api.example.test/v1/chat',
            headers: ['Content-Type' => 'application/json'],
            secretHeaders: ['Authorization' => new Secret('Bearer ' . LEAK_MARKER)],
            body: '{"model":"model-a"}',
        );

        expect($request->toArray())->toBe([
            'method' => 'POST',
            'url' => 'https://api.example.test/v1/chat',
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => '[secret]'],
            'body' => '{"model":"model-a"}',
        ]);
    });

    it('does not show a secret header in any output form', function (): void {
        $request = httpRequest(secretHeaders: [
            'Authorization' => new Secret('Bearer ' . LEAK_MARKER),
            'X-Api-Key' => new Secret(LEAK_MARKER),
        ]);

        expectNoMarker(valueForms($request) + ['toArray' => print_r($request->toArray(), true), 'toArray as JSON' => json_encode($request->toArray(), JSON_THROW_ON_ERROR)]);
    });

    it('marks the URL, the headers and the body as sensitive parameters', function (): void {
        $sensitive = [];
        foreach ((new ReflectionMethod(HttpRequest::class, '__construct'))->getParameters() as $parameter) {
            if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                $sensitive[] = $parameter->getName();
            }
        }

        expect($sensitive)->toBe(['url', 'headers', 'secretHeaders', 'body']);
    });

    it('keeps nothing of a refused request in the refusal', function (bool $ignoreArgs, string $case): void {
        // Built without a helper in between: the arguments of a caller's own function are the caller's to guard.
        $url = 'https://api.example.test/v1/chat';
        $build = match ($case) {
            'a key as a plain secret header' => fn() => new HttpRequest('POST', $url, [], untyped(['Authorization' => 'Bearer ' . LEAK_MARKER]), 30.0),
            'a key in a header with a line break' => fn() => new HttpRequest('POST', $url, ['Authorization' => LEAK_MARKER . "\n"], [], 30.0),
            'a secret with a line break' => fn() => new HttpRequest('POST', $url, [], ['Authorization' => new Secret(LEAK_MARKER . "\n")], 30.0),
            'a key in the user info of the URL' => fn() => new HttpRequest('POST', 'https://user:' . LEAK_MARKER . '@api.example.test', [], [], 30.0),
            'a key in the query, with a wrong method' => fn() => new HttpRequest('PUT', $url . '?key=' . LEAK_MARKER, [], [], 30.0),
            'a key in the body, with a wrong timeout' => fn() => new HttpRequest('POST', $url, [], [], 0.0, '{"key":"' . LEAK_MARKER . '"}'),
            'a key as a header name' => fn() => new HttpRequest('POST', $url, [LEAK_MARKER . ' ' => 'value'], [], 30.0),
            'a key as a secret header name' => fn() => new HttpRequest('POST', $url, [], [LEAK_MARKER . ' ' => new Secret('k')], 30.0),
            'a key as a header name given twice' => fn() => new HttpRequest('POST', $url, [LEAK_MARKER => 'a', strtolower(LEAK_MARKER) => 'b'], [], 30.0),
            'a key in a header next to a wrong secret header' => fn() => new HttpRequest('POST', $url, ['X-Key' => LEAK_MARKER], untyped(['Authorization' => 1]), 30.0),
            default => throw new LogicException('Unknown case.'),
        };

        withExceptionIgnoreArgs($ignoreArgs, function () use ($build): void {
            try {
                $build();
            } catch (InvalidArgumentException $exception) {
                expectNoMarker(exceptionForms($exception));

                return;
            }

            throw new LogicException('The request was accepted.');
        });
    })->with(['arguments kept' => [false], 'arguments left out' => [true]])->with([
        'a key as a plain secret header',
        'a key in a header with a line break',
        'a secret with a line break',
        'a key in the user info of the URL',
        'a key in the query, with a wrong method',
        'a key in the body, with a wrong timeout',
        'a key as a header name',
        'a key as a secret header name',
        'a key as a header name given twice',
        'a key in a header next to a wrong secret header',
    ]);
});
