# Silo AI (AMD)

Read on 2026-11-27. Hosted text chat API: no. No vendor-hosted chat API that a customer can sign up for and call was
found on any page of Silo AI or AMD that could be read; the list of what was searched is under "Where a hosted API was
looked for". Silo AI's text models are published as open weights, and the customer runs them on its own server.

Every fact below was read on 2026-11-27 on the page linked next to it, unless the cell says "from a search summary"
or "not confirmed". Only pages of Silo AI and AMD were used: the model cards and model files they published on
Hugging Face under the `LumiOpen` organisation, AMD's ROCm blog, AMD's Enterprise AI documentation, and the SiloGen
pages on GitHub. All examples are synthetic unless marked "from the page".

One sentence for the preset: **the customer must have one of Silo AI's open models running on its own server that
speaks the OpenAI chat-completions format (for example vLLM), reachable at an address the customer gives, with an
optional bearer key that the customer set on that server.**

Short names for the pages:

- [HF-ORG]: https://huggingface.co/LumiOpen
- [HF-LIST]: https://huggingface.co/api/models?author=LumiOpen&limit=200 (the organisation's public model list)
- [P2-8B]: https://huggingface.co/LumiOpen/Llama-Poro-2-8B-Instruct
- [P2-8B-RAW]: https://huggingface.co/LumiOpen/Llama-Poro-2-8B-Instruct/raw/main/README.md
- [P2-8B-TOK]: https://huggingface.co/LumiOpen/Llama-Poro-2-8B-Instruct/raw/main/tokenizer_config.json
- [P2-70B]: https://huggingface.co/LumiOpen/Llama-Poro-2-70B-Instruct
- [P2-70B-TOK]: https://huggingface.co/LumiOpen/Llama-Poro-2-70B-Instruct/raw/main/tokenizer_config.json
- [P2-LONG]: https://huggingface.co/LumiOpen/Llama-Poro-2-8B-Long-Instruct
- [P2-LONG-RAW]: https://huggingface.co/LumiOpen/Llama-Poro-2-8B-Long-Instruct/raw/main/README.md
- [P2-LONG-TOK]: https://huggingface.co/LumiOpen/Llama-Poro-2-8B-Long-Instruct/raw/main/tokenizer_config.json
- [P1-CHAT]: https://huggingface.co/LumiOpen/Poro-34B-chat
- [P1-CHAT-RAW]: https://huggingface.co/LumiOpen/Poro-34B-chat/raw/main/README.md
- [V-33B]: https://huggingface.co/LumiOpen/Viking-33B
- [V-7B-RAW]: https://huggingface.co/LumiOpen/Viking-7B/raw/main/README.md
- [HF-SILOGEN]: https://huggingface.co/silogen
- [B-CPT]: https://rocm.blogs.amd.com/artificial-intelligence/multilingual-continued-pretraining/README.html
- [B-LONG]: https://rocm.blogs.amd.com/artificial-intelligence/rl-training-playbook/README.html
- [GH-SILOGEN]: https://github.com/silogen
- [W-INDEX]: https://silogen.github.io/ai-workloads
- [W-VLLM]: https://silogen.github.io/ai-workloads/workloads/llm-inference-vllm/helm/
- [E-HOME]: https://enterprise-ai.docs.amd.com/en/latest/
- [E-AIM]: https://enterprise-ai.docs.amd.com/en/latest/aims/overview.html
- [E-AIM-RUN]: https://enterprise-ai.docs.amd.com/en/latest/aims/guides/deployment-guide.html
- [E-AIM-CAT]: https://enterprise-ai.docs.amd.com/en/latest/aims/catalog/models.html
- [E-WB]: https://enterprise-ai.docs.amd.com/en/latest/workbench/overview.html
- [E-WB-INF]: https://enterprise-ai.docs.amd.com/en/latest/workbench/inference/overview.html
- [E-WB-HOW]: https://enterprise-ai.docs.amd.com/en/latest/workbench/inference/how-to-deploy-and-inference.html
- [E-WB-OPT]: https://enterprise-ai.docs.amd.com/en/latest/workbench/inference/deployment-options.html
- [E-WB-KEYS]: https://enterprise-ai.docs.amd.com/en/latest/workbench/api-keys.html
- [AMD-SILO]: https://www.amd.com/en/solutions/ai/silo-ai.html (could not be opened; see "Not confirmed")

