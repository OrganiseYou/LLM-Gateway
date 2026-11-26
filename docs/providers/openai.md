# OpenAI Platform (ChatGPT)

Read on 2026-11-27. Hosted text chat API: yes.

Every fact below was read on 2026-11-27 on the page linked next to it. Only OpenAI's own pages were used. Long pages
were read in parts; where a part could not be read the cell says "not confirmed". Model names are written as the
pages give them. All examples are synthetic.

Short names for the pages:

- [REF-CREATE]: https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create
- [REF-CHAT]: https://developers.openai.com/api/reference/resources/chat
- [REF-OVERVIEW]: https://developers.openai.com/api/reference/overview
- [REF-MODELS]: https://developers.openai.com/api/reference/resources/models/methods/list
- [G-MIGRATE]: https://developers.openai.com/api/docs/guides/migrate-to-responses
- [G-STRUCT]: https://developers.openai.com/api/docs/guides/structured-outputs.md
- [G-FUNC]: https://developers.openai.com/api/docs/guides/function-calling
- [G-REASON]: https://developers.openai.com/api/docs/guides/reasoning
- [G-TEXT]: https://developers.openai.com/api/docs/guides/text
- [G-ERRORS]: https://developers.openai.com/api/docs/guides/error-codes
- [G-RATE]: https://developers.openai.com/api/docs/guides/rate-limits
- [G-DATA]: https://developers.openai.com/api/docs/guides/your-data
- [G-RESIDENCY]: https://developers.openai.com/api/docs/guides/data-residency

## Which API OpenAI recommends, and what it means for this library

- OpenAI recommends the Responses API (`POST /v1/responses`) over Chat Completions. "While Chat Completions remains
  supported, Responses is recommended for all new projects." [G-MIGRATE]. The Chat Completions reference page carries
  the same advice [REF-CREATE], and the text guide calls Chat Completions "the older Chat Completions API" [G-TEXT].
- Chat Completions is not marked deprecated and no end date is given on the pages read [G-MIGRATE]. (The Assistants
  API, a different API, was shut down on 2026-08-26 [G-MIGRATE].)
- **Tool calling on Chat Completions no longer works on OpenAI's newest models.** [G-MIGRATE] says: "Starting with
  GPT-5.4, Chat Completions does not support tool calling with `reasoning_effort` values other than `none`", and that
  GPT-6 Astra and GPT-6.1 Sol "require the Responses API for tool calling". [G-REASON] repeats it: "Chat Completions
  does not support function calling with GPT-6 Astra or GPT-6.1 Sol." [G-REASON] also says that on GPT-6 Astra, and
  on GPT-6.2 Astra and GPT-6.2 Sol, setting `reasoning_effort` to `none` returns HTTP 400. Read together: on those
  models tool calling through Chat Completions is not available at all.
- The differences between the two APIs [G-MIGRATE]: `messages` in and `choices[].message` out, against `input` in
  and a typed `output` list out; `response_format` against `text.format`; a tool definition nested under a `function`
  key against a flat one; the caller resends the transcript, against optional server-side state
  (`previous_response_id`); different storage defaults (see Data).
- What this means for a library that implements the Chat Completions format because many other servers speak it:
  1. The Chat Completions adapter still reaches OpenAI for plain answers, streaming and structured output, and for
     tool calling on models where the page above does not exclude it.
  2. Tool calling, and so the forced tool call the application relies on, is a per-model capability on this preset,
     not a per-provider one. For the models named above it is absent through this adapter.
  3. Full coverage of OpenAI's newest models with tools needs a second adapter for the Responses format. That is a
     new API format in the sense of LR-20 and LR-23, not preset data.
  4. The Responses format keeps conversation state at the provider when asked (`previous_response_id`) and stores by
     default; an adapter for it would have to send `store: false` and resend the transcript to stay inside LR-01,
     LR-09 and LR-31.

## Reaching it

- Base URL and the chat path: `https://api.openai.com/v1`, chat at `POST /chat/completions`. The reference gives the
  path as `/chat/completions` and its examples use `https://api.openai.com/v1/chat/completions` [REF-CREATE]. No page
  read states the base URL as a sentence; it is taken from the examples [REF-OVERVIEW].
- Regional addresses for projects with data residency: `us.api.openai.com`, `eu.api.openai.com`, `au.`, `jp.`,
  `in.`, `ae.`, `ca.`, `sg.`, `kr.`, `gb.api.openai.com` [G-RESIDENCY]. See Data.
