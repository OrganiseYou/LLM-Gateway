# LLM provider library: requirements

Status: draft for Joris, 2026-11-26. Requirements only: what the Composer library must do and must not do. The design
(class names, HTTP client, file layout) is the software-architect's. The feature brief is the brief in the OrganiseYou application (`docs/plan/llm-providers/README.md` there; its provider table is copied to `docs/providers/overview.md` here); its
compatibility table holds the facts per provider, read from their documentation. Nothing here is built yet and the
repository does not exist yet.

Each requirement has an id (`LR-nn`) and is written so a test or a review can say yes or no.

## 1. Where this comes from

Joris, 2026-11-26, word for word:

1. "Instead of using only Mistral, i want organisations giving the ability to change to your own LLM provider (Chatgtp,
   Grok, LMStudio, etc)"
2. "I want to make a separeted connector in its own repository". Asked what the repository holds, he chose "Composer
   package". Asked how much moves in the first version, he chose "Builder chat too".
3. "I want that the connector is compatible with the following: OpenAI Platform (ChatGPT); Claude (Anthropic); Gemini
   (Google Cloud / Vertex AI); Microsoft Copilot / Azure OpenAI; Cohere; Aleph Alpha (PhariaAI); Silo AI (AMD); LightOn
   (Paradigm); Pleias; Mistral; Kyutai; STACKIT"
4. "I want a A Unified, Provider-Agnostic Contract". Asked "Is "contract" the package's one PHP interface, as I read
   it?", he answered: "Yes, the interface".

5. "Add grok". Grok (xAI) is on the list by his explicit word, next to the twelve of point 3; LM Studio is on it from
   point 1.
6. "And kimi". Kimi (Moonshot AI) is on the list by his explicit word.

Everything below that is not in those six points is a proposal, and section 12 lists what he still has to choose.

## 2. What the library is

One PHP library that talks to LLM providers. The application gives it a provider, a model, credentials and a request in
one neutral shape; the library translates that to the provider's own API, sends it, and gives the answer back in the
same neutral shape.

| In the library | Stays in the application |
|---|---|
| The interface and its value objects | Which provider an organisation uses, and who may change it |
| One adapter per API format | Keys in the vault, and reading them |
| The list of provider presets | Limits per organisation and per day, pricing counters |
| Translating errors, retrying, streaming | The audit log and the stored traces |
| Checking a structured answer against its schema | The outbound rule (`OutboundUrlGuard`) and secret redaction |
| A fake for tests and a test set every adapter passes | Running tools, permissions, prompts, tenancy |

- **LR-01** The library holds no tenant, user, organisation or database notion. It stores nothing between two calls.
- **LR-02** The library has no knowledge of OrganiseYou's metadata, MCP tools or prompts.

## 3. The contract (the interface)

- **LR-03** There is one interface. Every caller in the application uses only that interface and its value objects.
- **LR-04** No provider's name, id, class or wire format appears in the interface, in a value object, or in anything a
  call returns. A provider's name exists only in its preset and in its adapter.
- **LR-05** The interface offers these operations, each with the same shape for every provider:
  1. one completion (messages in, answer out);
  2. the same completion as a stream of events;
  3. the list of models the credentials may use;
  4. the capabilities of a provider and model (LR-10);
  5. a connection check (LR-27).
- **LR-06** A request carries: messages with the roles system, user, assistant and tool; the model; tool definitions
  (name, description, JSON Schema of the arguments); tool choice; a response format as JSON Schema; maximum output
  tokens; temperature; a timeout.
- **LR-07** An answer carries: the text; the decoded structured data and the reasons it is unusable (not JSON, schema
  mismatch as "path: message", cut off at the token limit); the tool calls with decoded arguments, or an arguments
  error per call; a neutral finish reason; the model that answered; input and output tokens; latency; the number of
  attempts; a trace id. This is what `AiResponse` and `AiToolCall` give today and callers rely on it.
- **LR-08** Value ranges are neutral. Where providers differ (temperature 0 to 1 or 0 to 2, tool name length, maximum
  tokens), the adapter maps or refuses with a typed error; the neutral request does not take one provider's range.
