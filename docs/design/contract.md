# The contract: design

Task LG-02. Written 2026-11-26. Requirement ids (LR-nn) are in `docs/requirements.md`, decision ids (Gn) in
`docs/plan/decisions.md`. This file names every public class of slice S1, its fields, its validation and the task
that builds it. A rule written as "throws" means `InvalidArgumentException` from the constructor unless another class
is named.

Words used here: a **format** is one wire format of a chat API; an **adapter** speaks one format; a **preset** is the
data for one provider; the **host** or **caller** is the application that uses the library.

## 1. Overview

```
Preset + Credentials + Transport + GatewayConfig
        │  GatewayFactory::create()
        ▼
     Gateway  ── complete(Request): Answer
              ── stream(Request): iterable<StreamEvent>
              ── models(...): list<ModelInfo>
              ── capabilities(model): Capabilities
              ── check(...): ConnectionReport
```

- The caller sees `Gateway` and the value objects of `src/Contract`. Nothing else (LR-03).
- No class under `src/Contract`, `src/Transport`, `src/Loop`, `src/Testing` or `src/Adapter/Shared` holds a provider's
  name, header, address or wire word (LR-04). Such data lives in a preset or in a format's adapter.
- Every object is immutable. Nothing is kept between two calls (LR-01).

### 1.1 Namespaces and folders

| Namespace | Folder | Holds |
|---|---|---|
| `OrganiseYou\LlmGateway` | `src/` | `GatewayFactory`, `GatewayConfig` |
| `OrganiseYou\LlmGateway\Contract` | `src/Contract/` | `Gateway`, request and answer values, errors, capabilities, `Secret`, `Operation` |
| `OrganiseYou\LlmGateway\Contract\Stream` | `src/Contract/Stream/` | the stream events |
| `OrganiseYou\LlmGateway\Contract\Credentials` | `src/Contract/Credentials/` | the credential kinds, `TokenSupplier` |
| `OrganiseYou\LlmGateway\Transport` | `src/Transport/` | `Transport`, HTTP values, the PSR-18 bridge, the attempt observer, `Clock` |
| `OrganiseYou\LlmGateway\Adapter\Shared` | `src/Adapter/Shared/` | the adapter base, the server-sent-events reader, the structured-answer checker |
| `OrganiseYou\LlmGateway\Preset` | `src/Preset/` | `Preset`, `PresetField`, `BaseUrlRule` and, from slice S4, the preset data |
| `OrganiseYou\LlmGateway\Testing` | `src/Testing/` | `FakeGateway`, `FakeTransport`, `FakeClock`, `ContractTestCase` |

### 1.2 Classes by task

| Task | Classes |
|---|---|
| LG-03 | `Contract\`: `Role`, `ContentPart`, `TextPart`, `Message`, `JsonSchema`, `ToolDefinition`, `ToolCall`, `ToolChoiceMode`, `ToolChoice`, `ResponseFormat`, `Request`, `Usage`, `FinishReason`, `StructuredIssueKind`, `StructuredIssue`, `StructuredResult`, `Answer` |
| LG-04 | `Contract\`: `ErrorReason`, `GatewayException`, `Support`, `Capabilities`, `ModelInfo` |
| LG-05 | `Contract\Secret`, `Contract\Operation`; `Transport\`: `Transport`, `HttpRequest`, `HttpResponse`, `HttpStreamResponse`, `TransportFailure`, `TransportException`, `Psr18Transport`, `AttemptObserver`, `AttemptStarted`, `AttemptFinished`, `AttemptOutcome`, `NullAttemptObserver`, `Clock`, `SystemClock`; `Testing\FakeTransport`, `Testing\FakeClock` |
| LG-06 | `Contract\Credentials\`: `Credentials`, `CredentialKind`, `NoCredentials`, `BearerKey`, `HeaderKey`, `BearerToken`, `TokenSupplier`; the leak tests for these and for `Secret` |
| LG-07 | `Contract\Gateway`, `Contract\RetryPolicy`, `Contract\CheckStatus`, `Contract\CheckResult`, `Contract\ConnectionReport`; `Contract\Stream\`: `StreamEvent`, `TextDelta`, `ToolCallStarted`, `ToolCallCompleted`, `UsageReported`, `StreamFinished`, `StreamFailed`; `GatewayConfig`, `GatewayFactory`; `Preset\Preset`, `Preset\PresetField`, `Preset\BaseUrlRule`; `Adapter\Shared\AbstractAdapter`, `Adapter\Shared\SseReader`, `Adapter\Shared\SseEvent` |
| LG-08 | `Adapter\Shared\StructuredAnswerChecker`, `Adapter\Shared\StructuredOutputEmulation` |
| LG-09 | `Testing\FakeGateway`, `Testing\ContractTestCase`, the tests under `tests/Architecture` |

`Secret` is built in LG-05, not LG-06, because `HttpRequest` needs it and LG-05 comes first. LG-06 adds the leak tests.

Every class marked "value" below is `final readonly class`. Every enum is string-backed with the values shown.

## 2. The interface (LG-07)

`OrganiseYou\LlmGateway\Contract\Gateway`, an interface. Meets LR-03, LR-05, LR-09.

```php
namespace OrganiseYou\LlmGateway\Contract;

interface Gateway
{
    /** @throws GatewayException */
    public function complete(Request $request): Answer;

    /** @return iterable<int, Stream\StreamEvent> */
    public function stream(Request $request): iterable;

    /**
     * @return list<ModelInfo>
     * @throws GatewayException
     */
    public function models(float $timeoutSeconds, ?string $traceId = null): array;

    public function capabilities(string $model): Capabilities;

