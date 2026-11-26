# Pleias
Read on 2026-11-27. Hosted text chat API: **no.** No page of Pleias read today offers a chat API that a customer can
sign up for and call: no address, no key, no API reference, no pricing for inference. Pleias publishes open-weight
models (Apache 2.0) on Hugging Face; the customer downloads one and runs it on its own server. The model cards show
how: vLLM, SGLang, llama.cpp, Ollama, LM Studio, Docker Model Runner, the Transformers library, or (for the
Pleias-RAG models) Pleias's own Python library.

Every fact below was read on 2026-11-27 on the linked page of Pleias itself (its site, its Hugging Face
organisation and model cards, its GitHub organisation), unless the line says "not confirmed". The pages were read as
text through a page reader that summarises; where exact wording matters a short quote is given, and where the reader
could not return the full text (one chat template) that is said. Nothing was signed up for, signed in to, or sent
to any API.

Short names for the pages used below:

| Short name | Page |
|---|---|
| [site] | https://pleias.ai/ (`https://pleias.fr` answers with a permanent redirect to it; the page's own home link points to `https://pleias.dev/`) |
| [about] | https://pleias.ai/about |
| [terms] | https://pleias.ai/terms-of-use (version 1.0, dated 04.06.2026) |
| [gdpr] | https://pleias.ai/gdpr (version 1.0, dated 4.06.2026) |
| [sillon] | https://pleias.ai/blog/sillon-ratp |
| [synth-site] | https://synth.pleias.dev/ |
| [stratum-site] | https://stratum.pleias.dev/ |
| [hf-org] | https://huggingface.co/PleIAs |
| [hf-models] | https://huggingface.co/PleIAs/models and https://huggingface.co/PleIAs/models?p=1 (33 models on two pages) |
| [rag-1b] | https://huggingface.co/PleIAs/Pleias-RAG-1B |
| [rag-1b-tok] | https://huggingface.co/PleIAs/Pleias-RAG-1B/raw/main/tokenizer_config.json |
| [rag-1b-gguf] | https://huggingface.co/PleIAs/Pleias-RAG-1B-gguf |
| [rag-350m] | https://huggingface.co/PleIAs/Pleias-RAG-350M |
| [slm-rag] | https://huggingface.co/PleIAs/Pleias-SLM-RAG |
| [bag-350m] | https://huggingface.co/PleIAs/Baguettotron |
| [bag-350m-tok] | https://huggingface.co/PleIAs/Baguettotron/raw/main/tokenizer_config.json |
| [bag-gguf] | https://huggingface.co/PleIAs/Baguettotron-GGUF |
| [bag-600m] | https://huggingface.co/PleIAs/baguettotron-600m |
| [bag-moe] | https://huggingface.co/PleIAs/Baguettotron-MoE |
| [monad] | https://huggingface.co/PleIAs/Monad |
| [nano] | https://huggingface.co/PleIAs/Pleias-Nano |
| [pico] | https://huggingface.co/PleIAs/Pleias-Pico |
| [p-350m] | https://huggingface.co/PleIAs/Pleias-350m-Preview |
| [p-1.2b] | https://huggingface.co/PleIAs/Pleias-1.2b-Preview |
| [p-3b] | https://huggingface.co/PleIAs/Pleias-3b-Preview |
| [cassandre] | https://huggingface.co/PleIAs/Cassandre-RAG |
| [gh-org] | https://github.com/Pleias (26 public repositories; the page read showed 14 of them) |
| [rag-lib] | https://github.com/Pleias/Pleias-RAG-Library and its README at https://raw.githubusercontent.com/Pleias/Pleias-RAG-Library/main/README.md |

## Reaching it
- Base URL and the chat path: **none at Pleias.** The only addresses on Pleias's pages are those of a server the
  customer starts itself:
  - vLLM: `http://localhost:8000/v1/chat/completions` for Baguettotron 600M, Baguettotron-MoE and Monad
    [bag-600m] [bag-moe] [monad]; `http://localhost:8000/v1/completions` (a `prompt`, not `messages`) for
    Pleias-RAG-1B and Pleias-SLM-RAG [rag-1b] [slm-rag].
  - SGLang: port 30000, the same two paths for the same models [bag-600m] [monad] [rag-1b].
  - llama.cpp: `llama serve -hf <repository>`, described as an OpenAI-compatible server with a web page; no port
    or path is given [slm-rag] [rag-1b-gguf] [bag-gguf].
  - The Pleias-SLM-RAG repository ships its own small server (Flask, llama.cpp bindings and a LanceDB full-text
    search) on port 8081 with `POST /ask_stream` and `POST /raw_query`; both answer with a plain-text stream. This
    is **not** the OpenAI format [slm-rag].