- **LR-09** A builder chat turn uses the same completion and stream operations as every other caller. There is no
  second, chat-only operation and no provider-side connector, conversation id or stored credential in the contract.
  (Today's `converse()`, `AiConversation::connectorId` and `toolToken` go.)
- **LR-10** Capabilities answer, per provider and model: tool calling; a forced tool call (native, emulated, or none:
  every structured answer in the application comes back as a forced tool call today, and the brief found a provider
  whose newest models refuse it); parallel tool calls; structured output (native, emulated, or none); tools together with structured output; streaming; model listing; the context length when the
  provider tells it. A caller asks capabilities; it never compares a provider's name.
- **LR-11** What a provider lacks is either emulated inside its adapter and reported as "emulated", or reported as
  absent. A caller never works around a provider. Example: today's rule in `AiRequest` that tools and a response format
  are never combined is a Mistral limit; it becomes a capability, not a rule of the request.
- **LR-12** Streaming events are neutral: text delta, tool call started, tool call complete, usage, finished, failed.
  For a provider or model without streaming, the adapter delivers the whole answer as one text event followed by
  finished, so a caller has one code path.
- **LR-13** Images, audio, embeddings and speech are not in the first version of the contract. The contract is built so
  a message can later hold parts other than text without breaking existing callers. (Proposal; question Q6.)

## 4. Tools and the tool loop

- **LR-14** The library never runs a tool. It hands tool calls to the caller and takes tool results back as messages.
- **LR-15** The library offers one tool loop that both the AI step and the builder chat use: it calls the model, hands
  each tool call to an executor the application supplies, feeds the results back, and stops at an answer or at a limit.
  The limits (rounds, tool calls) are arguments; the library has no defaults of its own for them. (Proposal; Q4.)
- **LR-16** The loop reports every step as an event (model round started, tool call, tool result, text), so the builder
  chat panel can show progress as it does today.
- **LR-17** A tool name the model garbles, or arguments it cannot be read, come back to the model as a readable error
  inside the loop, not as an exception. (Today's `answerUnknownCalls` and `argumentsError` behaviour.)

## 5. Providers

- **LR-18** These fifteen are supported, each as a named preset: OpenAI Platform (ChatGPT), Claude (Anthropic), Gemini
  (Google Cloud / Vertex AI), Microsoft Copilot / Azure OpenAI, Cohere, Aleph Alpha (PhariaAI), Silo AI (AMD), LightOn
  (Paradigm), Pleias, Mistral, Kyutai, STACKIT, Grok (xAI), Kimi (Moonshot AI), LM Studio.
- **LR-19** A preset names: the adapter it uses, the default base URL or that the customer must give one, the sign-in
  method, the fields the customer fills in, and where the provider processes data as its documentation states it.
- **LR-20** There is one adapter per API format, not per provider. Several presets may share an adapter. How many
  formats the fifteen need is read from the brief's compatibility table, not assumed.
- **LR-21** Next to the fifteen there is a preset "other, OpenAI-compatible": a base URL, an optional key and a model
  name, for a provider that is not on the list. (Proposal, from the "etc" in point 1.)
- **LR-22** A provider that has no hosted chat API (the brief's table says which) is not dropped. Its preset says how it
  is reached, for example the customer runs the model behind a compatible server and gives its address. What
  "compatible" means for each such provider is Joris's choice (Q5).
- **LR-23** Adding a provider that speaks an existing format is a new preset and no new code. Adding a format is one new
  adapter that passes the shared test set (LR-40), with no change to the interface.

## 6. Signing in

- **LR-24** The library supports the sign-in methods the fifteen need, as the brief's table found them: a key as bearer
  token; a key in a provider-specific header; a key with a resource address and a deployment name, with an API version
  only where the provider's API still asks for one (Azure); a Google Cloud project and region with a short-lived access
  token (Vertex AI); a Microsoft Entra token; no key at all (a server the customer runs; whether the application allows
  that is the brief's question LP-11, not the library's).
- **LR-25** The application hands credentials to the library per call or per client. The library does not read the
  environment, a file or a vault, and keeps no credential longer than the object that holds it. A sign-in that swaps a
  secret for a short-lived token (Google, Microsoft Entra) gets that token from a supplier the application gives; the
  application keeps the token between calls, the library does not (LR-01 stays true).
- **LR-26** A credential never appears in an exception message, an event, a log line or a string form of an object.
  Parameters that hold one are marked sensitive. The library tells the host which values are secret so the host's
  redactor can register them before the first request.
- **LR-27** The connection check answers, for a provider, model and credentials: does the sign-in work; does the model
  exist; does a tool call come back; does a structured answer pass its schema; does a stream arrive. Each as yes, no or
  not supported, with the provider's own error code when there is one. It sends only fixed test text, never customer
  data.

## 7. Sending requests

- **LR-28** The library opens no network connection by itself. Every request goes through a transport the application
  supplies, so the outbound rule, tracing and redaction apply to every provider and every attempt.
- **LR-29** The library never follows a redirect by itself and never changes the host of a request. A customer-given
  base URL is used exactly as the application approved it.
- **LR-30** Before and after every HTTP attempt the library reports to the host: provider, operation, attempt number,
  the request and the answer as data, outcome and duration. The host decides what is stored (D67: one trace per
  attempt). Reporting must never change the result of the call.
- **LR-31** Where a provider offers an option not to store the conversation, the adapter sends it, and the preset says
  so. The library never switches on a provider's training or data-sharing option. (Continues D62.)

## 8. Errors, retries and time

- **LR-32** Every failure is one typed error with a machine-readable reason: not configured, credentials rejected, rate
  limited (with the wait in seconds when known), unavailable, rejected (such as an unknown model or an invalid schema),
  provider error (an answer without content, or an error inside a 200), timeout, not supported (a capability the
  provider lacks). It also carries the HTTP status, the provider's own error code when it is a plain word, and the
  trace id. The same reasons for every provider.
- **LR-33** An unusable structured answer is not an error: it comes back with its reasons (LR-07), so the caller's
  repair loop can feed them back.
- **LR-34** The retry policy is given by the application: how many retries, the waits, and the longest wait it accepts
  from the provider. The library retries only what may succeed later (rate limit, server error, lost connection) and
  never a request the provider rejected.
- **LR-35** A streamed call that fails after the first event is not retried by the library; it reports failed with what
  arrived.
- **LR-36** The timeout is per call, given by the application. The library has no limit of its own on calls per minute
  or per day: those stay in the application.

## 9. Package facts

- **LR-37** A Composer library in its own repository, in the `organiseyou` vendor, with `declare(strict_types=1)`,
  readonly value objects, PSR-4 autoloading and a `CHANGELOG.md`. Name, namespace, licence and lowest PHP version: Q1
  to Q3.
- **LR-38** The core (interface, value objects, adapters, loop, fake) depends on no framework. If Laravel wiring is
  wanted, it is a thin optional layer; this application can also wire the library in its own service provider.
- **LR-39** Versions follow semantic versioning. A change to the interface or a value object that breaks a caller is a
  new major version. Releasing is: update the changelog, tag the version; the application then raises the version in
  its `composer.json`. A new adapter or preset is a minor version.

## 10. Tests and quality

- **LR-40** One shared test set describes the contract. Every adapter must pass it: plain answer, tool call, garbled
  tool call, structured answer valid and invalid, stream, each error reason, retry and wait, timeout, no credential in
  any output.
- **LR-41** Tests never reach a real provider. They use synthetic recorded answers; no real key, account, customer text
  or shop name is in the repository. A secret scan runs on every push, as in this repository.
- **LR-42** The library ships a fake that implements the interface, with scripted answers, tool calls, streams and
  failures, so the application's tests need no adapter. (Today's `FakeAiGateway`.)
- **LR-43** A separate live check exists that a person runs by hand with their own keys, one provider at a time. It is
  never part of the automatic tests.
- **LR-44** The application gets an architecture test that fails when a provider's name, class or wire format is used
  outside the library (as `tests/Architecture/ColumnTypeSwitchTest.php` does for column types).
- **LR-45** The README explains in plain words: how to call the library, how to add a preset, how to add an adapter,
  and, per preset, what the customer needs from their provider to connect.

## 11. Done when

1. The application's AI agent step, AI decision, loop AI calls and builder chat all run through the interface, with
   Mistral on the platform key as it is today, and no test in the application names a provider.
2. Each of the fifteen presets passes the shared test set through its adapter.
3. The live check has been run by hand against every provider Joris has an account for, and the README states which
   were and which were not.
4. The architecture test of LR-44 is green.

## 12. Questions for Joris

| # | Question | Options | Proposal |
|---|---|---|---|
| Q1 | The package name. | `organiseyou/llm-gateway`; `organiseyou/ai-providers`; another name | `organiseyou/llm-gateway`, namespace `OrganiseYou\LlmGateway` |
| Q2 | Public or private. | Public with the MIT licence, as `organiseyou/mcp-server`; private | Public, MIT: nothing in it is specific to a customer |
| Q3 | Lowest PHP version. | 8.4, as this application; 8.2, as `organiseyou/mcp-server` | 8.4: one version to test |
| Q4 | Where the tool loop lives (LR-15). | In the library, shared by the AI step and the builder chat; in the application | In the library |
| Q5 | Providers without a hosted chat API (LR-22). The brief's table found none for Silo AI, Pleias and Kyutai, and found that Microsoft Copilot is not a model API. | A named preset each for the model the customer runs on its own OpenAI-compatible server, and Azure OpenAI under the label "Microsoft Copilot / Azure OpenAI"; or leave them out | The named presets (the product-owner's recommendation too) |
| Q6 | Images, audio, embeddings and speech (LR-13). | Not in the first version; in the first version | Not in the first version. Kyutai is not speech only: the brief found a text model (Helium 1), so it fits text chat under Q5 |
| Q7 | Where the released package is published, so a deploy does not depend on GitHub. | As the three existing `organiseyou` packages are installed today; an own package store | As today, unless a deploy has failed on it |

The questions about the product (fall back to Mistral or not, the outbound rule and local servers, limits for calls on
an own key, providers outside the EU) are in the brief, not here: they are the application's, not the library's.