    public function check(string $model, float $timeoutSeconds, ?string $traceId = null): ConnectionReport;
}
```

| Operation | Rules |
|---|---|
| `complete` | One completion. Throws `GatewayException` on a transport or provider failure. An unusable structured answer is returned, never thrown (LR-33). |
| `stream` | The same completion as events (section 6). Lazy: nothing is sent before the caller starts to iterate. Throws no `GatewayException`: every failure is the event `StreamFailed` (G25). |
| `models` | The models the credentials may use. Throws `GatewayException` with reason `not_supported` when `Capabilities::$modelListing` is `none`. `$timeoutSeconds` must be finite and above 0, else `InvalidArgumentException`. |
| `capabilities` | Answers from preset data and the adapter's knowledge. Sends no request and cannot fail (G27). `$model` follows the model-name rule of section 3.9, else `InvalidArgumentException`. |
| `check` | The connection check of LR-27 (section 9). A provider failure is an answer "no", not an exception. `$timeoutSeconds` applies to each request of the check. |

- There is no chat-only operation, no conversation id and no stored credential in any signature (LR-09, G4).
- Credentials are bound to the gateway object when it is built, not passed per call (G20, LR-25).
- `$traceId`, when given, must match `^[A-Za-z0-9_.:-]{1,128}$`; when null the library makes one (section 8.5).

## 3. Request values (LG-03)

All in `OrganiseYou\LlmGateway\Contract`. Meet LR-06, LR-08, LR-11, LR-13.

### 3.1 `Role` (enum)

`System = 'system'`, `User = 'user'`, `Assistant = 'assistant'`, `Tool = 'tool'`.

### 3.2 `ContentPart` (interface) and `TextPart` (value)

```php
interface ContentPart {}
```

A marker interface. The first version has one implementation. A later version adds part classes without changing
`Message` (LR-13, G10). Callers and adapters test parts with `instanceof` and must skip a part class they do not know.

`TextPart`:

| Field | Type | Validation |
|---|---|---|
| `text` | `string` | Throws when it is not valid UTF-8. An empty string is allowed. |

### 3.3 `Message` (value)

| Field | Type | Meaning |
|---|---|---|
| `role` | `Role` | |
| `parts` | `list<ContentPart>` | The content, always a list (G10). |
| `toolCalls` | `list<ToolCall>` | Calls the assistant asked for. Default `[]`. |
| `toolCallId` | `?string` | For role `Tool`: the id of the call this message answers. Default null. |
| `toolName` | `?string` | For role `Tool`: the name of the tool that was called (G30). Default null. |

Named constructors, the normal way to build one:

```php
public static function system(string $text): self;
public static function user(string $text): self;
public static function assistant(string $text, ToolCall ...$toolCalls): self;
public static function toolResult(string $toolCallId, string $toolName, string $text): self;
public function text(): string;   // the text of all TextPart parts, joined without a separator
```

`assistant('')` with at least one tool call gives an empty `parts` list.

Validation, each throws:

1. `parts` is not a list, or holds a value that is not a `ContentPart`.
2. `toolCalls` holds a value that is not a `ToolCall`.
3. Role `System` or `User`: `parts` is empty, `toolCalls` is not empty, `toolCallId` or `toolName` is not null.
4. Role `Assistant`: `parts` and `toolCalls` are both empty; `toolCallId` or `toolName` is not null.
5. Role `Tool`: `parts` is empty; `toolCalls` is not empty; `toolCallId` is null or empty; `toolName` is null.
6. Role `Assistant`: two tool calls have the same `id`.

A tool result is a message, so the library never runs a tool (LR-14). A tool error goes back as readable text in a
tool-result message (LR-17).

### 3.4 `JsonSchema` (value)

A JSON Schema, kept as JSON text so an empty object stays an object.

| Field | Type |
|---|---|
| `json` | `string` (canonical: `json_encode` of the decoded input, with `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE`) |

```php
public static function fromJson(string $json): self;
/** @param array<string, mixed> $schema */
public static function fromArray(array $schema): self;
/** @return array<string, mixed> */
public function toArray(): array;
public function toObject(): \stdClass;   // the form opis/json-schema reads
```

The constructor is private. Validation, each throws:

1. `fromJson`: the text is not valid JSON, or its root is not a JSON object.
2. `fromArray`: the array is a non-empty list, or cannot be encoded as JSON.
3. Both: the root has no `"type": "object"` (G23).

`fromArray` encodes an empty PHP array as `{}` at the root and as the value of `properties`, `patternProperties`,
`$defs`, `definitions`, `dependentSchemas` and `dependentRequired`, at any depth. Everywhere else an empty array stays
`[]`. No other check is done here: whether the schema is a valid JSON Schema is found when it is used (section 10) or by
the provider (`rejected`).

### 3.5 `ToolDefinition` (value)

| Field | Type | Validation |
|---|---|---|
| `name` | `string` | Throws when empty, or when it holds whitespace or a control character (`\p{Cc}`, `\p{Z}`). No length limit (LR-08). |
| `description` | `string` | Throws when not valid UTF-8. May be empty. |
| `parameters` | `JsonSchema` | The JSON Schema of the arguments. |

### 3.6 `ToolCall` (value)

A call the model asked for. Unreadable arguments are a value, not an exception (LR-07, LR-17).

| Field | Type | Meaning |
|---|---|---|
| `id` | `string` | The call's id. When a format gives none, the adapter makes `call_<n>`, n being the call's position in the answer, from 0. |
| `name` | `string` | As the model wrote it. Not checked: a model may garble it (LR-17). |
| `arguments` | `?array<string, mixed>` | The decoded arguments, or null when they could not be read. |
| `argumentsError` | `?string` | Why they could not be read, in plain words, or null. |
| `rawArguments` | `string` | The arguments as they arrived (for a format that delivers an object: its JSON text). Used to send the call back in the next round. |

```php
/** @param array<string, mixed> $arguments */
public static function decoded(string $id, string $name, array $arguments, string $rawArguments): self;
public static function unreadable(string $id, string $name, string $argumentsError, string $rawArguments): self;
public function isReadable(): bool;   // arguments !== null
```

Validation, each throws:

1. `id` is empty.
2. `arguments` and `argumentsError` are both null, or both not null.
3. `argumentsError` is an empty string.
4. `arguments` is a non-empty list (arguments are a JSON object).

### 3.7 `ToolChoiceMode` (enum) and `ToolChoice` (value)

`ToolChoiceMode`: `Auto = 'auto'`, `None = 'none'`, `Required = 'required'`, `Tool = 'tool'`.

| Field | Type |
|---|---|
| `mode` | `ToolChoiceMode` |
| `toolName` | `?string`, default null |

```php
public static function auto(): self;
public static function none(): self;
public static function required(): self;          // the model must call some tool
public static function tool(string $name): self;  // the model must call this tool: the forced tool call of G16
```

Validation, each throws: mode `Tool` with a null or empty `toolName`; any other mode with a `toolName` that is not null.

### 3.8 `ResponseFormat` (value)

| Field | Type | Validation |
|---|---|---|
| `schema` | `JsonSchema` | |
| `name` | `string` | Same rule as `ToolDefinition::$name`. Default `'answer'`. Needed because the structured answer may travel as a forced tool call (G16). |
| `description` | `string` | Throws when not valid UTF-8. Default `''`. |

### 3.9 `Request` (value)

| Field | Type | Default | Validation (throws when …) |
|---|---|---|---|
| `model` | `string` | required | empty, or holds a control character (G18). `/`, `:` and `.` are allowed. |
| `messages` | `list<Message>` | required | empty; not a list; holds a value that is not a `Message`; a `System` message comes after a message of another role (G24). |
| `timeoutSeconds` | `float` | required | not finite, or not above 0 (LR-36). |
| `tools` | `list<ToolDefinition>` | `[]` | not a list; holds another value; two tools have the same name. |
| `toolChoice` | `ToolChoice` | `new ToolChoice(ToolChoiceMode::Auto)` | mode `Required` or `Tool` while `tools` is empty; mode `Tool` naming a tool that is not in `tools`. |
| `responseFormat` | `?ResponseFormat` | null | `responseFormat->name` equals the name of a tool in `tools`. |
| `maxOutputTokens` | `?int` | null | below 1. Null: the provider's default, or the adapter's documented default where the format requires a value. |
| `temperature` | `?float` | null | not finite, below 0.0 or above 1.0 (G22). Null: the provider's default. |
| `traceId` | `?string` | null | does not match `^[A-Za-z0-9_.:-]{1,128}$`. |

Withers, each returns a new validated `Request`: `withMessages(array $messages)`, `withTools(array $tools)`,
`withToolChoice(ToolChoice $choice)`, `withResponseFormat(?ResponseFormat $format)`. The tool loop (LG-22) uses them.

Not a rule of the request: tools and a response format may be given together (LR-11). Whether a provider can do that is
`Capabilities::$toolsWithStructuredOutput`.

### 3.10 Neutral ranges (LR-08)

| Value | Neutral range | What an adapter does |
|---|---|---|
| Temperature | 0.0 to 1.0 | Sends the same number. An adapter whose format uses another scale maps it and says so in `docs/design/adapters.md`. No silent scaling in the contract (G22). |
| Maximum output tokens | 1 and up, or null | Sends it as given. Never lowers it silently. A value the adapter knows to be above the model's limit is refused. |
| Tool name, response-format name | not empty, no whitespace, no control character, any length | Refuses a name its format cannot carry (length, characters). Never renames a tool. |
| Model name | not empty, no control character (G18) | Sends it as given; where a format calls it something else, the adapter maps it. |
| Timeout | above 0 seconds | Section 8.3. |
| JSON Schema | root type `object` (G23) | Removes or rewrites a keyword its format does not take; the answer is still checked against the caller's full schema (section 10). |

"Refuses" is always: `GatewayException` with reason `rejected`, `httpStatus` null, a `detail` written by the library,
thrown before any request is sent.

## 4. Answer values (LG-03)

All in `OrganiseYou\LlmGateway\Contract`. Meet LR-07, LR-33.

### 4.1 `FinishReason` (enum)

| Case | Meaning |
|---|---|
| `Stop = 'stop'` | The model ended its answer. |
| `ToolCalls = 'tool_calls'` | The model asks for tool calls. |
| `Length = 'length'` | Cut off at the token limit. |
| `ContentFilter = 'content_filter'` | The provider withheld the output. |
| `Refusal = 'refusal'` | The model declined to answer (G67, LG-30). |
| `Other = 'other'` | A reason the format gives that fits none of the above. |

A `match` over `FinishReason` has an arm for every case or a default arm. An answer without text and without a tool
call is returned as an answer when its finish reason is `Length`, `ContentFilter` or `Refusal`; with any other finish
reason it is `provider_error` (section 7.3). A prompt the provider refused is the error `rejected`, never an answer.

### 4.2 `Usage` (value)

| Field | Type | Validation |
|---|---|---|
| `inputTokens` | `?int` | Throws below 0. Null when the provider did not report it. |
| `outputTokens` | `?int` | Throws below 0. Null when the provider did not report it. |

### 4.3 `StructuredIssueKind` (enum), `StructuredIssue` and `StructuredResult` (values)

`StructuredIssueKind`: `NotJson = 'not_json'`, `SchemaMismatch = 'schema_mismatch'`, `CutOff = 'cut_off'`.

`StructuredIssue`:

| Field | Type | Validation |
|---|---|---|
| `kind` | `StructuredIssueKind` | |
| `message` | `string` | Throws when empty. |
| `path` | `?string` | A JSON pointer (`/` for the root). Throws when `kind` is `SchemaMismatch` and it is null, or when `kind` is another case and it is not null. |

`reason(): string` gives `"<path>: <message>"` for a schema mismatch and `<message>` otherwise.

`StructuredResult`:

| Field | Type | Meaning |
|---|---|---|
| `data` | `?array<string, mixed>` | The decoded answer. Null when it could not be decoded. Kept when it decoded but does not match, so the caller can show it. |
| `issues` | `list<StructuredIssue>` | Why the answer is unusable. Empty when it is usable. |

```php
public function isUsable(): bool;        // data !== null && issues === []
/** @return list<string> */
public function reasons(): array;        // reason() of every issue, in order
```

Validation, each throws: `issues` holds a value that is not a `StructuredIssue`; `data` is null while `issues` is empty.

### 4.4 `Answer` (value)

| Field | Type | Validation (throws when …) |
|---|---|---|
| `text` | `string` | not valid UTF-8. May be empty. |
| `structured` | `?StructuredResult` | — Null exactly when the request had no response format. |
| `toolCalls` | `list<ToolCall>` | not a list; holds another value; two calls have the same id. |
| `finishReason` | `FinishReason` | |
| `model` | `string` | empty. The model that answered; the requested model when the provider does not say. |
| `usage` | `Usage` | |
| `latencyMs` | `int` | below 0. The whole call: every attempt and every wait. |
| `attempts` | `int` | below 1. HTTP attempts made for this call. |
| `traceId` | `string` | does not match the trace-id pattern. |

```php
public function toMessage(): Message;   // Message::assistant($this->text, ...$this->toolCalls)
```

`toMessage()` throws `LogicException` when `text` is empty and there are no tool calls. An answer without content is a
`provider_error` before it becomes an `Answer` (section 7.3), so this is not reached through an adapter.

When an adapter gets the structured answer through a forced tool call or through instructions (emulation), the caller
sees no difference: `structured` is filled, the helper call is not in `toolCalls`, and `finishReason` is `Stop` (or
`Length`).

## 5. Capabilities and models (LG-04)

In `OrganiseYou\LlmGateway\Contract`. Meet LR-10, LR-11, G16.

### 5.1 `Support` (enum)

`Native = 'native'`, `Emulated = 'emulated'`, `None = 'none'`. Method `isAvailable(): bool` (true unless `None`).

### 5.2 `Capabilities` (value)

| Field | Type | Question it answers |
|---|---|---|
| `toolCalling` | `Support` | Can the model ask for tool calls? |
| `forcedToolCall` | `Support` | Can the caller force one named tool (`ToolChoice::tool`)? (G16) |
| `parallelToolCalls` | `Support` | Can one answer hold several tool calls? |
| `structuredOutput` | `Support` | Can a response format be given? |
| `toolsWithStructuredOutput` | `Support` | Can tools and a response format go in one request? |
| `streaming` | `Support` | `Native`, or `Emulated`: the whole answer as one text event (LR-12). An adapter never reports `None` here. |
| `modelListing` | `Support` | Does `models()` work? `Native` or `None`. |
| `contextLength` | `?int` | The context length in tokens when it is known without a request, else null. `ModelInfo::$contextLength` gives it per model when the model list tells it. |
| `temperature` | `Support` | Does the model take a temperature? `Native`: the temperature of a request is sent. `None`: the model takes none. The last constructor parameter, with the default `Native` (G68, LG-30). |

Validation, each throws: `contextLength` below 1; `toolCalling` is `None` while `forcedToolCall` or
`parallelToolCalls` is not `None`; `structuredOutput` is `None` while `toolsWithStructuredOutput` is not `None`;
`streaming` is `None`; `modelListing` or `temperature` is `Emulated`.

What the gateway does with them:

| Request asks | Capability | Result |
|---|---|---|
| tools | `toolCalling` is `None` | `not_supported`, before any request |
| `ToolChoice::tool` | `forcedToolCall` is `None` | `not_supported`, before any request |
| a response format | `structuredOutput` is `None` | `not_supported`, before any request |
| tools and a response format | `toolsWithStructuredOutput` is `None` | `not_supported`, before any request |
| a temperature, also 0.0 | `temperature` is `None` | `not_supported`, before any request; the value is never dropped |
| anything `Emulated` | | the adapter does it; the caller sees the normal answer |

`parallelToolCalls` is information for the caller; no request is refused on it. A caller never asks which provider it
talks to (LR-10, LR-11).

### 5.3 `ModelInfo` (value)

| Field | Type | Validation |
|---|---|---|
| `id` | `string` | Model-name rule of section 3.9. The value to put in `Request::$model`. |
| `name` | `?string` | A display name. Throws when an empty string. Default null. |
| `contextLength` | `?int` | Throws below 1. Default null. |
| `aliases` | `list<string>` | Throws when an entry is empty or not a string. Default `[]`. |
| `deprecated` | `bool` | Default false. |

## 6. Stream events (LG-07)

In `OrganiseYou\LlmGateway\Contract\Stream`. Meet LR-12, LR-35. `StreamEvent` is a marker interface; the six classes
are values.

| Class | Fields | When |
|---|---|---|
| `TextDelta` | `string $text` (throws when empty) | A piece of answer text. |
| `ToolCallStarted` | `int $index` (throws below 0), `string $id`, `string $name` | A tool call begins. `index` is its position in the answer. |
| `ToolCallCompleted` | `int $index`, `ToolCall $toolCall` | The call is whole; arguments decoded or with an arguments error. |
| `UsageReported` | `Usage $usage` | The provider reported tokens. At most once. |
| `StreamFinished` | `Answer $answer` | The end. The same `Answer` that `complete` would return, with `structured`, `latencyMs`, `attempts` and `traceId`. |
| `StreamFailed` | `GatewayException $error`, `string $textSoFar`, `list<ToolCall> $toolCallsSoFar` | The end, by failure, with what arrived. |

Rules for every adapter and for the fake:

1. A stream ends with exactly one `StreamFinished` or one `StreamFailed`, and that is the last event.
2. Every `ToolCallStarted` is followed by a `ToolCallCompleted` with the same `index` before `StreamFinished`.
3. The joined text of all `TextDelta` events equals `StreamFinished::$answer->text`.
4. Without native streaming the adapter sends a normal request and yields: one `TextDelta` (when the text is not
   empty), then `ToolCallStarted` and `ToolCallCompleted` per tool call, then `UsageReported` (when known), then
   `StreamFinished` (LR-12). One code path for the caller.
5. A failure before the first event is retried by the retry policy; when it still fails, the only event is
   `StreamFailed` with empty `textSoFar` (G25).
6. A failure after the first event was yielded is never retried: `StreamFailed` with what arrived (LR-35).
7. When the caller stops iterating, the adapter stops reading and the transport's chunk iterator is released.

## 7. Errors (LG-04)

### 7.1 `ErrorReason` (enum)

| Case | Value | When |
|---|---|---|
| `NotConfigured` | `not_configured` | A required setting is missing, the credential kind does not fit the preset, or the host's transport refused the address. |
| `CredentialsRejected` | `credentials_rejected` | The provider refused the sign-in, or the token supplier failed. |
| `RateLimited` | `rate_limited` | The provider asks to slow down. Carries the wait when known. |
| `Unavailable` | `unavailable` | Server error, lost connection, or a redirect. |
| `Rejected` | `rejected` | The provider, or the adapter, refused the request: unknown model, invalid schema, a value out of range. |
| `ProviderError` | `provider_error` | An answer without content, an error inside a successful response, a body that cannot be read. |
| `Timeout` | `timeout` | The call's time ran out. |
| `NotSupported` | `not_supported` | A capability the provider lacks. |

The same eight for every provider (LR-32). No case, and no text of the exception, names a provider.

### 7.2 `GatewayException`

`final class GatewayException extends \RuntimeException implements \JsonSerializable`, with public readonly
properties. The only exception a gateway throws besides `InvalidArgumentException` for a malformed argument.

| Property | Type | Rule |
|---|---|---|
| `reason` | `ErrorReason` | |
| `traceId` | `string` | Trace-id pattern, else throws `InvalidArgumentException`. |
| `httpStatus` | `?int` | Null when no response arrived or no request was sent. Throws outside 100 to 599. |
| `providerCode` | `?string` | The provider's own code, lower-cased, kept only when it then matches `^[a-z0-9_.-]{1,64}$`; any other value becomes null, without an exception. |
| `retryAfterSeconds` | `?int` | Only with `RateLimited` or `Unavailable`. Throws below 0. |
| `attempts` | `int` | HTTP attempts made. 0 when the failure came before any request. Throws below 0. |
| `detail` | `?string` | A sentence written by the library (for example "tool name is longer than this format allows"). Never text from a provider's response, never a header value, never a URL. |

```php
public function __construct(
    ErrorReason $reason,
    string $traceId,
    ?int $httpStatus = null,
    ?string $providerCode = null,
    ?int $retryAfterSeconds = null,
    int $attempts = 0,
    ?string $detail = null,
);
/** @return array{reason: string, trace_id: string, http_status: ?int, provider_code: ?string, retry_after_seconds: ?int, attempts: int, detail: ?string} */
public function jsonSerialize(): array;
```

Rules that keep secrets out (LR-26, G29):

1. `getMessage()` is built by the class from `reason`, `httpStatus`, `providerCode` and `detail` only, for example
   `rate_limited (HTTP 429, code: too_many_requests)`. No caller of the constructor passes a message.
2. There is no `$previous`. A transport's or a token supplier's exception is never chained and its message is never
   copied.
3. The provider's response body is not in the exception. The host gets it through the attempt observer (section 12),
   where its own redaction applies.

### 7.3 From a response to a reason

Owned by `AbstractAdapter` (LG-07). An adapter may refine a row for its format (for example a documented code for an
unknown model), never invent a ninth reason.

| What happened | Reason | Retried |
|---|---|---|
| Status 401 or 403 | `credentials_rejected` | no |
| Status 429 | `rate_limited`, with `retryAfterSeconds` when the response tells it. Not when the response forbids a retry (`retryAllowed()` says no, section 8.2 rule 1): then the error tells no wait (G82) | yes, unless the response forbids it |
| Status 408, or 500 to 599 | `unavailable`; no `retryAfterSeconds` when the response forbids a retry (G82) | yes, unless the response forbids it |
| Status 300 to 399 | `unavailable` | no (LR-29) |
| Any other status 400 to 499 | `rejected` | no |
| Status 200 to 299 with an error in the body, with no text and no tool call (unless the finish reason is `Length`, `ContentFilter` or `Refusal`: that is an answer), or with a body that cannot be decoded | `provider_error` | no |
| `TransportFailure::ConnectionFailed` | `unavailable` | yes |
| `TransportFailure::TimedOut` | `timeout` | no |
| `TransportFailure::Redirected` | `unavailable` | no |
| `TransportFailure::Blocked` | `not_configured` | no (G31) |
| The token supplier throws or returns an empty token | `credentials_rejected` | no |
| The call's time is used up before an attempt | `timeout` | no |
| The request needs a capability that is `None` | `not_supported` | no request sent |
| A value outside what the format can carry | `rejected` | no request sent |

### 7.4 What the library throws: `InvalidValue`, `WrongUse`, `ForeignFault`

Decision G63, which widens G35. Meets LR-26.

Why they exist:

- PHP stores the arguments of every call on the stack in the trace of an exception or an error. Where the library
  throws, a request, a response or a key is among them.
- A parameter marked `#[\SensitiveParameter]` is hidden from the printed forms of a trace only. The trace object holds
  a `\SensitiveParameterValue` in its place, and `getTrace()[n]['args'][0]->getValue()` gives the value back.
