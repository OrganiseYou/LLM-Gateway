# Mistral

Read on 2026-11-27. Hosted text chat API: yes.

Every fact below was read on 2026-11-27 on the page linked next to it. Only Mistral's own pages were used
(`docs.mistral.ai`, `help.mistral.ai`, `mistral.ai`). The API reference page is long and was read in parts; it gives
types and enum values but almost no prose, so where it is silent the cell says "not confirmed". Model names are
written as the pages give them. All examples are synthetic unless marked "from the page".

Short names for the pages:

- [REF-CHAT]: https://docs.mistral.ai/api/endpoint/chat
- [REF-MODELS]: https://docs.mistral.ai/api/endpoint/models
- [REF-INDEX]: https://docs.mistral.ai/api
- [G-CHAT]: https://docs.mistral.ai/inference/conversations/chat-completion
- [G-FUNC]: https://docs.mistral.ai/inference/conversations/function-calling
- [G-STRUCT]: https://docs.mistral.ai/inference/conversations/structured-output
- [G-CUSTOM]: https://docs.mistral.ai/inference/conversations/structured-output/custom
- [G-JSON]: https://docs.mistral.ai/inference/conversations/structured-output/json-mode
- [G-REASON]: https://docs.mistral.ai/inference/conversations/reasoning
- [G-RESPONSES]: https://docs.mistral.ai/inference/conversations/responses
- [G-LIMITS]: https://docs.mistral.ai/resources/known-limitations
- [G-ERRORS]: https://docs.mistral.ai/resources/error-glossary
- [G-MIGRATE]: https://docs.mistral.ai/resources/migration-guides
- [G-REGION]: https://docs.mistral.ai/inference/regional-inference
- [G-DEPLOY]: https://docs.mistral.ai/inference/deployment
- [G-CLOUD]: https://docs.mistral.ai/inference/deployment/cloud-deployments
- [G-VLLM]: https://docs.mistral.ai/models/deployment/local-deployment/vllm
- [G-MODELS]: https://docs.mistral.ai/models/overview
- [G-QUICK]: https://docs.mistral.ai/inference/quickstarts/send-your-first-request
- [G-KEY]: https://docs.mistral.ai/getting-started/quickstarts/studio/activate-and-generate-api-key
- [G-ZDR]: https://docs.mistral.ai/admin/monitor-comply/zero-data-retention.md
- [H-WHERE]: https://help.mistral.ai/en/articles/347629-where-do-you-store-my-data-or-my-organization-s-data
- [H-ZDR]: https://help.mistral.ai/en/articles/347612-can-i-activate-zero-data-retention-zdr
- [H-TRAIN]: https://help.mistral.ai/en/articles/347617-do-you-use-my-user-data-to-train-your-artificial-intelligence-models
- [H-OPTOUT]: https://help.mistral.ai/en/articles/455207-can-i-opt-out-of-my-input-or-output-data-being-used-for-training
- [H-RATE]: https://help.mistral.ai/en/articles/698531-why-am-i-hitting-api-rate-limits-and-how-do-i-increase-them
- [M-INFER]: https://mistral.ai/cloud/inference/

## Which chat APIs Mistral has, and what it means for this library

Mistral documents four ways to chat. The first is the one this file describes.

1. **Native Chat Completions**, `POST https://api.mistral.ai/v1/chat/completions`: "the native Mistral format",
   used by Mistral's own SDKs [G-CHAT], [REF-CHAT]. Stateless: the caller sends the whole transcript.
2. **An OpenAI-compatible endpoint**, `POST https://api.mistral.ai/openai/v1/chat/completions`, in the OpenAI Chat
   Completions request and response format. It is in public preview: "Its behavior, supported parameters, and error
   responses can change during the preview." [G-CHAT]. No list of supported or unsupported parameters is given
   [G-CHAT]. Its errors are wrapped in an `error` object, unlike the native endpoint [G-ERRORS].
3. **A Responses API**, `POST https://api.mistral.ai/v1/responses`, in the OpenAI Responses format, described as
   stateless [G-RESPONSES]. Its status (preview or general) is not stated on the page.
