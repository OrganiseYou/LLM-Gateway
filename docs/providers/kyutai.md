# Kyutai

Read on 2026-11-27. Hosted text chat API: no. Kyutai publishes no API of any kind that a customer can sign up for and
call: no address, no sign-in and no request format were found on its site, its GitHub organisation or its Hugging
Face organisation; the list of what was searched is under "Where a hosted API was looked for". Kyutai is a research
lab that releases model weights and code, most of them for speech.

**Kyutai also publishes no text chat model.** Its text language models (the Helium family) are base models that
their own cards say must not be used to follow instructions, and they ship no chat template. So the honest preset
text is: the customer points the preset at whatever text chat model sits behind its own OpenAI-compatible server;
that model will not be one of Kyutai's. The library handles text chat only, so nothing of Kyutai's speech work is
reachable through it.

Every fact below was read on 2026-11-27 on the page linked next to it, unless the cell says "from a search summary"
or "not confirmed". Only Kyutai's own pages were used: kyutai.org, the repositories under `kyutai-labs` on GitHub,
and the model cards and model list under `kyutai` on Hugging Face. All examples are synthetic unless marked "from
the page".

One sentence for the preset: **the customer must have a text chat model of its own choice running on its own server
that speaks the OpenAI chat-completions format, reachable at an address the customer gives, with an optional bearer
key that the customer set on that server; Kyutai supplies neither the server nor a chat model.**

Short names for the pages:

- [K-HOME]: https://kyutai.org/
- [K-BLOG]: https://kyutai.org/blog/
- [K-UNMUTE]: https://kyutai.org/unmute/
- [K-TTS]: https://kyutai.org/tts/
- [K-STT]: https://kyutai.org/stt/
- [K-TEAM]: https://kyutai.org/team/
- [K-PRIV]: https://kyutai.org/privacy-policy/ (opened, no readable text; see "Not confirmed")
- [K-HE-PREV]: https://kyutai.org/blog/2025-01-13-helium/ (title and date only; see "Not confirmed")
- [K-HE-1]: https://kyutai.org/blog/2025-04-30-helium/ (title and date only)
- [K-MOSHI-BLOG]: https://kyutai.org/blog/2024-09-18-moshi-release/ (title and date only)
- [K-UNMUTE-BLOG]: https://kyutai.org/blog/2025-05-22-unmute/ (title only)
- [GH-ORG]: https://github.com/kyutai-labs
- [GH-UNMUTE]: https://github.com/kyutai-labs/unmute
- [GH-UNMUTE-RAW]: https://raw.githubusercontent.com/kyutai-labs/unmute/main/README.md
- [GH-MOSHI-RAW]: https://raw.githubusercontent.com/kyutai-labs/moshi/main/README.md
- [HF-ORG]: https://huggingface.co/kyutai
- [HF-LIST]: https://huggingface.co/api/models?author=kyutai&limit=200 (the organisation's public model list)
- [HE-PREV]: https://huggingface.co/kyutai/helium-1-preview-2b/raw/main/README.md
- [HE-PREV-MLX]: https://huggingface.co/kyutai/helium-1-preview-2b-mlx/raw/main/README.md
- [HE-1]: https://huggingface.co/kyutai/helium-1-2b
- [HE-1-RAW]: https://huggingface.co/kyutai/helium-1-2b/raw/main/README.md
- [HE-1-META]: https://huggingface.co/api/models/kyutai/helium-1-2b (the repository's public file list and tags)
- [HE-1-WIKI]: https://huggingface.co/kyutai/helium-1-2b-wiki/raw/main/README.md
- [HE-1-MAIN]: https://huggingface.co/kyutai/helium-1-2b-main/raw/main/README.md
- [HE-6B]: https://huggingface.co/kyutai/Sequential_Helium_6B/raw/main/README.md
- [HE-VL]: https://huggingface.co/kyutai/Helium1-VL-2B/raw/main/README.md
- [MOSHI-CARD]: https://huggingface.co/kyutai/moshiko-pytorch-bf16/raw/main/README.md
- [GRADIUM]: https://gradium.ai (a separate company; see below)

