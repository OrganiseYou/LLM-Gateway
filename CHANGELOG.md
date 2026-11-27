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
- `Adapter\OpenAiCompatible\ChatCompletionsAdapter`: the first shipped adapter, for the chat-completions format
  (`POST {base}/chat/completions`) that many servers speak. It sends a closed list of fields and nothing else, reads
  an answer and a stream tolerantly (content as a string or a list, tool-call arguments as text or as an object, a
  tool call by its presence, usage wherever a stream puts it, a stream with or without its last line), reads four
  shapes of an error body, and lists models from `{"data": […]}`, `{"models": […]}` or a bare list. Eighteen
  dialect options of a preset say where a server differs (`max_tokens_field`, `system_role`, `system_messages`,
  `assistant_content_without_text`, `required_word`, `named_tool_choice`, `tool_strict`, `tool_result_name`,
  `json_schema_strict`, `strict_subset`, `json_schema_key`, `stream_usage`, `store_field`, `reasoning_carrier`,
  `inline_reasoning`, `finish_content_filter`, `models_path`, `model_id_field`); the README lists their values. A
  status 429 that means "no credit" is `rejected` and is not tried again, one that means "the provider is
  overloaded" is `unavailable`, and a wait in `retry-after-ms` is kept. With `reasoning_carrier: message_field` the
  reasoning beside an answer travels in the opaque part and goes back only with the message it came with. No preset
  ships with it yet. `json_schema_strict: when_fits` sends `strict: true` only for a schema that uses nothing but
  what the provider's documentation names as accepted; an option whose name the adapter does not know is
  `not_configured`.
- `reasoning_carrier: content_entries` on the chat-completions adapter, the third value of that option: for a server
  whose answer holds its reasoning, a citation or another kind of entry inside a `content` that is a list. The whole
  list travels in the opaque part of the answer, as the JSON text it arrived in (an empty object stays an empty
  object and a number keeps its spelling), and goes back as the `content` of the assistant message, only with the
  message it came with. In a stream the pieces of the list are joined into one list first. A content that is a
  string, or a list of text only, has no opaque part, and neither has a list of more than 1 MiB or of more than
  10,000 objects and lists. Before, a preset that set this value failed as `not_configured`.
- `Adapter\Shared\RawJson`: reads JSON as the text it is, for an adapter that must send data of a provider back as
  it came: `at()` gives the text of one value inside a body, `members()` and `items()` the text of each child,
  `plain()` the same JSON in one spelling, `containers()` the number of objects and lists in a text without
  decoding it.
- The adapter base finds a credential in an opaque part also when the part holds JSON text that writes a character
  of the credential as an escape with its number; such a part is dropped, as every part that holds a credential.
- The chat-completions adapter reads the flat error body that names itself with `"object": "error"` also under a
  successful status and as a chunk of a stream: such a call ends as `provider_error` with the provider's code.
- `Adapter\Anthropic\MessagesAdapter`: the second shipped adapter, for the Messages format
  (`POST {base}/v1/messages`), with its model list (`GET {base}/v1/models`). It sends a closed list of fields
  (`model`, `max_tokens`, `system`, `messages`, `tools`, `tool_choice`, `output_config`, `temperature`, `stream`) and
  the version header of the format. A preset on it must set two options of its own: `api_version` and
  `default_max_output_tokens`, the maximum the adapter sends for a request that gives none, because the format
  requires one. System messages go into the top-level `system`, neighbouring messages of one role become one
  message, and the tool results of one round are one user message. A transcript that ends with an assistant message,
  or holds only system messages, is refused as `rejected` before anything is sent. Thinking that arrives with an answer never becomes answer text:
  the list of content blocks it stands in travels in the opaque part, as the JSON text the server wrote, and goes
  back only with the message it came with, and only when every block has exactly the members the provider documents
  for its kind; in a stream the blocks are joined from their pieces first. Structured
  output is native: the schema is sent without the constraint keywords the format refuses, a schema the format
  cannot take (recursive, an object that is not closed, a reference to another document) goes into the system text
  for that one request, and the answer is checked against the caller's whole schema either way. A status 429 whose
  code says the spend cap was reached is `rejected` and not tried again, a response with `x-should-retry: false` is
  not tried again and tells no wait, and an `error` event in a stream ends it with the reason of its type. No preset
  ships with it yet. The README lists its options.