## Where a hosted API was looked for

| Place | What was found | Link |
|---|---|---|
| Silo AI's own site | `www.silo.ai` answers with a permanent redirect to AMD's Silo AI page. The redirect was read; the target page was not (four attempts, each timed out). | https://www.silo.ai/ , [AMD-SILO] |
| AMD's Silo AI page | Not opened. From a search summary only: it presents the SiloGen platform as a one-stop solution for developing and deploying AI that combines AMD software, hardware and expertise, and does not name Poro or Viking as hosted products. No API address, sign-up or reference was reported. | [AMD-SILO] |
| SiloGen's own domain | `silogen.ai` (the website named on SiloGen's Hugging Face page) does not resolve. | [HF-SILOGEN] |
| SiloGen on GitHub | Tools to set up and run workloads on a Kubernetes cluster (`cluster-bloom`, `cluster-forge`, `kaiwo`, `ai-workloads`). No API reference and no hosted service. | [GH-SILOGEN] |
| SiloGen "AI Workloads" documentation | Helm charts that serve a model on the reader's own cluster (vLLM, TGI, SGLang, Ollama, llama.cpp), and chat interfaces. No vendor address. | [W-INDEX], [W-VLLM] |
| The model cards of Poro 2, Poro and Viking | Usage is shown with the `transformers` library only. No card names an API, an endpoint or a hosted service. Each model's page states "This model isn't deployed by any Inference Provider." | [P2-8B], [P2-70B], [P2-LONG], [P1-CHAT], [V-33B] |
| AMD's ROCm blog on Poro 2 and Poro 2 Long | Training recipes; the models are linked on Hugging Face. No hosted API, no endpoint. | [B-CPT], [B-LONG] |
| AMD Enterprise AI documentation | Software the customer deploys: inference containers ("distributed as Docker images") and a workbench on a cluster. Addresses are written as `https://ai.<your-domain>/...`, the customer's own domain. No AMD-hosted address is documented. Silo AI, SiloGen, Poro and Viking are not named. | [E-HOME], [E-AIM], [E-WB], [E-WB-KEYS] |
| AMD's catalogue of inference containers | 93 entries; no Poro, Viking or other `LumiOpen` model among them. | [E-AIM-CAT] |
| Web search for a SiloGen API reference or chat-completions documentation | No page of Silo AI or AMD found. | (search, 2026-11-27) |

Conclusion: no documented address, sign-in and request format of a vendor-hosted chat API exists on the pages read.
The earlier overview's remark that one Viking release is served on Google Cloud could not be confirmed on any page
read today.

## The text models Silo AI publishes

All are published on Hugging Face under the organisation `LumiOpen` [HF-ORG], which holds 93 models: 17 text
generation models and 76 text classifiers [HF-LIST]. The cards name Silo AI as a maker: Poro 2 "was created in a
collaboration between AMD Silo AI", the TurkuNLP group of the University of Turku and HPLT [P2-8B-RAW]; the older
cards say "SiloGen from Silo AI" [P1-CHAT-RAW], [V-33B].

Models meant for chat (instruction-tuned, with a chat template):

