# Tasks

Build order, top to bottom. A task may start when every
task in its "Needs" is `done`. Status is one of `todo`, `doing`, `done`, `blocked`, `needs-joris`; change only the
status line of a task and add a short "Note:" line under it when something is worth knowing. A task that a later task
made unnecessary keeps its heading, says `dropped`, and has a note that says where its work went.

Each slice is one branch and one pull request. The requirement ids (LR-nn) are in `docs/requirements.md`, the decision
ids (Gn) in `docs/plan/decisions.md`.

| Slice | Branch | Tasks | Gate |
|---|---|---|---|
| S1 Contract | `slice/s1-contract` | LG-01 to LG-09 | `composer check` green; the fake passes the shared test set |
| S2 Providers read | `slice/s2-providers` | LG-10, LG-11 | fifteen provider files, one adapter map |
| S3 Adapters | `slice/s3-adapters` | LG-29 to LG-32, LG-12 to LG-16, LG-34 (LG-17 dropped) | every adapter passes the shared test set |
| S4 Presets | `slice/s4-presets` | LG-18 to LG-21, LG-33 | every entry of `docs/design/adapters.md` section 9 resolves to an adapter and passes the preset test |
| S5 Loop and checks | `slice/s5-loop` | LG-22 to LG-24 | loop, connection check and model list work on the fake and on every adapter |
| S6 Finish | `slice/s6-finish` | LG-25 to LG-28 | documentation complete; the rest waits for Joris |

---

## S1 Contract

### LG-01 · Repository skeleton
- Status: done · Needs: none
- Scope: `composer.json` (G5, G6, G7; `require`: php, `psr/http-client`, `psr/http-factory`, `psr/http-message`,
  `opis/json-schema`; `require-dev`: Pest 4, PHPStan, Pint, `guzzlehttp/guzzle`, `nyholm/psr7`), the scripts in
  the project guide ("Commands"), the folders of "Layout", `LICENSE` (MIT), `README.md` (one paragraph), `CHANGELOG.md`,
  `.gitignore`, `.gitleaks.toml`, `phpstan.neon` (level 8), `pint.json`, one CI workflow that runs `composer check`
  and gitleaks on pull requests that are not drafts.
- Acceptance: `composer install` and `composer check` succeed on an empty `src/`; one placeholder Pest test passes.

### LG-02 · Design the contract
- Status: done · Needs: LG-01
- Scope: write `docs/design/contract.md`: the interface with its five operations (LR-05), every value object with its
  fields and validation (LR-06, LR-07), the neutral ranges (LR-08), the capability list (LR-10), the stream events
  (LR-12), the error reasons (LR-32), the credential kinds (LR-24), `Transport` and the attempt observer (LR-28 to
  LR-30), the retry policy (LR-34), and how a preset, credentials, a transport and options become a gateway object.
  For each LR in sections 3, 6, 7 and 8 of the requirements, name the class or method that meets it.
- Acceptance: every LR-03 to LR-13 and LR-24 to LR-36 appears in a table "requirement → where it is met"; no
  provider name appears in the designed contract; message content is a list of parts (G10).

### LG-03 · Messages, tools, request and answer
- Status: done · Needs: LG-02
- Scope: `src/Contract`: message, content parts (text only for now), tool definition, tool call (decoded arguments or
  an arguments error), tool choice, response format (JSON Schema), request, answer, usage, finish reason (an enum of
  neutral values).
- Acceptance: unit tests cover every validation rule in the design; all classes `final readonly`; a tool call with
  unreadable arguments is a value, not an exception (LR-17).

### LG-04 · Errors, capabilities, model info
- Status: done · Needs: LG-02
- Scope: one exception class with the reasons of LR-32 as an enum, HTTP status, provider code (only when it matches
  `^[a-z0-9_.-]{1,64}$`), retry-after seconds, trace id; the capabilities value object (LR-10) with "native",
  "emulated", "none" for structured output and for a forced tool call (G16); model info (id, name, context length, aliases, deprecated).
- Acceptance: unit tests; the exception's message, string form and JSON form never contain a value passed as a
  credential (tested with a marker key).

### LG-05 · Transport
- Status: done · Needs: LG-02
- Note: G41: the observer criterion is shown in LG-07, where the adapter base calls the observer.
- Scope: `src/Transport`: the `Transport` interface (send; send and read the body as a stream of chunks), neutral HTTP
  request and response values, a bridge to any PSR-18 client, the attempt observer (before and after each attempt:
  operation, attempt number, request, answer, outcome, duration; LR-30), and `src/Testing/FakeTransport` with scripted
  answers, streams, delays and failures.
- Acceptance: the bridge refuses a 3xx as an `unavailable` error and never requests the `Location` (LR-29); an
  observer that throws does not change the result of the call; no connection is opened anywhere in `src/` outside
  the bridge (architecture test).

### LG-06 · Credentials and secrets
- Status: done · Needs: LG-02
- Note: G21 and G42: four credential classes plus preset settings instead of the six kinds listed in the scope.
- Scope: credential value objects for the kinds in the design (bearer key, header key, key with resource/deployment/
  API version, access token with project and region, token supplier callback for short-lived tokens, none); a way for
  the caller to receive the secret values to redact before the first request (LR-26).
