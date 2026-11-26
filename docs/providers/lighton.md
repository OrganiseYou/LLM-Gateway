# LightOn (Paradigm)
Read on 2026-11-27. Hosted text chat API: yes. LightOn's API reference gives the chat endpoint at a fixed address of
LightOn itself (`https://paradigm.lighton.ai/api/v3/chat/completions`), and its terms describe Paradigm as run by
LightOn (shared or dedicated), as a hybrid, or installed and run by the customer. So: both. On an instance of the
customer's own, the same paths are reached at that instance's address. Whether a new customer can get an account on
`paradigm.lighton.ai` without a sales contract is not confirmed.

Every fact below was read on 2026-11-27 on the linked page of LightOn itself, unless the line says "from a search
summary" or "not confirmed". The pages were read as text through a page-reading tool that returns the content
condensed; the quoted fragments are as that tool returned them and should be compared with the page before they are
shown to a customer. The OpenAPI file [spec-v3] is 795,131 characters long; only its first 100,000 were read, the
chat schemas were taken from the reference page [chat-v3], which is generated from it. All JSON in this file is
synthetic, written by hand from the documented shapes.

Short names for the pages used below:

| Short name | Page |
|---|---|
| [chat-v3] | https://docs.lighton.ai/api-reference-v3/models/generate-a-chat-completion.md ("Generate a chat completion", API V3) |
| [chat-v2] | https://docs.lighton.ai/api-reference-v2/models/generate-a-chat-completion-v2-%E2%80%94-deprecated.md |
| [spec-v3] | https://docs.lighton.ai/api-reference/openapi-v3.yaml (`info.title` "LightOn API", `info.version` "3.15.0 (v3)"; first 100,000 characters read) |
| [index] | https://docs.lighton.ai/llms.txt and the lists it points to: https://docs.lighton.ai/_llms/en/paradigm-api.txt, https://docs.lighton.ai/_llms/en/paradigm-api/api-references.txt, https://docs.lighton.ai/_llms/en/paradigm-api/api-references/api-v3.txt, https://docs.lighton.ai/_llms/en/paradigm-platform.txt |
| [openai-compat] | https://docs.lighton.ai/en/developer-resources/api-fundamentals/openai-compatibility.md ("OpenAI SDK Compatibility") |
| [quick-guide] | https://docs.lighton.ai/en/developer-resources/api-fundamentals/quick-guide.md ("Chat API Endpoint Quick Guide") |
| [structured] | https://docs.lighton.ai/en/developer-resources/chat-and-ai-models/structured-output.md ("Get Structured Output Generation") |
| [models-guide] | https://docs.lighton.ai/en/developer-resources/chat-and-ai-models/models-list.md ("Get the list of models") |
| [models-v2] | https://docs.lighton.ai/api-reference-v2/models/list-available-models.md |
| [models-v3-instance] | https://docs.lighton.ai/api-reference-v3-instance/models/list-ml-models.md |
| [aliases] | https://docs.lighton.ai/api-reference-v3/models/list-aliases.md |
| [external-key] | https://docs.lighton.ai/api-reference-v3/models/set-personal-external-api-key.md |
| [async] | https://docs.lighton.ai/en/developer-resources/chat-and-ai-models/async-requests.md |
| [usage-data] | https://docs.lighton.ai/en/developer-resources/api-fundamentals/usage-data.md |
| [sv5] | https://docs.lighton.ai/en/developer-resources/lighton-models/alfred-sv5.md ("Alfred Sovereign 5") |
| [ft5] | https://docs.lighton.ai/en/developer-resources/lighton-models/alfred-ft5.md ("Alfred Frontier 5") |
| [threads-guide] | https://docs.lighton.ai/en/developer-resources/agents-and-threads/threads.md |
| [agents-guide] | https://docs.lighton.ai/en/developer-resources/agents-and-threads/agents.md |
| [agent-api-old] | https://docs.lighton.ai/en/developer-resources/chat-and-ai-models/agent-api (says of itself that it is outdated from the February 2026 release on) |
| [turn-v3] | https://docs.lighton.ai/api-reference-v3/threads/create-a-conversation-turn-in-a-thread.md |
| [ask-v3] | https://docs.lighton.ai/api-reference-v3/files-processing/ask-a-question-over-your-documents.md |
| [status-v3] | https://docs.lighton.ai/api-reference-v3/system/system-status.md |
| [errors] | https://developers.lighton.ai/errors ("API Errors", on LightOn's second documentation site) |
| [dev-index] | https://developers.lighton.ai/llms.txt (page list of the second documentation site) |
| [dev-intro] | https://developers.lighton.ai/api-reference/introduction.md |
| [dev-auth] | https://developers.lighton.ai/authentication.md |
| [dev-scaleway] | https://developers.lighton.ai/integrations/scaleway.md |
| [admin-keys] | https://docs.lighton.ai/en/administration/iam/authentication/api-keys.md |
| [profile-keys] | https://docs.lighton.ai/en/user-guides/user-profile/my-api-keys.md |
| [admin-models] | https://docs.lighton.ai/en/administration/system-configuration/managing-models.md |
| [releases] | https://docs.lighton.ai/en/releases/index.md |
| [terms-use] | https://docs.lighton.ai/en/support-legal/legal-and-compliance/terms-of-use.md (effective 28/05/2026) |
| [terms-service] | https://docs.lighton.ai/en/support-legal/legal-and-compliance/terms-of-service.md (LightOn Console, version 1.0, effective 28/05/2026) |
| [privacy] | https://docs.lighton.ai/en/support-legal/legal-and-compliance/privacy-policy.md (dated April 21, 2026) |
| [gdpr] | https://docs.lighton.ai/en/support-legal/legal-and-compliance/gdpr-compliance.md |

