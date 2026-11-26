# Aleph Alpha (PhariaAI)
Read on 2026-11-27. Hosted text chat API: not confirmed as something a new customer can sign up for and call at a
fixed address. The documented product, PhariaAI, is installed and run by the customer; its inference API
(PhariaInference, the successor of the Luminous API) is reached at the address of that installation. A hosted,
invitation-only access exists in the documentation, but with an address the customer receives by mail, not a public
one.

Every fact below was read on 2026-11-27 on the linked page of Aleph Alpha itself, unless the line says "from a
search summary" or "not confirmed". The OpenAPI file [spec] is 211,134 characters long and was read in overlapping
parts (from 0, 40,000, 95,000, 100,000, 140,000 and 200,000); the pages [ref-index] and [ref-4.7.0] only show an
empty viewer when read as text, so the file behind them was read instead. All JSON in this file is synthetic,
written by hand from the documented shapes.

Short names for the pages used below:

| Short name | Page |
|---|---|
| [spec] | https://docs.aleph-alpha.com/COMMON/_attachments/openapi/pharia-inference/v4.7.0.json (OpenAPI 3 file, `info.title` "Aleph Alpha API", `info.version` 4.7.0) |
| [ref-index] | https://docs.aleph-alpha.com/phariaai-dev-guide/latest/pharia-openapi/pharia-inference/index.html (list of reference versions; v4.7.0 is the newest listed) |
| [ref-4.7.0] | https://docs.aleph-alpha.com/phariaai-dev-guide/latest/pharia-openapi/pharia-inference/v4.7.0.html (viewer page for [spec]) |
| [api-overview] | https://docs.aleph-alpha.com/phariaai-admin-guide/latest/pharia-inference/api/index.html |
| [inference] | https://docs.aleph-alpha.com/phariaai-admin-guide/latest/pharia-inference/index.html |
| [tools-guide] | https://docs.aleph-alpha.com/phariaai-admin-guide/latest/pharia-inference/tool-calling.html |
| [structured] | https://docs.aleph-alpha.com/phariaai-admin-guide/latest/pharia-inference/structured-output.html |
| [multimodal] | https://docs.aleph-alpha.com/phariaai-admin-guide/latest/pharia-inference/concepts/multimodality.html |
| [steering] | https://docs.aleph-alpha.com/phariaai-admin-guide/latest/pharia-inference/concepts/steering.html |
| [trouble] | https://docs.aleph-alpha.com/phariaai-admin-guide/latest/pharia-inference/troubleshooting.html |
| [service-accounts] | https://docs.aleph-alpha.com/phariaai-admin-guide/latest/pharia-os/service-account-management/service-accounts.html |
| [get-token] | https://docs.aleph-alpha.com/phariaai-dev-guide/latest/get-token.html |
| [hosted-start] | https://docs.aleph-alpha.com/inference-api/latest/index.html ("Inference Getting Started", the only page of the "Inference API" section) |
| [deploy-options] | https://docs.aleph-alpha.com/phariaai-install-config-guide/latest/installation/before-you-start/deployment-options.html |
| [shared-resp] | https://docs.aleph-alpha.com/phariaai-install-config-guide/latest/installation/before-you-start/shared-responsibility-model.html |
| [install] | https://docs.aleph-alpha.com/phariaai-install-config-guide/latest/index.html |
| [connectors] | https://docs.aleph-alpha.com/phariaai-install-config-guide/latest/configuration/forward-external-apis.html |
| [rel-2511] | https://docs.aleph-alpha.com/phariaai-home/latest/release-notes/pharia-ai/1.261100.0.html (PhariaAI v1.261100.0, dated November 04, 2026; the newest release listed) |
| [rel-2609] | https://docs.aleph-alpha.com/phariaai-home/latest/release-notes/pharia-ai/1.260900.0.html (dated September 03, 2026) |
| [rel-2509] | https://docs.aleph-alpha.com/phariaai-home/latest/release-notes/pharia-ai/1.250900.0.html (dated September 10, 2025) |
| [responses] | https://docs.aleph-alpha.com/phariaai-dev-guide/latest/responses-api/index.html |
| [responses-tools] | https://docs.aleph-alpha.com/phariaai-dev-guide/latest/responses-api/tool-calling.html |
| [responses-data] | https://docs.aleph-alpha.com/phariaai-dev-guide/latest/responses-api/data-flows.html |
| [pharia-llm-access] | https://docs.aleph-alpha.com/phariaai-dev-guide/latest/pharia-llm/model-access.html |
| [pharia-llm-use] | https://docs.aleph-alpha.com/phariaai-dev-guide/latest/pharia-llm/using-the-models.html |
| [site-platform] | https://aleph-alpha.com/platform/ |
| [site-kolibri] | https://aleph-alpha.com/kolibri/ |
| [site-kolibri-signup] | https://aleph-alpha.com/signup-kolibri-api/ (read only; nothing was submitted) |
| [site-privacy] | https://aleph-alpha.com/data-privacy/ (page says last updated 4 September 2024) |