- Hosted by the vendor, run by the customer, or both: hosted by the vendor. No page read describes a way for a
  customer to run these models.
- API format: its own. The Chat Completions format is the one other servers copy ("the OpenAI format"); the Responses
  API is OpenAI's own newer format and the fuller one (see the section above).
- API versions or dated headers a request must send: none. The REST API is `v1` in the path. The answer carries an
  `openai-version` header (the page shows `2020-10-01`); it is a response header, not something to send
  [REF-OVERVIEW]. No beta header is mentioned on the pages read.
- Headers a request sends: `Authorization: Bearer <key>` and `Content-Type: application/json` (the body is
  `application/json`) [REF-OVERVIEW], [REF-CREATE]. Optional: `OpenAI-Organization`, `OpenAI-Project`,
  `X-Client-Request-Id` (ASCII, at most 512 characters, otherwise a 400) [REF-OVERVIEW]. All request headers together
  should stay under 64 KiB [REF-OVERVIEW].
- Headers in an answer worth keeping: `x-request-id` (OpenAI advises logging it), `openai-processing-ms`,
  `openai-organization`, `openai-version`, and the rate-limit headers below [REF-OVERVIEW].
- Compatibility promise: OpenAI may add resources, optional parameters, response properties and stream event types
  without calling it a breaking change [REF-OVERVIEW]. An adapter must ignore fields it does not know.

## Signing in

- Method: HTTP bearer, `Authorization: Bearer <API key>`. A short-lived access token from workload identity
  federation is accepted in the same place [REF-OVERVIEW].
- Organization and project: the headers `OpenAI-Organization` and `OpenAI-Project` say which organization and
  project a request counts for. They are needed for a person in more than one organization or with a legacy user
  key [REF-OVERVIEW].
- What the customer must create at the provider, in plain words: an OpenAI Platform account with an organization, a
  project in it, and an API key for that project. For processing in Europe: a project created with the Europe
  region, which needs OpenAI's sales team to make the organization eligible first, and a key of that project
  [G-RESIDENCY].

## What a request can do

All rows are for Chat Completions.

| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | yes, but not on every model: see "Which API OpenAI recommends" | `tools`: a list of `{"type":"function","function":{"name","description","parameters","strict"}}`; `parameters` is a JSON Schema; `strict` defaults to false. A second tool type `custom` (free-text input) exists. The older `functions` and `function_call` are deprecated. | [REF-CREATE], [G-MIGRATE], [G-REASON] |
| Forced tool call | yes | `tool_choice`: `"none"`, `"auto"`, `"required"`, a named function `{"type":"function","function":{"name":"..."}}`, or `allowed_tools` (a subset with mode `auto` or `required`). Default `none` without tools, `auto` with tools. | [REF-CREATE] |
| Parallel tool calls | yes, on by default | `parallel_tool_calls` (boolean, default true); false means zero or one call. For the snapshot `gpt-4.1-nano-2025-04-14` the guide advises switching it off. With a fine-tuned model, several calls in one turn switch strict mode off for those calls. | [REF-CREATE], [G-FUNC] |
| Structured output with a JSON Schema | yes | `response_format`: `{"type":"json_schema","json_schema":{"name","description","schema","strict"}}`; `strict` defaults to false; `name` follows the tool-name rule. Refused and accepted keywords: see "Structured output: the schema subset". The older `{"type":"json_object"}` gives valid JSON without a schema. | [REF-CREATE], [G-STRUCT] |
| Tools together with structured output | not confirmed | Neither the reference nor the guide says that `response_format` and `tools` can or cannot be sent together. | [REF-CREATE], [G-STRUCT] |
| Streaming | yes, server-sent events | `stream: true`. Each event is a `data:` line with a `chat.completion.chunk` object; the stream ends with `data: [DONE]`. Usage arrives only when `stream_options: {"include_usage": true}` is sent: one extra chunk before `[DONE]` with `usage` filled and an empty `choices` list. | [REF-CREATE], [REF-CHAT] |
| System instructions | a role | A message with role `developer` or `system`. The reference says that for o1 and newer models `developer` replaces `system`; `system` is described as the older equivalent. Whether newer models still accept `system`: not confirmed. | [REF-CREATE] |
| Listing models | yes | `GET /models` returns `{"object":"list","data":[...]}`; each model has `id`, `object` ("model"), `created`, `owned_by`, and an optional `shutdown_date`. No paging is documented. No context length and no capabilities are returned. | [REF-MODELS] |