## Where a hosted API was looked for

| Place | What was found | Link |
|---|---|---|
| Kyutai's home page | A research lab: "AI RESEARCH LAB BASED IN PARIS", with open science as "one of our core values". Lists models, papers and online demos. No API, platform, developer console, pricing or account. The only sign-up is a newsletter. | [K-HOME] |
| The site's navigation and footer | Demos, Publications, Blog, About us, Join us; GitHub, Hugging Face, Privacy Policy, careers, two contact addresses. No "API", "Docs", "Platform" or "Pricing" entry. | [K-HOME] |
| The blog index (35 posts, 2023-11-17 to 2026-11-20) | Research and open-source releases. No post about an API or a hosted service. | [K-BLOG] |
| The Unmute page | A demo (`unmute.sh`) and a link to the code. Says the technology is put "into the hands of all developers for free" as code. No API, no pricing. | [K-UNMUTE] |
| The text-to-speech and speech-to-text pages | Open-source models to run yourself, and the Unmute demo to try them. No hosted API, no pricing, no sign-up. | [K-TTS], [K-STT] |
| The team page | Funders only (Iliad Group, CMA CGM Group, Schmidt Sciences). No products or services. | [K-TEAM] |
| Kyutai on GitHub (37 repositories; the 6 pinned and the 10 newest were read) | Models, research code and tools. No client library or SDK for a hosted service, and no repository for Helium. | [GH-ORG] |
| The Unmute repository | Software the reader runs with Docker Compose. Its text model is somebody else's, reached at an OpenAI-compatible server the reader chooses. No Kyutai-hosted address. | [GH-UNMUTE], [GH-UNMUTE-RAW] |
| The Moshi repository | A server and web interface the reader runs locally (`localhost:8998`). Speech in, speech out. No text chat and no OpenAI-compatible interface. | [GH-MOSHI-RAW] |
| Kyutai on Hugging Face (all 97 models in the public list) | Weights only. The page of Helium 1 states "This model isn't deployed by any Inference Provider." | [HF-ORG], [HF-LIST], [HE-1] |
| The Helium model cards | Usage is shown with the `transformers` library only. No card names an API, an endpoint or a hosted service. | [HE-PREV], [HE-1-RAW], [HE-6B] |
| Web search for a Kyutai API reference, and a search limited to kyutai.org for "API", "platform", "chat completions" | No page of Kyutai found. One third-party site offers a "Moshi API"; it is not Kyutai's and is not a source. | (search, 2026-11-27) |
| Gradium, which Kyutai's home page links as a spin-off | A separate company. Its home page lists voice models only (text-to-speech, speech-to-text, live translation, voice cloning) behind an API key; no text chat model is listed, and the page does not name Kyutai. Only its home page was read. It is not Kyutai's API and not a text chat API, so it does not change the answer. | [K-HOME], [GRADIUM] |

Conclusion: no documented address, sign-in or request format of a Kyutai-hosted API exists on the pages read, for
text or for anything else. What Kyutai runs for the public are demos in a browser.

## What Kyutai publishes

The organisation on Hugging Face holds 97 models [HF-LIST]. By kind:

### Speech and audio (not usable by this library)