## Reaching it
- Base URL and the chat path: **no fixed vendor address is documented.** The OpenAPI file gives one server,
  `{host}/v1`, with the variable `host` defaulting to the placeholder `https://api.pharia.example.com` [spec]. The
  chat path is `POST /chat/completions` under that server, so `POST <host>/v1/chat/completions` [spec]. The guides
  use the placeholder host `https://inference-api.pharia.example.com` [structured] [tools-guide] [multimodal]. The
  hosted-access page uses `$API_URL/v1/chat/completions`, where the API URL is "The URL that was sent to you"
  [hosted-start].
- **The pages disagree on the `/v1` prefix.** With `/v1`: the server entry in [spec], the curl examples in
  [multimodal] and [hosted-start]. Without it: the curl examples in [structured] and [tools-guide]
  (`https://inference-api.pharia.example.com/chat/completions`), and their examples with an OpenAI client library
  that is given only the host as its base address. The release note of September 2026 speaks of "the legacy `/v1`
  and no-prefix routes" [rel-2609], so both forms exist; which one will stay is not stated. The preset should take
  the base address from the customer exactly as given (ending in `/v1` or not) and append `/chat/completions`.
- Hosted by the vendor, run by the customer, or both: **run by the customer, as the documentation describes it.**
  - PhariaAI is installed with Helm charts on Kubernetes, on the customer's premises or in the customer's own
    cloud (the page names STACKIT, AWS, Google Cloud and Azure); hybrid and air-gapped set-ups go through product
    support [deploy-options] [install]. The shared-responsibility page calls it "a self-hosted product"; the
    customer runs the cluster, the ingress, the GPUs, backups and updates [shared-resp].
  - SaaS: "We do not currently provide a SaaS offering for PhariaAI." The same page tells the reader to ask
    product support about managed instances and "industry vertical SaaS offerings" [deploy-options].
  - Pages that point the other way: the marketing page says the platform is available "on-premise, air-gapped oder
    in einer souveränen SaaS-Lösung" [site-platform]; the release notes label each release "on-premise and hosted"
    and mark the inference items "(on-premise)" [rel-2511] [rel-2609]; and [hosted-start] describes access by
    invitation to an API whose address is mailed to the customer, with a personal key made at
    `https://personal-tokens.solutions.stackit.run`. The company's home page offers a sign-up form for a "Kolibri
    API" [site-kolibri-signup]; that form states neither what is received, nor where it runs, nor its API format.
  - So for a preset: the customer always supplies the address. A fixed address anyone can sign up for was not
    found on any page read.
- API format: both, on one endpoint family. `/chat/completions` follows the OpenAI chat-completions format (the
  multimodality page calls it "OpenAI-compatible" [multimodal]; the guides call it with an OpenAI client library
  [structured] [tools-guide]). Next to it are the provider's own endpoints from the Luminous days: `/complete`,
  `/complete/json` (only for Luminous workers), `/embed`, `/semantic_embed`, `/evaluate`, `/explain`, `/tokenize`,
  `/detokenize`, `/translate`, `/transcribe`, `/rerank`, `/model-settings` [api-overview] [spec]. For chat with
  tools and structured output, `/chat/completions` is the only documented way [tools-guide] [structured].
  The connector page states that not all parameters of the OpenAI endpoints are supported [connectors]; the list
  is under "The OpenAI format: what is and is not accepted" below.