## Reaching it
- Base URL and the chat path: `POST https://paradigm.lighton.ai/api/v3/chat/completions`. The reference names the
  server `https://paradigm.lighton.ai` and the path `/api/v3/chat/completions` [chat-v3]. A base URL for a preset is
  therefore `https://paradigm.lighton.ai/api/v3`, with `/chat/completions` appended.
- On a customer's own instance the host changes and the path stays. The guides read the base address from a setting
  and say for private instances to "configure the correct base URL" [quick-guide]; the usage-data guide says to
  replace the base URL with the company's instance URL [usage-data]; the error page uses the placeholder
  `https://your-instance.lighton.ai/api/v3/` [errors]. No page gives a rule for what such an address looks like.
- **The pages disagree on the version in the path.** `/api/v3` in the reference [chat-v3] and in the thread and
  agent guides [threads-guide] [agents-guide]. `/api/v2` (base `https://paradigm.lighton.ai/api/v2`) in every chat
  guide: [openai-compat], [quick-guide], [structured], [async]. The v2 chat endpoint is marked deprecated: it "will
  be removed in a future release", use `POST /api/v3/chat/completions` instead [chat-v2]; the release notes of March
  2026 ("Xenial Xerus") say chat completions and embeddings moved to V3 and "V2 is now deprecated" [releases]. Build
  to v3. An older customer-run instance (before March 2026) may only have v2; not confirmed.
- **The pages disagree on the host of v2.** The v2 reference pages name the server `https://api.lighton.ai`
  [chat-v2] [models-v2]; the guides use `https://paradigm.lighton.ai/api/v2` [quick-guide] [openai-compat].
  `api.lighton.ai` is also the address of LightOn's other product, the Console API (see the last section).
- Hosted by the vendor, run by the customer, or both: both. LightOn's terms of use define four ways (section 6.2)
  [terms-use]: shared cloud (multi-tenant, run by LightOn); dedicated (single tenant, "hosted and managed by LightOn
  exclusively on behalf of the Customer", with the choice of a SecNumCloud-certified host); hybrid (software and
  stored data at the customer, the GPU computation on nodes under LightOn's control); on-premises ("installed and
  operated directly under the Customer's full control on their own infrastructure", can be cut off from the
  internet).
- API format: OpenAI-compatible in shape, for chat completions. The reference describes the endpoint as "a simple
  proxy forwarding your requests to the desired model" [chat-v3]. The compatibility guide says only "specific
  endpoints" are compatible and that some advanced parameters may not be supported, without listing them
  [openai-compat]. There is no fuller own format for plain chat; the platform's own endpoints (threads, ask) do a
  different job (last section).
- API versions or dated headers a request must send: none documented. The version is in the path [chat-v3].
- Request body types: the reference accepts `application/json`, and also form-encoded and multipart bodies with the
  same schema [chat-v3]. Send JSON.

## Signing in
- Method: a bearer key, `Authorization: Bearer <key>` [chat-v3] [quick-guide]. No other header, no project or
  deployment field.