4. **Agents and Conversations**, listed under "Beta" in the reference index [REF-INDEX]. They keep state at the
   provider; they are outside zero data retention [G-ZDR] and are not available on regional endpoints [G-REGION].

Two Mistral pages disagree about the OpenAI-compatible address: [G-CHAT] gives `https://api.mistral.ai/openai/v1`;
[G-MIGRATE] gives `https://api.mistral.ai/v1` as the base URL for OpenAI-compatible clients and says the API
"follows the same Chat Completions structure as the OpenAI API".

For the plan: the native endpoint is close to the OpenAI format but differs in the places listed in "Differences from
the plain OpenAI chat-completions format" below. The preview endpoint (2) would let the OpenAI-compatible adapter
reach Mistral with preset data only, but Mistral says it may change and does not list what it supports.

## Reaching it

- Base URL and the chat path: `https://api.mistral.ai/v1`, chat at `POST /chat/completions`. The reference's and the
  guides' examples post to `https://api.mistral.ai/v1/chat/completions` [REF-CHAT], [G-CHAT], [G-QUICK]. No page read
  states the base URL as a sentence; it is taken from the examples.
- Regional addresses [G-REGION]: `api.mistral.ai` (global, no surcharge, no committed location), `api.eu.mistral.ai`
  (data centres in EU and EFTA countries), `api.us.mistral.ai` (data centres in the US). Regional requests cost 1.1
  times the list price. Models differ per region; the page gives no list and advises calling the model list on the
  regional address. On regional addresses function calling is the only tool kind listed as supported, and "Stateful
  features, including Agents, Batch, and the Files API, are not available on regional endpoints." Whether one API key
  works on all three addresses is not stated (the examples use the same variable).
- Hosted by the vendor, run by the customer, or both: **both**.
  - Vendor: the hosted API above.
  - Cloud marketplaces [G-DEPLOY], [G-CLOUD]: Azure AI (serverless pay-as-you-go or real-time endpoints), Amazon
    Bedrock, Google Cloud Vertex AI Model Garden, Snowflake Cortex (through its `COMPLETE` function), IBM watsonx,
    Outscale. The page does not describe the request format, address or sign-in on each; those belong to the cloud
    provider and are not confirmed here.
  - Customer: open-weight models are under Apache 2.0 and "can be deployed on compatible hardware"; the commercial
    models are not open weights and are reached through a cloud provider or Mistral Compute [G-DEPLOY]. Documented
    ways to run them: vLLM, TensorRT-LLM, TGI, SkyPilot, Cerebrium, Cloudflare Workers AI [G-DEPLOY]. With vLLM the
    server speaks the OpenAI protocol at `http://localhost:8000/v1/chat/completions`, started with
    `--tokenizer_mode mistral --config_format mistral --load_format mistral`; the weights come from Hugging Face with
    a read token [G-VLLM]. Tool-calling flags for vLLM are not on that page.
- API format: both. Its own native format (the fuller and the stable one) and an OpenAI-compatible endpoint in public
  preview (see the section above).
- API versions or dated headers a request must send: none found. `v1` is in the path. The reference index has no
  versioning statement [REF-INDEX].
- Headers a request sends: `Authorization: Bearer <key>`, `Content-Type: application/json`; the examples also send
  `Accept: application/json` [G-CUSTOM], [G-QUICK].

## Signing in

- Method: HTTP bearer, `Authorization: Bearer <API key>` [REF-CHAT], [G-QUICK], [G-ERRORS]. No organisation,
  workspace or project header is documented on the pages read.
- What the customer must create at the provider, in plain words: a Mistral account on a plan with API access; then in
  the Studio console (`console.mistral.ai`) under "API Keys" a new key with a name and an expiry date. The key is
  shown once [G-KEY]. The key also has a "connector access scope" setting, which does not concern plain chat [G-KEY].
  Billing: a free mode with the lowest limits exists, and pay-as-you-go raises them [H-RATE]. For processing in the
  EU only, the customer uses the address `api.eu.mistral.ai` [G-REGION]; whether that needs anything switched on in
  the account is not stated.
