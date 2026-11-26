# Microsoft Copilot / Azure OpenAI

Read on 2026-11-27. Hosted text chat API: yes for Azure OpenAI (Azure OpenAI in Microsoft Foundry Models); no model
API for Microsoft Copilot (see "Microsoft Copilot" below).

Every fact below was read on 2026-11-27 on the linked page of Microsoft's own documentation. "Not confirmed" means it
was not found on a page opened that day. Short names for the pages used most:

- [v1 guide]: https://learn.microsoft.com/en-us/azure/foundry/openai/api-version-lifecycle (page dated 2026-05-13)
- [chat reference]: https://learn.microsoft.com/en-us/rest/api/microsoft-foundry/azureopenai/chat (API version v1, page dated 2026-05-27)
- [models reference]: https://learn.microsoft.com/en-us/rest/api/microsoft-foundry/azureopenai/models (page dated 2026-05-27)
- [dated reference]: https://learn.microsoft.com/en-us/azure/foundry/openai/reference (page dated 2026-05-19)
- [function calling]: https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/function-calling (page dated 2026-05-13)
- [structured outputs]: https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/structured-outputs (page dated 2026-08-24)
- [reasoning models]: https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/reasoning (page dated 2026-11-20)
- [responses guide]: https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/responses
- [content filter]: https://learn.microsoft.com/en-us/azure/foundry-classic/foundry-models/concepts/content-filter (page dated 2026-08-04; marked "classic")
- [filter annotations]: https://learn.microsoft.com/en-us/azure/foundry-classic/openai/concepts/content-filter-annotations (page dated 2026-01-15; marked "classic")
- [content streaming]: https://learn.microsoft.com/en-us/azure/foundry/openai/concepts/content-streaming (page dated 2026-09-08)
- [quota]: https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/quota (page dated 2026-05-04)
- [limits]: https://learn.microsoft.com/en-us/azure/foundry/openai/quotas-limits (page dated 2026-08-20)
- [deployment types]: https://learn.microsoft.com/en-us/azure/foundry/foundry-models/concepts/deployment-types (page dated 2026-08-06)
- [data privacy]: https://learn.microsoft.com/en-us/azure/foundry/responsible-ai/openai/data-privacy (page dated 2026-05-18, change log entry 17 November 2026)
- [abuse monitoring]: https://learn.microsoft.com/en-us/azure/foundry/openai/concepts/abuse-monitoring (page dated 2026-05-13)
- [Copilot Chat API]: https://learn.microsoft.com/en-us/microsoft-365/copilot/extensibility/api/ai-services/chat/overview (page dated 2026-06-18)

## Reaching it

- Base URL and the chat path, newer "v1" form: `https://YOUR-RESOURCE-NAME.openai.azure.com/openai/v1/` with
  `POST .../openai/v1/chat/completions`. The same guide says the base URL may also be
  `https://YOUR-RESOURCE-NAME.services.ai.azure.com/openai/v1/`. The resource name is the customer's own; there is no
  shared host. ([v1 guide], [chat reference])
- Base URL and the chat path, older dated form:
  `POST https://YOUR_RESOURCE_NAME.openai.azure.com/openai/deployments/YOUR_DEPLOYMENT_NAME/chat/completions?api-version=2024-06-01`
  (the example on the page). Here the deployment name is in the path and `api-version` is required. ([dated reference])
- Hosted by the vendor, run by the customer, or both: hosted by Microsoft in Azure, inside a resource the customer
  creates in its own Azure subscription. "Microsoft hosts the Models sold by Azure in Microsoft's Azure environment"
  and they do not interact with services operated by OpenAI. ([data privacy])
- API format: the OpenAI format (Chat Completions and the Responses API), with Azure additions (content-filter
  fields, `reasoning_content`, `user_security_context`). On v1 the plain OpenAI client is used with a changed base
  URL; the older form needed an Azure-specific client. ([v1 guide], [chat reference])
- API versions or dated headers a request must send:
  - v1: none. "`api-version` is no longer a required parameter with the v1 GA API." An optional query parameter
    `api-version` exists with the possible values `v1` and `preview`, default `v1`. ([v1 guide], [chat reference])
  - The two pages disagree in one place: the parameter table of the [chat reference] lists only `v1` and `preview`,
    but the example request on the same page is
    `POST {endpoint}/openai/v1/chat/completions?api-version=latest&azure-beta=v1=preview`. Build to the table (send
    nothing).
  - Preview features on v1 are switched on with a feature-specific request header or by a path segment such as
    `alpha`, not by a version. ([v1 guide])
  - Dated form: `api-version` in the `YYYY-MM-DD` shape is required. The page names `2024-10-21` as the GA release
    of the dated inference specification and its table now lists `v1` as the latest GA data-plane release and
    `v1 preview` as the latest preview. The last dated preview named in the change log is `2025-04-01-preview`.
    ([dated reference], [v1 guide])
  - A retirement date for the dated form: not confirmed (none on the pages read).
