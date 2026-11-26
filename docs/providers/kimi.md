# Kimi (Moonshot AI)

Read on 2026-11-27. Hosted text chat API: yes.

Every fact below was read on 2026-11-27 on the page linked next to it. Only the provider's own pages were used
(`platform.kimi.ai`, `platform.kimi.com`, `www.kimi.com`). The pages were read as extracted text, not as rendered
HTML; long pages (the chat reference, the OpenAPI file) were read in parts. Quotes are short and as the page gave
them; Chinese quotes carry a translation. Where a page is silent the cell says "not confirmed". Model names are
written as the pages give them. All examples are synthetic.

Short names for the pages:

International platform (English, `platform.kimi.ai`):

- [INDEX]: https://platform.kimi.ai/docs/llms.txt
- [OVERVIEW]: https://platform.kimi.ai/docs/api/overview.md
- [REF-CHAT]: https://platform.kimi.ai/docs/api/chat.md
- [OPENAPI]: https://platform.kimi.ai/docs/openapi.json
- [REF-PARAMS]: https://platform.kimi.ai/docs/api/models-overview.md
- [REF-ERRORS]: https://platform.kimi.ai/docs/api/errors.md
- [REF-MODELS]: https://platform.kimi.ai/docs/api/list-models.md
- [MODELS]: https://platform.kimi.ai/docs/models.md
- [G-TOOLS]: https://platform.kimi.ai/docs/guide/use-kimi-api-to-complete-tool-calls.md
- [G-CHOICE]: https://platform.kimi.ai/docs/guide/use-tool-choice.md
- [G-K3TOOLS]: https://platform.kimi.ai/docs/guide/kimi-k3-tool-calling-best-practice.md
- [G-OFFICIAL]: https://platform.kimi.ai/docs/guide/use-official-tools.md
- [G-SEARCH]: https://platform.kimi.ai/docs/guide/best-practices-for-web-search.md
- [G-FORMAT]: https://platform.kimi.ai/docs/guide/response_format.md
- [G-JSON]: https://platform.kimi.ai/docs/guide/use-json-mode-feature-of-kimi-api.md
- [G-STREAM]: https://platform.kimi.ai/docs/guide/utilize-the-streaming-output-feature-of-kimi-api.md
- [G-THINK]: https://platform.kimi.ai/docs/guide/use-thinking-models.md
- [G-PARTIAL]: https://platform.kimi.ai/docs/guide/use-partial-mode-feature-of-kimi-api.md
- [G-K3]: https://platform.kimi.ai/docs/guide/kimi-k3-quickstart.md
- [G-K31]: https://platform.kimi.ai/docs/guide/kimi-k3-1-pro-quickstart.md
- [G-KEY]: https://platform.kimi.ai/docs/get-your-api-key.md
- [G-TROUBLE]: https://platform.kimi.ai/docs/guide/troubleshooting.md
- [G-ZDR]: https://platform.kimi.ai/docs/guide/zero-data-retention.md
- [G-LIMITS]: https://platform.kimi.ai/docs/pricing/limits.md
- [FEATURES]: https://platform.kimi.ai/docs/features.md
- [TERMS]: https://platform.kimi.ai/docs/agreement/modeluse.md
- [PRIVACY]: https://platform.kimi.ai/docs/agreement/userprivacy.md

Platform for mainland China (Chinese, `platform.kimi.com` and the help centre on `www.kimi.com`):

- [CN-OVERVIEW]: https://platform.kimi.com/docs/api/overview.md
- [CN-ERRORS]: https://platform.kimi.com/docs/api/errors.md
- [CN-MODELS]: https://platform.kimi.com/docs/models.md
- [CN-TERMS]: https://platform.kimi.com/docs/agreement/modeluse.md
- [CN-PRIVACY]: https://platform.kimi.com/docs/agreement/userprivacy.md
- [CN-HELP]: https://www.kimi.com/zh-cn/help/kimi-api/api-overview
- [CN-HELP-DATA]: https://www.kimi.com/zh-cn/help/kimi-api/api-data-security

## Two platforms, two hosts: the customer must choose

The provider runs two separate platforms. The documentation of each names only its own host.

| | International platform | Platform for mainland China |
|---|---|---|
| Console and documentation | `platform.kimi.ai` [OVERVIEW] | `platform.kimi.com` [CN-OVERVIEW] |
| Base URL, OpenAI format | `https://api.moonshot.ai/v1` [OVERVIEW] | `https://api.moonshot.cn/v1` [CN-OVERVIEW] |
| Base URL, Anthropic format | `https://api.moonshot.ai/anthropic` [OVERVIEW] | `https://api.moonshot.cn/anthropic` [CN-OVERVIEW] |
| Who it is for | "For users outside mainland China, use platform.kimi.ai." [G-TROUBLE] | Called 中国站 (the China site) [CN-ERRORS]; [PRIVACY] sends users in mainland China to kimi.com |
| Contracting company in the terms | Moonshot AI PTE. LTD., Singapore law [TERMS] | 北京月之暗面科技股份有限公司 (Beijing Moonshot), mainland China law [CN-TERMS] |

- Accounts, balances and keys are not shared. [G-TROUBLE]: "Accounts and keys from different regional platforms are
  independent and cannot be mixed." [CN-ERRORS] says the same and names both sites: the accounts, balances and API
  keys of `platform.kimi.com` (中国站) and `platform.kimi.ai` (国际站) are "完全独立，混用会返回 401" (fully
  separate; mixing them returns 401). [REF-MODELS] also says the keys of the two sites are not interchangeable.