- A second, separate API: the "Stateful Responses API" at `<deployment>/v1/responses`, a layer in the OpenAI
  Responses format that stores conversations and can run tools on the server [responses]. It is not the chat API
  and keeps state at the provider's side of the line; it is outside this file except where noted under "Data".
- API versions or dated headers a request must send: none documented. The reference is versioned (4.7.0 is the
  newest listed [ref-index]); `GET /version` returns the running version as plain text [spec]. No version header.

## Signing in
- Method: a bearer token, `Authorization: Bearer <token>` [spec] [structured] [hosted-start]. Bodies are JSON
  (`Content-Type: application/json`; the examples also send `Accept: application/json`) [structured]. No project,
  organisation or deployment field.
- What the customer must create at the provider, in plain words. The pages describe four ways to get the token,
  depending on the installation:
  1. An administrator creates a service account in PhariaOS and makes an API token for it, with an expiry date of
     at most one year; the token is shown once [service-accounts]. Which roles the account needs to call the
     inference API is on a page not read: not confirmed.
  2. A user copies a personal bearer token from the profile menu in PhariaStudio ("Copy Bearer Token")
     [get-token]. Its lifetime is not stated there.
  3. The API itself has `POST /users/me/tokens` to create a token, and the spec's description of the bearer
     scheme says it "Can be generated in your Aleph Alpha profile" [spec].
  4. For the hosted access by invitation: an account, then a personal key from
     `https://personal-tokens.solutions.stackit.run`; "API keys are valid for 30 days and bound to you
     personally" [hosted-start]. A key that ends after 30 days does not fit a stored, long-lived credential; the
     customer would have to renew it by hand.
- Since September 2025 credentials made directly in the old identity system (Zitadel) no longer work; new ones are
  made in PhariaOS [rel-2509].

## What a request can do
| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | yes, per model and only on one worker type | `tools`: a list of `{"type": "function", "function": {"name", "description", "parameters", "strict"}}`; only `function`; at most 128. "Currently, tool calling is supported only for worker type `vllm`", and the installation's administrator must switch chat on for the model (`[queue.models."<model>".chat_task] supported = true`); a tool parser (the example uses `hermes`) is recommended. Calls are read from `choices[0].message.tool_calls`. | [spec] [tools-guide] |
| Forced tool call | yes, in the request schema | `tool_choice`: `none`, `auto`, `required`, or `{"type": "function", "function": {"name": "…"}}`. Default `none` without tools, `auto` with tools. No guide shows it in use; the tool-calling guide does not mention it. | [spec] |
| Parallel tool calls | not confirmed; **the switch must not be sent** | `parallel_tool_calls` is marked deprecated with the text "This parameter is unsupported and will be rejected." Whether a model may return several calls in one answer is not stated; every example reads only `tool_calls[0]`. | [spec] [tools-guide] |
| Structured output with a JSON Schema (and which schema keywords are refused) | yes, per model and only on one worker type | `response_format`: `{"type": "json_schema", "json_schema": {"name", "schema", "description", "strict"}}`; `name` and `schema` required; `strict` defaults to false. "Only \"json_schema\" is currently supported": there is no `json_object` and no `text` type. Only for worker type `vllm`, with `[generator.structured_output] supported_types = ["json_schema"]` in the worker's configuration. No list of refused keywords is given; one example uses `minimum` and `maximum`. If the token limit is reached first, the answer may be invalid JSON. | [spec] [structured] [rel-2509] |
| Tools together with structured output | not confirmed | No page says they may or may not be combined. The worker example in the tool-calling guide switches both on for one model, which is not a statement about one request. | [tools-guide] [structured] |
| Streaming (and whether usage arrives in the stream) | yes | `stream: true`; server-sent events; chunks with `object` `chat.completion.chunk` and `choices[].delta`; ends with `data: [DONE]`. Usage only when asked: `stream_options: {"include_usage": true}` adds one more chunk before `[DONE]` with the usage of the whole request and an empty `choices` list; otherwise `usage` is `null`. | [spec] |
| System instructions (a role, or a separate field) | a role | A message with `role: "system"`. "Only one optional \"system\" message is allowed at the beginning of the conversation." No `developer` role in the schema. | [spec] |
| Listing models | yes, in its own shape; the OpenAI shape only if the installation switched it on | `GET /model-settings`: the models this token may use, each with `name`, `status` (`available` or `unavailable`), `chat` (true when the chat endpoint serves it), `max_context_size`, `maximum_completion_tokens`, `multimodal`, `completion_type`, `embedding_type`, `aligned`, `prompt_template`, `description`, `model_card`. `GET /models_available` is deprecated. An OpenAI-shaped `GET /models` exists since PhariaAI v1.260900.0 only when the administrator sets `openaiCompatibleModelsEndpoint`; it is not in the 4.7.0 file. | [spec] [rel-2609] |