- How the neutral "model" maps: on v1 the body field `model` holds the **deployment name** the customer chose, not
  the base model name (the code comments read "Replace with your model deployment name"). The answer's `model` field
  holds the underlying model and version (example on the page: a deployment answers with `gpt-4o-2024-08-06`). On the
  dated form the deployment name is in the path. ([v1 guide], [structured outputs])
- The deployments page also describes "instant access (preview)", where supported models are called by name without
  creating a deployment. Not read further. ([deployment types])

## Signing in

- Method, key: header `api-key: <key>`. The [chat reference] lists a second key scheme, the key in the
  `authorization` header (what the plain OpenAI client sends as a bearer token). Any one of the three methods is
  accepted. ([chat reference], [dated reference])
- Method, Microsoft Entra ID: header `Authorization: Bearer <token>`. ([dated reference], [v1 guide])
- The scope the token needs: **two values are documented, record both.**
  - `https://ai.azure.com/.default` in the code of the [v1 guide], [function calling] and [structured outputs].
  - `https://cognitiveservices.azure.com/.default` as the OAuth scope in the [chat reference] and
    [models reference]; the [reasoning models] page gets a token for the resource
    `https://cognitiveservices.azure.com`.
  - Which of the two a given resource accepts: not confirmed. The preset must let the token supplier choose.
- The identity needs the role `Cognitive Services OpenAI User` on the resource. ([v1 guide]) Entra ID sign-in needs a
  custom subdomain on the resource. ([function calling])
- What the customer must create at the provider, in plain words: an Azure subscription; a Foundry resource or an
  Azure OpenAI resource in a region; at least one model deployment with a name and a deployment type; then either
  one of the resource's keys, or an identity with the role above. The customer gives: the resource address, the
  deployment name, and the key or a token. ([v1 guide])

## What a request can do

Chat Completions unless stated. All read on 2026-11-27.

| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | yes, per model. **On the newest models it is limited or absent on Chat Completions** (see "Newest models" below) | `tools: [{type: "function", function: {name, description, parameters, strict}}]`; `functions` and `function_call` are deprecated; at most 128 tools; descriptions at most 1,024 characters | [chat reference], [function calling], [limits] |
| Forced tool call | yes | `tool_choice`: `none`, `auto`, `required`, or `{"type": "function", "function": {"name": "..."}}`; default `none` without tools, `auto` with tools | [chat reference], [function calling] |
| Parallel tool calls | yes, per model; no on the o-series; no when `reasoning_effort` is `minimal`; not on `gpt-5.4-mini` and `gpt-5.1-codex-mini` | `parallel_tool_calls` (boolean, default true); several entries in `tool_calls`, each with its own `id` | [chat reference], [reasoning models] |
| Structured output with a JSON Schema | yes, on listed models (gpt-4o 2024-08-06 and later, gpt-4.1, o-series, gpt-5 family; the GPT-6 rows of the reasoning page mark it supported) | `response_format: {type: "json_schema", json_schema: {name, strict: true, schema}}`; `json_object` is the older JSON mode. Refused or unsupported: string `minLength`, `maxLength`, `pattern`, `format`; number `minimum`, `maximum`, `multipleOf`; object `patternProperties`, `unevaluatedProperties`, `propertyNames`, `minProperties`, `maxProperties`; array `unevaluatedItems`, `contains`, `minContains`, `maxContains`, `minItems`, `maxItems`, `uniqueItems`. Every property must be in `required` (optional is a union with `null`); `additionalProperties: false` on every object; root may not be `anyOf`; at most 100 properties and five levels of nesting; `$defs`, `$ref` and recursion are supported | [structured outputs], [reasoning models] |
| Tools together with structured output | strict tool arguments: yes (`strict: true` on the function). With either form: "Structured outputs are not supported with parallel function calls", so send `parallel_tool_calls: false`. A sentence that `response_format` with a schema and `tools` may be sent in one request: not confirmed | `strict`, `parallel_tool_calls: false` | [structured outputs] |
| Streaming (and whether usage arrives in the stream) | yes, server-sent events, `data: {...}` lines ended by `data: [DONE]`. Usage arrives only when asked: `stream_options: {include_usage: true}` adds a last chunk with `usage` and an empty `choices` list | `stream: true`, `stream_options.include_usage` | [chat reference], [content streaming] |
| System instructions | a role. `system`, and `developer` for o1 and newer; "functionally the same". Do not send both in one request | `messages[].role` | [chat reference], [reasoning models] |
| Listing models | an endpoint exists: `GET {endpoint}/openai/v1/models` and `GET .../models/{model}`, same sign-in as chat. Whether it returns the customer's deployments, the base models, or both: not confirmed (the page only says "currently available models"). Items carry `id`, `object`, `created`, `owned_by` | - | [models reference] |

