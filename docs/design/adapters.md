# The adapter map

Task LG-11. Date: 2026-11-27. This document says which API formats the fifteen providers of decision G3 speak, which
adapter serves which preset, what each preset holds, what each adapter can do, and what the contract of slice S1 must
still gain before the adapters can be built.

**Sources.** Every fact about a provider comes from its file under `docs/providers/`, read on 2026-11-27. A reference
such as (`kimi.md`, "Reasoning content") names the file and its section. Nothing here is from memory. Where a provider
file says "not confirmed", this map says **not confirmed** and means: to be established by the connection check
(LR-27) or the hand-run live check (LR-43). The contract described here is the contract as built in `src/`, not
`docs/design/contract.md`, which predates decisions G33 to G62.

**Words.** "Preset entry" is one `Preset` value in the catalogue. "Option" is one entry of `Preset::$adapterOptions`.
"Until checked" marks a capability that no provider file confirms: the preset reports the value that cannot fail, and
a host raises it after the connection check (section 6.3).

## Contents

1. The formats and the adapters
2. The chat-completions format: dialect differences and options
3. The Responses format (decision G59, open)
4. The other three adapters
5. Changes the contract needs
6. Capabilities per adapter and per preset
7. Providers without a hosted text chat API
8. The preset catalogue
9. The preset entries
10. Model lists
11. Data location and the option not to store
12. Requirement → where the map meets it
13. What a provider file says that this map does not place

---

## 1. The formats and the adapters

### 1.1 Formats found

| Format | Who speaks it as its main documented chat API | Source |
|---|---|---|
| Chat completions | OpenAI (its origin), Azure OpenAI, Grok, Kimi, STACKIT, Aleph Alpha, LightOn, LM Studio; the customer's server for Silo AI, Pleias and Kyutai; Mistral's native format is close to it | `overview.md` 3.1 |
| Messages | Claude | `anthropic.md`, "Reaching it" |
| Generate-content | Gemini, on both ways in | `gemini.md`, "Reaching it" |
| Chat v2 | Cohere | `cohere.md`, "Reaching it" |
| Responses | recommended or required at OpenAI, Azure OpenAI and Grok for their newest models | `overview.md` 3.1; decision G59 |

Formats that exist and are not built, with the reason:

| Not built | Why | Source |
|---|---|---|
| The OpenAI-compatible layers of Claude, the Gemini API, Google Cloud, Cohere and Mistral | Each provider calls its own format the fuller one; the layers are "not production-ready", beta, a preview, or without a stated status | `overview.md` 3.1 |
| The Interactions API of Gemini | Server-side state on by default (LR-01) | `gemini.md`, introduction |
| Threads and ask (LightOn), `/api/v1/chat` (LM Studio), the older endpoints and the stateful Responses API (Aleph Alpha) | Server-side state or server-run tools (LR-01, LR-14) | `lighton.md`, "Not to confuse with chat completions"; `lm-studio.md`, "Reaching it"; `aleph-alpha.md`, "Reaching it" |
| The Anthropic-compatible endpoints of Kimi, LM Studio and AMD's containers | A second way in to a server that already speaks chat completions | `overview.md` 3.1 |
| The older `functions` form of tool calling | Deprecated at its origin; the only documented user is LightOn (section 2.3) | `openai.md`, "What a request can do"; `lighton.md`, same section |
| The dated address form of Azure OpenAI | The v1 form needs no `api-version`; see 1.3 | `azure-openai.md`, "Reaching it" |

### 1.2 The adapters

One adapter per format (LR-20). Four are built by LG-12 to LG-16. The fifth, for the Responses format, is built by
LG-34: decision G59 is decided as option (a).

| # | Class | Folder | Format | Preset entries |
|---|---|---|---|---|
| A1 | `ChatCompletionsAdapter` | `src/Adapter/OpenAiCompatible` | chat completions | `azure-openai`, `grok`, `kimi`, `kimi-cn`, `stackit`, `aleph-alpha`, `lighton`, `lm-studio`, `mistral`, `mistral-eu`, `silo-ai`, `pleias`, `kyutai`, `openai-compatible`; and `openai`, `openai-eu` unless G59 says otherwise |
| A2 | `MessagesAdapter` | `src/Adapter/Anthropic` | Messages | `claude` |
| A3 | `GenerateContentAdapter` | `src/Adapter/Gemini` | generate-content | `gemini`, `vertex-ai`, `vertex-ai-eu` |
| A4 | `ChatV2Adapter` | `src/Adapter/Cohere` | Chat v2 | `cohere` |
| A5 | `ResponsesAdapter` (built by LG-34) | `src/Adapter/OpenAiResponses` | Responses | the presets that G59 (a) moves to it; see section 3 |

Every adapter extends `Adapter\Shared\AbstractAdapter`, is `final readonly`, and passes `Testing\ContractTestCase`.

### 1.3 Decisions on the formats

| Question | Decision | Why, with the evidence |
|---|---|---|
| Mistral: own adapter or options on A1 | **Options on A1** (decision G64). No `src/Adapter/Mistral`. | The differences (`mistral.md`, "Differences from the plain OpenAI chat-completions format") are of three kinds. Data: `max_tokens`, `tool_choice` `any`, `name` on a tool message, no `store`. Tolerant reading that other servers of this format need too: content as a string or a list, arguments as a string or a map, a flat error body. One carrier for reasoning that must go back (section 5.1), which Kimi needs in another form. None needs a second class. Its own OpenAI-compatible endpoint is a public preview with two documented addresses and is not used. |
| LightOn | A1 at `/api/v3`. Tool calling reported **none until checked**; the older `functions` form is not built. Structured output by the emulation of LG-08; `guided_json` is not sent. Model list by path and id field (section 10). | The reference lists only `functions` and `function_call`; `tools` is not confirmed. `guided_json` is in a guide only, on the deprecated v2 path (`lighton.md`, "What a request can do"). |
| Azure OpenAI: v1 or dated form | **v1 only** (decision G65): `https://{resource}.openai.azure.com/openai/v1`, the `model` of the request is the deployment name, no `api-version` is sent. No Azure variant class: it is a preset on A1. | "`api-version` is no longer a required parameter with the v1 GA API." The dated reference could not be read and its retirement date is not confirmed (`azure-openai.md`, "Reaching it", "Not confirmed"). |
| Azure OpenAI: key header or identity token | Both, and the key as bearer: `HeaderKey` in `api-key`, `BearerKey`, `BearerToken` from the caller's supplier. | "Any one of the three methods is accepted." Two token scopes are documented and which one a resource accepts is not confirmed; the supplier is the caller's, so the preset's description names both (`azure-openai.md`, "Signing in"). |
| Cohere | Its own adapter A4 on Chat v2. | The Compatibility API has no stated status, lists `parallel_tool_calls` as unsupported and does not mention `tool_choice` (`cohere.md`, "The Compatibility API"). |
| Gemini: both ways in | One adapter A3, three entries: a key (`gemini`), a Google Cloud region (`vertex-ai`), the EU multi-region (`vertex-ai-eu`). The path after the base URL is the same: `/models/{model}:generateContent`. | "the same `generateContent`" on both; the addresses and the sign-in differ (`gemini.md`, "Reaching it", "Signing in"). |
| Claude | Its own adapter A2 on the Messages format, API version `2026-10-21`. | (`anthropic.md`, "Reaching it") |

---

## 2. The chat-completions format: dialect differences and options

Adapter A1. The rule: a difference that is **data** becomes an option with a neutral name and a fixed list of values.
A difference that is a **shape** is read tolerantly by the one adapter, for every preset, without an option. Nothing
is decided by the id of a preset.

### 2.1 What A1 always does (no option)

| Rule | Why | Source |
|---|---|---|
| Sends only: `model`, `messages`, `tools`, `tool_choice`, `response_format`, the maximum-tokens field, `temperature`, `stream`, `stream_options`, `store`. Nothing else, ever. | Provider-only fields must not be sent: `search_parameters`, `partial`, `ttl`, `guided_json`, `steering_concepts`, `safe_prompt` and others | `grok.md`, "Server-side tools…"; `kimi.md`, "Provider-only fields…"; `lm-studio.md`, "Limits and shapes"; `aleph-alpha.md`, "Steering and guardrail fields…" |
| Leaves `tools` and `tool_choice` out when the request has no tools. Sends `tool_choice` only when it is not "auto". | An empty or `null` `tools` makes some models invent a call | `stackit.md`, "What a request can do" |
| Never sends `parallel_tool_calls`, `n`, `stop`, penalties, `seed`, `reasoning_effort`. | The neutral request has none of them; one server rejects `parallel_tool_calls` in any value; several refuse the others | `aleph-alpha.md`, "The OpenAI format…"; `grok.md`, "Limits and shapes"; `kimi.md`, "Temperature and the other sampling fields" |
| Sets no header with an underscore in its name. | Such headers are dropped | `stackit.md`, "Reaching it" |
| Reads answer `content` as a string, `null`, or a list of entries; the text is the joined `text` entries. | | `mistral.md`, "Limits and shapes" |
| Reads tool-call `arguments` as a string holding JSON or as a map; a string that is not a JSON object is an unreadable call (LR-17), not an error. Checks nothing else: an integer that arrives as a string stays as it came. | | `mistral.md`, same; `openai.md`, "Limits and shapes"; `stackit.md`, same |
| Recognises a tool call by its presence. An answer that holds tool calls ends as "tool calls" unless it was cut off. | The finish reason does not always say so | `grok.md`, `aleph-alpha.md`, "Finish reasons…" |
| In a stream: joins argument pieces per `index`; also takes a call that arrives whole in one chunk; skips a chunk with empty `choices` unless it holds usage; ignores unknown fields (`obfuscation`, filter results, `stats`). Ends at `data: [DONE]` or at the end of the body (G46). | | `openai.md`, `azure-openai.md`, `grok.md`, `kimi.md`, `lm-studio.md`, stream sections |
| Reads usage wherever it arrives (top level of a chunk, or inside a choice); the last value seen wins; nothing is added up. | Two places on one page; a rising count per chunk on another | `kimi.md`, "Streaming"; `grok.md`, "What a request can do" |
| Reads `reasoning_content` and `reasoning` on a message or a delta and keeps them out of the text. | | `azure-openai.md`, `grok.md`, `kimi.md`, `lm-studio.md` |
| A non-null `message.refusal` ends the answer as "refusal" (section 5.2). | | `openai.md`, `azure-openai.md`, `grok.md` |
| Reads the provider code from, in this order: `error.code` when it is a word, `error.type`, a top-level `error` that is a string, a top-level `type`, a top-level `code`. A body that is not JSON gives no code. | Four body shapes and several servers with none | `openai.md`, `mistral.md`, `lighton.md`, `kimi.md`, error sections |
| Reads `retry-after-ms` (milliseconds) when `Retry-After` is absent. | | `azure-openai.md`, "Limits and shapes" |
| Refuses a tool name outside `^[A-Za-z0-9_-]{1,64}$` as `rejected` before sending. | The rule three files state; the widest rule that every documented server accepts | `openai.md`, `azure-openai.md`, `aleph-alpha.md` |