- Acceptance: `var_export`, `json_encode`, `print_r`, string casts and exception traces of a credential object show
  no secret (tested); nothing in `src/` reads the environment or a file (architecture test).

### LG-07 · The gateway interface, retries and streaming
- Status: done · Needs: LG-03, LG-04, LG-05, LG-06
- Note: From LG-06: catch exactly `TokenSupplierFailed` around `token()` and map it to `credentials_rejected`, not retried, without copying its text; let an `\Error` pass; the adapter base builds the sign-in header value as a `Secret`.
- Note: G41, extra acceptance carried over from LG-05: an observer that throws, before or after an attempt, does not change the result of the call. In tests, script a redirect as `willFail(Redirected)`; the fake transport returns a scripted 3xx as a normal response.
- Note: From LG-04: the adapter base drops a provider code that equals the credential, clamps the HTTP status and the wait before it builds the gateway exception, marks parameters that hold a body or headers as sensitive, and builds every `detail` from fixed text, numbers and neutral names only.
- Note: G34: `Preset` has a public constructor a host project can use; the constructor and protected methods of `AbstractAdapter` are extension points for adapters written outside the library.
- Scope: the interface (LR-05); the adapter base that owns retries (LR-34, LR-35), timeouts (LR-36), attempt counting,
  latency, trace ids and error mapping from HTTP status; the stream event types (LR-12) and a server-sent-events
  reader that adapters share; the "whole answer as one event" fallback.
- Acceptance: tests on the fake transport: retries on 429 with and without Retry-After, on 5xx and on a lost
  connection; no retry on other 4xx; a Retry-After above the caller's maximum fails at once as `rate_limited`; a
  stream that breaks after the first event reports `failed` and is not retried.

### LG-08 · Structured answers
- Status: done · Needs: LG-03
- Note: From LG-07: the adapter base calls the structured check itself, at the marked place in its private `finished()`; it is not a hook an adapter can override. An empty answer cut off at the token limit is returned, not thrown (G47), and must come out as "cut off at the token limit".
- Scope: decode and check an answer against its JSON Schema with `opis/json-schema` (G13); reasons as
  "path: message"; "cut off at the token limit" from the finish reason; the shared helper adapters use to emulate
  structured output for a provider without it (schema in the instructions, then the same check).
- Acceptance: unit tests for valid, not JSON, schema mismatch, cut off, JSON wrapped in a code fence; an unusable
  answer is returned with reasons, never thrown (LR-33).

### LG-09 · The fake, the shared test set and the architecture tests
- Status: done · Needs: LG-07, LG-08
- Note: From LG-08: the fake runs the same structured check; a gateway exception the checker throws carries a placeholder trace id that the fake must replace with the call's, as the adapter base does. An answer that holds tool calls has no structured result (G53).
- Note: From LG-07: the shared test set also checks what the adapter base does not enforce: the order of stream events, usage reported at most once, and a finished event whose text equals the text deltas.
- Note: G34: add a preset and an adapter defined under `tests/`, outside `src/`, that pass the shared test set.
- Scope: `src/Testing/FakeGateway` (scripted answers, tool calls, streams, failures; records the requests it got);
  `src/Testing/ContractTestCase`: the shared test set of LR-40, written once, that an adapter runs by supplying its
  fixtures; `tests/Architecture`: the rules of the project guide as tests, including a list of provider names (G3) that must
  not appear under `src/Contract`, `src/Loop`, `src/Testing` or `src/Transport`.
- Acceptance: the fake passes the shared test set; an architecture test fails when a provider name is added to
  `src/Contract` (shown by a test that checks the rule on a sample file).

## S2 Providers read

### LG-10 · Read the documentation of the fifteen providers
- Status: done · Needs: LG-02
- Note: Read on 2026-11-27. Most pages came through a reader that returns extracted text; `docs/providers/overview.md` section 5 lists per provider what could not be read. Open decision G59 and proposed decisions G60 and G62 come from this task.
- Note: From LG-09: the provider-name list in `tests/Architecture/ProviderNamesTest.php` takes its model names from the overview file; revisit it when the provider files are written.
- Scope: one file `docs/providers/<provider>.md` for each provider in G3, and
  `docs/providers/overview.md` with one row per provider: hosted chat API or not; API format; base URL; sign-in; tool
  calling; parallel tool calls; structured output; tools with structured output; streaming; model list endpoint;
  the option not to store or train; where data is processed; hosted by the vendor or run by the customer.
- Acceptance: fifteen files, each cell with a link and the date read, or "not confirmed"; no cell from memory;
  providers without a hosted text chat API are named plainly.

