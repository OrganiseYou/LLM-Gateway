# STACKIT (AI Model Serving)
Read on 2026-11-27. Hosted text chat API: yes. STACKIT AI Model Serving offers open-weight models ("shared models")
behind one OpenAI-compatible address, run by STACKIT; the customer runs nothing.

Every fact below was read on 2026-11-27 on the linked page of STACKIT itself, unless the line says "from a search
summary" or "not confirmed". **The documentation is thin for a preset author:** it shows one curl request for chat,
one tool-calling tutorial written with a client framework, a list of models, and the rate limits. It has no
reference of the chat request and answer: no page read today lists the request fields, the answer shape, the stream
format or the error body. For "additional parameters" the how-to page sends the reader to OpenAI's API
documentation [use], which is not STACKIT's own page and is not used as a source here. All JSON in this file is
synthetic, written by hand from the documented shapes.

Short names for the pages used below:

| Short name | Page |
|---|---|
| [overview] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/ |
| [intro] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/basics/introduction/ |
| [service] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/basics/service-description/ (page says last updated Nov 24, 2025) |
| [models] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/basics/available-shared-models/ |
| [preview] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/basics/preview-models/ |
| [start] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/getting-started/getting-started-with-shared-models/ (page says last updated Aug 25, 2026) |
| [tokens] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/how-tos/manage-auth-tokens/ |
| [use] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/how-tos/use-the-models/ (page says last updated Mar 11, 2026) |
| [limits] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/how-tos/rate-limits-on-shared-models-_stackit-ai-model-serving/ |
| [tools-howto] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/how-tos/integration-of-tool-calling/ |
| [faq] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/faq/ |
| [release] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/release-notes/ |
| [tut-frameworks] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/tutorials/first-steps-with-common-frameworks/ |
| [tut-lcel-basic] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/tutorials/langchain-expression-language-basic-usage/ |
| [tut-vision] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/tutorials/chat-with-images-vision-understanding-basics/ |
| [tut-apps] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/tutorials/integrate-stackit-ai-model-serving-with-other-applications/ |
| [tut-opencode] | https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/tutorials/using-opencode-with-stackit-models/ |
| [regions] | https://docs.stackit.cloud/platform/regions/ (page says last updated Nov 6, 2026) |
| [product] | https://stackit.com/en/products/data-ai/stackit-ai-model-serving (product page; no date on it) |
| [learn-llm] | https://stackit.com/en/learn/knowledge/llm (STACKIT's own explainer, dated July 13, 2026; marketing, not terms) |
| [certs] | https://stackit.com/en/gtc/service-certificates (list of service certificates) |
| [sdk-go] | https://pkg.go.dev/github.com/stackitcloud/stackit-sdk-go/services/modelserving/v1api (reference of STACKIT's own Go SDK for the management API, generated from its API description; shown on the Go package index, not on a STACKIT domain) |

Pages opened that could not be read (see "Not confirmed"): the API reference
https://docs.api.eu01.stackit.cloud/documentation/model-serving/version/v1 and the service certificate PDFs.

## Reaching it
- Base URL and the chat path: `https://api.openai-compat.model-serving.eu01.onstackit.cloud/v1`, chat at
  `POST /chat/completions`, so `POST https://api.openai-compat.model-serving.eu01.onstackit.cloud/v1/chat/completions`
  [use] [start] [faq] [models].
- **One fixed address, with the region in the host name.** The same address is given for every model [models] and
  on every page; it holds the region code `eu01` and no project id. No second inference address (for example for
  region `eu02`) is documented on the pages read. The project is not in the address: it follows from the token.
- Other paths the model list names: `POST /completions` and `GET /models` for every chat model, `POST /embeddings`
  and `GET /models` for the embedding models [models].
- Hosted by the vendor, run by the customer, or both: the vendor. "Fully managed"; the models are shared by all
  customers ("used communally by all clients") [intro] [service]. No customer-run or dedicated variant of this
  service is described on the pages read.
