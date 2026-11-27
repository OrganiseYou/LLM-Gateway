# Changelog

All notable changes to this package are written here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- The repository skeleton: Composer package, Pest, PHPStan level 8, Pint, and the CI workflow.
- `Testing\FakeGateway`: a gateway for the tests of an application, with scripted answers, tool calls, streams and
  failures.
- `Testing\ContractTestCase`: the shared test set every adapter passes, also an adapter a host project writes itself.
  It needs `phpunit/phpunit` in the project that runs it.
- The README: how to call the library, how to choose a provider and a model, how to add a provider of your own, and
  how to use the fake gateway in tests. Every sample is marked as running today or as not built yet.
- `tests/Readme`: the tests execute every sample of the README that is marked as running today, compare what it
  prints with what the README shows, and run the shared test set on the example adapter of the README.
- `Testing\FakeGateway::willAnswerText()` takes the token counts of the answer as an optional second argument.

### Security

- Everything the library throws keeps no argument of any call in its trace. PHP hides a sensitive argument from the
  printed forms of a trace only; a walk over the trace could still read it. The library now throws its own
  `Contract\InvalidValue`, `Contract\WrongUse` and `Contract\ForeignFault`, which extend `InvalidArgumentException`,
  `LogicException` and `Error`, so existing `catch` blocks keep working. `Testing\OutputForms`, and so the shared
  test set, searches a trace that way too. Not covered: a `TypeError` PHP itself raises at a typed parameter of the
  library, before the constructor runs (`new BearerKey([…])`), still holds the argument in its trace.
- What an adapter throws while it builds a request no longer leaves a call as it is, with the request of the caller
  in its trace: an exception becomes `provider_error` with a fixed detail, an error a `Contract\ForeignFault`.