### LG-11 · The adapter map
- Status: done · Needs: LG-10
- Note: From LG-10: start from `docs/providers/overview.md` section 3 (thirteen topics the plan must decide). Eight provider files cite no requirement ids, so map those yourself. Known small faults in provider files are listed in the commit message of LG-10; the two oddly named LM Studio endpoints must be checked against the page before anything relies on them.
- Scope: `docs/design/adapters.md`: which API formats exist among the fifteen, which preset uses which adapter, which
  credential kind each needs, which capabilities are native, emulated or absent per adapter. Then update this file:
  add, merge or drop adapter tasks LG-12 to LG-17 so they match (for example, Mistral as a preset on LG-12 instead of
  its own adapter), and record each change in `docs/plan/decisions.md` as `proposed`.
- Acceptance: every provider of G3 is on the map; every credential kind on the map exists in LG-06 or a task is added
  for it; a contradiction with a requirement is an `open` decision, not a silent choice.

## S3 Adapters

Every adapter task has the same acceptance, plus what is listed with it: **the
adapter passes the shared test set of LG-09 with synthetic fixtures; its capabilities match `docs/design/adapters.md`;
streaming works or falls back to one event; the "no credential in any output" test passes.**

The four contract tasks LG-29 to LG-32 come first: the adapter map (`docs/design/adapters.md` section 5) found that
the adapters cannot be built on the contract of S1 as it is. Section numbers below are those of that document.

### LG-29 · Contract: the opaque part
- Status: done · Needs: LG-09, LG-11
- Note: G66. Five providers return data with an answer that must go back unchanged in a tool loop; decision G59
  (open) needs the same part if a Responses adapter is built.
- Scope: section 5.1. `Contract\OpaquePart` (one field `data`); `Message` accepts it in an assistant message, at most
  once; `Answer` gains `$opaque` and `toMessage()` carries it; `AbstractAdapter::answer()` gains the parameter and
  the helpers `seal()` and `unseal()`; `Testing\FakeGateway` passes a scripted part on and records the parts it is
  sent; `ContractFixture::ToolCallWithOpaqueData` with the seven assertions of 5.1, and `NotApplicable` as an answer
  of `AdapterFixtures::answer()` and `stream()` for that case. The adapter defined under `tests/` (G34) gets a
  fixture for it.
- Acceptance: unit tests for every validation rule; the fake and the test adapter pass the shared set with the new
  case; a message whose opaque part is not the adapter's own, or whose text was changed, is sent without it; no part
  holds the test key; no class, case or field name holds a provider name.

### LG-30 · Contract: finish reasons, temperature as a capability, the retry hook
- Status: done · Needs: LG-09, LG-11
- Note: G67, G68, G71. One break of S1: a `match` over `FinishReason` without a default arm.
- Scope: sections 5.2, 5.3 and 5.6. `FinishReason::Refusal`; an answer without text and without a tool call is
  returned when its finish reason is `Length`, `Refusal` or `ContentFilter` (the adapter base and the fake);
  `Capabilities` gains `Support $temperature = Support::Native` and refuses `Emulated`; the adapter base and the fake
  refuse a temperature for a model that takes none, as `not_supported`, before anything is sent;
  `AbstractAdapter::retryAllowed()`; the shared set gains the cases `Refused` and `Withheld` (`NotApplicable`
  allowed), a test that a temperature is refused where the capability says none, and a test that a response which
  forbids a retry is not tried again.
- Acceptance: unit tests; the fake and the test adapter pass the shared set; `composer stan` finds no unhandled case.

### LG-31 · Preset data: options per model, a setting as a header, the storage note
- Status: done · Needs: LG-09, LG-11
- Note: G70, G72.
- Scope: sections 5.4 and 5.5. `Preset\ModelOverride`; `Preset::$modelOverrides`, `Preset::optionsFor()`,
  `Preset::withAdapterOptions()`, `Preset::$storageNote` with its source; `PresetField::$header`, added by the
  adapter base to every request when the setting is filled in; `AbstractAdapter::option()` and
  `capabilitiesFromOptions()` with the capability options of section 2.3 (a value outside its list is
  `not_configured`; tool calling `none` makes a forced call and parallel calls `none`; structured output `none` makes
  tools with structured output `none`). The architecture test's list of value classes gains `ModelOverride`.
- Acceptance: unit tests for every validation rule and for the order in which overrides apply; a header field with a
  value that is not visible ASCII is `not_configured` and nothing is sent; `capabilities()` still sends nothing.

### LG-32 · The schema helper
- Status: done · Needs: LG-08, LG-11
- Note: G74.
- Scope: section 5.9. `Adapter\Shared\SchemaShape` with the methods listed there. It walks only the places of a schema
  where a schema stands (as G55), never changes the caller's `JsonSchema`, and holds no provider name.
- Acceptance: unit tests for each method, with a keyword that also stands as a property name, inside `enum`, `const`,
  `default` and `examples` (not touched), with a recursive schema and with numeric property names (G33).