- API format: OpenAI-compatible only ("OpenAI Compatible API") [service] [use]. There is no format of its own for
  inference. A second API, the "STACKIT AI Model Serving API (Product API)", manages auth tokens and describes the
  shared models; it is not an inference API and lives at another host (`model-serving.api.stackit.cloud`)
  [service] [tokens].
- API versions or dated headers a request must send: none documented. Only `Authorization` and
  `Content-Type: application/json` appear in the examples [use] [start].
- Request headers whose name holds an underscore are dropped by the service; the FAQ says not to rely on them
  [faq]. A preset must not put anything in such a header.
- The API is stateless: there is no chat id, and the whole conversation is sent with every call [use].
- Request body limit: 20 MiB for all models [models].

## Signing in
- Method: `Authorization: Bearer <token>`, where the token is a "STACKIT AI Model Serving auth token" [use]
  [start]. One token works for all shared models, chat and embedding [faq]. No project, deployment or region field
  is sent with an inference request.
- Not the same thing as a STACKIT account token: the token-management API is called with a STACKIT auth token of a
  user or service account, and that API issues the AI Model Serving auth token [tokens]. Whether a STACKIT user or
  service-account token is also accepted directly by the inference address: not confirmed (not stated on [tokens]).
- What the customer must create at the provider, in plain words: a STACKIT customer account, a user account and a
  project [start]; in the portal, open "AI Model Serving" in that project (switch the service on if asked), choose
  "Create token", give it a name and, if wanted, a lifetime, and confirm with "Order fee-based". The token is shown
  once and cannot be read again later [start] [tokens]. That token is the only value the customer pastes into a
  preset.
- The token can also be made through the Product API:
  `POST https://model-serving.api.stackit.cloud/v1/projects/[project-id]/regions/eu01/tokens` with a body holding
  `name`, `description` and `ttlDuration` (a whole number followed by `m`, `h` or `d`, for example `30d`) [tokens].
  This is for the customer's own automation; the library does not call it.
- Does it expire: **only if a lifetime was set.** The lifetime is optional in the portal [start] and in the API
  [tokens]; the troubleshooting text says tokens expire "based on the `ttlDuration` set during creation"
  [tut-opencode]. STACKIT's Go SDK documents the field as: "If not set the token will never expire." [sdk-go]. A
  default or a maximum lifetime is not stated on the pages read. A token has a state (`creating`, `active`,
  `deleting`, `inactive`) and a `validUntil` value in the management API [sdk-go]. What the inference address
  answers for an expired token (status, body): not confirmed.
- The token belongs to one project and one region: the management paths hold a project id and `regions/eu01`
  [tokens], and rate limits are counted per project over all its tokens [limits].

## What a request can do
| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | Yes, per model: every chat model on the list is marked as supporting tool calling. Documented only through a tutorial with a client framework (a function bound as a tool, the model answers with tool calls, the result goes back, the model answers). The raw request (`tools`) is not shown on that page. | `tools` is named in the FAQ's known issue: **never send an empty `tools` list or `null`**; leave the key out when there are no tools. Some Llama-based models, among them `cortecs/Llama-3.3-70B-Instruct-FP8-Dynamic`, then invent a function call. For `openai/gpt-oss-20b` the model list advises retries for tool calling, or a larger model. | [models] [tools-howto] [faq] |
| Forced tool call | **Not confirmed.** `tool_choice` (`auto`, `none`, `required`, a named function) is mentioned on no page read. | not documented | [tools-howto] [use] [faq] (none mentions it) |
| Parallel tool calls | **Not confirmed.** The tutorial says the `tool_calls` list "can easily contain multiple, possibly nested, tool calls", which says an answer may hold several calls; the example handles only the first. The field `parallel_tool_calls` is mentioned on no page read. | not documented | [tools-howto] |
| Structured output with a JSON Schema (and which schema keywords are refused) | **Not confirmed as a request feature.** Two mentions only: the release note for `openai/gpt-oss-120b` says the model supports "Structured Outputs", and the FAQ has a question about a "LengthFinishReasonError" "especially with structured output" (advice: `frequency_penalty` 0.7 or higher). No page shows `response_format`, a `json_schema` type, `json_object`, or guided-output fields, and none lists schema keywords. Not listed as a feature of any model on the model list. | not documented | [release] [faq] [models] |
| Tools together with structured output | **Not confirmed.** Not mentioned on any page read. | not documented | n/a |
| Streaming (and whether usage arrives in the stream) | **Not confirmed.** No page shows `stream`, a stream chunk, the wire format (server-sent events or other), `stream_options` or usage in a stream. The only mention is a tutorial sentence that chains of a client framework "can also be streamed or run asynchronously". | not documented | [tut-lcel-basic] [use] |
| System instructions (a role, or a separate field) | Yes, as a message with the role `system` in `messages`. Roles shown: `system`, `user`, `assistant`. | `messages[].role`, `messages[].content` | [use] [start] |
| Listing models | Yes: `GET /models` is listed under "Available Endpoints" for every model, and a tutorial calls the list operation of an OpenAI client library against the base address. The answer's shape is not shown. A second, richer list exists in the Product API (category, type `chat`/`embedding`, context length, region, address of each model), reached with a STACKIT account token, not with the inference token. | `GET https://api.openai-compat.model-serving.eu01.onstackit.cloud/v1/models` | [models] [tut-vision] [sdk-go] |

