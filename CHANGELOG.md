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
- `Contract\OpaquePart`: data that came with an answer and must go back unchanged in the next round. `Answer::$opaque`
  and `Answer::toMessage()` carry it, an adapter seals and opens it with `seal()` and `unseal()` of the adapter base,
  and the shared test set gains the case `ContractFixture::ToolCallWithOpaqueData`. For a host that wrote its own
  classes for the shared test set: a class that implements `Testing\ContractSubject` must add `cannotGive()`, and a
  class that implements `Testing\AdapterFixtures` must answer for the new case, with a fixture or with
  `NotApplicable` from both `answer()` and `stream()`.
- `Contract\FinishReason::Refusal`: the model declined to answer. An answer without text and without a tool call is
  now returned as an answer when its finish reason is `Length`, `ContentFilter` or `Refusal`; before, only `Length`
  was, and the other two ended as `provider_error`. The adapter base and `Testing\FakeGateway` both follow this.
- `Contract\Capabilities::$temperature` (`native` or `none`, the last constructor parameter, `native` when left
  out). A request that gives a temperature to a model that takes none is refused as `not_supported` before anything
  is sent, by the adapter base and by the fake gateway when it was given capabilities.
- `AbstractAdapter::retryAllowed()`: an adapter returns `false` for a failed response that itself says "do not
  retry". The call then fails at once with its reason (`rate_limited` or `unavailable`) and tells no wait.
- The shared test set gains the answers `ContractFixture::Refused` and `ContractFixture::Withheld`, the failure
  `ContractFailure::RetryForbidden`, the test that a temperature is refused where the capability says none, and the
  test that a response which forbids a retry is not tried again.
- `Preset\ModelOverride`: adapter options for the models whose name fits a pattern (`*` stands for any run of
  characters). `Preset::$modelOverrides` holds them and `Preset::optionsFor($model)` gives the options of the preset
  with every override that fits laid over them, in the order of the list. An override is not a closed list of
  models: a model that fits none gets the options of the preset.
- `Preset::withAdapterOptions()`: a copy of a preset with options laid over its own, for a host that learned more
  about a server than its preset says.
- `Preset::$storageNote` and `Preset::$storageNoteSource`: one plain sentence on what the provider keeps of a
  request and how the customer arranges otherwise, with its link.
- `PresetField::$header` (the last constructor parameter): a setting that is sent as a request header with every
  request of the gateway, when the customer filled it in. The value is no secret and shows in the attempt reports.
  The name is an HTTP token without an underscore, not digits only, and never a header of the sign-in, the
  address, the connection or one that changes the response;
  a value that is not visible ASCII fails as `not_configured` when the gateway is built.
- `AbstractAdapter::option()` reads an adapter option, for one model when a model is given.
  `AbstractAdapter::capabilitiesFromOptions()` reads the capability options every adapter shares (`tool_calling`,
  `forced_tool_call`, `parallel_tool_calls`, `structured_output`, `tools_with_structured_output`, `streaming`,
  `model_listing`, `temperature`, `context_length`) over the defaults of a format. A value outside its list is
  `not_configured`. The README lists the values.
- `Adapter\Shared\SchemaShape`: questions about the schema of a response format, for an adapter that must decide how
  the schema goes to its server. `uses()` tells which of a list of keywords the schema uses, `valuesOf()` gives the
  values of one keyword, `without()` gives a copy without keywords and `renamed()` a copy with a keyword under
  another name; `mayTighten()` tells whether such a copy can refuse an answer the caller's schema allows, in which
  case it must not be sent. `isRecursive()`, `everyPropertyIsRequired()`, `everyObjectIsClosed()`,
  `everyObjectHasARequiredProperty()`, `depth()` and `propertyCount()` tell whether a schema fits what a server
  accepts. Only the places where a schema stands are read: never the name of a property, nor a value of `enum`,
  `const`, `default` or `examples`. The caller's schema is never changed.

### Changed

- **Breaking:** a `match` over `Contract\FinishReason` without a default arm must handle the new case `Refusal`.
- **Breaking, for a host that wrote its own classes for the shared test set:** a class that implements
  `Testing\AdapterFixtures` must add `retryForbidden()` (answer `NotApplicable` when the format has no such sign)
  and must answer for the cases `Refused` and `Withheld`, with a fixture or with `NotApplicable` from both
  `answer()` and `stream()`. A `match` over `Testing\ContractFixture` or `Testing\ContractFailure` without a default
  arm must handle the new cases.
- **Breaking, for a host that wrote its own adapter:** the adapter base now has the final methods `option()` and
  `capabilitiesFromOptions()`. An adapter with a method of one of these names must rename it. An adapter that sets
  a header itself which a field of its preset also fills is refused with a `WrongUse` before anything is sent.

### Security

- Everything the library throws keeps no argument of any call in its trace. PHP hides a sensitive argument from the
  printed forms of a trace only; a walk over the trace could still read it. The library now throws its own
  `Contract\InvalidValue`, `Contract\WrongUse` and `Contract\ForeignFault`, which extend `InvalidArgumentException`,
  `LogicException` and `Error`, so existing `catch` blocks keep working. `Testing\OutputForms`, and so the shared
  test set, searches a trace that way too. Not covered: a `TypeError` PHP itself raises at a typed parameter of the
  library, before the constructor runs (`new BearerKey([…])`), still holds the argument in its trace.
- What an adapter throws while it builds a request no longer leaves a call as it is, with the request of the caller
  in its trace: an exception becomes `provider_error` with a fixed detail, an error a `Contract\ForeignFault`.