- A key used on the other platform's host gets `401 invalid_authentication_error` [G-TROUBLE].
- For the preset: the host is a customer choice with exactly two documented values. A request must go only to the
  host the customer chose; a 401 is never a reason to try the other host (that would send the customer's key and
  text to a host under other terms and another country's law).
- The endpoint lists of the two overviews are the same [OVERVIEW], [CN-OVERVIEW]. Whether the two platforms accept
  exactly the same request fields was not compared field by field: the details in this file come from the
  international pages unless marked CN.
- Keys of the provider's other products (Kimi Code, Kimi Membership) are separate again and do not work on the Open
  Platform [G-TROUBLE].

## Reaching it

- Base URL and the chat path: `https://api.moonshot.ai/v1` (international) or `https://api.moonshot.cn/v1` (mainland
  China), chat at `POST /chat/completions` [OVERVIEW], [CN-OVERVIEW]. The OpenAPI file lists one server,
  `https://api.moonshot.ai` [OPENAPI].
- Hosted by the vendor, run by the customer, or both: hosted by the vendor. The documentation also says the weights
  of the current models are public ([G-K3]: "The full model weights have been released."; [G-K31] calls K3.1 Pro an
  "open-weight model"), but no page read says where the weights are, under which licence, or how to serve them. So:
  vendor-hosted is documented; running it yourself is not documented on these pages.
- API format: OpenAI-compatible, three formats in all [OVERVIEW]: Chat Completions (`POST /v1/chat/completions`),
  Responses (`POST /v1/responses`), and an Anthropic-compatible Messages API (`POST /anthropic/v1/messages`). The
  guides for tools, structured output, streaming and thinking are all written for Chat Completions; this file
  describes that one. Which of the three is fuller is not stated.
- API versions or dated headers a request must send: none documented. An optional request header
  `X-Msh-Request-Nonce` switches on a signed response (`Msh-Request-Timestamp`, `Msh-Request-Signature`)
  [REF-CHAT], [OPENAPI]; a neutral client does not send it.

## Signing in

- Method: bearer key. `Authorization: Bearer <key>` on every request [OVERVIEW], [CN-OVERVIEW]. No project,
  organisation or deployment field in the request.
- What the customer must create at the provider, in plain words: an account on the platform of their choice
  (international or mainland China), then an API key on the console's API Keys page [G-KEY]. The account needs a
  top-up before the current models work (minimum $1 on the international platform) [G-K3], [G-LIMITS]. An
  organisation can restrict keys to an IP allowlist; a call from another address gets 403 [REF-ERRORS].
- Key format or prefix: not confirmed ([G-KEY] does not show one).

## What a request can do

| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | yes | `tools`: a list of `{"type": "function", "function": {"name", "description", "parameters", "strict"}}`; `strict` defaults to `true`; `parameters` must follow the provider's schema dialect (MFJS, see below). Function names must be unique in a request (duplicates: 400). | [REF-CHAT], [G-TOOLS], [G-OFFICIAL] |
| Forced tool call | partly. `tool_choice`: `"auto"` (default), `"none"`, `"required"`, or `{"type": "function", "function": {"name": "..."}}`. `"required"` works on the K3 series only; on `kimi-k2.7-code` and `kimi-k2.6` it "returns an error". Forcing one named function "is currently incompatible with thinking": with thinking on it returns 400 `tool_choice 'specified' is incompatible with thinking enabled`. Thinking is always on for the K3 series, so on the current models a named function cannot be forced; `"required"` with a single declared tool is the documented way to get one. | `tool_choice` | [G-CHOICE], [REF-PARAMS], [REF-CHAT], [G-THINK] |
| Parallel tool calls | yes, the model may return several calls in one answer ("the model can invoke multiple tools in parallel"); one tool message per call is required. A switch to turn it off is not documented: `parallel_tool_calls` is absent from the chat request schema. | several entries in `message.tool_calls` | [G-TOOLS], [OPENAPI] |
| Structured output with a JSON Schema (and which schema keywords are refused) | yes. `response_format`: `{"type": "text"}` (default), `{"type": "json_object"}`, or `{"type": "json_schema", "json_schema": {"name", "schema", "strict"}}`; `name` and `schema` required, `strict` defaults to `true`. With `strict: true` the schema must follow "MFJS (Moonshot Flavored JSON Schema)". No list of refused keywords is published. Named as supported: nested objects, arrays, `anyOf`, `oneOf`, `$ref`, `additionalProperties`. The same page warns that `oneOf` and `$ref` "may not be followed strictly" and that the answer can come wrapped in a Markdown code block. Reliable on `kimi-k3.1-pro` and `kimi-k3`; other models not stated. | `response_format` | [REF-CHAT], [G-FORMAT], [G-JSON] |
| Tools together with structured output | not confirmed: no page read says it works or that it is refused. | | [REF-CHAT], [G-FORMAT], [G-TOOLS], [G-K3TOOLS], [FEATURES] |
| Streaming (and whether usage arrives in the stream) | yes, server-sent events; `stream: true`. Usage arrives only when `stream_options: {"include_usage": true}` is sent, in a last chunk with `"choices": []` and a top-level `usage`, just before `data: [DONE]`. Works with tool calls and with reasoning. | `stream`, `stream_options.include_usage` | [REF-CHAT], [G-STREAM] |
| System instructions (a role, or a separate field) | a role: `system` in `messages` (roles: `system`, `user`, `assistant`, `tool`). | `messages[].role` | [REF-CHAT] |
| Listing models | yes, `GET /v1/models`, same bearer key; each model carries its context length and three capability flags. | see "The model list" below | [REF-MODELS] |

## Differences from the plain OpenAI chat-completions format

What a preset author must know; each point is detailed in the sections below.

1. **Sampling fields must not be sent.** `temperature`, `top_p`, `n`, `presence_penalty`, `frequency_penalty` are
   fixed per model; "passing any other value returns an error, so do not pass it explicitly" [REF-PARAMS].
2. **Maximum tokens is `max_completion_tokens`**; `max_tokens` is deprecated [REF-CHAT]. Reasoning counts inside it
   [G-THINK].
3. **Thinking cannot be switched off on the current models**, and every answer carries `reasoning_content`, which
   must be sent back unchanged in the next request of a conversation or tool loop [G-THINK], [G-K3], [REF-PARAMS].
4. **`tool_choice` with a named function fails with thinking on** (so on every current model) [G-CHOICE].
5. **`parallel_tool_calls` does not exist** in the documented request [OPENAPI].
6. **Schemas use the provider's own dialect (MFJS)**, for `response_format` and for tool `parameters` [REF-CHAT].
7. **Provider-only fields** a neutral client must not send by accident: `partial` and `name` on an assistant
   message, a `system` message with a `tools` field, `thinking`, `prompt_cache_key`, `prompt_cache_options`,
   `safety_identifier`, `prediction`, the header `X-Msh-Request-Nonce` (see "Provider-only fields").
8. **Images by public URL are refused** on the K3 series (base64 or an uploaded file id only) [G-K3]; outside the
   first version of the contract, noted for later.
9. **429 has three meanings**, one of which (no balance) is not a rate limit [REF-ERRORS].
10. **Usage has extra fields** for cache and reasoning, and one page shows usage in a second place in a stream
    [REF-CHAT], [G-STREAM].

## Limits and shapes

### Request fields

Documented in the chat request [REF-CHAT], [OPENAPI]: `model`, `messages`, `max_completion_tokens`, `max_tokens`
(deprecated), `stop` (at most 5 strings, each at most 32 bytes), `logprobs`, `top_logprobs` (0 to 20),
`prediction`, `response_format`, `stream`, `stream_options`, `tools`, `tool_choice`, `reasoning_effort` (K3
series), `thinking` (K2.6 and K2.7 only), `prompt_cache_key`, `prompt_cache_options`, `safety_identifier`.

Not in the chat request schema [OPENAPI]: `temperature`, `top_p`, `n`, `presence_penalty`, `frequency_penalty`,
`seed`, `user`, `logit_bias`, `parallel_tool_calls`. The first five are described on [REF-PARAMS] as fixed values
(next section). For `seed`, `user`, `logit_bias` and `parallel_tool_calls` no page says whether they are ignored or
refused: not confirmed. The top-level request schema does not forbid extra properties [OPENAPI], which says nothing
about what the server does with them.

The request is validated per model: the schema is chosen by the `model` value [REF-CHAT].

- Required fields a neutral request may lack: none. `model` and `messages` are the only required fields;
  `max_completion_tokens` is optional (default 131072 on the K3 series) [REF-CHAT].
- A message's `content` must not be empty [REF-CHAT]. (The reference's own example of an assistant message with tool
  calls has `content` as an empty string; that message is sent back as received.)