### Newest models: Chat Completions or the Responses API

- Microsoft's general advice: "For Azure OpenAI models we recommend using the Responses API"; Chat Completions on v1
  stays available, also for other vendors' models in Foundry. ([v1 guide])
- Both APIs are documented for the newest models: the GPT-6 rows (`gpt-6-astra` 2026-09-03 up to `gpt-6.2-sol`
  2026-11-17) mark Chat Completions, the Responses API and streaming as supported. ([reasoning models])
- **Tool calling differs between the two.** "Use the Responses API when you combine reasoning with function or custom
  tools." On Chat Completions ([reasoning models]):

  | Models | `reasoning_effort` not sent | `none` | any other value |
  |---|---|---|---|
  | `gpt-5.6-sol`, `gpt-5.6-terra`, `gpt-5.6-luna` | works | works, without reasoning | fails |
  | `gpt-6-sol`, `gpt-6-luna`, `gpt-6.1-terra` | fails | works, without reasoning | fails |
  | `gpt-6-astra`, `gpt-6.1-sol`, `gpt-6.2-astra`, `gpt-6.2-sol` | fails | fails | fails |

  For the last row function tools work only on the Responses API. The failure is a rejected request whose message
  starts "Function tools with reasoning_effort are not supported for ..."; its status and code are not stated.
- Some models have no Chat Completions at all (Responses only): `gpt-5.4-pro`, `gpt-5.3-codex`, `gpt-5.2-codex`,
  `gpt-5.1-codex-max`, `gpt-5.1-codex`, `gpt-5.1-codex-mini`, `gpt-5-pro`, `gpt-5-codex`, `codex-mini`, `o3-pro`.
  ([reasoning models])
- Responses API tool calling uses another shape: tools with `type`, `name`, `description`, `parameters` at the top
  level; the answer holds `function_call` items with `call_id`, `name`, `arguments`; results go back as
  `function_call_output` items; structured output is `text.format` with `type`, `name`, `schema`, `strict` as
  siblings; the token limit is `max_output_tokens`. ([responses guide], read through a summary of the first half of
  the page, not the full text; [structured outputs]; [reasoning models])

## Limits and shapes

- Required fields a neutral request may lack: only `model` and `messages` are required. No maximum-tokens field is
  required. ([chat reference])
- Maximum-tokens field: `max_completion_tokens` (covers visible and reasoning tokens). `max_tokens` is deprecated
  and "not compatible with o1 series models"; "Reasoning models will only work with the `max_completion_tokens`
  parameter when using the Chat Completions API." ([chat reference], [reasoning models])
- Parameters reasoning models refuse: "Reasoning models other than GPT-6 Astra don't support" `temperature`,
  `top_p`, `presence_penalty`, `frequency_penalty`, `logprobs`, `top_logprobs`, `logit_bias`, `max_tokens`. The
  GPT-6 table on the same page marks `temperature` and `top_p` as supported for all seven GPT-6 models; the two
  statements do not fully agree. ([reasoning models])
- Temperature range: not confirmed (the v1 reference gives the type "number or null" without a range).
- Tool name rules: "a-z, A-Z, 0-9, or contain underscores and dashes, with a maximum length of 64". ([chat reference])
- Model name shape: a deployment name chosen by the customer. Its allowed characters and length: not confirmed.
- The shape of a tool call in an answer: `choices[].message.tool_calls[]` with `id`, `type: "function"`,
  `function.name`, `function.arguments` (a JSON string). In a stream: `delta.tool_calls[]` with `index` (required),
  and `id`, `type`, `function.name`, `function.arguments` in pieces. ([chat reference])
- The shape of a tool result in a request: a message with `role: "tool"`, `tool_call_id` and `content`; one message
  per call. ([chat reference], [function calling])
