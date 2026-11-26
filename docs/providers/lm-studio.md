# LM Studio
Read on 2026-11-27. Hosted text chat API: no. LM Studio is a desktop application (and a daemon without a window,
`llmster`) that the customer installs and runs on their own machine or server; its local server answers chat
requests from models the customer downloaded. The vendor hosts no chat API for it. The customer gives the address of
their own server.

Every fact below was read on 2026-11-27 on the linked page of LM Studio itself, unless the line says "not
confirmed". The pages were read as extracted text, so the short quotes are as the extraction gave them; a quote that
carries weight should be checked against the page once more before it is shown to a customer. **The documentation
is thin for a preset author in three places:** the chat-completions page has a parameter list of thirteen names and
no answer shape; no page shows an error body or a status code for the OpenAI-compatible endpoints; and
`parallel_tool_calls`, `max_completion_tokens` and a forced named function are mentioned nowhere. For "parameter
semantics" the chat-completions page sends the reader to OpenAI's reference [chat], which is not LM Studio's page
and is not used as a source here. All JSON in this file is synthetic, written by hand from the documented shapes.

Short names for the pages used below:

| Short name | Page |
|---|---|
| [compat] | https://lmstudio.ai/docs/developer/openai-compat |
| [chat] | https://lmstudio.ai/docs/developer/openai-compat/chat-completions |
| [tools] | https://lmstudio.ai/docs/developer/openai-compat/tools |
| [structured] | https://lmstudio.ai/docs/developer/openai-compat/structured-output |
| [compat-models] | https://lmstudio.ai/docs/developer/openai-compat/models |
| [responses] | https://lmstudio.ai/docs/developer/openai-compat/responses |
| [decisions] | https://lmstudio.ai/docs/developer/openai-compat/decisions |
| [rest] | https://lmstudio.ai/docs/developer/rest |
| [rest-quick] | https://lmstudio.ai/docs/developer/rest/quickstart |
| [rest-chat] | https://lmstudio.ai/docs/developer/rest/chat |
| [rest-list] | https://lmstudio.ai/docs/developer/rest/list |
| [rest-stream] | https://lmstudio.ai/docs/developer/rest/streaming-events |
| [rest-stateful] | https://lmstudio.ai/docs/developer/rest/stateful-chats |
| [rest-v0] | https://lmstudio.ai/docs/developer/rest/endpoints |
| [anthropic] | https://lmstudio.ai/docs/developer/anthropic-compat |
| [anthropic-messages] | https://lmstudio.ai/docs/developer/anthropic-compat/messages |
| [jev] | https://lmstudio.ai/docs/developer/jev-compat |
| [auth] | https://lmstudio.ai/docs/developer/core/authentication |
| [server] | https://lmstudio.ai/docs/developer/core/server |
| [settings] | https://lmstudio.ai/docs/developer/core/server/settings |
| [network] | https://lmstudio.ai/docs/developer/core/server/serve-on-network |
| [ttl] | https://lmstudio.ai/docs/developer/core/ttl-and-auto-evict |
| [headless] | https://lmstudio.ai/docs/developer/core/headless |
| [lmlink] | https://lmstudio.ai/docs/developer/core/lmlink |
| [changelog] | https://lmstudio.ai/docs/developer/api-changelog |
| [cli-start] | https://lmstudio.ai/docs/cli/serve/server-start |
| [offline] | https://lmstudio.ai/docs/app/offline |
| [privacy] | https://lmstudio.ai/app-privacy (the application's privacy policy; the page says "Effective June 2026", last updated 10/19/2026) |
| [full] | https://lmstudio.ai/llms-full.txt (the whole documentation as one text file; used only to search for a word in all pages at once) |

## Reaching it
- Base URL and the chat path: `http://localhost:1234/v1`, chat at `POST /v1/chat/completions` [compat] [chat]. The
  native REST API has the base `http://localhost:1234` and its paths start with `/api/v1` [rest-quick].