### Temperature and the other sampling fields

Per model [REF-PARAMS], [G-K3], [G-K31]:

| | K3 series (`kimi-k3`, `kimi-k3.1-pro`, `kimi-k3.1-pro-highspeed`) | `kimi-k2.7-code` (and `-highspeed`) | `kimi-k2.6` |
|---|---|---|---|
| `temperature` | fixed at 1.0 | fixed at 1.0 | 1.0 with thinking on, 0.6 with thinking off |
| `top_p` | fixed at 0.95 | fixed at 0.95 | fixed at 0.95 |
| `n` | fixed at 1 | fixed at 1 | fixed at 1 |
| `presence_penalty`, `frequency_penalty` | fixed at 0 | fixed at 0 | fixed at 0 |
| `thinking` | not supported (do not send) | only `enabled`; `disabled` returns an error | `enabled` (default) or `disabled` |
| `reasoning_effort` | `low`, `high`, `max`; default `max` | not supported | not supported |
| `tool_choice: "required"` | accepted | returns an error | returns an error |

- "Fixed" is defined on [REF-PARAMS]: the parameter cannot be changed, "passing any other value returns an error, so
  do not pass it explicitly". [G-THINK]: "Do not set `temperature`." [G-K3] and [G-K31] say to omit all five.
- So there is no temperature range on the current models. A preset must drop `temperature` (or refuse a value other
  than the fixed one with a typed error), never forward it. Whether sending exactly the fixed value is accepted is
  not confirmed; the documentation says not to send it.
- `n` above 1 returns 400 with `invalid n: only 1 is allowed for this model` [G-STREAM]. [REF-PARAMS] also carries
  the sentence "When `temperature` is close to 0, `n` can only be 1", next to a table that fixes `n` at 1 for every
  listed model.
