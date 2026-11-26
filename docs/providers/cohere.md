# Cohere
Read on 2026-11-27. Hosted text chat API: yes.

Every fact below was read on 2026-11-27 on the linked page of Cohere itself, unless the cell says "from a search
summary" or "not confirmed". The two long reference pages (Chat, Chat with Streaming) were read in two parts each.
All JSON in this file is synthetic, written by hand from the documented shapes.

Short names for the pages used below:

| Short name | Page |
|---|---|
| [chat-ref] | https://docs.cohere.com/reference/chat |
| [stream-ref] | https://docs.cohere.com/v2/reference/chat-stream |
| [about] | https://docs.cohere.com/reference/about |
| [errors] | https://docs.cohere.com/reference/errors |
| [models-ref] | https://docs.cohere.com/reference/list-models |
| [models] | https://docs.cohere.com/docs/models |
| [chat-guide] | https://docs.cohere.com/docs/chat-api |
| [streaming-guide] | https://docs.cohere.com/v2/docs/streaming |
| [tools-guide] | https://docs.cohere.com/docs/tool-use-overview |
| [tools-patterns] | https://docs.cohere.com/v2/docs/tool-use-usage-patterns |
| [tools-params] | https://docs.cohere.com/docs/tool-use-parameter-types |
| [structured] | https://docs.cohere.com/docs/structured-outputs |
| [safety] | https://docs.cohere.com/docs/safety-modes |
| [compat] | https://docs.cohere.com/docs/compatibility-api |
| [migration] | https://docs.cohere.com/docs/migrating-v1-to-v2 |
| [rate-limits] | https://docs.cohere.com/docs/rate-limits |
| [faq] | https://docs.cohere.com/docs/cohere-faqs |
| [deploy] | https://docs.cohere.com/docs/deployment-options-overview |
| [private] | https://docs.cohere.com/docs/private-deployment-overview |
| [vault] | https://docs.cohere.com/docs/model-vault |
| [vault-standard] | https://docs.cohere.com/docs/model-vault/standard |
| [vault-api] | https://docs.cohere.com/docs/model-vault/standard/api-access |
| [vault-regions] | https://docs.cohere.com/docs/model-vault/regions |
| [data-commitments] | https://cohere.com/enterprise-data-commitments (page says "Last Update: December 5, 2025") |
| [security] | https://cohere.com/security |
| [privacy] | https://cohere.com/privacy (page says last updated May 1, 2026) |
| [subprocessors] | https://trustcenter.cohere.com/subprocessors (no date on the page) |

## Reaching it
- Base URL and the chat path: `POST https://api.cohere.com/v2/chat` [chat-ref]. The same address serves the stream;
  there is no separate stream path [stream-ref]. Model listing is `GET https://api.cohere.com/v1/models`: a `v1`
  path, also in the v2 documentation [models-ref].
- Two host names appear in Cohere's pages. The API reference uses `api.cohere.com` [chat-ref]. The Compatibility API
  guide uses `https://api.cohere.ai/compatibility/v1` in every example but one, and
  `https://api.cohere.com/compatibility/v1/audio/transcriptions` in that one [compat]. Neither page says whether the
  two hosts are equal. The preset should use `api.cohere.com`, the reference's host; whether `.ai` and `.com` may be
  interchanged is not confirmed.
