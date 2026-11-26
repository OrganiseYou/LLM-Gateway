# Providers: overview

Starting point, copied from the OrganiseYou application's brief (read from the providers' documentation on 2026-11-26). Cells marked "not confirmed" or "search summary" are not facts yet. Task LG-10 reads every provider again and replaces this file; ids such as LP-n and D-n point to that brief and to the application's decisions.


Read on 2026-11-26. "not confirmed" means it was not read on a page today; no cell is filled from memory. "Search
summary" means the fact came from a search result's summary of the named page, not from opening the page.

| Provider (his words) | Hosted model API | API format | Sign-in | Tool calling | JSON-schema output | Streaming | Where data is processed | Run by |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| OpenAI Platform (ChatGPT) | yes | OpenAI's own Chat Completions (the "OpenAI format"); Responses API recommended for new projects | API key (bearer) | yes: `none`, `auto`, `required`, a named function; parallel calls on by default | yes: `response_format` `json_schema` with `strict` | not confirmed (not in the part of the reference read) | US by default; EU through a project created with EU residency and `eu.api.openai.com` (search summary of OpenAI's data-residency guide) | vendor |
| Claude (Anthropic) | yes | own Messages API (`x-api-key`, `anthropic-version`); an OpenAI-compatibility layer "not considered a long-term or production-ready solution" | API key in its own header (bearer on the compatibility layer) | yes; a forced tool call returns 400 on its newest models (list in 4.2) | yes, natively (`output_config.format`, search summary); on the compatibility layer only `json_schema` with `strict: true` | yes on the compatibility layer ("Fully supported") | inference geo only `global` or `us`; workspace geo only `us`: **no EU-only option** | vendor (also through AWS, Google Cloud, Microsoft Foundry) |
| Gemini (Google Cloud / Vertex AI) | yes, two ways: the Gemini API and Vertex AI | own format (the Gemini API's function-calling examples post to `v1beta/interactions`); an OpenAI-compatible endpoint on both, "still in beta" on the Gemini API | Gemini API: API key (`x-goog-api-key`, bearer on the compatible endpoint). Vertex AI: "Only Google Cloud Auth": a short-lived OAuth token, for example from a service account, one hour by default (search summary) | yes: `auto`, `any`, `none`, `validated`; parallel calls | on the compatible endpoint through `response_format` with a schema (beta); natively not confirmed | yes ("supports streaming responses") | Vertex AI: EU multi-region and EU jurisdictional endpoints keep ML processing in the EU, per model, including Eemshaven (`europe-west4`) (search summary). Gemini API: not confirmed | vendor |
| Microsoft Copilot / Azure OpenAI | Azure OpenAI: yes. Microsoft 365 Copilot Chat API: a preview API that answers prompts grounded in Microsoft 365 and web search, not a model API. Consumer Copilot: "No, we don't offer an API" (search summary of Microsoft's FAQ) | Azure OpenAI: the OpenAI format at `<resource>/openai/v1/`, the `model` field is the deployment name, no `api-version` on v1 (search summary). Copilot Chat API: Microsoft Graph conversations | Azure OpenAI: API key or a Microsoft Entra ID token (recommended by Microsoft) (search summary). Copilot Chat API: needs a Microsoft 365 Copilot licence per user; the sign-in was not read | Azure OpenAI: not confirmed on a page read. Copilot Chat API: no custom tools found; "only responds with textual responses" | Azure OpenAI: yes (structured outputs guide, search summary). Copilot Chat API: not confirmed | Azure OpenAI: not confirmed. Copilot Chat API: yes (server-sent events) | Azure OpenAI: an EU Data Zone deployment processes in countries of the Azure EU Data Boundary, listed with Norway and Switzerland (search summary). Copilot Chat API: "within the Microsoft 365 trust boundary" | vendor (in the customer's own Azure resource) |
| Cohere | yes | own Chat API v2 (`api.cohere.ai/v2/chat`) and a Compatibility API in the OpenAI format (`api.cohere.ai/compatibility/v1`) | API key (bearer) | yes; on the Compatibility API `parallel_tool_calls` is unsupported and `tool_choice` is not mentioned | natively `json_object` with a `schema` (not the type `json_schema`); `strict_tools` experimental | yes on the Compatibility API | standard API: not confirmed. Model Vault runs inference in the US, the EU or Canada; private deployments in a customer's own cloud (search summary) | both |
| Aleph Alpha (PhariaAI) | yes, as SaaS through STACKIT and Schwarz Digits, or installed by the customer (search summaries) | OpenAI-compatible `/chat/completions` (search summary of its docs) | not confirmed | yes, "follow the OpenAI tool calling specification", switched on per model in the worker's configuration (search summary) | constrained decoding to JSON announced (search result title only); not confirmed | not confirmed | SaaS on European infrastructure (search summary); on-premise in the customer's data centre | both |
| Silo AI (AMD) | **no hosted chat API found** | open-weight models (Poro, Viking; Apache 2.0); one Viking release served on Google Cloud (AMD blog, search summary) | n/a | not confirmed | not confirmed | n/a | wherever the customer runs the model | customer |
| LightOn (Paradigm) | yes (`paradigm.lighton.ai/api/v3/chat/completions`; v2 deprecated) | OpenAI-like; its OpenAI-compatibility guide still shows the v2 address; "Some advanced parameters may not be supported" | API key (bearer), created in the Paradigm profile | the v3 reference lists `functions` and `function_call` (OpenAI's older form), not `tools`; not confirmed whether `tools` works | `response_format` not listed in the v3 reference | yes (server-sent events) | SaaS "hosted entirely on sovereign European cloud"; on-premise and hybrid (pricing page, search summary) | both |
| Pleias | **no hosted API by Pleias found** | open-weight models on Hugging Face (Pleias-RAG 350M and 1B); Azure's model catalog lists Pleias-RAG-350M behind an OpenAI-compatible endpoint (search summary) | n/a | not confirmed | not confirmed | n/a | wherever the customer runs the model | customer |
| Mistral | yes | own Chat Completions, close to the OpenAI format; also the Conversations API used today | API key (bearer) | yes: `auto`, `any`, `none`; `parallel_tool_calls` (function-calling guide, search summary) | yes: `response_format` `json_schema` with `strict` on `/v1/chat/completions` (search summary of its structured-output page) | not confirmed today | EU (D55; `docs/security/mistral-data-terms.md`); not re-read today | vendor |
| Kyutai | **no hosted text API found** | Helium 1, a 2B-parameter multilingual LLM; Moshi, a speech-native dialogue system; Unmute, a layer that lets "any LLM" listen and speak and calls an OpenAI-compatible LLM itself; open science on GitHub and Hugging Face (kyutai.org) | n/a | not confirmed | not confirmed | n/a | wherever the customer runs the model | customer |
| STACKIT | yes (AI Model Serving, shared models) | OpenAI-compatible (`api.openai-compat.model-serving.eu01.onstackit.cloud/v1`) | AI Model Serving auth token (bearer), made in the STACKIT portal | yes on several models; never send an empty `tools` list | not confirmed | not confirmed | region `eu01` in the address; "We do not store any customer data from the requests"; the country was not stated on the pages read | vendor |
| Grok (xAI) | yes | OpenAI-compatible Chat Completions, which xAI calls "legacy"; Responses API primary | API key (bearer) | yes: `auto`, `required`, `none`, a named function; parallel on, `parallel_tool_calls: false` turns it off | yes: `response_format` `json_schema`; with tools only on "supported Grok 4 family models" | not confirmed | `eu-west-1.api.x.ai` exists but is "not a comprehensive data-residency guarantee" (search summary) | vendor |
| Kimi (Moonshot AI) | yes | OpenAI-compatible (Chat Completions and Responses) at `https://api.moonshot.ai/v1`, and Anthropic-compatible (Messages) at `https://api.moonshot.ai/anthropic`; a China address `api.moonshot.cn/v1` also exists (search summary of LiteLLM's and a Rust client's documentation) | API key (bearer) | yes, OpenAI's `tools` form; several calls at once; works with streaming; `tool_choice` not mentioned | not confirmed (`response_format` not on the pages read) | yes, also with tools | **not stated for the API.** The platform's privacy policy (operator NOVASCENT PRIVATE LIMITED) says only that data "may be transferred to and stored on servers located outside of your country of residence", and excludes Open Platform data processed for enterprise customers. Search summaries report Singapore for the Open Platform (Moonshot AI PTE. LTD.) and mainland China for the consumer Kimi app; neither confirmed on a page opened today. **Not in the EU by any statement found.** | vendor |
| LM Studio | the customer's own machine | OpenAI-compatible (`/v1/chat/completions`, `/v1/models`, `/v1/responses`) | optional token, off by default, LM Studio 0.4.0 or later (bearer) | yes; small or untrained models produce tool calls it cannot parse | yes, `json_schema`; "Not all models", especially below 7B | not confirmed | the customer's machine | customer |

Sources (opened on 2026-11-26 unless marked as search summary in the table): developers.openai.com/api/reference/resources/chat;
platform.claude.com/docs/en/api/openai-sdk; platform.claude.com/docs/en/manage-claude/data-residency;
aistudio.google.com/docs/openai; aistudio.google.com/docs/function-calling;
learn.microsoft.com/microsoft-365/copilot/extensibility/api/ai-services/chat/overview;
docs.cohere.com/docs/compatibility-api; docs.cohere.com/docs/structured-outputs;
docs.lighton.ai/api-reference-v3/models/generate-a-chat-completion;
docs.lighton.ai/en/developer-resources/api-fundamentals/openai-compatibility;
docs.stackit.cloud/products/data-and-ai/ai-model-serving/faq/ and …/basics/service-description/; kyutai.org;
docs.x.ai/docs/api-reference, …/guides/function-calling, …/guides/structured-outputs;
platform.kimi.ai/docs/api/overview.md; platform.kimi.ai/docs/agreement/userprivacy.md;
platform.kimi.com/docs/guide/use-kimi-api-to-complete-tool-calls;
lmstudio.ai/docs/developer/openai-compat, …/tools, …/structured-output, …/core/authentication. Search summaries:
OpenAI data residency; Vertex AI OpenAI compatibility, auth and data residency; Azure OpenAI v1, structured outputs
and data zones; Cohere Model Vault and private deployment; Aleph Alpha tool calling, release notes and STACKIT SaaS;
LightOn pricing; AMD and Silo AI blogs; Pleias on Hugging Face and Azure; Mistral function calling and structured
output; xAI regions; Moonshot's two API addresses and the Kimi privacy policies.

### 5.1 What this means for the package (proposal; LLM-02 decides)

This is the reading of the table that LR-20 (adapters per format), LR-22 (providers without a hosted API) and LR-24
(sign-in methods) ask for; LLM-02 confirms it.

**One adapter will not cover the list.** The coordinator expected this; the documentation confirms it. Adapters by
API format:

| Adapter | Providers |
| --- | --- |
| A. OpenAI format (Chat Completions) | OpenAI, Grok, Kimi, LM Studio, Azure OpenAI (v1), STACKIT, Aleph Alpha, the open models of Silo AI, Pleias and Kyutai when run behind an OpenAI-compatible server, "Other". Per-preset differences (the words of tool choice, `max_tokens` against `max_completion_tokens`, no empty `tools` list, the auth header) are preset data, not code in callers. |
| B. Mistral | Mistral (today's adapter, moved). Whether Mistral can use adapter A with preset data instead is LLM-02's. |
| C. Anthropic Messages | Claude. Its compatibility layer is not meant for production, so a native adapter. |
| D. Gemini | Gemini API and Vertex AI. Native, or adapter A on Google's OpenAI-compatible endpoints (beta on the Gemini API); LLM-02 tests both. |
| E. Cohere | Cohere. Native v2 for JSON with a schema and strict tools, unless LLM-02 finds the Compatibility API enough. |
| F. LightOn | Paradigm, if its chat completions take only `functions` (OpenAI's older form): a variant of A. Not confirmed. |
| G. Microsoft 365 Copilot Chat API | Only if the answer to Q5 asks for it (section 12, input to Q5). |

So **four adapters at least (A to D), up to seven.** Each adapter answers the same capability questions (LR-10).

**Sign-in kinds the vault and the settings page must hold (for LR-24):**

1. **API key as a bearer token:** OpenAI, Grok, Kimi, Mistral, Cohere, LightOn, STACKIT, the Gemini API's compatible
   endpoint, LM Studio, Aleph Alpha (not confirmed), "Other".
2. **API key in a provider's own header:** Claude (`x-api-key`), the Gemini API natively (`x-goog-api-key`), Azure
   OpenAI's key (header name not confirmed).
3. **Google Cloud service account:** Vertex AI. The JSON key is a secret in the vault; the package exchanges it for a
   one-hour access token, kept in the host's token cache and refreshed.
4. **Microsoft Entra ID application:** Azure OpenAI (tenant id, client id, client secret; the package exchanges them
   for a token). Recommended by Microsoft over keys. On Azure's v1 API no `api-version` is needed (search summary of
   Microsoft's API lifecycle page).
5. **A sign-in per Microsoft 365 user:** only if Q5 includes the Copilot Chat API (it needs a licensed user).

**No key at all** applies only to a server the customer runs without a token (LM Studio's token is off by default).
Such a server must still be at a public `https://` address (LP-11), so the product recommendation is to require a
token there.

**Fields beyond the key, per preset:** an address (LM Studio, Aleph Alpha, LightOn on-premise, the open models,
"Other"); the Azure resource address and deployment name; the Google project and location; a region or EU address
where a provider offers one (OpenAI EU project address, xAI `eu-west-1`, Vertex EU locations, Azure EU Data Zone
deployments); Anthropic's workspace header for keys not scoped to a workspace.