Fields of the OpenAI chat-completions format, as far as STACKIT's own pages name them:

| Field | Documented | Link |
|---|---|---|
| `model`, `messages`, `temperature` | yes, in the curl examples | [use] [start] |
| `max_completion_tokens` | yes: the only maximum-tokens field in the examples (curl and client-library tutorial). `max_tokens` is not shown; whether it is accepted is not confirmed. One application tutorial speaks of a "max tokens" setting of a third-party application. | [use] [start] [tut-vision] [tut-apps] |
| `frequency_penalty` | yes, named in the FAQ and set in tutorials | [faq] |
| `tools` | named (the empty-list issue); request shape not shown | [faq] |
| `tool_choice` (also `required` and a forced function) | not mentioned | n/a |
| `parallel_tool_calls` | not mentioned | n/a |
| `response_format` (JSON schema), guided-output fields | not mentioned | n/a |
| `stream`, `stream_options`, usage in a stream | not mentioned | n/a |
| image parts in `content` (`type: "text"`, `type: "image_url"` with a base64 data address) | yes, for the vision models; at most 10 images per request | [tut-vision] [models] |

Whether these differ per model: the documentation gives features per model only as far as the table below (tool
calling, reasoning, image input). Nothing else is stated per model.

Chat models named on the model list [models] (ids exactly as written there):

| Model id | Status | Context | Max output tokens | Tokens / minute | Requests / minute | Input | Tool calling | Reasoning |
|---|---|---|---|---|---|---|---|---|
| `Qwen/Qwen3-VL-235B-A22B-Instruct-FP8` | supported | 200K | 16,384 | 350,000 | 30 | text, image | yes | not listed |
| `Qwen/Qwen3.8-27B` | supported | 262K | 16,384 | 350,000 | 45 | text, image | yes | yes (thinking budget 8,192 tokens) |
| `Qwen/Qwen3.6-27B` | deprecated (migrate by December 8, 2026 [release]) | 262K | 16,384 | 350,000 | 45 | text, image | yes | yes |
| `cortecs/Llama-3.3-70B-Instruct-FP8-Dynamic` | supported | 128K | 4,096 | 200,000 | 80 | text | yes (see the empty-`tools` issue) | not listed |
| `openai/gpt-oss-120b` | supported | 131K | 16,384 | 200,000 | 30 | text | yes | yes |
| `google/gemma-4-31B-it` | supported | 256K | 16,384 | 200,000 | 80 | text, image | yes | yes |
| `openai/gpt-oss-20b` | supported | 131K | 8,192 | 200,000 | 80 | text | yes (retries advised) | yes |

Not chat models (a chat request to them fails, see errors): `intfloat/e5-mistral-7b-instruct` and
`Qwen/Qwen3-VL-Embedding-8B`, both embedding models [models]. Preview models carry the name prefix
`public-preview--`, have no service levels and can be removed at any time without notice; none was listed today
[preview]. Models are replaced a few times a year with about three months of notice [release], so a preset must not
hold a fixed model list.