| Family | What it does | Repositories (examples) | Link |
|---|---|---|---|
| Moshi | Speech-to-speech dialogue: "a speech-text foundation model and full-duplex spoken dialogue framework". Audio in, audio out; it also predicts the text of its own speech (its "inner monologue"). Weights CC-BY 4.0; Python code MIT, Rust code Apache. "This model is for research only." | `kyutai/moshiko-*`, `kyutai/moshika-*`, `kyutai/moshika-rag-*`, `kyutai/moshika-rl-seamless`, `kyutai/personaplex-rl-seamless` | [GH-MOSHI-RAW], [MOSHI-CARD], [HF-LIST] |
| Kyutai STT | Speech-to-text, streaming. PyTorch for research, a Rust server "over websockets" for production, MLX for Apple devices. | `kyutai/stt-1b-en_fr`, `kyutai/stt-2.6b-en` and their variants | [K-STT], [HF-LIST] |
| Kyutai TTS and Pocket TTS | Text-to-speech. | `kyutai/tts-1.6b-en_fr`, `kyutai/tts-0.75b-en-public`, `kyutai/pocket-tts` | [K-TTS], [HF-LIST] |
| Hibiki, Hibiki-Zero | Speech-to-speech translation. | `kyutai/hibiki-*`, `kyutai/hibiki-zero-3b-pytorch-bf16` | [HF-LIST] |
| Mimi | The audio codec the speech models use. | `kyutai/mimi` | [GH-MOSHI-RAW], [HF-LIST] |
| Unmute | Not a model: a program that puts Kyutai's speech-to-text in front of a text model and its text-to-speech behind it. "Lets text LLMs listen and speak". Code under the MIT licence. | (code only) | [GH-ORG], [GH-UNMUTE], [K-UNMUTE] |

Also in the list, and outside text chat: image, video, robotics and world models (AURA, OVIE, REKI, CADRE), the
MoshiVis models, the ARC text encoders (tagged feature extraction) and two `glm-4-voice-of-reason` models tagged
any-to-any [HF-LIST]. Their cards were not opened.

### Text language models

All text-generation entries in the list carry the name Helium [HF-LIST]:

| Model (repository) | Size | Licence | Languages | Context | Meant for chat? | Chat template | Link |
|---|---|---|---|---|---|---|---|
| `kyutai/helium-1-2b` | about 2B parameters | CC-BY-SA 4.0, and "As a model distilled from Gemma 2, Helium 1 is subject to the Gemma Terms of Use" | 24 official EU languages | 4,096 tokens | **no**: "Helium-1 is a base model, which was not fine-tuned to follow instructions or human preferences." | none: the repository has no `tokenizer_config.json` and the card mentions none | [HE-1-RAW], [HE-1-META] |
| `kyutai/helium-1-2b-books`, `-hum`, `-life`, `-main`, `-pop`, `-science`, `-stem`, `-wiki` | not stated per variant | the two cards opened (`-wiki`, `-main`) repeat the Helium 1 card word for word: CC-BY-SA 4.0 plus the Gemma terms | as above | as above | **no** (same card text) | none mentioned | [HF-LIST], [HE-1-WIKI], [HE-1-MAIN] |
| `kyutai/helium-1-preview-2b` (and `-mlx`, a pointer to the same card) | about 2B parameters | CC-BY 4.0 | English, French, German, Italian, Portuguese, Spanish | 4,096 tokens | **no**: "Helium-1 Preview is a base model, which was not fine-tuned to follow instructions or human preferences." | none mentioned | [HE-PREV], [HE-PREV-MLX] |
| `kyutai/Sequential_Helium_6B` | not stated; the name says 6B | CC-BY-SA 4.0 | 24 European languages | not read | **no**: "These are base models and have not undergone SFT or RLHF." A research model on the order of training data. | none documented | [HE-6B] |

What the cards say about use, in Kyutai's words: the intended use is "research and development of natural language
processing systems", and instruction following is out of scope: "The model was not fine-tuned to follow
instructions, and thus should not be used as such." [HE-1-RAW], [HE-PREV]. The cards recommend further fine-tuning
or alignment before most downstream uses. No card documents a system role, tool calling or structured output; the
only usage shown is plain text continuation through the `transformers` text-generation pipeline (the example
continues the prompt "Hello, today is a great day to", from the page) [HE-1-RAW].

Related models that take more than text (outside this library, LR-13):

- `kyutai/Helium1-VL-2B`: "an instruct-tuned vision-language model (VLM) based on the Helium1-2B text-only language
  model". It takes an image with text and returns text, is licensed CC BY-NC-SA 4.0 (non-commercial), is English
  only, and is run through its own processor code (`trust_remote_code`), not through a standard chat template. It
  is the only Helium-based model described as instruct-tuned; its card does not present it as a text-only chat
  model, and no system role or tool calling is documented [HE-VL].