- What the customer must create at the provider, in plain words: an API key in their Paradigm instance. A user with
  the "API Key Manager" permission creates it from the profile menu ("API Keys", then "Add API Key"); otherwise an
  administrator creates it in the admin panel. The key is shown once. A user can have at most five active keys
  [profile-keys] [admin-keys]. The models guide gives the profile path as `/settings/api-key` on the instance
  [models-guide].
- A key can be limited: to a role (standard, admin, project), to "Permitted Models", and with an optional expiry;
  an expired or revoked key stops working [admin-keys]. A model missing for a key is therefore a normal case, not
  only a wrong name. The key's format is not described beyond an automatically generated prefix [admin-keys].
- The customer must also give: the model's technical name as configured on their instance (see below), and, on an
  instance of their own, its address.

## What a request can do
| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | **Not confirmed in OpenAI's `tools` form.** The reference lists only OpenAI's older form. | Request: `functions` (array of objects), `function_call` (string). Message roles include `function` and `tool`; a message may carry `name` and `function_call`. Answer: `choices[].message.function_call` (object). No `tools`, `tool_choice` or `tool_calls` field appears in the request or the answer schema. The guide names the `tool` role and promises tools "later" without a section. A release note of February 2026 ("Wise Wolf") says compatibility with external tools using OpenAI-style tool calling was fixed, without naming the endpoint or the fields. | [chat-v3], [quick-guide], [releases] |
| Forced tool call | Not confirmed. | `function_call` is typed as a string only, described as controlling how the model responds to function calls; its values are not listed, and the object form that names one function is not in the schema. | [chat-v3] |
| Parallel tool calls | Not confirmed for the endpoint. | No `parallel_tool_calls` field; the answer schema holds one `function_call` per message. The model page of Alfred-ft5 says the model can call several tools in sequence or in parallel, which is a statement about the model, not about the endpoint. | [chat-v3], [ft5] |
| Structured output with a JSON Schema (and which schema keywords are refused) | Yes by a guide, with provider-only fields; not in the reference. | Top-level `guided_json` (a JSON Schema, in the example given as a string), `guided_choice` (list of strings), `guided_regex` (a pattern). The guide's example posts to the deprecated `/api/v2/chat/completions`; neither the v2 nor the v3 reference lists these fields. `response_format` is not documented for chat completions. Refused schema keywords: not documented. The guide advises a stop sequence for JSON output, and says that from January 2026 the thread API is "the preferred way" to get structured output. | [structured], [chat-v3], [chat-v2] |
| Tools together with structured output | Not confirmed. | No page speaks of the combination. | [structured], [chat-v3] |
| Streaming (and whether usage arrives in the stream) | Yes; usage in the stream not confirmed. | `stream: true`. Server-sent events: lines `data: {json}`, the text in `choices[0].delta.content`, the end marked by `data: [DONE]`. The reference gives no schema for a chunk. `stream_options` is not documented, and no page shows `usage` in a chunk. | [chat-v3], [quick-guide] |
| System instructions (a role, or a separate field) | Yes, a role. | A message with `role: "system"`. An instance may also add its own start messages to a model (a template set by the administrator), which the caller does not see. | [chat-v3], [quick-guide], [admin-models] |
| Listing models | Yes on v2 (deprecated); the v3 replacement is not documented under the name the deprecation notice gives. | `GET <base>/models` with the bearer key, where base ends in `/api/v2`: `{"object": "list", "data": [...]}`, each entry with `object: "model"`, `name`, `technical_name`, `model_type`, `deployment_type` (`Self-Hosted` or `External`), `enabled`, `is_default_for_company`. There is **no `id` field**: the value to send as `model` is `technical_name`. The page says this endpoint will be removed and names `GET /api/v3/models` as the replacement; no page for that path exists in the v3 index. What v3 does document is `GET /api/v3/instance/models`: a bare JSON array (no `data` wrapper) of entries with `id` (a uuid, not the name to send), `name`, `technical_name`, `model_type`, `enabled`, `health`, and more. Only models the key may use are listed. | [models-v2], [models-guide], [models-v3-instance], [index] |

## Limits and shapes
- Required fields a neutral request may lack: only `model` and `messages` are required [chat-v3]. The maximum-tokens
  field is `max_tokens` (optional); `max_completion_tokens` is not documented [chat-v3].