### Structured output: the schema subset

With `strict: true` only a subset of JSON Schema is accepted, and a schema outside it is answered with an error
[REF-CREATE], [G-STRUCT]. The guide's examples use the Responses field `text.format`; the reference for Chat
Completions points to the same subset through the `strict` field.

- Types: string, number, boolean, integer, object, array, enum, `anyOf` [G-STRUCT].
- Accepted keywords: for strings `pattern` and `format` (`date-time`, `time`, `date`, `duration`, `email`,
  `hostname`, `ipv4`, `ipv6`, `uuid`); for numbers `multipleOf`, `maximum`, `exclusiveMaximum`, `minimum`,
  `exclusiveMinimum`; for arrays `minItems`, `maxItems`; `$defs` with `$ref`, also recursive (`"$ref": "#"`)
  [G-STRUCT].
- Refused keywords: `allOf`, `not`, `dependentRequired`, `dependentSchemas`, `if`, `then`, `else` [G-STRUCT].
- Refused on fine-tuned models as well: `minLength`, `maxLength`, `pattern`, `format`, `minimum`, `maximum`,
  `multipleOf`, `patternProperties`, `minItems`, `maxItems` [G-STRUCT]. `minLength` and `maxLength` are not in the
  accepted list for other models either; whether they are refused there: not confirmed.
- Rules: the root must be an object and may not be an `anyOf`; every property must be listed in `required` (an
  optional field is written as a union with `null`); every object must set `additionalProperties: false` [G-STRUCT].
- Limits: 10 levels of nesting; 5,000 object properties in total; 120,000 characters for all property names,
  definition names, enum values and const values together; 1,000 enum values in total; one string enum with more
  than 250 values may hold 15,000 characters at most [G-STRUCT].
- Output keys come in the order of the schema [G-STRUCT]. The first request with a new schema is slower [G-STRUCT].
- Models: `json_schema` works from `gpt-4o-mini`, `gpt-4o-mini-2024-07-18` and `gpt-4o-2024-08-06` on; older
  models have JSON mode only [G-STRUCT].
- JSON mode (`json_object`) needs the word "JSON" in a message, otherwise the API returns an error [G-STRUCT].
- A refusal does not follow the schema; it arrives in `message.refusal` (see below) [G-STRUCT], [REF-CHAT].
- The same rules apply to a function tool with `strict: true` [G-FUNC]. Chat Completions is non-strict unless
  `strict` is sent [G-FUNC]; [G-MIGRATE] advises sending `strict` explicitly because the two APIs default differently.

## Limits and shapes

- Required fields a neutral request may lack: none. Only `model` and `messages` (at least one) are required
  [REF-CREATE]. There is no mandatory maximum-tokens field.
- Maximum output tokens: `max_completion_tokens` (visible output plus reasoning tokens). `max_tokens` is deprecated
  and "not compatible with o-series models" [REF-CREATE]. A reasoning model can reach the limit before any visible
  text is produced; the guide suggests leaving room of at least 25,000 tokens when starting out [G-REASON].
- Temperature range: 0 to 2, default 1 [REF-CREATE]. Whether reasoning models refuse `temperature`: not confirmed.
- `n`: default 1; the reference says newer models reject an explicit `n`, even `n=1`, so the adapter should not
  send it [REF-CREATE]. `stop` is not supported by `o3` and `o4-mini` [REF-CREATE].
- Tool name rules: letters a-z and A-Z, digits, underscores and dashes, at most 64 characters; the same rule for
  `response_format.json_schema.name` [REF-CREATE]. A maximum number of `tools`: not stated (the deprecated
  `functions` list allows 128) [REF-CREATE]; the guide advises fewer than 20 [G-FUNC].
- Model name shape: a free string such as `gpt-4o-2024-08-06`; undated aliases and dated snapshots both exist
  [REF-CREATE], [G-STRUCT].
- Message roles in a request: `developer`, `system`, `user`, `assistant`, `tool`, and the deprecated `function`
  [REF-CREATE]. `content` is a string or a list of parts; an assistant message may leave `content` out when it has
  `tool_calls` [REF-CREATE].