### 2.2 Dialect options of A1

These names are final: the README documents them as written here. An option that is left out has its default. A value
outside the list is `not_configured`, raised when the gateway is asked for capabilities or a call.

| Option | Values (default first) | What it changes | Why it exists |
|---|---|---|---|
| `max_tokens_field` | `max_completion_tokens`, `max_tokens` | the name of the maximum-tokens field | `overview.md` 3.3 |
| `system_role` | `system`, `developer` | the role a system message is sent with | `openai.md`: `developer` replaces `system` for newer models; whether they still take `system` is not confirmed |
| `system_messages` | `separate`, `joined` | one message per system message, or one message with the texts joined by a blank line | `aleph-alpha.md`: only one system message, first |
| `assistant_content_without_text` | `null`, `empty_string` | the `content` of an assistant message that holds only tool calls | `kimi.md`, `aleph-alpha.md`: `content` is required there |
| `required_word` | `required`, `any` | the `tool_choice` word for "must call some tool" | `mistral.md`: the guide documents `any` |
| `named_tool_choice` | `object`, `required_single`, `unsupported` | how one named tool is forced: the named-function object; or `required` with only that tool in `tools`; or not at all | `kimi.md`: the object form returns 400 with thinking on; `lm-studio.md`: not documented |
| `tool_strict` | `omit`, `false` | whether `strict: false` is written on every tool | `kimi.md`: `strict` defaults to `true` there and the schema dialect is unpublished |
| `tool_result_name` | `false`, `true` (boolean) | whether a tool message also carries `name` | `mistral.md`: every guide example sends both |
| `json_schema_strict` | `omit`, `false`, `when_fits` | the `strict` flag of `response_format`: not sent; always `false`; or `true` only when the caller's schema already fits the subset of `strict_subset` (section 5.9) | `openai.md`, `azure-openai.md`, `kimi.md` |
| `strict_subset` | `standard`, `narrow` | which documented subset `when_fits` tests | `azure-openai.md`: a narrower subset than `openai.md` records |
| `json_schema_key` | `schema`, `schema_definition` | the key that holds the schema inside `json_schema` | `mistral.md`: guide against reference; not confirmed |
| `stream_usage` | `on_request`, `unasked` | whether `stream_options: {"include_usage": true}` is sent with a stream | `overview.md` 3.9: three servers do not document the field |
| `store_field` | `omit`, `false` | whether `store: false` is sent (LR-31) | `openai.md`, "Data" |
| `reasoning_carrier` | `none`, `message_field`, `content_entries` | whether reasoning that came with an assistant answer goes back with it, and where it sits (section 5.1) | `kimi.md`, "Reasoning content"; `mistral.md`, "Limits and shapes" |
| `inline_reasoning` | `keep`, `strip` | whether one leading `<think>…</think>` block is cut from the answer text | `pleias.md`, "What makes these models unlike a general chat model" |
| `finish_content_filter` | `withheld`, `unknown` | whether the finish reason `content_filter` means withheld output or "not understood" | `aleph-alpha.md`, "Finish reasons…" |
| `models_path` | `/models`, or another path under the base URL | where the model list is | section 10 |
| `model_id_field` | `id`, or another field name | the field of a model entry that is put in a request's `model` | section 10 |

### 2.3 Capability options (every adapter)

Every adapter reads the same capability options. They take the words of `Contract\Support`, so the name in the README
(`'structured_output' => 'emulated'`) stays valid.

| Option | Values | Meaning for A1 |
|---|---|---|
| `tool_calling` | `native`, `none` | |
| `forced_tool_call` | `native`, `emulated`, `none` | must agree with `named_tool_choice`: `object` → native, `required_single` → emulated, `unsupported` → none; a preset sets `named_tool_choice` and the capability follows |
| `parallel_tool_calls` | `native`, `none` | nothing is sent; it is what the caller is told |
| `structured_output` | `native`, `emulated`, `none` | native: `response_format` with `json_schema`; emulated: `StructuredOutputEmulation` |
| `tools_with_structured_output` | `native`, `emulated`, `none` | emulated: the tools are sent and the schema goes into the system text |
| `streaming` | `native`, `emulated` | emulated: one request without `stream`, the answer as the events of a stream |
| `model_listing` | `native`, `none` | |
| `temperature` | `accepted`, `not_accepted` | section 5.3 |
| `context_length` | an integer | only where a preset can state one for every model; no shipped preset does |

The defaults of A1, for a preset that sets nothing: every capability native, except `tools_with_structured_output`,
which is **emulated**. No provider file confirms tools and `response_format` in one chat-completions request
(`overview.md` 3.6), and the emulated route works wherever tools work.

### 2.4 Every dialect difference, placed

| Provider | Difference (`overview.md` 3.1 and the provider file) | Placed as |
|---|---|---|
| OpenAI | `max_completion_tokens` | `max_tokens_field` |
| | role `developer` | `system_role` |
| | `message.refusal` | 2.1 |
| | newer models reject an explicit `n` | 2.1: never sent |
| | tool calling per model | section 3; `Preset::$modelOverrides` (5.4) |
| Azure OpenAI | `model` is the deployment name | nothing: the model is free text (G18) |
| | content-filter fields; 400 with code `content_filter` | 2.1 (ignored fields); the status gives `rejected` with the code |
| | a narrower schema subset | `strict_subset: narrow` |
| | `reasoning_content` | 2.1 |
| | `api-key` header or Entra token | credential kinds of the preset |
| | the dated form with `api-version` | not built (1.3) |
| Grok | `max_completion_tokens` counts visible output only | preset note (5.3) |
| | `stop` and penalties return 400 | 2.1: never sent |
| | finish reasons `stop`, `length`, `end_turn` | 2.1 and 5.2 |
| | tool-call JSON, `response_format` shape and error body not documented | built to the plain shape, **not confirmed**; `structured_output: emulated` until checked |
| | `search_parameters` | 2.1: never sent |
| Kimi | sampling fields must not be sent | `temperature: not_accepted` |
| | `max_completion_tokens` includes reasoning | preset note (5.3) |
| | `reasoning_content` must come back | `reasoning_carrier: message_field` |
| | no `parallel_tool_calls` | 2.1: never sent |
| | own schema dialect | `json_schema_strict: false`, `tool_strict: false` |
| | a content refusal is a 400 | the status gives `rejected` with the code |
| | two hosts | two preset entries (section 9) |
| STACKIT | only `max_completion_tokens` shown | `max_tokens_field` |
| | empty or `null` `tools` | 2.1 |
| | headers with an underscore | 2.1 |
| | `tool_choice`, `response_format`, `stream` on no page | capabilities until checked (section 6) |
| Aleph Alpha | `max_tokens` only | `max_tokens_field` |
| | `parallel_tool_calls` rejected | 2.1: never sent |
| | `response_format` only as `json_schema` | the only form A1 sends |
| | one system message, first | `system_messages: joined` |
| | base address with or without `/v1` | the customer's base URL is used exactly as given (LR-29) |
| | no tool calls and no error body in the reference | 2.1 (presence; no code) |
| LightOn | only `functions` and `function_call` | not built; `tool_calling: none` until checked |
| | `guided_json` | not sent; `structured_output: emulated` |
| | a flat error body | 2.1 |
| | model list without `id` | `models_path`, `model_id_field` |
| | `/api/v3` in the path | part of the base URL |
| LM Studio | `max_tokens` | `max_tokens_field` |
| | no `json_object` | never sent by A1 |
| | `tool_choice` `required` on one engine only; a named function not documented | `named_tool_choice: unsupported` |
| | reasoning in `reasoning_content` or `reasoning` | 2.1 |
| | `ttl`, `draft_model`, `top_k`, `repeat_penalty` | 2.1: never sent |
| | no error body documented | 2.1: no code |
| Mistral | content as a string or a list | 2.1; `reasoning_carrier: content_entries` |
| | arguments as a map or a string | 2.1 |
| | `tool_choice` `any` | `required_word: any` |
| | `max_tokens` | `max_tokens_field` |
| | `random_seed` | 2.1: no seed is sent |
| | three error-body shapes | 2.1 |
| | no `store` | `store_field: omit` |

---

## 3. The Responses format (decision G59, open)

**This section decides nothing.** G59 is the owner's. It lays out the options, says what the build does until then,
and gives a recommendation.

### 3.1 What the files say

| Provider | Statement | Source |
|---|---|---|
| OpenAI | From GPT-5.4 on, Chat Completions supports tool calling only with `reasoning_effort` `none`; GPT-6 Astra and GPT-6.1 Sol "require the Responses API for tool calling", and `none` returns 400 on GPT-6 Astra, GPT-6.2 Astra and GPT-6.2 Sol. Chat Completions is not deprecated and has no end date. | `openai.md`, "Which API OpenAI recommends…" |
| Azure OpenAI | The same, as a table of models; ten models have no Chat Completions at all. The `model` is a deployment name, so the library cannot see which model it reaches. | `azure-openai.md`, "Newest models…" |
| Grok | Responses is recommended; Chat Completions is called "deprecated" on two pages and not on the reference; no end date; fields are still added to it. No page says function calling is refused there for any model. | `grok.md`, "Which API xAI recommends…" |

The Responses format stores by default and can keep the conversation at the provider (`previous_response_id`). An
adapter for it sends `store: false`, never sends `previous_response_id`, and resends the whole transcript (`openai.md`,
`azure-openai.md`, `grok.md`, same sections).

### 3.2 The options and their consequences

