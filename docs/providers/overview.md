# Providers: overview

What this file is: the summary of the fifteen provider files in this folder, written for task LG-10 and used as the
input for the adapter map (task LG-11). Date: 2026-11-27. The fifteen providers and their order are those of decision
G3 in [../plan/decisions.md](../plan/decisions.md).

**Each provider's own file is the source.** Every cell and every item below is taken from that file, which holds the
link to the provider's page for each fact and the date it was read (2026-11-27 for all fifteen). Nothing here is new
research and nothing is filled in from memory. A cell links to the provider's page where one page carries it, and
otherwise to the section of the provider's file that holds the links. "Not confirmed" means the provider's file could
not establish it on a page of the provider; the file says what was tried. This file decides nothing: where two pages
or two files disagree, both are given.

The files: [openai.md](openai.md), [anthropic.md](anthropic.md), [gemini.md](gemini.md),
[azure-openai.md](azure-openai.md), [cohere.md](cohere.md), [aleph-alpha.md](aleph-alpha.md),
[silo-ai.md](silo-ai.md), [lighton.md](lighton.md), [pleias.md](pleias.md), [mistral.md](mistral.md),
[kyutai.md](kyutai.md), [stackit.md](stackit.md), [grok.md](grok.md), [kimi.md](kimi.md),
[lm-studio.md](lm-studio.md).

## 1. The table

The thirteen columns of LG-10 do not fit one readable table, so it is split into three, with the provider name
repeated: 1.1 reaching it and signing in, 1.2 what a request can do, 1.3 data. Gemini has two rows in each: the Gemini
API with a key, and Google Cloud with a project. The last column of each table is the date the row was read.

### 1.1 Reaching it and signing in