### LG-12 · The chat-completions adapter
- Status: done · Needs: LG-29, LG-30, LG-31, LG-32
- Note: From LG-32: the provider's file names limits the row of 5.9 omits (root may not be an `anyOf`, total length of names and enum values, number of enum values); check them with the value method of `SchemaShape` (G89) before sending `strict`.
- Note: From LG-31: the adapter needs a test with a header setting filled in, to show none of its own headers clashes.
- Note: From LG-30: the shared set tests the retry sign only on a 503 and a refusal only with text; the adapter needs its own unit tests for a 429 that forbids a retry and for a refusal without text.
- Note: From LG-29: an empty JSON object sealed as an array comes back as `[]`; an adapter that must send provider data back unchanged seals the provider's raw JSON text as one string, not the decoded arrays.
- Note: From LG-09: an adapter proves itself with `Testing\ContractTestCase` and an `AdapterFixtures` class; a reason that depends on a specific provider code in the body (for example "unknown model") needs the adapter's own unit test, because the shared set fixes the code its fixtures echo. The same holds for LG-13 to LG-16.
- Note: G34: no closed list of models; an unknown model gets the format's default capabilities; a preset can state different capabilities through `adapterOptions` and `modelOverrides`. The same holds for LG-13 to LG-16.
- Note: G64, G65: no Mistral adapter and no Azure variant; both are presets on this adapter. This task takes over what
  LG-17 held for Azure: `retry-after-ms`, the content-filter fields, the stream shapes to tolerate.
- Note: G59 (open) does not block this task: fourteen preset entries need this adapter whatever is decided.
- Scope: `Adapter\OpenAiCompatible\ChatCompletionsAdapter`, sections 2, 5.1 to 5.9 and 10: the closed list of fields
  it sends (2.1), the tolerant reading (2.1), every dialect option of 2.2 except `reasoning_carrier: content_entries`
  (LG-13), the capability options (2.3), the finish reasons (5.2), the error table and the two word lists (5.6), the
  structured-output rows of 5.9, the model list reader of section 10.
- Extra acceptance: three fixture sets pass the shared set: the defaults of the format; a minimal server (no tool
  calling, structured output and streaming emulated, no usage in the stream, a flat error body); a server with
  `reasoning_carrier: message_field`, `temperature: not_accepted` and `named_tool_choice: required_single`. Unit
  tests: every option value; an option value outside its list is `not_configured`; no request ever holds an empty
  `tools`, `parallel_tool_calls` or a field outside the closed list; a 429 with a "no credit" type is `rejected` and
  not retried; a wait in `retry-after-ms` is kept.

### LG-13 · The Mistral dialect on the chat-completions adapter
- Status: done · Needs: LG-12
- Note: From LG-12: copy its structure (Dialect, Reading, ErrorBody, fixtures builder, the test files under tests/Unit/Adapter/OpenAiCompatible); the base now keeps an echoed credential out of the model name, the model list and tool calls; a finish word for tool calls without a call is `Other`; an allow-list decides when a strict schema mode is sent.
- Note: From LG-29: an empty JSON object sealed as an array comes back as `[]`; an adapter that must send provider data back unchanged seals the provider's raw JSON text as one string, not the decoded arrays.
- Note: G64: not an adapter of its own. The id is kept; the scope changed.
- Scope: `reasoning_carrier: content_entries` (sections 2.2 and 5.1): answer content as a list with entries that are
  not text, kept in the opaque part and sent back as the content of the assistant message, also in a stream where a
  delta's content is a list; the options the `mistral` entry sets (section 9, row 13); the three error-body shapes.
- Extra acceptance: a fixture set with those options passes the shared set, the opaque case included; both argument
  forms (a string and a map) and both content forms (a string and a list) are covered by fixtures.

### LG-14 · The Messages adapter
- Status: done · Needs: LG-29, LG-30, LG-31, LG-32
- Note: From LG-13: `Adapter\Shared\RawJson` cuts a member out of a body as text; anything sealed as one string must pass the size and container limits as text before it is decoded, in both directions and per stream piece (G100).
- Note: From LG-12: copy its structure (Dialect, Reading, ErrorBody, fixtures builder, the test files under tests/Unit/Adapter/OpenAiCompatible); the base now keeps an echoed credential out of the model name, the model list and tool calls; a finish word for tool calls without a call is `Other`; an allow-list decides when a strict schema mode is sent.
- Note: From LG-32: take `not` out in the same call as the constraint keywords, write `oneOf` as `anyOf` before sending, use the emulated route when `SchemaShape` says the reduced schema may be tighter (G89), and run `isRecursive()` and `everyObjectIsClosed()` on the copy that is sent.
- Note: From LG-31: the adapter needs a test with a header setting filled in, to show none of its own headers clashes.
- Note: From LG-30: the shared set tests the retry sign only on a 503 and a refusal only with text; the adapter needs its own unit tests for a 429 that forbids a retry and for a refusal without text.
- Note: From LG-29: an empty JSON object sealed as an array comes back as `[]`; an adapter that must send provider data back unchanged seals the provider's raw JSON text as one string, not the decoded arrays.
- Note: G69: the provider documents no default for the maximum of output tokens; the default is preset data
  (`default_max_output_tokens`).
- Scope: `Adapter\Anthropic\MessagesAdapter`, section 4.1, with 5.1 (thinking blocks), 5.2, 5.6 (`x-should-retry`,
  the spend-limit code), 5.9 and section 10.