| Model (repository) | Size | Licence | Context | Languages | Chat template | Link |
|---|---|---|---|---|---|---|
| `LumiOpen/Llama-Poro-2-8B-Instruct` | 8.03B parameters; built on Llama 3.1 8B | Llama 3.3 Community License | 8,192 tokens | Finnish, English | yes, in the tokenizer file; the Llama 3.1 form (`<\|start_header_id\|>`, `<\|eot_id\|>`); system role and tools handled | [P2-8B], [P2-8B-RAW], [P2-8B-TOK] |
| `LumiOpen/Llama-Poro-2-70B-Instruct` | 70.55B parameters; "based on the Llama 3.1 70B architecture" | Llama 3.3 Community License | 8,192 tokens | Finnish, English | yes, the same form; system role and tools handled | [P2-70B], [P2-70B-TOK] |
| `LumiOpen/Llama-Poro-2-8B-Long-Instruct` | 8B parameters; from Poro 2 8B Long Base | Llama 3.3 Community License | "up to 128K tokens"; the tokenizer file gives 131,072 | English, Finnish | yes, the same form; system role and tools handled | [P2-LONG], [P2-LONG-RAW], [P2-LONG-TOK], [B-LONG] |
| `LumiOpen/Poro-34B-chat` | 34B parameters; chat-tuned from Poro 34B | Apache 2.0 | not stated on the card | Finnish, English | yes: "We use the ChatML chat template." with an optional system prompt; tools not mentioned | [P1-CHAT], [P1-CHAT-RAW] |

Other text models in the same organisation [HF-LIST]:

- Variants of Poro 34B chat: `LumiOpen/Poro-34B-chat-GGUF`, `LumiOpen/Poro-34B-chat-OpenAssistant` (Apache 2.0).
  Their cards were not opened.
- Intermediate and preview models of Poro 2: `Llama-Poro-2-8B-SFT`, `Llama-Poro-2-70B-SFT` (Llama 3.3 licence tag),
  `Llama-Poro-2-8B-Long-Math-Reasoning-SFT-Preview`, `Llama-Poro-2-8B-Long-Math-Reasoning-RL-Preview` (Llama 3.1
  licence tag). The two reasoning models are marked "Preview" by AMD [B-LONG]. Their cards were not opened.
- Base models, not meant for chat as published: `LumiOpen/Poro-34B`, `LumiOpen/Viking-7B`, `LumiOpen/Viking-13B`,
  `LumiOpen/Viking-33B` (Apache 2.0), and `LumiOpen/Llama-Poro-2-8B-base`, `LumiOpen/Llama-Poro-2-70B-base`,
  `LumiOpen/Llama-Poro-2-8B-Long-Base` (Llama 3.1 licence tag). The Viking cards say: "Viking is a base model which
  needs further fine tuning for most use cases." Viking has a 4,096-token sequence length and covers Finnish,
  English, Swedish, Danish, Norwegian, Icelandic and code; Viking 7B has about 7.55B parameters. The cards mention
  no chat template [V-33B], [V-7B-RAW]. **No instruction-tuned or chat Viking model is published in the
  organisation's list** [HF-LIST], so Viking cannot be offered for chat without the customer's own fine-tuning.

Points where the pages disagree:

- Licence against base: the Poro 2 instruct models are built on Llama 3.1 [P2-8B-RAW], [P2-70B] and are released
  under the Llama 3.3 Community License; the base and preview models carry the Llama 3.1 licence tag [HF-LIST].
  Both are recorded as the pages give them. Neither is Apache 2.0; only the older Poro 34B and Viking models are.
- Context of Poro 2 8B Long Instruct: the card says "up to 128K tokens" and, under its limitations, "Extended
  context (32K tokens)"; the fine-tuning used a sequence length of 32,768 [P2-LONG-RAW]. The tokenizer file gives
  131,072 [P2-LONG-TOK].
- Repository name: the usage code on the Long Instruct card loads `LumiOpen/Llama-Poro-2-Long-Instruct` (without
  "8B"), while the repository is `LumiOpen/Llama-Poro-2-8B-Long-Instruct` [P2-LONG-RAW], [P2-LONG].