- The port: the examples "assume port 1234" [compat]; the SDK text names it the "default port `1234`" [full]; the
  quickstart calls `http://localhost:1234` the default base URL [rest-quick]. The customer changes it in the
  application's server settings ("Server Port") [settings] or with `lms server start --port <number>`; without the
  flag the server takes the port used last [cli-start]. So a preset can suggest the address but must let the
  customer type it.
- The scheme is `http`. **No page read mentions HTTPS or TLS for the local server** [network] [settings]
  [cli-start]. A customer who needs `https://` has to put their own proxy in front; the documentation does not
  describe that.
- Other machines: by default the server listens on `127.0.0.1` only. The setting "Serve on Local Network"
  [settings], or `lms server start --bind 0.0.0.0` (also the environment variable `LMS_SERVER_HOST`), makes it
  listen on the network; the application then shows the address other devices use [network] [cli-start]. The
  documentation warns that any address other than `127.0.0.1` exposes the server beyond the machine and recommends
  switching authentication on [network] [cli-start]. Cross-origin access (CORS) is off by default; "Enable CORS" or
  `--cors` switches it on [settings] [cli-start].
- Starting it: the "Start server" switch in the Developer tab, or `lms server start` [server]. Without a window:
  the `llmster` daemon (`lms daemon up`), which the documentation recommends for servers, or the desktop
  application started at login and minimised to the tray [headless].
- Hosted by the vendor, run by the customer, or both: run by the customer. "LM Link" lets a request to `localhost`
  be served by a model on another of the customer's devices on the same link; the request is still sent to
  `localhost` in the same way [lmlink]. The application also sells optional cloud features (cloud models, web
  search) [privacy]; whether those can be reached through the local server's API is not confirmed.
- API format: several, on the same server and port.

  | Format | Paths | Status as documented | Link |
  |---|---|---|---|
  | OpenAI-compatible | `GET /v1/models`, `POST /v1/chat/completions`, `POST /v1/responses`, `POST /v1/embeddings`, `POST /v1/completions` (legacy), `POST /v1/decisions` (needs a "decision model", not a chat model) | No beta or deprecation label. Tool calling since 0.3.6, `/v1/responses` since 0.3.29. | [compat] [decisions] [changelog] |
  | Its own REST API v1 | `POST /api/v1/chat`, `GET /api/v1/models`, `POST /api/v1/models/load`, `/unload`, `/download`, `GET /api/v1/models/download/status/{job_id}` | Released with 0.4.0; recommended over v0. Stateful chats, MCP servers, API tokens, model management. | [rest] [rest-quick] [changelog] |
  | Its own REST API v0 | `GET /api/v0/models`, `GET /api/v0/models/{model}`, `POST /api/v0/chat/completions`, `/completions`, `/embeddings` | Older (0.3.6 or later); the page recommends v1 "for new projects". Chat here has the OpenAI shape plus statistics fields. | [rest-v0] |
  | Anthropic-compatible | `POST /v1/messages` | Since 0.4.1. Tools (`input_schema`), `tool_choice` such as `{"type": "any"}`, streaming with the Messages event names. | [anthropic] [anthropic-messages] [changelog] |
  | "Jev"-compatible | `POST /v1/systemone` (typed decisions) | Not a chat API. | [jev] |

- Which one the documentation recommends: for new projects the native v1 API over v0 [rest] [rest-v0]; the
  quickstart uses `/api/v1/chat` throughout and names the compatible endpoints as options, without a
  recommendation between them [rest-quick]. **For this library the OpenAI-compatible chat completions is the
  fitting one**, by the documentation's own comparison table [rest]: `/api/v1/chat` takes no custom tools and no
  assistant messages in a request (it runs tools itself through MCP and keeps the conversation on the server),
  while `/v1/chat/completions` takes custom tools and assistant messages and keeps no state. `/v1/responses` and
  `/v1/messages` also take custom tools; `/v1/responses` is stateful through `previous_response_id` [responses].
- API versions or dated headers a request must send: none documented for `/v1/chat/completions`; the examples send
  only `Content-Type: application/json` [compat]. The `/v1/messages` pages do not mention a version header either
  [anthropic] [anthropic-messages].

