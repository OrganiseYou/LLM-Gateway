# Tasks

Build order, top to bottom. A task may start when every
task in its "Needs" is `done`. Status is one of `todo`, `doing`, `done`, `blocked`, `needs-joris`; change only the
status line of a task and add a short "Note:" line under it when something is worth knowing.

Each slice is one branch and one pull request. The requirement ids (LR-nn) are in `docs/requirements.md`, the decision
ids (Gn) in `docs/plan/decisions.md`.

| Slice | Branch | Tasks | Gate |
|---|---|---|---|
| S1 Contract | `slice/s1-contract` | LG-01 to LG-09 | `composer check` green; the fake passes the shared test set |
| S2 Providers read | `slice/s2-providers` | LG-10, LG-11 | fifteen provider files, one adapter map |
| S3 Adapters | `slice/s3-adapters` | LG-12 to LG-17 | every adapter passes the shared test set |
| S4 Presets | `slice/s4-presets` | LG-18 to LG-21 | sixteen presets, each resolves to an adapter and passes the preset test |
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
- Status: todo · Needs: LG-02
- Scope: credential value objects for the kinds in the design (bearer key, header key, key with resource/deployment/
  API version, access token with project and region, token supplier callback for short-lived tokens, none); a way for
  the caller to receive the secret values to redact before the first request (LR-26).
- Acceptance: `var_export`, `json_encode`, `print_r`, string casts and exception traces of a credential object show
  no secret (tested); nothing in `src/` reads the environment or a file (architecture test).

### LG-07 · The gateway interface, retries and streaming
- Status: todo · Needs: LG-03, LG-04, LG-05, LG-06
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
- Status: todo · Needs: LG-03
- Scope: decode and check an answer against its JSON Schema with `opis/json-schema` (G13); reasons as
  "path: message"; "cut off at the token limit" from the finish reason; the shared helper adapters use to emulate
  structured output for a provider without it (schema in the instructions, then the same check).
- Acceptance: unit tests for valid, not JSON, schema mismatch, cut off, JSON wrapped in a code fence; an unusable
  answer is returned with reasons, never thrown (LR-33).

### LG-09 · The fake, the shared test set and the architecture tests
- Status: todo · Needs: LG-07, LG-08
- Note: G34: add a preset and an adapter defined under `tests/`, outside `src/`, that pass the shared test set.
- Scope: `src/Testing/FakeGateway` (scripted answers, tool calls, streams, failures; records the requests it got);
  `src/Testing/ContractTestCase`: the shared test set of LR-40, written once, that an adapter runs by supplying its
  fixtures; `tests/Architecture`: the rules of the project guide as tests, including a list of provider names (G3) that must
  not appear under `src/Contract`, `src/Loop`, `src/Testing` or `src/Transport`.
- Acceptance: the fake passes the shared test set; an architecture test fails when a provider name is added to
  `src/Contract` (shown by a test that checks the rule on a sample file).

## S2 Providers read

### LG-10 · Read the documentation of the fifteen providers
- Status: todo · Needs: LG-02
- Scope: one file `docs/providers/<provider>.md` for each provider in G3, and
  `docs/providers/overview.md` with one row per provider: hosted chat API or not; API format; base URL; sign-in; tool
  calling; parallel tool calls; structured output; tools with structured output; streaming; model list endpoint;
  the option not to store or train; where data is processed; hosted by the vendor or run by the customer.
- Acceptance: fifteen files, each cell with a link and the date read, or "not confirmed"; no cell from memory;
  providers without a hosted text chat API are named plainly.

### LG-11 · The adapter map
- Status: todo · Needs: LG-10
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

### LG-12 · OpenAI-compatible adapter
- Status: todo · Needs: LG-09, LG-11
- Note: G34: no closed list of models; an unknown model gets the format's default capabilities; a preset can state different capabilities through `adapterOptions`. The same holds for LG-13 to LG-17.
- Scope: the chat-completions format: messages, tools, tool choice, JSON-schema response format, streaming, usage,
  model list. Options for the differences between servers that speak this format (no model list, no strict schema, no
  usage in the stream), set by the preset.
- Extra acceptance: fixtures for a strict server and for a minimal one (no tool calling, no schema support); the
  minimal one reports its capabilities truthfully and structured output is emulated.

### LG-13 · Mistral adapter
- Status: todo · Needs: LG-12
- Scope: as the adapter map says: its own adapter, or options on LG-12. Tool-call arguments may arrive as a string or
  as an object; content may be a string or a list of chunks.
- Extra acceptance: both argument forms and both content forms are covered by fixtures.

### LG-14 · Anthropic adapter
- Status: todo · Needs: LG-09, LG-11
- Scope: the Messages format: system text apart from the messages, content blocks, tool use and tool result blocks,
  the required maximum tokens, its header sign-in and version header, its stream events, structured output as the
  documentation of LG-10 describes it.