- Hosted by the vendor, run by the customer, or both: **run by the customer.** Where looked for a hosted API, and
  what was found:
  - The home page has no API, platform, sign-up or pricing; its calls to action are "Book a demo" and "Explore on
    HuggingFace" [site]. The about page says the stack "Can run entirely on your own infrastructure - on-premise
    or on-device" and names no API [about].
  - The terms of use cover the website only [terms]; the GDPR notice covers website visitors, newsletter and job
    applicants only [gdpr]. Neither mentions an API, keys or prompts.
  - Every model card read carries the Hugging Face notice that the model is not deployed by any inference
    provider [rag-1b] [rag-350m] [slm-rag] [bag-350m] [bag-600m] [bag-moe] [monad] [p-3b].
  - The GitHub organisation shows no API client, SDK for a hosted service or API reference among the repositories
    listed [gh-org]. The Pleias-RAG library loads a model locally (vLLM on a GPU, Transformers on a CPU); its
    "API" is Python methods, not an HTTP service [rag-lib].
  - Sillon, a 600M model Pleias built for RATP, runs "at RATP's sovereign infrastructure on Scaleway"; the page
    describes no access for anyone else and no published weights [sillon].
  - Two products mention an interface, neither a chat API: Synth (synthetic training data) "deploys on-premise
    within your infrastructure or is available via API", in beta behind a waiting list, with no address,
    reference or key described [synth-site]; Stratum is a Python SDK for document preparation in closed beta,
    deployed inside the customer's infrastructure [stratum-site].
- API format: whatever the customer's server speaks. With vLLM, SGLang or llama.cpp as the cards show them, that is
  the OpenAI format [bag-600m] [monad] [slm-rag]. Pleias defines no API format of its own for chat.
- API versions or dated headers a request must send: none (there is no Pleias API).

## Signing in
- Method: none at Pleias. Sign-in is whatever the customer configures on its own server (a bearer key, or
  nothing); the model cards show requests to `localhost` without a key [bag-600m] [monad] [rag-1b].
- What the customer must create at the provider, in plain words: nothing. The customer downloads the model files
  from Hugging Face and starts a server. For the preset: **the customer must have one of Pleias's models running
  behind its own OpenAI-compatible server (for example vLLM or llama.cpp) and gives that server's address, the
  model name as that server knows it, and the server's key if it set one.**

## What a request can do
There is no Pleias API, so each row says what Pleias documents for its *models* when served as its cards show.

| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | not documented for any model | No model card read mentions tools or function calling. The chat template of Baguettotron has no tools part. The RAG cards speak of "proto-agentic" reasoning steps (query analysis, reformulation), which are not tool calls. | [bag-350m] [bag-350m-tok] [bag-600m] [bag-moe] [monad] [rag-1b] [rag-350m] [slm-rag] |
| Forced tool call | not documented | as above | as above |
| Parallel tool calls | not documented | as above | as above |
| Structured output with a JSON Schema (and which schema keywords are refused) | not documented for any model | No card mentions JSON output or a schema. The Pleias-RAG library turns the model's marked-up text into a Python dictionary (`language`, `query_report`, `source_analysis`, `draft`, `answer`, `clean_answer`, `citations`) and can export it as JSON; that is parsing after the fact by the library, not a `response_format` in a request. Whether the customer's server can force a schema on these models: not confirmed. | [rag-lib] [rag-350m] |
| Tools together with structured output | not documented | neither is documented | as above |
| Streaming (and whether usage arrives in the stream) | the server's matter; not documented by Pleias for the OpenAI-format servers | Pleias's own small server for Pleias-SLM-RAG streams plain text. For vLLM, SGLang and llama.cpp the cards show no streamed request. | [slm-rag] [bag-600m] |
| System instructions (a role, or a separate field) | **not used by the models** | Baguettotron 600M: no system prompt is used; Baguettotron-MoE was trained without one. The Baguettotron chat template writes any role name it is given between its turn markers, so a system message is rendered, but the model was not trained on one. Pleias-RAG-1B has no chat template at all. | [bag-600m] [bag-moe] [bag-350m-tok] [rag-1b-tok] |
| Listing models | the server's matter | Pleias documents no model-list endpoint. The list of published models is the Hugging Face organisation page. | [hf-models] |

## Limits and shapes