## Limits and shapes
- Required fields a neutral request may lack: none documented. The examples send `max_completion_tokens`, but no
  page says it is required [use]. Each model has a maximum of output tokens (table above); an answer that reaches
  it is cut off [faq].
- Temperature range: not stated (examples use 0.1 and 0.01) [use]. Tool name rules: not stated. Model name shape:
  `<publisher>/<model name>`, case as written, for example `openai/gpt-oss-120b` [models]; preview models start
  with `public-preview--` [preview].
- The shape of a tool call in an answer, and of a tool result in a request: **the raw JSON is not shown on any page
  read.** The tutorial shows it only as a client framework presents it: a call has a name, arguments and an id of
  the form `chatcmpl-tool-<32 hex characters>`; the result goes back as a tool message that carries that id as its
  `tool_call_id`, after the assistant message that held the call [tools-howto]. In the tutorial's printed example
  the argument for an integer parameter came back as a string (`'k': '70'`) [tools-howto], so an adapter must check
  arguments against the tool's schema and not trust their types.
- The shape of a chat answer: **not shown.** The one answer printed for a chat request has
  `"object": "text_completion"` and `choices[].text` (with `logprobs`, `finish_reason`, `stop_reason`,
  `prompt_logprobs`), which is the shape of the older completions endpoint, under a request to `/chat/completions`
  [start]. The client-library tutorial reads `choices[0].message.content` [tut-vision]. The two pages disagree; the
  second fits the OpenAI chat format the service says it follows. Usage in that printed answer: `prompt_tokens`,
  `completion_tokens`, `total_tokens` [start].
- Finish reasons: only `length` is documented: the answer was cut off at the model's output limit; the FAQ advises
  sending the history with the partial answer back and asking to continue [faq] [start]. `stop`, `tool_calls` and
  others are not listed on any page read. A field `stop_reason` appears next to `finish_reason` in the printed
  answer [start]; its meaning is not explained.
- Error body shape: **not documented** for the inference API. (The management API's error has the fields `error`
  and `message` [sdk-go]; nothing says the inference API uses the same.)
- Statuses documented: `404 Not Found` when the `model` value is wrong, including a chat request to an embedding
  model or the reverse [faq]; `429 Too Many Requests` when a rate limit is passed [limits]. Statuses for a wrong or
  expired token, an invalid request or a server fault: not stated.
- Rate limits: per model (table above), counted **per STACKIT project over all its tokens**, as requests per minute
  and tokens per minute; tokens per minute = prompt tokens + 5 x generated tokens (embedding models: prompt tokens
  only). Short bursts above the limit are tolerated. A higher limit is asked through a service request [limits]
  [models].
- Rate-limit headers, sent with every answer: `x-ratelimit-limit-requests`, `x-ratelimit-limit-tokens`,
  `x-ratelimit-remaining-requests`, `x-ratelimit-remaining-tokens`, `x-ratelimit-reset-requests`,
  `x-ratelimit-reset-tokens` [limits]. The format of the two reset values (seconds, a duration text, a time) is not
  given and no example is shown. **A `Retry-After` header is not documented.** The page advises exponential backoff
  with jitter on 429 [limits].
- Provider-only fields a neutral client must not send: none are documented as extra request fields. What to avoid,
  from the pages read: an empty or `null` `tools` [faq]; headers with an underscore in the name [faq]; and, because
  nothing documents them, `tool_choice`, `parallel_tool_calls`, `response_format` and `stream_options` should be
  sent only when the caller asked for the feature, never as a default.

Synthetic example of the documented request, with an invented token placeholder:

```json
{
  "model": "openai/gpt-oss-120b",
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "Say hello."}
  ],
  "max_completion_tokens": 250,
  "temperature": 0.1
}
```

sent as `POST /v1/chat/completions` with `Authorization: Bearer <auth-token>` and `Content-Type: application/json`.