- Extra acceptance: a request without maximum tokens gets the adapter's documented default, never an error to the
  caller.

### LG-15 · Gemini adapter
- Status: todo · Needs: LG-09, LG-11
- Scope: the generate-content format: contents and parts, function declarations and function responses, the schema
  dialect it accepts for structured output, its stream; both ways in that LG-10 found (a key, and a Google Cloud
  project with region and an access token from the caller's token supplier).
- Extra acceptance: a JSON Schema keyword the provider does not accept is removed or rewritten by the adapter and the
  answer is still checked against the caller's full schema.

### LG-16 · Cohere adapter
- Status: todo · Needs: LG-09, LG-11
- Scope: its chat format with tools, structured output and streaming, as LG-10 found it.

### LG-17 · Azure OpenAI variant
- Status: todo · Needs: LG-12
- Scope: the resource address, deployment name and API version in the request address as LG-10 found them; sign-in
  with a key header or with a Microsoft Entra token from the caller's token supplier; content-filter refusals mapped
  to `rejected` with the provider code.
- Extra acceptance: the "model" of the neutral request maps to the deployment; a wrong API version comes back as
  `rejected`, not `provider_error`.

## S4 Presets

Acceptance for each preset: **it names its adapter, default base URL
or "the customer gives one", credential kind, the fields the customer fills in, the data location as documented with
its link, and its option not to store; a test builds a gateway from it with the fake transport and gets an answer.**

### LG-18 · Presets: OpenAI Platform, Grok, Kimi, STACKIT
- Status: todo · Needs: LG-12

### LG-19 · Presets: Aleph Alpha (PhariaAI), LightOn (Paradigm), LM Studio, other OpenAI-compatible
- Status: todo · Needs: LG-12
- Scope: these may run on the customer's own servers: the base URL is always the customer's when the documentation
  says so. LM Studio has no key by default. "Other" is G15.

### LG-20 · Presets: Silo AI, Pleias, Kyutai
- Status: todo · Needs: LG-12
- Scope: as LG-10 found them. Without a hosted chat API: G9 (a named preset for the model the customer runs on its
  own OpenAI-compatible server). Each preset's description says in one plain sentence what the customer needs to
  have running, and names the provider's text models LG-10 found.
- Extra acceptance: none of the three is missing; a preset without a base URL from the customer fails as
  `not_configured` before any request is sent.

### LG-21 · Presets: Claude, Gemini and Vertex AI, Cohere, Mistral, Microsoft Copilot / Azure OpenAI
- Status: todo · Needs: LG-13, LG-14, LG-15, LG-16, LG-17
- Scope: one preset each; Gemini with a key and Vertex AI with a Google Cloud project are two entries of the same
  provider if LG-10 found both. The Microsoft preset states what LG-10 found about Copilot having a model API or not.

## S5 Loop and checks

### LG-22 · The tool loop
- Status: todo · Needs: LG-09
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
- Scope: LR-27: five answers (sign-in, model exists, tool call, structured answer, stream), each yes, no or not
  supported, with the provider code. Fixed test text only.
- Acceptance: tests on the fake transport for each answer and for a provider that fails at sign-in (the other four
  are then "not run", and no further request is sent).

### LG-24 · Model lists
- Status: todo · Needs: LG-21
- Scope: the model list operation for every adapter whose provider documents one; `not_supported` for the others,
  with the capability saying so.
- Acceptance: a fixture per adapter; an unknown field in a provider's model card never breaks the list.

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
- Note: G34: the README has a section "A new model or provider that the library does not know yet", with the three cases and a runnable example of a preset and an adapter in the host project.
- Scope: `README.md` (LR-45): install, the first call, streaming, tools and the loop, structured output, errors,
  capabilities, writing a transport, the fake in tests, adding a preset, adding an adapter; a table of the presets
  with what the customer needs for each; `docs/migrating-from-organiseyou-gateway.md`: how the application's present
  `AiRequest`, `AiResponse`, `AiToolCall`, `AiGatewayException` reasons and `converse()` map to the contract.
  `CHANGELOG.md` for the first version.
- Acceptance: every code sample in the README runs as a test against the fake.

### LG-27 · Run the live check with real keys
- Status: needs-joris · Needs: LG-25
- For Joris: run `php live/check.php <preset>` for each provider you have an account for, and write the outcome per
  preset in `docs/providers/live-results.md`. Presets without a run stay marked "not run against the real provider".

### LG-28 · Publish the first version
- Status: needs-joris · Needs: LG-26, LG-27 · Blocked by decision G11
- For Joris: decide where the package is published (G11), then release it.