### The text models Pleias publishes
From [hf-models] (33 repositories; sizes as the list shows them) and each model's card. Licence Apache 2.0 unless
noted. Classifiers, OCR correctors and retrieval encoders in the same list (CommonLingua, celadon, OCRerrcr,
ksante-colbert-small, KaribuAI, Segmentext, BibTexer, Bibstyle-Detector, Topical, classifier_bandeaux, Florence-PDF,
OCRonos, OCRonos-Vintage, OCRonos-Vintage-CT2, journaux-lm-v1) are not chat models and are left out.

| Repository | Size | What it is | Input layout | Link |
|---|---|---|---|---|
| `PleIAs/Baguettotron-MoE` | 13.3B in total, about 1.08B active per token (16 experts) | reasoning model trained on the synthetic SYNTH corpus; no preference tuning or safety alignment, "not intended for high-stakes use without further evaluation" | chat turns (ChatML markers), thinking block first; context 2,048 tokens for prompt, reasoning and answer together | [bag-moe] |
| `PleIAs/baguettotron-600m` | 608M | same family; reasoning, instruction following, factual recall | same; context 2,048 tokens | [bag-600m] |
| `PleIAs/Baguettotron` (and `PleIAs/Baguettotron-GGUF`) | about 321M | same family; general small reasoning model, also retrieval-grounded answers; French, German, Italian, Spanish, Polish, weaker Latin and Dutch | same; context length not stated on the card (an example command on the GGUF card uses `-c 2048`) | [bag-350m] [bag-gguf] |
| `PleIAs/Monad` | 56.7M | same family, English only; "no multi-turn support yet"; hallucinations expected at this size | chat turns, one turn only | [monad] |
| `PleIAs/Pleias-SLM-RAG` | about 0.3B (GGUF, Q8_0, 328 MB) | "Pleias Redline", a fine-tune of Baguettotron for answering from sources with quotes; English and French; ships with its own server | chat turns; the user turn holds the question and numbered source blocks; context 4,096 in the shipped settings | [slm-rag] |
| `PleIAs/Pleias-RAG-1B` (and `PleIAs/Pleias-RAG-1B-gguf`) | about 1.2B | retrieval-augmented answering, search and source summarising, with citations and a written reasoning trace | **its own special tokens; no chat template** | [rag-1b] [rag-1b-tok] [rag-1b-gguf] |
| `PleIAs/Pleias-RAG-350M` (and `PleIAs/Pleias-RAG-350M-gguf`) | 350M | same, smaller; fine-tuned from Pleias-350m-Preview | its own special tokens | [rag-350m] |
| `PleIAs/Pleias-Nano` (and `PleIAs/Pleias-Nano-GGUF`) | 1.2B | "Pleias-nano-1.2b-RAG 0.1", the earlier RAG model | an older set of special tokens (see below) | [nano] |
| `PleIAs/Pleias-Pico` (and `PleIAs/Pleias-Pico-GGUF`) | about 353M | the earlier small RAG model | the same older set | [pico] |
| `PleIAs/Pleias-350m-Preview`, `PleIAs/Pleias-1.2b-Preview`, `PleIAs/Pleias-3b-Preview` | 350M, 1.2B, 3B | base models trained on Common Corpus; **not chat models**: "only able to run continuation prompts" | plain text continuation | [p-350m] [p-1.2b] [p-3b] |
| `PleIAs/Cassandre-RAG` | 8B | fine-tune of Llama 3.1 8B for French administrative documents; licence not stated on the card | a text layout with `### Query ###`, `### Source ###`, `### Answer ###` and the stop string `#END#` | [cassandre] |

### What makes these models unlike a general chat model
- **The Baguettotron family and Monad** [bag-350m] [bag-600m] [bag-moe] [monad] [bag-350m-tok]:
  - Turn markers `<|im_start|>` and `<|im_end|>`; a chat template is bundled, so a server that applies it accepts
    a plain `messages` list on `/v1/chat/completions`.
  - The template opens the assistant's turn with `<think>`, so every answer begins with a reasoning trace that
    the model closes with `</think>` before the answer. The trace is in English whatever the question's language
    and uses its own symbols. With vLLM the cards add `--reasoning-parser deepseek_r1`, which moves the trace to
    a separate `reasoning` field; without it the trace is part of the answer text and the caller must cut it
    off at `</think>`.
  - No system prompt. In a conversation of several turns only the latest thinking trace should be kept
    [bag-350m]. Monad has no multi-turn support [monad].
  - Sources for a grounded answer go inside the user's message as `<source_1>…</source_1>`, and the answer
    quotes them in `<ref>…</ref>` tags [bag-600m] [bag-moe].
  - The window is small: 2,048 tokens for prompt, reasoning and answer together on the 600M and MoE models; the
    MoE card recommends `max_tokens` 1536 [bag-600m] [bag-moe].
  - Recommended sampling: temperature 0.1, `top_p` 0.95, `presence_penalty` 0.1 [bag-600m] [bag-moe].
  - Baguettotron-MoE needs `--trust-remote-code --model-impl transformers --enforce-eager` on vLLM and about
    26.6 GB of weights on one 80 GB GPU [bag-moe].