| | (a) A Responses adapter; the three presets use it | (b) Chat completions only | (c) Both; the preset chooses |
|---|---|---|---|
| LR-20 | A fifth format, one adapter A5 for three providers. | Four formats. | Five formats. |
| LR-23 | Holds, on one condition: the opaque part of 5.1 exists first, because with `store: false` the caller resends earlier reasoning items itself (`azure-openai.md`, "Data"; `grok.md`: "encrypted reasoning that can be carried across turns"). No change to the interface. | Holds. | As (a). |
| LR-01, LR-09 | Hold only by discipline: `store: false` on every request and no `previous_response_id`, each with its own test. | Hold by the format: it has no state. | As (a) for the Responses entries. |
| LR-31 | The adapter sends `store: false`; all three presets can say so. Grok gains a request option it lacks on chat completions; its 30-day audit storage is a team setting either way (`grok.md`, "Data"). | OpenAI and Azure send `store: false`; Grok has nothing to send. | Per entry. |
| Tool calling the preset can truthfully report | Native on every model the files name. | **Per model.** OpenAI: absent on the models named in 3.1, and from GPT-5.4 on only when the adapter also sends `reasoning_effort: none`, which a neutral request cannot ask for. Azure: unknowable from a deployment name. Grok: native today. Reported as native "until checked"; a wrong guess is a `rejected` from the provider. | Truthful per entry. |
| Forced tool call (G16) | Native. | Fails on the models where tool calling fails. The loop then needs native structured output for the final answer, which works there. | Per entry. |
| Structured output | Native (`text.format`). Tools with structured output: stated by Grok's guide on this API; not confirmed for OpenAI and Azure. | Native on OpenAI and Azure; on Grok the shape is not confirmed, so emulated. | Per entry. |
| Models with no Chat Completions (ten at Azure) | Reachable. | Not reachable. | Reachable. |
| Cost | One more adapter and its fixtures. The stream is a different event list; the provider files read it only in part (`azure-openai.md`: the Responses guide through a summary; `openai.md`: tool-calling examples only in the Responses form). **A provider-research pass on the Responses format is needed before it is built.** | None. | (a), plus two entries per provider in the catalogue. |
| Risk | A bug that drops `store: false` stores customer text for 30 days at the provider. | The three providers move further away from this API; the newest models lose tool calling one by one. | Both, and a choice the customer cannot judge. |

### 3.3 What the build does until G59 is decided

1. A1 is built in full (LG-12). Fourteen other entries need it whatever G59 says.
2. `grok` and `azure-openai` are built on A1 now. Their files document chat completions with tools today. Their
   presets are data: if G59 becomes (a), one line of each changes.
3. `openai` and `openai-eu` wait, as G59 says. They are their own task (LG-33), so no other preset waits with them.
4. The opaque part (LG-29) is built first in any case: four other providers need it, and it is the one thing A5 would
   need from the contract.
5. A5 is a task (LG-34) that starts only after the decision, and with its own reading of the provider pages.

The map fits either outcome. Under (b), `openai` and `openai-eu` become A1 entries with the options in section 9 and
LG-34 is dropped. Under (a) or (c), A5 joins table 1.2, the three presets name it, and nothing else in this document
changes.

### 3.4 Recommendation

**Recommended: (a).** Build A5 and let `openai`, `openai-eu`, `azure-openai` and `grok` use it; keep A1 for the other
entries. A host that wants chat completions at one of these three can still reach it with `openai-compatible` or a
preset of its own (G34).

Why: the application gets every structured answer as a forced tool call today (G16), and option (b) loses exactly that
on the newest models of two of the three providers, with no way for the library to tell beforehand at Azure. Option (c)
doubles the entries and hands the customer a choice it cannot judge. The price of (a) is the discipline of
`store: false`, which a test in the shared set can hold.

This is a recommendation. The row G59 stays `open`.

---

## 4. The other three adapters

### 4.1 A2, the Messages format

Source for every row: `anthropic.md`, the section named.

| Topic | Design | Section |
|---|---|---|
| Address | `POST {base}/v1/messages`; the list at `GET {base}/v1/models`. | Reaching it |
| Headers | `anthropic-version` from the option `api_version` (the shipped preset: `2026-10-21`); always sent. `content-type: application/json`. The setting `workspace_id` is sent as `anthropic-workspace-id` when the customer filled it in (`PresetField::$header`, 5.5). | Reaching it; Signing in |
| System text | The texts of the system messages, joined by a blank line, in the top-level `system`. | What a request can do |
| Messages | Roles `user` and `assistant`. Neighbouring neutral messages that map to the same role become one message. | The shape of a request |
| Tool results | Every neutral tool message that follows one assistant message becomes one `tool_result` block; together they are one `user` message, the blocks first. An unreadable or failed result is the caller's text; `is_error` is not sent (the contract has no such flag). | The shape of a tool call… |
| Tool calls | `tool_use` blocks; `input` is an object, so a call is always readable (see G107); the raw arguments are its JSON. | same |
| Tool choice | auto → left out; none → `{"type":"none"}`; required → `{"type":"any"}`; one tool → `{"type":"tool","name":…}`. | What a request can do |
| Maximum tokens | Required by the format. The neutral value when given; otherwise the option `default_max_output_tokens` (5.3). | Limits and shapes |
| Temperature | Never sent (see G105); the capability says "not accepted" (5.3). | Reaching it |
| Thinking | The adapter never asks for it. `thinking` and `redacted_thinking` blocks that arrive are kept in the opaque part and go back unchanged (5.1). | Limits and shapes |
| Structured output | Native: `output_config.format` with `json_schema`. The schema policy of 5.9. | Structured output |
| Stream | Named events; text from `text_delta`; a tool call starts at `content_block_start` and completes at `content_block_stop`; usage from `message_start` and the last `message_delta` (cumulative, so the last value wins); unknown events are skipped; an `error` event is thrown as a failure with its type as code: `overloaded_error` → `unavailable`, `rate_limit_error` → `rate_limited`, any other → `provider_error`. A stream without `message_stop` is a broken stream. | Streaming |
| Usage | Input: `input_tokens` plus the two cache counts. Output: `output_tokens` (thinking included). | Usage fields |
| Finish reasons | 5.2. | Finish reasons… |
| Errors | Code: `error.details.error_code` when present, else `error.type`. A 429 with `enforced_spend_limit_reached` is `rejected`. `x-should-retry: false` stops the retry (5.6). 529 is `unavailable` by its status. | Error body shape… |
| Tool names | Outside `^[a-zA-Z0-9_-]{1,128}$`: `rejected` before sending. | Temperature range; tool name rules… |
| Not sent | `inference_geo`, `metadata`, `service_tier`, `thinking`, `stop_sequences`, a prefilled last assistant message (a transcript that ends with an assistant message is refused as `rejected`: the format reads it as a prefill, which current models reject). | The shape of a request |

### 4.2 A3, the generate-content format

Source for every row: `gemini.md`, the section named.

| Topic | Design | Section |
|---|---|---|
| Address | `POST {base}/models/{model}:generateContent`; a stream at `:streamGenerateContent` with the query `alt=sse`. The base URL of the entry carries everything before `/models` (section 9). A model name that cannot stand in a path is `rejected` before sending. | Reaching it |
| Field names | camelCase, as the references give them. | Limits and shapes |
| System text | `systemInstruction.parts[].text`, the system texts joined by a blank line. | What a request can do |
| Messages | Roles `user` and `model`. A tool result is a `functionResponse` part in a `user` content: all results of one round together, in the order of the calls, each with `name`, with `id` when the call had one, and `response: {"output": <text>}`. | Limits and shapes, "Answer" |
| Tool calls | `functionCall` parts, anywhere in the parts; `args` is an object. A call without an `id` gets one from the adapter (`call-0`, `call-1`, by position in the answer); such an id is not sent back. | same |
| Tools | `functionDeclarations` with `parametersJsonSchema` (plain JSON Schema). | Schema dialect |
| Tool choice | auto → `AUTO`; none → `NONE`; required → `ANY`; one tool → `ANY` with `allowedFunctionNames`. | What a request can do |
| Thought signatures | Kept in the opaque part and sent back in the same part (5.1). For an assistant message the caller built by hand or changed, the documented value `skip_thought_signature_validator` is sent on the first call part. | Thought signatures |
| Structured output | Native. The option `structured_output_field`: `response_json_schema` (default: `responseMimeType: "application/json"` with `responseJsonSchema`) or `response_format` (`responseFormat.text` with `mimeType` and `schema`). **Not confirmed**: the pages give three forms; the live check must test this first. The OpenAPI-subset `responseSchema` is not built. | Schema dialect |
| Text | The `text` of the parts without `thought: true`. | Answer |
| Usage | Input: `promptTokenCount`. Output: `candidatesTokenCount` plus `thoughtsTokenCount`. In a stream the last value seen wins. | Usage |
| Finish reasons | Both spellings (with and without the `FINISH_REASON_` prefix). 5.2. | Finish reasons |
| A blocked prompt | A 200 without candidates and with `promptFeedback.blockReason`: thrown as `rejected` with the reason as code (5.2). | same |
| Errors | Code: the first `details[].reason`, else `status`, in lower case. 400 with reason `API_KEY_INVALID` is `credentials_rejected`. 402 is `rejected` by its status. No retry hint is documented. | Errors |
| `store` | The option `store_field` (`omit`, `false`): `gemini` sends `store: false`; the Google Cloud entries have no such field. | Data |
| Tool names | Outside `^[A-Za-z_][A-Za-z0-9_]{0,63}$`: `rejected` before sending. The form the file gives as safe for both ways in. | Limits and shapes |
| Not sent | `safetySettings`, `cachedContent`, `labels`, `serviceTier`, `streamFunctionCallArguments`, `?key=` in the address. | Request; Signing in |

### 4.3 A4, the Chat v2 format

Source for every row: `cohere.md`, the section named.

| Topic | Design | Section |
|---|---|---|
| Address | `POST {base}/v2/chat` (the stream on the same address, `stream` always sent explicitly); the list at `GET {base}/v1/models`. | Reaching it |
| Messages | Roles `system`, `user`, `assistant`, `tool`; a tool result is one `tool` message per call with `tool_call_id` and string content. | Messages |
| Tool calls | `message.tool_calls[]`; `arguments` is a string holding JSON and may be invalid (LR-17). `tool_plan` is not answer text: it is kept in the opaque part with `thinking` blocks and goes back with the assistant message (5.1). | The shape of a tool call… |
| Tool choice | auto → left out; none → `NONE`; required → `REQUIRED`; one tool → `REQUIRED` with only that tool in `tools` (emulated). | What a request can do |
| Structured output | Native: `response_format` of type `json_object` with the schema under the key of the option `schema_key` (`json_schema` default, or `schema`). **Not confirmed**: reference against guide. | same |
| Tools with a response format | The format refuses the pair. The adapter sends the tools and puts the schema in the system text (emulated). `strict_tools` is not sent (experimental). | same |
| Stream | Named events; text from `content-delta` of a text block; a call starts at `tool-call-start` and completes at `tool-call-end`; usage and finish reason in `message-end`; `delta.error` there is a `provider_error`. | The stream |
| Usage | `usage.tokens`; a number such as `12.0` is read as 12; a missing `usage` is "not reported". | Usage |
| Finish reasons | 5.2. | Finish reasons… |
| Errors | No code exists. 402 and 498 are `rejected` by their status. No retry hint is documented. | Error body shape… |
| Not sent | `safety_mode`, `documents`, `citation_options`, `thinking`, `priority`, `strict_tools`, `X-Client-Name`. | Request |

---

## 5. Changes the contract needs

The package is unreleased, so a breaking change is allowed. Each change says whether it breaks what slice S1 built.
"Compatible" means: code written against S1 still compiles and behaves the same.