- Extra acceptance: a request without maximum tokens is sent with the preset's default, never an error to the caller;
  a request with a temperature is refused as `not_supported` and nothing is sent; a transcript that ends with an
  assistant message is `rejected` before sending; the tool results of one round arrive as one user message; an
  `error` event in a stream gives the reason of its type; a schema with a refused constraint keyword is sent without
  it and the answer is checked against the caller's full schema; a recursive schema takes the emulated route.

### LG-15 · The generate-content adapter
- Status: done · Needs: LG-29, LG-30, LG-31, LG-32
- Note: From LG-13: `Adapter\Shared\RawJson` cuts a member out of a body as text; anything sealed as one string must pass the size and container limits as text before it is decoded, in both directions and per stream piece (G100).
- Note: From LG-12: copy its structure (Dialect, Reading, ErrorBody, fixtures builder, the test files under tests/Unit/Adapter/OpenAiCompatible); the base now keeps an echoed credential out of the model name, the model list and tool calls; a finish word for tool calls without a call is `Other`; an allow-list decides when a strict schema mode is sent.
- Note: From LG-31: the adapter needs a test with a header setting filled in, to show none of its own headers clashes.
- Note: From LG-30: the shared set tests the retry sign only on a 503 and a refusal only with text; the adapter needs its own unit tests for a 429 that forbids a retry and for a refusal without text.
- Note: From LG-29: an empty JSON object sealed as an array comes back as `[]`; an adapter that must send provider data back unchanged seals the provider's raw JSON text as one string, not the decoded arrays.
- Note: `structured_output_field` is not confirmed: the provider's pages give three forms (section 4.2).
- Note: From LG-14: guard sums of usage counts against overflow; refuse a sealed text with a repeated member name (`RawJson`); on the way back send only documented members with the expected type; keep a set of taken call ids.
- Scope: `Adapter\Gemini\GenerateContentAdapter`, section 4.2, with 5.1 (thought signatures), 5.2 (a blocked prompt
  is `rejected`), 5.6, 5.9 and section 10; one adapter for the three entries `gemini`, `vertex-ai` and
  `vertex-ai-eu`, which differ only in base URL, sign-in and options.
- Extra acceptance: fixture sets for a key in a header and for a token from a supplier pass the shared set; a call
  without an id gets one and that id is not sent back; a hand-built assistant message with a tool call is sent with
  the documented skip value; a 400 with the invalid-key reason is `credentials_rejected`; both spellings of a finish
  reason are read; the answer is still checked against the caller's full schema.

### LG-16 · The Chat v2 adapter
- Status: done · Needs: LG-29, LG-30, LG-31, LG-32
- Note: From LG-15: no wall-clock bound in a test; a hand-built argument text with a repeated member name is not sent verbatim; the agreement check compares raw text in one spelling, in order.
- Note: From LG-13: `Adapter\Shared\RawJson` cuts a member out of a body as text; anything sealed as one string must pass the size and container limits as text before it is decoded, in both directions and per stream piece (G100).
- Note: From LG-12: copy its structure (Dialect, Reading, ErrorBody, fixtures builder, the test files under tests/Unit/Adapter/OpenAiCompatible); the base now keeps an echoed credential out of the model name, the model list and tool calls; a finish word for tool calls without a call is `Other`; an allow-list decides when a strict schema mode is sent.
- Note: From LG-32: take `not` out in the same call as the constraint keywords, write `oneOf` as `anyOf` before sending, use the emulated route when `SchemaShape` says the reduced schema may be tighter (G89), and run `isRecursive()` and `everyObjectIsClosed()` on the copy that is sent.
- Note: From LG-31: the adapter needs a test with a header setting filled in, to show none of its own headers clashes.
- Note: From LG-30: the shared set tests the retry sign only on a 503 and a refusal only with text; the adapter needs its own unit tests for a 429 that forbids a retry and for a refusal without text.
- Note: From LG-29: an empty JSON object sealed as an array comes back as `[]`; an adapter that must send provider data back unchanged seals the provider's raw JSON text as one string, not the decoded arrays.
- Note: `schema_key` is not confirmed: reference against guide (section 4.3).
- Note: From LG-14: guard sums of usage counts against overflow; refuse a sealed text with a repeated member name (`RawJson`); on the way back send only documented members with the expected type; keep a set of taken call ids.
- Scope: `Adapter\Cohere\ChatV2Adapter`, section 4.3, with 5.1 (the tool plan), 5.2, 5.9 and section 10.
- Extra acceptance: one named tool is forced by sending only that tool; tools with a response format are sent as
  tools with the schema in the system text; a usage count of `12.0` is read as 12; an error in the last stream event
  is `provider_error`.

### LG-17 · Azure OpenAI variant
- Status: dropped · Needs: none
- Note: Dropped by LG-11 (G65). The v1 address form needs no variant: the deployment name is the request's model,
  the resource is a placeholder of the base URL, and no API version is sent. Its work moved to LG-12 (the adapter)
  and LG-21 (the preset). Its criterion "a wrong API version comes back as `rejected`" is gone: no version is sent.