- **Pleias-SLM-RAG** [slm-rag]: chat turns as above; the user turn is the question followed by source blocks
  labelled `**source_1**`, `**source_2**`, …; the answer thinks in a `<think>` block and cites with
  `<ref name="…">quote</ref>`. Shipped defaults: temperature 0.1, `top_p` 0.95, 2,048 new tokens.
- **Pleias-RAG-1B and Pleias-RAG-350M** [rag-1b] [rag-350m] [rag-1b-tok] [rag-lib]:
  - The model cards do not give the prompt layout; they send the reader to the Pleias-RAG library. The library's
    README gives it: the question between `<|query_start|>` and `<|query_end|>`, then each source as
    `<|source_start|><|source_id|>1 …<|source_end|>`, and the prompt ends with `<|language_start|>`.
  - The answer is one marked-up text: detected language, query analysis and report, source analysis and report,
    a draft, then the answer (the tokenizer lists `<|language_end|>`, `<|query_analysis_start|>` …
    `<|answer_start|>`, `<|answer_end|>`), with citations to the numbered sources.
  - The tokenizer file of Pleias-RAG-1B has **no chat template** and no turn markers [rag-1b-tok]. The serving
    examples on the card call `/v1/completions` with a `prompt`, not the chat path [rag-1b]. So Pleias documents
    no way to use these two models through a plain chat request; the documented ways are its library, or a
    completion request with the layout above.
  - Library defaults: temperature 0.0, repetition penalty 1.0, 2,048 new tokens; "It is recommended to use the
    default parameters" [rag-lib].
  - The library has no published install command yet ("To be completed when the library is put somewhere")
    [rag-lib].
- **Pleias-Nano and Pleias-Pico** [nano] [pico]: an older layout, different from the one above:
  `<|query_start|>…<|query_end|>`, each source as
  `<|source_start|><|source_id_start|>ID<|source_id_end|>text<|source_end|>`, then `<|source_analysis_start|>`.
  Temperature 0 and no repetition penalty, which the cards say harms citations.

### What works through a plain chat request (the honest note for the preset)
- Works as Pleias documents it: a single user message, or user and assistant turns, to Baguettotron 350M, 600M
  or MoE, to Monad (one turn) and to Pleias-SLM-RAG, served by a server that applies the bundled chat template.
  The caller writes sources into the user message in the model's own tags and reads quotes from the answer text.
- Not documented, so not to be promised: a system message, tool calling, a forced tool call, structured output
  with a schema, and anything on the chat path for Pleias-RAG-1B, Pleias-RAG-350M, Pleias-Nano, Pleias-Pico and
  the three Preview base models.

### Template fields
- Required fields a neutral request may lack (for example maximum tokens): none documented; the server decides.
  With a 2,048-token window a maximum of output tokens is wise [bag-moe].
- Temperature range; tool name rules; model name shape: no range stated; recommended values above. No tool name
  rules (no tools). Model name: the Hugging Face repository name in the vLLM examples, for example
  `PleIAs/baguettotron-600m` [bag-600m]; on another server whatever name that server gives the model.
- The shape of a tool call in an answer, and of a tool result in a request: not documented (no tool calling).
- Finish reasons and what each means: not documented by Pleias; the server's.
- Error body shape, error codes, rate-limit headers: not documented by Pleias; the server's.

## Data
- Where requests are processed, as the provider states it: on the customer's own machines, because the customer
  runs the model. The about page: "Can run entirely on your own infrastructure - on-premise or on-device"
  [about]. The Pleias-SLM-RAG card about its own server: "No data leaves the machine." [slm-rag]. No page names a
  Pleias data centre for inference, because Pleias offers none.
- The option not to store requests, and whether training on them is off by default: not applicable to a model
  the customer runs; Pleias is not in the path of a request as its pages describe it. No page read says so in
  those words, and no page read says the opposite. There is no request field for it.

## Not confirmed
- That no hosted chat API exists anywhere at Pleias. What can be said is that none is on the pages read: [site],
  [about], [terms], [gdpr], [sillon], [synth-site], [stratum-site], [hf-org], [hf-models], the model cards above,
  [gh-org], [rag-lib]. Not read: the 12 GitHub repositories the organisation page did not show, the blog posts
  other than [sillon], the research page, the Stratum documentation (`pleias.github.io/stratum`), and the pages
  `/legal-mentions`, `/copyright`, `/training-content`. Two searches for a Pleias API address or key page returned
  nothing of Pleias's own.