| # | Change | Classes | Breaks S1? | Task |
|---|---|---|---|---|
| 5.1 | The opaque part | new `Contract\OpaquePart`; `Message`, `Answer`, `AbstractAdapter::answer()`, the fake, the shared set | compatible | LG-29 |
| 5.2 | Finish reason "refusal"; an empty withheld answer is an answer; a refused prompt is `rejected` | `FinishReason`, the adapter base, the fake, the shared set | **one break**: a `match` over `FinishReason` without a default | LG-30 |
| 5.3 | Temperature as a capability | `Capabilities`, the adapter base, the fake | compatible | LG-30 |
| 5.4 | Options per model | new `Preset\ModelOverride`; `Preset`, `AbstractAdapter` | compatible | LG-31 |
| 5.5 | A setting sent as a header; a storage note; a preset with changed options | `PresetField`, `Preset`, the adapter base | compatible | LG-31 |
| 5.6 | "Do not retry" without changing the reason | `AbstractAdapter` (one new hook) | compatible | LG-30 |
| 5.9 | The schema helper | new `Adapter\Shared\SchemaShape` | compatible (new) | LG-32 |

### 5.1 The opaque part

**What the files say.** Five providers return data with an assistant answer that must go back unchanged in the next
request of a tool loop (`overview.md` 3.5):

| Provider | The data | When it is missing |
|---|---|---|
| Claude | `thinking` blocks with `signature`, `redacted_thinking` blocks | altered blocks return 400; thinking cannot be switched off on several models |
| Gemini | `thoughtSignature` on a part | 400 on Gemini 3 and 4 models |
| Kimi | `reasoning_content` on the message | not stated |
| Mistral | `thinking` entries in the content | "significantly degrades output quality" |
| Cohere | `tool_plan`, `thinking` blocks | the documentation says to send the message back as it came |

**The change.** One new content part. `Message` content is already a list of parts (G10).

`final readonly class Contract\OpaquePart implements ContentPart`

| Field | Type | Validation |
|---|---|---|
| `data` | `string` | not empty; valid UTF-8; otherwise `InvalidArgumentException` |

Its docblock says: data that came with an answer and must go back with it, unchanged; it has no meaning in the
contract; do not read it, do not change it, do not show it.

| Class | Change |
|---|---|
| `Message` | An `OpaquePart` may stand only in an assistant message, at most once; otherwise `InvalidArgumentException`. `text()` is unchanged: it joins text parts only. |
| `Answer` | New last constructor parameter `?OpaquePart $opaque = null`. `toMessage()` builds the assistant message with the opaque part first, then the text part (left out when the text is empty and there are tool calls), and the tool calls. An answer without text and without tool calls still cannot become a message. |
| `AbstractAdapter` | `answer()` gets a new last parameter `?OpaquePart $opaque = null`, marked sensitive. Two new `final protected` helpers: `seal(string $text, array $toolCalls, array $content): OpaquePart` and `unseal(Message $message): ?array`. |
| `Stream\StreamFinished` | Unchanged: its answer carries the part. There is no stream event for reasoning. |

**How an adapter fills it.** `seal()` writes one JSON object:

| Key | Holds |
|---|---|
| `f` | a constant of the adapter class: two letters and a digit that name nothing (`a1`, `a2`, `a3`, `a4`) |
| `m` | the SHA-256 of the neutral answer it belongs to: the text, then per tool call its id, name and raw arguments |
| `c` | what the provider asks back, as the adapter chooses: the provider's own assistant content, verbatim |

The envelope holds no preset id, no class name and no provider name. `c` is the provider's own content, which the
provider wrote; the contract gives it no meaning. An adapter seals only when the answer held something that has no
neutral form; an answer of plain text and tool calls has no opaque part.

**How an adapter reads it.** For every assistant message of a request, `unseal()` returns `c` only when all three
hold: the part decodes as such an object, `f` is this adapter's constant, and `m` equals the hash of the message as it
is now. Then the adapter sends `c` in place of what it would build. In every other case it builds the message from the
neutral fields and sends nothing of the part. So a transcript that moves to another provider, or that the caller
edited, still goes out as a valid request.

| Adapter | `c` holds | Sent back as |
|---|---|---|
| A1, `reasoning_carrier: message_field` | the reasoning string | `reasoning_content` on the assistant message |
| A1, `reasoning_carrier: content_entries` | the answer's `content` list | the `content` of the assistant message |
| A1, `reasoning_carrier: none` | nothing is sealed | |
| A2 | the answer's `content` block list | the `content` of the assistant message |
| A3 | the candidate's `parts` list | the `parts` of the `model` content |
| A4 | `tool_plan` and the `content` block list | the same fields of the assistant message |

**What a caller must do.** Use `$answer->toMessage()` for the next round, or copy every part of that message. A caller
that builds `Message::assistant($answer->text, …)` by hand loses the part; where the provider insists (Claude, Gemini),
the next request is then `rejected` by the provider, with its code. A caller that stores a transcript stores
`$part->data` and rebuilds the part with `new OpaquePart($data)`. The docblock of `ContentPart` already tells a reader
to skip a part class it does not know; the README adds: skip it when reading, keep it when sending.

**What the fake does.** `FakeGateway` never invents an opaque part. A scripted answer may hold one; it then reaches
the caller in `Answer::$opaque` and in `toMessage()`. The requests the fake records hold the parts the caller sent, so
an application's test can assert that its loop carried the part into the next round.

**What the shared test set asserts.** A new case `ContractFixture::ToolCallWithOpaqueData`; `AdapterFixtures::answer()`
and `stream()` may give `NotApplicable` for it.

1. The answer's text and tool calls are those of `ToolCall`; the provider's opaque content is in neither.
2. `Answer::$opaque` is set and `toMessage()` holds it.
3. A second call with that message and a tool result sends a body that holds the marker text of the fixture
   (`opaque-marker`), read from the recorded attempt.
4. The same second call with the text of the assistant message changed sends a body without the marker, and is sent.
5. A message with an `OpaquePart` of made-up data (`{"f":"zz"}` and plain text that is no JSON) is sent without it.
6. The key is in no opaque part ("no credential in any output" covers the new field).
7. For the fake: a scripted opaque part arrives and is in the recorded request of the next round.

**LR-04.** The part's bytes are a provider's content inside a value object. Decision G66 reads LR-04 as: no provider
format that a caller can read or depend on. The alternative, keeping the data inside the library between two calls,
breaks LR-01. The owner should see this reading; it is named in the report of this task.

### 5.2 Finish reasons

**What the files say.** `overview.md` 3.7, and the finish-reason section of each provider file.

**The changes.**

1. `FinishReason` gains `Refusal = 'refusal'`: the model declined to answer. (`ContentFilter` already exists.)
2. An answer without text and without a tool call is returned as an answer when its finish reason is `Length`,
   `Refusal` or `ContentFilter`. This extends G47. With any other reason it stays `provider_error`.
3. A **refused prompt** is `rejected`, whatever the status: an HTTP error where the provider sends one, and a 200
   without an answer where it does not. A **withheld answer** is an answer with `ContentFilter`.
4. An answer that holds tool calls has the finish reason `ToolCalls`, unless it was cut off (`Length`). Every adapter
   follows this; it does not depend on the provider's finish reason.
5. A value an adapter does not know, and a missing value, is `Other`.

With a response format, a refusal needs no new issue kind: its text is not JSON, and the structured result says so.

| Provider value | Neutral | Adapter |
|---|---|---|
| `stop`, `end_turn`, `stop_sequence`, `COMPLETE`, `STOP_SEQUENCE`, `STOP` | `Stop` | all |
| `length`, `model_length`, `max_tokens`, `model_context_window_exceeded`, `MAX_TOKENS` | `Length` | all |
| `tool_calls`, `function_call`, `tool_use`, `TOOL_CALL` | `ToolCalls` | all |
| `refusal`; a non-null `message.refusal` (its text becomes the answer text when the content is empty) | `Refusal` | A2; A1 |
| `content_filter` | `ContentFilter`; `Other` with `finish_content_filter: unknown` | A1 |
| `SAFETY`, `RECITATION`, `BLOCKLIST`, `PROHIBITED_CONTENT`, `SPII`, `MODEL_ARMOR` | `ContentFilter` | A3 |
| `pause_turn`, `LANGUAGE`, `CONTINUATION`, `TOO_MANY_TOOL_CALLS`, `OTHER`, `FINISH_REASON_UNSPECIFIED`, anything unknown | `Other` | all |
| `error`; `ERROR`, `TIMEOUT`; `MALFORMED_FUNCTION_CALL`, `UNEXPECTED_TOOL_CALL`, `MALFORMED_RESPONSE`, `MISSING_THOUGHT_SIGNATURE` | thrown: `provider_error`, the value in lower case as code | A1; A4; A3 |
| a 200 without candidates and with `promptFeedback.blockReason` | thrown: `rejected`, the block reason in lower case as code | A3 |
| HTTP 400 with code or type `content_filter`; HTTP 498 | `rejected` by the status, with the code where one exists | A1; A4 |
| `content_filter_results.error` beside an answer (the filter did not run) | ignored; the answer is returned | A1 |
| a second field `stop_reason` | ignored | A1 |

**Breaks S1?** One place: a `match` over `FinishReason` without a default arm. Change 2 turns some former
`provider_error` exceptions into answers.