## Limits and shapes

### Request
- Required fields: `model` and `messages` [spec].
- Required fields a neutral request may lack: none. `max_tokens` is optional (minimum 1); left out, the model
  generates up to its own maximum [spec]. The field is `max_tokens`; `max_completion_tokens` does not appear in
  the request schema [spec].
- Temperature: 0 to 2, default 1; 0 always picks the most likely token [spec]. `top_p` 0 to 1, default 1 [spec].
- Other optional fields: `n` (default 1, minimum 1), `stop` (a string or a list of strings; no maximum stated),
  `frequency_penalty` and `presence_penalty` (-2 to 2), `logit_bias` (token id to -100…100), `logprobs`,
  `top_logprobs` (0 to 20) [spec].
- A query parameter of the provider's own: `?nice=true` puts the request behind concurrent ones [spec]. Not needed.
- Tool name rules: letters, digits, underscores and dashes, at most 64 characters [spec].
- Model name shape: free; it is the name the installation's administrator gave the model. The documentation's
  examples use lower-case names such as `qwen2.5-vl-32b-instruct` [multimodal] and `llama-3.1-8b-instruct`
  [steering], and on the hosted access a name with an owner prefix, `Aleph-Alpha/Kolibri-1.1` [hosted-start]. The
  model "must be eligible for chat completions" [spec]; `chat: true` in `/model-settings` tells it [spec].
- Context length: `max_context_size` per model in `/model-settings` [spec].

### Messages
Roles in a request: `system`, `user`, `assistant`, `tool` [spec]. `role` and `content` are required on every
message [spec].

| Role | `content` | Other fields |
|---|---|---|
| `system` | a string | at most one, first |
| `user` | a string, or a list of parts `{"type": "text", "text"}` and `{"type": "image_url", "image_url": {"url": "<base64 data URL>"}}` | `detail` may only be `auto`; image links to other hosts are not supported [multimodal] |
| `assistant` | a string | `tool_calls`: a list of `{"id", "type": "function", "function": {"name", "arguments"}}` |
| `tool` | a string | `tool_call_id`: the call this message answers (the schema does not make it required) |

- `name` on a message is deprecated and ignored [spec].
- Two limits a neutral request can break [spec]:
  - only one system message, and only as the first message;
  - the description of `role` says that after the system message the others must alternate between user and
    assistant, begin with user and end with user. That sentence does not mention `tool` messages, which the same
    schema allows and the tool-calling guide uses (assistant with calls, then tool, then the request is sent
    again) [tools-guide]. Read together: it describes plain conversations; how strictly an installation enforces
    it depends on the model's chat template ("content" must follow the model's chat template [spec]). Not
    confirmed beyond that.