- What Synth's "available via API" is (address, sign-in, format, where it runs). It is a data-generation
  product, not a chat API, and its page gives no detail. Tried: [synth-site].
- Azure's model catalogue listing Pleias-RAG-350M behind an OpenAI-compatible endpoint (the earlier overview row,
  from a search summary). That is a page of another company, not of Pleias; it was not opened and no page of
  Pleias read today mentions it.
- The full text of the Baguettotron chat template: the reader returned only its start and a description (each
  message as `<|im_start|>role`, content, `<|im_end|>`; then `<|im_start|>assistant` and `<think>`). Tried:
  [bag-350m-tok]. The templates of the 600M, MoE, Monad and SLM-RAG repositories were not opened; their cards
  describe the same layout.
- Whether a system message harms or is ignored by the Baguettotron models; the cards say only that none is used.
- What a chat request to Pleias-RAG-1B or Pleias-RAG-350M does on a server (refused for lack of a template, or
  answered with a generic one). Tried: [rag-1b], [rag-1b-tok], [rag-350m], [rag-1b-gguf].
- The exact source layout for the Pleias-RAG models beyond the README's example (whether text follows the id on
  the same line, where metadata goes), and the stop token. The library's source files were not readable (the
  `src` folder address returned 404). Tried: [rag-lib].
- Context length of Baguettotron 350M, Monad, Pleias-RAG-1B and Pleias-RAG-350M: not on their cards.
- Languages of Pleias-RAG-1B: its text names French, German, Italian, Spanish, Polish, Latin and Portuguese; its
  tags count five languages [rag-1b]. English is not in the sentence read; whether it is covered was not
  confirmed.
- Tool calling, structured output and streaming on any server for any of these models: nothing on any page read.
- Whether the weights of Sillon are published. Tried: [sillon], [hf-models] (not in the list).
- The licence of Cassandre-RAG and of the Pleias-RAG library (a LICENSE file is linked; its text was not read).

## Pages that disagree
- The site's address: the Hugging Face organisation gives `https://pleias.fr/`, which redirects to
  `https://pleias.ai/`, whose own navigation links to `https://pleias.dev/` [hf-org] [site].
- Serving paths: the cards of Pleias-RAG-1B and Pleias-SLM-RAG show `/v1/completions`; the cards of
  Baguettotron 600M, MoE and Monad show `/v1/chat/completions` [rag-1b] [slm-rag] [bag-600m] [bag-moe] [monad].
  For Pleias-SLM-RAG this sits next to a documented chat layout, so both may work; for Pleias-RAG-1B there is no
  chat template.
- Two source layouts under the name "Pleias RAG": the older one with `<|source_id_start|>` and
  `<|source_id_end|>` [nano] [pico], the newer one with a single `<|source_id|>` [rag-lib] [rag-1b-tok].
- Size of Pleias-RAG-350M: 350M on its card, "0.4B" in the list [rag-350m] [hf-models].

## Proposed overview row
For `docs/providers/overview.md`, read on 2026-11-27:

| Provider | Hosted model API | API format | Sign-in | Tool calling | JSON-schema output | Streaming | Where data is processed | Run by |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Pleias | **no hosted chat API**: none on its site, terms, model cards or GitHub organisation; open-weight models on Hugging Face (Apache 2.0): Baguettotron 350M, 600M and MoE 13B, Monad 56M, Pleias-SLM-RAG, Pleias-RAG 350M and 1B, the older Pleias-Pico and Pleias-Nano, three Preview base models | the customer's own server; the model cards show vLLM, SGLang and llama.cpp (OpenAI format). Chat path documented for the Baguettotron family, Monad and Pleias-SLM-RAG; the Pleias-RAG 350M and 1B models have no chat template and are shown only with the completions path or Pleias's Python library | none at Pleias; the customer's server decides (the examples use no key) | not documented for any model; parallel calls: not documented | not documented for any model; with tools: not documented | not documented by Pleias for the OpenAI-format servers (its own small RAG server streams plain text) | on the customer's machines ("Can run entirely on your own infrastructure - on-premise or on-device"); no store or training option exists or is needed; no Pleias data centre is in the path | customer |

Base URL: none, the customer gives it. Model list endpoint: none at Pleias; the server's. System messages: the
models are trained without one. Answers of the chat-capable models start with a thinking trace.