**The shared set.** Two new cases, for which the fixtures may give `NotApplicable`: `Refused` (the text "I cannot help
with that.", finish reason `Refusal`) and `Withheld` (no text, finish reason `ContentFilter`). The fixture
`errorInBody()` stays as it is. `ContractFixture::ToolCall` gains the rule that a provider finish reason of "stop"
beside a tool call still gives `ToolCalls`; a format without a tool-call finish reason shows it in its own fixture.

### 5.3 Request values a provider refuses, fixes or ignores

LR-08: a value a provider cannot carry is refused with a typed error, never silently changed. So **nothing is
dropped**. What a caller can know beforehand is a capability.

**The change.** `Capabilities` gains a last constructor parameter `Support $temperature = Support::Native`. `Native`:
the temperature of a request is sent. `None`: the model takes none. `Emulated` is refused by the constructor. The
adapter base refuses a request that gives a temperature to a model whose capability is `None`, before anything is
sent, as `not_supported` with the detail "this model takes no temperature", in the same place where it refuses tools
for a model without tool calling. The fake does the same when it was given capabilities. Compatible: the parameter has
a default.

| Case | Decision | Source |
|---|---|---|
| Claude, API version `2026-10-21`: `temperature` returns 400 on every model | `temperature: not_accepted` on the preset → refused as `not_supported` | `anthropic.md`, "Reaching it" |
| Kimi: fixed per model, another value returns an error | `temperature: not_accepted` → refused as `not_supported` | `kimi.md`, "Temperature and the other sampling fields" |
| Gemini: "silently ignored" on Gemini 3.6 Flash and newer, 400 "in a future release" | Sent. A host that knows its model adds a `ModelOverride` (5.4). A later 400 is `rejected`. | `gemini.md`, "Limits and shapes" |
| Google Cloud: "greater than 0.0" on one page, 0 recommended on another | Sent as given, also 0. **Not confirmed.** | same |
| Grok: values above 1 are sampled at 1 | Sent; the neutral range ends at 1.0 (G22), so nothing is capped. | `grok.md`, "Limits and shapes" |
| OpenAI, Azure OpenAI: reasoning models refuse it (Azure: stated, with a table that disagrees; OpenAI: not confirmed) | Sent. Per model, so a `ModelOverride` where the model is known; otherwise the provider's 400 is `rejected`. | `azure-openai.md`, "Limits and shapes"; `openai.md`, "Not confirmed" |
| Ranges of 0 to 2 (OpenAI, Aleph Alpha, LightOn, Gemini); no range published (Mistral, Cohere, STACKIT, LM Studio) | Sent as the same number (G22). | `overview.md` 3.3 |
| A maximum of output tokens the format requires (Claude; "no documented default") | The neutral value when given, else the option `default_max_output_tokens` of the preset. The shipped value is **16000**: a choice of this library, not a number of the provider. It is under the lowest documented per-model maximum (64K), and large enough for an answer after thinking that cannot be switched off. It is preset data, so a host can ship another (G69). | `anthropic.md`, "Required fields a neutral request may lack" |
| A maximum that counts reasoning tokens (OpenAI, Azure OpenAI, Kimi, Claude); one that counts visible output only (Grok) | Sent as given. Not changed and not refused. A small value can end as `Length` with no text; that is returned as an answer (G47), and a structured result says "cut off". The preset's description says in one sentence that reasoning counts. | `overview.md` 3.3 |
| A maximum above what a model allows | Sent as given; the provider's refusal is `rejected`. (Cohere caps it, Kimi lowers it by itself.) | `cohere.md`, `kimi.md` |
| More than one system message where only one is allowed | `system_messages: joined` | `aleph-alpha.md`, "Messages" |
| A transcript that ends with an assistant message | A2 refuses it as `rejected` before sending (a prefill). A1 sends it, and never adds `partial` or `prefix`. | `anthropic.md`, "The shape of a request"; `kimi.md`, "Provider-only fields…" |
| A tool name the format cannot carry | `rejected` before sending (2.1, 4.1, 4.2) | |

### 5.4 Capabilities per model, without a model list and without a request

`capabilities()` sends nothing (G27) and no adapter keeps a closed list of models (G34). Three sources answer, in this
order; the first that speaks wins:

1. **A model override of the preset.** Documented exceptions for named models are preset data.
2. **The options of the preset.** What the provider documents for its current models.
3. **The defaults of the format.**

A model the preset does not know falls to 2 and 3, as G34 says. An override is not a closed list.

`final readonly class Preset\ModelOverride`

| Field | Type | Validation |
|---|---|---|
| `pattern` | `string` | not empty, no control character; `*` stands for any run of characters; compared with the whole model name, case-sensitive |
| `adapterOptions` | `array<string, bool\|int\|string>` | as `Preset::$adapterOptions` |

Method: `matches(string $model): bool`.

| Class | Change |
|---|---|
| `Preset` | New constructor parameter `array $modelOverrides = []` (`list<ModelOverride>`). New method `optionsFor(string $model): array<string, bool\|int\|string>`: the preset's options, overlaid by every override that matches, in list order, a later one over an earlier one. |
| `AbstractAdapter` | New `final protected function option(string $name, ?string $model = null): bool\|int\|string\|null`: from `optionsFor($model)` when a model is given, else from the preset's options; null when not set. New `final protected function capabilitiesFromOptions(string $model, Capabilities $formatDefaults): Capabilities`: reads the capability options of 2.3 over the defaults; a value outside its list is thrown as `not_configured`. Every adapter's `modelCapabilities()` ends with it. |

Compatible. The architecture test that lists the value classes of `src/Preset` gains `ModelOverride`.

| Per-model fact in a file | Where it goes |
|---|---|
| Claude: a forced tool call returns 400 on six named models (`anthropic.md`, "What a request can do") | overrides on `claude` with `forced_tool_call: none`. The file gives the display names and one id form (`claude-opus-5-5`); LG-21 first records the six ids in `anthropic.md`, then writes the patterns. |
| Kimi: `required` only on the K3 series; the K2 models are closed to new accounts and retire in December 2026 (`kimi.md`, "Temperature…", "Models and context sizes…") | no override: the preset describes the current series |
| OpenAI: tool calling absent on named models (`openai.md`) | section 3; under option (b), overrides with `tool_calling: none`, after the ids are recorded in `openai.md` |
| Azure OpenAI: per deployment (`azure-openai.md`) | the library cannot know; reported as native until checked. A host that knows its deployments builds its own preset with overrides. |
| Gemini: tools with structured output on the 3 and 4 series only (`gemini.md`) | emulated for every model on the shipped entries; a host may override |
| Aleph Alpha, LM Studio, the customer-run servers: per installation and per loaded model | reported until checked (section 6) |
| Mistral: `function_calling` flag per model in the model list (`mistral.md`) | not read into capabilities: that would be a request (G27) |

### 5.5 Small preset data

| Class | Change | Why |
|---|---|---|
| `PresetField` | New last parameter `?string $header = null`: the name of a request header this setting is sent in. An HTTP token, without an underscore. A field with a header must have a pattern. The adapter base adds the header to every request of the gateway when the setting is not empty; the value must be visible ASCII, otherwise `not_configured`. Not a secret: it is in the attempt report. | Claude's workspace id; OpenAI's organisation and project (`anthropic.md`, `openai.md`, "Signing in") |
| `Preset` | New parameters `string $storageNote = ''` and `?string $storageNoteSource = null`: one plain sentence on what the provider keeps of a request and how the customer arranges otherwise, and its link. | LR-31: "the preset says so"; most providers have no request option (section 11) |
| `Preset` | New method `withAdapterOptions(array $options): self`: a copy with these options laid over its own. | A host raises an "until checked" capability after the connection check without rebuilding the preset by hand (6.3) |

All compatible.

### 5.6 Errors

**Do the eight reasons of LR-32 suffice? Yes** (decision G71). No ninth reason is proposed.

| Finding (`overview.md` 3.8) | Decision |
|---|---|
| A 429 that means "no credit" or a spend limit (OpenAI type `insufficient_quota`; Claude `enforced_spend_limit_reached`; Kimi `exceeded_current_quota_error`) | `rejected`, with the code; not retried. It is not `rate_limited`: a caller that waits and tries again would never get through. An application tells its customer "your account at the provider is out of credit" from the status 429 together with the code. |
| 402 (Mistral, Claude, Cohere, the Gemini API) | `rejected` by its status; not retried. |
| A 429 that is not the customer's limit (Kimi `engine_overloaded_error`; Mistral `backend_out_of_capacity`) | `unavailable`; retried. |
| A 429 at Mistral for a monthly limit | cannot be told from a rate limit by a documented code; stays `rate_limited`. Recorded limit. |
| 400 for bad credentials: the Gemini API (reason `API_KEY_INVALID`) | `credentials_rejected` by the code. |
| 400 for bad credentials: Grok ("an incorrect API key", no documented body) | stays `rejected`: nothing documented tells the two apart. The connection check reports sign-in as "no" with the status. Recorded limit, **not confirmed**. |
| Unknown model: 400 (Mistral) or 404 (Cohere, Grok, STACKIT, Kimi); not confirmed elsewhere | `rejected` either way, by the status. No hook needed. |
| 529, 503, 504 (also with an HTML body), a 503 maintenance body | `unavailable` by the status; a body that cannot be read gives no code. |
| `retry-after-ms` in milliseconds (Azure OpenAI) | read by A1 through `retryAfterSeconds()`. |
| `Retry-After` with no unit stated (Mistral, Kimi) | read as the base reads it: seconds or a date. |
| Reset values with no stated format (STACKIT) | not read. |
| `x-should-retry: false` (Claude) | the new hook below. |
| No error code or no documented body (Cohere, Grok, STACKIT, Aleph Alpha, LM Studio) | no code; the status decides. |

**One new hook** on `AbstractAdapter`:

`protected function retryAllowed(#[\SensitiveParameter] HttpResponse $response, ErrorReason $reason): bool` — default
`true`. Asked only for a failed response whose reason is `rate_limited` or `unavailable`. `false`: the call is not
tried again and fails with that reason and no wait. An exception thrown in it counts as `true`. Compatible.

The words A1 knows, as a fixed table inside the adapter: types that mean "no credit" (`insufficient_quota`,
`exceeded_current_quota_error`) and types that mean "overloaded" (`engine_overloaded_error`,
`backend_out_of_capacity`). They are read from `error.type` or the flat `type`. A server with other words keeps the
reading of its status.

### 5.7 The option not to store, and what must never be sent

Section 11 has the table per preset. The rules:

- An adapter sends the provider's "do not store" option only through `store_field`, and the preset's
  `sendsNoStoreOption` is true exactly when that option is `false`.
- A preset whose provider has only an arrangement on the account says so in `storageNote`.
- No adapter ever sends a field that switches on storing, training or sharing (LR-31): `store: true`,
  `previous_response_id`, `deferred`, `cachedContent`, `prompt_cache_key`, `metadata.user_id`.
- What must never be sent is the closed field list of each adapter (2.1, 4.1 to 4.3).

### 5.8 Model lists and data location

No contract change. Sections 10 and 11.

### 5.9 Structured output per adapter, and where the schema is rewritten

**The policy** (decision G74), the same for every adapter:

1. The caller's `JsonSchema` is never changed, and the answer is always checked against it in full by the adapter base.
2. An adapter may send the provider a **looser copy**: constraint keywords the native mode refuses are taken out.
   Loosening is safe, because the full check follows.
3. An adapter never sends a copy that is **tighter in a way the caller's schema does not allow**. In particular it
   never makes an optional property required.
4. When the native mode still cannot take the schema (a structural keyword, a shape rule, a size limit), the adapter
   uses the emulation of LG-08 for that one request. The capability stays "native"; the result is checked the same.
5. A flag that promises exact adherence (`strict`) is sent as `true` only when the caller's schema fits the documented
   subset as it is.

**Where the code lives.** A neutral helper in `src/Adapter/Shared`, without a provider name:

`final readonly class Adapter\Shared\SchemaShape`

| Method | Gives |
|---|---|
| `uses(JsonSchema $schema, array $keywords): list<string>` | which of these keywords stand at a place where a schema stands |
| `without(JsonSchema $schema, array $keywords): JsonSchema` | a copy without them |
| `isRecursive(JsonSchema $schema): bool` | whether a reference leads back to a schema that holds it |
| `everyPropertyIsRequired(JsonSchema $schema): bool` | |
| `everyObjectIsClosed(JsonSchema $schema): bool` | `additionalProperties: false` on every object |
| `everyObjectHasARequiredProperty(JsonSchema $schema): bool` | |
| `depth(JsonSchema $schema): int`, `propertyCount(JsonSchema $schema): int` | |
| `mayTighten(JsonSchema $schema, array $keywords): bool` (G89) | whether the schema can refuse an answer the caller's schema allows once these keywords are taken out, or `oneOf` among them is written as `anyOf`: a keyword under `not`, under `if`, in a branch of `oneOf`, under `contains` beside `maxContains`, and the other cases the class lists. True: the adapter does not send the copy and uses the emulation |
| `valuesOf(JsonSchema $schema, string $keyword): list<mixed>` (G89) | the value of a keyword at every place where a schema stands, for a rule on a value: `minItems` above 1, a `format` outside a list, the content of a `pattern`, an external `$ref`, a non-scalar `enum` value |
| `renamed(JsonSchema $schema, string $keyword, string $as): JsonSchema` (G89) | a copy with the keyword under another name (`oneOf` written as `anyOf`), under the rules of `without()` |

It walks only the places where a schema stands, as the structured check does (G55). Each adapter keeps its own keyword
lists, from its provider file, in its own folder.

| Adapter / preset | Native mode | Taken out of the copy | Falls back to emulation when | Source |
|---|---|---|---|---|
| A1, `json_schema_strict: when_fits`, `strict_subset: standard` | `response_format` `json_schema`; `strict: true` when the schema uses none of `allOf`, `not`, `dependentRequired`, `dependentSchemas`, `if`, `then`, `else`, every property is required, every object is closed, depth ≤ 10 and properties ≤ 5000; else `strict: false` | nothing | never | `openai.md`, "Structured output: the schema subset" |
| A1, `strict_subset: narrow` | as above, and also none of the string, number, object and array limits the file lists, depth ≤ 5, properties ≤ 100 | nothing | never | `azure-openai.md`, "What a request can do" |
| A1, `json_schema_strict: omit` or `false` | `response_format` `json_schema`, the schema as it is | nothing (the accepted keywords are not published) | never | `mistral.md`, `kimi.md`, `aleph-alpha.md`, `lm-studio.md` |
| A1, `structured_output: emulated` | none | | always | `grok.md`, `stackit.md`, `lighton.md`, the customer-run three |
| A2 | `output_config.format` | `minimum`, `maximum`, `multipleOf`, `minLength`, `maxLength`, `maxItems`, `minItems` above 1, `uniqueItems`; `oneOf` is written as `anyOf`; `not` is taken out | the schema is recursive, has an external `$ref`, a non-scalar `enum` value, or an object that is not closed (G110) | `anthropic.md`, "What a request can do", "Structured output" |
| A3 | the field of `structured_output_field` | nothing: unsupported keywords are ignored by the provider, not refused | never; a schema too large or too deep is the provider's 400, `rejected` | `gemini.md`, "Schema dialect" |
| A4 | `response_format` `json_object` with a schema | `minimum`, `maximum`, `minItems`, `maxItems`, `minLength`, `maxLength`, `uniqueItems`, a `format` other than `date-time`, `uuid`, `date`, `time`; `oneOf` is written as `anyOf`; `not` is taken out | the schema uses `allOf`, an object has no required property, a `pattern` holds `^`, `$`, `?=` or `?!`, or the request also has tools | `cohere.md`, "Structured output: schema rules" |

Not guaranteed even with a schema (`overview.md` 3.6): the base's check covers it, also an answer wrapped in a code
fence (G51).

**A forced tool call as the route to a structured answer** is the loop's choice (G16), not an adapter's: no shipped
preset emulates structured output through a forced call.

---

## 6. Capabilities per adapter and per preset

### 6.1 The defaults of each format

| Capability | A1 | A2 | A3 | A4 |
|---|---|---|---|---|
| Tool calling | native | native | native | native |
| Forced tool call | native | native | native | emulated |
| Parallel tool calls | native | native | native | native |
| Structured output | native | native | native | native |
| Tools with structured output | emulated | native | emulated | emulated |
| Streaming | native | native | native | native |
| Model listing | native | native | native | native |
| Temperature | accepted | accepted | accepted | accepted |
| Context length | not known without a request | same | same | same |

The context length is null for every shipped preset (G27). The model list gives it per model where the provider tells
it (section 10).

### 6.2 Per preset entry

N native, E emulated, – none. A star means **until checked**: the provider file does not confirm it.

| Entry | Tools | Forced | Parallel | Structured | Tools + structured | Streaming | Model list | Temperature |
|---|---|---|---|---|---|---|---|---|
| `openai`, `openai-eu` (on A1, if G59 says so) | N* per model | N | N | N | E | N | N | accepted* |
| `azure-openai` | N* per deployment | N | N* | N* | E | N | –* | accepted* |
| `grok` | N* | N | N | E* | E | N | N | accepted |
| `kimi`, `kimi-cn` | N | E | N | N | E | N | N | not accepted |
| `stackit` | N | –* | –* | E* | E | E* | N | accepted |
| `aleph-alpha` | N* per installation | N | –* | N* per installation | E | N | N | accepted |
| `lighton` | –* | – | – | E | – | N | N* | accepted |
| `lm-studio` | N* per model | – | N | N* per model | E | N | N | accepted |
| `mistral`, `mistral-eu` | N | N | N | N | E | N | N | accepted |
| `silo-ai` | N* | –* | – | E* | E | E* | N | accepted |
| `pleias` | – | – | – | E | – | E* | N* | accepted |
| `kyutai`, `openai-compatible` | N* | –* | –* | E* | E | E* | N* | accepted |
| `claude` | N | N; – on the six named models | N | N | N | N | N | not accepted |
| `gemini` | N | N | N | N | E | N | N | accepted |
| `vertex-ai`, `vertex-ai-eu` | N | N | N | N | E* | N | – | accepted |
| `cohere` | N | E | N | N | E | N | N | accepted |

Evidence for the cells that are not a format default:

| Cell | Source |
|---|---|
| `kimi` forced E | `kimi.md`, "What a request can do": a named function returns 400 with thinking on; `required` with one tool is the documented way |
| `stackit` forced, parallel, structured, streaming | `stackit.md`, same section: mentioned on no page |
| `aleph-alpha` parallel | `aleph-alpha.md`: not confirmed; every example reads one call |
| `lighton` tools | `lighton.md`: only the older form is documented |
| `lm-studio` forced | `lm-studio.md`: `required` on one engine only, a named function not documented |
| `silo-ai` parallel | `silo-ai.md`: the Poro 2 template refuses more than one call |
| `pleias` tools | `pleias.md`: not documented for any model; the chat template has no tools part |
| `azure-openai` model list | `azure-openai.md`: what the list returns is not confirmed, and a request's model is a deployment name |
| `vertex-ai` model list | `gemini.md`: only a catalogue list on another path version |
| `vertex-ai` tools + structured | `gemini.md`: one guide says it can be combined, with no model list |
| `cohere` forced, tools + structured | `cohere.md`, "What a request can do" |
| `claude` forced | `anthropic.md`, "What a request can do" |

### 6.3 The rule for "until checked"

Where no provider file confirms a capability, the shipped preset reports the value that cannot fail (decision G70):

| Capability | Reported | Why it cannot fail |
|---|---|---|
| Structured output | emulated | text in, the same check |
| Tools with structured output | emulated | works wherever tools work |
| Streaming | emulated | one plain request |
| Forced tool call | none | the loop takes the structured route instead (G16) |
| Parallel tool calls | none | a caller that expects one call is not surprised; several calls that still arrive are returned |
| Model listing | native where a file names the path, else as the format | a failed list is one error on a settings screen |
| Tool calling | native | there is no safe value; `none` only where a file gives evidence against |

The connection check (LG-23) probes the **native** route for every starred capability, whatever the preset reports. A
host that sees "yes" builds its gateway from `$preset->withAdapterOptions(['streaming' => 'native'])`. The library
keeps no result of a check (LR-01).

---

## 7. Providers without a hosted text chat API

Decision G9 gives each a named preset that asks for the address of the customer's own server. All are A1 entries with
`BaseUrlRule::CustomerMust` (LM Studio: `CustomerMay`), the credential kinds `none` and `bearer_key`, and the data
location "at the customer". Each description is one plain sentence; the models are suggestions in that sentence, never
a list in code (G34).

| Entry | What the description says | What it asks | Source |
|---|---|---|---|
| `aleph-alpha` | PhariaAI runs on your own cluster; give the address of its inference API exactly as your administrator gave it, with or without `/v1`. A hosted access exists by invitation only, at an address sent by mail. Any model your administrator enabled for chat. | base URL (required); a bearer token (required: `bearer_key` only) | `aleph-alpha.md`, "Reaching it", "Signing in" |
| `silo-ai` | A Silo AI open model that you run yourself on a server that speaks the OpenAI chat-completions format, for example vLLM. Silo AI and AMD offer no hosted chat API. Suggested: `LumiOpen/Llama-Poro-2-8B-Instruct`, `LumiOpen/Llama-Poro-2-70B-Instruct`, `LumiOpen/Llama-Poro-2-8B-Long-Instruct`, `LumiOpen/Poro-34B-chat`. Viking is published as base models only and is not a chat model. | base URL; an optional key | `silo-ai.md`, "For the preset" |
| `pleias` | A Pleias open model that you run yourself behind an OpenAI-compatible server. Pleias offers no hosted chat API. Works through a chat request: `PleIAs/Baguettotron`, `PleIAs/baguettotron-600m`, `PleIAs/Baguettotron-MoE`, `PleIAs/Monad` (one turn), `PleIAs/Pleias-SLM-RAG`. They are trained without a system message, do not call tools, and have a window of about 2,000 tokens. The Pleias-RAG models have no chat template and do not work here. | base URL; an optional key | `pleias.md`, "What works through a plain chat request" |
| `kyutai` (G62) | A text chat model of your own choice that you run on a server that speaks the OpenAI chat-completions format. Kyutai offers no hosted API and publishes no chat model: its Helium text models do not follow instructions, and its other models are for speech. **No model is suggested.** | base URL; an optional key | `kyutai.md`, "For the preset" |
| `lm-studio` | LM Studio on your own machine; start its local server and give its address. Any chat model you downloaded in it. The first request to a model that is not loaded takes longer. | base URL (default `http://localhost:1234/v1`, may be replaced); an optional token | `lm-studio.md`, "Reaching it", "Signing in", "Limits and shapes" |
| `azure-openai`, labelled "Microsoft Copilot / Azure OpenAI" | Connects Azure OpenAI. Microsoft Copilot has no model API: its Chat API answers from an organisation's Microsoft 365 content, with no model choice and no custom tools. | section 9 | `azure-openai.md`, "Microsoft Copilot" |

`kyutai` holds the same options as `openai-compatible`; it differs in its id, label and description. It is kept
because G3 says no provider is dropped without the owner's word.

Two cautions the descriptions carry, from the files: an installation can forward a model to another provider
(`aleph-alpha.md`, "Data"; `lighton.md`, "Data"), and an address the customer gives may be a third party's service
(`kyutai.md`, "Data").

---

## 8. The preset catalogue

Decision G60 asks for `Preset\Presets`. Its shape (decision G75):

`final readonly class Preset\Presets` — only static methods, a private constructor, no state: every call builds its
values anew.

| Method | Gives |
|---|---|
| `Presets::all(): list<Preset>` | every shipped entry, in the order of section 9 |
| `Presets::find(string $id): ?Preset` | the entry with this id, or null |
| `Presets::ids(): list<string>` | the ids, in the same order |

An **entry** is a `Preset` value, nothing more. What a "choose a provider" screen needs is on it: `id`, `label`,
`description`, `fields` (each with its label, whether it is required, and its pattern), `credentialKinds`, `keyHeader`,
`baseUrlRule`, `defaultBaseUrl`, `dataLocation` with its source, `sendsNoStoreOption`, `storageNote` with its source.

Each entry is written in its own file `src/Preset/Catalogue/<Name>.php`: a `final readonly` class with one static
method `preset(): Preset` and no logic. `Presets::all()` calls them in order. Provider names may stand in these files
and in `Presets.php` (G58: preset data).

A project's own presets are not in the catalogue (G60). The names `Presets::all()` and `Presets::find()` are the ones
the README already uses as placeholders; they are now final.

---

## 9. The preset entries

Twenty-one entries for the fifteen providers and "other" (decision G73). A provider has more than one entry where one
address cannot state its data location truthfully (LR-19): an EU address the provider documents, a second host under
another company, or a second way in.

Columns: **Base URL** with its rule (F fixed, M the customer may replace it, C the customer must give it).
**Credentials** are kinds of `CredentialKind`; all four exist since LG-06. **Fields** are settings, never secrets.
**Options** list only what differs from the defaults of 2.2, 2.3 and section 4.

| # | Id | Label | Adapter | Base URL | Credentials | Fields | Options |
|---|---|---|---|---|---|---|---|
| 1 | `openai` | OpenAI Platform (ChatGPT) | G59; A1 below | `https://api.openai.com/v1` F | `bearer_key` | `organization` (header `OpenAI-Organization`), `project` (header `OpenAI-Project`); both optional | `store_field: false`, `json_schema_strict: when_fits` |
| 2 | `openai-eu` | OpenAI Platform (ChatGPT), Europe | as 1 | `https://eu.api.openai.com/v1` F | as 1 | as 1 | as 1 |
| 3 | `claude` | Claude (Anthropic) | A2 | `https://api.anthropic.com` F | `bearer_key`, `header_key` in `x-api-key` | `workspace_id` (header `anthropic-workspace-id`, optional, pattern `wrkspc_[A-Za-z0-9_-]+`) | `api_version: 2026-10-21`, `default_max_output_tokens: 16000`, `temperature: not_accepted`; overrides for the six models (5.4) |
| 4 | `gemini` | Gemini (Gemini API) | A3 | `https://generativelanguage.googleapis.com/v1beta` F | `header_key` in `x-goog-api-key` | none | `store_field: false` |
| 5 | `vertex-ai` | Gemini (Google Cloud / Vertex AI) | A3 | `https://{location}-aiplatform.googleapis.com/v1/projects/{project}/locations/{location}/publishers/google` M | `bearer_token` | `project` (pattern `[A-Za-z0-9._-]+`), `location` (pattern `[a-z]+-[a-z]+[0-9]+`) | `model_listing: none`, `tools_with_structured_output: emulated` |
| 6 | `vertex-ai-eu` | Gemini (Google Cloud / Vertex AI), EU multi-region | A3 | `https://aiplatform.eu.rep.googleapis.com/v1/projects/{project}/locations/eu/publishers/google` F | `bearer_token` | `project` (required) | as 5 |
| 7 | `azure-openai` | Microsoft Copilot / Azure OpenAI | A1 (G59 may change it) | `https://{resource}.openai.azure.com/openai/v1` M | `header_key` in `api-key`, `bearer_key`, `bearer_token` | `resource` (pattern `[A-Za-z0-9-]+`) | `store_field: false`, `json_schema_strict: when_fits`, `strict_subset: narrow`, `model_listing: none` |
| 8 | `cohere` | Cohere | A4 | `https://api.cohere.com` M | `bearer_key` | none | none |
| 9 | `aleph-alpha` | Aleph Alpha (PhariaAI) | A1 | C | `bearer_key` | none | `max_tokens_field: max_tokens`, `system_messages: joined`, `assistant_content_without_text: empty_string`, `finish_content_filter: unknown`, `parallel_tool_calls: none`, `models_path: /model-settings`, `model_id_field: name` |
| 10 | `silo-ai` | Silo AI (AMD) | A1 | C | `none`, `bearer_key` | none | `max_tokens_field: max_tokens`, `named_tool_choice: unsupported`, `parallel_tool_calls: none`, `structured_output: emulated`, `streaming: emulated`, `stream_usage: unasked` |
| 11 | `lighton` | LightOn (Paradigm) | A1 | `https://paradigm.lighton.ai/api/v3` M | `bearer_key` | none | `max_tokens_field: max_tokens`, `tool_calling: none`, `structured_output: emulated`, `stream_usage: unasked`, `models_path: /instance/models`, `model_id_field: technical_name` |
| 12 | `pleias` | Pleias | A1 | C | `none`, `bearer_key` | none | `max_tokens_field: max_tokens`, `tool_calling: none`, `structured_output: emulated`, `streaming: emulated`, `stream_usage: unasked`, `inline_reasoning: strip` |
| 13 | `mistral` | Mistral | A1 | `https://api.mistral.ai/v1` F | `bearer_key` | none | `max_tokens_field: max_tokens`, `required_word: any`, `tool_result_name: true`, `reasoning_carrier: content_entries`, `stream_usage: unasked` |
| 14 | `mistral-eu` | Mistral, Europe | A1 | `https://api.eu.mistral.ai/v1` F | as 13 | none | as 13 |
| 15 | `kyutai` | Kyutai | A1 | C | `none`, `bearer_key` | none | as 21 |
| 16 | `stackit` | STACKIT | A1 | `https://api.openai-compat.model-serving.eu01.onstackit.cloud/v1` F | `bearer_key` | none | `named_tool_choice: unsupported`, `parallel_tool_calls: none`, `structured_output: emulated`, `streaming: emulated`, `stream_usage: unasked` |
| 17 | `grok` | Grok (xAI) | A1 (G59 may change it) | `https://api.x.ai/v1` F | `bearer_key` | none | `structured_output: emulated` |
| 18 | `kimi` | Kimi (Moonshot AI), international | A1 | `https://api.moonshot.ai/v1` F | `bearer_key` | none | `temperature: not_accepted`, `named_tool_choice: required_single`, `tool_strict: false`, `json_schema_strict: false`, `assistant_content_without_text: empty_string`, `reasoning_carrier: message_field` |
| 19 | `kimi-cn` | Kimi (Moonshot AI), mainland China | A1 | `https://api.moonshot.cn/v1` F | `bearer_key` | none | as 18 |
| 20 | `lm-studio` | LM Studio | A1 | `http://localhost:1234/v1` M | `none`, `bearer_key` | none | `max_tokens_field: max_tokens`, `named_tool_choice: unsupported` |
| 21 | `openai-compatible` | Other, OpenAI-compatible | A1 | C | `none`, `bearer_key` | none | `max_tokens_field: max_tokens`, `named_tool_choice: unsupported`, `parallel_tool_calls: none`, `structured_output: emulated`, `streaming: emulated`, `stream_usage: unasked` |

Notes on the table:

1. **Base URLs and decision G43.** Every placeholder in a host is followed by a fixed domain of two labels or more
   (`openai.azure.com`, `googleapis.com`). A field that fills a placeholder is not marked required where the customer
   may give a whole base URL instead (`azure-openai`: the second documented host `<resource>.services.ai.azure.com`;
   `vertex-ai`: the `global` and `us` endpoints). Without a base URL and without the setting, the factory answers
   `not_configured`, as built.
2. **`cohere` is M** because a Model Vault is called at the vault's own address with the same key and the same paths
   (`cohere.md`, "Reaching it").
3. **`max_tokens_field: max_tokens` on the customer-run entries**: the field the serving documents of those providers
   show (`silo-ai.md`, "Limits and shapes"; `pleias.md`, "What makes these models unlike…"; `lm-studio.md`). For
   `kyutai` and `openai-compatible` nothing is documented: **not confirmed**.
4. **`stream_usage: unasked`** where the field `stream_options` is on no page of the provider or the pages disagree
   (`stackit.md`, `lighton.md`, `mistral.md`). A stream may then report no usage; the adapter's test says so with
   `reportsUsage()`.
5. **No US entries.** OpenAI, Mistral and Grok document a US address (`overview.md` 3.12). They are not shipped; a
   project builds its own preset (G34).
6. **A Google Cloud API key** for Vertex AI is documented "for testing" and its header is known from a search summary
   only (`gemini.md`, "Signing in"). It is not a credential kind of entries 5 and 6.
7. **Credentials that stop by date** (`overview.md` 3.2) need nothing in a preset: an expired key is
   `credentials_rejected`. The description of `aleph-alpha` says that a key of the hosted access ends after 30 days.
8. **The model** is not a preset field: it is the `model` of the request, free text (G18). For `azure-openai` the
   description says it is the deployment name.

---

## 10. Model lists

LR-05.3. `AbstractAdapter` refuses a request whose address does not start with the gateway's base URL (G44). So a list
on **another host or outside the base path cannot be reached**, and that is how LR-29 is kept: the list is then "none",
never a second address.

Every list is one request with the largest page the provider documents. A provider that has more models than one page
gives only the first page; this is a recorded limit. An unknown field never breaks a list; an entry without a usable
id is skipped.

A1 reads `{"data":[…]}`, `{"models":[…]}` or a bare list. Per entry: the id from `model_id_field`; the name from
`name` or `display_name`; the context length from the first of `context_length`, `max_context_length`,
`max_context_size`; the aliases from `aliases`; deprecated when `deprecation` or `shutdown_date` is set or
`is_deprecated` is true. An entry that says `chat: false`, `enabled: false` or `status: "unavailable"` is left out.

| Entry | Request | Shape | What it reports | Source |
|---|---|---|---|---|
| `openai` | `GET /models` | `data[]` with `id` | ids only | `openai.md`, "What a request can do" |
| `azure-openai` | none | | the endpoint exists, but whether it lists deployments is **not confirmed** | `azure-openai.md` |
| `grok` | `GET /models` | `data[]` | context length, aliases | `grok.md`, "Model list endpoints" |
| `kimi`, `kimi-cn` | `GET /models` | `data[]` | context length | `kimi.md`, "The model list" |
| `mistral`, `mistral-eu` | `GET /models` | `data[]` (the page's example shows a bare list; both are read) | context length, aliases, deprecation | `mistral.md`, "What a request can do" |
| `stackit` | `GET /models` | **not confirmed** (not shown) | ids | `stackit.md` |
| `aleph-alpha` | `GET /model-settings` | own shape; `name` is the id | context length; only entries with `chat: true` | `aleph-alpha.md`, "What a request can do" |
| `lighton` | `GET /instance/models` | a bare list; `technical_name` is the id | ids; **not confirmed** that it is what chat accepts | `lighton.md`, "What a request can do" |
| `lm-studio` | `GET /models` | **not confirmed** (not shown) | ids. The richer `/api/v1/models` is outside the base URL `…/v1` and is not reached. | `lm-studio.md`, "The model lists" |
| `silo-ai`, `pleias`, `kyutai`, `openai-compatible` | `GET /models` | the customer's server | ids; **not confirmed** | `silo-ai.md`; `pleias.md`; `kyutai.md` |
| `claude` | `GET /v1/models?limit=1000` | `data[]` with `id`, `display_name`, `max_input_tokens` | context length; paged, one page read | `anthropic.md`, "What a request can do" |
| `gemini` | `GET /models?pageSize=1000` | `models[]` with `name` (`models/{id}`), `inputTokenLimit`, `supportedGenerationMethods` | the id without `models/`; context length; only entries that support `generateContent` | `gemini.md`, same |
| `vertex-ai`, `vertex-ai-eu` | none | | only a catalogue on another path version, not what the credentials may call | `gemini.md`, same |
| `cohere` | `GET /v1/models?endpoint=chat&page_size=1000` | `models[]` with `name`, `context_length`, `is_deprecated` | context length. The path is `/v1` beside chat at `/v2`: both are under the base URL `https://api.cohere.com`. | `cohere.md`, same |

No list reports structured-output support (except Claude's) or parallel calls (`overview.md` 3.10); `ModelInfo` has no
field for them and gains none.

---

## 11. Data location and the option not to store

`dataLocation` is the provider's own statement, shortened, with `dataLocationSource` as its link. "Not stated" is a
valid value. `storageNote` is 5.5. The exact wording is written in LG-18 to LG-21 from the "Data" section of each file;
a quote that carries weight is compared with the page first (`overview.md` 5).

| Entry | `dataLocation` (the substance) | Sends a no-store option | `storageNote` (the substance) | Source |
|---|---|---|---|---|
| `openai` | no region promised on this address | yes: `store: false` | kept up to 30 days for abuse monitoring; Zero Data Retention needs the provider's approval; the structured-output schema counts as system data | `openai.md`, "Data" |
| `openai-eu` | Europe (EEA and Switzerland), with a key of a project created for that region; newer models cost 10% more | yes | as above | same |
| `claude` | "us" or "global" only; no EU-only option | no | no request option; deleted within 30 days (condensed reading); zero data retention per organisation through sales; a schema is cached up to 24 hours | `anthropic.md`, "Data" |
| `gemini` | no region can be chosen and none is promised | yes: `store: false`, for developer logs only | abuse monitoring keeps prompts with no opt-out described; the unpaid tier is used for training | `gemini.md`, "Data" |
| `vertex-ai` | the region the customer chose | no | caching is on by default and is switched off per project; flagged prompts may be logged | same |
| `vertex-ai-eu` | the EU multi-region: machine-learning processing stays in EU member states | no | as above | same |
| `azure-openai` | set by the deployment type: Global, Data Zone or Regional; nothing in a request selects it | yes: `store: false`; it does not cover abuse monitoring | abuse monitoring may store sampled prompts; the opt-out is by application and is announced to end for new models | `azure-openai.md`, "Data" |
| `cohere` | not stated in the documentation; the sub-processor list gives "USA"; a Model Vault runs in the region the customer picked | no | logs deleted after 30 days; zero data retention for approved enterprise customers; a training opt-out in the dashboard, its default not confirmed | `cohere.md`, "Data" |
| `aleph-alpha` | in the customer's own installation; an administrator can forward a model elsewhere; the hosted access by invitation: not stated | no | nothing to send; logging is the customer's | `aleph-alpha.md`, "Data" |
| `silo-ai`, `pleias` | at the customer | no | nothing to send | `silo-ai.md`, `pleias.md`, "Data" |
| `kyutai`, `openai-compatible` | wherever the address the customer gave points to | no | nothing to send; the server is the customer's choice | `kyutai.md`, "Data" |
| `lighton` | LightOn-run: "hosted within the European Union", provider and country not named; an "External" model is served elsewhere; on-premises: the customer's | no | stateless by its terms; training only if the customer asks in writing | `lighton.md`, "Data" |
| `mistral` | "Mistral does not commit to a specific inference location" on this address | no | zero data retention on request, pay-as-you-go only; the free mode may be used for training unless switched off | `mistral.md`, "Data" |
| `mistral-eu` | data centres in EU and EFTA countries; 1.1 times the price; the account data is not regional | no | as above | same |
| `stackit` | region `eu01`, which the platform's region page places in Germany; no page of the service itself names the country | no | "We do not store any customer data from the requests." | `stackit.md`, "Data" |
| `grok` | "the processing location is not guaranteed"; no EU address is documented | no | stored 30 days by default; Zero Data Retention is a team setting | `grok.md`, "Data" |
| `kimi` | not stated by the provider; terms under Singapore law; no statement of processing in the EU | no | zero data retention only as an enterprise arrangement; training is not documented as off by default | `kimi.md`, "Data" |
| `kimi-cn` | not stated for the API; personal information is stored in the People's Republic of China; another company and another law than `kimi` | no | the pages disagree on training | same |
| `lm-studio` | on the customer's own machine | no | nothing to send; the local log shows requests | `lm-studio.md`, "Data" |

A gateway built from `kimi` never sends a request to the host of `kimi-cn`, and the other way round: the base URL is
fixed per entry and a 401 changes nothing (LR-29; `kimi.md`, "Two platforms, two hosts").

---

## 12. Requirement → where the map meets it

| Requirement | Where |
|---|---|
| LR-18 the fifteen, each a named preset | section 9: every provider of G3 has one entry or more |
| LR-19 what a preset names | sections 8, 9 and 11; `storageNote` (5.5) |
| LR-20 one adapter per format | 1.2: four adapters for twenty-one entries; A5 reserved (section 3) |
| LR-21 "other, OpenAI-compatible" | entry 21 |
| LR-22 providers without a hosted chat API | section 7 |
| LR-23 a new provider is a preset; a new format is one adapter | section 2: every dialect difference is an option or tolerant reading; `ModelOverride` and `withAdapterOptions()` (5.4, 5.5); A5 needs no change to the interface once 5.1 exists |
| LR-24 sign-in methods | section 9, column Credentials: bearer key, a key in the provider's header, a resource address with a deployment name (Azure: base URL and model), a Google Cloud project and region with a token from the supplier, a Microsoft Entra token, no key. The API version of Azure is not sent: the v1 form does not ask for one (G65). |
| LR-27 the connection check | 6.3: it probes the native route of every "until checked" capability; 5.6: a sign-in that fails with a 400 is reported with its status |
| LR-31 the option not to store | 5.7 and section 11 |
| LR-01, LR-09 no state | 1.1 (stateful APIs not built); 3.2; 5.1 (the opaque data travels with the caller's messages) |
| LR-04 no provider format in the contract | 5.1, with its reading (G66); option names and enum cases in sections 2 and 5 hold no provider name |
| LR-06, LR-08 request values | 5.3 |
| LR-07 the answer | 5.1 (reasoning is not answer text), 5.2, 5.9 |
| LR-10, LR-11 capabilities, emulation | section 6; 5.3 (temperature); 5.4 (per model) |
| LR-13 text only | reasoning, images and audio are not surfaced; 5.1 |
| LR-14, LR-15 the tool loop | 5.1 (`toMessage()` carries the part). For LG-22: every tool call of an answer gets a tool message before the next request, also when a limit stops the loop halfway (`kimi.md`, "The shape of a tool call…"; `anthropic.md`, same). |
| LR-17 garbled calls | 2.1; 4.3; a tool call that failed to parse and came back as text stays text (`lm-studio.md`) |
| LR-25 short-lived tokens | entries 5, 6, 7: `bearer_token` from the caller's supplier |
| LR-29 never another host | section 10 (a list elsewhere is "none"); section 9 (fixed hosts, two Kimi entries) |
| LR-32, LR-34 reasons and retries | 5.6 |

---

## 13. What a provider file says that this map does not place

| Finding | File | Why it is not placed |
|---|---|---|
| Two oddly named endpoints (`/v1/decisions`, `/v1/systemone`) | `lm-studio.md`, "Reaching it"; `overview.md` 5 | Not chat endpoints; nothing here names or uses them. They must be compared with the pages before any document does. |
| A tool result as `document` blocks, with citations | `cohere.md`, "The shape of a tool call…" | The contract has text results only (G10). |
| Tool names are normalised by the server | `lm-studio.md`, "Limits and shapes" | Whether the returned name is the original is not confirmed. A name that does not match is the loop's "unknown tool" (LR-17). |
| A stream is closed after 10 minutes without activity | `mistral.md`, "What a request can do" | The caller's timeout decides (G26). |
| A first request to a model that is not loaded takes longer; two presets on one server unload each other's model | `lm-studio.md`, "Limits and shapes" | A matter of the caller's timeout; in the description of the entry. |
| The Poro 2 chat template writes a fixed date into every system header | `silo-ai.md`, "Limits and shapes" | A matter of the caller's prompt. |
| Workload identity federation as a sign-in | `openai.md`, `anthropic.md`, "Signing in" | The token it yields is sent as a bearer; a host can supply it. No entry lists `bearer_token` for these two; a host that wants it builds its own preset. |
| Routing headers of AMD AI Workbench (`x-ai-eg-backend`, `x-ai-eg-model`) | `silo-ai.md`, "What Silo AI and AMD document…" | Optional and specific to one gateway product; not a field of `silo-ai`. |
| `x-zero-data-retention` on every answer | `grok.md`, "Reaching it" | An answer header; the host sees it in the attempt report (LR-30). |
| Statuses 203 for a model list | `silo-ai.md` | Already an answer for the adapter base: any status of 200 to 299. |
| The `inference_geo` field | `anthropic.md`, "Data" | "us" or "global" only, at another price; not an EU option. Not sent. |