- A key has an expiry date [G-KEY], so "credentials rejected" can appear on a key that used to work.

## What a request can do

All rows are for the native `POST /v1/chat/completions`.

| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | yes, per model | `tools`: a list of `{"type":"function","function":{"name","description","parameters","strict"}}`. `name` and `parameters` are required; `description` defaults to `""`, `strict` to `false`. `parameters` is a JSON Schema object. Other tool types exist (`image_generation`, `document_library`, `connector`) and are not used by this library. At most 128 tools per request. Models: the guide gives a non-exhaustive list (Mistral Large 4, Medium 3.5, Small 4, Ministral 3, Devstral, Codestral and others); the model list carries a `function_calling` flag per model. | [REF-CHAT], [G-FUNC], [G-LIMITS], [REF-MODELS] |
| Forced tool call | yes | `tool_choice`: `"auto"` (default), `"none"`, `"any"`, `"required"`, or a named function `{"type":"function","function":{"name":"..."}}`. The reference lists all five; the guide documents only `auto`, `any`, `none`. `any` "forces tool use"; it "does not guarantee which tool is selected". The meaning of `required` and of the named form is not described in prose on the pages read (a search summary of the reference says "any or required" both mean the model must call one or more tools; not read as a sentence). | [REF-CHAT], [G-FUNC], [G-LIMITS] |
| Parallel tool calls | yes, on by default | `parallel_tool_calls` (boolean, default `true`); `false` "forces single tool calling". Calls may come back in any order. | [REF-CHAT], [G-FUNC], [G-LIMITS] |
| Structured output with a JSON Schema | yes | `response_format`: `{"type":"json_schema","json_schema":{"name","description","schema","strict"}}`; `name` required, `strict` default `false`. The guide's raw request uses the key `schema`; the reference lists the same field as `schema_definition` (required). Models: all "except `codestral-mamba`". **No list of accepted or refused schema keywords is published**; the guide's example uses `type`, `properties`, `items`, `required`, `title`, `additionalProperties: false`. Also `{"type":"json_object"}` (valid JSON, no schema) and `{"type":"text"}`. | [REF-CHAT], [G-CUSTOM], [G-JSON], [G-STRUCT] |
| Tools together with structured output | not confirmed | No page read says that `tools` and `response_format` can or cannot be sent together. | [REF-CHAT], [G-FUNC], [G-CUSTOM], [G-STRUCT], [G-LIMITS] |
| Streaming (and whether usage arrives in the stream) | yes, server-sent events | `stream: true`. The 200 answer is `text/event-stream`; each event's `data` is a `chat.completion.chunk`; "the stream terminated by a data: [DONE] message". Usage: the guide's sample shows `usage: null` on every chunk and a filled `usage` on the last chunk (the one with the finish reason) with no option sent; the limitations page says `stream_options.include_usage` "must be explicitly set"; the reference lists no `stream_options` field. The three pages disagree. A stream is closed after 10 minutes without activity. | [REF-CHAT], [G-CHAT], [G-LIMITS] |
| System instructions (a role, or a separate field) | a role | A message with role `system`; `content` is a string or a list of chunks. | [REF-CHAT], [G-CHAT] |
| Listing models | yes | `GET /v1/models` (optional query `provider`, `model`) and `GET /v1/models/{model_id}`. Answer `{"object":"list","data":[...]}` per the schema. Each model: `id`, `object` ("model"), `created`, `owned_by`, `type` ("base" or "fine-tuned"), `name`, `description`, `max_context_length` (integer, default 32768), `aliases`, `deprecation`, `deprecation_replacement_model`, `default_model_temperature`, and `capabilities` with boolean flags `completion_chat`, `completion_fim`, `function_calling`, `fine_tuning`, `vision`, `reasoning`, `classification`, `moderation`, `ocr`, `audio`, `audio_speech`, `audio_transcription`, `audio_transcription_realtime`, `unified_resources`. No flag for structured output, parallel calls or streaming. The page's own example differs from its schema: it shows a bare list and a key `TYPE`. No paging is documented. | [REF-MODELS] |