## Signing in
- Method: **none by default.** "By default, LM Studio does not require authentication for API requests." [auth];
  the quickstart says the same [rest-quick]. The curl example for chat completions sends no `Authorization` header
  at all [compat], so a request without that header is accepted by a server in its default state. The client
  library examples pass a made-up key (`lm-studio`) only because the client library wants a value [chat] [tools].
- Optional API token, LM Studio 0.4.0 or later: the customer switches on "Require Authentication" in the Developer
  page under Server Settings; from then on requests must carry a valid token [auth] [settings]. The header is
  `Authorization: Bearer <token>` [auth] [settings] [rest-quick]. On `/v1/messages` the token is also accepted in
  `x-api-key` [anthropic].
- Whether the token rule also covers the `/v1/...` OpenAI-compatible paths: the authentication page names the REST
  API and the two SDKs [auth]; the setting's description says "API clients" in general [settings]; the
  Anthropic-compatible page states it for `/v1/messages` [anthropic]. Not stated in so many words for
  `/v1/chat/completions`: not confirmed, though nothing suggests an exception.
- What the server answers when the token is missing or wrong (status, body): not confirmed. No page gives it, and
  the words "401" and "Unauthorized" occur nowhere in the documentation [full].
- What the customer must create, in plain words: nothing at a vendor. On their own machine: install LM Studio (or
  `llmster`), download a model, start the server. For a token: Server Settings, "Manage Tokens", "Create Token",
  a name and its permissions, then copy it at once because it is not shown again [auth]. The permission choices
  are not listed on the page. Tokens have no documented lifetime.
- Two settings need the token: local MCP plugins through the API ("Allow calling servers from mcp.json" requires
  "Require Authentication") [settings] [rest-quick]. Not used by this library.

## What a request can do
All rows are about `POST /v1/chat/completions` unless they say otherwise.

| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | Yes, since 0.3.6, for every model, but with two grades. **Native**: the model's chat template supports tools and LM Studio knows the model's tool format (a hammer badge in the application; the page lists Qwen 2.5 Instruct, Llama 3.1 and 3.2, a Mistral model, "subject to change"). **Default**: for every other model LM Studio adds its own system prompt and a default call format (the example shows `[TOOL_REQUEST]{...}[END_TOOL_REQUEST]`), turns `tool` messages into `user` messages and rewrites assistant `tool_calls` into that format; results vary by model and the format may change. In both grades the model writes text and LM Studio parses it. **When nothing parses, the text is returned as ordinary `message.content`**, with no error; small models and models not trained for tools may write broken calls. | `tools` in OpenAI's function format (`type: "function"`, `function.name`, `function.description`, `function.parameters`) | [tools] [changelog] |
| Forced tool call | Partly. `tool_choice` since 0.3.15 with three values: `"none"`, `"auto"`, `"required"`; `"required"` is marked "llama.cpp only", so not for models in the MLX format. **A named function (`{"type":"function","function":{"name":...}}`) is not documented**: not confirmed. The tool-use page itself does not mention `tool_choice` at all. | `tool_choice` | [changelog] [tools] |
| Parallel tool calls | An answer may hold several calls: the flow says the model may "Call one or more tools", the examples loop over the list, and two changelog entries fix "parallel tool calls" in streams (0.3.18, 0.3.19). **The request field `parallel_tool_calls` occurs nowhere in the documentation**; switching several calls off is not confirmed. | `message.tool_calls[]` (several entries); no request field documented | [tools] [changelog] [full] |
| Structured output with a JSON Schema (and which schema keywords are refused) | Yes: `response_format` with `type: "json_schema"` and `json_schema` holding `name`, `schema` and optionally `strict` (the curl example writes it as the text `"true"`; its effect is not explained). Enforced by the engine: grammar-based sampling of llama.cpp for GGUF models, Outlines for MLX models. The answer is JSON as text in `choices[0].message.content`. `response_format.type` also accepts `"text"` (0.3.18). **`json_object` occurs nowhere in the documentation.** No list of refused schema keywords. Warning: "Not all models are capable of structured output, particularly LLMs below 7B parameters."; what such a model returns (an error, or text that does not fit) is not said. | `response_format.type`, `response_format.json_schema.name`, `.schema`, `.strict` | [structured] [changelog] [full] |
| Tools together with structured output | **Not confirmed.** Neither page describes the combination; each says only that all parameters of `/v1/chat/completions` are honoured. | not documented | [structured] [tools] |
| Streaming (and whether usage arrives in the stream) | Yes: `stream: true`; the comparison table marks streaming for all four inference endpoints. Usage arrives in the stream only when asked: `stream_options.include_usage: true` (0.3.18) returns prompt and completion tokens. Tool calls stream as `delta.tool_calls[]` with an `index`: the first piece carries `id` and `function.name`, later pieces carry parts of `function.arguments`. Since 0.3.15 a streamed answer ends with `finish_reason: "tool_calls"` when it holds calls. **Wire format: not stated for this endpoint** (server-sent events are stated for `/v1/responses`, `/v1/messages` and `/api/v1/chat`); the closing `[DONE]` line occurs nowhere in the documentation. Stream errors "follow the format OpenAI clients expect" (0.3.18), without an example. | `stream`, `stream_options.include_usage` | [chat] [rest] [tools] [changelog] [responses] [full] |
| System instructions (a role, or a separate field) | A message with the role `system` in `messages` (the example has one). The model's prompt template is applied by the server. On the native `/api/v1/chat` it is a separate field, `system_prompt`. | `messages[].role: "system"` | [chat] [rest-chat] |
| Listing models | Yes, three lists. `GET /v1/models`: models "visible to the server"; with Just-in-Time loading on, all downloaded models, with it off only the loaded ones; no example answer on the page. `GET /api/v1/models`: the rich list (below). `GET /api/v0/models`: OpenAI-shaped with extra fields (below). | see "The model lists" | [compat-models] [headless] [rest-list] [rest-v0] |