### LG-34 · The Responses adapter
- Status: todo · Needs: LG-29, LG-30, LG-31, LG-32
- Note: From LG-16: count what is kept per kind, so a text that is never kept cannot drop what is; give every stream counter a boundary test; a whole answer and a stream must read odd tool-call shapes the same way.
- Note: From LG-15: no wall-clock bound in a test; a hand-built argument text with a repeated member name is not sent verbatim; the agreement check compares raw text in one spelling, in order.
- Note: From LG-13: `Adapter\Shared\RawJson` cuts a member out of a body as text; anything sealed as one string must pass the size and container limits as text before it is decoded, in both directions and per stream piece (G100).
- Note: From LG-12: copy its structure (Dialect, Reading, ErrorBody, fixtures builder, the test files under tests/Unit/Adapter/OpenAiCompatible); the base now keeps an echoed credential out of the model name, the model list and tool calls; a finish word for tool calls without a call is `Other`; an allow-list decides when a strict schema mode is sent.
- Note: From LG-32: the provider's file names limits the row of 5.9 omits (root may not be an `anyOf`, total length of names and enum values, number of enum values); check them with the value method of `SchemaShape` (G89) before sending `strict`.
- Note: From LG-31: the adapter needs a test with a header setting filled in, to show none of its own headers clashes.
- Note: From LG-30: the shared set tests the retry sign only on a 503 and a refusal only with text; the adapter needs its own unit tests for a 429 that forbids a retry and for a refusal without text.
- Note: From LG-29: an empty JSON object sealed as an array comes back as `[]`; an adapter that must send provider data back unchanged seals the provider's raw JSON text as one string, not the decoded arrays.
- Note: G59 is decided (2026-11-27): the Responses adapter is built. The entries `openai`, `openai-eu`, `azure-openai` and `grok` use it.
- Note: Starts only when G59 is decided as (a) or (c); dropped when it is decided as (b). See section 3.
- Note: From LG-14: guard sums of usage counts against overflow; refuse a sealed text with a repeated member name (`RawJson`); on the way back send only documented members with the expected type; keep a set of taken call ids.
- Scope: first read the provider's pages on the Responses format for OpenAI Platform, Azure OpenAI and Grok and add
  what is found to their files under `docs/providers/` (the stream events, the item shapes and the error body were
  read only in part). Then `Adapter\OpenAiResponses\ResponsesAdapter`: every request with `store: false`, never
  `previous_response_id`, the whole transcript resent, reasoning items carried in the opaque part.
- Extra acceptance: a test that no request is ever sent without `store: false` and none with
  `previous_response_id`.

## S4 Presets

Acceptance for each preset: **it names its adapter, default base URL
or "the customer gives one", credential kind, the fields the customer fills in, the data location as documented with
its link, its option not to store and its storage note; its options are those of `docs/design/adapters.md` section 9
and its capabilities those of section 6.2; a test builds a gateway from it with the fake transport and gets an
answer.**

Every entry is one file `src/Preset/Catalogue/<Name>.php` (section 8). The wording of `description`, `dataLocation`
and `storageNote` comes from the "Data" section of the provider's file (sections 7 and 11); a quote that carries
weight is compared with the provider's page first.

### LG-18 · Presets: Grok, Kimi, STACKIT
- Status: todo · Needs: LG-12
- Note: From LG-12: a stream that ends without `[DONE]` is accepted as complete (design 2.1); `kimi.md` calls it incomplete. This task decides whether `kimi` needs an option for it.
- Note: From LG-31: no field of an entry names a header that carries a credential at that provider (the neutral reserved list cannot know provider header names).
- Note: G59 is decided as (a): the `grok` entry is built on the Responses adapter, so it also needs LG-34. Kimi and STACKIT stay on the chat-completions adapter.
- Note: The OpenAI Platform preset moved to LG-33, which waits for decision G59; this task does not wait.
- Note: G59 (open) may later change the adapter of `grok`; it is built on the chat-completions adapter now.
- Scope: the entries `grok`, `kimi`, `kimi-cn` and `stackit` (section 9, rows 16 to 19). Kimi is two entries: two
  hosts under different companies and law (G73).
- Extra acceptance: a gateway built from `kimi` never sends to the host of `kimi-cn`, also after a 401.

### LG-19 · Presets: Aleph Alpha (PhariaAI), LightOn (Paradigm), LM Studio, other OpenAI-compatible
- Status: todo · Needs: LG-12
- Note: From LG-31: no field of an entry names a header that carries a credential at that provider (the neutral reserved list cannot know provider header names).
- Scope: the entries `aleph-alpha`, `lighton`, `lm-studio` and `openai-compatible` (section 9, rows 9, 11, 20, 21;
  section 7). The base URL of `aleph-alpha` is used exactly as the customer gave it, with or without `/v1`. LM Studio
  has no key by default. "Other" is G15.
- Extra acceptance: the model list of `aleph-alpha` and of `lighton` is read from the path and the id field their
  options name.