## What Silo AI and AMD document for serving a model

- **The model cards document no server.** The card text of Poro 2 8B Instruct, Poro 2 8B Long Instruct, Poro 34B
  chat and Viking 7B shows loading the model with the `transformers` library and, for the chat models, calling the
  tokenizer's chat template; it names no vLLM, no text-generation-inference and no OpenAI-compatible server
  [P2-8B-RAW], [P2-LONG-RAW], [P1-CHAT-RAW], [V-7B-RAW]. The commands `vllm serve "LumiOpen/..."` with a request to
  `http://localhost:8000/v1/chat/completions` that appear on the models' Hugging Face pages [P2-8B], [P2-70B],
  [P2-LONG], [P1-CHAT] are part of the hosting site's page, not of the card Silo AI wrote. The repositories carry a
  `text-generation-inference` tag on the page, with no instructions [P2-8B].
- **SiloGen's workload collection** has a Helm chart that serves a Hugging Face model with vLLM on the reader's own
  Kubernetes cluster. The model is given by its Hugging Face id (or a bucket path); the service is reached at
  `/v1/chat/completions` with a `model` field and `messages` with the roles `system` and `user`; the example uses a
  port-forward to `http://localhost:8080`. The page describes no key on the endpoint and no tool-calling settings,
  and does not use the words "OpenAI-compatible". Its examples use other models, not Poro or Viking [W-VLLM].
  Charts for TGI, SGLang, Ollama and llama.cpp are listed next to it [W-INDEX]. AMD's workbench documentation links
  to this collection [E-WB].
- **AMD Inference Microservices (AIM)**, AMD's documented way to serve a model: containers the customer runs,
  "distributed as Docker images"; "AIM runs language models on vLLM"; language models get an OpenAI-compatible API
  [E-AIM]. The quick-start container listens on port 8000 with `GET /v1/models` and `POST /v1/chat/completions`
  (and an Anthropic-style `/v1/messages`); "The API can be protected with an API key, but it is outside of the
  scope of this example." A general image `aim-base` takes the model id in `AIM_MODEL_ID`; steps for a model outside
  the catalogue are not given there [E-AIM-RUN]. Poro and Viking are not in the catalogue [E-AIM-CAT].