## Data
- Where requests are processed, as the provider states it: the inference address carries the region code `eu01`
  [use], the product page's price table lists the region "EU01" [product], and the platform's region page says:
  "STACKIT hosts all regions exclusively in Germany (`eu01`) and Austria (`eu02`)." [regions]. So: Germany, by
  combining the address with the region page. **No page of AI Model Serving itself names the country**: the
  introduction, the service description and the FAQ are silent on location [intro] [service] [faq]. The city of
  `eu01` is not on [regions]; a search summary of another STACKIT documentation page (Compute Engine regions) gives
  "Heilbronn/Germany", not opened today.
- Sovereignty statements: "By operating data centers exclusively in Germany and Austria, STACKIT ensures your
  digital sovereignty." [regions]. STACKIT's explainer says of this service: hosting in data centers in Germany and
  Austria, and "Data never leaves the European Union." [learn-llm]; that page is marketing, not terms. The product
  page speaks of the "data-sovereign STACKIT Cloud" [product]. A contractual statement (service certificate, data
  processing terms) could not be read today.
- The option not to store requests, and whether training on them is off by default: there is no option to set,
  because nothing is stored: "We do not store any customer data from the requests." and "We do not train any models
  using your data." [faq]; the product page says the same ("neither stored nor used to train models") [product].
  Preview models are handled the same way [preview]. Projects and customers are separated: "There will be no data
  exchange between projects or even between different customers." [service]. How long operational logs or usage
  counts are kept is not stated.
- The models are open-weight models of third parties, served by STACKIT; no page read says a request is passed on
  to the model's publisher [faq] [models].

## Not confirmed
- **The chat request reference.** Tried: [use] (one curl example, then a pointer to OpenAI's documentation), the
  API reference at https://docs.api.eu01.stackit.cloud/documentation/model-serving/version/v1 and at
  https://docs.api.stackit.cloud/documentation/model-serving/version/v1 (both give only the title "API Docs" when
  read as text; the content is loaded by script), and the API description file at two guessed addresses in
  STACKIT's public specification repository (404). By its description [service] and by the SDK generated from it
  [sdk-go], that reference covers the management API (tokens, model details), not chat.
- `tool_choice` in any form (`auto`, `none`, `required`, a named function): not on any page read.
- `parallel_tool_calls`, and whether a model returns several calls in one answer beyond the tutorial's remark.
- Structured output as a request feature: `response_format` with `json_schema` or `json_object`, guided-output
  fields, refused schema keywords, and which models support it (only `openai/gpt-oss-120b` is said to support
  "Structured Outputs", in a release note).
- Tools together with structured output.
- Streaming: whether `stream` is supported, the wire format, `stream_options`, usage in the stream, tool calls in a
  stream. Searched the documentation for "stream", "streaming", "response_format", "tool_choice": no page of the
  service documents them.
- Whether `max_tokens` is accepted next to `max_completion_tokens`.
- The raw shape of a chat answer, of a tool call and of a tool result; the page that prints an answer shows the
  completions shape under a chat request [start].
- Finish reasons other than `length`; the meaning of `stop_reason`.
- The error body of the inference API; the statuses for a rejected token, an expired token, an invalid request and
  a server fault; whether 429 carries `Retry-After`; the format of the `x-ratelimit-reset-*` values.
- Temperature range and tool name rules.
- The shape of the `GET /models` answer on the inference address.
- Default and maximum token lifetime. "Never expires when no lifetime is set" comes from the SDK's reference
  [sdk-go], not from a documentation page.
- Whether a second inference address exists for region `eu02`.
- A contractual statement on data location, logging and retention. Tried the service certificate (list at [certs];
  newest listed: v1.4, effective November 6, 2026,
  https://stackit.com/en/asset/download/61500/file/Service_Certificate_STACKIT_AI_Model_Serving_1-4.pdf): the PDF
  opened but its text could not be extracted; only its title and section names (service description, key features,
  SLA specifics, backup, additional terms) were readable. The data processing agreement was not found.
- The city of region `eu01` (from a search summary only: Heilbronn).
- No page read today addressed the reader with instructions.