Request fields of `/v1/chat/completions`, as LM Studio's own pages name them:

| Field | Documented | Link |
|---|---|---|
| `model`, `messages`, `temperature`, `top_p`, `max_tokens`, `stream`, `stop`, `presence_penalty`, `frequency_penalty`, `logit_bias`, `seed` | yes: the "Supported payload parameters" list | [chat] |
| `top_k`, `repeat_penalty` | yes, in the same list; not fields of the plain OpenAI format | [chat] |
| `tools` | yes, on its own page (not in the list above) | [tools] |
| `tool_choice` | `"none"`, `"auto"`, `"required"` (llama.cpp only), in the changelog only | [changelog] |
| `response_format` | `json_schema` and `text` | [structured] [changelog] |
| `stream_options.include_usage` | yes, in the changelog only | [changelog] |
| `ttl` | yes: seconds a model loaded by this request may stay idle (see below) | [ttl] |
| `draft_model` | yes: speculative decoding with a second model (0.3.10) | [changelog] |
| `max_completion_tokens` | **not mentioned anywhere** | [full] |
| `parallel_tool_calls` | **not mentioned anywhere** | [full] |
| `n`, `logprobs`, `top_logprobs`, `user`, `store`, `metadata`, `service_tier`, `reasoning_effort` | not mentioned for this endpoint | [chat] |
| a named function in `tool_choice` | not mentioned | [changelog] |

The list on [chat] is not complete by the documentation's own pages (`tools` and `response_format` are missing from
it), and no page lists fields that are refused. What the server does with a field it does not know (ignore or
reject) is not confirmed.

## Limits and shapes
- Required fields a neutral request may lack: none documented beyond `model` and `messages`. **The maximum-tokens
  field is `max_tokens`** [chat]; a v0 example sends `"max_tokens": -1` [full], whose meaning is not explained. On
  the native `/api/v1/chat` the field is `max_output_tokens` [rest-chat]; on `/v1/messages` it is `max_tokens`
  [anthropic-messages].
- Temperature range: not stated for `/v1/chat/completions`. The native `/api/v1/chat` gives 0 to 1 [rest-chat].
- Tool name rules: none stated. Since 0.3.23 tool names are "normalized (e.g., snake_case)" before they reach the
  model [changelog]; whether the name in the returned call is the caller's original name or the normalised one is
  not confirmed. An adapter must match returned names leniently or report an unknown name to the model (LR-17).