- A temperature range of 0 to 1, as older models of this provider are said to have had, is not on any page read
  today; the models it would apply to are listed as deprecated or retired [MODELS], [CN-MODELS]. Not confirmed.

### Maximum tokens

- Field name: `max_completion_tokens`. K3 series: default 131072, at most 1048576 [REF-CHAT], [G-K3], [G-K31].
  `max_tokens` is marked deprecated ("Deprecated, please refer to max_completion_tokens") but is still in the schema;
  the page does not say it is refused [REF-CHAT]. Several guides still write `max_tokens` [G-THINK], [G-JSON],
  [G-FORMAT], [G-PARTIAL].
- If input tokens plus the limit exceed the context window, the server lowers the limit by itself [REF-CHAT].
- Reasoning counts inside the limit: "the sum of tokens in `reasoning_content` and `content` must be less than or
  equal to `max_tokens`"; the page recommends 16000 or more [G-THINK]. A small neutral maximum can therefore be used
  up by reasoning before any answer text appears (finish reason `length` with little or no `content`).

### Tool names and model names

- Tool name: must match `^[a-zA-Z_][a-zA-Z0-9-_]{0,127}$` (starts with a letter or underscore, at most 128
  characters) [REF-CHAT]. Unique within one request, else 400 `invalid_request_error` [G-OFFICIAL].
- Number of tools: no maximum stated [G-TOOLS], [G-K3TOOLS].
- Model name shape: lowercase with dots and hyphens, for example `kimi-k3.1-pro` [MODELS].

### Models and context sizes as documented

Current [MODELS], [CN-MODELS], [REF-PARAMS]:

| Model | Context | Notes |
|---|---|---|
| `kimi-k3.1-pro` | 1M tokens | flagship; thinking always on |
| `kimi-k3.1-pro-highspeed` | 1M tokens [REF-PARAMS] (not stated on [MODELS]) | faster variant |
| `kimi-k3` | 1M tokens | previous flagship; thinking always on |

Deprecated, closed to accounts registered from 1 November, full retirement expected late December 2026 [MODELS],
[CN-MODELS]: `kimi-k2.7-code`, `kimi-k2.7-code-highspeed`, `kimi-k2.6` (each 256K tokens [REF-PARAMS]).

Listed as deprecated on [MODELS] and as retired (已下线) on [CN-MODELS]: `kimi-k2.5`, `moonshot-v1-8k`,
`moonshot-v1-32k`, `moonshot-v1-128k`, `moonshot-v1-auto`, the three `moonshot-v1-*-vision-preview` models,
`kimi-k2-0905-preview`, `kimi-k2-0711-preview`, `kimi-k2-turbo-preview`, `kimi-k2-thinking`,
`kimi-k2-thinking-turbo`, `kimi-latest`, `kimi-thinking-preview`. A preset should not offer these.

Maximum output per model: not listed apart from the `max_completion_tokens` ceiling above.

### The shape of a tool call in an answer, and of a tool result in a request

Answer [REF-CHAT], [G-TOOLS]: `choices[0].message.tool_calls[]`, each with `id`, `type: "function"`,
`function.name` and `function.arguments` (a JSON document as a string; the caller parses it). `finish_reason` is
`tool_calls`. `content` is usually empty but can hold text [G-TOOLS]. Synthetic example:

```json
{
  "role": "assistant",
  "content": "",
  "reasoning_content": "The user asks for the stock of one item.",
  "tool_calls": [
    {"id": "call_example_1", "type": "function",
     "function": {"name": "get_stock", "arguments": "{\"sku\":\"EXAMPLE-1\"}"}}
  ]
}
```

Next request: the assistant message exactly as received (with `tool_calls` and `reasoning_content`), then one tool
message per call [REF-CHAT], [G-TOOLS]:

```json
{"role": "tool", "tool_call_id": "call_example_1", "content": "{\"stock\": 3}"}
```

- `content` of a tool message is a string [G-TOOLS].
- The two pages differ on `name`: the guide's tool message also has `name` (the function name) [G-TOOLS]; the
  reference's example has only `role`, `tool_call_id`, `content` [REF-CHAT]. Neither calls `name` required.
- Every tool call must be answered: "A mismatch in count or `tool_call_id` values causes the next request to fail."
  [G-TOOLS]. A loop that stops at a limit halfway through a set of calls cannot send a partial set.
- Tool call ids need only be unique within one answer [G-OFFICIAL].
- The message schema in the reference lists only `role`, `content`, `name`, `partial`; `tool_calls`, `tool_call_id`
  and `reasoning_content` on request messages are described in prose and examples, not in the schema [REF-CHAT],
  [OPENAPI].

### Reasoning content

- Answer: `choices[0].message.reasoning_content`, a string next to `content`, present when thinking is on
  [REF-CHAT], [G-THINK]. On the K3 series thinking is always on and cannot be switched off; `reasoning_effort`
  (`low`, `high`, `max`) only sets the depth [G-K3], [G-K31], [REF-PARAMS].
- Stream: `delta.reasoning_content` fragments come first: "the `reasoning_content` field will always appear before
  the `content` field" [G-THINK], and before `delta.tool_calls` [G-STREAM]. It appears in the examples but not in
  the chunk schema [REF-CHAT].