- `content` is required also on an assistant message that only holds tool calls [spec]; whether an empty string
  is accepted there is not confirmed.

### The shape of a tool call in an answer, and of a tool result in a request
**The reference and the guide disagree.**

- The reference (4.7.0) defines the message of an answer with only `role` (`assistant`) and `content` (a string,
  not nullable), and the stream's `delta` with only `content` and an optional `role`. It has **no `tool_calls`
  in an answer or in a delta**, and its `finish_reason` list has no `tool_calls` value [spec].
- The guide reads the call from `choices[0].message.tool_calls[0]`, uses its `id` and its `function.arguments`
  (a string holding JSON), appends the assistant message as it came, and answers with a `tool` message carrying
  `tool_call_id` and string content [tools-guide]. It shows no full answer, no `finish_reason`, and no stream.
- The release note says the feature follows the usual shape: the model returns function names and parameters
  [rel-2509].

So the shape of a tool call in an answer is known only from the guide's code and from the request-side schema
`ChatRequestMessageToolCall` (`id`, `type: "function"`, `function.name`, `function.arguments` as a JSON string)
[spec]. Built from those, synthetic:

```json
{
  "id": "synthetic-answer-1",
  "object": "chat.completion",
  "created": 1790000000,
  "model": "example-chat-model",
  "system_fingerprint": "empty",
  "choices": [
    {
      "index": 0,
      "finish_reason": "stop",
      "logprobs": null,
      "message": {
        "role": "assistant",
        "content": "",
        "tool_calls": [
          {
            "id": "call-synthetic-1",
            "type": "function",
            "function": {"name": "find_order", "arguments": "{\"number\":\"1001\"}"}
          }
        ]
      }
    }
  ],
  "usage": {"prompt_tokens": 40, "completion_tokens": 12, "total_tokens": 52}
}
```

The `finish_reason` and the `content` value in this example are guesses at a place the documentation leaves open;
the adapter must recognise a tool call by the presence of `tool_calls`, not by the finish reason.

Sending the result back [tools-guide] [spec]:

```json
[
  {"role": "user", "content": "Where is order 1001?"},
  {
    "role": "assistant",
    "content": "",
    "tool_calls": [
      {
        "id": "call-synthetic-1",
        "type": "function",
        "function": {"name": "find_order", "arguments": "{\"number\":\"1001\"}"}
      }
    ]
  },
  {"role": "tool", "tool_call_id": "call-synthetic-1", "content": "{\"number\":\"1001\",\"status\":\"sent\"}"}
]
```

`strict` on a tool: the schema puts it inside `function`, next to `name` and `parameters`, default false [spec];
two of the guide's three examples do the same, its curl example puts it inside `parameters` [tools-guide]. Use the
schema's place.

### The answer
- Fields: `id`, `object` (`chat.completion`), `created` (seconds), `model`, `system_fingerprint`, `choices[]`
  (`index`, `message`, `finish_reason`, `logprobs`), `usage` (`prompt_tokens`, `completion_tokens`,
  `total_tokens`) [spec] [multimodal]. None of the top-level fields is marked required [spec].
- The reference declares the 200 answer of `/chat/completions` as a *list* of answer objects [spec]; the one full
  example in the guides shows a single object [multimodal]. Build to the single object, the OpenAI shape.
- No reasoning field is in the schema; a `reasoning_parser` can be set on a worker [tools-guide] and
  `reasoning_effort: none` was added for one model family [rel-2511], but neither field is in the 4.7.0 request
  or answer schema. Where reasoning text appears in an answer: not confirmed.

### Finish reasons and what each means
`choices[].finish_reason` [spec]:

| Value | Meaning as the reference gives it |
|---|---|
| `stop` | the model reached a natural end or a given stop sequence |
| `length` | `max_tokens` was reached; the answer is cut off |
| `content_filter` | **not a content block**: returned when the API "is unable to understand the stop reason emitted by one of the workers" |

There is no `tool_calls` value in the list (see the disagreement above). In a stream the finish reason is set only
in the last chunk [spec]. An adapter should map `content_filter` here to "unknown", not to "blocked", and should
accept a value outside the list.