- PHP has no switch for one exception. It has only the setting `zend.exception_ignore_args` for the whole process.
  That setting is the host's; the library never changes it.

So the library throws only classes of its own, and each takes the arguments out of its own trace in its constructor.
File, line, class and function stay: the trace says where, never with what.

The three classes, all `final class` in `OrganiseYou\LlmGateway\Contract`:

| Class | Extends | The library throws it for |
|---|---|---|
| `InvalidValue` | `\InvalidArgumentException` | A value it refuses: every "throws" rule of a constructor or an argument in this document. |
| `WrongUse` | `\LogicException` | A use of the library it cannot serve: a call in the wrong state, a fake without a script, a fixture that is missing, a secret that is serialized, a request of an adapter that leaves the base URL (G44). |
| `ForeignFault` | `\Error` | The replacement of a fault (an `\Error`) in code that is not the library's: the HTTP client or its message classes (G40), an adapter written outside the library (G44), the token supplier (G42), the JSON Schema validator (G50). |

Each has the same constructor and no property of its own:

```php
public function __construct(string $message);
```

- `$message` is a text the library wrote. It never holds a value the library was given. For a `ForeignFault` it names
  at most the class of what was thrown, and for an adapter or the validator also the file name without its folder and
  the line.
- There is no `$previous` and no code. What foreign code threw is never chained and its message is never copied, as
  for `GatewayException` (7.2 rule 2).