- Sending it back: required by the documentation in a conversation and in a tool loop. [G-THINK]: "pass the complete
  assistant message returned by the API back in `messages` as-is"; [G-TOOLS] says the assistant message sent back
  must keep `reasoning_content` and the full `tool_calls`; [G-K31]: "Do not keep only content." What the API does
  when it is left out (an error, or a worse answer) is not stated on any page read: not confirmed.
- On `kimi-k2.6` the default discards earlier reasoning unless `thinking.keep` is `"all"` [REF-CHAT].
- Reasoning is billed as output and, when sent back, as input [G-THINK].
- Structured output: only `content` is the JSON; `reasoning_content` is not part of it [G-FORMAT].

### Structured output details

- `json_object`: a valid JSON object, no check of fields; the prompt must describe the wanted fields; only objects,
  never a top-level array [G-JSON], [REF-CHAT].
- `json_schema` with `strict: false`: "the API only guarantees that the output is a valid JSON object" [G-FORMAT].
- An invalid schema returns 400 `invalid_request_error`, for example when `json_schema.schema` is not an object
  [G-FORMAT]. But a schema the dialect does not fully support is often accepted with 200 and no warning; the
  provider's own checking tool (`walle`) is static only [G-FORMAT]. So the library's own check of the answer against
  the schema stays necessary.
- `required` fields always appear; for a value that may be missing the page recommends a nullable type such as
  `["integer", "null"]` [G-FORMAT]. `enum`, `pattern`, `format`, nesting depth and property counts: not mentioned,
  not confirmed.
- Cut off at the token limit: `finish_reason` is `length` and the JSON is incomplete [G-FORMAT], [G-JSON].
- With streaming: not addressed on the pages read. With partial mode: only for simple schemas [G-FORMAT].

### Streaming

- Wire format [G-STREAM], [REF-CHAT]: `Content-Type: text/event-stream`; each event is a line `data: ` plus one
  JSON object, then a blank line; the stream ends with `data: [DONE]`. A stream without `[DONE]` is incomplete even
  when a finish reason was already seen. Keep-alive comment lines: not mentioned.
- Chunk: `id`, `object: "chat.completion.chunk"`, `created`, `model`, `choices[]` with `index`, `delta`,
  `finish_reason`; `role` only in the first chunk [G-STREAM].
- Tool calls: `delta.tool_calls[]` grouped by `index`; `id`, `type` and `function.name` only in the first fragment
  of a call; `function.arguments` as string fragments to append and parse after the stream ends [G-STREAM],
  [G-TOOLS].
- Usage: with `stream_options.include_usage: true`, a last chunk with `"choices": []` and a top-level `usage`,
  before `[DONE]`; ordinary chunks carry `usage: null` [REF-CHAT], [G-STREAM]. The streaming guide's raw example
  also shows a `usage` object inside `choices[0]` on the chunk that carries the finish reason, and does not say
  whether that depends on `include_usage` [G-STREAM]; the reference shows only the top-level form [REF-CHAT]. An
  adapter should read both places and not count twice.
- If the stream breaks before the last chunk, no usage is reported [REF-CHAT], [G-STREAM].
- Synthetic tail of a stream:

```text
data: {"id":"chatcmpl-example","object":"chat.completion.chunk","created":0,"model":"kimi-k3","choices":[{"index":0,"delta":{"content":"Hi"},"finish_reason":null}],"usage":null}

data: {"id":"chatcmpl-example","object":"chat.completion.chunk","created":0,"model":"kimi-k3","choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"usage":null}

data: {"id":"chatcmpl-example","object":"chat.completion.chunk","created":0,"model":"kimi-k3","choices":[],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}

data: [DONE]
```

### Usage fields

[REF-CHAT]: `usage.prompt_tokens`, `usage.completion_tokens`, `usage.total_tokens`. Extra: `usage.cached_tokens`
(top level) and `usage.prompt_tokens_details.cached_tokens` and `.cache_write_tokens`; the cached, cache-write and
uncached parts add up to `prompt_tokens`. The reference's example also has
`usage.completion_tokens_details.reasoning_tokens`, which is not in its schema. Whether `completion_tokens` includes
the reasoning tokens is not stated for Chat Completions (the Anthropic-format schema says its output tokens include
them [OPENAPI]): not confirmed.

### Finish reasons

[REF-CHAT], [OPENAPI]: `stop` (the model ended, or a stop string matched), `length` (the token limit cut the answer
off; with thinking the limit may be spent on reasoning), `tool_calls` (the model asks for tools). In a stream also
`null` until the end. No `content_filter` finish reason is documented: a content refusal is an HTTP 400 error (next
section). Whether an answer can be cut by the content filter halfway through a stream, and how that looks, is not
confirmed.

### Error body shape, error codes, rate-limit headers

Body [OVERVIEW], [REF-ERRORS], [OPENAPI]: `{"error": {"message": "...", "type": "...", "code": "..."}}`; `message`
is required, `type` and `code` optional. The error table names `error.type` values only; no `code` values are
listed. Synthetic example:

```json
{"error": {"type": "rate_limit_reached_error", "message": "Organization-level RPM limit reached"}}
```

Statuses and types [REF-ERRORS]; [CN-ERRORS] lists the same names and statuses:

| Status | `error.type` | Meaning | Neutral reason it suggests |
|---|---|---|---|
| 400 | `content_filter` | input or output refused by the safety review ("considered high risk") | rejected (content refusal) |
| 400 | `invalid_request_error` | malformed request, wrong field, input longer than the context window, invalid schema | rejected |
| 401 | `invalid_authentication_error`, `incorrect_api_key_error` | bad or missing key; also a key from the other platform | credentials rejected |
| 403 | `permission_denied_error` | API not open for the account, or caller's IP not on the organisation's allowlist | credentials rejected or rejected (a choice for the adapter) |
| 404 | `resource_not_found_error` | "Model not found, or this account does not have permission to access the model" | rejected (unknown model) |
| 429 | `rate_limit_reached_error` | organisation-level concurrency, RPM, TPM or TPD limit | rate limited |
| 429 | `engine_overloaded_error` | the provider is overloaded; retry with backoff | unavailable or rate limited; may be retried |
| 429 | `exceeded_current_quota_error` | no balance, account disabled, or token quota used up | not retryable; must not be treated as a rate limit |
| 499 | `client_closed_request` | the client or a proxy closed the connection | |
| 500 | `server_error`, `unexpected_output` | internal error | provider error; may be retried |
| 503 | `server_unavailable` | temporary outage | unavailable |
| 504 | none: an HTML page "504 Gateway Time-out" | no answer within 900 seconds, common on long non-streamed calls | timeout or unavailable; the body is not JSON |

- A 429 must be told apart by `error.type`, not by the status [REF-ERRORS], [G-TROUBLE].
- Retry header: `Retry-After` is named on the errors page for the overloaded case ("Wait per `Retry-After`") and the
  rate-limit rows speak of waiting "the indicated time" [REF-ERRORS], [CN-ERRORS]. Its unit and whether every 429
  carries it are not stated. It is not in the OpenAPI file [OPENAPI].
- `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` are documented only for the web-search and fetch
  endpoints, not for chat [G-LIMITS], [OPENAPI].
- Rate limits depend on the account's cumulative top-up (tiers); the tier table could not be read (it came back
  empty) [G-LIMITS]. Automatic retries count toward the requests-per-minute limit, and on the lowest tier one failed
  request can use it up [G-TROUBLE].
- A request id header for chat is not documented. Support asks for a `request_id` [REF-ERRORS]; where it is found
  for a chat call is not confirmed.

### The model list

`GET /v1/models` with the bearer key [REF-MODELS], [OPENAPI]. Answer: `{"object": "list", "data": [...]}`; each
entry has `id`, `object` (`"model"`), `created`, `owned_by`, `context_length` (tokens), `supports_image_in`,
`supports_video_in`, `supports_reasoning`. So the context length is told by the provider; tool calling and
structured output are not flagged per model. Synthetic example:

```json
{"object": "list", "data": [
  {"id": "kimi-k3", "object": "model", "created": 0, "owned_by": "moonshot",
   "context_length": 1000000, "supports_image_in": true, "supports_video_in": true, "supports_reasoning": true}
]}
```

The list shows what the key may use; [G-TROUBLE] advises it as the check that a model is available to an account.
A missing or wrong key gives 401 [REF-MODELS].

### Built-in tools

- No built-in tool type exists in the documented chat request. A `$web_search` function of type `builtin_function`
  is not on any page read today [G-SEARCH], [G-OFFICIAL], [REF-CHAT]: not confirmed, do not build to it.
- "Official tools" (web search, fetch, date, memory and others) are ordinary `function` tools whose definitions the
  caller fetches from `GET /v1/formulas/{uri}/tools` and whose calls the caller itself sends to
  `POST /v1/formulas/{uri}/fibers` [G-OFFICIAL]. The model only asks; the caller runs them. For this library they are
  just tools of the caller, nothing to support.
- Separate REST endpoints for search and fetch exist (`/v1/tools/search`, `/v1/tools/search_pro`, `/v1/tools/fetch`)
  [G-SEARCH]; not part of chat.
- Server-side web search exists only in the Responses format [FEATURES]; [G-K31] says web search "is being updated"
  and is not recommended for production for now.

### Provider-only fields a neutral client must not send by accident

- `partial: true` on a last message with role `assistant` (Partial Mode): the model continues that text and the
  answer holds only the continuation [G-PARTIAL], [REF-CHAT]. A neutral transcript that ends with an assistant
  message must never get this field. The reference says not to combine it with `json_object` [REF-CHAT].
- `name` on a message: an optional sender name; on an assistant message in Partial Mode it "is part of the output
  prefix" [G-PARTIAL], [REF-CHAT]. Do not copy a neutral name field into it.
- A `system` message with a `tools` field and no `content` (dynamic tool loading, K3 series) [REF-CHAT],
  [G-K3TOOLS].
- `thinking` (`{"type": ..., "keep": ...}`): only for K2.6 and K2.7; not part of the K3 schemas [REF-CHAT],
  [REF-PARAMS], [G-THINK].
- `prompt_cache_key`, `prompt_cache_options` (`mode: "implicit"`, `ttl: "5m"` or `"1h"`), `safety_identifier`,
  `prediction` [REF-CHAT]. Caching is automatic; the string `prompt_cache_breakpoint` inside `content` makes the
  request fail with 400 [REF-CHAT].
- The header `X-Msh-Request-Nonce` [REF-CHAT].
- Image parts with a public URL: refused on the K3 series [G-K3], [G-K31].

## Data

