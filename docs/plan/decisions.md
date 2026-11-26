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
| G19 | `psr/http-message` is required as `^1.1 \|\| ^2.0`, not `^2.0` only. Why: the library only calls PSR-7 interfaces and implements none, 2.0 differs from 1.1 by return types on the interfaces (release notes read 2026-11-26), and `psr/http-factory` 1.1 itself allows `^1.0 \|\| ^2.0`; a host whose other packages hold 1.x can then install the library. LG-05 changes `composer.json`. | proposed |
| G20 | Credentials are bound to the gateway object when it is built, not passed with each call. Why: LR-25 allows "per call or per client"; per client keeps secrets out of every request value and out of the five operation signatures. | proposed |
| G21 | A credential object is only the secret and how it is presented: none, bearer key, header key, bearer token from a supplier. A resource address is the gateway's base URL, a deployment name is the request's model (as LG-17 says), and a project, region or API version are named fields of the preset. Why: those words mean something at one provider only, so they stay out of the contract; LR-24 is still met (table in `docs/design/contract.md` 11.2). LG-06 builds four classes instead of the six kinds its scope lists. | proposed |
| G22 | The neutral temperature is 0.0 to 1.0 and is sent as the same number; no scaling in the contract. Why: it is the range LR-08 names as common, and a number that means the same everywhere surprises no caller. | proposed |
| G23 | A JSON Schema in the contract (tool arguments, response format) must have root type `object`. Why: tool arguments are an object, and G16 lets a structured answer travel as a forced tool call, so any other root could not be carried the same way everywhere. | proposed |
| G24 | System messages come first in a request; one after a message of another role is refused by the request. Why: every format can carry leading instructions; a system message in the middle cannot be translated the same way everywhere. | proposed |
| G25 | `stream()` never throws a gateway error: every failure, also one before the first event and after the retries, is the event "failed". Why: one code path for the caller (LR-12); LR-35 is unchanged. | proposed |
| G26 | The timeout is required on every call and is one deadline for the whole call: all attempts and all waits. A stream is covered until its end. Why: LR-36 says "per call" and the library may have no default of its own. | proposed |
| G27 | `capabilities()` sends no request; it answers from preset data and the adapter. The context length is null unless known that way; the model list gives it per model. Why: a caller asks capabilities often and must not pay a request or handle a failure for it. | proposed |
| G28 | The "provider" in an attempt report (LR-30) is the id of the preset the caller built the gateway from. It appears in attempt reports only: not in an answer, an error, a stream event or a connection report. Why: LR-30 asks for it, LR-04 and the project guide keep provider names out of what a call returns; the caller chose the preset, so it learns nothing new. | proposed |
| G29 | The message of the gateway exception is built by the library from the reason, the HTTP status, the provider code and a detail sentence the library wrote. No provider text, no chained previous exception. The provider's body reaches the host through the attempt observer only. Why: a provider's error text or a client's exception can repeat a key (LR-26). | proposed |
| G30 | A tool-result message carries the tool's name next to the call id. Why: an adapter whose format matches results by name need not search the history, and a caller always has both. | proposed |
| G31 | When the host's transport refuses a request by its own rule, the error reason is `not_configured` and nothing is retried. Why: LR-32 has no ninth reason, and an address the host does not allow is a matter of configuration. | proposed |
| G32 | The retry policy is required and has no default; the library adds no jitter. The caller gives the list of waits. Why: LR-34 gives the policy to the application; a fixed list keeps tests exact. | proposed |