### The stream
`stream: true`, server-sent events [spec]. Each chunk is an answer object with `object`
`chat.completion.chunk`, the same `id` for all chunks, and `choices[].delta` with `content` (and `role` on the
first) [spec]. `system_fingerprint` and `finish_reason` are filled only in the last chunk [spec]. With
`stream_options.include_usage` a further chunk follows with `usage` and an empty `choices` list, then
`data: [DONE]` [spec]. Synthetic:

```
data: {"id":"synthetic-1","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"role":"assistant","content":"Hel"}}]}

data: {"id":"synthetic-1","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"content":"lo"},"finish_reason":"stop"}]}

data: {"id":"synthetic-1","object":"chat.completion.chunk","choices":[],"usage":{"prompt_tokens":8,"completion_tokens":2,"total_tokens":10}}

data: [DONE]
```

How tool calls arrive in a stream is not documented (the `delta` schema has no `tool_calls`). The guide warns
that the `hermes` tool parser has had "issues with specific token handling during streaming outputs" with some
models and advises testing [tools-guide]. For a preset: treat tool calls in a stream as not confirmed.

### The OpenAI format: what is and is not accepted
From the request schema of 4.7.0 [spec]:

| Field | Status |
|---|---|
| `model`, `messages` | required |
| `tools` | accepted; only `function`; at most 128 |
| `tool_choice` | accepted: `none`, `auto`, `required`, a named function |
| `parallel_tool_calls` | **rejected** ("unsupported and will be rejected"); never send it, not even as `true` |
| `response_format` | accepted only as `json_schema`; `json_object` and `text` are not in the list |
| `stream`, `stream_options.include_usage` | accepted |
| `max_tokens` | accepted |
| `max_completion_tokens` | not in the schema |
| `temperature`, `top_p`, `n`, `stop`, `frequency_penalty`, `presence_penalty`, `logit_bias`, `logprobs`, `top_logprobs` | accepted |
| `seed`, `service_tier`, `user`, message `name` | deprecated: "unsupported and will be ignored" |
| `reasoning_effort` | not in the schema; mentioned in a release note for one model family [rel-2511] |
| `steering_concepts` | the provider's own; see below |

What the API answers to a field that is in neither list (ignored or refused): not confirmed.

### Steering and guardrail fields a neutral client must not send
- `steering_concepts`: the provider's own request field on chat and completion requests, a list of references to
  steering concepts, default an empty list, one concept at most for now [spec]. A concept is made with
  `POST /steering_concepts` (marked BETA, "may not be available in every installation") or loaded by a worker;
  worker-defined ones are named with the prefix `_worker/` [spec] [steering]. It changes the model's behaviour
  from inside; a neutral client leaves the field out. What a model without steering answers when it is sent: not
  confirmed.
- `nice` (query parameter): lowers the request's priority [spec]. Leave out.
- `aligned` in `/model-settings` is information about a model, not a request field [spec].
- No moderation, safety or guardrail field exists on `/chat/completions` in the schema [spec]. Guardrails exist
  only in the separate Responses API, where they can send content to another company's service; they are off by
  default [responses-data].

### Error body shape, error codes, rate-limit headers
- **The error body is not documented.** The 4.7.0 file declares only the 200, 201 and 204 answers; it has no
  error schema and no 4xx or 5xx answer for any endpoint [spec]. Field names of an error body: not confirmed.
- What the troubleshooting page gives [trouble]:

| Status | Meaning on the page |
|---|---|
| 503 | the model's queue is full: the request "could not be finished in a reasonable timeframe", the model is very busy, or no worker serves it. May succeed later. |
| 500 | a time-out; "often" seen together with the queue-full error |

- The same page names two words in error messages, `MODEL_PACKAGE_CONFLICT` and `INVALID_REQUEST`, both for a
  worker registering itself, not for a chat request [trouble].
- The schema speaks of a "validation error" for a completion request to a model that cannot do completions, and
  of `parallel_tool_calls` being "rejected", without a status [spec].
