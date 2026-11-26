# LLM gateway

`organiseyou/llm-gateway` is a PHP 8.4 library that talks to LLM providers through one interface. A caller gives a
provider preset, a model, credentials and a request in one neutral shape; the library translates it to the provider's
own API, sends it through a transport the caller supplies (any PSR-18 client), and returns the answer in the same
neutral shape. The library opens no connection itself, keeps no state between calls and never runs a tool. It is under
construction: there is no release yet.

## Development

```bash
composer install
composer check    # code style, PHPStan level 8 and the tests
```

Without PHP on the machine, run the same two commands in Docker from the project folder:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer check
```

CI runs the suite with PHP's `php.ini-production`, which differs from PHP without a `php.ini` in what the trace of an
exception shows. The tests set what they need themselves (`phpunit.xml.dist`), so both give the same result. To run
the tests as CI does:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.4-cli sh -c 'cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini && vendor/bin/pest'
```

## Licence

MIT, see [LICENSE](LICENSE).