## Limits and shapes

- Required fields a neutral request may lack: none. Only `model` and `messages` are required [REF-CHAT]. There is no
  mandatory maximum-tokens field.
- Maximum output tokens: `max_tokens` (integer or null). Prompt tokens plus `max_tokens` may not exceed the model's
  context length [REF-CHAT]. There is also `min_tokens`. No `max_completion_tokens` field is listed [REF-CHAT].
- Temperature range: **no minimum or maximum is published.** The reference says only "we recommend between 0.0 and
  0.7"; the default differs per model and is given in the model list as `default_model_temperature` [REF-CHAT],
  [REF-MODELS]. `top_p`: use it or `temperature`, not both; with greedy sampling `top_p` must be 1 (error code 3054)
  [REF-CHAT], [G-ERRORS].
- Other request fields [REF-CHAT]:
  - `random_seed` (integer): the seed; makes sampling deterministic. This is the seed field; no `seed` field is
    listed.
  - `safe_prompt` (boolean, default `false`): "Whether to inject a safety prompt before all conversations."
  - `prompt_mode`: one value, `"reasoning"`. It belongs to the older native-reasoning models; Mistral now steers
    reasoning with `reasoning_effort` (search summary of Mistral's deprecated native-reasoning page; [G-REASON]
    itself does not mention `prompt_mode`).
  - `reasoning_effort`: enum `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, `max`; each model accepts only
    some of them (Mistral Small 4 and Medium 3.5: `high`, `none`; Mistral Large 4: `low`, `medium`, `high`, default
    `high`, and it does not accept `none`) [G-REASON].
  - `stop` (string or list), `n`, `presence_penalty`, `frequency_penalty`, `repetition_penalty`, `top_k`, `logprobs`
    and related fields, `prediction`, `prompt_cache_key`, `service_tier` (`auto`, `standard_only`), `guardrails`.
    No numeric bounds are shown for any of them.
  - A top-level `metadata` or `stream_options` field is not listed in the reference.
- Tool name rules: not published (the reference gives `function.name` as a required string with no pattern or
  length) [REF-CHAT]. At most 128 tools per request; tool descriptions count as prompt tokens [G-LIMITS].
- Model name shape: a free string. Dated names such as `mistral-large-2512` and `mistral-small-2603`, aliases ending
  in `-latest` such as `mistral-large-latest`, and prefixes `open-` and `labs-` all occur [G-MODELS], [G-REASON].
  The model list gives `aliases` per model [REF-MODELS].
- Message roles in a request: `system`, `user`, `assistant`, `tool` [REF-CHAT], [G-CHAT]. No `developer` role is
  listed.
  - `system`: `content` a string or a list of chunks, required.
  - `user`: `content` a string, a list of chunks, or null.
  - `assistant`: `content` optional (string, list of chunks, or null); `tool_calls` (list or null); `prefix`
    (boolean, default `false`): on a last assistant message it makes the answer start with that text.
  - `tool`: `content` required; `tool_call_id` (string or null) and `name` (string or null) are **both optional in
    the schema**. Every example in the guide sends both [G-FUNC]. Whether the API refuses a tool message without
    `name` or without `tool_call_id`: not confirmed.
- Content chunk types [REF-CHAT]: `text`, `image_url`, `document_url`, `reference`, `file`, `thinking`,
  `input_audio`, `resource`, `resource_link`.
- **An answer's `content` is not always a string.** `choices[].message.content` is a string, null, or a list of
  chunks [REF-CHAT], [G-CHAT]. With reasoning switched on the answer is a list: a `thinking` chunk whose `thinking`
  field is itself a list of text chunks, followed by a `text` chunk with the answer [G-REASON]:

  ```json
  {"role": "assistant", "content": [
    {"type": "thinking", "thinking": [{"type": "text", "text": "synthetic reasoning"}]},
    {"type": "text", "text": "synthetic answer"}
  ]}
  ```

  The reference also lists `closed` and `signature` on a thinking chunk [REF-CHAT]; the guide's example does not
  show them. With `reasoning_effort: "none"` the content is a plain string [G-REASON]. Mistral advises sending the
  whole assistant message back in later turns, thinking chunk included; dropping it "significantly degrades output
  quality" [G-REASON]. `reference` chunks (citations) can also appear in an answer [REF-CHAT], [G-CHAT].
- The shape of a tool call in an answer [REF-CHAT], [G-FUNC]. Tool calls sit in `choices[].message.tool_calls`:

  ```json
  {"id": "AbCdEf123", "type": "function", "index": 0,
   "function": {"name": "lookup_order", "arguments": "{\"order_id\": \"A-1\"}"}}
  ```

  - `function.arguments`: the reference types it as **a map or a string** ("map<any> | string", required). The
    guide's example answer shows a string holding JSON, parsed by the caller [G-FUNC]. An adapter must accept both.
  - `id`: a string, optional in the schema. The guide's example id is nine letters and digits (`D681PevKs`, from the
    page). **No page read states a rule for the id's length or characters**, neither for ids the API returns nor for
    ids a caller sends back.
  - `type`: `"function"`. `index`: integer, default 0.
- The shape of a tool result in a request [G-FUNC]: the assistant message with its `tool_calls` is sent back,
  followed by one message per call:

  ```json
  {"role": "tool", "name": "lookup_order", "tool_call_id": "AbCdEf123", "content": "{\"status\": \"shipped\"}"}
  ```

  `content` is a string in the guide's examples. The model may answer a tool result with another tool call, so the
  caller loops [G-FUNC].
- The answer [REF-CHAT]: `id`, `object` ("chat.completion"), `created`, `model`, `choices[]` (`index`, `message`,
  `finish_reason`, optional `logprobs`), `usage`; all six top-level fields are required.
- Finish reasons [REF-CHAT]: `stop`, `length`, `model_length`, `error`, `tool_calls` in a plain answer; `stop`,
  `length`, `error`, `tool_calls` in a stream chunk. The reference gives the values without a description. From the
  guide: generation ends at a natural end, at a stop string, or "when the maximum number of tokens is reached"
  [G-CHAT]. What separates `length` from `model_length`, and when `error` is used, is not confirmed. No
  `content_filter` value is listed.
- Usage [REF-CHAT]: `usage.prompt_tokens`, `usage.completion_tokens`, `usage.total_tokens` (integers, default 0);
  optional `prompt_audio_seconds`, `prompt_tokens_details` (`cached_tokens`, `audio_tokens`) and `service_tier`
  ("standard" or "priority"). No `completion_tokens_details` and no reasoning-token count is listed.
- A stream [REF-CHAT], [G-CHAT]: each chunk has `id`, `object` ("chat.completion.chunk"), `created`, `model`,
  `choices[]` with `index`, `delta`, `finish_reason` (null until the last chunk), and `usage`. `delta` may hold
  `role`, `content`, `tool_calls` (and `tool_call_id`, `index`, `metadata`). In the guide's sample the first chunk
  carries `"role": "assistant"` with empty content, and the last chunk carries both the last text and the finish
  reason and usage (no separate usage-only chunk with empty `choices`).
  - `delta.content` is a string **or a list of chunks**. With reasoning: first lists holding a thinking chunk, then
    one list holding a closing thinking chunk and the first text chunk, then plain strings [G-REASON]. Raw JSON of
    these deltas is not shown on the page.
  - A tool call in a delta has the same `ToolCall` shape as in a plain answer (`id`, `type`, `index`,
    `function.name`, `function.arguments` as map or string) [REF-CHAT]. Whether arguments arrive whole or in pieces
    to be joined per `index`: not confirmed; no page read shows a streamed tool call.
  - How an error shows inside a running stream: not confirmed ([G-ERRORS] does not cover it). The finish reason
    `error` exists.
- Error body shape [G-ERRORS]. **Three shapes on the native endpoint**:
  - most errors: `{"object": "error", "message": "...", "type": "invalid_model", "param": null, "code": "1500",
    "raw_status_code": 400}` (synthetic, with the documented fields). `type` is a word; `code` is a four-digit
    number sent as a string.
  - 401: `{"detail": "Invalid API Key"}`, with no `code` and no `type`.
  - 422: `{"detail": [ ... ]}`, one item per invalid field, each with `loc` (the field) and `msg`.
  - the Agents and Conversations APIs use `"object": "Error"`, `"type": "invalid_request_error"` and a numeric
    code; the OpenAI-compatible endpoint wraps the error in an `error` object.
- Statuses and codes [G-ERRORS], [G-LIMITS], [H-RATE]:

  | Status | Meaning | `code` / `type` | Retry |
  |---|---|---|---|
  | 400 | malformed request or unsupported value | 1500 `invalid_model` (**unknown model**, or `model` missing; message "Invalid model: <name>"); 3051 `invalid_request_invalid_args`; 3054 `invalid_request_greedy_sampling`; 3059 `invalid_request_prompt_too_long` (prompt over the context length) | no |
  | 401 | key missing, invalid, expired or revoked (**bad credentials**) | none; body `{"detail": ...}` | no |
  | 402 | a billing condition blocks the request | 2300 organisation monthly spending limit; 2301 workspace monthly spending limit; 2302 payment failed; 2303 API budget exhausted; 2304; 2308 out of credits | no |
  | 403 | no permission, for example a model not in the customer's tier, or a workspace restriction | not named | no |
  | 404 | wrong path or id. "An unknown model returns 400, not 404." | not named | no |
  | 422 | valid JSON, but a field is missing or invalid | none; body `{"detail": [...]}` | no |
  | 429 | **rate limit** | 1300 `rate_limited` | yes, unless it is a monthly limit or a limit of 0 |
  | 429 | the model has no capacity (not the customer's limit) | 3505 or 3801, `backend_out_of_capacity` | yes, or another model |
  | 500 | server error | 1000 `internal_server_error` | yes, after a short wait |
  | 502 | bad answer from an upstream service | not named | yes, after 30 to 60 seconds |
  | 503 | overloaded, maintenance or no capacity | 1000, 1100 `unreachable_backend`, 3505, 3800, 3801 | yes |
  | 504 | the request took too long | not named | yes |

  So 429 alone does not say "the customer's rate limit": the adapter must read `code`. The reference page for chat
  lists only the 200 answers, no error answers [REF-CHAT].
- Rate limits [H-RATE]: requests per second, tokens per minute and tokens per month, each enforced on its own, set
  for the organisation across all its workspaces, raised by tier.
- Rate-limit headers [G-ERRORS], [G-LIMITS]: `Retry-After` (its unit and format are not stated), and the families
  `x-ratelimit-limit-*` and `x-ratelimit-remaining-*`; the only full name given is `x-ratelimit-limit-req-minute`,
  which is 0 for a model the free mode may not use. The limitations page names `X-RateLimit-Remaining`. Mistral
  advises exponential backoff with jitter for 429, 500, 502, 503 and 504.

## Differences from the plain OpenAI chat-completions format

Compared with what `docs/providers/openai.md` records for OpenAI (read the same day). Each Mistral fact has its link
above.

| Point | Mistral native `/v1/chat/completions` | OpenAI, per `docs/providers/openai.md` |
|---|---|---|
| Answer content | a string, null, or a **list of chunks** (`thinking`, `text`, `reference`, ...), also in a stream delta | a string or null |
| Reasoning | `thinking` chunks in the content, to be sent back unchanged | not in the content |
| Refusal | no `refusal` field and no `content_filter` finish reason listed | `message.refusal`, `content_filter` |
| Tool-call arguments | typed "map or string"; a string in the guide's example | a string holding JSON |
| Tool message | `name` and `tool_call_id` both optional in the schema; the guide always sends both | `tool_call_id` required; no `name` |
| Tool-call id | no rule published; the example is nine letters and digits | no rule recorded |
| `tool_choice` | `auto`, `none`, `any`, `required`, a named function | `none`, `auto`, `required`, a named function, `allowed_tools` |
| Schema field of `response_format` | `schema` in the guide, `schema_definition` in the reference | `schema` |
| Accepted schema keywords | not published | a documented subset with rules and limits |
| Maximum tokens | `max_tokens` | `max_completion_tokens` (`max_tokens` deprecated) |
| Seed | `random_seed` | not recorded |
| Extra fields | `safe_prompt`, `prompt_mode`, `prefix` on an assistant message, `guardrails`, `min_tokens` | none of these |
| System role | `system` only | `developer` or `system` |
| Temperature | no range published; 0.0 to 0.7 recommended | 0 to 2 |
| Finish reasons | `stop`, `length`, `model_length`, `error`, `tool_calls` | `stop`, `length`, `tool_calls`, `content_filter` |
| Usage in a stream | on the last content chunk in the guide's sample; pages disagree on whether `stream_options.include_usage` is needed | an extra chunk with empty `choices`, only with `stream_options.include_usage` |
| Storing | no `store` field; retention is an account setting | `store` field |
| Error body | flat object with `object`, `message`, `type`, `param`, `code`, `raw_status_code`; `{"detail": ...}` for 401 and 422 | an `error` object with `message`, `type`, `param`, `code` |
| Unknown model | 400 with code 1500 | not confirmed |
| No credit | 402 | 429 with type `insufficient_quota` |
| Model list | carries capabilities and `max_context_length` | neither |
| Stream wire format and end | server-sent events, `data: [DONE]` | the same |
| Tool definition | `{"type":"function","function":{...}}` with `strict` | the same |

## Data

- Where requests are processed, as the provider states it. The pages do not say the same thing:
  - The regional-inference page, about the default address `api.mistral.ai`: "Mistral does not commit to a specific
    inference location for requests sent to this endpoint." [G-REGION]
  - The help centre: "By default, your data is hosted in the European Economic Area (EEA)."; with the US address
    the inference requests "are processed in the United States"; and, depending on the feature, data "can be
    temporarily transferred outside of the European Union" to subprocessors listed in the Trust Center [H-WHERE].
  - The inference product page says the models are "all served by us in Europe" and that without a regional
    address "requests use the global endpoint" [M-INFER].
  - Read together: hosting (storage) is in the EEA by default, but **processing in the EU is only committed on
    `api.eu.mistral.ai`** (data centres in EU and EFTA countries), at 1.1 times the price [G-REGION]. Even there,
    "Regional inference does not make the Mistral control plane regional": account data, keys, billing and usage
    figures may be handled elsewhere [G-REGION].
- The option not to store requests: **there is no request field or header for it.** Zero data retention is an
  account setting: "available only with pay-as-you-go and only for stateless API calls" [H-ZDR], asked from
  Mistral's support with a reason, and granted or refused by Mistral [G-ZDR], [H-ZDR]. It covers chat completions
  (also FIM, embeddings, moderation, classification, OCR, speech) and not Agents, Conversations, batch files,
  libraries or `/v1/files`; it does not apply to "Labs and Public Preview models" [G-ZDR]. How long requests are
  kept without it: not confirmed (see below).
- Training: the pages differ by plan.
  - The product page: "Data sent through our API isn't used for model training." [M-INFER]
  - The help centre, free mode: "we may use your data (input and output) to train our artificial intelligence
    models", with a right to opt out. For pay-as-you-go no default is stated, only that customers "have the right
    to opt out at any time" [H-TRAIN].
  - The opt-out is a switch in the Admin panel, "Privacy", under "Data usage for improving our services"; it is
    separate from the switch for Mistral's chat product [H-OPTOUT]. It "does not apply to Labs and Public Preview
    models" [H-TRAIN]. Zero data retention and the training opt-out are separate controls [G-ZDR].
  - So "training is off by default" is not confirmed for every plan; on the free mode it is on unless switched off.

## Not confirmed

- Tools together with `response_format` in one request: no statement either way in [REF-CHAT], [G-FUNC],
  [G-CUSTOM], [G-STRUCT] or [G-LIMITS]; a search of `docs.mistral.ai` found no page that settles it.
- A rule for the tool-call id (length, characters), for ids returned and for ids sent back. Tried: [REF-CHAT]
  (type string, no pattern), [G-FUNC] (an example only), a search of `docs.mistral.ai`; a tokenizer cookbook page
  that the search named (`/cookbooks/concept-deep-dive-tokenization-tool_calling`) answered 404.
- Whether `name` and `tool_call_id` are required on a tool message in practice (optional in the schema).
- Whether tool-call arguments ever arrive as an object, and how they arrive in a stream (whole or in pieces): the
  schema allows both types and no page shows a streamed tool call.
- The accepted and refused JSON Schema keywords for `json_schema` and for a strict tool; whether
  `additionalProperties: false` is required; what `strict: false` changes.
- Which of `schema` and `schema_definition` the API expects in a raw request (guide and reference differ; the
  guide's request is the only full example).
- The meaning of `tool_choice: "required"` against `"any"` as a sentence on a page, and of the named form.
- Whether usage in a stream needs `stream_options.include_usage` ([G-CHAT] sample, [G-LIMITS] and [REF-CHAT]
  disagree).
- How an error is delivered inside a running stream; the meaning of the finish reasons `error` and `model_length`.
- A minimum and maximum for `temperature`, and bounds for `top_p`, `n` and the penalties.
- A rule for tool names (characters, length).
- The unit of `Retry-After` and the full names of the `x-ratelimit-*` headers.
- The 422 item fields beyond `loc` and `msg`; the `type` words for 401, 403, 404, 502 and 504.
- How long requests are kept without zero data retention. Tried: Mistral's privacy policy
  (https://legal.mistral.ai/terms/privacy-policy/), which returned only its title; a search summary of it mentions
  30 days, which is not a reading. Mistral's Trust Center and its list of subprocessors were not read.
- Whether one API key works on `api.eu.mistral.ai` and `api.us.mistral.ai`, which models each region serves, and
  whether structured output is available there.
- Which parameters the OpenAI-compatible preview endpoint accepts, and which of the two addresses given for it
  ([G-CHAT] against [G-MIGRATE]) is right.
- Whether the "Public Preview" exclusion from zero data retention and from the training opt-out, which the pages
  state for models, also touches the OpenAI-compatible endpoint, itself in public preview.
- `prompt_mode`: its description comes from a search summary of Mistral's deprecated native-reasoning page, not
  from a page read.
- The request format, address and sign-in for Mistral models on each cloud marketplace, and tool calling on a
  self-run server.
- The base URL as a stated sentence: it is taken from the examples.

## Row for the overview

| Provider | Hosted model API | API format | Sign-in | Tool calling | JSON-schema output | Streaming | Where data is processed | Run by |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Mistral | yes: `https://api.mistral.ai/v1/chat/completions` | own Chat Completions, close to the OpenAI format with differences (content as chunks, arguments as map or string, `any`, `max_tokens`, `random_seed`, own error bodies); an OpenAI-compatible endpoint at `/openai/v1` in public preview; a Responses API; Agents and Conversations in beta | API key (bearer), made in the Studio console, with an expiry date | yes: `auto`, `none`, `any`, `required`, a named function; `parallel_tool_calls` on by default; at most 128 tools | yes: `response_format` `json_schema` with `strict` (default false), and `json_object`; accepted schema keywords not published; together with tools not confirmed | yes: server-sent events ending in `data: [DONE]`; usage on the last chunk (pages disagree on `stream_options.include_usage`) | default address: hosted in the EEA, but "Mistral does not commit to a specific inference location"; EU processing committed only on `api.eu.mistral.ai` (1.1 times the price); US on `api.us.mistral.ai`. No request field against storing: zero data retention on request, pay-as-you-go only. Training: on in free mode unless switched off | both: vendor; cloud marketplaces (Azure AI, Amazon Bedrock, Vertex AI, Snowflake, IBM watsonx, Outscale); open-weight models run by the customer |