- Finish reasons: `stop`, `length`, `tool_calls`, `content_filter`, `function_call` (deprecated form).
  `content_filter` means part or all of the answer was withheld by the filter. ([chat reference], [content filter])
- Azure additions in an answer: `choices[].content_filter_results`, top-level `prompt_filter_results[]` (each with
  `prompt_index` and `content_filter_results`), `message.reasoning_content`, `message.refusal`. Response header
  `apim-request-id` is the request id for support. ([chat reference], [filter annotations])
- Token usage: `usage.prompt_tokens`, `completion_tokens`, `total_tokens`, with
  `completion_tokens_details.reasoning_tokens` and `prompt_tokens_details.cached_tokens`. ([chat reference])
- Error body shape:
  - The v1 reference describes the error as an object with `code` (string or null, required), `message` (required),
    `param`, `type` and `inner_error`. ([chat reference])
  - The filter page's example wraps it: `{"error": {"message": "...", "type": null, "param": "prompt", "code":
    "content_filter", "status": 400}}`. ([content filter])
  - The older annotations page reads the detail from `error.innererror.content_filter_results` (no underscore),
    the v1 reference names the field `inner_error`. Read both spellings. ([filter annotations], [chat reference])
- Content-filter refusals:
  - Prompt refused: HTTP 400, `error.code` `content_filter`, `param` `prompt`. Not to be retried. ([content filter])
  - Answer refused, no stream: HTTP 200, `finish_reason` `content_filter`, no content ("In rare cases with longer
    responses, a partial result can be returned"). ([content filter])
  - Answer refused in a stream: HTTP 200, the stream goes on until the filter hits, the last chunk for that choice
    has `finish_reason` `content_filter`. The prompt and completion tokens produced so far are billed.
    ([content filter], [content streaming])
  - Filter not run: HTTP 200 with `content_filter_results.error` (`code` `content_filter_error`). ([content filter])
  - Stream shape to tolerate: the first chunk can have empty `id`, `object` and `model`, an empty `choices` list
    and `prompt_filter_results`; with the asynchronous filter there are chunks whose choice has no `delta`, only
    `content_filter_results` and `content_filter_offsets`. The page's sample also shows the first field spelled
    `prompt_annotations` once. In default streaming, text arrives in buffered pieces, not token by token.
    ([content streaming])
  - Filter categories and severities (`hate`, `sexual`, `violence`, `self_harm` with `safe`/`low`/`medium`/`high`;
    `jailbreak`, `indirect_attack`, `profanity`, `protected_material_text`, `protected_material_code`, custom
    blocklists) are in the reference. Turning filters off needs Microsoft's approval. ([chat reference],
    [content filter])
- Unknown deployment: not confirmed. No reference page states the status and code; only forum threads were found,
  which are not a source.
- Wrong API version: not confirmed, same reason.
- Rate limit: HTTP 429. Header `retry-after-ms` (milliseconds) is "Included in 429 responses"; a note on the same
  page says the OpenAI client respects `retry-after`. Every answer carries `x-ratelimit-limit-requests`,
  `x-ratelimit-limit-tokens`, `x-ratelimit-remaining-requests`, `x-ratelimit-remaining-tokens`,
  `x-ratelimit-reset-requests`, `x-ratelimit-reset-tokens`. A 429 can mean the deployment's own limit (message
  contains "Rate limit is exceeded") or shared capacity ("temporarily unable to process your request"); both are to
  be retried after the wait. The `code` value in a 429 body: not confirmed. ([quota])
- Stream errors on the Responses API arrive as an `error` event inside an HTTP 200. ([responses guide], summary read)

## Data

- Where requests are processed, as the provider states it: it depends on the deployment type the customer chose.
  "**Data Zone** types: The service processes data only within the Microsoft-specified data zone (US, EU, or Asia
  Pacific (APAC))." ([deployment types])
  - Global (`GlobalStandard`, `GlobalProvisionedManaged`, `GlobalBatch`): any Azure region. Microsoft recommends
    this type as the default and new models arrive there first.
  - Data Zone (`DataZoneStandard`, `DataZoneProvisionedManaged`, `DataZoneBatch`): for the EU zone, within the
    Azure EU Data Boundary, which "can include" EFTA countries such as Norway and Switzerland. Microsoft may add
    regions to a zone without notice.
  - Regional (`Standard`, `ProvisionedManaged`): within the Azure geography of the resource, possibly between
    regions of that geography. These arrive last for a new model, with no guaranteed date.
  - Data at rest stays in the geography of the resource for every type. ([deployment types], [data privacy])
  - The type is a property of the deployment, not of the request: nothing in the request selects a region.
- Training: prompts and completions "are NOT used to train any generative AI foundation models without your
  permission or instruction", and are not available to OpenAI or to other customers. Off by default, no switch
  needed. ([data privacy])
- What is stored without the customer asking:
  - Nothing by the model or the content filter.
  - Abuse monitoring may store sampled prompts and completions for human review by Microsoft staff, in the
    geography of the resource. The number of days: not confirmed (not on the pages read). ([data privacy],
    [abuse monitoring])
  - The opt-out is "modified abuse monitoring": an application form, open only to customers who meet Microsoft's
    Limited Access criteria; when approved, the storing and the human review stop, automated review continues. It
    is set per subscription and shows as the capability `ContentLogging` with the value `false`. There is no
    request field for it. ([data privacy], [abuse monitoring])
  - Change dated 17 November 2026: for models made available on or after 1 November 2026 the opt-out "will no
    longer be available, beginning on a future date to be announced", also for customers already approved, with at
    least 30 days' notice per model. ([data privacy], [abuse monitoring])
- The option not to store requests, per API:
  - Chat Completions: `store` (boolean or null) asks to keep the output "for use in model distillation or evals
    products". It is an opt-in; the default is not stated on the page. The feature's own guide is now under the
    "classic" documentation and titled deprecated (from a search summary; the page itself was not opened, its newer
    address returned 404). ([chat reference])
  - Responses API: stores by default; "By default, response data is retained for 30 days." `store: false` makes it
    stateless, and the caller then resends the earlier items itself. ([responses guide], summary read; [data privacy])

## Microsoft Copilot

- Microsoft Copilot is not a model API. What exists is the Microsoft 365 Copilot Chat API (preview) under Microsoft
  Graph: it starts and continues conversations with Microsoft 365 Copilot, grounded in the organisation's Microsoft
  365 content and web search, "all within the Microsoft 365 trust boundary". ([Copilot Chat API])
- It cannot serve as a chat model for this library: no model choice, no custom tools ("doesn't support tools such as
  code interpreter"), "only responds with textual responses", no structured output option on the page, conversations
  are kept by the service, and each user needs a Microsoft 365 Copilot add-on licence. It has a synchronous endpoint
  and a streamed one (server-sent events, `text/event-stream`). ([Copilot Chat API])
- Its sign-in (Graph permissions) and whether production use is allowed: not confirmed on the page read. A search
  summary of its reference page reports the endpoint `POST /beta/copilot/conversations` and a statement that
  production use is not supported.
- Consumer and Copilot Chat: a search summary of Microsoft's Copilot Chat FAQ reports "No, we don't offer an API".
  Not confirmed: the FAQ address (learn.microsoft.com/en-ca/copilot/faq) now leads to another article
  (https://learn.microsoft.com/en-us/copilot/plan/copilot-overview) that does not contain the sentence.
- So the preset named "Microsoft Copilot / Azure OpenAI" reaches Azure OpenAI only.

## Not confirmed

- Which Entra ID scope a resource accepts (`https://ai.azure.com/.default` or
  `https://cognitiveservices.azure.com/.default`); both are documented.