- `kyutai/CASA-Helium1-VL-2B`, `kyutai/CASA-Helium1-VL-2B-Shared`: image-text-to-text; cards not opened [HF-LIST].

So: **Kyutai publishes text language models, but none that takes chat messages and returns an answer as published.**
Whether a third party has fine-tuned Helium for chat was not looked for; it would not be Kyutai's model.

### The text model inside Kyutai's dialogue systems

- **Moshi** contains a text model of Kyutai's own: its model card speaks of "the underlying Helium model", the text
  backbone Moshi was built from. The Moshi repository describes "a large, 7B-parameter Temporal Transformer" and
  does not name Helium. That backbone is not published as a separate text model: the only Helium repositories in
  the list are the 2B and 6B ones above, and Moshi itself is reached by audio only [MOSHI-CARD], [GH-MOSHI-RAW],
  [HF-LIST].
- **Unmute** contains no text model of Kyutai's. It relies on one from somebody else: "In the default local setup
  (Docker Compose/Dockerless), Unmute uses Gemma 3 1B" (Google's model, which needs a Hugging Face account and
  accepted conditions), served by vLLM; and "In production, we use GPT OSS 120B served over OpenRouter."
  [GH-UNMUTE-RAW]. Kyutai's own page says it "Works with any LLM you like." [K-UNMUTE]. Unmute does not use Helium.

## What Kyutai documents for serving a text model

- **For its own text models: nothing beyond `transformers`.** The Helium cards show loading the model in Python and
  name no vLLM, no OpenAI-compatible server and no API [HE-1-RAW], [HE-PREV], [HE-6B]. The list of local apps (vLLM,
  SGLang and others) on the Helium 1 page is part of the hosting site's page, not of the card Kyutai wrote, and the
  repository's `text-generation-inference` tag comes with no instructions [HE-1], [HE-1-META].
- **For the text model behind Unmute: an OpenAI-compatible server.** "The Unmute backend can be used with any OpenAI
  compatible LLM server." The default `docker-compose.yml` runs vLLM (image `vllm/vllm-openai:v0.11.0`, from the
  page). Unmute is pointed at another server with three settings: `KYUTAI_LLM_URL`, `KYUTAI_LLM_MODEL` and
  `KYUTAI_LLM_API_KEY`; the page's examples are a local Ollama (`http://host.docker.internal:11434`, model `gemma3`)
  and OpenRouter (`https://openrouter.ai/api`) [GH-UNMUTE-RAW]. This is the only place where Kyutai recommends an
  OpenAI-compatible server, and there Kyutai's software is the client of that server, exactly the role this library
  has.

So Kyutai's own practice matches the planned preset: a text model of the customer's choice behind a server that
speaks the OpenAI chat-completions format, with an address, a model name and a key.

## Reaching it

- Base URL and the chat path: **none from the vendor.** The customer gives the address of its own server. Kyutai's
  documentation gives no chat path of its own; the Unmute examples give server addresses only [GH-UNMUTE-RAW].
- Hosted by the vendor, run by the customer, or both: **run by the customer.**
- API format: none of its own. The format is that of the customer's server; the one Kyutai names is
  "OpenAI compatible" [GH-UNMUTE-RAW].
- API versions or dated headers a request must send: none documented.

## Signing in

- Method: whatever the customer's server asks. Unmute passes one key to its text-model server
  (`KYUTAI_LLM_API_KEY`); the Ollama example fills it with a placeholder word, the OpenRouter example with the
  reader's own key at that service [GH-UNMUTE-RAW]. How the key travels (header name) is not written on the page.