- Documented request fields, complete list: `model`, `messages`, `max_tokens`, `temperature`, `top_p`, `n`,
  `stream`, `stop` (up to 4 sequences), `presence_penalty`, `frequency_penalty`, `logit_bias`, `user`, `functions`,
  `function_call` [chat-v3]. Not documented, so not confirmed to be accepted or passed on: `tools`, `tool_choice`,
  `parallel_tool_calls`, `response_format`, `stream_options`, `max_completion_tokens`, `seed`. Because the endpoint
  is described as a proxy to the model's server, an undocumented field may pass through or may be refused; no page
  says which.
- Temperature range: "between 0 and 2" [chat-v3]. The guide gives 0.0 to 2.0 for the two penalties [quick-guide].
- Tool name rules: not documented.
- Model name shape: the model's "technical name", or the technical name of an alias; it "must exist and be
  configured from the admin" [chat-v3]. If the value matches an alias of the caller's company, the alias decides
  which model answers, even when a model has the same name [chat-v3]. Names are therefore per instance, set by its
  administrator [admin-models]. Text models the documentation names:
  - `alfred-ft5` (Alfred Frontier 5): used in the guides' requests; 32-billion-parameter vision-language model,
    context up to 32,768 tokens [quick-guide] [structured] [ft5].
  - Alfred-sv5 (Alfred Sovereign 5): 24 billion parameters, context up to 32,768 tokens, "strong support" for
    function calling and structured outputs. The page writes the name as "Alfred-sv5"; its technical name in a
    request was not shown (presumably `alfred-sv5`; not confirmed) [sv5].
  - `alfred-4.2`: in the example of the v3 reference page (from a search summary; the example was not in the text
    read). The Alfred-sv5 page names Alfred 4.2 as the earlier model [sv5].
  - `mistral-large-latest` and `custom-{company_id}-{uuid}`: named as accepted `model` values of the ask endpoint,
    not of chat completions [ask-v3].
  - A model on an instance is either served by the instance (the guide recommends a vLLM server) or is "External";
    the model list carries a routing string of the form `provider/model` for such models [admin-models]
    [models-v2] [models-v3-instance]. Which models a given instance has is only known from its model list.
- The shape of a tool call in an answer, and of a tool result in a request: as documented, the older form only. In
  an answer: `choices[].message.function_call` (an object; its inner fields are not spelled out). In a request: a
  message with role `function` (or `tool`), `name` and `content` [chat-v3]. A `tool_calls` list with call ids and a
  `tool_call_id` on the result are not in the schema; not confirmed.
- The answer: `id`, `object` (always `chat.completion`), `created` (Unix time), `model`, `choices[]` with `index`,
  `message` (`role`, `content` as string or null, `function_call`) and `finish_reason`; `usage` with
  `prompt_tokens`, `completion_tokens`, `total_tokens` [chat-v3].
- Finish reasons and what each means: `finish_reason` is a string or null; **no values are listed** [chat-v3]. Not
  confirmed which values arrive (the usual `stop`, `length`, `function_call`, `tool_calls` are not stated). An
  adapter must treat an unknown value as neutral "other".
- Error body shape, error codes, rate-limit headers:
  - For chat completions the reference documents only two statuses: 200 and 503 [chat-v3].
  - 503 during maintenance has its own body: `detail`, `error` (`service_maintenance`), `mode` (`full_shutdown` or
    `warning_banner`), and optionally `reason`, `started_at`, `endpoint_category_names`. The page says to check
    `GET /api/v3/system/status` (no sign-in needed) and retry [chat-v3] [status-v3].
  - The general v3 error body, standardised in March 2026 [releases], is flat, **not OpenAI's nested `error`
    object**: `id` (or null), `code` (the HTTP status as a number), `error` (a machine-readable word), `detail`
    (text that may change), `doc_url`; a 422 adds `fields` (per field a list of `{error, detail}`) [errors]
    [external-key] [ask-v3]. The words listed: `bad_request` 400, `unauthorized` 401,
    `email_verification_required` and `insufficient_permissions` 403, `not_found` 404, `conflict` 409,
    `payload_too_large` 413, `content_filtered`, `context_length_exceeded` and `validation_error` 422,
    `too_many_requests` 429, `internal_server_error` 500, `model_unavailable` 502 or 503, `model_timeout` 504
    [errors]. That this body is what chat completions returns is not stated on [chat-v3]; and since the endpoint
    forwards to the model's server, an error in that server's own shape cannot be ruled out. Not confirmed.
  - An instance whose API is switched off answers 503 with a plain message ("API is disabled...") [releases].
  - Rate-limit headers: none documented. A 429 only means wait before retrying [errors] [dev-auth]; no
    `Retry-After` and no limit numbers on any page read. The compatibility guide says only that rate limits differ
    from OpenAI's [openai-compat].