- `Adapter\Gemini\GenerateContentAdapter`: the third shipped adapter, for the generate-content format
  (`POST {base}/models/{model}:generateContent`, a stream at `:streamGenerateContent?alt=sse`), with its model list
  (`GET {base}/models?pageSize=1000`, one page). One adapter for both ways in the provider has: they differ in the
  base URL, the sign-in and the options of the preset. It sends a closed list of fields (`systemInstruction`,
  `contents`, `tools`, `toolConfig`, `generationConfig`, `store`); the key or the token travels in a header, never
  in the address. The model stands in the path, so a model name that is not one path segment of letters, digits,
  dots, underscores and hyphens is refused as `rejected` before anything is sent. Two options of its own, both with
  a default: `structured_output_field` (`response_json_schema` or `response_format`: the provider's pages do not
  agree on the field) and `store_field` (`omit` or `false`). System messages go into `systemInstruction`, and the
  tool results of one round are one `user` content in the order of the calls. A tool call that comes without an id
  gets one from the adapter (`call-0`, `call-1`), which is not sent back; an id the server gave goes back as it
  came. A thought signature that arrives on a part never becomes answer text. The list of parts of every answer that
  holds a signature or a tool call travels in the opaque part, as the JSON text the server wrote, and goes back only
  with the message it came with, and only when every part has exactly the members the provider documents; an
  assistant message built by hand or changed carries the documented skip value on its first tool call. An answer
  with a tool call that names no tool fails as `provider_error`. The
  schema of a response format is sent as it is and the answer is checked against the caller's whole schema; tools
  together with a response format are emulated by default. Finish reasons are read in both spellings; a prompt the
  provider refused (a successful response without an answer) is `rejected` with the provider's reason as the code;
  a status 400 whose body says the key is not valid is `credentials_rejected`; a status 402 is `rejected` and not
  tried again. No preset ships with it yet. The README lists its options.
- `Testing\ContractTestCase::keepsDataWithEveryToolCall()`: a test class of an adapter that keeps data of its
  format with every answer that holds a tool call says true, and the shared set then allows an opaque part on such
  an answer. The default is false, so every existing test class runs as before.
- `Adapter\Shared\RawJson::hasRepeatedName()` tells whether an object anywhere in a JSON text holds the same member
  name twice, and `RawJson::at()` takes an optional longest length, so a value that is too long is not copied.

### Changed

- The library ships no provider presets and no preset catalogue (`Preset\Presets` is not built): a host builds its
  own `Preset` values. Worked definitions for the providers the tests prove stand in `tests/Presets`, as examples;
  they are not part of the package's autoload.
- An answer of very many tool calls is read in one walk by the chat-completions adapter: the ids that are taken are
  kept as a set. 30,000 calls took close to a minute before and take under a second now. The ids are the same.
- `reasoning_carrier: content_entries`: a content list in which an object holds the same member name twice is no
  longer kept with an answer, and is never sent back (see Security).
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

- Data of a provider that is kept as text and sent back as it is (the content list of `reasoning_carrier:
  content_entries`, the content blocks of the Messages adapter) is refused when an object in it holds the same
  member name twice. JSON does not say which of the two values counts, so the library could check one and a server
  read the other: a part built by hand could show the adapter a harmless entry and the server another one.

- A credential that a server repeats no longer reaches a caller through the model name of an answer, the model list
  or a tool call, for every adapter built on the adapter base. A model name that holds a credential or a control
  character is replaced by the model of the request; an entry of the model list whose id, name or alias holds one is
  skipped; an answer with a tool call whose id, name or arguments hold one fails as `provider_error` with a fixed
  detail, in a stream as the failed event, because a tool call cannot be handed on in part.

- Everything the library throws keeps no argument of any call in its trace. PHP hides a sensitive argument from the
  printed forms of a trace only; a walk over the trace could still read it. The library now throws its own
  `Contract\InvalidValue`, `Contract\WrongUse` and `Contract\ForeignFault`, which extend `InvalidArgumentException`,
  `LogicException` and `Error`, so existing `catch` blocks keep working. `Testing\OutputForms`, and so the shared
  test set, searches a trace that way too. Not covered: a `TypeError` PHP itself raises at a typed parameter of the
  library, before the constructor runs (`new BearerKey([…])`), still holds the argument in its trace.
- What an adapter throws while it builds a request no longer leaves a call as it is, with the request of the caller
  in its trace: an exception becomes `provider_error` with a fixed detail, an error a `Contract\ForeignFault`.