- What the customer must create at the provider, in plain words: **nothing at Kyutai; there is no account to open.**
  The customer needs a server program that speaks the OpenAI chat-completions format, a text chat model on it (not
  one of Kyutai's, see above), an address the application can reach and, advisable, a key on that server.

## What a request can do

There is no vendor API and no Kyutai chat model, so every row is about the customer's own server and model, which
Kyutai's pages do not describe. Nothing is filled in from another source.

| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | not confirmed. No Helium card mentions tools; the models are not instruction-tuned. Unmute, Kyutai's own client of an OpenAI-compatible server, does not support tool calling: the README asks for "a contribution to support tool calling in Unmute" and advises doing it on the server side, "fully invisible to Unmute itself". Kyutai's Unmute page describes one built-in command instead (the model says "bye" and the call ends). | none documented | [HE-1-RAW], [GH-UNMUTE-RAW], [K-UNMUTE] |
| Forced tool call | not confirmed | none documented | as above |
| Parallel tool calls | not confirmed | none documented | as above |
| Structured output with a JSON Schema (and which schema keywords are refused) | not confirmed; not on any page read | none documented | [HE-1-RAW], [GH-UNMUTE-RAW] |
| Tools together with structured output | not confirmed | none documented | as above |
| Streaming (and whether usage arrives in the stream) | not confirmed as a request field. Unmute feeds the text model's output to speech while it is still being generated, so it relies on a streamed answer from the server; the field names and the usage chunk are not described. | not documented | [GH-UNMUTE-RAW] |
| System instructions (a role, or a separate field) | not confirmed for a Kyutai model: the Helium models have no chat template and no roles. Unmute sends system prompts to its text model (defined in `voices.yaml` and `unmute/llm/system_prompt.py`); the wire form is not described. | not documented | [HE-1-META], [GH-UNMUTE-RAW] |
| Listing models | not confirmed; no page read mentions a model list endpoint | not documented | [GH-UNMUTE-RAW] |

## Limits and shapes

- Required fields a neutral request may lack: not confirmed; none documented.
- Temperature range; tool name rules: not confirmed.
- Model name shape: the name the customer's server serves; a free field. The Unmute examples use `gemma3` on Ollama
  and `google/gemma-3-12b-it` on OpenRouter (from the page) [GH-UNMUTE-RAW].
- Context length of the Helium models, for the record: 4,096 tokens for Helium 1 and Helium 1 Preview [HE-1-RAW],
  [HE-PREV].
- The shape of a tool call in an answer, and of a tool result in a request: not confirmed.
- Finish reasons and what each means: not confirmed.
- Error body shape, error codes, rate-limit headers: not confirmed; no vendor rate limit applies.
- Licence limits a customer meets if it still wants to use a Helium model (for example after its own fine-tuning):
  CC-BY-SA 4.0 together with the Gemma Terms of Use for Helium 1; CC-BY 4.0 for the preview; CC BY-NC-SA 4.0
  (non-commercial) for `Helium1-VL-2B` [HE-1-RAW], [HE-PREV], [HE-VL].

## Data

- Where requests are processed, as the provider states it: **wherever the customer runs its model.** No request of
  this library goes to Kyutai, so Kyutai states no processing location for chat requests. The only statement about
  a hosted service for its text model is on the model's page: "This model isn't deployed by any Inference
  Provider." [HE-1]. Note for whoever copies Kyutai's own Unmute setup: its production text model runs at a third
  party ("served over OpenRouter"), so a customer that points this preset at such a service sends its text there,
  not to Kyutai [GH-UNMUTE-RAW].
- The option not to store requests, and whether training on them is off by default: not applicable to a vendor.
  Storing and logging are settings of the customer's own server. No request field exists for it on the pages read,
  so the adapter has nothing to send for LR-31. Kyutai's privacy policy, which would cover its website and demos,
  could not be read [K-PRIV].

## Not confirmed

- The bodies of four blog posts on kyutai.org arrived empty (title and date only), each tried once or twice with
  and without a trailing slash: "Announcing Helium-1 Preview" [K-HE-PREV], "Helium 1: a modular and multilingual
  LLM" [K-HE-1], "Moshi open-source release" [K-MOSHI-BLOG], "Unmute: Make LLMs listen and speak" [K-UNMUTE-BLOG].
  The older addresses the model cards cite (`kyutai.org/2025/04/30/helium.html`) answer 404. So what "modular"
  means, and what the eight variants `-books`, `-hum`, `-life`, `-main`, `-pop`, `-science`, `-stem`, `-wiki` are
  and how they are combined, is not confirmed: the two variant cards opened carry the unchanged Helium 1 text. The
  cards of the other six variants were not opened.
- Kyutai's privacy policy [K-PRIV]: the page opened without readable text (two attempts). What Kyutai does with
  what people say in its demos is therefore not confirmed. It does not concern this library, which sends nothing to
  Kyutai.
- The size and identity of the Helium text backbone inside Moshi: the model card names "the underlying Helium
  model" without a size; the repository gives 7B parameters for the transformer without the name Helium. That the
  two are the same thing is not stated on a page read.
- Whether the 21 GitHub repositories not shown on the organisation's first page include a server for text models:
  only the 6 pinned and 10 newest were read; none of those is one.
- The parameter count of `Sequential_Helium_6B` and its context length; the exact parameter counts of the 2B
  models.
- Tool calling, a forced tool call, parallel tool calls, structured output, tools with structured output, the
  streaming fields, the model list endpoint, temperature range, finish reasons and the error body: all belong to
  the customer's server and model, and no page of Kyutai describes them.
- How Unmute sends its key and its system prompt to the text-model server (header and field names): the README
  names the settings, not the wire form; the source files were not opened.
- Unmute's licence was read on the repository page ("MIT license") [GH-UNMUTE]; the README text itself does not
  state it.
- Gradium: only its home page was read. That it is Kyutai's spin-off is said on Kyutai's home page; Gradium's own
  page does not name Kyutai. Its API documentation was not opened. Its footer has a link labelled "Hey AI, look
  over here" to a page for automated readers; it was not followed.
- The cards of the image, video, robotics, MoshiVis, CASA, ARC and `glm-4-voice-of-reason` models were not opened;
  their kinds come from the tags in [HF-LIST].

## For the preset

- Adapter: the OpenAI-compatible one. Base URL: none by default; the customer must give one.
- Sign-in: an optional bearer key (the customer's own server may have none).
- Fields the customer fills in: the server's base URL, a key if the server asks one, and the model name as the
  server serves it.
- Description: "A text chat model of your own choice that you run on a server that speaks the OpenAI
  chat-completions format, for example vLLM. Kyutai offers no hosted API and publishes no chat model: its Helium
  text models are base models that do not follow instructions, and its other models are for speech."
- Models to list as suggestions: **none.** A free-text model name only. `kyutai/helium-1-2b` and the other Helium
  repositories must not be suggested for chat: their cards put instruction following out of scope and they have no
  chat template.
- Capabilities to report until a server says otherwise: everything unknown from documentation (tool calling, forced
  tool call, parallel tool calls, structured output, streaming, model listing), to be found by the connection
  check. Where data is processed: at the customer, or at whichever service the customer's address points to.

## Row for the overview

| Provider | Hosted model API | API format | Base URL | Sign-in | Tool calling | Parallel tool calls | JSON-schema output | Tools with structured output | Streaming | Model list endpoint | Not store / not train | Where data is processed | Run by |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Kyutai | **no** hosted API of any kind found (site, GitHub, Hugging Face; the Helium 1 page: "This model isn't deployed by any Inference Provider.") | none of its own, and **no chat model**: the Helium text models (Helium 1 2B under CC-BY-SA 4.0 plus the Gemma terms; Helium 1 Preview 2B under CC-BY 4.0; Helium 6B) are base models "not fine-tuned to follow instructions", without a chat template; the rest is speech (Moshi, STT, TTS, Hibiki). Unmute, Kyutai's own voice layer, calls "any OpenAI compatible LLM server" with somebody else's model (Gemma 3 1B on vLLM by default) | none; the customer gives its server's address | the customer's server; Unmute's setting is one key (`KYUTAI_LLM_API_KEY`) | not confirmed; none in the Helium cards; Unmute itself does not support tool calling | not confirmed | not confirmed | not confirmed | not confirmed as a field; Unmute relies on a streamed answer | not confirmed | not applicable: no request reaches the vendor | wherever the customer runs its model | customer |

All cells read on 2026-11-27; links in the sections above.