- Model name shape: the model's identifier in LM Studio, as the customer sees it there: `<publisher>/<model>`
  (examples on the pages: `ibm/granite-4-micro`, `openai/gpt-oss-20b`) or a plain name
  (`deepseek-r1-distill-qwen-7b`); a variant can follow after `@` (`...@q4_k_m`) [rest-list] [ttl] [responses].
  The list differs per customer, so a preset holds no model list.
- **A model that is not loaded.** With "Just in Time Model Loading" on (the default), the first request that names
  a downloaded model loads it, then answers; that first answer takes longer by the loading time [ttl] [headless]
  [rest-quick]. With it off, a model must be loaded before use [headless]; what the server answers then is not
  confirmed. A model loaded this way is unloaded after 60 idle minutes by default; a request may set another
  time with `ttl` (seconds, for example `"ttl": 300`), on the OpenAI-compatible and the REST API alike [ttl]
  [changelog]. "Auto-Evict" (on by default; setting "Only Keep Last JIT Loaded Model") unloads the previous
  just-in-time model before the next is loaded, so two presets that name different models on one server make
  each other's model reload [ttl] [settings]. A caller's timeout must allow for loading. Only `/api/v1/chat`
  reports loading: `model_load.*` stream events and `stats.model_load_time_seconds` [rest-stream] [rest-chat].
