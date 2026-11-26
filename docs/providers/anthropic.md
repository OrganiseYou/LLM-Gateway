# Claude (Anthropic)
Read on 2026-11-27. Hosted text chat API: yes (the Messages API).

Every fact below was read on 2026-11-27 on the page its link names. The link labels are listed under
[Pages read](#pages-read). Where a page was too long to be shown whole and only a condensed rendering of it was seen,
the fact is marked "(condensed reading)". All JSON in this file is synthetic.

## Reaching it
- Base URL and the chat path: `https://api.anthropic.com`, `POST /v1/messages` [overview], [create].
- Hosted by the vendor, run by the customer, or both: the vendor. The same models are also offered through cloud
  platforms: operated by Anthropic (Claude Platform on AWS, Claude Models on Google Cloud "Anthropic managed",
  Microsoft Foundry) or by the partner (Amazon Bedrock, Claude Models on Google Cloud "Google managed"); sign-in,
  model ids, request size limits and features differ there [overview]. No way for a customer to run the models itself
  was found on the pages read.
- API format: both; its own is fuller. Its own Messages API is the one to build on. An OpenAI-compatible layer exists
  at `https://api.anthropic.com/v1/` (Chat Completions), but it "is not considered a long-term or production-ready
  solution for most use cases"; on it `strict` on a function is ignored, `response_format` accepts only `json_schema`
  with `strict: true`, system messages are hoisted to the front, and it is offered on the first-party API only
  [openai-compat].
- API versions or dated headers a request must send:
  - `anthropic-version`. The current value is `2026-10-21`; the earlier `2023-06-01` is still described as usable
    (the migration guide says a request that needs a removed parameter can "keep that request on `2023-06-01`"); no
    end date for `2023-06-01` was found [versioning], [migration].
  - The provider's pages disagree on whether the header is required: [overview] marks it "Yes" (required) and
    [versioning] says "you must send" it; the reference page [create] lists it as `optional` (condensed reading).
    Build to "always send it".
  - `content-type: application/json` is required [overview].
  - What `2026-10-21` changes for chat: `temperature`, `top_p` and `top_k` are removed and a request that sends any of
    them gets a 400 "on every model", "even at the parameter's default value"; a `document` block directly in an
    assistant message gets a 400; a `text` block with non-empty `citations` in a user message gets a 400
    [versioning], [migration].
  - Within one version the provider may add optional inputs, add output values, change the conditions for an error
    type, and add new values to enum-like outputs such as stream event types; a client must tolerate unknown values
    [versioning].
  - On Amazon Bedrock `2026-10-21` exists only for Claude 4.7 and later models [migration].

## Signing in
- Method (bearer key, header name, token exchange, deployment or project fields):
  - An API key as `Authorization: Bearer <key>`. This is now the documented primary form; `x-api-key: <key>` is called
    the "legacy fallback", "still supported", and "the Claude API accepts a key in either header" [overview],
    [authentication], [migration]. Most request examples in the documentation still show `x-api-key`.
  - `anthropic-workspace-id: wrkspc_...` is required on every request when the key is not scoped to one workspace;
    without it the answer is 400 `invalid_request_error`; an unknown or inaccessible workspace is 404
    `not_found_error`. A key scoped to one workspace may omit it [authentication], [overview].
  - Alternative: Workload Identity Federation. The workload exchanges an identity-provider token at
    `POST /v1/oauth/token` for a short-lived access token, sent as the same bearer header; the workspace is chosen at
    the exchange, not by header [authentication], [overview].
  - Key shape: the documentation describes keys as a static `sk-ant-api...` secret [authentication].
  - An expired key gets 401 `authentication_error` [authentication].
- What the customer must create at the provider, in plain words: an account in the Claude Console and an API key made
  on its "API keys" page. When making the key the customer picks who it acts as (a person, or a service account for
  shared use), an expiry, and optionally one workspace. If the key is not tied to one workspace, the customer must
  also give the workspace id [authentication], [overview].

## What a request can do
| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | yes, "available on every model" | `tools[]` with `name`, `description`, `input_schema` (a JSON Schema with `type: "object"`); optional `strict: true`. The answer holds `tool_use` blocks; results go back as `tool_result` blocks in a user message. | [define-tools], [tool-calls] |
| Forced tool call | depends on the model | `tool_choice`: `{"type":"auto"}` (default when tools are given), `{"type":"any"}`, `{"type":"tool","name":"..."}` (one named tool), `{"type":"none"}` (default when no tools). `any` and `tool` return 400 `invalid_request_error` on Claude Fable 5.2, Mythos 5.2, Opus 5.5, Sonnet 5.5, Fable 5.1 and Mythos 5.1, and with `thinking: {"type":"enabled"}`. Where accepted, the answer starts with the tool call: no text and no thinking before it. | [define-tools], [errors] |
| Parallel tool calls | yes, on by default | Several `tool_use` blocks in one answer. Turn off with `disable_parallel_tool_use: true` inside the `tool_choice` object (not a top-level field): at most one call with `auto`, exactly one with `any` or `tool`. All results must go back in one user message. | [parallel], [create] |
| Structured output with a JSON Schema (and which schema keywords are refused) | yes, native, no beta header; "available on every model" | `output_config.format = {"type":"json_schema","schema":{...}}`. The JSON arrives as text in a `text` block. Refused with a 400: recursive schemas, `oneOf`, `not`, `minimum`, `maximum`, `multipleOf`, `minLength`, `maxLength`, `minItems` other than 0 or 1 and other array limits, `additionalProperties` other than `false`, external `$ref`, non-scalar `enum` values, some `anyOf`/`allOf` combinations, regex backreferences and lookaround. Accepted: the basic types, `enum`, `const`, `anyOf`, `allOf`, `$ref`/`$defs`/`definitions`, `default`, `required`, a list of string `format`s, simple `pattern`. See "Structured output" below. | [structured] |
| Tools together with structured output | yes | `output_config.format` and tools (also `strict: true` tools) in one request; the answer is then either a `tool_use` block or JSON in a `text` block, and the caller must handle both. The schema does not constrain tool calls. | [structured] |
| Streaming (and whether usage arrives in the stream) | yes; server-sent events | `stream: true`. Usage arrives in `message_start` (input tokens, first output count) and in `message_delta` (cumulative). No `[DONE]` line. | [streaming], [versioning] |
| System instructions (a role, or a separate field) | a separate field | Top-level `system`: a string or an array of text blocks. The reference also lists a message with `role: "system"` for "mid-conversation system messages"; that feature's page was not read. | [create] (condensed reading), [retention] |
| Listing models | yes | `GET /v1/models`; query `limit` (default 20, 1 to 1000), `after_id`, `before_id`, `lifecycle`. Answer: `data[]`, `has_more`, `first_id`, `last_id`. Each model: `id`, `display_name`, `created_at`, `lifecycle`, `max_input_tokens`, `max_tokens`, `capabilities` (among them `structured_outputs.supported`). Newest first. | [models-list] |

## Limits and shapes

### Required fields a neutral request may lack (for example maximum tokens)
- `model`, `messages` and `max_tokens` are required [create] (condensed reading).
- `max_tokens`: an integer, minimum 0, **no documented default**. The reference says "Different models have different
  maximum values for this parameter" [create] (condensed reading). The per-model maximum is the "Max output" column of
  the model table: 128K tokens for every model from the 4.6 generation on, 64K for Opus 4.5, Sonnet 4.5 and Haiku
  4.5 [models]. It can be read per model from `max_tokens` in the model list [models-list].
- `max_tokens: 0` produces no answer; it only fills the prompt cache [create] (condensed reading).
- A large `max_tokens` without streaming is discouraged: requests over about 10 minutes should stream [errors].
- Thinking tokens count toward `max_tokens` [create] (condensed reading). On several current models thinking cannot be
  switched off (`thinking: {"type":"disabled"}` returns 400) [errors]. An adapter must therefore expect `thinking` and
  `redacted_thinking` blocks in an answer and, in a tool loop, send them back unchanged; altered blocks return 400
  [errors].
- Request size: at most 128 MB, else 413 `request_too_large` [errors]. At most 100,000 messages per request [create]
  (from a search summary and a condensed reading).

### Temperature range; tool name rules; model name shape
- Temperature: on `2026-10-21` there is none; sending it is a 400 on every model. Without it the model samples at its
  default, "a `temperature` of 1.0" [migration]. On `2023-06-01` the native range was not read; the compatibility
  layer's page gives "between 0 and 1 (inclusive)" and says that on Claude 4.7 and later any value below 1 returns
  400 [openai-compat].
- Tool name: must match `^[a-zA-Z0-9_-]{1,128}$` [define-tools].
- Tool call id: matches `^[a-zA-Z0-9_-]+$` [create].
- Model name: a plain string id such as `claude-opus-5-5`; older ones carry a date, such as
  `claude-haiku-4-5-20251001`. "Every Claude model ID is a pinned snapshot, including the dateless IDs" [models].
  The cloud platforms use other ids (for example an `anthropic.` prefix on Bedrock) [models].

### The shape of a request
Roles in `messages` are `user` and `assistant`. `content` is a string or an array of blocks; a string is short for one
`text` block. Consecutive turns of the same role are combined. A final assistant message is a prefill, which Claude
4.6 and later reject with 400 [create] (condensed reading), [errors].

```json
{
  "model": "claude-opus-5-5",
  "max_tokens": 1024,
  "system": "Answer briefly.",
  "messages": [
    {"role": "user", "content": "What is the weather in Utrecht?"}
  ],
  "tools": [
    {
      "name": "get_weather",
      "description": "Current weather for a city.",
      "input_schema": {
        "type": "object",
        "properties": {"city": {"type": "string"}},
        "required": ["city"]
      }
    }
  ],
  "tool_choice": {"type": "auto"}
}
```

Other optional top-level fields read: `stream`, `stop_sequences`, `metadata.user_id` (at most 512 characters, must
not be personal data), `service_tier`, `inference_geo`, `thinking`, `output_config` (`effort`, `format`) [create]
(condensed reading).

### The shape of an answer
Fields: `id`, `type: "message"`, `role: "assistant"`, `model`, `content[]`, `stop_reason`, `stop_sequence`,
`stop_details`, `usage` [create].

```json
{
  "id": "msg_example01",
  "type": "message",
  "role": "assistant",
  "model": "claude-opus-5-5",
  "content": [
    {"type": "text", "text": "I will look that up."},
    {"type": "tool_use", "id": "toolu_example01", "name": "get_weather", "input": {"city": "Utrecht"}}
  ],
  "stop_reason": "tool_use",
  "stop_sequence": null,
  "stop_details": null,
  "usage": {"input_tokens": 40, "output_tokens": 25}
}
```

Block types an adapter for text chat will meet in `content`: `text`, `tool_use`, `thinking` (`thinking`,
`signature`), `redacted_thinking` (`data`). Server-tool blocks exist too but only when server tools are requested
[create].

### The shape of a tool call in an answer, and of a tool result in a request
- Tool call: a `tool_use` block with `id`, `name` and `input`. `input` is a JSON **object**, not a string
  [tool-calls], [create]. Without `strict: true` the input is not guaranteed to match the schema (wrong types or
  missing required fields are possible) [strict].
- Tool result: there is no `tool` role. The assistant's `content` is sent back unchanged as an assistant message,
  followed by a user message with one `tool_result` block per `tool_use` block, "even if the tool failed":
  `tool_use_id`, optional `content` (a string, or an array of `text`, `image`, `document`, `search_result` blocks),
  optional `is_error: true` [tool-calls].

```json
{
  "role": "user",
  "content": [
    {"type": "tool_result", "tool_use_id": "toolu_example01", "content": "12 degrees, cloudy"},
    {"type": "tool_result", "tool_use_id": "toolu_example02", "content": "Unknown city", "is_error": true}
  ]
}
```

- Ordering rules, each a 400 when broken: the tool-result message must directly follow the assistant message that
  holds the calls; inside it the `tool_result` blocks come first and any text after them; every `tool_use` id needs
  its result [tool-calls]. Results of parallel calls go in one user message [parallel].
- The same `tools` must be sent again on the follow-up request [tool-calls].

### Structured output
- Two native ways, and they can be used together: (1) `output_config.format` with a JSON Schema, the answer being JSON
  in a `text` block; (2) `strict: true` on a tool, which makes the tool's `input` follow its `input_schema`. A forced
  tool call (`tool_choice` `tool`) is a third way only on models that accept it (see the table) [structured],
  [strict], [define-tools].
- The schema's objects must set `additionalProperties: false`; an object with no listed properties can only come back
  as `{}` [structured].
- Limits per request, across the output schema and all strict tools: 20 strict tools, 24 optional parameters, 16
  parameters with a union type; beyond those a 400 "Schema is too complex for compilation" is possible, and schema
  compilation has a 180-second limit [structured].
- Not guaranteed even with a schema: on `stop_reason` `refusal` (HTTP 200) and on `max_tokens` the text may not match
  the schema or be cut off; the capitalisation of `enum` and `const` strings may differ. Required properties come
  before optional ones in the output [structured].
- Cannot be combined with citations (400) or with a prefilled assistant message [structured].
- The old top-level `output_format` is deprecated and returns 400 without a beta header [structured].
- First use of a new schema is slower; the compiled schema is cached for 24 hours [structured].

### Streaming
Wire format: server-sent events. Every event has a name line and a JSON `data` line whose `type` equals the name
[streaming].

Order [streaming]:
1. `message_start`: `message` is the answer object with empty `content`, `stop_reason: null`, and a first `usage`.
2. Per content block, with `index` = its position in `content`: `content_block_start` (holds the empty block),
   zero or more `content_block_delta`, `content_block_stop`.
3. One or more `message_delta`: `delta.stop_reason`, `delta.stop_sequence`, and `usage`.
4. `message_stop`.

`ping` events may appear anywhere. An `error` event may appear at any point after the 200. Unknown event types must be
ignored without failing [streaming].

Delta types: `text_delta` (`text`), `input_json_delta` (`partial_json`), `thinking_delta` (`thinking`),
`signature_delta` (`signature`) [streaming].

Tool-call arguments in a stream: `content_block_start` carries the `tool_use` block with `id`, `name` and
`"input": {}`. The arguments then arrive as `input_json_delta` events whose `partial_json` strings are pieces of one
JSON text (the first may be an empty string); they are concatenated and parsed once at `content_block_stop`. "The
final `tool_use.input` is always an object" [streaming].

Usage in a stream: `message_start.message.usage` has `input_tokens`, a first `output_tokens`, and cache counts when
present; `message_delta.usage` has `output_tokens` and, nullable, `input_tokens` and the cache counts. The counts in
`message_delta` "are cumulative" (totals, not increments) [streaming], [create].

Synthetic stream of a tool call:

```
event: message_start
data: {"type":"message_start","message":{"id":"msg_example02","type":"message","role":"assistant","model":"claude-opus-5-5","content":[],"stop_reason":null,"stop_sequence":null,"usage":{"input_tokens":40,"output_tokens":1}}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_example03","name":"get_weather","input":{}}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"city\":"}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":" \"Utrecht\"}"}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"tool_use","stop_sequence":null},"usage":{"output_tokens":25}}

event: message_stop
data: {"type":"message_stop"}
```

Error inside a stream: `event: error` with `data: {"type":"error","error":{"type":"overloaded_error","message":"..."}}`.
The HTTP status stays 200, so the error type is the only signal. If the connection drops, no error event arrives; a
stream without `message_stop` is incomplete. A partial tool call or thinking block cannot be recovered
[streaming], [errors], [handle-errors].

### Finish reasons and what each means
`stop_reason` [create], [stop-reasons] (the second a condensed reading):

| Value | Meaning |
|---|---|
| `end_turn` | The model finished by itself. |
| `max_tokens` | Cut off at the requested `max_tokens` or the model's maximum. A last `tool_use` block may be incomplete; retry with a higher limit. |
| `stop_sequence` | One of the request's `stop_sequences` was produced; `stop_sequence` names it. |
| `tool_use` | The model called one or more tools. |
| `pause_turn` | Only with server tools: the provider's own tool loop paused; send the content back to continue. |
| `refusal` | The model declined. HTTP 200, not an error. `stop_details` then holds `type: "refusal"`, a `category` (`cyber`, `bio`, `frontier_llm`, `reasoning_extraction`, `general_harms`, or null) and an `explanation` (may be null, wording not stable). |
| `model_context_window_exceeded` | The answer filled the context window; treat as cut off. |

Never null outside a stream; null in `message_start` [create]. New values may be added [versioning].

### Usage fields
`usage.input_tokens`, `usage.output_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens` (both nullable),
`cache_creation` (per lifetime), `output_tokens_details.thinking_tokens`, `server_tool_use`, `service_tier`,
`inference_geo` [create]. The total input is `input_tokens + cache_creation_input_tokens + cache_read_input_tokens`;
`input_tokens` alone counts only what comes after the last cache point [create], [rate-limits]. `output_tokens`
includes thinking tokens [create].

### Error body shape, error codes, rate-limit headers
Body [errors]:

```json
{
  "type": "error",
  "error": {"type": "rate_limit_error", "message": "...", "details": {"error_code": "ramp_limit_exceeded"}},
  "request_id": "req_example01"
}
```

`error.type` and `error.message` are always present; `error.details.error_code` only sometimes. New types and codes
can appear [errors]. The response header `request-id` carries the same id [errors].

| HTTP | `error.type` | Meaning for the library |
|---|---|---|
| 400 | `invalid_request_error` | Rejected request. Also: a model that refuses forced tool use, a refused schema keyword, a sampling parameter on `2026-10-21`, a spend limit the customer set itself, a missing workspace header. May also be used for other 4xx. |
| 401 | `authentication_error` | Bad, revoked or expired key. Also a browser-origin request the organisation does not allow. |
| 402 | `billing_error` | Billing or payment problem. |
| 403 | `permission_error` | The key may not use the resource. |
| 404 | `not_found_error` | Resource not found (documented for the path and ids in the address, and for an unknown workspace). |
| 409 | `conflict_error` | Conflict with a resource's state. |
| 413 | `request_too_large` | Body over the size limit. |
| 429 | `rate_limit_error` | Rate limit, ramp limit (`error_code` `ramp_limit_exceeded`), or the tier's monthly spend cap (`error_code` `enforced_spend_limit_reached`). |
| 500 | `api_error` | Provider-side failure. |
| 504 | `timeout_error` | Timed out; the same type can come with other statuses such as 500. |
| 529 | `overloaded_error` | Overloaded. The same type can also come with a 503, and inside a stream. |

Sources: [errors], [rate-limits], [authentication].

- Rate limit: 429. Overload: 529. Bad credentials: 401. Refusal: not an HTTP error; HTTP 200 with `stop_reason`
  `refusal` [errors], [stop-reasons], [structured].
- Unknown model: the status is not stated on the pages read (see Not confirmed).
- `retry-after`: "the number of seconds to wait until you can retry the request". Not every 429 has it; the
  spend-cap 429 never has it and keeps failing until the next month, so it must not be retried [rate-limits].
- `x-should-retry`: `true` or `false`. When present it decides whether a retry makes sense; the documentation tells
  direct callers to follow `x-should-retry` and `retry-after`. Without it the provider's own clients retry connection
  errors, timeouts, 408, 409, 429 and 5xx, with backoff from 0.5 to 8 seconds [errors].
- Rate-limit headers on answers: `anthropic-ratelimit-requests-limit|remaining|reset`,
  `anthropic-ratelimit-tokens-...`, `anthropic-ratelimit-input-tokens-...`, `anthropic-ratelimit-output-tokens-...`;
  the reset values are RFC 3339 times [rate-limits].
- Other answer headers: `request-id`, `anthropic-organization-id`, `anthropic-workspace-id` [overview].

## Data
- Where requests are processed, as the provider states it (one short quote with its link): "Only `"us"` and
  `"global"` are available" for the inference location, and for storage "`"us"` is the only available workspace geo"
  [residency]. `global`, the default, means "inference may run in any available geography". **There is no EU-only
  option on the first-party API.** The request field is `inference_geo` (`"us"` costs 1.1 times the standard price;
  Claude 4.6 and later only, 400 on earlier models; not available on the compatibility layer); a workspace can set a
  default and an allowed list. `usage.inference_geo` in the answer reports where it ran. On Bedrock and on Google
  Cloud (Google managed) the region follows from the endpoint instead [residency].
- The option not to store requests, and whether training on them is off by default:
  - There is **no request field** that switches storage off. Zero data retention is an arrangement per organisation,
    requested from the provider's sales team; under it prompts and answers are not stored after the answer is
    returned [retention].
  - Without such an arrangement the provider deletes API inputs and outputs "within 30 days of receipt or
    generation", with exceptions (flagged content up to 2 years; legal duties) [retention-policy] (condensed
    reading; the article shows "updated July 1, 2026").
  - Some models ("Covered Models": Claude Fable 5.2, 5.1, 5 and Claude Mythos 5.2, 5.1, 5) require 30-day retention
    and are refused with a 400 for an organisation whose retention setting does not allow it [retention].
  - With structured output or strict tools, the JSON Schema itself is cached for up to 24 hours, also under zero
    data retention; schemas must therefore hold no personal or customer data [structured], [retention].
  - Training: "Retained data is never used for model training without your express permission" [retention]. So
    training is off by default and there is no flag to send.
  - On the partner-operated platforms (Bedrock, Google Cloud "Google managed") the cloud provider is the data
    processor and its own terms apply [retention].