Synthetic examples, written by hand from the shapes above:

```json
{
  "model": "example-model",
  "messages": [
    {"role": "system", "content": "Answer in one word."},
    {"role": "user", "content": "Say hello."}
  ],
  "max_tokens": 16,
  "temperature": 0.2
}
```

```json
{
  "id": "chatcmpl-example-1",
  "object": "chat.completion",
  "created": 1795000000,
  "model": "example-model",
  "choices": [
    {"index": 0, "message": {"role": "assistant", "content": "Hello"}, "finish_reason": "stop"}
  ],
  "usage": {"prompt_tokens": 12, "completion_tokens": 1, "total_tokens": 13}
}
```

(The value `"stop"` above is illustrative; the documentation lists no finish reasons.)

```json
{
  "id": null,
  "code": 401,
  "error": "unauthorized",
  "detail": "Authentication credentials were not provided or are invalid.",
  "doc_url": "https://developers.lighton.ai/errors#unauthorized"
}
```

## Data
- Where requests are processed, as the provider states it: for the ways LightOn runs itself, LightOn's
  infrastructure is "hosted within the European Union" (terms of use, section 6.2) [terms-use]. The privacy policy
  says processing is in "data centers hosted by its service providers, in France or in Europe" and that data "may
  be transferred to third parties operating outside the European Union"; the one processor it names is a monitoring
  service in the US for the web interfaces, under standard contractual clauses; the full list is in a data
  processing agreement "available upon request" [privacy]. No page names the cloud provider or the country of the
  inference nodes. For an on-premises instance: the customer's own infrastructure [terms-use]. For a hybrid: storage
  at the customer, computation on LightOn-controlled GPU nodes [terms-use].
- A caveat a preset must carry: a model on an instance can be of deployment type "External" (the admin guide gives
  Sagemaker and OpenAI as examples next to self-hosted) [admin-models] [models-v2]. A request for such a model
  leaves the instance for that provider; the documentation read does not state this in so many words. Where a
  request is processed therefore depends on the instance's model configuration, which the model list shows per
  model (`deployment_type`).
- The option not to store requests, and whether training on them is off by default:
  - There is **no per-request field** to switch storage off on chat completions; none is documented [chat-v3].
    Nothing for an adapter to send.
  - Storage: the terms describe inference run by LightOn as stateless; input "is not stored persistently on the
    servers or compute nodes" beyond the time needed to answer, and there is no persistent storage of inference
    data by default [terms-use]. The privacy policy says the same for API inference and that access and technical
    logs, which exclude prompts and completions, are kept up to 90 days [privacy]. Conversation history is stored
    only when the customer switches such features on [privacy]; the thread API is such a feature (it keeps threads
    and turns that can be read back) [threads-guide].
  - Usage tracking per key: an API key has an "Opt-In" setting; unchecked, no usage data is recorded for it
    [admin-keys]. This is a key setting in the admin panel, not a request field.
  - Training: off by default. The privacy policy says LightOn "does not use the input and output data (prompts and
    completions)" for training unless the customer asks in writing [privacy]. The Console terms of service say the
    same for customer data (article 8.6) [terms-service]; those terms cover the Console, not Paradigm.
  - On an on-premises instance, storage and retention are the customer's own configuration [terms-use].

## Not to confuse with chat completions
A client could mistake these for the chat API; a neutral client uses none of them.

- **Threads and agents** (`POST /api/v3/threads`, `POST /api/v3/threads/turns`, `POST /api/v3/threads/{id}/turns`):
  the platform's own conversation API, which LightOn recommends for using Paradigm's tools [threads-guide]. It takes
  a `query` (not `messages`), runs an agent with the tools set up on the instance (native tools and MCP servers;
  `force_tool`, `force_mcp_server`), keeps the conversation on the server, may answer 202 and continue in the
  background, and returns turns made of parts (`text`, `reasoning`, `tool_call`, ...) [turn-v3] [threads-guide].
  Its `response_format` takes a bare JSON Schema object, not OpenAI's `{type: "json_schema", ...}` wrapper
  [threads-guide] [turn-v3]. A caller cannot hand in its own function tools there, as far as the pages read show
  [threads-guide] [agents-guide]. Server-side state and server-run tools do not fit a stateless library that never
  runs a tool.