- Statuses for a wrong or expired token, an unknown model, a model without chat, an invalid schema, or a too-long
  prompt: not confirmed.
- Rate limits: none documented for the API itself; no `Retry-After` or rate-limit header is mentioned
  [trouble] [spec]. (The connector that forwards to another provider reads that provider's
  `x-ratelimit-reset-*` headers; that is about the installation's outgoing calls [connectors].)
- Because an installation can forward a model to another provider's OpenAI-format API [connectors], errors and
  behaviour for such a model can be that other provider's.

### Models the documentation names for text chat
The models are whatever the installation runs; the documentation has no list of chat models. Names that appear in
examples, each only as an example:

| Name | Where | Note |
|---|---|---|
| `Aleph-Alpha/Kolibri-1.1` | [hosted-start] | the only model on the hosted-access page; the company's site describes Kolibri 1.1 as its own open-weight model with tool calling [site-kolibri] |
| `qwen2.5-vl-32b-instruct` | [multimodal] | example for images in chat |
| `qwen3-32b-tool` | [responses-tools] | example in the Responses API |
| `llama-3.1-8b-instruct` | [steering] | named for steering |
| `Pharia-1-LLM-7B-control`, `Pharia-1-LLM-7B-control-aligned` | [pharia-llm-use] | the provider's own older models; their page shows local use with a Python library and no HTTP endpoint; whether they serve `/chat/completions` with tools is not confirmed (tool calling needs the `vllm` worker type [tools-guide]) |
| `luminous-base` | [multimodal] | Luminous worker; the page sends such models to `/complete`, not to chat |

## Data
- Where requests are processed, as the provider states it: in the customer's own installation. PhariaAI is "a
  self-hosted product" [shared-resp], installed on the customer's premises or in the customer's cloud
  [deploy-options]. The marketing page says: "Sie behalten volle Kontrolle über Ihre Daten und Prozesse"
  [site-platform]. No page read names a country or a data centre, because the place is the customer's choice.
  One exception to "it stays inside": an administrator can configure the installation to forward a model's
  requests to another provider's API (the page's examples are a US provider's address and a Google address in
  `europe-west1`) [connectors]; that is the installation owner's decision and is not visible in the API.
- For the hosted access by invitation ([hosted-start]) and the "Kolibri API" form ([site-kolibri-signup]): where
  requests are processed is not stated on either page. The only hint is the host of the key page
  (`…solutions.stackit.run`), which is not a statement about processing. Not confirmed.
- The option not to store requests: there is **no request field for it** on `/chat/completions` [spec], and no
  page read says the inference API stores chat requests. `GET /users/me/requests` ("Query Recent Usage") is
  deprecated [spec]. What an installation logs is the customer's matter (logging, monitoring and backups are the
  customer's in the shared-responsibility table [shared-resp]). The separate Responses API does store
  conversations, in the installation's own database, and has `store: false` to opt out [responses-data].
- Training on requests: not addressed on any page read. Neither the shared-responsibility page nor the privacy
  notice (which covers the website only) says whether the vendor receives or trains on prompts [shared-resp]
  [site-privacy]. For a self-hosted installation the vendor is not in the path of a request as the pages describe
  it; a sentence that says so in those words was not found. Not confirmed.

## Not confirmed
- A vendor-hosted chat API with a fixed, public address that a new customer can sign up for. Tried:
  [deploy-options] (no SaaS for PhariaAI), [site-platform] (a "SaaS-Lösung" is named without an address),
  [hosted-start] (address by mail, invitation), [site-kolibri-signup] (a form, no details), [rel-2511] ("hosted"
  as a label). An earlier search summary named `https://api.aleph-alpha.com/chat/completions` on an older
  reference page (`docs.aleph-alpha.com/api/pharia-inference/4.2.0/chat-completions`); that page returned 404
  today and the address is on no page read.
- Which prefix is the lasting one, `/v1` or none. Tried: [spec], [structured], [tools-guide], [multimodal],
  [rel-2609].