## Not confirmed
- **The HTTP status and error type for an unknown model id.** Tried: [errors] (404 is described only for the path and
  ids in the address), [handle-errors], [create]. Not stated on any of them.
- **The temperature range on version `2023-06-01` of the native API.** [create] documents the current version, which
  has no temperature. Only the compatibility layer's range (0 to 1) was read [openai-compat].
- **Until when `2023-06-01` is accepted.** No end date found on [versioning] or [migration].
- **Whether `anthropic-version` is truly required.** The provider's pages disagree (see Reaching it).
- **The full list of `error.details.error_code` values.** Only `enforced_spend_limit_reached`, `ramp_limit_exceeded`
  and, on the model list, `organization_on_hold` (400) were noted; the reference page that lists all of them was not
  opened.
- **Mid-conversation system messages** (`role: "system"` inside `messages`): seen only as a type name in the
  reference and a row in the retention table; its own page was not read. Build to the top-level `system` field.
- **Whether `content` is empty on a refusal.** The condensed reading of [stop-reasons] did not say.
- **What `input` looks like in a stream for a tool without arguments.** Not stated on [streaming].
- **A maximum number of tools per request** (other than 20 strict tools). Not found.
- **Parts of [create] and [stop-reasons].** Both pages are longer than could be shown at once. For [create] the
  response half was read in full and the request half only as a condensed rendering; for [stop-reasons] the last
  fifth was not read.