### LG-20 · Presets: Silo AI, Pleias, Kyutai
- Status: todo · Needs: LG-12
- Note: From LG-12: `inline_reasoning: strip` cuts only a block that opens with `<think>`; `pleias.md` says the template opens the block, so the text may start inside it. This task decides, from the provider's file, whether to also cut up to the first `</think>`.
- Note: From LG-31: no field of an entry names a header that carries a credential at that provider (the neutral reserved list cannot know provider header names).
- Note: G62 is decided (2026-11-27): Kyutai is dropped. Build `silo-ai` and `pleias` only; no `kyutai` entry.
- Scope: the entries `silo-ai`, `pleias` and `kyutai` (section 9, rows 10, 12, 15; section 7). Without a hosted chat
  API: G9. Each description says in one plain sentence what the customer needs to have running. `silo-ai` and
  `pleias` name the provider's chat models as suggestions; `kyutai` suggests no model (G62).
- Extra acceptance: none of the three is missing; a preset without a base URL from the customer fails as
  `not_configured` before any request is sent; an answer of `pleias` that starts with a thinking block comes back
  without it.

### LG-21 · Presets: Claude, Gemini and Vertex AI, Cohere, Mistral, Microsoft Copilot / Azure OpenAI
- Status: todo · Needs: LG-13, LG-14, LG-15, LG-16
- Note: From LG-15: the three entries differ only in base URL, sign-in and options; the `vertex-ai` base URLs carry the publisher path, and a model name is a bare id (G113); `structured_output_field` is an option and not confirmed.
- Note: From LG-13: how a streamed content list is joined for the `mistral` entry is not confirmed by the provider's file (G100); the live check of LG-27 must cover a streamed answer with thinking and its next round.
- Note: From LG-31: no field of an entry names a header that carries a credential at that provider (the neutral reserved list cannot know provider header names).
- Note: G59 is decided as (a): the `azure-openai` entry is built on the Responses adapter, so it also needs LG-34. G62: the catalogue has no `kyutai` entry; its count is twenty.
- Note: G60, G75: add the catalogue `Preset\Presets` (`all()`, `find()`, `ids()`; section 8) with a test that every entry of section 9 that is built is in it, in that order, and every id is unique.
- Note: G65: LG-17 is dropped; `azure-openai` is an entry on the chat-completions adapter. G59 (open) may later change its adapter.
- Note: Before the six model overrides of `claude` are written, record the ids of the six models in `docs/providers/anthropic.md` from the provider's page; the file holds their display names and one id form only (section 5.4).
- Note: From LG-14: the entry must set `api_version` and `default_max_output_tokens`, and `temperature: not_accepted` for the version that refuses one (G104, G105).
- Scope: the entries `claude`, `gemini`, `vertex-ai`, `vertex-ai-eu`, `cohere`, `mistral`, `mistral-eu` and
  `azure-openai` (section 9, rows 3 to 8, 13, 14). The Microsoft entry says that Copilot has no model API (section 7).
- Extra acceptance: `vertex-ai` and `azure-openai` resolve their base URL from their settings and also take a base
  URL from the customer; `azure-openai` builds with each of its three credential kinds.

### LG-33 · Presets: OpenAI Platform
- Status: todo · Needs: LG-34
- Note: From LG-31: no field of an entry names a header that carries a credential at that provider (the neutral reserved list cannot know provider header names).
- Note: G59 is decided as (a): these entries are built on the Responses adapter of LG-34.
- Note: Split from LG-18 so that no other preset waits. When G59 is (a) or (c) it also needs LG-34.
- Scope: the entries `openai` and `openai-eu` (section 9, rows 1 and 2), on the adapter G59 names. When G59 is (b):
  first record in `docs/providers/openai.md` the ids of the models on which tool calling is absent, then write them
  as model overrides (section 5.4).
- Extra acceptance: both entries are in the catalogue; every request holds `store: false`.

## S5 Loop and checks

### LG-22 · The tool loop
- Status: todo · Needs: LG-09
- Note: From LG-15: a supplier token with a space or non-ASCII bytes reaches the sign-in header through the base, and one stream event has no size cap in the base; neither is fixed yet.
- Note: From LG-09: the architecture rule "never runs caller code" reserves the name `Loop\ToolExecutor` for the executor seam; update its list if the loop names it differently.
- Note: G53: an answer that asks for tool calls has no structured result; only the final answer is checked. A check that goes over its limits comes back as an unusable result, not an exception (G52).
- Note: From LG-11 (`docs/design/adapters.md` 5.1, 5.2, 5.3, 12): the loop builds the assistant message of the next round with `Answer::toMessage()`, so the opaque part goes back; every tool call of an answer gets a tool message before the next request, also when a limit stops the loop halfway (two providers refuse a partial set); the loop gives no temperature to a model whose capability says it takes none; an answer that ends as `Refusal` or `ContentFilter` ends the loop with a typed outcome.
- Note: From LG-14: a tool call the adapter could not read goes back to one provider as an empty input; the loop must give the model the error as the tool result (LR-17), with a test.
- Scope: `src/Loop` (LR-14 to LR-17): calls the model, hands each tool call to the caller's executor, feeds results
  back, stops at an answer or at the caller's limits (rounds, tool calls; no defaults); events for every step; an
  unknown tool name or unreadable arguments go back to the model as a readable error; a final structured answer is
  asked for the way the capabilities allow (G16): native structured output, a forced tool call, or the emulation of
  LG-08.