- Where requests are processed, as the provider states it: **not stated for the API, on either platform.**
  - International platform: the privacy policy (operator NOVASCENT PRIVATE LIMITED, last updated 25 November 2026)
    says only: "Your information may be transferred to and stored on servers located outside of your country of
    residence." [PRIVACY]. It names no country; the word Singapore does not appear in it. It applies to users
    outside mainland China, covers the Open Platform only where the operator acts as controller "for limited
    purposes", and does not apply where it processes "on behalf of enterprise customers" [PRIVACY]. For transfers
    from Europe it relies on the European Commission's standard contractual clauses and names an EU representative
    [PRIVACY]. The terms of service (Moonshot AI PTE. LTD., Singapore law, arbitration in Singapore, last updated
    30 July 2026) say nothing about where data is stored or processed [TERMS]. The zero-data-retention page and the
    troubleshooting page name no location either [G-ZDR], [G-TROUBLE]. No statement of processing in the EU was
    found.
  - The two international documents name different companies: Moonshot AI PTE. LTD. in the terms [TERMS], NOVASCENT
    PRIVATE LIMITED in the privacy policy [PRIVACY].
  - Platform for mainland China: the privacy policy (北京月之暗面科技股份有限公司, Beijing; updated 24 August 2026)
    says "我们将您的个人信息存储于中华人民共和国境内" (we store your personal information within the People's
    Republic of China) and that nothing is provided abroad without separate consent [CN-PRIVACY]. That sentence is
    about personal information, not explicitly about API request content. The terms are under mainland China law and
    do not state a storage location [CN-TERMS].
- The option not to store requests, and whether training on them is off by default:
  - International: Zero Data Retention exists as "a data protection mode available to enterprise customers", on
    request through sales; it is an arrangement on the account, not a field or header in a request [G-ZDR]. Under
    it, prompts and answers are deleted after the request; the Batch API and directly uploaded images and videos are
    outside it, as are security logs and billing records [G-ZDR]. The retention period without it is not stated
    [G-ZDR], [TERMS].
  - Training, international: the pages do not agree in wording. [TERMS]: "We may use Content to provide, maintain,
    develop, support, and improve the Services", and "Unless otherwise expressly agreed in writing, Customer Content
    may be used for the foregoing purposes"; a customer wanting limits on training use "may contact Moonshot AI" for
    an enterprise arrangement. [G-ZDR] says enterprise customer data is not used to train models by default.
    [PRIVACY] says user content is processed for "training and optimizing" models, with an opt-out by contacting the
    operator. Read together: training is not documented as off by default for an ordinary account, and there is no
    self-service switch and no request field.
  - Mainland China: the pages disagree. The help centre says data sent through the API "不会被用于训练或改进 Kimi
    的模型" (will not be used to train or improve Kimi's models) [CN-HELP-DATA]. The terms grant the provider a free
    right to use inputs, outputs and feedback "用于模型服务优化" (for optimising the model service), with no opt-out
    described [CN-TERMS].
  - For the library: there is no store or training option an adapter could send, and none to switch on by accident.

## Overview row (for `docs/providers/overview.md`)

| Provider (his words) | Hosted model API | API format | Sign-in | Tool calling | JSON-schema output | Streaming | Where data is processed | Run by |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Kimi (Moonshot AI) | yes, on two separate platforms: international (`platform.kimi.ai`) and mainland China (`platform.kimi.com`); accounts and keys are not shared, a key on the wrong host gets 401 | OpenAI-compatible Chat Completions at `https://api.moonshot.ai/v1` (international) or `https://api.moonshot.cn/v1` (mainland China), path `/chat/completions`; also Responses (`/v1/responses`) and an Anthropic-compatible Messages API (`/anthropic/v1/messages`). Sampling fields (`temperature`, `top_p`, `n`, penalties) are fixed per model and must not be sent; maximum tokens is `max_completion_tokens`; thinking is always on for the current models and `reasoning_content` must be sent back | API key (bearer), made in the console of the chosen platform; a top-up is needed | yes, OpenAI's `tools` form; `tool_choice` `auto`, `none`, `required` (K3 series only); a named function cannot be forced while thinking is on (400), so not on the current models; several calls in one answer, no `parallel_tool_calls` switch documented; model list at `GET /v1/models` with context length | yes: `response_format` `json_object` or `json_schema` (`name`, `schema`, `strict` default true) in the provider's own schema dialect (MFJS), no list of refused keywords; together with tools: not confirmed | yes, server-sent events ending with `data: [DONE]`; usage in a last chunk with empty `choices` when `stream_options.include_usage` is set; `reasoning_content` deltas before the text | **not stated for the API.** International: the privacy policy (NOVASCENT PRIVATE LIMITED) names no country ("servers located outside of your country of residence"); terms under Singapore law (Moonshot AI PTE. LTD.). Mainland China: personal information stored in the People's Republic of China (privacy policy of the Beijing company). **No EU processing by any statement found.** No-retention only as an enterprise arrangement (ZDR); training not documented as off by default for ordinary accounts | vendor; weights of the current models are said to be released, but running them is not documented on the pages read |

All cells: read on 2026-11-27 on the pages named in this file.

## What contradicts or strains `docs/requirements.md`

- **LR-06, LR-08 (temperature):** the current models take no temperature at all: it is fixed at 1.0 and another value
  returns an error. The adapter cannot map a neutral range onto it; it must drop the value or refuse with a typed
  error. A caller that relies on a low temperature for repeatable output does not get it here.