- The shape of a tool call in an answer [REF-CHAT]:

  ```json
  {"id": "call_example1", "type": "function",
   "function": {"name": "lookup_order", "arguments": "{\"order_id\":\"A-1\"}"}}
  ```

  `function.arguments` is a **string holding JSON**, not an object. The reference warns that it may be invalid JSON
  or hold parameters that are not in the schema, so it must be checked before use [REF-CHAT]. Tool calls sit in
  `choices[].message.tool_calls`; `message.content` may then be null.
- The shape of a tool result in a request [REF-CREATE]: the assistant message with its `tool_calls` is sent back
  unchanged, followed by one message per call:

  ```json
  {"role": "tool", "tool_call_id": "call_example1", "content": "{\"status\":\"shipped\"}"}
  ```

  `tool_call_id` is required and must equal the `id` of the call; `content` is a string or text parts.
- The answer [REF-CHAT]: `id`, `object` ("chat.completion"), `created`, `model`, `choices[]` (`index`, `message`,
  `finish_reason`, `logprobs`), `usage`, and optional fields such as `system_fingerprint` and `service_tier`.
  `message` has `role` ("assistant"), `content` (string or null), `refusal` (string or null), `tool_calls`.
- Finish reasons [REF-CHAT]: `stop` (a natural end or a stop sequence); `length` (the token limit was reached);
  `tool_calls` (the model called tools); `content_filter` (content was left out by a content filter);
  `function_call` (deprecated).
- Content refusal: not an HTTP error. The model's own refusal arrives as text in `message.refusal` (in a stream
  `delta.refusal`) inside a normal answer, and filtered content shows as finish reason `content_filter`
  [REF-CHAT], [G-STRUCT]. An HTTP status for a refused request: not confirmed.
- Usage [REF-CHAT]: `usage.prompt_tokens`, `usage.completion_tokens`, `usage.total_tokens`, with
  `completion_tokens_details` (among them `reasoning_tokens`) and `prompt_tokens_details` (among them
  `cached_tokens`). `usage` is optional in the object.
- A stream [REF-CHAT]: each chunk has `id`, `object` ("chat.completion.chunk"), `created`, `model`, `choices[]`
  with `index`, `delta` and `finish_reason` (null until the last content chunk). `delta` may hold `role`,
  `content`, `refusal` and `tool_calls`. A tool call in a delta has `index` (which call it belongs to), and
  optionally `id`, `type` and `function.name` / `function.arguments`; the argument string arrives in pieces that
  the adapter joins per `index`. `usage` is null on every chunk except the extra last one, and only with
  `include_usage`; if the stream breaks, that chunk may never come. A chunk may carry an `obfuscation` string of
  padding unless `stream_options.include_obfuscation` is false. How an error shows inside a running stream: not
  confirmed.
- Error body shape [G-ERRORS]: an `error` object with `message`, `type`, `param` and `code`. A full JSON example
  was not on the page read; the four field names are.
- Statuses and codes [G-ERRORS], [G-RATE]:

  | Status | Meaning | `type` / `code` named on the page | Retry |
  |---|---|---|---|
  | 400 | the request is refused (example given: a service tier that is not allowed) | `invalid_request_error` | no |
  | 401 | bad credentials: wrong, revoked or deleted key, wrong organization or project, no organization, IP not on the allow list | not named | no |
  | 403 | country, region or territory not supported | not named | no |
  | 429 | rate limit on requests or tokens | `rate_limit_error`, code `slow_down` for a too fast rise | yes |
  | 429 | no credit or a spend or usage limit reached | type `insufficient_quota`; codes `credit_balance_exhausted`, `organization_spend_limit_exceeded`, `project_spend_limit_exceeded`, `organization_usage_limit_exceeded` | **no**: "Retrying billing, spend, or quota errors won't restore API access." |
  | 500 | error at OpenAI | not named | yes, after a short wait |
  | 503 | model overloaded | `service_unavailable_error`, code `server_is_overloaded` | yes |

  So 429 alone does not mean "wait and retry": the adapter must read `error.type` and `error.code`.
- Unknown model: the status and code for Chat Completions are not confirmed. The error guide does not list it. A
  search summary (not a page read) of OpenAI's Agents API error page mentions 404 with `model_not_found`; that is
  another API and not a reading.