- Acceptance: tests on the fake gateway: answer without tools; two rounds; parallel calls; limit reached (a typed
  outcome, not an exception); executor throws (the error goes to the model, the loop continues); unknown tool; streamed
  and not streamed give the same events apart from text deltas.

### LG-23 · The connection check
- Status: todo · Needs: LG-12
- Note: From LG-31: the connection check must send through `owned()` so header settings are added and checked.
- Note: From LG-11 (`docs/design/adapters.md` 6.3, 5.6): for a capability the preset reports "until checked", the check probes the native route whatever the preset reports, so a host can raise it with `Preset::withAdapterOptions()`; the library keeps no result. A sign-in that fails with a status other than 401 or 403 is reported as "no" with that status (one provider answers 400 for a wrong key).
- Scope: LR-27: five answers (sign-in, model exists, tool call, structured answer, stream), each yes, no or not
  supported, with the provider code. Fixed test text only.
- Acceptance: tests on the fake transport for each answer and for a provider that fails at sign-in (the other four
  are then "not run", and no further request is sent).

### LG-24 · Model lists
- Status: todo · Needs: LG-21
- Note: From LG-11: the readers are built with their adapters (LG-12, LG-14 to LG-16); this task proves every entry.
- Scope: `docs/design/adapters.md` section 10: for every preset entry, the model list as its table says, or
  `not_supported` with the capability saying so. A list on another host or outside the base URL is never requested
  (LR-29).
- Acceptance: a fixture per row of that table; an unknown field in a provider's model card never breaks the list; an
  entry that says it does not serve chat is left out; one request per call, also where the provider pages its list.

## S6 Finish

### LG-25 · The hand-run live check
- Status: todo · Needs: LG-23
- Scope: `live/check.php <preset>`: reads the key and settings from the environment, runs the connection check and
  one tiny completion through the PSR-18 bridge with Guzzle, prints the five answers. `live/README.md` says which
  environment variables each preset needs. Not part of any Composer script that the suite runs.
- Acceptance: with no key in the environment it says what is missing and sends nothing; `composer check` does not
  touch `live/`.

### LG-26 · Documentation
- Status: todo · Needs: LG-22, LG-23, LG-24
- Note: G60: the README shows how to list the providers (the preset catalogue) and then the models of one provider (`models()`), as the two steps of a "choose provider and model" screen.
- Note: G34: the README has a section "A new model or provider that the library does not know yet", with the three cases and a runnable example of a preset and an adapter in the host project.
- Note: From LG-11: the README takes its option names from `docs/design/adapters.md` sections 2.2 and 2.3 and its catalogue methods from section 8 (they are final now); it tells a caller to keep the parts of an assistant message it does not know (5.1), names the new finish reason and the temperature capability (5.2, 5.3), and shows how a host raises an "until checked" capability after the connection check (6.3). The table of presets has one row per entry of section 9.
- Scope: `README.md` (LR-45): install, the first call, streaming, tools and the loop, structured output, errors,
  capabilities, writing a transport, the fake in tests, adding a preset, adding an adapter; a table of the presets
  with what the customer needs for each; `docs/migrating-from-organiseyou-gateway.md`: how the application's present
  `AiRequest`, `AiResponse`, `AiToolCall`, `AiGatewayException` reasons and `converse()` map to the contract.
  `CHANGELOG.md` for the first version.
- Acceptance: every code sample in the README runs as a test against the fake.

### LG-27 · Run the live check with real keys
- Status: needs-joris · Needs: LG-25
- Note: From LG-16: not confirmed and to be seen live: which schema key counts; whether a plan joined from stream pieces is taken back; whether a transcript may hold calls to tools missing from `tools` when one tool is forced; whether plain-text tool content is accepted; a transcript of system messages only or ending with an assistant message.
- Note: From LG-15: not confirmed and to be seen live: whether the skip value is accepted for a hand-built call; whether a parts list joined from a stream is taken back; what real call ids look like and whether results match without an id; which structured-output field counts; whether an empty text part or two `user` contents in a row are accepted.
- Note: From LG-13: how a streamed content list is joined for the `mistral` entry is not confirmed by the provider's file (G100); the live check of LG-27 must cover a streamed answer with thinking and its next round.
- Note: From LG-14: not confirmed and to be seen live: a thinking list joined from a stream is accepted back; what the provider answers when thinking is left out of the next round.
- For Joris: run `php live/check.php <preset>` for each provider you have an account for, and write the outcome per
  preset in `docs/providers/live-results.md`. Presets without a run stay marked "not run against the real provider".

### LG-28 · Publish the first version
- Status: needs-joris · Needs: LG-26, LG-27 · Blocked by decision G11
- For Joris: decide where the package is published (G11), then release it.