- **AMD AI Workbench**, when the customer installs it on its cluster, puts a gateway in front of deployed models:
  `https://ai.<your-domain>/v1/chat/completions` and `https://ai.<your-domain>/v1/models`, with
  `Authorization: Bearer <key>`; keys are made per project under Settings, API Keys, with a validity period and
  optionally bound to chosen deployments; the full key is shown once (the page gives the prefix `amd_aim_api_key_`).
  Two optional routing headers, `x-ai-eg-backend` (the deployment's UUID) and `x-ai-eg-model`, pick one deployment
  when the same model name is deployed more than once; without them the gateway picks any deployment with that
  name. The model list answers with HTTP status 203, and the page says to treat any 2xx as success [E-WB-KEYS].
  The workbench can also deploy a model brought from Hugging Face [E-WB-INF].

So the engine both of the vendor's own serving documents use is vLLM, behind the OpenAI chat-completions paths.
That Poro or Viking in particular should be served this way is not written on a page of Silo AI or AMD.

## Reaching it

- Base URL and the chat path: **none from the vendor.** The customer gives the address of its own server. On the
  documented servers the chat path is `/v1/chat/completions`: `http://localhost:8000/v1/chat/completions` for an
  AIM container [E-AIM-RUN], `https://ai.<your-domain>/v1/chat/completions` behind AMD AI Workbench [E-WB-KEYS],
  the service's `/v1/chat/completions` for the SiloGen vLLM chart [W-VLLM].
- Hosted by the vendor, run by the customer, or both: **run by the customer.**
- API format: none of its own. The format is that of the customer's server: the OpenAI chat-completions format on
  the servers AMD documents [E-AIM], [E-WB-KEYS].
- API versions or dated headers a request must send: none documented. Behind AMD AI Workbench the optional headers
  `x-ai-eg-backend` and `x-ai-eg-model` exist [E-WB-KEYS].

## Signing in

- Method: whatever the customer's server asks.
  - A plain AIM container or the SiloGen vLLM chart: no key in the documented examples [E-AIM-RUN], [W-VLLM]. A key
    can be set on an AIM; where one is set, the server "expects the key as a bearer token" (said about its
    Anthropic-style endpoints) [E-AIM-RUN].
  - Behind AMD AI Workbench: `Authorization: Bearer <key>` [E-WB-KEYS].
- What the customer must create at the provider, in plain words: **nothing at Silo AI or AMD; there is no account
  to open for a chat API.** The customer needs: a server with enough GPU memory; the model's weights from Hugging
  Face (the SiloGen chart asks for a Hugging Face token as a cluster secret [W-VLLM]; AMD asks for one only for
  gated models [E-AIM-RUN]; whether the Poro 2 repositories are gated was not read); a server program that speaks
  the OpenAI chat-completions format; an address the application can reach; and, advisable, a key on that server.
  The customer must also accept the model's licence: the Llama 3.3 Community License for the Poro 2 instruct
  models, Apache 2.0 for Poro 34B chat [P2-8B-RAW], [P1-CHAT-RAW].

## What a request can do

There is no vendor API, so each row says what the published model and the documented server give. "Server" means
the customer's OpenAI-compatible server; its own request fields are not described on a page of Silo AI or AMD and
are not filled in here from another source.

| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | not confirmed as a feature. No model card mentions tool or function calling. The chat template shipped with the three Poro 2 instruct models does accept tool definitions (placed in the first user message by default), an assistant tool call and a tool result (roles `tool` or `ipython`). Poro 34B chat: not mentioned. Viking: base models, no chat template mentioned. Whether the Poro 2 models were trained or tested to call tools is not stated anywhere read. | On the server: AMD's AIM guide names the engine arguments `tool-call-parser` and `enable-auto-tool-choice` as required for tool use, with a parser that matches the model's format; it shows them for another model. Which parser fits Poro 2: not confirmed. | [P2-8B-RAW], [P2-8B-TOK], [P2-70B-TOK], [P2-LONG-TOK], [P1-CHAT-RAW], [E-AIM-RUN] |
| Forced tool call | not confirmed | Not on any page read. | [P2-8B-RAW], [E-AIM-RUN] |
| Parallel tool calls | no for Poro 2, by its template: more than one tool call in an assistant message is refused with "This model only supports single tool-calls at once!". Other models: not confirmed. | none | [P2-8B-TOK], [P2-70B-TOK], [P2-LONG-TOK] |
| Structured output with a JSON Schema (and which schema keywords are refused) | not confirmed | Not on any model card and not in AMD's serving pages read. | [P2-8B-RAW], [P2-LONG-RAW], [E-AIM-RUN], [E-WB-OPT] |
| Tools together with structured output | not confirmed | Not on any page read. | as above |
| Streaming (and whether usage arrives in the stream) | not confirmed for the OpenAI-style chat path. AMD's AIM guide states streaming only for its Anthropic-style `/v1/messages` endpoint. Usage in a stream: not confirmed. | not documented | [E-AIM-RUN] |
| System instructions (a role, or a separate field) | a role. Poro 2: the template puts a first message with the role `system` in the system header. Poro 34B chat: ChatML with an optional system block. The SiloGen chart's example sends a `system` message. | `messages[].role: "system"` | [P2-8B-TOK], [P1-CHAT-RAW], [W-VLLM] |
| Listing models | on the server, not at the vendor: `GET /v1/models` on an AIM container and behind AMD AI Workbench (there limited to what the key may use; status 203). | `GET /v1/models` | [E-AIM-RUN], [E-WB-KEYS] |

## Limits and shapes

- Required fields a neutral request may lack: none documented. The documented examples send `model` and `messages`;
  AMD's examples also send `max_tokens` without calling it required [E-AIM-RUN], [E-WB-KEYS], [W-VLLM].
- Context length, which the server will enforce: 8,192 tokens for Poro 2 8B and 70B Instruct; up to 128K for Poro 2
  8B Long Instruct (see the disagreement above); 4,096 for Viking; not stated for Poro 34B chat [P2-8B-RAW],
  [P2-70B], [P2-LONG-RAW], [V-33B], [P1-CHAT-RAW].
- Temperature range: not confirmed. The Poro 2 8B card's own example samples with temperature 0.7 [P2-8B-RAW].
- Tool name rules: not confirmed.
- Model name shape: the name the customer's server serves. By default the Hugging Face repository id, for example
  `LumiOpen/Llama-Poro-2-8B-Instruct`; the SiloGen chart has a `served_model_name` setting that changes it
  [W-VLLM]; behind AMD AI Workbench the name is "the model's name prepended with a path to the model" [E-WB-HOW].
  So the model name is a free field the customer fills in, best read from the server's model list.
- The Poro 2 chat template adds two fixed lines to every system header: a knowledge cut-off line ("December 2023")
  and a date that defaults to "26 Jul 2024" unless the server passes another one [P2-8B-TOK], [P2-70B-TOK],
  [P2-LONG-TOK]. A caller that needs the model to know today's date must say it in its own system message.
- The shape of a tool call in an answer, and of a tool result in a request: on the wire not confirmed (it is the
  server's). Inside the Poro 2 template a tool call is written as JSON with `name` and `parameters`, and a tool
  result is a message with the role `tool` or `ipython` [P2-70B-TOK], [P2-LONG-TOK].
- Finish reasons and what each means: not confirmed. AMD's example answer shows `"finish_reason": "length"` after
  `max_tokens` was reached, and a `usage` block with token counts [E-AIM-RUN].
- Error body shape, error codes, rate-limit headers: not confirmed; none documented on the pages read. No rate
  limit of the vendor applies. A key behind AMD AI Workbench can expire or be limited to a number of uses, so
  "credentials rejected" can appear on a key that used to work [E-WB-KEYS].
- Use limits the cards state: Poro 2 has "Limited proficiency in languages other than English and Finnish" and is
  not for medical, legal or financial advice without a person checking [P2-8B], [P2-70B].

## Data

- Where requests are processed, as the provider states it: **wherever the customer runs the model.** No request
  goes to Silo AI or AMD, so neither states a processing location for chat requests. The only statement about a
  vendor-side service is the one on each model's page: "This model isn't deployed by any Inference Provider."
  [P2-8B]. Behind AMD AI Workbench the address is the customer's own domain, `ai.<your-domain>` [E-WB-KEYS].
- The option not to store requests, and whether training on them is off by default: not applicable to a vendor.
  Storing and logging are settings of the customer's own server. No request field exists for it on the pages read,
  so the adapter has nothing to send for LR-31.

## Not confirmed

- AMD's Silo AI page [AMD-SILO] and three AMD blog posts on `www.amd.com` (on Poro, 2024; on Poro 2, 2025; on
  Poro 2 Long, 2026): every attempt timed out (four attempts on the Silo AI page, with and without `www`, and one
  on each blog post). What is said above about the Silo AI page comes from a search summary. So the current
  description of the SiloGen platform, and whether it has a customer-facing API behind a contract, is not confirmed
  from the page itself. No public documentation of such an API was found by search.
- That a Viking or Poro model is offered on Google Cloud or any other cloud's model catalogue (the earlier overview
  said so from a search summary): no page read today says it; a search of `amd.com` returned nothing on it.