- The shape of a tool call in an answer: OpenAI's. `choices[0].message.tool_calls[]`, each with `id`, `type:
  "function"` and `function` holding `name` and `arguments`, the arguments as JSON text [tools]. The id in the
  page's example is a string of nine digits [tools], so an adapter must not expect a `call_` prefix.
- The shape of a tool result in a request: first the assistant message with its `tool_calls`, then one message per
  result with `role: "tool"`, `content` (text, for example JSON) and `tool_call_id` equal to the call's `id`
  [tools]. For a model with default tool support the server turns these into `user` messages itself [tools].
- The shape of an answer: shown only in the tool example: `id` (`chatcmpl-...`), `choices[]` with `message`,
  `logprobs` (null) and `finish_reason`, `usage`, and `system_fingerprint`, which holds the model's name [tools].
- Usage: `usage.prompt_tokens`, `usage.completion_tokens`, `usage.total_tokens` [tools] [rest-v0]. Since 0.3.17
  the counts include the system prompt and the tool definitions [changelog]. In a stream only with
  `stream_options.include_usage` [changelog].
- Extra statistics fields: the changelog says answers carry a `stats` object with `tokens_per_second`,
  `draft_model` and counts of draft tokens (0.3.10) [changelog]. On `/api/v0/chat/completions` an answer has
  `stats` (`tokens_per_second`, `time_to_first_token`, `generation_time`, `stop_reason`), `model_info` (`arch`,
  `quant`, `format`, `context_length`) and `runtime` (`name`, `version`, `supported_formats`) next to the OpenAI
  fields [rest-v0]. The one `/v1/chat/completions` answer printed (tool page) has no `stats` [tools]. An adapter
  must ignore unknown top-level fields and must not need them.
- Reasoning text arrives outside `content`, in a field whose name depends on the model and a setting:
  `message.reasoning_content` for DeepSeek R1 models when switched on in the application's Developer settings
  (0.3.9); `message.reasoning` and `delta.reasoning` for `gpt-oss` models (0.3.23) [changelog]. With the setting
  off, such a model's thinking may sit inside `content`.
- Finish reasons: `tool_calls` (the answer holds tool calls, also in a stream since 0.3.15) [tools] [changelog];
  `stop` and `length` appear in the v0 examples [rest-v0] [full]. No page lists the values or explains them;
  `content_filter` or others: not confirmed. An answer cut off at `max_tokens` giving `length` is the usual
  meaning but is not stated on a page.
- Error body shape and statuses: **not documented for the OpenAI-compatible endpoints.** No page shows an error
  body, a status code or an error code for `/v1/chat/completions`; "401", "403", "404" and "model_not_found" as
  an HTTP answer occur nowhere [full]. The only documented error shape is the `error` event in a stream of the
  native `/api/v1/chat`: `{"type": "error", "error": {"type", "message", "code"?, "param"?}}` with `error.type`
  one of `invalid_request`, `unknown`, `mcp_connection_error`, `plugin_connection_error`, `not_implemented`,
  `model_not_found`, `job_not_found`, `internal_error` [rest-stream]. Whether the compatible endpoints use the
  same words is not confirmed.
- Rate limits and rate-limit headers: none documented; it is the customer's own machine. Requests may queue: the
  command `lms ps --json` reports "the number of queued prediction requests" per model [changelog].
- Provider-only fields a neutral client must not send by default: `ttl`, `draft_model`, `top_k`,
  `repeat_penalty` (accepted here, not part of the plain format) [chat] [ttl] [changelog]. Fields of the plain
  format that nothing here documents and that should therefore be left out unless a live check shows they work:
  `max_completion_tokens`, `parallel_tool_calls`, a named function in `tool_choice`, `response_format` of the
  type `json_object`, `n`, `logprobs`, `store`. `tool_choice: "required"` only for a model on the llama.cpp
  engine (GGUF format) [changelog]; the format of a model is in the rich model list (`format`) [rest-list].

### The model lists
- `GET /v1/models` (OpenAI-shaped): the answer is not shown on the page [compat-models]. Which fields it has
  beyond the plain format is not confirmed. It gives no context length and no capabilities, as far as documented.
- `GET /api/v1/models` (the rich one), answer `{"models": [...]}`; each model has `type` (`llm`, `embedding`,
  `decision`), `publisher`, `key` (the identifier to send as `model`), `display_name`, `architecture`,
  `quantization` (`name`, `bits_per_weight`), `size_bytes`, `params_string` (such as "7B"), `loaded_instances[]`
  (each with `id` and `config`, which holds `context_length` among others; an empty list means not loaded),
  `max_context_length`, `format` (`gguf`, `mlx`), `capabilities` (`vision`, `trained_for_tool_use`, and
  `reasoning` with `allowed_options` and `default`), `description`, `variants`, `selected_variant` [rest-list].
  This is where the context length (LR-10) and "trained for tool use" can be read; the loaded context length
  (`loaded_instances[].config.context_length`) can be smaller than `max_context_length`.
- `GET /api/v0/models`: `{"object": "list", "data": [...]}`; each item has `id`, `object`, `type` (`llm`, `vlm`,
  `embeddings`), `publisher`, `arch`, `compatibility_type` (`gguf`, `mlx`), `quantization`, `state` (for example
  `not-loaded`) and `max_context_length` [rest-v0]. The changelog (0.3.16) adds a `capabilities` list per model,
  for example `"tool_use"`; the endpoint page's example does not show it [changelog] [rest-v0]. The two pages
  disagree; both are recorded.

Synthetic example of a documented request with a tool and a time-to-live left out (plain fields only):

```json
{
  "model": "example-publisher/example-model-7b",
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "What is the weather in Utrecht?"}
  ],
  "tools": [
    {
      "type": "function",
      "function": {
        "name": "get_weather",
        "description": "Weather for a city.",
        "parameters": {
          "type": "object",
          "properties": {"city": {"type": "string"}},
          "required": ["city"]
        }
      }
    }
  ],
  "tool_choice": "auto",
  "max_tokens": 200,
  "temperature": 0.2
}
```

sent as `POST http://localhost:1234/v1/chat/completions` with `Content-Type: application/json`, and with
`Authorization: Bearer <token>` only when the customer switched authentication on. A synthetic answer in the
documented shape:

```json
{
  "id": "chatcmpl-example0001",
  "object": "chat.completion",
  "model": "example-publisher/example-model-7b",
  "choices": [
    {
      "index": 0,
      "logprobs": null,
      "finish_reason": "tool_calls",
      "message": {
        "role": "assistant",
        "tool_calls": [
          {
            "id": "123456789",
            "type": "function",
            "function": {"name": "get_weather", "arguments": "{\"city\":\"Utrecht\"}"}
          }
        ]
      }
    }
  ],
  "usage": {"prompt_tokens": 120, "completion_tokens": 18, "total_tokens": 138},
  "system_fingerprint": "example-publisher/example-model-7b"
}
```