- **A statement that the models cannot be self-hosted.** Nothing on the pages read offers it; no page says so in
  words.
- **The provider's commercial terms and privacy policy** were not opened; the data facts above come from the
  documentation and one article of its privacy centre.

## Pages read
All opened on 2026-11-27.

[overview]: https://platform.claude.com/docs/en/api/overview
[create]: https://platform.claude.com/docs/en/api/messages/create
[versioning]: https://platform.claude.com/docs/en/api/versioning
[migration]: https://platform.claude.com/docs/en/api/migration-2026-10-21
[errors]: https://platform.claude.com/docs/en/api/errors
[handle-errors]: https://platform.claude.com/docs/en/api/handle-errors
[rate-limits]: https://platform.claude.com/docs/en/api/rate-limits
[models-list]: https://platform.claude.com/docs/en/api/models/list
[models]: https://platform.claude.com/docs/en/models/overview
[authentication]: https://platform.claude.com/docs/en/manage-claude/authentication
[streaming]: https://platform.claude.com/docs/en/build-with-claude/streaming
[structured]: https://platform.claude.com/docs/en/build-with-claude/structured-outputs
[stop-reasons]: https://platform.claude.com/docs/en/build-with-claude/handling-stop-reasons
[define-tools]: https://platform.claude.com/docs/en/agents-and-tools/tool-use/define-tools
[tool-calls]: https://platform.claude.com/docs/en/agents-and-tools/tool-use/handle-tool-calls
[parallel]: https://platform.claude.com/docs/en/agents-and-tools/tool-use/parallel-tool-use
[strict]: https://platform.claude.com/docs/en/agents-and-tools/tool-use/strict-tool-use
[residency]: https://platform.claude.com/docs/en/manage-claude/data-residency
[retention]: https://platform.claude.com/docs/en/manage-claude/api-and-data-retention
[retention-policy]: https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data
[openai-compat]: https://platform.claude.com/docs/en/cli-sdks-libraries/libraries/openai-sdk

- [overview]: API overview
- [create]: Create a Message (reference)
- [versioning]: API versions
- [migration]: Migrating to API version 2026-10-21
- [errors]: Claude API errors
- [handle-errors]: Handle errors
- [rate-limits]: Rate limits
- [models-list]: List Models (reference)
- [models]: Models overview
- [authentication]: Authentication
- [streaming]: Streaming messages
- [structured]: Structured outputs
- [stop-reasons]: Handling stop reasons
- [define-tools]: Define tools
- [tool-calls]: Handle tool calls
- [parallel]: Parallel tool use
- [strict]: Strict tool use
- [residency]: Data residency
- [retention]: API and data retention
- [retention-policy]: How long do you store my organization's data (privacy centre)
- [openai-compat]: OpenAI SDK compatibility
