# Grok (xAI)

Read on 2026-11-27. Hosted text chat API: yes.

Every fact below was read on 2026-11-27 on the page linked next to it. Only xAI's own pages were used (`docs.x.ai`).
Pages were read through a page reader that returns the content in parts and shortens long quotes; where a part could
not be read the cell says "not confirmed". A fact marked "search summary" comes from a search result's summary of the
named page, not from opening it. Model names are written as the pages give them. All examples are synthetic.

The documentation pages read today name the company "SpaceXSI" in running text (for example "The SpaceXSI REST API");
the hosts are still `x.ai`, `docs.x.ai` and `api.x.ai`. This file says "xAI" and quotes the pages as they are.

Short names for the pages:

- [REF-OVERVIEW]: https://docs.x.ai/developers/api-reference
- [REF-CHAT]: https://docs.x.ai/developers/rest-api-reference/inference/chat-completions
- [REF-RESP]: https://docs.x.ai/developers/rest-api-reference/inference/responses
- [REF-MODELS]: https://docs.x.ai/developers/rest-api-reference/inference/models
- [REF-LEGACY]: https://docs.x.ai/developers/rest-api-reference/inference/legacy
- [G-CHAT]: https://docs.x.ai/docs/guides/chat-completions (the guide "Chat Completions", filed under the legacy
  model capabilities)
- [G-COMPARE]: https://docs.x.ai/developers/model-capabilities/text/comparison
- [G-FUNC]: https://docs.x.ai/developers/tools/function-calling
- [G-STRUCT]: https://docs.x.ai/developers/model-capabilities/text/structured-outputs
- [G-STREAM]: https://docs.x.ai/developers/model-capabilities/text/streaming
- [G-REASON]: https://docs.x.ai/developers/model-capabilities/text/reasoning
- [G-TOOLS]: https://docs.x.ai/developers/tools/overview
- [G-TOOLS-ADV]: https://docs.x.ai/developers/tools/advanced-usage
- [G-WEB]: https://docs.x.ai/developers/tools/web-search
- [G-ERRORS]: https://docs.x.ai/developers/debugging
- [G-RATE]: https://docs.x.ai/docs/key-information/consumption-and-rate-limits
- [G-REGIONS]: https://docs.x.ai/developers/regions
- [G-SECURITY]: https://docs.x.ai/developers/faq/security
- [G-MODELS]: https://docs.x.ai/docs/models
- [G-GROK47]: https://docs.x.ai/developers/grok-4-7
- [G-VERTEX]: https://docs.x.ai/developers/community/google-cloud-vertex-ai
- [G-RELEASE]: https://docs.x.ai/developers/release-notes

## Which API xAI recommends, and what it means for this library

- xAI recommends the Responses API (`POST /v1/responses`). [G-COMPARE] calls it "the recommended way to interact
  with" its models. [REF-CHAT] describes Chat Completions as the stateless, OpenAI-compatible predecessor of the
  Responses API and says: "New integrations should use Responses."