- Whether the Poro 2 models were trained or evaluated for tool calling; which vLLM tool-call parser fits them;
  a forced tool call; structured output with a JSON Schema; tools together with structured output.
- Streaming, the usage chunk, finish reasons, the error body and the temperature range on the customer's server:
  these belong to the server program, and no page of Silo AI or AMD read today describes them for the OpenAI-style
  chat path.
- Whether an AIM container's OpenAI-style endpoints ask for a key by default, and the setting that switches a key
  on [E-AIM-RUN] says only that it can be done.
- How to serve a model that is not in AMD's catalogue (such as Poro 2) with the `aim-base` image: named, not
  described [E-AIM-RUN].
- Whether AMD AI Workbench is ever hosted by AMD: the pages read describe software installed on the customer's
  cluster and give no AMD-hosted address; the installation guide was not opened.
- Whether the `LumiOpen` Poro 2 repositories are gated (need an accepted licence and a token to download).
- The context length of Poro 34B chat; the cards of Poro 34B, Viking 13B, the GGUF and OpenAssistant variants, the
  SFT, base and preview models were not opened (their names and licence tags come from [HF-LIST]).
- The chat templates were read for their properties (tokens, roles, tool handling, default date), not copied.
  The template of Poro 34B chat was read from its card, not from its tokenizer file.