- Hosted by the vendor, run by the customer, or both: both. Four ways, in Cohere's own split:
  1. The Cohere platform (SaaS): models on Cohere's infrastructure, managed by Cohere [deploy].
  2. Cloud AI services, hosted by the cloud provider: Amazon Bedrock, Amazon SageMaker, Azure AI Foundry, OCI
     Generative AI Service [deploy]. These have their own addresses, sign-in and model ids ([models] has a table per
     platform); they are not reached through `api.cohere.com` and are outside this file.
  3. Private deployments, run by the customer: in the customer's own cloud network (AWS, Azure, GCP or OCI) or
     on-premises, possibly air-gapped; "you manage the model deployment infrastructure" [private]. The page gives no
     base URL and does not say whether the private deployment serves the same `/v2/chat` shape: not confirmed.
  4. Model Vault: managed by Cohere on dedicated, not shared, infrastructure in a region the customer picks [vault].
     A Standard Vault is called at the vault's own endpoint URL (shown on its model card) with the customer's Cohere
     API key as bearer token; Cohere models accept `/v2/*` and `/compatibility/v1` there; open models hosted in a
     vault accept only OpenAI-format requests at `/v1` and refuse `/v2/*` and `/compatibility/v1` [vault-api]. An
     Encrypted Vault cannot be called directly; requests go through a local proxy that checks attestation (from a
     search summary of https://docs.cohere.com/v2/docs/model-vault/encrypted/api-usage; page not opened).
- API format: both. Its own Chat API v2 is the fuller one. The Compatibility API (OpenAI format) covers chat
  completions, embeddings and audio transcriptions, and leaves out several things; see "The Compatibility API" below.
- API versions or dated headers a request must send: none. The version is in the path (`/v2/chat`). The reference
  says "This page is for version v2 API (default)" [about]. No dated or version header is documented. v1 still has
  its own documentation; the migration guide gives no end date for v1 [migration].

## Signing in
- Method: an API key as bearer token, `Authorization: Bearer <key>` [chat-ref]. The overview page writes the shape as
  "`BEARER [API_KEY]`" [about]; the Model Vault curl example writes `bearer` in lower case [vault-api]. Send
  `Bearer`. Bodies are JSON, so `Content-Type: application/json` (used in every example; not listed as a parameter)
  [chat-ref]. One optional header: `X-Client-Name`, a free string naming the calling project [chat-ref]. No project,
  organisation or deployment field.
- What the customer must create at the provider, in plain words: an account at Cohere and an API key in the dashboard
  (the page names `dashboard.cohere.com/api-keys`) [about]. There are two kinds of key. A trial key is free and
  limited (chat: 20 requests a minute, 1,000 calls a month). A production key is paid and must be made by an
  organisation admin [faq] [rate-limits]. For a Model Vault the customer also needs the vault's endpoint URL and the
  model name from the model card [vault-api].

## What a request can do
| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | yes | `tools`: a list of `{"type": "function", "function": {"name", "description", "parameters"}}`. `type` is only `function`. `name` and `parameters` (a JSON Schema) are required, `description` is optional. Calls come back in `message.tool_calls`, with free text in `message.tool_plan`. | [chat-ref] [tools-guide] |
| Forced tool call | partly | `tool_choice`: `REQUIRED` (the model must call a tool; `tools` must be sent) or `NONE` (the model must answer without a tool). Leaving the field out lets the model decide; there is no `AUTO` value. "Only compatible with the Command R7B and newer models." **Forcing one named tool is not documented**: neither page offers a way to name a tool. Sending only that one tool together with `REQUIRED` is the documented way to get the same effect. | [chat-ref] [tools-patterns] |
| Parallel tool calls | yes, decided by the model | The model "will call multiple tools in parallel" when it finds that needed. All calls arrive in one assistant message; the caller answers with one `tool` message per call. No field to switch it off is documented on the native API; on the Compatibility API `parallel_tool_calls` is listed as unsupported. | [tools-patterns] [compat] |
| Structured output with a JSON Schema (and which schema keywords are refused) | yes | `response_format`: `{"type": "json_object"}` for any JSON, plus a schema for a fixed shape. **The two pages name the schema key differently:** the API reference says `json_schema` [chat-ref] [stream-ref]; the guide says `schema` [structured] (and so does the Compatibility guide [compat]). There is no type `json_schema` and no `strict` flag on `response_format`. Refused keywords and rules are listed under "Structured output: schema rules" below. Supported on "Command R, Command R+ and newer models" [stream-ref]. | [chat-ref] [structured] |
| Tools together with structured output | no | `response_format` "is not supported when used in combinations with the `documents` or `tools` parameters". For tool arguments that follow their schema there is `strict_tools: true` (top-level, for all tools at once), which the guide calls "currently an experimental parameter". | [chat-ref] [structured] |
| Streaming (and whether usage arrives in the stream) | yes | `stream: true` in the same request. Server-sent events, each with an `event:` line and a `data:` line of JSON. Usage and the finish reason arrive in the last event, `message-end`, without asking for them. No `[DONE]` line is shown. | [stream-ref] [streaming-guide] |
| System instructions (a role, or a separate field) | a role | A message with `role: "system"` in `messages`; `content` a string or a list of text blocks. Recommended as the first message. It replaces v1's `preamble`. | [chat-ref] [chat-guide] [migration] |
| Listing models | yes | `GET https://api.cohere.com/v1/models`, same bearer key. Query: `page_size` (default 20, 1 to 1000), `page_token`, `endpoint` (use `chat`), `default_only`. Answer: `models[]` with `name`, `is_deprecated`, `endpoints`, `default_endpoints`, `finetuned`, `context_length`, `tokenizer_url`, `features` (a list of strings whose values the page does not define), `sampling_defaults`; and `next_page_token`. | [models-ref] |

## Limits and shapes

### Request
- Required fields: `model` and `messages` [chat-ref]. The reference also prints `stream` as required with the value
  `false` on the Chat page and `true` on the stream page; that is how the reference splits one address into two
  pages. Its description says "Defaults to `false`" [chat-ref]. Send it explicitly in both cases.
- Required fields a neutral request may lack: none. `max_tokens` is optional and defaults to the model's output
  limit; a higher value is capped, not refused [chat-ref].
- Temperature: "a non-negative float", default `0.3`; no upper bound is stated on the page [chat-ref]. Upper bound:
  not confirmed.
- Other optional fields: `stop_sequences` (at most 5), `seed`, `frequency_penalty` and `presence_penalty` (0.0 to
  1.0), `k` (0 to 500, default 0), `p` (0.01 to 0.99, default 0.75; this is top-p under its own name), `logprobs`,
  `safety_mode` (`CONTEXTUAL` default, `STRICT`, `OFF`), `thinking` (`{"type": "enabled" | "disabled",
  "token_budget"}`), `priority` (0 to 999, default 0, lower first), `documents`, `citation_options` [chat-ref].
- Tool name rules: none stated. The reference describes `function.name` only as "The name of the function"
  [chat-ref]; the guides use lower-case names with underscores [tools-guide]. Allowed characters and length: not
  confirmed.
- Model name shape: lower case with hyphens and usually a month-year suffix, for example `command-a-03-2025`,
  `command-r7b-12-2024`, `command-a-plus-05-2026`. Undated names such as `command-r` are aliases and are marked
  deprecated since 15 September 2025 [models]. In a Model Vault the name is the one on the vault's model card
  [vault-api].

### Messages
Roles: `system`, `user`, `assistant`, `tool` [chat-ref].

| Role | `content` | Other fields |
|---|---|---|
| `system` | required; a string or a list of `{"type": "text", "text"}` | |
| `user` | required; a string or a list of blocks `text` or `image_url` | |
| `assistant` | optional; a string or a list of blocks `text` or `thinking` | `tool_calls`, `tool_plan`, `citations`, all optional |
| `tool` | required; a string or a list of blocks `text` or `document` | `tool_call_id` required |

In an **answer** `message.content` is always a list of blocks, never a plain string: `{"type": "text", "text": "…"}`
or `{"type": "thinking", "thinking": "…"}` [chat-ref]. The text of an answer is the joined `text` blocks. `content`
may be absent when the answer is only tool calls.

### The shape of a tool call in an answer, and of a tool result in a request
A tool call in `message.tool_calls[]`: `id` (required), `type` (`"function"`), `function.name`,
`function.arguments`. **`arguments` is a string holding JSON, not an object** [chat-ref] [tools-guide]. It must be
decoded by the adapter and can be invalid JSON. `message.tool_plan` is a free-text plan the model writes before its
calls; it is not the answer text.

```json
{
  "id": "synthetic-answer-1",
  "finish_reason": "TOOL_CALL",
  "message": {
    "role": "assistant",
    "tool_plan": "I will look up the order.",
    "tool_calls": [
      {
        "id": "find_order_a1b2c3",
        "type": "function",
        "function": {"name": "find_order", "arguments": "{\"number\":\"1001\"}"}
      }
    ]
  },
  "usage": {
    "billed_units": {"input_tokens": 20, "output_tokens": 12},
    "tokens": {"input_tokens": 95, "output_tokens": 12}
  }
}
```

Sending the result back [tools-guide] [tools-patterns] [migration]:

1. Append the assistant message as it came, with `tool_plan` and `tool_calls` (one assistant message holding all
   calls of that round).
2. Append one `tool` message per call. A result is matched to its call by `tool_call_id`, equal to the call's `id`.
   No empty user message is needed in between (v1 needed one).

`content` of a `tool` message is a string or a list of blocks. The reference describes the string form as a JSON
object in a string [chat-ref]; the guide says "a string or a list of objects" and uses `document` blocks in its
examples: `{"type": "document", "document": {"data": "<the result>", "id": "<optional>"}}`. With `document` blocks
the answer can carry citations that point to the result [tools-guide].

```json
[
  {
    "role": "assistant",
    "tool_plan": "I will look up the order.",
    "tool_calls": [
      {
        "id": "find_order_a1b2c3",
        "type": "function",
        "function": {"name": "find_order", "arguments": "{\"number\":\"1001\"}"}
      }
    ]
  },
  {
    "role": "tool",
    "tool_call_id": "find_order_a1b2c3",
    "content": [
      {"type": "document", "document": {"data": "{\"number\":\"1001\",\"status\":\"sent\"}"}}
    ]
  }
]
```

### Finish reasons and what each means
`finish_reason`, upper case, required in an answer [chat-ref]:

| Value | Meaning as the reference gives it |
|---|---|
| `COMPLETE` | the model finished its answer |
| `STOP_SEQUENCE` | one of the given stop sequences was reached |
| `MAX_TOKENS` | `max_tokens` or the model's context length was reached; the answer is cut off |
| `TOOL_CALL` | the model made tool calls and waits for their results |
| `ERROR` | generation failed through an internal error (arrives inside a 200, or in `message-end` of a stream) |
| `TIMEOUT` | generation took longer than the time limit |

The chat guide lists only `COMPLETE` and `MAX_TOKENS` [chat-guide]; the reference's list is the full one. There is
no finish reason for a refusal or for blocked content: see "Errors".

### Usage
`usage` is optional in an answer [chat-ref]:

- `usage.billed_units`: `input_tokens`, `output_tokens`, `search_units`, `classifications`. What the customer pays
  for.
- `usage.tokens`: `input_tokens`, `output_tokens`. What the model counted.
- `usage.cached_tokens`: prompt tokens that hit the inference cache.

The two sets differ: the reference's own example shows `billed_units.input_tokens` 5 against `tokens.input_tokens`
71 [chat-ref]. The neutral input and output token counts should come from `usage.tokens`; `billed_units` is a
billing figure. All these numbers are typed as doubles in the reference, so the adapter must accept `12.0` as well
as `12`. Every field is optional.

Two pages disagree on the place: the reference puts the counts under `usage` [chat-ref]; the chat guide's example
shows them under `meta` (`meta.billed_units`, `meta.tokens`, next to `meta.api_version` and `meta.warnings`)
[chat-guide]. Build to `usage`, the reference; do not fail when it is absent.

### The stream
Asked for with `"stream": true` on the same address. Server-sent events; every event has an `event:` line with the
type and a `data:` line with a JSON object that repeats the type in `type` [stream-ref]:

```
event: content-delta
data: {"type":"content-delta","index":0,"delta":{"message":{"content":{"text":"Hel"}}}}
```

| Event | Carries |
|---|---|
| `message-start` | `id` of the answer; `delta.message.role` = `assistant`. Once, first. |
| `content-start` | `index`; `delta.message.content.type` (`text` or `thinking`) with an empty `text` or `thinking` |
| `content-delta` | `index`; `delta.message.content.text` (or `.thinking`); `logprobs` when asked |
| `content-end` | `index` |
| `tool-plan-delta` | `delta.message.tool_plan`, a piece of the plan text; no `index` |
| `tool-call-start` | `index`; `delta.message.tool_calls` = one object (not a list) with `id`, `type`, `function.name` and an empty `function.arguments` |
| `tool-call-delta` | `index`; `delta.message.tool_calls.function.arguments`, a piece of the arguments string |
| `tool-call-end` | `index` |
| `citation-start` | `index`; `delta.message.citations` with `start`, `end`, `text`, `sources`, `type`, `content_index` |
| `citation-end` | `index` |
| `message-end` | `id`; `delta.finish_reason`; `delta.usage` (same shape as above); optionally `delta.error`, "an error message if an error occurred during the generation". Once, last. |
| `debug` | `event_type`, optionally `prompt`; to be ignored |

Order [stream-ref] [streaming-guide]:

- Plain text: `message-start`, `content-start`, `content-delta` (repeated), `content-end`, `message-end`.
- Tool calls: `message-start`, `tool-plan-delta` (repeated), then per call `tool-call-start`, `tool-call-delta`
  (repeated), `tool-call-end`, with `index` going up per call, then `message-end` with `finish_reason` `TOOL_CALL`.
- With documents: `citation-start` and `citation-end` pairs appear among the content events.

For the adapter: the tool call's id and name are known at `tool-call-start`; the arguments are complete at
`tool-call-end`, after joining the `tool-call-delta` pieces. Usage needs no extra request field. The pages show no
`[DONE]` line and no separate error event; a failure during generation is `message-end` with `finish_reason`
`ERROR` or `TIMEOUT` and `delta.error`. What the stream looks like when the connection or the provider fails before
`message-end`: not confirmed.

### Structured output: schema rules
From the structured-output guide [structured], which gives one table for `response_format` ("JSON") and for
`strict_tools` ("Tools").

- Without a schema, `{"type": "json_object"}` gives valid JSON of at most 5 levels deep, and the prompt must itself
  ask for JSON; otherwise the model may go on generating until the context is full [chat-ref] [structured].
- With a schema: the top level must be `"type": "object"`, and "every object in the schema must have at least one
  `required` field specified". Depth is not limited.
- Accepted: string, integer, float, boolean; arrays (typed, untyped, nested); nested objects; `enum`; `const` (int,
  float, bool, null, string); `pattern`; `format` only for `date-time`, `uuid`, `date`, `time`; `$ref`; `$def`;
  `anyOf`; `additionalProperties` (with exceptions the page notes).
- Refused: `allOf`, `oneOf`, `not`; `minimum`, `maximum`; `minItems`, `maxItems`; `minLength`, `maxLength`;
  `uniqueItems` (in both strict modes); in `pattern` the tokens `^`, `$`, `?=`, `?!`; every other `format`.
- `strict_tools: true`: every tool needs at least one `required` parameter (a tool with only optional parameters is
  not supported); at most 200 fields over all tools of one request; v2 only; the first requests with a new set of
  tools are slower [structured] [tools-params] [chat-ref]. Status: experimental according to [structured]; the
  reference and [tools-params] give no status.
- The guide says JSON mode is "not supported in RAG mode"; the reference says the same for `documents` and for
  `tools` [chat-ref].
- What the API answers when a schema uses a refused keyword (which status, which message): not confirmed.

### Error body shape, error codes, rate-limit headers
- Body: the reference gives every error answer two optional fields, `message` (string) and `id` (string)
  [chat-ref]. No `code` or `type` field is documented, so there is no plain-word provider error code to pass on;
  the status and `message` are all there is.

```json
{"id": "synthetic-error-1", "message": "invalid api token"}
```

| Status | Meaning on Cohere's pages | Link |
|---|---|---|
| 400 | the body is invalid: a missing field or a wrong value | [errors] |
| 401 | the key is missing, invalid or expired ("no api key supplied", "invalid api token") | [errors] |
| 402 | the account reached its billing limit | [errors] (not in the list on [chat-ref]) |
| 403 | forbidden; listed without further explanation read | [chat-ref] |
| 404 | not found; **also the unknown model**: "model 'xyz' not found, make sure the correct model ID was used" | [errors] |
| 422 | the request "is not well formed": invalid JSON, a missing required field, or an invalid combination of fields | [stream-ref] [chat-ref] |
| 429 | the rate limit was passed: "You are past the per minute request limit" | [errors] |
| 498 | "a request or response contains a deny-listed token" | [stream-ref] [chat-ref] |
| 499 | the request was cancelled, usually by the client | [errors] |
| 500 | internal error | [errors] |
| 501, 503, 504 | not implemented, service unavailable, gateway timeout; listed without further explanation read | [chat-ref] |

- Rate limit: 429. Bad credentials: 401. Unknown model: 404. Both 400 and 422 are "the request is wrong".
- Refusal: no status and no finish reason is documented. With `safety_mode` the model declines in ordinary answer
  text (the example begins "I'm sorry, but I cannot…") [safety]. The nearest thing to a block is status 498 for a
  deny-listed token; when exactly it is returned is not explained further.
- Retry header: none documented. The error page, the rate-limit page and the FAQ mention neither `Retry-After` nor
  any rate-limit header [errors] [rate-limits] [faq]. Whether the API sends one: not confirmed.
- An error can also sit inside a 200: `finish_reason` `ERROR` or `TIMEOUT`, and in a stream `delta.error` on
  `message-end` [chat-ref] [stream-ref].
- Rate limits for chat: trial key 20 requests a minute and 1,000 calls a month; production key 500 requests a
  minute for most Command models, "contact sales" for the newest ones [rate-limits]. The FAQ says 1,000 calls a
  minute for production keys [faq]; the two pages disagree.

### The Compatibility API (OpenAI format)
[compat], all points from that page.

- Base URL in its examples: `https://api.cohere.ai/compatibility/v1` (see the note on the two hosts above), sign-in
  with the same Cohere key as bearer token. Endpoints: `/chat/completions`, `/embeddings`,
  `/audio/transcriptions`.
- Status: the page gives none. It is not called beta, preview or production. It describes itself as a way "to use
  Cohere's models through OpenAI's SDK" and as "a quickstart guide". Only the Java and Go client libraries are
  marked "(beta)".
- Supported chat parameters, as listed: `model`, `messages`, `stream`, `reasoning_effort` (only `none` and `high`),
  `response_format`, `tools`, `temperature`, `max_tokens`, `stop`, `seed`, `top_p`, `frequency_penalty`,
  `presence_penalty`.
- Unsupported, as listed: `store`, `metadata`, `logit_bias`, `top_logprobs`, `n`, `modalities`, `prediction`,
  `audio`, `service_tier`, `parallel_tool_calls`. Cohere's own extras (`documents`, `citation_options`, connectors)
  are not available either.
- In neither list: `tool_choice`, `stream_options`, `max_completion_tokens`. So a forced tool call and usage in a
  stream are not confirmed on this endpoint.
- Structured output: `response_format` is `{"type": "json_object", "schema": {…}}`, Cohere's shape, not OpenAI's
  `{"type": "json_schema", "json_schema": {…, "strict": true}}`. An adapter for the OpenAI format would need a
  preset difference for it.
- Tools: OpenAI's `tools` shape; results as `role: "tool"` with `tool_call_id` and string content; arguments as a
  JSON string. The page says setting `strict` to true makes tool calls follow the schema, but no example shows where
  the field goes.
- System instructions use the role `developer` in the examples.
- The native v2 API is the fuller one: it has `tool_choice`, `strict_tools`, documented usage in the stream and a
  documented event list.

## Data
- Where requests are processed, as the provider states it: for the standard API at `api.cohere.com`, **no documentation
  page read states a place.** The security page says only that the API is hosted on cloud infrastructure and points
  to the Trust Center [security]; the FAQ and the data commitments do the same [faq] [data-commitments]. The Trust
  Center's sub-processor list names Google Cloud as "Infrastructure Service Provider" with processing location
  "USA", and gives "USA" for every other sub-processor [subprocessors]. The privacy policy (which excludes
  enterprise customers' inputs and outputs) says Cohere "may transfer, store, and/or receive certain personal
  information outside of your jurisdiction of residence", naming Canada, the United States and the United Kingdom
  [privacy]. So: United States by the sub-processor list; no EU option on the shared API was found.
- An EU option exists only through Model Vault: the customer picks the United States, the European Union or Canada
  when creating a vault, and "every model in the vault runs in that region" [vault-regions]. Encrypted Vaults are
  US only for now [vault-regions]. A vault is paid per GPU per hour and is a different product from the shared API
  [vault].
- Private deployments and cloud AI platforms: Cohere "does not receive any customer inputs (prompts) or outputs
  (generations)" [data-commitments]; in a private deployment "the data never leaves your environment" [private].
  Where a cloud platform processes is that platform's matter.
- The option not to store requests: there is **no request field for it**. On the shared API, logged prompts and
  answers are deleted "after 30 days", with exceptions for legal duties, contracts and flagged misuse. Zero data
  retention exists only for enterprise customers who make extra commitments, on request by e-mail to Cohere's
  support; once approved, Cohere "does not log any customer prompts or generations" [data-commitments]. In a Model
  Vault zero data retention "is on by default" [vault-standard].
- Training: a customer "can opt out from your prompts and generations being used to train Cohere models" with a
  toggle under Settings, Data Controls in the dashboard [data-commitments]. An opt-out implies that training is not
  off by default; the page does not state the default in so many words: not confirmed. It is an account setting,
  not something a request can send.

## Not confirmed
- Whether `api.cohere.com` and `api.cohere.ai` are interchangeable. Tried: [chat-ref], [about], [compat]; the pages
  only use one or the other.
- The name of the schema key inside `response_format`: `json_schema` (reference) or `schema` (guides). Both read
  today; Cohere's pages disagree. Only a live check can settle it; until then the adapter needs this as one
  constant, and the live check must test it first.
- Forcing one named tool. Tried: [chat-ref], [tools-patterns]; only `REQUIRED` and `NONE` exist.
- Switching parallel tool calls off on the native API. Tried: [chat-ref], [tools-patterns].
- Upper bound of `temperature`; tool name characters and length. Tried: [chat-ref], [tools-guide], [tools-params].
- A `Retry-After` or rate-limit header. Tried: [errors], [rate-limits], [faq], [chat-ref].
- Status and message for a schema with a refused keyword, and for `response_format` sent together with `tools`.
  Tried: [structured], [chat-ref] (422 mentions "an invalid combination of fields" in general).
- When 403 and 498 are returned, in detail. Tried: [chat-ref], [stream-ref], [errors].
- A refusal signal other than answer text. Tried: [safety], [chat-ref].
- What a stream looks like when it breaks before `message-end`, and whether an HTTP error for a streamed request
  has the same `message` and `id` body. Tried: [stream-ref], [streaming-guide].
- Whether usage is under `usage` or `meta` in every answer. Reference says `usage`, the chat guide's example `meta`.
- The values of `features` in the model list, so whether it tells tool or structured-output support per model.
  Tried: [models-ref].
- Whether training on prompts is on by default for every kind of account; where the shared API processes requests
  beyond the sub-processor list's "USA"; any EU address for the shared API. Tried: [data-commitments], [security],
  [privacy], [subprocessors], [faq].
- Base URL and API shape of a private deployment. Tried: [private], [deploy].
- Calling an Encrypted Vault: from a search summary only.
- On the Compatibility API: its status, `tool_choice`, `stream_options`, usage in the stream, and where `strict`
  goes. Tried: [compat].
- An end date for API v1. Tried: [migration], [about].

## Proposed overview row
For `docs/providers/overview.md`, read on 2026-11-27:

| Provider | Hosted model API | API format | Sign-in | Tool calling | JSON-schema output | Streaming | Where data is processed | Run by |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Cohere | yes | own Chat API v2 (`https://api.cohere.com/v2/chat`, the fuller one); also a Compatibility API in the OpenAI format (`/compatibility/v1`, no status stated, `parallel_tool_calls` unsupported, `tool_choice` not mentioned) | API key (bearer); no version header | yes; `tool_choice` `REQUIRED` or `NONE`, no named tool; several calls at once decided by the model, no switch; arguments as a JSON string; model list at `GET /v1/models` | natively `response_format` `json_object` with a schema (key `json_schema` in the reference, `schema` in the guide); **not together with tools**; `strict_tools` experimental; refuses `allOf`, `oneOf`, `not`, numeric and length limits | yes, server-sent events with named events; usage and finish reason in `message-end` | shared API: not stated in the documentation; the sub-processor list names Google Cloud, "USA". Model Vault: US, EU or Canada, zero data retention by default. Shared API: logs deleted after 30 days, zero retention only for approved enterprise customers, training opt-out in the dashboard | both (vendor; Model Vault; Bedrock, SageMaker, Azure AI Foundry, OCI; private deployment by the customer) |