- The shape of a tool call in an answer and in a stream, and the finish reason of an answer with tool calls. The
  reference has neither; the guide shows only how a client reads `message.tool_calls[0]`. Tried: [spec],
  [tools-guide], [rel-2509].
- Whether several tool calls can come back in one answer. Tried: [spec], [tools-guide], [responses-tools].
- Tools together with `response_format` in one request. Tried: [spec], [tools-guide], [structured].
- Which JSON Schema keywords are refused, and what `strict` changes. Tried: [spec], [structured].
- Error body field names; statuses for a bad token, unknown model, model without chat, invalid schema, too-long
  prompt, a rejected `parallel_tool_calls`; any `Retry-After` header. Tried: [spec] (no error answers declared),
  [trouble].
- What happens to an unknown request field, and to `steering_concepts` on a model without steering. Tried:
  [spec], [steering].
- How strictly the "alternate user and assistant" rule and the single-system-message rule are enforced, and
  whether an assistant message with tool calls may have empty content. Tried: [spec], [tools-guide].
- Where reasoning text appears in an answer; whether `reasoning_effort` is a field of `/chat/completions`. Tried:
  [spec], [rel-2511], [tools-guide].
- The roles a service account needs for the inference API; the lifetime of a token copied from PhariaStudio.
  Tried: [service-accounts], [get-token]. The access-control page was not read.
- Whether the OpenAI-shaped `GET /models` is on in a given installation (it is opt-in) and its exact fields.
  Tried: [rel-2609]; not in [spec].
- Where the hosted access processes requests, whether it stores them, and whether training on requests is
  excluded anywhere in so many words. Tried: [hosted-start], [site-kolibri-signup], [site-kolibri],
  [site-platform], [site-privacy], [shared-resp]. No terms of service or data-processing page for the API was
  found.
- STACKIT or Schwarz Digits as the operator of a SaaS version of PhariaAI (the earlier overview row says so from a
  search summary). On the pages read, STACKIT appears as one of four clouds a customer can install on
  [deploy-options] and in the host name of the key page [hosted-start]; no page read says it operates a SaaS
  version.
- Whether the reference pages for versions other than 4.7.0 differ in the points above. Only 4.7.0 was read.

## Proposed overview row
For `docs/providers/overview.md`, read on 2026-11-27:

| Provider | Hosted model API | API format | Sign-in | Tool calling | JSON-schema output | Streaming | Where data is processed | Run by |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Aleph Alpha (PhariaAI) | **no public hosted API found**: PhariaAI is installed by the customer ("We do not currently provide a SaaS offering for PhariaAI"); a hosted access exists only by invitation, at an address mailed to the customer; the marketing page names a "souveräne SaaS-Lösung" without an address | OpenAI-compatible `POST <the installation's address>/v1/chat/completions` (reference 4.7.0; some guides omit `/v1`); own older endpoints next to it; a separate stateful Responses API | bearer token: a service-account token made in PhariaOS (up to one year), or a personal token; on the hosted access a personal key valid 30 days | yes, only for models on the `vllm` worker type and switched on per model; `tool_choice` `none`, `auto`, `required`, a named function; **`parallel_tool_calls` is rejected, never send it**; several calls at once not confirmed; the answer's tool-call shape is missing from the reference | yes: `response_format` only as `json_schema` (`name`, `schema`, `strict` default false), only on `vllm` workers; refused keywords not listed; with tools: not confirmed | yes, server-sent events ending in `data: [DONE]`; usage only with `stream_options.include_usage`; tool calls in a stream not documented | in the customer's own installation ("a self-hosted product"), place chosen by the customer; an administrator can forward a model to another provider; hosted access: not stated. No store or training option in a request; none needed for self-hosting; training: not addressed on the pages read | customer (hosted access by invitation: not confirmed who operates it) |

Model list: `GET /model-settings` (own shape, tells `chat` and `max_context_size`); an OpenAI-shaped `GET /models`
only where the installation switched it on.