- The organisation `LumiOpen` has no description on its page [HF-ORG]; that it is Silo AI's publishing place rests
  on the model cards naming Silo AI as a maker and on AMD's blog linking to it [P2-8B-RAW], [B-CPT], [B-LONG].

## For the preset

- Adapter: the OpenAI-compatible one. Base URL: none by default; the customer must give one.
- Sign-in: an optional bearer key (the customer's own server may have none).
- Fields the customer fills in: the server's base URL, a key if the server asks one, and the model name as the
  server serves it.
- Description: "A Silo AI open model (Poro 2 or Poro) that you run yourself on a server that speaks the OpenAI
  chat-completions format, for example vLLM. Silo AI and AMD offer no hosted chat API."
- Models to list as suggestions (free text must stay possible): `LumiOpen/Llama-Poro-2-8B-Instruct`,
  `LumiOpen/Llama-Poro-2-70B-Instruct`, `LumiOpen/Llama-Poro-2-8B-Long-Instruct`, `LumiOpen/Poro-34B-chat`.
  Viking (`LumiOpen/Viking-7B`, `-13B`, `-33B`) is published as base models only and is not a chat model as
  published.
- Capabilities to report until a server says otherwise: parallel tool calls none (Poro 2 template); tool calling,
  forced tool call, structured output and streaming unknown from documentation, to be found by the connection
  check. Where data is processed: at the customer.

## Row for the overview

| Provider | Hosted model API | API format | Base URL | Sign-in | Tool calling | Parallel tool calls | JSON-schema output | Tools with structured output | Streaming | Model list endpoint | Not store / not train | Where data is processed | Run by |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Silo AI (AMD) | **no** hosted chat API found (Silo AI's site redirects to AMD; model pages: "This model isn't deployed by any Inference Provider."; AMD's Silo AI page itself not opened) | none of its own; open-weight models (Poro 2 8B, 70B and 8B Long Instruct under the Llama 3.3 Community License; Poro 34B chat under Apache 2.0; Viking 7B, 13B, 33B under Apache 2.0, base models only) run behind the customer's server; AMD's and SiloGen's serving documents use vLLM with OpenAI-style `/v1/chat/completions` | none; the customer gives its server's address | the customer's server: no key in the documented examples, or a bearer key | not confirmed as a feature; the Poro 2 chat template accepts tools; no card mentions tool calling | no for Poro 2 (its template refuses more than one call) | not confirmed | not confirmed | not confirmed for the OpenAI-style path | `GET /v1/models` on the customer's server (AMD's documents) | not applicable: no request reaches the vendor | wherever the customer runs the model | customer |

All cells read on 2026-11-27; links in the sections above.
