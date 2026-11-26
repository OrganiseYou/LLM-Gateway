# Decisions

One row per decision. Status: `decided` (by Joris, with his words), `working` (a proposal the build uses until Joris
says otherwise), `proposed` (made by the builder during a task), `open` (blocks something; a person must choose).
New rows go at the bottom with the next number. Never rewrite a `decided` row.

| # | Decision | Status |
|---|---|---|
| G1 | The library is a Composer package in its own repository. Joris, 2026-11-26: "I want to make a separeted connector in its own repository"; he chose "Composer package". | decided |
| G2 | One interface for every provider. Joris, 2026-11-26: "I want a A Unified, Provider-Agnostic Contract"; asked whether "contract" is the package's one PHP interface: "Yes, the interface". | decided |
| G3 | Providers: OpenAI Platform (ChatGPT); Claude (Anthropic); Gemini (Google Cloud / Vertex AI); Microsoft Copilot / Azure OpenAI; Cohere; Aleph Alpha (PhariaAI); Silo AI (AMD); LightOn (Paradigm); Pleias; Mistral; Kyutai; STACKIT (his list, 2026-11-26); Grok ("Add grok"); Kimi ("And kimi"); LM Studio (his first message). All fifteen are in scope; none is dropped or postponed without his word. | decided |
| G4 | The builder chat of the application uses the same calls as every other caller; the library has no chat-only operation and no provider-side connector. From his choice "Builder chat too". | decided |
| G5 | Package `organiseyou/llm-gateway`, namespace `OrganiseYou\LlmGateway`. | working (Q1) |
| G6 | Public, MIT licence. | working (Q2) |
| G7 | PHP 8.4 and up. | working (Q3) |
| G8 | The tool loop lives in the library; the caller supplies the executor and the limits. | working (Q4) |
| G9 | A provider with no hosted chat API gets a named preset that asks for the address of the customer's own OpenAI-compatible server and the model it runs there. The application's brief (2026-11-26) found no hosted chat API for Silo AI, Pleias and Kyutai; S2 checks it again. "Microsoft Copilot / Azure OpenAI" connects Azure OpenAI: the brief found that Copilot is not a model API. | working (Q5) |
| G10 | The first version of the contract is text chat with tools and structured output. No images, audio, embeddings or speech; message content is a list of parts so they can be added later. Kyutai is on the list for its text model (the brief found Helium 1), under G9. | working (Q6) |
| G11 | Where the package is published. | open (Q7); blocks only LG-28 |
| G12 | HTTP goes through the library's own `Transport` interface (send, and send as a stream). The library ships a bridge to any PSR-18 client; it requires no HTTP client itself. | working |
| G13 | Structured answers are checked with `opis/json-schema`, as the OrganiseYou application does today. | working |
| G14 | Tests: Pest 4, PHPStan level 8, Pint. | working |
| G15 | "Other, OpenAI-compatible" exists as a sixteenth preset: a base URL, an optional key, a model name. | working |
| G16 | A forced tool call is its own capability (native, emulated, none). The application gets every structured answer as a forced tool call today, and the brief found a provider whose newest models refuse one. The loop asks for the final structured answer the way the capabilities allow: native structured output, a forced tool call, or the emulation of LG-08. | working |
| G17 | A short-lived sign-in token (Google Cloud, Microsoft Entra) comes from a supplier the caller gives. The caller keeps it between calls; the library keeps nothing. | working |
| G18 | A model name may hold `/`, `:` and `.` (the brief found such names at three providers). The contract checks only that it is not empty and has no control characters. | working |