- **Ask** (`POST /api/v3/ask`): a question over the instance's indexed documents; `query`, an optional `model`,
  `response_format` as a bare schema with `type: "object"`; its stream uses named events (`sources`, `token`,
  `done`, `error`), not chat-completion chunks [ask-v3].
- **The Console API** (`https://api.lighton.ai/api/v3/...`, documented on `developers.lighton.ai`): LightOn's
  developer product for search, parse, extract and ask. Its page list holds no chat-completions endpoint
  [dev-index] [dev-intro] [dev-auth], and its integration guide pairs LightOn search with a model served by another
  company's OpenAI-compatible endpoint [dev-scaleway]. A key of the Console is created at `console.lighton.ai`
  [dev-auth] and is a different thing from a Paradigm key.
- **Text completions, embeddings, tokenize, files** on the same instance are other endpoints
  ([index], [openai-compat]); out of scope here.

Provider-only fields a neutral client must not send unless the preset says so: `guided_json`, `guided_choice`,
`guided_regex` (chat completions, by the guide only) [structured]; `functions` and `function_call` are OpenAI's
older form and the only tool fields in the reference [chat-v3]; `query`, `force_tool`, `tool_parameters`,
`force_mcp_server`, `agent_id`, `chat_setting_id`, `background`, `max_steps`, `system_prompt_suffix`,
`workspace_ids`, `file_ids`, `tag_ids` belong to threads and ask, never to chat completions [turn-v3]
[threads-guide] [ask-v3]. The header `X-Skip-Final-Answer` belongs to the platform's tool endpoints [releases].

## Not confirmed
- Whether `tools`, `tool_choice` and an answer with `tool_calls` work on `/api/v3/chat/completions`. Tried: the v3
  and v2 reference pages (only `functions` / `function_call`), the quick guide (the `tool` role, no section), the
  compatibility guide (silent), the release notes (one line on "OpenAI-style tool calling", no fields), a search of
  LightOn's sites for a tool-calling guide (none found). Until confirmed on a live instance by a person, the preset
  cannot claim tool calling in the current form.
- The values of `function_call` and whether one function can be forced.
- Parallel tool calls on the endpoint; `parallel_tool_calls`.
- Whether `guided_json` works on the v3 path (the guide uses v2), whether `response_format` is accepted on chat
  completions, which schema keywords are refused, and whether guided output can be combined with functions.
- The shape of a stream chunk beyond `choices[0].delta.content`; usage in a stream; `stream_options`; how a
  function call arrives in a stream; errors in the middle of a stream.
- Finish reason values.
- The error body and statuses that chat completions really returns (only 200 and 503 are on its page); rate limits,
  `Retry-After`.
- A v3 model list in OpenAI's shape. The deprecation notice names `GET /api/v3/models`; the page
  `api-reference-v3/models/list-available-models` returned 404 and the v3 index has no such entry.
- Whether a model's capabilities (functions, guided output, context length) can be asked of the API. The model
  lists carry no capability or context-length field [models-v2] [models-v3-instance].
- The exact technical names of the models on LightOn's own instance, other than `alfred-ft5`.
- Whether `paradigm.lighton.ai` is the shared-cloud instance a new customer lands on, or whether each hosted
  customer gets an address of its own. The terms name `console.lighton.ai` as the shared-cloud mode of the Console
  [terms-use]; for Paradigm no page says it.
- The cloud provider and country behind the EU statement; the list of sub-processors (in an agreement on request).
- That prompts sent to an "External" model leave the instance: implied by the model configuration pages, not
  stated.
- LightOn's website pages on deployment and pricing: `https://lighton.ai/deployment-options`,
  `https://www.lighton.ai/deployment-options` and `https://www.lighton.ai/new/pricing` returned 404;
  `https://lighton.ai/pricing` showed only "Coming soon". The sentence "hosted entirely on sovereign European
  cloud" is therefore known only from a search summary of the pricing page.
- The rest of the OpenAPI file [spec-v3] beyond its first 100,000 characters (its `components` section and its
  `servers` entry were not read directly).
- The GDPR page gives no location, provider or training statement [gdpr].