- **LR-10 (forced tool call):** a named function cannot be forced on the current models (400 with thinking on, and
  thinking cannot be switched off). Only `required` exists, on the K3 series. The capability is "emulated" at best
  (`required` with a single declared tool), "none" on K2.6 and K2.7. Every structured answer that comes back as a
  forced tool call is affected.
- **LR-10 (parallel tool calls):** the model may return several calls and no documented field turns that off.
- **LR-10, LR-11 (tools together with structured output):** not documented either way; the capability cannot be set
  from documentation.
- **LR-07, LR-14, LR-15 (messages and the tool loop):** the provider requires the assistant message, including
  `reasoning_content`, to come back unchanged in the next round. The neutral assistant message and the loop need a
  way to carry that text back to the same adapter without the contract naming a provider (LR-04), and without
  showing it to the caller as answer text.
- **LR-06 (maximum output tokens):** the limit covers reasoning plus answer; a small neutral value can end with
  finish reason `length` and no usable text.
- **LR-19 (where data is processed):** the documentation does not state it for the API; the preset can only say "not
  stated by the provider", per platform.
- **LR-19, LR-29 (base URL and host):** two hosts under different companies and laws; the preset needs a customer
  choice between them and must never fall back from one to the other.
- **LR-31 (do not store):** no request option exists; zero retention is an enterprise arrangement the customer makes
  with the provider. The preset has nothing to send and should say so.
- **LR-32, LR-34 (errors and retries):** status 429 covers a rate limit, an overloaded provider and an empty balance;
  the last must not be retried or reported as rate limited. A 504 has an HTML body, not the error shape. A content
  refusal is a 400, not a finish reason. `Retry-After` is mentioned but its unit is not documented.

## What differs from `docs/providers/overview.md` (as of 2026-11-26)

- The China address was a search summary there; it is now read on the provider's own page: `https://api.moonshot.cn/v1`
  [CN-OVERVIEW], with the rule that keys and accounts are separate [CN-ERRORS], [G-TROUBLE].
- "`tool_choice` not mentioned" is out of date: it is documented, with the limits above [G-CHOICE], [REF-PARAMS].
- "JSON-schema output: not confirmed" is out of date: `response_format` with `json_schema` is documented [REF-CHAT],
  [G-FORMAT].
- The search summaries that reported Singapore as the place of processing for the Open Platform are not supported by
  the pages read today: the current privacy policy names no country and a different operator than the terms.
- "Run by: vendor" stays, with the note that the documentation calls the current models open-weight.

## Not confirmed

- Where API requests are processed and stored (country), on either platform. Tried: [PRIVACY], [TERMS], [G-ZDR],
  [G-TROUBLE], [FEATURES], [CN-PRIVACY], [CN-TERMS], [CN-HELP-DATA], the agreement index at
  `platform.kimi.ai/docs/agreement`, and a search for a trust or data-residency page (none found).
- How long requests are kept without zero data retention. Tried: [G-ZDR], [TERMS], [PRIVACY], [CN-HELP-DATA].
- Whether tools and `response_format` may be sent together. Tried: [REF-CHAT], [G-FORMAT], [G-JSON], [G-TOOLS],
  [G-K3TOOLS], [G-K3], [G-K31], [FEATURES].
- Whether `parallel_tool_calls`, `seed`, `user`, `logit_bias` are ignored or refused. Tried: [REF-CHAT], [OPENAPI].
- Whether sending a sampling field with exactly its fixed value is accepted. Tried: [REF-PARAMS], [G-K3], [G-K31].
- Whether `max_tokens` is still accepted on the K3 series (deprecated, not said to be refused). Tried: [REF-CHAT].
- What happens when `reasoning_content` is not sent back on the K3 series. Tried: [G-THINK], [G-TOOLS], [REF-CHAT],
  [G-K3], [G-K31].
- The full list of JSON Schema keywords the dialect (MFJS) accepts or refuses, and limits on depth or size. Tried:
  [G-FORMAT], [REF-CHAT]. No MFJS specification page was found in [INDEX].
- Whether structured output works with streaming. Tried: [G-FORMAT], [G-JSON], [G-STREAM].
- Whether usage inside `choices[0]` of a stream chunk is always sent, and whether `completion_tokens` includes the
  reasoning tokens. Tried: [G-STREAM], [REF-CHAT], [OPENAPI].
- `Retry-After`: its unit (seconds or a date) and on which errors it is sent; any rate-limit headers on chat. Tried:
  [REF-ERRORS], [CN-ERRORS], [G-LIMITS], [OPENAPI].
- `error.code` values, and the request id of a chat call. Tried: [REF-ERRORS], [OPENAPI].
- How a content refusal appears once a stream has started. Tried: [REF-ERRORS], [G-STREAM], [REF-CHAT].
- The rate-limit figures per tier (the table on [G-LIMITS] came back empty).
- The key format, and whether a key is shown only once. Tried: [G-KEY].
- Where the open weights are published, their licence, and a documented way to serve them. Tried: [G-K3], [G-K31],
  [MODELS], [FEATURES].
- A `$web_search` builtin function and a temperature range of 0 to 1: neither is on a page read today.
- Whether the mainland China platform accepts exactly the same request fields: only its overview, errors, model list
  and agreements were read, not its chat reference.
- The second half of [OPENAPI] was read only for chat, model list, error, usage and header schemas.