| Provider | Hosted chat API | API format | Base URL | Sign-in | Model list endpoint | Run by | Read |
| --- | --- | --- | --- | --- | --- | --- | --- |
| OpenAI Platform (ChatGPT) | yes ([file](openai.md)) | its own Chat Completions, the format other servers copy; the Responses API is recommended "for all new projects" ([page][oa-migrate]) | `https://api.openai.com/v1`, chat at `/chat/completions`; taken from the examples, no page states it as a sentence; regional hosts such as `eu.api.openai.com` ([file](openai.md#reaching-it)) | API key as bearer; optional headers `OpenAI-Organization`, `OpenAI-Project` ([page][oa-overview]) | `GET /models`: `id`, `created`, `owned_by`; no context length, no capabilities ([page][oa-models]) | vendor ([file](openai.md#reaching-it)) | 2026-11-27 |
| Claude (Anthropic) | yes ([file](anthropic.md)) | its own Messages API; an OpenAI-compatible layer exists and "is not considered a long-term or production-ready solution" ([page][an-compat]) | `https://api.anthropic.com`, `POST /v1/messages` ([page][an-overview]) | API key as bearer (now the documented primary form) or in `x-api-key` ("legacy fallback"); header `anthropic-version`; header `anthropic-workspace-id` when the key is not scoped to one workspace ([page][an-auth], [file](anthropic.md#signing-in)) | `GET /v1/models`, paged; per model `max_input_tokens`, `max_tokens`, `capabilities` ([page][an-models]) | vendor; also through cloud platforms ([file](anthropic.md#reaching-it)) | 2026-11-27 |
| Gemini: the Gemini API (a key) | yes ([file](gemini.md)) | its own `generateContent`; a newer Interactions API is recommended "for all new development"; an OpenAI-compatible endpoint "still in beta" ([file](gemini.md#reaching-it)) | `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` ([page][ga-gen]) | API key in `x-goog-api-key`; bearer on the compatible endpoint ([file](gemini.md#signing-in)) | `GET /v1beta/models`, paged; per model `inputTokenLimit`, `outputTokenLimit`, `supportedGenerationMethods` ([page][ga-models]) | vendor ([file](gemini.md#reaching-it)) | 2026-11-27 |
| Gemini: Google Cloud (a project) | yes ([file](gemini.md)) | the same `generateContent`; Interactions in Preview and only on `global`; an OpenAI-compatible endpoint with no status word on the pages read ([file](gemini.md#reaching-it)) | `https://{location}-aiplatform.googleapis.com/v1/projects/{project}/locations/{location}/publishers/google/models/{model}:generateContent`; `https://aiplatform.eu.rep.googleapis.com` with `locations/eu` ([page][gc-gen], [file](gemini.md#reaching-it)) | bearer with a Google Cloud OAuth access token (service-account tokens last 1 hour by default); project and location in the path; a Google Cloud API key also exists ("for testing") ([page][gc-auth], [file](gemini.md#signing-in)) | only a catalogue list on `v1beta1` was found ([page][gc-list]); a `v1` list, and a list of what one project may call: not confirmed | vendor, inside the customer's Google Cloud project ([file](gemini.md#reaching-it)) | 2026-11-27 |
| Microsoft Copilot / Azure OpenAI | Azure OpenAI: yes. Microsoft Copilot: no model API ([file](azure-openai.md#microsoft-copilot)) | the OpenAI format (Chat Completions and Responses) with Azure additions; Microsoft recommends the Responses API for Azure OpenAI models ([page][az-v1]) | `https://<resource>.openai.azure.com/openai/v1/` (also `<resource>.services.ai.azure.com`); an older dated form has the deployment in the path and needs `api-version` ([file](azure-openai.md#reaching-it)) | header `api-key`, or the key as bearer, or a Microsoft Entra ID bearer token (two scopes are documented); the `model` field holds the deployment name ([file](azure-openai.md#signing-in)) | `GET {endpoint}/openai/v1/models` exists ([page][az-models]); whether it returns deployments, base models or both: not confirmed | vendor, inside a resource in the customer's Azure subscription ([page][az-privacy]) | 2026-11-27 |
| Cohere | yes ([file](cohere.md)) | its own Chat API v2 (the fuller one); a Compatibility API in the OpenAI format, with no status stated ([page][co-compat]) | `https://api.cohere.com/v2/chat` ([page][co-chat]) | API key as bearer ([page][co-chat]) | `GET https://api.cohere.com/v1/models`, paged; per model `context_length`, `features`, `endpoints` ([page][co-models]) | both: vendor, Model Vault, cloud platforms, private deployment by the customer ([page][co-deploy]) | 2026-11-27 |
| Aleph Alpha (PhariaAI) | no public one found; the file's first line says "not confirmed": the product is installed by the customer, a hosted access exists by invitation at an address sent by mail ([file](aleph-alpha.md#reaching-it)) | OpenAI-compatible `/chat/completions`, next to older endpoints of its own; a separate stateful Responses API ([file](aleph-alpha.md#reaching-it)) | none fixed; `<the installation's address>/v1/chat/completions`; the pages disagree on the `/v1` prefix ([page][aa-spec], [file](aleph-alpha.md#reaching-it)) | bearer token: a service-account token made in PhariaOS (at most one year), a personal token, or on the hosted access a personal key valid 30 days ([file](aleph-alpha.md#signing-in)) | `GET /model-settings` in its own shape (`chat`, `max_context_size`); an OpenAI-shaped `GET /models` only where the installation switched it on ([page][aa-spec], [page][aa-rel2609]) | customer ([page][aa-deploy]); who operates the hosted access by invitation: not confirmed | 2026-11-27 |
| Silo AI (AMD) | no ([file](silo-ai.md#where-a-hosted-api-was-looked-for)) | none of its own; open-weight models behind the customer's server; AMD's and SiloGen's serving documents use vLLM with `/v1/chat/completions` ([file](silo-ai.md#what-silo-ai-and-amd-document-for-serving-a-model)) | none; the customer gives its server's address ([file](silo-ai.md#reaching-it)) | whatever the customer's server asks: no key in the documented examples, or a bearer key ([page][si-aimrun], [page][si-wbkeys]) | `GET /v1/models` on the customer's server, per AMD's documents ([page][si-aimrun]) | customer ([file](silo-ai.md#reaching-it)) | 2026-11-27 |
| LightOn (Paradigm) | yes; whether a new customer gets an account on `paradigm.lighton.ai` without a sales contract: not confirmed ([file](lighton.md)) | OpenAI-like chat completions, described as "a simple proxy forwarding your requests to the desired model"; v3 is current, v2 deprecated ([page][lo-chat]) | `https://paradigm.lighton.ai/api/v3`, chat at `/chat/completions`; on a customer's own instance the host changes ([page][lo-chat]) | API key as bearer, made in the Paradigm instance ([page][lo-keys]) | `GET <base>/models` on the deprecated v2 (`technical_name`, no `id`); the v3 replacement named in the deprecation notice has no page; `GET /api/v3/instance/models` returns a bare array ([file](lighton.md#what-a-request-can-do)) | both: shared, dedicated, hybrid, on-premises ([page][lo-terms]) | 2026-11-27 |
| Pleias | no ([file](pleias.md#reaching-it)) | none of its own; the customer's server, in the OpenAI format with vLLM, SGLang or llama.cpp as the model cards show ([file](pleias.md#reaching-it)) | none; the customer gives it ([file](pleias.md#reaching-it)) | none at Pleias; the customer's server decides; the cards' examples use no key ([file](pleias.md#signing-in)) | none at Pleias; the server's ([file](pleias.md#what-a-request-can-do)) | customer ([page][pl-about]) | 2026-11-27 |
| Mistral | yes ([file](mistral.md)) | its own Chat Completions, close to the OpenAI format; an OpenAI-compatible endpoint in public preview; a Responses API; Agents and Conversations in beta ([file](mistral.md#which-chat-apis-mistral-has-and-what-it-means-for-this-library)) | `https://api.mistral.ai/v1`, chat at `/chat/completions`, taken from the examples; `api.eu.mistral.ai` and `api.us.mistral.ai` ([page][mi-chat-ref], [page][mi-region]) | API key as bearer, with an expiry date ([page][mi-key]) | `GET /v1/models`; per model `max_context_length` and `capabilities` flags ([page][mi-models]) | both: vendor, cloud marketplaces, open-weight models run by the customer ([page][mi-deploy]) | 2026-11-27 |
| Kyutai | no, of any kind ([file](kyutai.md#where-a-hosted-api-was-looked-for)) | none of its own, and no chat model; Kyutai's own voice layer calls "any OpenAI compatible LLM server" ([page][ky-unmute]) | none; the customer gives its server's address ([file](kyutai.md#reaching-it)) | whatever the customer's server asks ([file](kyutai.md#signing-in)) | not confirmed ([file](kyutai.md#what-a-request-can-do)) | customer ([file](kyutai.md#reaching-it)) | 2026-11-27 |
| STACKIT | yes (AI Model Serving, shared models) ([file](stackit.md)) | OpenAI-compatible only ([page][st-service]) | `https://api.openai-compat.model-serving.eu01.onstackit.cloud/v1`, chat at `/chat/completions` ([page][st-use]) | bearer with a "STACKIT AI Model Serving auth token" made in the portal; it expires only if a lifetime was set ([page][st-start], [file](stackit.md#signing-in)) | `GET /v1/models` on the same address; the answer's shape is not shown ([page][st-models]) | vendor ([file](stackit.md#reaching-it)) | 2026-11-27 |
| Grok (xAI) | yes ([file](grok.md)) | OpenAI-compatible, two formats: Chat Completions (called "deprecated" or "legacy" on some pages) and the Responses API (recommended) ([file](grok.md#which-api-xai-recommends-and-what-it-means-for-this-library)) | `https://api.x.ai`, chat at `/v1/chat/completions`; `https://us.api.x.ai/v1` for the United States ([page][gr-overview], [page][gr-regions]) | API key as bearer ([page][gr-overview]) | `GET /v1/models` (`context_length`, `aliases`) and `GET /v1/language-models` in its own shape ([page][gr-models]) | vendor; also through Google Cloud ([file](grok.md#reaching-it)) | 2026-11-27 |
| Kimi (Moonshot AI) | yes, on two separate platforms (international, mainland China) ([file](kimi.md#two-platforms-two-hosts-the-customer-must-choose)) | OpenAI-compatible Chat Completions; also a Responses API and an Anthropic-compatible Messages API ([page][ki-overview]) | `https://api.moonshot.ai/v1` or `https://api.moonshot.cn/v1`, chat at `/chat/completions` ([page][ki-overview], [page][ki-cn-overview]) | API key as bearer, of the chosen platform; a key on the other host gets 401 ([file](kimi.md#signing-in)) | `GET /v1/models`; per model `context_length` and three capability flags ([page][ki-models]) | vendor ([file](kimi.md#reaching-it)) | 2026-11-27 |
| LM Studio | no; the customer's own machine ([file](lm-studio.md)) | OpenAI-compatible; also a REST API of its own and an Anthropic-compatible endpoint, on the same server ([file](lm-studio.md#reaching-it)) | `http://localhost:1234/v1` by default; the customer can change the port and must be able to type the address ([page][lm-compat]) | none by default; an optional bearer token from version 0.4.0 ([page][lm-auth]) | `GET /v1/models` (answer not shown on the page) and the richer `GET /api/v1/models` ([page][lm-models], [page][lm-rest-list]) | customer ([file](lm-studio.md#reaching-it)) | 2026-11-27 |

### 1.2 What a request can do

For the providers with an OpenAI-compatible format the cells are about chat completions.

| Provider | Tool calling | Parallel tool calls | Structured output | Tools together with structured output | Streaming | Read |
| --- | --- | --- | --- | --- | --- | --- |
| OpenAI Platform (ChatGPT) | yes, but not on every model: the newest models need the Responses API for it ([page][oa-migrate], [file](openai.md#which-api-openai-recommends-and-what-it-means-for-this-library)) | yes, on by default; `parallel_tool_calls` ([page][oa-create]) | yes: `response_format` `json_schema` with `strict`; a documented schema subset ([page][oa-struct]) | not confirmed ([file](openai.md#not-confirmed)) | yes, server-sent events ended by `data: [DONE]`; usage only with `stream_options.include_usage` ([page][oa-create]) | 2026-11-27 |
| Claude (Anthropic) | yes, "available on every model"; a forced call returns 400 on six named models and with thinking enabled ([page][an-tools]) | yes, on by default; off with `disable_parallel_tool_use` inside `tool_choice` ([page][an-parallel]) | yes, native: `output_config.format` with a JSON Schema ([page][an-structured]) | yes; the answer is then a tool call or JSON text ([page][an-structured]) | yes, server-sent events with named events, no `[DONE]`; usage in `message_start` and `message_delta` ([page][an-streaming]) | 2026-11-27 |
| Gemini: the Gemini API (a key) | yes: `functionDeclarations`, answers hold `functionCall` parts ([page][ga-fc]) | yes; no switch to turn it off was found ([page][ga-fc]) | yes; the field names differ per page (`responseFormat`, `responseMimeType` with `responseSchema`, `responseJsonSchema`) ([page][ga-so], [file](gemini.md#schema-dialect)) | yes on Gemini 3 and Gemini 4 series only, called a preview ([page][ga-so]) | yes: `:streamGenerateContent?alt=sse`; `usageMetadata` on every chunk ([page][ga-text]) | 2026-11-27 |
| Gemini: Google Cloud (a project) | yes, the same fields ([page][gc-fc]) | yes; no switch to turn it off was found ([page][gc-fc]) | yes: `responseMimeType` with `responseSchema` in the guide; the type page marks them deprecated in favour of `responseFormat` ([page][gc-so], [file](gemini.md#schema-dialect)) | the function-calling guide says it "can be combined with structured output", no model list; the structured-output guide is silent ([page][gc-fc]) | yes, with `alt=sse`; `usageMetadata` is a field of each chunk ([page][gc-resp], [file](gemini.md#what-a-request-can-do)) | 2026-11-27 |
| Microsoft Copilot / Azure OpenAI | yes, per model; limited or absent on Chat Completions for the newest models ([page][az-reason]) | yes, per model; `parallel_tool_calls`; not on the o-series and some other models ([page][az-reason]) | yes, on listed models: `response_format` `json_schema`; a narrower schema subset than OpenAI's file records ([page][az-struct]) | strict tool arguments: yes, with `parallel_tool_calls: false`; a schema in `response_format` together with `tools`: not confirmed ([page][az-struct]) | yes, server-sent events ended by `data: [DONE]`; usage only with `stream_options.include_usage` ([page][az-chat]) | 2026-11-27 |
| Cohere | yes; `tool_choice` `REQUIRED` or `NONE`, no named tool ([page][co-chat], [page][co-patterns]) | yes, decided by the model; no switch documented ([page][co-patterns]) | yes: `response_format` `json_object` with a schema; the reference calls the key `json_schema`, the guide `schema` ([page][co-struct]) | no: `response_format` "is not supported" together with `tools`; `strict_tools` exists, called experimental ([page][co-chat], [page][co-struct]) | yes, server-sent events with named events; usage and finish reason in `message-end` ([page][co-stream]) | 2026-11-27 |
| Aleph Alpha (PhariaAI) | yes, only for models on the `vllm` worker type and switched on per model; the reference lacks the answer's tool-call shape ([page][aa-tools], [file](aleph-alpha.md#the-shape-of-a-tool-call-in-an-answer-and-of-a-tool-result-in-a-request)) | not confirmed; the field `parallel_tool_calls` "will be rejected" and must not be sent ([page][aa-spec]) | yes: `response_format` only as `json_schema`, only on `vllm` workers; refused keywords not listed ([page][aa-struct]) | not confirmed ([file](aleph-alpha.md#not-confirmed)) | yes, server-sent events ended by `data: [DONE]`; usage only with `stream_options.include_usage`; tool calls in a stream not documented ([page][aa-spec]) | 2026-11-27 |
| Silo AI (AMD) | not confirmed as a feature; the Poro 2 chat template accepts tools; no model card mentions tool calling ([file](silo-ai.md#what-a-request-can-do)) | no for Poro 2 (its template refuses more than one call); other models: not confirmed ([page][si-p2tok]) | not confirmed ([file](silo-ai.md#what-a-request-can-do)) | not confirmed ([file](silo-ai.md#what-a-request-can-do)) | not confirmed for the OpenAI-style chat path ([page][si-aimrun]) | 2026-11-27 |
| LightOn (Paradigm) | not confirmed in the `tools` form; the reference lists only the older `functions` and `function_call` ([page][lo-chat]) | not confirmed ([file](lighton.md#what-a-request-can-do)) | by a guide only, with provider-only fields (`guided_json`, `guided_choice`, `guided_regex`), shown on the deprecated v2 path; `response_format` is not documented ([page][lo-struct]) | not confirmed ([file](lighton.md#what-a-request-can-do)) | yes, server-sent events ended by `data: [DONE]`; usage in the stream: not confirmed ([page][lo-chat], [page][lo-quick]) | 2026-11-27 |
| Pleias | not confirmed: not documented for any model ([file](pleias.md#what-a-request-can-do)) | not confirmed: not documented ([file](pleias.md#what-a-request-can-do)) | not confirmed: not documented for any model ([file](pleias.md#what-a-request-can-do)) | not confirmed: not documented ([file](pleias.md#what-a-request-can-do)) | not confirmed for the OpenAI-format servers; Pleias's own small RAG server streams plain text ([page][pl-slm]) | 2026-11-27 |
| Mistral | yes, per model; at most 128 tools ([page][mi-func]) | yes, on by default; `parallel_tool_calls` ([page][mi-chat-ref]) | yes: `response_format` `json_schema` with `strict`; accepted schema keywords are not published ([page][mi-custom]) | not confirmed ([file](mistral.md#not-confirmed)) | yes, server-sent events ended by `data: [DONE]`; usage on the last chunk in the guide's sample; the pages disagree on whether `stream_options.include_usage` is needed ([page][mi-chat], [file](mistral.md#what-a-request-can-do)) | 2026-11-27 |
| Kyutai | not confirmed; Kyutai's own client of such a server does not support tool calling ([page][ky-unmute]) | not confirmed ([file](kyutai.md#what-a-request-can-do)) | not confirmed ([file](kyutai.md#what-a-request-can-do)) | not confirmed ([file](kyutai.md#what-a-request-can-do)) | not confirmed as a request field; Kyutai's client relies on a streamed answer ([page][ky-unmute]) | 2026-11-27 |
| STACKIT | yes, per model, documented only through a tutorial; never send an empty or `null` `tools` ([page][st-tools], [page][st-faq]) | not confirmed ([file](stackit.md#what-a-request-can-do)) | not confirmed as a request feature; one release note says one model supports "Structured Outputs" ([file](stackit.md#what-a-request-can-do)) | not confirmed ([file](stackit.md#what-a-request-can-do)) | not confirmed ([file](stackit.md#what-a-request-can-do)) | 2026-11-27 |
| Grok (xAI) | yes, functions only, at most 1,000; the JSON of a tool call on Chat Completions is not spelled out ([page][gr-func], [page][gr-chat]) | yes, on by default; `parallel_tool_calls: false` switches it off ([page][gr-func]) | yes on the Responses API (`text.format`); on Chat Completions `response_format` exists but its shape is not confirmed ([page][gr-struct], [page][gr-chat]) | the guide says yes and shows it on the Responses API; on Chat Completions: not confirmed ([page][gr-struct]) | yes, server-sent events ended by `data: [DONE]`; the reference and the guide disagree on usage in the stream ([page][gr-chat], [page][gr-stream]) | 2026-11-27 |
| Kimi (Moonshot AI) | yes; a named function cannot be forced while thinking is on, which is always on the current models; `required` on the K3 series only ([page][ki-choice]) | yes, several calls in one answer; no switch documented ([page][ki-tools]) | yes: `response_format` `json_object` or `json_schema`, in the provider's own schema dialect (MFJS) ([page][ki-format]) | not confirmed ([file](kimi.md#not-confirmed)) | yes, server-sent events ended by `data: [DONE]`; usage in a last chunk with `stream_options.include_usage` ([page][ki-stream]) | 2026-11-27 |
| LM Studio | yes, in two grades (native, or a default format the server parses); text that does not parse is returned as ordinary content ([page][lm-tools]) | an answer may hold several calls; a field to switch that off: not confirmed ([file](lm-studio.md#what-a-request-can-do)) | yes: `response_format` `json_schema`; "Not all models are capable" ([page][lm-struct]) | not confirmed ([file](lm-studio.md#what-a-request-can-do)) | yes; usage only with `stream_options.include_usage`; the wire format of this endpoint is not stated: not confirmed ([page][lm-changelog], [file](lm-studio.md#what-a-request-can-do)) | 2026-11-27 |

### 1.3 Data

| Provider | The option not to store or train | Where data is processed | Read |
| --- | --- | --- | --- |
| OpenAI Platform (ChatGPT) | request field `store` (two pages disagree on its default); kept up to 30 days for abuse monitoring; Zero Data Retention needs approval; training off by default ([page][oa-data], [file](openai.md#data)) | no region promised on the default address; Europe through `eu.api.openai.com` with a project created for that region ([page][oa-residency]) | 2026-11-27 |
| Claude (Anthropic) | no request field; zero data retention per organisation through sales; otherwise deleted within 30 days (condensed reading); no training "without your express permission" ([page][an-retention], [file](anthropic.md#data)) | "Only `"us"` and `"global"` are available"; no EU-only option on the first-party API ([page][an-residency]) | 2026-11-27 |
| Gemini: the Gemini API (a key) | request field `store` for developer logs (on by default in projects created on or after 2026-10-26); abuse monitoring keeps prompts with no opt-out described; training off for paid use, on for the unpaid tier ([page][ga-logs], [page][ga-terms]) | no region can be chosen and none is promised: "in any country in which Google or its agents maintain facilities" ([page][ga-terms]) | 2026-11-27 |
| Gemini: Google Cloud (a project) | no request field; caching is on by default and switched off per project; flagged prompts may be logged up to 90 days; no training "without your prior permission or instruction" ([page][gc-gov], [file](gemini.md#data)) | the customer chooses: the `eu` multi-region or a region keeps machine-learning processing there; on `global` "you can't control or know which region" ([page][gc-loc], [page][gc-res]) | 2026-11-27 |
| Microsoft Copilot / Azure OpenAI | no request field against abuse-monitoring storage (an opt-out by application, announced to end for new models); `store` on Chat Completions is an opt-in whose default is not stated; no training "without your permission or instruction" ([page][az-privacy], [file](azure-openai.md#data)) | set by the deployment type: Global (any Azure region), Data Zone ("only within the Microsoft-specified data zone (US, EU, or Asia Pacific (APAC))"), or Regional ([page][az-deploy]) | 2026-11-27 |
| Cohere | no request field; logs deleted "after 30 days"; zero data retention for approved enterprise customers, and by default in a Model Vault; a training opt-out in the dashboard; the default for training: not confirmed ([page][co-commit], [file](cohere.md#data)) | shared API: no documentation page states a place; the sub-processor list gives "USA"; Model Vault: United States, European Union or Canada ([page][co-sub], [page][co-vault]) | 2026-11-27 |
| Aleph Alpha (PhariaAI) | no request field; no page says the inference API stores chat requests; logging is the customer's; training on requests is not addressed on any page read: not confirmed ([file](aleph-alpha.md#data)) | in the customer's own installation ("a self-hosted product"); an administrator can forward a model to another provider; for the hosted access by invitation: not confirmed ([page][aa-shared], [file](aleph-alpha.md#data)) | 2026-11-27 |
| Silo AI (AMD) | not applicable: no request reaches the vendor; storing is a setting of the customer's server ([file](silo-ai.md#data)) | wherever the customer runs the model ([file](silo-ai.md#data)) | 2026-11-27 |
| LightOn (Paradigm) | no request field; the terms describe inference as stateless, input "is not stored persistently"; training only if the customer asks in writing ([page][lo-terms], [page][lo-privacy]) | LightOn-run: "hosted within the European Union"; the cloud provider and country are not named; a model of the type "External" is served elsewhere; on-premises: the customer's ([page][lo-terms], [file](lighton.md#data)) | 2026-11-27 |
| Pleias | not applicable: Pleias is not in the path of a request ([file](pleias.md#data)) | on the customer's machines: "Can run entirely on your own infrastructure - on-premise or on-device" ([page][pl-about]) | 2026-11-27 |
| Mistral | no request field; zero data retention on request, pay-as-you-go only; how long requests are kept without it: not confirmed; training: free mode "may use your data" unless opted out, no default stated for pay-as-you-go ([page][mi-zdr], [page][mi-train]) | default address: "Mistral does not commit to a specific inference location" (the help centre: hosted in the EEA by default); EU and EFTA data centres only on `api.eu.mistral.ai` ([page][mi-region], [page][mi-where]) | 2026-11-27 |
| Kyutai | not applicable: no request reaches the vendor ([file](kyutai.md#data)) | wherever the customer runs its model, or whichever service the customer's address points to ([file](kyutai.md#data)) | 2026-11-27 |
| STACKIT | nothing to set: "We do not store any customer data from the requests." and "We do not train any models using your data." ([page][st-faq]) | region `eu01` in the address, which the platform's region page places in Germany; no page of AI Model Serving itself names the country ([page][st-regions], [file](stackit.md#data)) | 2026-11-27 |
| Grok (xAI) | no request field on Chat Completions; requests and responses stored 30 days by default; Zero Data Retention is a team setting; training off by default ([page][gr-security]) | default address: "the processing location is not guaranteed"; United States on `us.api.x.ai`; no EU address is documented ([page][gr-regions]) | 2026-11-27 |
| Kimi (Moonshot AI) | no request field; zero data retention only as an enterprise arrangement; training is not documented as off by default for an ordinary account ([page][ki-zdr], [page][ki-terms], [file](kimi.md#data)) | not confirmed: not stated for the API on either platform; no statement of processing in the EU was found ([page][ki-privacy], [file](kimi.md#data)) | 2026-11-27 |
| LM Studio | nothing to send: chat completions keeps no conversation and the vendor receives no local request ([page][lm-rest], [file](lm-studio.md#data)) | on the customer's own machine: requests "stay local" ([page][lm-offline]) | 2026-11-27 |

## 2. Providers without a hosted text chat API

Named plainly, as the files establish it. Decision G9 covers these (a named preset that asks for the address of the
customer's own server); decision G62 covers Kyutai.

- **Aleph Alpha (PhariaAI).** No vendor address that a new customer can sign up for and call was found; the file's
  first line records the question as "not confirmed" and its row as "no public hosted API found". The customer
  installs PhariaAI on its own Kubernetes cluster and calls that installation; a hosted access exists by invitation
  only, at an address sent by mail. Through a plain chat request: whatever model the installation's administrator
  enabled for chat (`chat: true` in `/model-settings`); the documentation has no list of chat models, and the names
  in its examples (`Aleph-Alpha/Kolibri-1.1`, `qwen2.5-vl-32b-instruct`, `llama-3.1-8b-instruct`) are examples only.
  ([aleph-alpha.md](aleph-alpha.md#reaching-it))
- **Silo AI (AMD).** The customer runs one of the open-weight models on its own server that speaks the OpenAI
  chat-completions format (the vendor's serving documents use vLLM). Through a plain chat request: the
  instruction-tuned models `LumiOpen/Llama-Poro-2-8B-Instruct`, `LumiOpen/Llama-Poro-2-70B-Instruct`,
  `LumiOpen/Llama-Poro-2-8B-Long-Instruct` and `LumiOpen/Poro-34B-chat`. Not Viking: it is published as base models
  only. ([silo-ai.md](silo-ai.md#the-text-models-silo-ai-publishes))
- **Pleias.** The customer downloads a model and runs it behind its own OpenAI-compatible server. Through a plain
  chat request, as Pleias documents it: the Baguettotron family (`PleIAs/Baguettotron`, `PleIAs/baguettotron-600m`,
  `PleIAs/Baguettotron-MoE`), `PleIAs/Monad` (one turn) and `PleIAs/Pleias-SLM-RAG`; they are trained without a
  system message and every answer starts with a thinking trace. Not through a chat request: Pleias-RAG-1B and
  Pleias-RAG-350M (no chat template; shown with the completions path or Pleias's library), Pleias-Nano,
  Pleias-Pico and the three Preview base models. ([pleias.md](pleias.md#what-works-through-a-plain-chat-request-the-honest-note-for-the-preset))
- **Kyutai.** No hosted API of any kind, and no text chat model: the Helium text models are base models whose cards
  say they are "not fine-tuned to follow instructions", without a chat template; the rest is speech. The customer
  runs a text chat model of its own choice behind its own OpenAI-compatible server; none of Kyutai's models works
  through a plain chat request as published. ([kyutai.md](kyutai.md#text-language-models))
- **LM Studio.** An application the customer installs and runs; its local server answers chat requests from the
  models the customer downloaded. Through a plain chat request: any chat model loaded or downloaded in it; the list
  differs per customer and is read from the server. ([lm-studio.md](lm-studio.md#reaching-it))
- **Microsoft Copilot.** It has no model API. The Microsoft 365 Copilot Chat API (preview) answers prompts grounded
  in an organisation's Microsoft 365 content, with no model choice and no custom tools. The preset "Microsoft
  Copilot / Azure OpenAI" reaches Azure OpenAI only, which is a hosted API (row above).
  ([azure-openai.md](azure-openai.md#microsoft-copilot))

LightOn, Cohere and Mistral have a hosted API and can also be run by the customer; STACKIT hosts only.

## 3. What the files found that the plan must decide

Input for the adapter map (LG-11). Each item names the providers; the facts and their links are in the provider's
file. A requirement id is given only where a provider file cites it. Only seven files cite ids: OpenAI, Gemini,
Silo AI, Kyutai, Grok, Kimi and LM Studio; Kimi's file has its own list
([kimi.md](kimi.md#what-contradicts-or-strains-docsrequirementsmd)). The other eight cite none.

### 3.1 API formats found

- **Chat completions, the common format.**
  - Spoken as the provider's main documented format: OpenAI (its origin), Azure OpenAI, Grok, Kimi, STACKIT,
    Aleph Alpha (on the customer's installation), LightOn, LM Studio.
  - Spoken by the customer's server for Silo AI, Pleias and Kyutai, as far as the vendor's documents show a server.
  - Mistral's native format is close to it, with differences
    ([mistral.md](mistral.md#differences-from-the-plain-openai-chat-completions-format)).
  - Offered as a second way in by providers with their own format: Claude (not for production), the Gemini API
    (beta), Google Cloud (no status word), Cohere (no status stated), Mistral (public preview; two pages give
    different addresses for it).
- **Dialect differences per provider**, as documented:
  - OpenAI: `max_completion_tokens`; role `developer`; `message.refusal`; newer models reject an explicit `n`;
    tool calling per model.
  - Azure OpenAI: the `model` field is the deployment name; content-filter fields and a 400 with code
    `content_filter`; a narrower schema subset; `reasoning_content`; sign-in by `api-key` header or Entra token; the
    older dated form with `api-version`.
  - Grok: `max_completion_tokens` counts visible output only; `stop` and the penalties return 400 on current
    models; finish reasons `stop`, `length`, `end_turn`; the tool-call JSON, the `response_format` shape and the
    error body are not documented for Chat Completions; a field `search_parameters` that must not be sent.
  - Kimi: sampling fields must not be sent; `max_completion_tokens` includes reasoning; `reasoning_content` must
    come back; no `parallel_tool_calls`; own schema dialect; a content refusal is a 400; two hosts
    ([kimi.md](kimi.md#differences-from-the-plain-openai-chat-completions-format)).
  - STACKIT: only `max_completion_tokens` is shown; an empty or `null` `tools` must never be sent; request headers
    with an underscore are dropped; `tool_choice`, `parallel_tool_calls`, `response_format` and `stream` are
    mentioned on no page.
  - Aleph Alpha: `max_tokens` only; `parallel_tool_calls` is rejected; `response_format` only as `json_schema`;
    one system message, first; the base address with or without `/v1`; the reference declares no tool calls in an
    answer and no error body.
  - LightOn: only the older `functions` and `function_call` are in the reference; structured output by
    `guided_json`; a flat error body; the model list has no `id`; the path holds `/api/v3`.
  - LM Studio: `max_tokens`; no `json_object`; `tool_choice` `required` only on one engine; reasoning in
    `reasoning_content` or `reasoning` depending on the model; own extra fields (`ttl`, `draft_model`, `top_k`,
    `repeat_penalty`); no error body documented.
  - Mistral native: content as a string or a list of chunks; arguments as a map or a string; `tool_choice` `any`;
    `max_tokens`; `random_seed`; three error-body shapes; no `store`.
- **Own formats.** Claude: Messages. Gemini: `generateContent`, the same on both ways in, with different addresses
  and sign-in. Cohere: Chat API v2. Mistral: native Chat Completions (above). Also present and not chat
  completions: LightOn's threads and ask endpoints, Aleph Alpha's older endpoints, LM Studio's `/api/v1/chat`.
- **A Responses-style API** (see decision G59, open):
  - OpenAI: recommended; required for tool calling on GPT-6 Astra and GPT-6.1 Sol, and from GPT-5.4 on Chat
    Completions supports tool calling only with `reasoning_effort` `none`. The file cites LR-20 and LR-23 (a new
    format) and LR-01, LR-09, LR-31 (it stores and keeps state by default).
  - Azure OpenAI: recommended; a table of models on which tools fail on Chat Completions; ten models have no Chat
    Completions at all. The library cannot see from a deployment name which model it reaches (G59).
  - Grok: recommended; Chat Completions is called "deprecated" on two pages and not on the reference, with no end
    date. The file cites LR-20, LR-23, LR-01, LR-09, LR-31.
  - Also offering a Responses-format API, without requiring it: Mistral (status not stated), Kimi, Aleph Alpha
    (stateful), LM Studio (`/v1/responses`, stateful).
  - Gemini has a comparable move in its own format: the Interactions API is recommended "for all new development",
    with server-side state on by default; `generateContent` "remains fully supported". The file cites LR-01 for
    staying on `generateContent`.
- **Anthropic-compatible endpoints** exist at Kimi and LM Studio, and on AMD's inference containers; xAI's are
  "fully deprecated".

### 3.2 Sign-in kinds found

- **API key as bearer:** OpenAI, Claude (now the primary documented form), Cohere, Mistral, LightOn, Grok, Kimi,
  STACKIT (a service-specific token, not the STACKIT account token), Aleph Alpha (a token), Azure OpenAI (its key
  is also accepted in the `authorization` header), the Gemini API's compatible endpoint, LM Studio when the
  customer switched it on, and the customer's servers of Silo AI, Pleias and Kyutai when they set a key.
- **API key in a header of the provider's own:** Claude `x-api-key` (called the "legacy fallback"), the Gemini API
  `x-goog-api-key` (the examples also show `?key=` in the address), Azure OpenAI `api-key`.
- **A short-lived token the caller supplies:** Google Cloud (an OAuth access token, 1 hour by default; the file
  cites LR-25: the library never holds the service-account key), Azure OpenAI (Microsoft Entra ID; two scopes are
  documented and which a resource accepts is not confirmed). Workload identity federation is documented at OpenAI
  and Claude as an alternative.
- **No sign-in:** LM Studio by default; the documented examples for Silo AI's and Pleias's servers.
- **Fields beyond the credential:** OpenAI organisation and project headers; Claude `anthropic-version` (the pages
  disagree on whether it is required) and `anthropic-workspace-id`; Azure resource address and deployment name;
  Google Cloud project and location; Kimi the choice between two hosts (the file cites LR-19 and LR-29: never fall
  back from one to the other); a regional host at OpenAI, Mistral and Grok; for the customer-run ones the base
  address and the model name.
- **Credentials that stop working by date:** Mistral (expiry date on the key), Claude (expiry chosen at creation),
  Aleph Alpha (at most one year; 30 days on the hosted access), STACKIT (only if a lifetime was set), LightOn
  (optional expiry), AMD AI Workbench keys for Silo AI.

### 3.3 Values a provider refuses or fixes

- **Temperature.**
  - Refused: Claude on API version `2026-10-21` (`temperature`, `top_p`, `top_k` return 400 on every model); Kimi
    (fixed per model, another value returns an error; the file cites LR-06 and LR-08).
  - Ignored or announced to be refused: Gemini ("silently ignored" on Gemini 3.6 Flash and newer, 400 "in a future
    release"). Capped: Grok (values above 1 are sampled at 1).
  - Per model: Azure OpenAI (reasoning models refuse it; the page's own table disagrees for the GPT-6 models).
    OpenAI: whether reasoning models refuse it is not confirmed.
  - Ranges as documented: OpenAI 0 to 2; Aleph Alpha 0 to 2; LightOn 0 to 2; Gemini 0 to 2 (Google Cloud: above
    0); Cohere non-negative with no upper bound stated; Mistral none published (0.0 to 0.7 recommended); Azure
    OpenAI, STACKIT and LM Studio not stated.
- **Penalties, `n`, `stop`.** Grok: `presence_penalty`, `frequency_penalty` and, on reasoning models, `stop`
  return 400. Kimi: penalties, `top_p` and `n` fixed. Azure OpenAI: reasoning models refuse the penalties, `top_p`,
  `logprobs`, `logit_bias`, `max_tokens`. OpenAI: newer models reject an explicit `n`; `stop` not on `o3` and
  `o4-mini`. Stop sequences: at most 5 at Cohere and Kimi, 4 at LightOn and Grok.
- **Maximum-tokens field names.** `max_completion_tokens`: OpenAI, Azure OpenAI, Grok, Kimi (each marks
  `max_tokens` deprecated), and the only one shown at STACKIT. `max_tokens`: Claude, Mistral, Cohere, Aleph Alpha,
  LightOn, LM Studio. `maxOutputTokens`: Gemini. What the limit counts differs: visible output only at Grok;
  reasoning included at OpenAI, Azure OpenAI, Kimi and Claude (the file cites LR-06 for Kimi: a small maximum can
  end with no usable text).
- **Required maximum tokens.** Only Claude: `max_tokens` is required and has no documented default; the per-model
  maximum is in the model list. (Task LG-14 speaks of "the adapter's documented default"; the file found none
  documented by the provider.) Nowhere else is it required.
- **Other things refused.** Aleph Alpha: `parallel_tool_calls` in any value. STACKIT: an empty `tools`. Claude: a
  last assistant message as a prefill on Claude 4.6 and later; switching thinking off on several models. Aleph
  Alpha: more than one system message. Mistral: the seed field is `random_seed`. Pleias: the models are trained
  without a system message. OpenAI and Azure OpenAI: role `developer` next to `system`.
- **Tool name rules.** Letters, digits, underscores and dashes, at most 64: OpenAI, Azure OpenAI, Aleph Alpha. At
  most 128: Claude, Kimi (must start with a letter or underscore). Gemini: the pages disagree; the file gives a
  form safe for both. Not published: Mistral, Cohere, Grok, STACKIT, LightOn, LM Studio (which normalises names;
  the file cites LR-17).

### 3.4 Tool calling differences

- **Forced tool call, per model or limited.**
  - Claude: `any` and a named tool return 400 on Claude Fable 5.2, Mythos 5.2, Opus 5.5, Sonnet 5.5, Fable 5.1 and
    Mythos 5.1, and with thinking enabled.
  - Kimi: a named function returns 400 with thinking on, which is always on the K3 series; `required` only on the
    K3 series (LR-10).
  - Cohere: `REQUIRED` or `NONE` only; one named tool cannot be forced.
  - LM Studio: `required` only on the llama.cpp engine; a named function is not documented.
  - OpenAI and Azure OpenAI: forcing is documented, but tool calling itself is per model on Chat Completions (3.1).
  - Gemini: mode `ANY` with `allowedFunctionNames`; the two ways in disagree on whether that list also works with
    `VALIDATED`.
  - Mistral: `any` and `required` both exist; the difference is not described.
  - Aleph Alpha: in the request schema, in no guide. Grok: the named form is documented for Chat Completions, the
    string values only for Responses. LightOn, STACKIT, Silo AI, Pleias, Kyutai: not confirmed.
- **Parallel calls.** A switch named `parallel_tool_calls`: OpenAI, Azure OpenAI, Mistral, Grok. A switch inside
  `tool_choice`: Claude. Several calls possible with no documented switch: Gemini, Cohere, Kimi, LM Studio (Kimi's
  file cites LR-10). The switch is rejected: Aleph Alpha. Not confirmed: STACKIT, LightOn. Azure OpenAI: not with
  structured outputs, and not on some models.
- **Single call only.** Poro 2 (Silo AI): its chat template refuses more than one call per assistant message.
- **Call ids.** Present and echoed in `tool_call_id`: OpenAI, Azure OpenAI, Cohere, Mistral (optional in the
  schema, no rule for its form), Kimi (unique only within one answer), STACKIT, LM Studio (nine digits in the
  example), Aleph Alpha by its guide. Claude: `tool_use_id`. Gemini: by `id` on the Gemini API; on Google Cloud by
  `id` only on `v1beta1`, otherwise by name and position. LightOn: no call id in the documented form.
- **Arguments.** A string holding JSON, which may be invalid: OpenAI, Azure OpenAI, Cohere, Kimi, LM Studio,
  Aleph Alpha (guide). An object: Claude (`input`), Gemini (`args`). Either: Mistral ("map or string"). STACKIT's
  tutorial shows an integer argument returned as a string.
- **The older `functions` form.** The only form in LightOn's reference; deprecated at OpenAI and Azure OpenAI.
- **Tool results.** A `tool` message per call: the chat-completions providers and Cohere (which also takes
  `document` blocks). Claude: `tool_result` blocks in one user message, directly after the assistant message. Gemini:
  `functionResponse` parts together in one `user` content, calls before results. Mistral's guide also sends
  `name`. Kimi: every call of an answer must be answered or the next request fails, so a loop cannot send a
  partial set.
- **A tool call that the finish reason does not show.** Gemini (`STOP`), Grok (`tool_calls` is not named on the
  reference), Aleph Alpha (no such value in the reference). The files advise recognising a call by its presence.
- **A tool call in a stream.** In pieces joined per index: OpenAI, Azure OpenAI, Kimi, LM Studio, Cohere
  (`tool-call-delta`), Claude (`input_json_delta`). Whole in one chunk: Grok; Gemini unless a preview option is
  set. Not documented: Mistral, Aleph Alpha, LightOn, STACKIT.
- **Text that is not a call.** LM Studio returns an unparsed tool call as ordinary content, with no error; Cohere
  sends a free-text `tool_plan` next to the calls.

### 3.5 Opaque data that must be sent back unchanged in a tool loop

- **Claude:** `thinking` blocks (with `signature`) and `redacted_thinking` blocks (with `data`); the whole
  assistant content goes back unchanged, altered blocks return 400; thinking cannot be switched off on several
  models.
- **Gemini** (both ways in): `thoughtSignature` on the part of a `functionCall` (with parallel calls only on the
  first); it must return in the same part, and a missing one is a 400 on Gemini 3 and Gemini 4 models. The file
  states the consequence: the neutral tool call must carry the call id and one opaque value back to the adapter.
- **Kimi:** `reasoning_content`, a string on the assistant message, to be sent back as received in a conversation
  and in a tool loop; what happens when it is left out is not stated. The file cites LR-07, LR-14, LR-15 and
  LR-04.
- **Mistral:** `thinking` chunks in the content (the reference also lists `signature` and `closed` on them);
  sending the whole assistant message back is advised, dropping it "significantly degrades output quality"; not
  described as an error.
- **Cohere:** the assistant message goes back as it came, with `tool_plan` and `tool_calls`; `thinking` content
  blocks exist.
- **Reasoning text that arrives but is not stated to be required back:** Azure OpenAI (`message.reasoning_content`),
  Grok (`reasoning_content`; encrypted reasoning only on the Responses API), LM Studio (`reasoning_content` or
  `reasoning`, by model and setting), Pleias (a `<think>` trace inside the answer text unless the server splits
  it; only the latest should be kept).
- **OpenAI:** none recorded for Chat Completions.

### 3.6 Structured output

- **Native, `response_format` with `json_schema`:** OpenAI, Azure OpenAI, Mistral (the guide's key is `schema`,
  the reference's `schema_definition`), Kimi, Aleph Alpha (the only accepted type), LM Studio (no `json_object`).
  Grok: documented on the Responses API; the shape on Chat Completions is not confirmed.
- **Native, in a field of the provider's own format:** Claude (`output_config.format`), Gemini (`responseFormat`,
  or `responseMimeType` with `responseSchema`; the pages disagree, see section 4), Cohere (`response_format`
  `json_object` with a schema whose key name the pages give differently).
- **By a provider-only field:** LightOn (`guided_json`, `guided_choice`, `guided_regex`; in a guide only, on the
  deprecated path).
- **Absent or not confirmed:** STACKIT, Silo AI, Pleias, Kyutai.
- **Schema keywords refused or not honoured.**
  - OpenAI (strict): `allOf`, `not`, `dependentRequired`, `dependentSchemas`, `if`/`then`/`else`; every property
    required; `additionalProperties: false`; size limits.
  - Azure OpenAI: also string, number and array limits (`minLength`, `pattern`, `format`, `minimum`, `maxItems`
    and more); at most 100 properties and five levels.
  - Claude: recursion, `oneOf`, `not`, numeric and length limits, `additionalProperties` other than `false`,
    external `$ref`; limits on strict tools and optional parameters.
  - Cohere: `allOf`, `oneOf`, `not`, numeric, length and item limits, most `format` values; every object needs a
    required field.
  - Gemini: unsupported keywords are ignored, not refused; two schema dialects (an OpenAPI subset with `ref` and
    `defs` without `$`, and a JSON Schema subset); too large or too deep is a 400.
  - Grok: a three-way list (enforced, accepted but not enforced, refused with 400).
  - Not published: Mistral, Kimi (its own dialect, often accepted with 200 and not followed), Aleph Alpha, LM
    Studio, LightOn.
- **Together with tools.** Yes: Claude; the Gemini API on Gemini 3 and 4 series (preview); Google Cloud by one
  guide; Grok by its guide, shown on the Responses API. No: Cohere. Strict tools only with parallel calls off:
  Azure OpenAI. Not confirmed: OpenAI, Mistral, Kimi, Aleph Alpha, LightOn, STACKIT, LM Studio, and the
  customer-run three. Kimi's file cites LR-10 and LR-11.
- **Not guaranteed even with a schema:** Claude on a refusal or a cut-off; Gemini ("syntactically valid JSON"; the
  file cites LR-07); Kimi (may arrive in a Markdown code block); Aleph Alpha (invalid JSON at the token limit); LM
  Studio (small models).
- **The schema itself is kept by the provider:** Claude (cached up to 24 hours, also under zero data retention),
  OpenAI (counts as system data outside data residency).

### 3.7 Finish reasons with no neutral value yet

- **Refusal by the model:** Claude `refusal` (HTTP 200, with a category); OpenAI and Azure OpenAI
  `message.refusal`; Grok `message.refusal`; Cohere only as ordinary answer text.
- **Content filter or safety block:** OpenAI and Azure OpenAI `content_filter`; Gemini `SAFETY`, `RECITATION`,
  `BLOCKLIST`, `PROHIBITED_CONTENT`, `SPII`, `MODEL_ARMOR`; a blocked prompt at Gemini is a 200 with no candidates
  (`promptFeedback.blockReason`; the file cites LR-32). As an HTTP error instead: Azure OpenAI (400, code
  `content_filter`, for the prompt), Kimi (400, type `content_filter`), Cohere (498 for a deny-listed token).
- **A name that means something else:** Aleph Alpha's `content_filter` is "the stop reason was not understood",
  not a block.
- **Errors inside a successful response:** Cohere `ERROR` and `TIMEOUT`; Mistral `error`; Gemini
  `MALFORMED_FUNCTION_CALL`, `UNEXPECTED_TOOL_CALL`, `MALFORMED_RESPONSE`, `MISSING_THOUGHT_SIGNATURE`; Azure
  OpenAI `content_filter_results.error`.
- **Other values:** Claude `pause_turn`, `model_context_window_exceeded`; Mistral `model_length`; Grok `end_turn`;
  Gemini `CONTINUATION`, `TOO_MANY_TOOL_CALLS`, `LANGUAGE`, `OTHER`; STACKIT a second field `stop_reason`.
- **No values listed at all:** LightOn. Only `length` documented: STACKIT.

### 3.8 Error handling

- **A 429 that must not be retried.** OpenAI (type `insufficient_quota`), Claude (the monthly spend cap, code
  `enforced_spend_limit_reached`), Kimi (type `exceeded_current_quota_error`; LR-32, LR-34), Mistral (a monthly
  limit). A 429 that is not the customer's limit: Mistral (no capacity), Kimi (`engine_overloaded_error`), Azure
  OpenAI (shared capacity), Google Cloud.
- **402.** No credit or a billing block: Mistral, Claude, Cohere, the Gemini API (with the same status word as its
  429).
- **400 for bad credentials.** Grok ("an incorrect API key", next to 401); the Gemini API (its example for an
  invalid key is 400 with reason `API_KEY_INVALID`, its table says 403). Claude returns 400 for a missing
  workspace header.
- **Unknown model.** 400: Mistral (code 1500). 404: Cohere, Grok, STACKIT, Kimi (also "no permission"). Not
  confirmed: OpenAI, Claude, Gemini, Azure OpenAI (unknown deployment), Aleph Alpha, LightOn, LM Studio.
- **Other statuses.** Claude 529 (overloaded) and 413; Cohere 498 and 422; Kimi 504 with an HTML body; LightOn 503
  with a maintenance body; Aleph Alpha 503 for a full queue and 500 for a time-out; the model list of AMD AI
  Workbench answers 203.
- **Retry headers and their units.** `Retry-After` in seconds: OpenAI, Claude (absent on the spend-cap 429).
  `retry-after-ms` in milliseconds: Azure OpenAI. `x-should-retry`: Claude. `Retry-After` named, unit not stated:
  Mistral, Kimi. Rate-limit headers with reset values whose format is not given: STACKIT (no `Retry-After`
  documented). None documented: Gemini, Cohere, Grok, LightOn, Aleph Alpha, LM Studio.
- **Error bodies.** An `error` object with `message`, `type`, `param`, `code`: OpenAI, Azure OpenAI (with two
  spellings of the inner field), Kimi (`code` optional, no values listed). Own shapes: Claude (`type`, `error`,
  `request_id`), Gemini (`code`, `message`, `status`, `details`), Mistral (three shapes), LightOn (flat; not
  stated to be what chat completions returns), Cohere (`message` and `id` only).
- **Missing error codes or bodies.** No code or type field: Cohere. No body documented: Grok, STACKIT, Aleph
  Alpha, LM Studio. Kimi's file cites LR-32 and LR-34 for the whole group.

### 3.9 Streaming

- **Wire formats.** Server-sent events with data-only lines and `data: [DONE]`: OpenAI, Azure OpenAI, Mistral,
  Grok, Kimi, Aleph Alpha, LightOn. Server-sent events with named events and no `[DONE]`: Claude, Cohere.
  Server-sent events only when asked with `alt=sse`: Gemini. Not stated: LM Studio (for this endpoint). Not
  confirmed at all: STACKIT, Silo AI, Pleias, Kyutai.
- **Usage in a stream.** Without asking: Claude (two events, cumulative), Cohere (`message-end`), Gemini (every
  chunk). Only with `stream_options.include_usage`, in an extra chunk with empty `choices`: OpenAI, Azure OpenAI,
  Aleph Alpha, Kimi (one page also shows it inside a choice), LM Studio (place not stated). Pages disagree:
  Mistral, Grok. Not confirmed: LightOn, STACKIT.
- **Errors inside a stream.** An `error` event after the 200: Claude. In the last event: Cohere (`delta.error`).
  "Follow the format OpenAI clients expect", no example: LM Studio. Not confirmed: OpenAI, Gemini, Mistral, Grok,
  Kimi, LightOn. A stream without its end marker is incomplete: Claude, Kimi.
- **Shapes to tolerate.** Azure OpenAI: a first chunk with empty `id` and `choices`, chunks with only filter
  results, buffered text. OpenAI: an `obfuscation` field. Mistral: `delta.content` as a list of chunks. Kimi and
  Grok: reasoning deltas before the text. Gemini: the signature in a part with empty text. Mistral closes a
  stream after 10 minutes without activity.

### 3.10 Model lists

- **In the OpenAI shape** (`{"object":"list","data":[...]}`): OpenAI (no context length, no capabilities), Azure
  OpenAI (what it lists is not confirmed), Mistral (context length and capability flags, among them
  `function_calling`), Grok (context length, reasoning values), Kimi (context length, three flags), STACKIT
  (shape not shown), LM Studio (answer not shown), the Gemini API's compatible endpoint, LightOn's deprecated v2
  (no `id`; the value to send is `technical_name`).
- **In a shape of its own:** Claude (paged; maximum input and output tokens, capabilities), the Gemini API (paged;
  token limits), Cohere (paged, on a `v1` path; context length), Aleph Alpha (`/model-settings`; `chat`, context
  size), LM Studio (`/api/v1/models`; context length, `trained_for_tool_use`), Grok (`/v1/language-models`),
  LightOn (`/api/v3/instance/models`, a bare array).
- **A catalogue, not what the credentials may call:** Google Cloud (`v1beta1` only).
- **None documented:** Pleias, Kyutai. On the customer's server: Silo AI.
- **What no list reports:** structured-output support (except Claude) and parallel calls. LM Studio's file cites
  LR-10 for reading the context length there.

### 3.11 The option not to store

- **A request field exists:** OpenAI (`store`; the pages disagree on its default, and the file advises always
  sending `false`); the Gemini API (`store`, for developer logs); Azure OpenAI (`store` on Chat Completions is an
  opt-in whose default is not stated).
- **On the Responses-style API, which stores by default, a `store` field switches it off:** OpenAI, Azure OpenAI,
  Grok (the only place Grok has such a field), Aleph Alpha's Responses API; LM Studio's own stateful endpoint has
  `store` too.
- **No request field; an arrangement on the account or organisation:** Claude, Cohere, Mistral, Kimi, Grok (a
  team setting, visible in the answer header `x-zero-data-retention`), Azure OpenAI (abuse monitoring), Google
  Cloud (a project setting for caching).
- **No request field and nothing to arrange, as documented:** STACKIT ("We do not store any customer data from
  the requests."), LightOn (stateless by its terms).
- **Not applicable, the customer runs it:** Aleph Alpha, Silo AI, Pleias, Kyutai, LM Studio. The Silo AI and
  Kyutai files cite LR-31: the adapter has nothing to send. Kimi's file cites LR-31 as well.
- **Training not off by default, or not stated:** the Gemini API's unpaid tier; Mistral's free mode; Cohere (an
  opt-out exists, the default is not stated); Kimi (not documented as off for an ordinary account; the pages of
  each platform disagree).

### 3.12 Data location and regional addresses

- **An EU option on the vendor's API, by address or setting:** OpenAI (`eu.api.openai.com`, a project created for
  the region, eligibility through sales), Mistral (`api.eu.mistral.ai`, 1.1 times the price), Google Cloud
  (location `eu` or a region), Azure OpenAI (a Data Zone or Regional deployment; a property of the deployment,
  not of the request).
- **EU by the provider's general statement:** STACKIT (Germany, by combining the address with the region page),
  LightOn ("hosted within the European Union").
- **An EU option only in another product:** Cohere (Model Vault).
- **No EU option documented:** Claude (`us` or `global` only), Grok (a US address only), the Gemini API (no region
  can be chosen).
- **Not stated at all:** Kimi (the file cites LR-19), the shared Cohere API beyond its sub-processor list, Aleph
  Alpha's hosted access by invitation.
- **At the customer:** Aleph Alpha, Silo AI, Pleias, Kyutai, LM Studio. Two caveats the files record: an
  installation can forward a model to another provider (Aleph Alpha, and LightOn's "External" models), and a
  customer's address may point at a third-party service (Kyutai's own set-up does).
- **Regional addresses change what is served:** fewer models (Mistral, Grok, OpenAI in some regions), a
  surcharge (OpenAI, Mistral, Grok, Claude's `us`), no stateful features (Mistral).
- **Different companies and law per host:** Kimi (LR-19, LR-29).

### 3.13 Capabilities that only a live check can establish

The files leave these open after reading the documentation; a person's hand-run check would settle them.

- OpenAI: tools together with `response_format`; which models still take tools on Chat Completions; the status
  for an unknown model.
- Azure OpenAI: which model a deployment reaches; which Entra scope a resource accepts; what the model list
  returns.
- Claude: the status for an unknown model.
- Gemini: the stream body without `alt=sse`; whether call ids come back on Google Cloud's `v1`; the status for an
  unknown model.
- Cohere: the schema key name (`json_schema` or `schema`); whether `api.cohere.com` and `api.cohere.ai` are
  interchangeable.
- Mistral: `schema` or `schema_definition`; arguments as string or object; tools with `response_format`; usage in
  a stream.
- Grok: the JSON of a tool call and a tool result, the finish reason with tool calls, the shape of
  `response_format` and the error body on Chat Completions; whether tools work there on `grok-4.7`.
- Kimi: tools with `response_format`; whether a sampling field with its fixed value is accepted; what happens
  without `reasoning_content`.
- STACKIT: `tool_choice`, `parallel_tool_calls`, `response_format`, streaming, the answer and error shapes.
- LightOn: whether `tools` and `tool_calls` work at all; `guided_json` on v3; finish reasons; the error body.
- Aleph Alpha: everything per installation (worker type, enabled models, the `/v1` prefix, the tool-call shape).
- LM Studio and the customer-run servers of Silo AI, Pleias and Kyutai: everything per server and per model
  (tools, forced call, structured output, streaming, sign-in).

## 4. Where the provider's own pages disagree

Briefly; each provider's file has the pages and links.

- **OpenAI:** the default of `store` on Chat Completions (`false` in the reference, "stored by default for new
  accounts" in the migration guide).
- **Claude:** whether `anthropic-version` is required (required on two pages, `optional` in the reference);
  bearer is the documented primary sign-in while most examples still show `x-api-key`.
- **Gemini:** the field names for structured output (four pages, three forms; `responseFormat` a list on one
  page and an object on another); whether `allowedFunctionNames` works with `VALIDATED`; tool name rules (three
  versions); the order of tool results on one page; the temperature lower bound on Google Cloud; `v1` "stable"
  against `generateContent` listed only under `v1beta`; finish reasons with and without a prefix; camelCase in
  the reference against snake_case in examples; abuse-monitoring retention (55 days against "a limited period").
- **Azure OpenAI:** the Entra ID scope (two values); the `api-version` values in the table against the example
  request on the same page; `temperature` on GPT-6 reasoning models; the error field `innererror` against
  `inner_error`; `prompt_filter_results` spelled `prompt_annotations` once.
- **Cohere:** the schema key (`json_schema` against `schema`); the host (`api.cohere.com` against
  `api.cohere.ai`); usage under `usage` against `meta`; the production rate limit (500 against 1,000 a minute);
  whether `strict_tools` is experimental.
- **Aleph Alpha:** the `/v1` prefix; tool calls in an answer (absent from the reference, used in the guide); the
  place of `strict` on a tool; the 200 answer declared as a list against a single object; "no SaaS offering"
  against a "SaaS-Lösung" on the marketing page and "hosted" in the release notes.
- **Silo AI:** the licence of the Poro 2 instruct models against their base; the context of Poro 2 8B Long
  Instruct (128K, 32K, 131,072); a repository name in the card's own code.
- **LightOn:** the version in the path (`/api/v3` in the reference, `/api/v2` in every chat guide); the host of
  v2 (`api.lighton.ai` against `paradigm.lighton.ai`); a v3 model list named in a deprecation notice with no page.
- **Pleias:** the site's address; the serving path per model (completions against chat completions); two source
  layouts under one name; the size of Pleias-RAG-350M.
- **Mistral:** the address of the OpenAI-compatible endpoint (`/openai/v1` against `/v1`); `tool_choice` values
  (five in the reference, three in the guide); `schema` against `schema_definition`; usage in a stream (three
  pages); the model list's example against its schema; where data is processed (no committed location, "hosted in
  the EEA", "served by us in Europe"); training (the product page against the help centre).
- **Kyutai:** the Helium backbone inside Moshi (named on the model card, not in the repository).
- **STACKIT:** the answer printed for a chat request has the older completions shape, the tutorial reads the chat
  shape.
- **Grok:** the status of Chat Completions (four wordings); usage in a stream (reference against guide); which
  models refuse the penalties; `max_tokens` against `max_completion_tokens` on the comparison page; the company
  name in running text ("SpaceXSI") against the hosts.
- **Kimi:** `name` on a tool message; `max_tokens` deprecated in the reference and used in several guides; usage
  in a stream (top level against inside a choice); a sentence about `n` next to a table that fixes it; the
  company named in the terms against the privacy policy; training, on each platform; a model list that calls
  older models deprecated on one platform and retired on the other.
- **LM Studio:** a `capabilities` list on `/api/v0/models` in the changelog, absent from the endpoint page's
  example; a `stats` object on answers per the changelog, absent from the one printed answer; the parameter list
  of the chat page lacks `tools` and `response_format`, which other pages document.

## 5. How the files were read

Most pages came through a fetch that returns extracted or summarised text, not the rendered page. Quotes in the
provider files, and those repeated here, are as that tool returned them; a quote that carries weight should be
compared with the page before it is shown to a customer. Long pages were read in parts. Facts marked "condensed
reading" or "from a search summary" in a provider file are not readings of the page.

Per provider, the pages that could not be read and the facts so marked:

- **OpenAI:** the streaming guide read covers the Responses API only; the Chat Completions tool-calling examples
  of the function-calling guide were not in the text read; part of the chat reference (stored completions) was
  not read. From a search summary, about another API: 404 with `model_not_found`.
- **Claude:** condensed readings: the request half of the Messages reference (required fields, `max_tokens`, the
  `system` field, other request fields), the stop-reasons page (its last fifth not read), and the privacy-centre
  article on retention (the 30 days). The limit of 100,000 messages is from a search summary and a condensed
  reading. Not opened: the reference page listing all error codes, the commercial terms, the privacy policy.
- **Gemini:** `ai.google.dev` and `cloud.google.com/vertex-ai` redirect to other Google hosts. Not reached: the
  tails of four long pages; the per-model availability marks on the locations page; Google's general API error
  guide. From a search summary: the express-mode `generateContent` page, and the header for an API key on Google
  Cloud's model endpoints. Three type pages returned another type or 404. The only server-sent-events example
  found for Google Cloud is on a text-to-speech page.
- **Azure OpenAI:** the Responses guide was read only through a summary of its first 100,000 characters (the
  Responses shapes, the 30-day retention, stream errors). The dated reference address returned the v1 content.
  404: the stored-completions guide and the current content-filter page (the "classic" pages were used). From a
  search summary: the stored-completions guide being deprecated; for Copilot, the endpoint path, the statement
  that production use is not supported, and the sentence "No, we don't offer an API" (its address now leads to
  another article).
- **Cohere:** from a search summary: how an Encrypted Vault is called. The two long reference pages were read in
  two parts each.
- **Aleph Alpha:** the reference viewer pages show nothing as text, so the OpenAPI file behind them was read, in
  overlapping parts; only version 4.7.0. An older reference page returned 404. The access-control page was not
  read.
- **Silo AI:** AMD's Silo AI page and three AMD blog posts timed out on every attempt; what is said about the
  Silo AI page is from a search summary. `silogen.ai` does not resolve. Several model cards were not opened.
- **LightOn:** the page reader returns content condensed. Of the OpenAPI file (795,131 characters) only the first
  100,000 were read. 404: the v3 "list available models" page and three website pages; the pricing page showed
  "Coming soon". From a search summary: the model name `alfred-4.2` in the reference example, and the sentence
  "hosted entirely on sovereign European cloud".
- **Pleias:** the page reader summarises. The Baguettotron chat template came back only in part; the library's
  source folder returned 404; twelve repositories, the other blog posts and several site pages were not read.
- **Mistral:** the privacy policy returned only its title (the 30 days in a search summary is not a reading); a
  cookbook page returned 404; the Trust Center was not read. From a search summary: the meaning of
  `tool_choice` `required`, and the description of `prompt_mode`.
- **Kyutai:** four blog posts arrived with title and date only; the privacy policy opened without readable text;
  older addresses return 404; 21 repositories were not read. Of the spin-off company's site only the home page
  was read; its footer has a link for automated readers, which was not followed.
- **STACKIT:** the API reference shows only its title as text (loaded by script); two guessed specification
  addresses returned 404; the service certificate PDF could not be extracted. From a search summary: the city of
  region `eu01`. The token lifetime rule ("never expires when no lifetime is set") comes from the reference of
  STACKIT's Go SDK, shown on the Go package index, not on a STACKIT domain.
- **Grok:** the page reader returns content in parts and shortens long quotes. 403: the status site and the data
  processing addendum. From a search summary: the host `eu-west-1.api.x.ai` as a component on the status site.
- **Kimi:** pages read as extracted text; the rate-limit tier table came back empty; the second half of the
  OpenAPI file was read only for some schemas; the chat reference of the mainland China platform was not read.
- **LM Studio:** pages read as extracted text; the whole-documentation text file was used only to search for
  words.

**One doubt to check before anyone relies on it.** The LM Studio file reports an OpenAI-compatible endpoint named
`POST /v1/decisions` (for a "decision model") and a "Jev"-compatible `POST /v1/systemone`, each seen on two pages
of the documentation as the extraction returned them ([lm-studio.md](lm-studio.md#reaching-it)). The names are
unusual, and they may be an artefact of the extracted text. They must be compared with the pages themselves
(`https://lmstudio.ai/docs/developer/openai-compat/decisions` and `https://lmstudio.ai/docs/developer/jev-compat`)
before a preset, a test or a document names them. Neither is a chat endpoint, so nothing in this overview depends
on them.

[oa-create]: https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create
[oa-overview]: https://developers.openai.com/api/reference/overview
[oa-models]: https://developers.openai.com/api/reference/resources/models/methods/list
[oa-migrate]: https://developers.openai.com/api/docs/guides/migrate-to-responses
[oa-struct]: https://developers.openai.com/api/docs/guides/structured-outputs.md
[oa-data]: https://developers.openai.com/api/docs/guides/your-data
[oa-residency]: https://developers.openai.com/api/docs/guides/data-residency
[an-overview]: https://platform.claude.com/docs/en/api/overview
[an-auth]: https://platform.claude.com/docs/en/manage-claude/authentication
[an-models]: https://platform.claude.com/docs/en/api/models/list
[an-tools]: https://platform.claude.com/docs/en/agents-and-tools/tool-use/define-tools
[an-parallel]: https://platform.claude.com/docs/en/agents-and-tools/tool-use/parallel-tool-use
[an-structured]: https://platform.claude.com/docs/en/build-with-claude/structured-outputs
[an-streaming]: https://platform.claude.com/docs/en/build-with-claude/streaming
[an-residency]: https://platform.claude.com/docs/en/manage-claude/data-residency
[an-retention]: https://platform.claude.com/docs/en/manage-claude/api-and-data-retention
[an-compat]: https://platform.claude.com/docs/en/cli-sdks-libraries/libraries/openai-sdk
[ga-gen]: https://aistudio.google.com/api-reference/generate-content/generate-content.md
[ga-fc]: https://aistudio.google.com/docs/function-calling.md?generate-content=true
[ga-so]: https://aistudio.google.com/docs/structured-output.md?generate-content=true
[ga-text]: https://aistudio.google.com/docs/text-generation.md?generate-content=true
[ga-models]: https://aistudio.google.com/api-reference/models/list-models.md
[ga-terms]: https://aistudio.google.com/docs/terms.md
[ga-logs]: https://aistudio.google.com/docs/logs-policy.md
[gc-gen]: https://docs.cloud.google.com/vertex-ai/generative-ai/docs/reference/rest/v1/projects.locations.publishers.models/generateContent
[gc-resp]: https://docs.cloud.google.com/vertex-ai/generative-ai/docs/reference/rest/v1/GenerateContentResponse
[gc-fc]: https://docs.cloud.google.com/vertex-ai/generative-ai/docs/multimodal/function-calling
[gc-so]: https://docs.cloud.google.com/vertex-ai/generative-ai/docs/multimodal/control-generated-output
[gc-loc]: https://docs.cloud.google.com/vertex-ai/generative-ai/docs/learn/locations
[gc-res]: https://docs.cloud.google.com/vertex-ai/generative-ai/docs/learn/data-residency
[gc-gov]: https://docs.cloud.google.com/vertex-ai/generative-ai/docs/data-governance
[gc-auth]: https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/migrate/openai/auth-and-credentials
[gc-list]: https://docs.cloud.google.com/gemini-enterprise-agent-platform/reference/rest/v1beta1/publishers.models/list
[az-v1]: https://learn.microsoft.com/en-us/azure/foundry/openai/api-version-lifecycle
[az-chat]: https://learn.microsoft.com/en-us/rest/api/microsoft-foundry/azureopenai/chat
[az-models]: https://learn.microsoft.com/en-us/rest/api/microsoft-foundry/azureopenai/models
[az-reason]: https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/reasoning
[az-struct]: https://learn.microsoft.com/en-us/azure/foundry/openai/how-to/structured-outputs
[az-deploy]: https://learn.microsoft.com/en-us/azure/foundry/foundry-models/concepts/deployment-types
[az-privacy]: https://learn.microsoft.com/en-us/azure/foundry/responsible-ai/openai/data-privacy
[co-chat]: https://docs.cohere.com/reference/chat
[co-stream]: https://docs.cohere.com/v2/reference/chat-stream
[co-models]: https://docs.cohere.com/reference/list-models
[co-compat]: https://docs.cohere.com/docs/compatibility-api
[co-struct]: https://docs.cohere.com/docs/structured-outputs
[co-patterns]: https://docs.cohere.com/v2/docs/tool-use-usage-patterns
[co-deploy]: https://docs.cohere.com/docs/deployment-options-overview
[co-commit]: https://cohere.com/enterprise-data-commitments
[co-sub]: https://trustcenter.cohere.com/subprocessors
[co-vault]: https://docs.cohere.com/docs/model-vault/regions
[aa-spec]: https://docs.aleph-alpha.com/COMMON/_attachments/openapi/pharia-inference/v4.7.0.json
[aa-tools]: https://docs.aleph-alpha.com/phariaai-admin-guide/latest/pharia-inference/tool-calling.html
[aa-struct]: https://docs.aleph-alpha.com/phariaai-admin-guide/latest/pharia-inference/structured-output.html
[aa-deploy]: https://docs.aleph-alpha.com/phariaai-install-config-guide/latest/installation/before-you-start/deployment-options.html
[aa-shared]: https://docs.aleph-alpha.com/phariaai-install-config-guide/latest/installation/before-you-start/shared-responsibility-model.html
[aa-rel2609]: https://docs.aleph-alpha.com/phariaai-home/latest/release-notes/pharia-ai/1.260900.0.html
[si-p2tok]: https://huggingface.co/LumiOpen/Llama-Poro-2-8B-Instruct/raw/main/tokenizer_config.json
[si-aimrun]: https://enterprise-ai.docs.amd.com/en/latest/aims/guides/deployment-guide.html
[si-wbkeys]: https://enterprise-ai.docs.amd.com/en/latest/workbench/api-keys.html
[lo-chat]: https://docs.lighton.ai/api-reference-v3/models/generate-a-chat-completion.md
[lo-quick]: https://docs.lighton.ai/en/developer-resources/api-fundamentals/quick-guide.md
[lo-struct]: https://docs.lighton.ai/en/developer-resources/chat-and-ai-models/structured-output.md
[lo-keys]: https://docs.lighton.ai/en/user-guides/user-profile/my-api-keys.md
[lo-terms]: https://docs.lighton.ai/en/support-legal/legal-and-compliance/terms-of-use.md
[lo-privacy]: https://docs.lighton.ai/en/support-legal/legal-and-compliance/privacy-policy.md
[pl-about]: https://pleias.ai/about
[pl-slm]: https://huggingface.co/PleIAs/Pleias-SLM-RAG
[mi-chat-ref]: https://docs.mistral.ai/api/endpoint/chat
[mi-models]: https://docs.mistral.ai/api/endpoint/models
[mi-chat]: https://docs.mistral.ai/inference/conversations/chat-completion
[mi-func]: https://docs.mistral.ai/inference/conversations/function-calling
[mi-custom]: https://docs.mistral.ai/inference/conversations/structured-output/custom
[mi-region]: https://docs.mistral.ai/inference/regional-inference
[mi-deploy]: https://docs.mistral.ai/inference/deployment
[mi-key]: https://docs.mistral.ai/getting-started/quickstarts/studio/activate-and-generate-api-key
[mi-zdr]: https://docs.mistral.ai/admin/monitor-comply/zero-data-retention.md
[mi-where]: https://help.mistral.ai/en/articles/347629-where-do-you-store-my-data-or-my-organization-s-data
[mi-train]: https://help.mistral.ai/en/articles/347617-do-you-use-my-user-data-to-train-your-artificial-intelligence-models
[ky-unmute]: https://raw.githubusercontent.com/kyutai-labs/unmute/main/README.md
[st-service]: https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/basics/service-description/
[st-models]: https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/basics/available-shared-models/
[st-start]: https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/getting-started/getting-started-with-shared-models/
[st-use]: https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/how-tos/use-the-models/
[st-tools]: https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/how-tos/integration-of-tool-calling/
[st-faq]: https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/faq/
[st-regions]: https://docs.stackit.cloud/platform/regions/
[gr-overview]: https://docs.x.ai/developers/api-reference
[gr-chat]: https://docs.x.ai/developers/rest-api-reference/inference/chat-completions
[gr-models]: https://docs.x.ai/developers/rest-api-reference/inference/models
[gr-func]: https://docs.x.ai/developers/tools/function-calling
[gr-struct]: https://docs.x.ai/developers/model-capabilities/text/structured-outputs
[gr-stream]: https://docs.x.ai/developers/model-capabilities/text/streaming
[gr-regions]: https://docs.x.ai/developers/regions
[gr-security]: https://docs.x.ai/developers/faq/security
[ki-overview]: https://platform.kimi.ai/docs/api/overview.md
[ki-cn-overview]: https://platform.kimi.com/docs/api/overview.md
[ki-models]: https://platform.kimi.ai/docs/api/list-models.md
[ki-tools]: https://platform.kimi.ai/docs/guide/use-kimi-api-to-complete-tool-calls.md
[ki-choice]: https://platform.kimi.ai/docs/guide/use-tool-choice.md
[ki-format]: https://platform.kimi.ai/docs/guide/response_format.md
[ki-stream]: https://platform.kimi.ai/docs/guide/utilize-the-streaming-output-feature-of-kimi-api.md
[ki-zdr]: https://platform.kimi.ai/docs/guide/zero-data-retention.md
[ki-terms]: https://platform.kimi.ai/docs/agreement/modeluse.md
[ki-privacy]: https://platform.kimi.ai/docs/agreement/userprivacy.md
[lm-compat]: https://lmstudio.ai/docs/developer/openai-compat
[lm-tools]: https://lmstudio.ai/docs/developer/openai-compat/tools
[lm-struct]: https://lmstudio.ai/docs/developer/openai-compat/structured-output
[lm-models]: https://lmstudio.ai/docs/developer/openai-compat/models
[lm-rest]: https://lmstudio.ai/docs/developer/rest
[lm-rest-list]: https://lmstudio.ai/docs/developer/rest/list
[lm-auth]: https://lmstudio.ai/docs/developer/core/authentication
[lm-changelog]: https://lmstudio.ai/docs/developer/api-changelog
[lm-offline]: https://lmstudio.ai/docs/app/offline