- A host catches them as the PHP class each extends: `catch (\InvalidArgumentException)` and
  `catch (\LogicException)` keep working. `InvalidValue` is also a `\LogicException`, as its parent is.
- `ForeignFault` is an `\Error` on purpose. It is a fault in code, not a failure of a provider or a connection: no
  `catch (\Exception)` takes it, nothing retries it and nothing maps it to one of the eight reasons of 7.1.

The rule: nothing in `src/` builds an exception or an error of PHP directly (`new \InvalidArgumentException`,
`new \LogicException`, `new \Error`, or any other). A throw site is plain: `throw new InvalidValue('…');`.

- Every other class of `src/` that can be thrown takes the arguments out of its own trace the same way:
  `GatewayException` (7.2), `Transport\TransportException` (12.4, also one a host's own transport builds),
  `Contract\Credentials\TokenSupplierFailed` (G42) and the two internal exceptions of the schema loop guard (G52).
- The write on the trace is in one place: `Contract\Internal\Traces::withoutArguments(\Throwable $thrown): \Throwable`.
  It is `@internal`, has no state and stands next to `Internal\Rules` (G48). Only the constructors of the classes
  above call it, on `$this`, as their last line. A host must not call it.
- `tests/Architecture/TraceArgumentsTest.php` holds the rule. It lists the one place that builds a PHP exception on
  purpose (a probe in the shared test set, never thrown) and every class that extends none of the three, with the
  reason.

Not covered:

- A `\TypeError` that PHP raises at a typed parameter. PHP builds it before the constructor or method runs, so no
  class of the library is involved and its trace holds the arguments of that call.
- An exception a host chains itself: the `$previous` it gives to a `TransportException` is the host's own and is left
  as it is.
- What code outside the library throws and the library lets pass (G44, G45): an exception of a host's transport that
  is no `TransportException`, or of the host's error handler.

Reading the rest of this document, and `docs/design/adapters.md`: wherever it says the library throws
`InvalidArgumentException`, `LogicException` or `\Error`, it throws the class above that extends it. The class tables
of 1.1 and 1.2 do not list the three or `Internal\Traces`; they belong to `Contract`.

## 8. Retries, time and trace ids (LG-07)

### 8.1 `RetryPolicy` (value, `Contract`)

Meets LR-34. The library has no default policy: `GatewayConfig` requires one.

| Field | Type | Validation |
|---|---|---|
| `waitsSeconds` | `list<float>` | The wait before retry 1, 2, …; its length is the number of retries. Throws when not a list, or when an entry is not finite or is below 0. |
| `maxProviderWaitSeconds` | `float` | The longest wait accepted from the provider. Throws when not finite or below 0. |

`RetryPolicy::none(): self` gives no retries (`[]`, `0.0`). There is no jitter in the library; the caller chooses the
waits (G32).

### 8.2 The retry rule

For `complete`, `models`, each request of `check`, and `stream` until its first event:

1. Only the rows marked "yes" in section 7.3 are retried (LR-34). A failed response of such a row that itself says
   "do not retry" is not retried: the adapter says so through `retryAllowed()` (section 13). The call then fails at
   once with the reason of that row, and its error tells no wait (G71, G82).
2. When the response tells a wait (the `Retry-After` header as seconds or as a date, or a field the format documents)
   and it is above `maxProviderWaitSeconds`: fail at once with this attempt's error, `retryAfterSeconds` filled.
3. When it tells a wait within the maximum: wait that long. Otherwise wait `waitsSeconds[n-1]` before retry n.
4. When no retry is left, or the wait would not end before the call's deadline (8.3): fail with this attempt's error.
5. Waiting is `Clock::sleep()`. `attempts` counts every HTTP attempt.

### 8.3 The timeout

Meets LR-36. One deadline per call (G26): `deadline = start + timeoutSeconds`. It covers every attempt and every wait.

- Before each attempt the remaining time is computed. At 0 or below: `timeout`. Otherwise it is given to the transport
  as `HttpRequest::$timeoutSeconds`.
- A response that did arrive is used, also when it arrived late.
- For a stream the deadline covers the whole stream. It is checked between chunks; past it the stream ends with
  `StreamFailed` (`timeout`).
- The library has no limit on calls per minute or per day and keeps no counter (LR-36, LR-01).

### 8.4 `Clock` (interface, `Transport`, LG-05)

```php
namespace OrganiseYou\LlmGateway\Transport;

interface Clock
{
    public function monotonic(): float;      // seconds, only differences are meaningful
    public function unixTime(): int;         // for a Retry-After given as a date
    public function sleep(float $seconds): void;
}
```

`SystemClock` (final) uses `hrtime`, `time` and `usleep`. `Testing\FakeClock` (final, not readonly) never sleeps: it
adds the seconds to its own time and records them, so retry tests run at once.

### 8.5 Trace ids

`Request::$traceId` or the `$traceId` argument when given; otherwise `bin2hex(random_bytes(16))`, made once per call.
The same id is on the `Answer`, the `GatewayException`, the `ConnectionReport` and every attempt report of that call.
It is not sent to the provider.

## 9. The connection check (values in LG-07, logic in LG-23)

In `OrganiseYou\LlmGateway\Contract`. Meets LR-27.

`CheckStatus` (enum): `Yes = 'yes'`, `No = 'no'`, `NotSupported = 'not_supported'`, `NotRun = 'not_run'`.

`CheckResult` (value):

| Field | Type | Validation |
|---|---|---|
| `status` | `CheckStatus` | |
| `reason` | `?ErrorReason` | Throws when not null while `status` is not `No`. |
| `providerCode` | `?string` | Same rule as `GatewayException::$providerCode`. Throws when not null while `status` is not `No`. |

`ConnectionReport` (value): `CheckResult $signIn`, `$modelExists`, `$toolCall`, `$structuredAnswer`, `$stream`, and
`string $traceId`. Method `allPassed(): bool` (no result is `No` or `NotRun`).

Rules for LG-23:

- The check uses only text fixed in the library. No argument carries caller text into it.
- A capability that is `None` gives `NotSupported` without a request.
- When `signIn` is `No`, the other four are `NotRun` and no further request is sent.
- `check` throws no `GatewayException` for a provider or transport failure; it becomes `No` with its reason and code.
- Until LG-23, `AbstractAdapter::check()` throws `GatewayException` with `not_supported`; `FakeGateway` returns a
  scripted report.

## 10. Structured answers (LG-08)

`OrganiseYou\LlmGateway\Adapter\Shared\StructuredAnswerChecker` (final, no constructor arguments, no state).
Meets LR-07, LR-33, G13.

```php
public function check(string $text, ResponseFormat $format, FinishReason $finishReason): StructuredResult;
```

Steps, in this order:

1. Trim. When the text is wrapped in one Markdown code fence, take what is inside.
2. `finishReason` is `Length`: one issue `CutOff`, message `cut off at the token limit`; `data` is the decoded object
   when the text happens to decode, else null. Stop.
3. The text is not JSON: one issue `NotJson`, message `not JSON: <the decoder's message>`; `data` null. Stop.
4. The JSON is not an object: one issue `SchemaMismatch`, path `/`, message `must be an object`; `data` null. Stop.
5. Validate with `opis/json-schema` against `$format->schema->toObject()`. Each leaf error becomes one issue
   `SchemaMismatch` with its JSON pointer and message, in the validator's order. `data` is the decoded object.
6. A schema the validator cannot load: `GatewayException` with `rejected`, detail `the response schema is not a valid
   JSON Schema`. This is the caller's schema being wrong, not the answer.

The method never throws for an unusable answer.

`OrganiseYou\LlmGateway\Adapter\Shared\StructuredOutputEmulation` (final, no state):

```php
public function instructions(ResponseFormat $format): string;
```

Returns fixed English text that asks for one JSON object matching the schema and nothing else, followed by the schema
JSON. An adapter without native structured output adds it to the system text, then calls `check`. An adapter that
emulates through a forced tool call builds that call itself from `ResponseFormat::$name`, `$description` and `$schema`,
and passes the call's raw arguments to `check`.

## 11. Credentials and secrets

Meet LR-24, LR-25, LR-26.

### 11.1 `Secret` (`Contract`, LG-05; `final class`, not readonly)

Holds one secret value where no output function can reach it.

```php
public function __construct(#[\SensitiveParameter] string $value);
public function reveal(): string;        // the only way to the value
public function __toString(): string;    // '[secret]'
public function jsonSerialize(): string; // '[secret]'
/** @return array{value: string} */
public function __debugInfo(): array;    // ['value' => '[secret]']
public function __serialize(): array;    // throws LogicException
public function __clone();               // allowed
```

Rules, each a test in LG-06 with a marker key: the marker is absent from `var_export`, `var_dump`, `print_r`,
`json_encode`, a string cast, `serialize` (which throws) and from the trace of an exception thrown inside the
constructor. The constructor throws `InvalidArgumentException` for an empty value, with a message that does not hold
the value.

`var_export` prints private properties, so the value may not sit in a plain property. Suggested: keep it in a private
`\Closure` that returns it. Whether `var_export` and `var_dump` of that closure stay silent on PHP 8.4 is **not
confirmed here**; the leak test decides, and the developer picks another holder if it fails.

### 11.2 Credential kinds (`Contract\Credentials`, LG-06)

A credential object is the secret and how it is presented. Everything that is not secret (an address, a deployment,
a project, a region, an API version) is a setting of the preset, not a credential (G21).

```php
interface Credentials
{
    public function kind(): CredentialKind;
    /** @return list<\OrganiseYou\LlmGateway\Contract\Secret> */
    public function secrets(): array;
}

interface TokenSupplier
{
    public function token(): \OrganiseYou\LlmGateway\Contract\Secret;
}
```

`CredentialKind` (enum): `None = 'none'`, `BearerKey = 'bearer_key'`, `HeaderKey = 'header_key'`,
`BearerToken = 'bearer_token'`.

| Class (value) | Field | Sent as | `secrets()` |
|---|---|---|---|
| `NoCredentials` | none | nothing | `[]` |
| `BearerKey` | `Secret $key` | `Authorization: Bearer <key>` | `[$key]` |
| `HeaderKey` | `Secret $key` | the header the preset names in `Preset::$keyHeader` | `[$key]` |
| `BearerToken` | `TokenSupplier $tokens` | `Authorization: Bearer <token>`, the token asked from the supplier before every attempt | `[]` |

- `secrets()` is how the host learns which values to register with its redactor before the first request (LR-26). A
  supplied token is the host's own value: the host registers it where it makes it.
- The library asks the supplier before every attempt and keeps no token. Keeping it between calls is the supplier's
  work (LR-25, G17). The supplier must not block for long; its time counts against the call's deadline.
- A secret is only ever placed in `HttpRequest::$secretHeaders`. Never in a URL, a body, an exception, an event or an
  attempt report.

How the sign-in methods of LR-24 are met:

| Sign-in method of LR-24 | Credential class | Settings beside it |
|---|---|---|
| A key as bearer token | `BearerKey` | — |
| A key in a provider-specific header | `HeaderKey` | header name in the preset |
| A key with a resource address and a deployment name, an API version only where asked | `HeaderKey` or `BearerKey` | resource address: `GatewayConfig::$baseUrl`; deployment: `Request::$model`; API version: a preset field |
| A cloud project and region with a short-lived access token | `BearerToken` | project and region: preset fields |
| A short-lived token of an identity service | `BearerToken` | resource address: `GatewayConfig::$baseUrl` |
| No key at all | `NoCredentials` | — |

LG-06 names "key with resource, deployment and API version" and "access token with project and region" as kinds. In
this design they are the two rows above: one of the four classes plus settings. LG-11 checks every provider against
this table.

## 12. Transport (LG-05)

In `OrganiseYou\LlmGateway\Transport`. Meet LR-28, LR-29, LR-30, G12.

### 12.1 `Transport` (interface)

```php
namespace OrganiseYou\LlmGateway\Transport;

interface Transport
{
    /** @throws TransportException */
    public function send(HttpRequest $request): HttpResponse;

    /** @throws TransportException  also while the chunks are read */
    public function stream(HttpRequest $request): HttpStreamResponse;
}
```

A transport must: send exactly the request it gets; not follow a redirect; not change the host; honour
`timeoutSeconds` when it can; return every status, also 4xx and 5xx, as a response. The host puts its outbound rule,
tracing and redaction here (LR-28).

### 12.2 `HttpRequest` (value)

| Field | Type | Validation (throws when …) |
|---|---|---|
| `method` | `string` | not `GET` or `POST`. |
| `url` | `string` | not an absolute `http` or `https` URL with a host; holds user info or a fragment. |
| `headers` | `array<string, string>` | a name is not an HTTP token; a value holds CR or LF. |
| `secretHeaders` | `array<string, Secret>` | a name is not an HTTP token; a name is also in `headers` (compared without case); a value is not a `Secret`. |
| `body` | `string` | — Default `''`. |
| `timeoutSeconds` | `float` | not finite or not above 0. |

```php
/** @return array<string, string> */
public function allHeaders(): array;   // headers plus the revealed secret headers: for the transport only
/** @return array{method: string, url: string, headers: array<string, string>, body: string} */
public function toArray(): array;      // secret headers shown as '[secret]': the form for reports and logs
```

`allHeaders()` is the one place a secret leaves its `Secret`. The library calls it nowhere outside `Psr18Transport`.

### 12.3 `HttpResponse` and `HttpStreamResponse` (values)

`HttpResponse`: `int $status` (throws outside 100 to 599), `array<string, string> $headers` (names stored
lower-cased; several values of one header joined with `, `), `string $body`. Methods `header(string $name): ?string`
(without case) and `toArray(): array{status: int, headers: array<string, string>, body: string}`.

`HttpStreamResponse`: `int $status`, `array<string, string> $headers`, `iterable<string> $chunks` (raw bytes, cut
anywhere; read once). For a status outside 200 to 299 the chunks are the error body. Method `header()` as above.

### 12.4 `TransportFailure` (enum) and `TransportException`

`TransportFailure`: `ConnectionFailed = 'connection_failed'` (could not connect, or the connection broke),
`TimedOut = 'timed_out'`, `Redirected = 'redirected'`, `Blocked = 'blocked'` (the host's own rule refused the request).

`final class TransportException extends \RuntimeException` with `public readonly TransportFailure $failure`.
Constructor: `(TransportFailure $failure, string $message = '', ?\Throwable $previous = null)`. The library reads only
`$failure`; it never copies the message or the previous exception anywhere.

### 12.5 `Psr18Transport` (final)

```php
public function __construct(
    \Psr\Http\Client\ClientInterface $client,
    \Psr\Http\Message\RequestFactoryInterface $requestFactory,
    \Psr\Http\Message\StreamFactoryInterface $streamFactory,
);
```

- `send`: builds the PSR-7 request from `allHeaders()` and the body, sends it, returns the status, headers and body.
- `stream`: the same, and yields the response body in pieces of at most 8192 bytes until its end.
- A status 300 to 399 throws `TransportException(Redirected)`. The `Location` header is never read (LR-29).
- `Psr\Http\Client\NetworkExceptionInterface` becomes `ConnectionFailed`; any other
  `Psr\Http\Client\ClientExceptionInterface` becomes `ConnectionFailed` too. The client's message is not copied.
- PSR-18 has no timeout per request. The bridge cannot pass `timeoutSeconds` on; the host sets a timeout on its
  client, or writes its own `Transport`. The call's deadline (8.3) is still checked between attempts and between
  chunks.
- Whether the body arrives piece by piece depends on how the host configured its client. Not confirmed here for any
  client; LG-05 reads the documentation of the client used in `require-dev` and writes what it found in the class's
  doc comment.
- The bridge only calls PSR interfaces and implements none, so it works with `psr/http-message` 1.1 and 2.0 (G19).

### 12.6 The attempt observer

Meets LR-30. `Contract\Operation` (enum): `Complete = 'complete'`, `Stream = 'stream'`, `Models = 'models'`,
`Check = 'check'`.

```php
namespace OrganiseYou\LlmGateway\Transport;

interface AttemptObserver
{
    public function attemptStarted(AttemptStarted $attempt): void;
    public function attemptFinished(AttemptFinished $attempt): void;
}
```

`AttemptStarted` (value):

| Field | Type | Meaning |
|---|---|---|
| `presetId` | `string` | The id of the preset the caller built the gateway from: the "provider" of LR-30 (G28). |
| `operation` | `Operation` | |
| `traceId` | `string` | |
| `attempt` | `int` | From 1. Throws below 1. |
| `request` | `HttpRequest` | The host reads it with `toArray()`; secret headers are masked there. |

`AttemptFinished` (value): the five fields above, and:

| Field | Type | Meaning |
|---|---|---|
| `response` | `?HttpResponse` | Null when no response arrived. For a stream: the status, the headers and the bytes read so far as the body. |
| `outcome` | `AttemptOutcome` | |
| `transportFailure` | `?TransportFailure` | Not null exactly when `outcome` is `TransportError`, else throws. |
| `willRetry` | `bool` | |
| `durationMs` | `int` | Throws below 0. |

`AttemptOutcome` (enum): `Succeeded = 'succeeded'`, `HttpError = 'http_error'` (a response the library treats as a
failure, also an error inside a 200), `TransportError = 'transport_error'`.

Rules:

1. `attemptStarted` is called before, and `attemptFinished` after, every HTTP attempt of every operation, also the
   attempts that are retried and the requests of `check`.
2. For a stream, `attemptFinished` is called when the stream ends, breaks or is abandoned by the caller.
3. Every observer call is wrapped: any `\Throwable` it throws is caught and dropped. Reporting never changes the
   result of the call (LR-30).
4. `NullAttemptObserver` (final) does nothing and is the default.
5. The preset id appears only here. Not in an `Answer`, a `GatewayException`, a stream event or a `ConnectionReport`
   (LR-04).

### 12.7 `Testing\FakeTransport` (final, not readonly)

Implements `Transport`. Scripted in order: `willReturn(HttpResponse)`, `willStream(int $status, array $headers,
string ...$chunks)`, `willBreakStreamAfter(int $chunks, TransportFailure $failure)`, `willFail(TransportFailure)`,
`willDelay(float $seconds)` (advances the `FakeClock` given to its constructor before the next answer). It records
every `HttpRequest` in `requests(): list<HttpRequest>`. An unscripted call throws `LogicException`. Its state lives in
the test, which is allowed: the no-state rule is for `src/` outside `src/Testing`.

## 13. From a preset to a gateway (LG-07)

### 13.1 `Preset`, `PresetField`, `BaseUrlRule` (`Preset`)

A preset is data (LR-19). The class is built in LG-07 so the factory has its input; the sixteen presets come in slice
S4. LG-11 may add fields to it; it may not move logic into it.

`BaseUrlRule` (enum): `Fixed = 'fixed'` (only the preset's address), `CustomerMay = 'customer_may'` (a default the
customer may replace), `CustomerMust = 'customer_must'` (no default).

`PresetField` (value): `string $key` (throws unless `^[a-z][a-z0-9_]{0,31}$`), `string $label` (throws when empty),
`bool $required`, `?string $pattern` (a regular expression the value must match; default null).

`Preset` (value):

| Field | Type | Validation (throws when …) |
|---|---|---|
| `id` | `string` | not `^[a-z][a-z0-9-]{0,63}$`. |
| `label` | `string` | empty. |
| `description` | `string` | — What the customer needs, in one plain sentence. |
| `adapter` | `class-string<AbstractAdapter>` | not a subclass of `AbstractAdapter`. |
| `baseUrlRule` | `BaseUrlRule` | |
| `defaultBaseUrl` | `?string` | null while the rule is `Fixed` or `CustomerMay`; not null while the rule is `CustomerMust`; not an absolute `http` or `https` URL. May hold `{key}` placeholders naming fields. |
| `credentialKinds` | `list<CredentialKind>` | empty. The kinds this preset accepts. |
| `keyHeader` | `?string` | null while `credentialKinds` holds `HeaderKey`; not an HTTP token. |
| `fields` | `list<PresetField>` | two fields share a key; a placeholder in `defaultBaseUrl` names no field; a field used in a placeholder has no `pattern`. |
| `dataLocation` | `string` | — Where the provider processes data, as its documentation states it. |
| `dataLocationSource` | `?string` | — The link to that statement. |
| `sendsNoStoreOption` | `bool` | — True when the adapter sends the provider's option not to store the conversation (LR-31). |
| `adapterOptions` | `array<string, bool\|int\|string>` | a value is of another type. Differences between servers of one format. |

### 13.2 `GatewayConfig` (value, root namespace)

| Field | Type | Default | Validation (throws when …) |
|---|---|---|---|
| `retry` | `RetryPolicy` | required | |
| `baseUrl` | `?string` | null | not an absolute `http` or `https` URL with a host; holds user info, a query or a fragment. |
| `settings` | `array<string, string>` | `[]` | a key or a value is not a string. The values for the preset's fields. |
| `observer` | `AttemptObserver` | `new NullAttemptObserver()` | |
| `clock` | `Clock` | `new SystemClock()` | |

### 13.3 `GatewayFactory` (final, root namespace)

```php
namespace OrganiseYou\LlmGateway;

final class GatewayFactory
{
    /** @throws Contract\GatewayException  reason not_configured */
    public static function create(
        Preset\Preset $preset,
        Contract\Credentials\Credentials $credentials,
        Transport\Transport $transport,
        GatewayConfig $config,
    ): Contract\Gateway;

    /** @throws Contract\GatewayException  reason not_configured */
    public static function resolveBaseUrl(Preset\Preset $preset, GatewayConfig $config): string;
}
```

`create` sends nothing. It fails with `GatewayException` (`not_configured`, `attempts` 0, a fresh trace id, a `detail`
naming the field key, never a value) when:

1. `$credentials->kind()` is not in `$preset->credentialKinds`.
2. The rule is `CustomerMust` and `$config->baseUrl` is null.
3. The rule is `Fixed` and `$config->baseUrl` is not null.
4. A required field has no value in `$config->settings`, or a value does not match the field's `pattern`.
5. `$config->settings` holds a key that is not a field of the preset.

Otherwise it returns `new ($preset->adapter)($preset, $config, $credentials, $transport)`.

`resolveBaseUrl` gives the address every request of that gateway starts with: `$config->baseUrl` when given, else
`defaultBaseUrl` with its placeholders filled from the settings. The host can run its outbound rule on it before the
first call. Static methods, no static state.

### 13.4 `AbstractAdapter` (`Adapter\Shared`, abstract, implements `Gateway`)

Owns everything that is the same for every format (LG-07): the deadline, retries, attempt counting, latency, trace
ids, the observer calls, the mapping of section 7.3, the capability refusals of section 5.2, the sign-in headers of
section 11.2, the structured check of section 10, and the one-event stream fallback.

```php
public function __construct(
    Preset $preset,
    GatewayConfig $config,
    Credentials $credentials,
    Transport $transport,
);
```

What a format's adapter supplies (protected; LG-07 may adjust these signatures, they are not part of the contract):

```php
abstract public function capabilities(string $model): Capabilities;
abstract protected function completionRequest(Request $request, bool $stream): HttpRequest;
abstract protected function readCompletion(HttpResponse $response, Request $request): Answer;   // without latency, attempts
/** @param iterable<SseEvent> $events  @return iterable<StreamEvent> */
abstract protected function readStream(iterable $events, Request $request): iterable;
abstract protected function modelsRequest(float $timeoutSeconds): HttpRequest;
/** @return list<ModelInfo> */
abstract protected function readModels(HttpResponse $response): array;
protected function providerCode(HttpResponse $response): ?string;          // default null
protected function refineReason(HttpResponse $response, ErrorReason $reason): ErrorReason;   // default: unchanged
protected function retryAllowed(HttpResponse $response, ErrorReason $reason): bool;          // default: true (LG-30)
```

Rules the base enforces:

- Every URL is `resolveBaseUrl()` plus a path and query from the adapter, through one protected method
  `url(string $path, array $query = []): string`. No other code builds a URL, so the host never changes (LR-29).
- `capabilities()` is written by each adapter and must send nothing (G27).
- `SseReader::read(iterable<string> $chunks): iterable<SseEvent>` (final, no state between calls) cuts raw chunks into
  events; `SseEvent` (value): `?string $event`, `string $data`, `?string $id`.

## 14. The fake and the shared test set (LG-09)

`Testing\FakeGateway` (final, not readonly) implements `Gateway` with no transport. Script, in order:
`willAnswer(Answer)`, `willAnswerText(string)`, `willCallTools(ToolCall ...)`, `willStream(StreamEvent ...)`,
`willFail(GatewayException)`, `withCapabilities(Capabilities)`, `withModels(ModelInfo ...)`,
`withConnectionReport(ConnectionReport)`. It records `requests(): list<Request>`. For `stream` after `willAnswer`, it
yields the fallback events of section 6 rule 4; for `complete` after `willStream`, it returns the answer of the
`StreamFinished` event. When a scripted text answer meets a request with a response format, it runs
`StructuredAnswerChecker`, so an application's repair loop is testable. An unscripted call throws `LogicException`.
The fake returns a scripted answer without text and without a tool call when its finish reason is `Length`,
`ContentFilter` or `Refusal`, and with capabilities given it refuses a temperature for a model that takes none.

`Testing\ContractTestCase` is the shared set of LR-40. An adapter's test supplies a gateway built on `FakeTransport`
and its fixtures; the fake supplies itself. The set asserts the rules of sections 4, 6, 7.3, 8.2, 8.3 and 11
(no marker key in any output). Since LG-30 it has the answers `Refused` and `Withheld` (a format may say they do not
apply), the refusal of a temperature where the capability says none, and the failure `RetryForbidden`: a status 503
whose response forbids a retry, built by `AdapterFixtures::retryForbidden()`, which a format without such a sign
answers with `NotApplicable`.

Architecture tests must list `src/Adapter/Shared` next to `src/Contract`, `src/Loop`, `src/Testing` and
`src/Transport` as folders where no provider name may appear.

## 15. Requirement → where it is met

| Requirement | Where it is met |
|---|---|
| LR-03 | `Contract\Gateway` is the one interface; callers use it and the values of `Contract` only (sections 1, 2). |
| LR-04 | No provider name, id, class or wire word in `Contract`, `Transport`, `Adapter\Shared`, `Testing`; `ErrorReason`, `FinishReason`, `Support` are neutral; the preset id appears only in `AttemptStarted` and `AttemptFinished` (12.6 rule 5). Checked by `tests/Architecture` (LG-09). |
| LR-05 | `Gateway::complete`, `stream`, `models`, `capabilities`, `check` (section 2). |
| LR-06 | `Request` with `Message`, `Role`, `ToolDefinition`, `JsonSchema`, `ToolChoice`, `ResponseFormat`, `maxOutputTokens`, `temperature`, `timeoutSeconds` (section 3). |
| LR-07 | `Answer` with `text`, `StructuredResult` and `StructuredIssue`, `ToolCall` (`arguments` or `argumentsError`), `FinishReason`, `model`, `Usage`, `latencyMs`, `attempts`, `traceId` (section 4). |
| LR-08 | The neutral ranges of 3.10; refusals as `GatewayException` (`rejected`) from the adapter. |
| LR-09 | `Gateway` has no chat-only operation; `Request` and `Message` hold no connector, conversation id or credential (sections 2, 3). |
| LR-10 | `Capabilities` and `Support`; `Gateway::capabilities()` (section 5). |
| LR-11 | `Support::Emulated` and `Support::None`; the table of 5.2; `Request` has no rule against tools with a response format (3.9). |
| LR-12 | `Contract\Stream\TextDelta`, `ToolCallStarted`, `ToolCallCompleted`, `UsageReported`, `StreamFinished`, `StreamFailed`; the fallback of section 6 rule 4 in `AbstractAdapter`. |
| LR-13 | `Message::$parts` is `list<ContentPart>`; `TextPart` is the only part now (3.2, 3.3). No image, audio, embedding or speech class exists. |
| LR-24 | `NoCredentials`, `BearerKey`, `HeaderKey`, `BearerToken` with `TokenSupplier`, plus `GatewayConfig::$baseUrl`, `Request::$model` and preset fields; the table of 11.2. |
| LR-25 | Credentials are an argument of `GatewayFactory::create()` and live as long as the gateway object; `TokenSupplier::token()` is asked per attempt and nothing is kept (11.2). No class reads the environment or a file. |
| LR-26 | `Secret` (11.1); `#[\SensitiveParameter]`; `HttpRequest::$secretHeaders` and `toArray()` (12.2); `GatewayException` rules (7.2); `InvalidValue`, `WrongUse`, `ForeignFault` and every other class the library throws keep no argument of any call in their trace (7.4, G63); `Credentials::secrets()` tells the host what to redact (11.2). |
| LR-27 | `Gateway::check()`, `ConnectionReport`, `CheckResult`, `CheckStatus` (section 9). |
| LR-28 | `Transport` is the only way out; `AbstractAdapter` gets it from `GatewayFactory::create()` (sections 12, 13). |
| LR-29 | The rules of `Transport` (12.1); `Psr18Transport` and `TransportFailure::Redirected` (12.5); 3xx is `unavailable` and not retried (7.3); `AbstractAdapter::url()` and `GatewayFactory::resolveBaseUrl()` (13.3, 13.4). |
| LR-30 | `AttemptObserver`, `AttemptStarted`, `AttemptFinished`, `AttemptOutcome`, `Operation`; observer exceptions are dropped (12.6). |
| LR-31 | `Preset::$sendsNoStoreOption` says it; the format's adapter sends it (13.1). No class has a field that switches on training or data sharing. The option itself is adapter work in slice S3. |
| LR-32 | `ErrorReason` (eight cases) and `GatewayException` with `httpStatus`, `providerCode`, `retryAfterSeconds`, `traceId` (section 7). |
| LR-33 | `Answer::$structured` is a `StructuredResult` with issues; `StructuredAnswerChecker::check()` never throws for an unusable answer (4.3, 10). |
| LR-34 | `RetryPolicy` in `GatewayConfig`; the retry rule of 8.2 and the "retried" column of 7.3, in `AbstractAdapter`. |
| LR-35 | Section 6 rule 6: `StreamFailed` with `textSoFar` and `toolCallsSoFar`, no retry after the first event. |
| LR-36 | `Request::$timeoutSeconds` (required) and the `$timeoutSeconds` arguments of `models` and `check`; the deadline of 8.3. No rate counter exists. |

Also relied on by later tasks: LR-01 (no state: every class is immutable, `Clock` and `TokenSupplier` are given),
LR-14 and LR-17 (`Message::toolResult`, `ToolCall::unreadable`), LR-19 (`Preset`), LR-40 and LR-42 (section 14).

## 16. Decisions this design adds

G19 to G32 in `docs/plan/decisions.md`, all `proposed`. None is `open`.