- The status of Chat Completions is worded differently per page; all four are recorded:
  - [G-CHAT] opens with a warning: "Chat Completions is deprecated and delivers strictly worse performance than the
    Responses API."
  - [G-COMPARE] heads its table column "Chat Completions API (Deprecated)" and says "Legacy endpoint, limited
    updates."
  - [G-FUNC] calls it "the legacy Chat Completions API".
  - [REF-CHAT] does not use "legacy" or "deprecated" for the endpoint itself, and [REF-LEGACY] (the page "Legacy &
    Deprecated") does not list `/v1/chat/completions`; it lists `/v1/completions`, `/v1/messages` and `/v1/complete`.
  - No end date for Chat Completions is given on any page read. The 2026 release notes still add fields to it
    (`guidelines` in October 2026, `safety_identifier` in September 2026, `service_tier` in June 2026) [G-RELEASE].
- Tool calling on Chat Completions for the newest models: no page read says that function calling is refused on
  Chat Completions for any model. [G-COMPARE] says Chat Completions has "function calling only" (no server-side
  tools), and [G-FUNC] documents the Chat Completions shape of a forced call. But [G-GROK47], the guide of the
  newest model `grok-4.7`, shows only the Responses API and does not say whether `grok-4.7` works on Chat
  Completions; the streaming guide's Chat Completions examples do use `grok-4.7` [G-STREAM]. Whether function
  calling through Chat Completions works on `grok-4.7`: not confirmed as a sentence.
- Differences between the two APIs [G-COMPARE]: `messages` against `input`; `max_tokens` against
  `max_output_tokens`; text in `choices[0].message.content` against an `output` list of typed items; the caller
  resends the history against `previous_response_id`; no storage against storage for 30 days by default (`store`
  defaults to `true`); function calling only against function calling plus server-side tools (search, code
  execution, MCP); a reasoning summary in `reasoning_content` against encrypted reasoning that can be carried
  across turns.
- The Anthropic-compatible endpoints (`/v1/messages`, `/v1/complete`) are "fully deprecated" [REF-LEGACY]; they
  are not an option for this library.
- What this means for a library that implements the Chat Completions format:
  1. The Chat Completions adapter reaches xAI today for plain answers, streaming, function calling and (see the
     rows below) structured output, with the differences listed under "Limits and shapes".
  2. xAI calls that API deprecated and says new capabilities arrive on Responses first. Full coverage in the long
     run needs an adapter for the Responses format (a new format in the sense of LR-20 and LR-23).
  3. A Responses adapter would have to send `store: false` and resend the transcript to stay inside LR-01, LR-09
     and LR-31, because Responses stores for 30 days by default [G-COMPARE], [REF-RESP].

## Reaching it

- Base URL and the chat path: `https://api.x.ai`, chat at `POST /v1/chat/completions` [REF-OVERVIEW], [REF-CHAT].
  The guides use `https://api.x.ai/v1` as the base for an OpenAI-style client [G-CHAT], [G-STREAM].
- Regional address: `https://us.api.x.ai/v1` (United States) [G-REGIONS]. It serves only `grok-4.7` and
  `grok-4.6`; another model, including `grok-latest`, is answered with 404; image and video generation are not
  served there; token prices are 10% higher [G-REGIONS]. Function calling and structured outputs take the same
  parameters there [G-REGIONS]. **No EU address is on the regions page.** See Data.
- Hosted by the vendor, run by the customer, or both: hosted by the vendor. No page read describes a way for a
  customer to run the models. xAI's documentation also describes reaching the models through Google Cloud Vertex AI
  (Model Garden, publisher xAI, model ids such as `xai/grok-4.7`, Google sign-in, an endpoint taken from Google's
  model card, both Responses and Chat Completions); that is hosted by Google, not by the customer [G-VERTEX].
- API format: OpenAI-compatible, in two formats. "The SpaceXSI REST API is compatible with the OpenAI REST API."
  [REF-OVERVIEW]. Chat Completions and Responses both exist; Responses is the fuller one and the recommended one
  (see above). xAI's own SDK uses gRPC [G-RATE], [REF-LEGACY]; not relevant for this library.
- API versions or dated headers a request must send: none mentioned [REF-OVERVIEW].
- Headers a request sends: `Authorization: Bearer <key>` and `Content-Type: application/json`; a missing body or
  a missing `Content-Type` gives 415 [REF-OVERVIEW], [G-ERRORS]. Optional on Chat Completions: `x-grok-conv-id`,
  for prompt caching [G-GROK47].
- Headers in an answer: `x-zero-data-retention` with `true` or `false` on every answer [G-SECURITY]. A request-id
  header: not confirmed.
- Timeouts: the guides set 3600 seconds on the client for reasoning models, also when streaming [G-CHAT],
  [G-STREAM], [G-REASON]. A long-running call can instead be sent with `deferred: true` and fetched from
  `GET /v1/chat/deferred-completion/{request_id}` (200 when done, 202 while pending) [REF-CHAT]; that is state at
  the provider and is switched off under zero data retention [G-SECURITY], so this library does not use it.

## Signing in

- Method: HTTP bearer, `Authorization: Bearer <API key>` on every inference route [REF-OVERVIEW], [G-ERRORS]. No
  organization, project or deployment field is part of a request. A separate "Management API key" exists for
  `https://management-api.x.ai` (keys, teams, billing); the chat API does not use it [REF-OVERVIEW].
- What the customer must create at the provider, in plain words: an account in the xAI Console with a team, and an
  API key for that team (the page for it is "API Keys" in the Console) [REF-OVERVIEW], [G-CHAT]. A key or team
  can lack permission for a model or endpoint, which gives 403 [G-ERRORS]. For processing in the United States the
  same key is used on `us.api.x.ai` [G-REGIONS].

## What a request can do

All rows are for Chat Completions unless the row says otherwise.

| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | yes | `tools`: a list of function tools with a JSON Schema for the arguments; "only functions are supported" on this endpoint; at most 1,000, more gives 400. The nested field names of a tool on Chat Completions are not spelled out in the reference (on Responses: `type: "function"`, `name`, optional `description`, `parameters`). The root of `parameters` must be `type: "object"` (or a `oneOf`/`anyOf` of objects), otherwise 400 naming the tool. Tool arguments always follow the schema: strict is "implicitly always `true`". | [REF-CHAT], [G-FUNC], [G-STRUCT] |
| Forced tool call | yes | `tool_choice`: `"auto"` (default), `"required"`, `"none"`, or a named function. On Chat Completions the named form is `{"type":"function","function":{"name":"..."}}`; on Responses it is `{"type":"function","name":"..."}`. "Each endpoint rejects the other's shape." The three string values are listed on the guide in its Responses section; the reference types `tool_choice` on Chat Completions only as "string or object". | [G-FUNC], [REF-CHAT] |
| Parallel tool calls | yes, on by default | `parallel_tool_calls: false` switches it off; then at most one call per answer. | [G-FUNC], [REF-CHAT] |
| Structured output with a JSON Schema | yes on Responses (`text.format`); on Chat Completions the field exists but its shape is not confirmed | Responses: `text.format = {"type":"json_schema","name":"...","schema":{...}}` (examples add `strict: true`), or `{"type":"json_object"}`, or `{"type":"text"}`. Chat Completions: the reference lists `response_format` as one of three object variants without naming them; the guide shows no Chat Completions example. Accepted, ignored and refused keywords: see "Structured output: the schema subset". | [G-STRUCT], [REF-CHAT] |
| Tools together with structured output | yes, as the guide states it (shown on Responses) | "You can combine structured outputs with tool calling", for server-side tools and for function calling. The guide names no model restriction. On Chat Completions: not confirmed. | [G-STRUCT] |
| Streaming | yes, server-sent events | `stream: true`. Data-only events (`data:` lines) each holding a `chat.completion.chunk`, ended by `data: [DONE]`. All text models stream. Usage: the reference says that with `stream_options: {"include_usage": true}` one extra chunk with usage comes before `[DONE]` and the other chunks carry `usage: null`; the streaming guide's sample chunks show a `usage` object on every chunk with a rising `completion_tokens`. Both are recorded; an adapter must accept either. | [REF-CHAT], [G-STREAM] |
| System instructions | a role | A message with role `system`. The reference has six unnamed message variants; `developer`, `tool` and `function` roles are not spelled out on the pages read. "No role-order limitation" for chat models. | [G-CHAT], [REF-CHAT], [G-MODELS] |
| Listing models | yes, two shapes | `GET /v1/models` (OpenAI-shaped, richer) and `GET /v1/language-models` (own shape). See "Model list endpoints". | [REF-MODELS] |

### Structured output: the schema subset

As [G-STRUCT] gives it (for `text.format` on Responses and for tool `parameters`). "When using supported schema
features, the response is guaranteed to match your schema." Draft 2020-12 is preferred; Draft-07 is accepted.

- Enforced: `type`, `enum`, `const`; `anyOf`, `oneOf`, `allOf`; `$ref` with `$defs`, also recursive; for strings
  `minLength`, `maxLength`, `pattern`, `format` (`date`, `time`, `date-time`, `duration`, `email`, `hostname`,
  `ipv4`, `ipv6`, `uuid`, `uri`, `uri-reference`, `uri-template`, `json-pointer`, `relative-json-pointer`; syntax
  only); for numbers `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`; for objects `properties`,
  `required`, `additionalProperties`, `patternProperties`, `propertyNames`, `unevaluatedProperties`,
  `minProperties`, `maxProperties`; for arrays `items`, `prefixItems`, `unevaluatedItems`, `minItems`, `maxItems`.
- Accepted but not enforced (the answer may break them; the library's own schema check still matters): `not`,
  `if`/`then`/`else`, `$ref` to an external address, other `format` values, `multipleOf`, `dependentRequired`,
  `dependentSchemas`, `uniqueItems`, `contains`, `minContains`, `maxContains`; some `pattern` forms (lookbehind,
  some inline modifiers, complex patterns). `oneOf` may accept a value that matches more than one branch.
- Refused with 400: `enum`, `anyOf`, `oneOf` or `allOf` with no entries; a property whose schema is `false`;
  `items` as a list (use `prefixItems`); a recursive `$ref` through `definitions` (use `$defs`); an invalid
  `pattern`, or one with backreferences, Unicode property escapes or word boundaries; tool `parameters` whose root
  is nullable or not an object.
- `additionalProperties` defaults to `false` for an object that declares `properties`.
- Limits on schema size or nesting: none stated. Refusals: not discussed on the page.
- In a stream the JSON text arrives in pieces and is parsed when complete.

### Model list endpoints

[REF-MODELS]. Paging is not documented on either.

- `GET /v1/models` and `GET /v1/models/{model_id}`: `{"object":"list","data":[...]}`; each model has `id`,
  `aliases` (names usable in `model`), `created`, `object` ("model"), `owned_by`, `context_length` (maximum context
  in tokens, may be null), `capabilities` (only `reasoning_effort`: the accepted values, and
  `default_reasoning_effort`), and prices.
- `GET /v1/language-models` and `GET /v1/language-models/{model_id}`: `{"models":[...]}`; each has `id`,
  `aliases`, `created`, `object`, `owned_by`, `fingerprint`, `version`, `input_modalities`, `output_modalities`,
  prices, and optional `capabilities` (the same reasoning fields). **No context length on this one.**
- Neither says whether a model supports function calling or structured output.
- `GET https://us.api.x.ai/v1/models` gives the list for the US address [G-REGIONS].
- Image and video model lists exist on their own paths; not relevant here.

### Server-side tools a neutral client must not enable by accident

- Server-side ("built-in") tools run on xAI's servers and are billed per call next to tokens: `web_search`,
  `x_search`, `code_interpreter` (also written `code_execution`), image generation, collections search; MCP is
  named on the comparison page [G-TOOLS], [G-COMPARE]. They are requested by listing them in `tools` on the
  Responses API; every example lists them explicitly [G-TOOLS], [G-WEB]. [G-COMPARE] says Chat Completions has
  function calling only.
- On the US address these tools may process data outside the United States [G-REGIONS].
- **`search_parameters` on Chat Completions.** The reference still lists a request field `search_parameters`
  (`mode`, `sources`, `from_date`, `to_date`, `max_search_results`, `return_citations`). Its `mode` is described as
  `off`, `on` "(default)" or `auto`; with no `sources` the model searches the web and X. What happens when the
  field is absent is not stated, and no page read says the field is deprecated [REF-CHAT]. The models page says a
  model has no knowledge of current events without the search tools [G-MODELS], which reads as: no search unless
  asked. The adapter must never send `search_parameters` or `web_search_options`.
- Related fields in an answer: `citations`, `usage.num_sources_used`, `usage.server_side_tool_usage_details`,
  `output_files` [REF-CHAT]. An adapter ignores them.

## Limits and shapes

- Required fields a neutral request may lack: none. `model` and `messages` are required [REF-CHAT].
- Maximum output tokens: `max_completion_tokens`. It limits **visible output only**, not reasoning or function-call
  tokens, and "Defaults to 128,000 when unset". `max_tokens` is marked "[DEPRECATED]" in favour of it [REF-CHAT].
  ([G-COMPARE] still maps `max_tokens` to Responses' `max_output_tokens`.)
- Temperature: 0 to 2, but "Values above 1 are sampled at 1." `top_p`: up to 1, capped at the model default (0.95)
  [REF-CHAT]. So the effective range is 0 to 1.
- Fields refused or ignored [REF-CHAT], [G-REASON]:

  | Field | On Chat Completions | On Responses |
  |---|---|---|
  | `stop` (up to 4) | not supported by reasoning models; 400 | accepted and ignored |
  | `presence_penalty` | "not supported by current Grok models"; 400 (the reference says: not by `grok-3` and reasoning models) | marked not supported; accepted and ignored |
  | `frequency_penalty` | same; 400 | same |
  | `reasoning_effort` | values and default depend on the model; `"none"` only on `grok-4.3`, on the other models 400 | `reasoning.effort`; `reasoning_effort` is an alias |
  | `logprobs`, `top_logprobs` | silently ignored on `grok-4.20` and newer | same |
  | `logit_bias` | unsupported | not listed |
  | `n` | accepted; the page advises 1 | not listed |
  | `seed` | best effort | not confirmed |

  `reasoning_effort` values [G-REASON]: `low` and `medium` on all listed models; `high` on `grok-4.5` and later
  (default on `grok-4.5`, `grok-4.6`, `grok-4.7`); `xhigh` on `grok-4.6` and later; default `low` on `grok-4.3`.
  The accepted values per model can be read from `capabilities.reasoning_effort` in the model list [REF-MODELS].
  The adapter should send none of `stop`, the two penalties, `logit_bias`, `n` or `reasoning_effort` unless asked.
- Other own fields, none of which a neutral client sends: `deferred`, `search_parameters`, `web_search_options`,
  `guidelines`, `prompt_cache_key`, `safety_identifier` (and the older `user`), `service_tier` (`default`,
  `priority`, `fast`) [REF-CHAT].
- Tool name rules: only "Unique identifier for the tool" [G-FUNC]. Allowed characters and length: not confirmed.
- Model name shape [G-MODELS]: `<name>` is the latest stable version, `<name>-latest` the newest, `<name>-<date>`
  a fixed release. Text models listed today: `grok-4.7`, `grok-4.6`, `grok-4.5` (500k context), `grok-4.3`,
  `grok-4.20-0309-reasoning`, `grok-4.20-0309-non-reasoning`, `grok-4.20-multi-agent-0309` (1M context). The
  reference's own example uses `"model": "latest"` [REF-CHAT].
- The shape of a tool call in an answer: on Chat Completions `choices[].message.tool_calls` is a list or null; the
  reference gives no inner shape [REF-CHAT]. The guide's SDK examples read `id`, `function.name` and
  `function.arguments` from each call, and on Responses `arguments` is a string holding JSON [G-FUNC]. The exact
  JSON of a Chat Completions tool call is not confirmed on an xAI page; the OpenAI shape is the assumption, to be
  checked in the hand-run live check.
- The shape of a tool result in a request: not shown for Chat Completions on the pages read (Responses uses a
  `function_call_output` item with `call_id` and `output`; the SDK takes a `tool_call_id`) [G-FUNC]. Not confirmed.
- Tool calls in a stream: "the function call is returned in whole in a single chunk, not streamed across chunks"
  [G-FUNC]. An adapter that joins argument pieces per index still works; one that expects pieces must not.
- The answer [REF-CHAT]: `id`, `object` ("chat.completion"), `created`, `model`, `choices[]` (`index`, `message`,
  `finish_reason`, `logprobs`), `usage`, `system_fingerprint`, `service_tier`, `citations`, `output_files`.
  `message` has `role` ("assistant"), `content`, `reasoning_content` (the reasoning trace), `refusal`, `tool_calls`.
- Finish reasons [REF-CHAT]: `stop` (a model-defined or caller-given stop sequence); `length` (the token limit);
  `end_turn` or `null` "in streaming mode when the chunk is not the last". **`tool_calls` and `content_filter` are
  not named anywhere on the reference page.** What finish reason an answer with tool calls carries: not confirmed;
  the adapter should decide "tool call" from the presence of `tool_calls`, not from the finish reason.
- Refusal: `message.refusal` is "The reason given by model if the model is unable to generate a response", null
  otherwise [REF-CHAT]. It arrives inside a normal answer. An HTTP status for refused content: not confirmed.
- Usage [REF-CHAT]: `usage.prompt_tokens`, `completion_tokens`, `total_tokens`; `prompt_tokens_details`
  (`text_tokens`, `audio_tokens`, `image_tokens`, `cached_tokens`); `completion_tokens_details`
  (`reasoning_tokens`, `audio_tokens`, `accepted_prediction_tokens`, `rejected_prediction_tokens`);
  `cost_in_usd_ticks` (10,000,000,000 ticks per dollar); `num_sources_used`; `server_side_tool_usage_details`.
  Whether `completion_tokens` includes the reasoning tokens is not stated. The reference's own example has
  `prompt_tokens` 32, `completion_tokens` 9, `reasoning_tokens` 94 and `total_tokens` 135, which adds up only if
  reasoning tokens are **outside** `completion_tokens`; that differs from the OpenAI reading and is taken from an
  example, not from a sentence. On Responses: `input_tokens`, `output_tokens`,
  `output_tokens_details.reasoning_tokens`, `input_tokens_details.cached_tokens` [REF-RESP]. Reasoning tokens are
  billed [G-REASON].
- A stream [G-STREAM], [REF-CHAT]: chunks have `id`, `object` ("chat.completion.chunk"), `created`, `model`,
  `choices`, `usage`, `system_fingerprint`; `delta` carries `role`, `content` and, before the text,
  `reasoning_content`. How an error shows inside a running stream: not confirmed.
- Error body shape: **not documented.** [G-ERRORS] says only that errors come with a self-explanatory message; no
  JSON example and no field names. Not confirmed.
- Statuses [G-ERRORS], [G-RATE], [G-REGIONS], [G-FUNC], [G-REASON]:

  | Status | Meaning as the page gives it | Retry |
  |---|---|---|
  | 400 | invalid body argument or URL parameter; **also "an incorrect API key"**; more than 1,000 tools; a refused schema; a refused field (`stop`, penalties, `reasoning_effort: "none"`) | no |
  | 401 | missing `Authorization` header or invalid token | no |
  | 403 | the key or team lacks permission, or is blocked | no |
  | 404 | the model in the body was not found (also a model not served on the US address), or a wrong path | no |
  | 405 | wrong HTTP method | no |
  | 415 | empty body or missing `Content-Type: application/json` | no |
  | 422 | a body field has an invalid format | no |
  | 429 | rate limit reached | yes, with backoff |
  | 202 | only on the deferred-completion fetch: still pending | n/a |

  5xx statuses are not listed. A status for "no credit left" is not listed. Because 400 can mean bad credentials,
  an adapter cannot map 400 to "rejected" on the status alone; without a documented error body there is nothing
  documented to tell the two apart.
- Rate limits [G-RATE]: per team and per model, in requests per second and tokens per minute (all tokens count,
  also reasoning and cached ones); tiers 0 to 4 by spend, plus Enterprise. "Exceeding any limit returns a
  `429 Too Many Requests` error." Advice: exponential backoff (the examples wait `2 ** attempt` seconds, five
  tries). **No rate-limit headers and no `Retry-After` header are documented.** Not confirmed that none is sent.

## Data

- Where requests are processed, as the provider states it: on the default address no place is promised: "SpaceXSI
  may route requests between regions for capacity or reliability, so the processing location is not guaranteed."
  [G-REGIONS]. The security page says the same of `https://api.x.ai`: it "does not guarantee a processing region"
  [G-SECURITY].
- United States: on `https://us.api.x.ai/v1` the request handling by the API servers, the inference, the safety
  moderation, and the storage of retained request metadata, inputs and outputs happen in the United States. Not
  covered: Files, Collections, server-side tools, and the network path to xAI. "A regional endpoint is not, by
  itself, a comprehensive data-residency guarantee."; for a contractual need the page points to xAI's sales
  contact [G-REGIONS].
- Europe: **no EU address and no EU processing option is documented** on the regions page or the security page
  [G-REGIONS], [G-SECURITY]. The only EU mention read is a release note that a model is "available in the API
  console for EU users" [G-RELEASE], which says nothing about where processing happens. A host
  `eu-west-1.api.x.ai` appears as a component name on xAI's status site (search summary of `status.x.ai`; the
  site answered 403 when opened) and no documentation page read today gives it as an address to use or says what
  it guarantees.
- Training: off by default. "SpaceXSI never trains on your API inputs or outputs without your explicit
  permission." [G-SECURITY]
- Kept by default: all API requests and responses are stored, encrypted at rest, for 30 days for abuse auditing
  and then deleted automatically [G-SECURITY]. This applies to Chat Completions too, although that API keeps no
  conversation state ("The Chat Completions API is stateless" [G-CHAT]).
- The option not to store requests:
  - On Chat Completions there is **no request field** for it; `store` is not a field of that endpoint [REF-CHAT].
  - On Responses: `store` (default `true`; stored 30 days, then deleted); `store: false` switches it off
    [G-COMPARE], [REF-RESP]. `DELETE /v1/responses/{id}` removes one [REF-RESP].
  - Zero Data Retention (ZDR): a setting of the whole team, switched on by a team admin in the Console (after
    deleting the team's Files and Collections), not per key and not per request. With it, prompts and outputs are
    not written to disk and the 30-day audit storage does not apply. It switches off the stateful Responses API,
    Files, Collections, the Batch API and deferred completions. xAI does not recommend it for most customers.
    Every answer carries `x-zero-data-retention: true` or `false`, so a client can check it [G-SECURITY]. It
    applies on the US address too [G-REGIONS].
- Compliance statements read: SOC 2 Type 2; the page's GDPR question is answered only with SOC 2 [G-SECURITY].

## Not confirmed

- The shape of `response_format` on Chat Completions (`json_schema` with `name`, `schema`, `strict`): the
  reference lists three unnamed variants [REF-CHAT]; [G-STRUCT] shows only `text.format` of the Responses API.
- Tools together with `response_format` on Chat Completions: [G-STRUCT] states the combination in general and
  shows it on Responses only. The earlier brief's restriction to "supported Grok 4 family models" is not on the
  page today.
- The JSON of a tool call and of a tool result message on Chat Completions, and the nested field names of a tool
  definition there: not in [REF-CHAT], [G-FUNC] or [G-TOOLS-ADV], which show the Responses and SDK forms.
- The finish reason of an answer that holds tool calls, and whether `content_filter` exists: [REF-CHAT] names only
  `stop`, `length`, `end_turn` and `null`.
- The `tool_choice` string values on Chat Completions specifically: listed in [G-FUNC] for Responses; only the
  forced form is given for Chat Completions.
- Whether function calling through Chat Completions works on `grok-4.7`: [G-GROK47] shows Responses only and does
  not say.
- The error body: no shape, field names or error codes on [G-ERRORS] or [REF-CHAT]. How to tell a bad key (400 or
  401) from another 400. The statuses for a server error, an overloaded model, no credit, and refused content.
- Rate-limit headers, a `Retry-After` header and a request-id header: none documented on [G-RATE], [G-ERRORS],
  [REF-OVERVIEW].
- How an error is delivered inside a stream that already started: not on [G-STREAM].
- Usage in a stream: [REF-CHAT] (one extra chunk, only with `include_usage`) and the samples in [G-STREAM] (usage
  on every chunk) disagree.
- Whether `completion_tokens` includes reasoning tokens: only an example in [REF-CHAT] suggests it does not.
- What Chat Completions does when `search_parameters` is absent, and whether that field is deprecated: [REF-CHAT]
  describes `mode: on` as "(default)" and says no more; [G-TOOLS] and [G-WEB] do not mention the field.
- Whether Chat Completions refuses a server-side tool type in `tools`: [REF-CHAT] says only functions are
  supported; a status is not given.
- The `developer` and `tool` roles on Chat Completions: the six message variants in [REF-CHAT] are unnamed.
- Tool name rules (characters, length).
- An EU address or EU processing: not on [G-REGIONS] or [G-SECURITY]. `eu-west-1.api.x.ai` is known only from a
  search summary of the status site. xAI's data processing addendum
  (https://x.ai/legal/data-processing-addendum) and https://status.x.ai/ both answered 403 and were not read.
- An end date for Chat Completions: none on the pages read.
- The request-side default of `store` on [REF-RESP] (the reference states none; [G-COMPARE] says `true`).