## Data
- Where requests are processed, as the provider states it: on the customer's own machine. The offline page says of
  the local server that requests "stay local", and of chatting: "Nothing you enter into LM Studio when chatting
  with LLMs leaves your device." [offline]. The privacy policy says for local use: "none of your messages, chat
  histories, and documents are ever transmitted from your system." [privacy]. Where that machine stands is the
  customer's matter; with LM Link the model may run on another device of the same customer [lmlink] (whether that
  traffic passes the vendor's servers is not stated on the page read).
- Telemetry: "the application does not include telemetry or user-specific tracking." [privacy]. What does go out,
  none of it chat content: an update check when the application starts (application version, build, operating
  system, and the IP address the delivery network sees), model search and downloads (for example to
  huggingface.co; anonymised search queries), and downloads of runtimes [privacy] [offline].
- The option not to store requests, and whether training on them is off by default: `/v1/chat/completions` keeps
  no conversation (the comparison table marks "stateful chat" as absent for it) [rest], so there is nothing to
  send. The stateful endpoints do store on the customer's machine: `/api/v1/chat` has `store`, default `true`,
  and `store: false` switches it off [rest-chat] [rest-stateful]; `/v1/responses` keeps state for
  `previous_response_id` [responses]. An adapter for chat completions sends no such field. Training: the vendor
  receives no local requests, so there is nothing to train on; the policy does not speak of training for local
  use, and says of the optional cloud features that data "is not used for training or any other purpose"
  [privacy].
- Logs on the customer's machine: `lms log stream` shows the text sent to the model and its output [chat]
  [changelog]. Anyone with access to that machine can read requests there. How long the application keeps logs is
  not stated.

## Not confirmed
- **The error body and the statuses of `/v1/chat/completions`**: an unknown model, a model that is not loaded with
  Just-in-Time loading off, a bad request, a schema the engine cannot use, a missing or wrong token, a server
  fault. Tried: [chat], [compat], [tools], [structured], [auth], [changelog], and a word search of [full] for
  "401", "403", "404", "Unauthorized", "invalid_api_key", "model_not_found", `"error"`. Only the stream error
  event of `/api/v1/chat` is documented.
- `parallel_tool_calls` as a request field, and any way to hold a model to one call per answer. Searched [full].
- A named function in `tool_choice`. Only `none`, `auto`, `required` are in the changelog entry [changelog].
- `max_completion_tokens`: whether it is accepted next to `max_tokens`. Searched [full].
- `response_format` of the type `json_object`. Searched [full].
- Tools together with `response_format` in one request.
- What a model that cannot follow a schema returns, and which schema keywords the two engines refuse.
- The effect of `json_schema.strict`.
- The wire format of a chat-completions stream (server-sent events, the `[DONE]` line) and the exact place of
  usage in it (a last chunk with empty `choices`, as in the plain format, or otherwise).
- The full list of finish reasons and their meaning.
- Whether `stats` is present on every `/v1/chat/completions` answer; the changelog says so for 0.3.10, the one
  printed answer has none.
- What the server does with a request field it does not know.
- The answer shape of `GET /v1/models`.
- Whether "Require Authentication" covers the OpenAI-compatible paths in so many words, and the answer to a
  request without a token when it is on.
- The default values of the server settings, other than: authentication off [auth], Just-in-Time loading on
  [ttl], Auto-Evict on [ttl], CORS off and bind `127.0.0.1` [cli-start]. The settings page gives no defaults.
- The temperature range and tool name rules of `/v1/chat/completions`; whether a returned tool name is the
  normalised or the original one.
- HTTPS for the local server.
- Whether LM Link traffic passes the vendor's servers, and whether the application's cloud models can be reached
  through the local server.
- The exact wording of the quoted sentences: the pages were read as extracted text; see the note at the top.
- No page read today addressed the reader with instructions.