- The status and error code for an unknown deployment and for a wrong `api-version`. Tried: the v1 chat reference,
  the dated reference, a search of learn.microsoft.com (only forum threads).
- The `code` value in a 429 body, and whether `retry-after` (seconds) is sent next to `retry-after-ms`.
- The status and code of the "Function tools with reasoning_effort are not supported" failure.
- Whether `GET /openai/v1/models` returns deployments, base models or both; a deployment list a data-plane key can
  call was not found (deployments are managed through the control plane at `management.azure.com`, which takes an
  Azure management token).
- The dated reference for chat (`?view=rest-microsoft-foundry-2024-10-21`): the address returned the v1 content, so
  the dated request and error schema were not read; a retirement date for dated versions was not found.
- Temperature range; deployment name rules.
- Whether `response_format` with a schema and `tools` may be sent in the same request.
- The default of `store` on Chat Completions; the stored-completions guide (404 at
  `/azure/foundry/openai/how-to/stored-completions`).
- How long abuse-monitoring data is kept.
- The Responses guide was read only through a summary of its first 100,000 characters.
- The current content-filter and annotations pages are the "classic" ones; `/azure/foundry/openai/concepts/content-filter`
  returned 404.
- Copilot: the Graph permissions of the Chat API, its production-use statement, and the "no API" sentence for
  Copilot Chat.