- Rate-limit headers [G-RATE]: `Retry-After` in seconds (the page's sample is `56`), a minimum wait, present on
  429 and 503 for temporary conditions and absent on quota and billing errors. Also
  `x-ratelimit-limit-requests`, `x-ratelimit-limit-tokens`, `x-ratelimit-remaining-requests`,
  `x-ratelimit-remaining-tokens`, `x-ratelimit-reset-requests`, `x-ratelimit-reset-tokens` (the reset values are
  durations such as `1s` and `6m0s`), and three `...-project-tokens` variants when a project limit applies. Without
  `Retry-After` OpenAI advises exponential backoff with jitter and a cap [G-RATE], [G-ERRORS].

## Data

- Where requests are processed, as the provider states it: by default no region is promised; the residency guide's
  own example marks a request to the default address as "No processing constraint" [G-RESIDENCY]. A sentence that
  names a default country was not found on the pages read. With a project in a region: "If the region supports
  regional processing, inference for your Customer Content also runs in that region." [G-RESIDENCY]
- Europe (EEA and Switzerland): storage and processing in the region, through `eu.api.openai.com` with a key of a
  project created with that region; `/v1/chat/completions` and `/v1/responses` are both covered [G-RESIDENCY].
  The region of a project cannot be changed later. An organization becomes eligible through OpenAI's sales team.
  A "Global" project can instead allow residency per request: the regional address is then used on the requests
  that need it [G-RESIDENCY].
- Other regions [G-RESIDENCY]: United States (storage and processing); Australia, Japan, India and the United Arab
  Emirates (storage and processing, for a limited set of models); Canada, Singapore, South Korea and the United
  Kingdom (storage only).
- What residency does not cover [G-RESIDENCY]: data other than customer content (account data, usage data, billing,
  and the structured-output schema itself, which counts as system data); third-party services reached through
  OpenAI; where a feature has no regional processing, content may be processed and kept for a time outside the
  region. Models released on or after 2026-03-05 cost 10% more on residency addresses.
- Training: off by default. "data sent to the OpenAI API is not used to train or improve OpenAI models" unless the
  customer opts in [G-DATA].
- Kept for abuse monitoring: up to 30 days by default for `/v1/chat/completions` [G-DATA]. Zero Data Retention and
  Modified Abuse Monitoring take customer content out of those logs; both need OpenAI's approval, asked through
  sales, and are then set per organization or project [G-DATA].
- The option not to store requests: the request field `store`. Two OpenAI pages disagree about its default on Chat
  Completions:
  - the reference gives `store` a default of `false` and describes it as storing the output for OpenAI's
    distillation and evals products [REF-CREATE];
  - the migration guide says: "Chat completions are stored by default for new accounts." [G-MIGRATE]

  Because of this the adapter should always send `store: false` and not rely on the default. With Zero Data
  Retention `store` is always treated as false [G-DATA]. In regions other than the United States a chat completion
  cannot set `store` to true [G-RESIDENCY].
- For the Responses API: stored by default, kept at least 30 days when `store` is true or left out [G-DATA],
  [G-MIGRATE].

## Not confirmed

- Tools together with `response_format` in one Chat Completions request: no statement either way in [REF-CREATE]
  or [G-STRUCT].
- The HTTP status and error code for an unknown model on Chat Completions: not in [G-ERRORS]; only a search summary
  about another API.
- An HTTP status for a refused request; the pages read describe only `message.refusal` and `content_filter`.
- How an error is delivered inside a stream that already started: the streaming guide read
  (https://developers.openai.com/api/docs/guides/streaming-responses) covers the Responses API only.
- A complete JSON example of the error body, and the `type` and `code` values for 401, 403 and 500.
- Chat Completions examples for tool calling: [G-FUNC] says it has them, but the text read showed only the
  Responses form. The Chat Completions shapes above come from the reference [REF-CREATE], [REF-CHAT].
- Whether `system` messages are still accepted by the newest models, and whether reasoning models refuse
  `temperature` or other sampling fields: [G-REASON] and [G-TEXT] do not say for Chat Completions.
- Whether `minLength` and `maxLength` are refused in strict mode on models that are not fine-tuned.
- The exact list of models on which tool calling still works through Chat Completions: the pages name the models
  where it does not, not a full list.
- A default country or region for processing without data residency.
- A maximum number of entries in `tools`, and the full format of the `x-ratelimit-reset-*` values.
- Paths of the stored-completions list and delete endpoints (that part of [REF-CHAT] was not read); the library
  does not use them.
- The base URL as a stated sentence: it is taken from the examples in the reference.
