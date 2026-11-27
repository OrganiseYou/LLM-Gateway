# Briefing: the Laravel package on top of the LLM gateway

Status: draft for Joris, 2026-11-27. A briefing, not a design: what the package is for, what it holds, what it must
never do, and what Joris still has to choose. Nothing of it is built. The names in it (`organiseyou/llm-gateway-laravel`
and the class names) are proposals.

## 1. Why a second package

`organiseyou/llm-gateway` is a plain PHP library: one interface to many LLM providers, no framework, no state, no
network connection of its own. Joris decided on 2026-11-27 (decision G140) that the library **ships no provider
presets**. A preset is the data that says how one named provider is reached: which adapter, which address, which
sign-in, which options. In the library those exist only under `tests/`, as proof that each provider's settings work
with the adapters.

So somebody else has to own the list of providers. That is this package. It is the place where a Laravel application
gets:

1. the catalogue of provider presets (Mistral, Claude, and the others);
2. a gateway built the Laravel way: from config, through Laravel's HTTP client, with the application's own rules on
   every outgoing request;
3. the small tools around it: a fake for tests, a connection check from the command line, events for every attempt.

The library stays the only place that knows a provider's wire format. This package knows provider **names and
settings**; it never builds or reads a provider's JSON.

## 2. What the library gives the package

Read the library's `README.md` first. In short:

| In the library | What it is |
|---|---|
| `Contract\Gateway` | The one interface: `complete()`, `stream()`, `models()`, `capabilities()`, `check()` |
| `Contract\Request`, `Answer`, `Message`, `ToolDefinition`, … | The neutral value objects; no provider name in any of them |
| `Preset\Preset`, `PresetField`, `ModelOverride`, `BaseUrlRule` | The shape of a preset. The library defines the shape; this package fills it in |
| `GatewayFactory::create($preset, $credentials, $transport, $config)` | Turns a preset into a gateway. Sends nothing |
| `GatewayFactory::resolveBaseUrl($preset, $config)` | The address every request will start with, so the application can check it before the first call |
| `GatewayConfig` | Retry policy, the customer's base URL and settings, the attempt observer, the clock |
| `Contract\Credentials\BearerKey`, `HeaderKey`, `BearerToken` (with a `TokenSupplier`), `NoCredentials` | The four ways to sign in |
| `Transport\Transport` and `Transport\Psr18Transport` | Every request goes through a transport the caller supplies |
| `Transport\AttemptObserver` | Told before and after every HTTP attempt |
| `Testing\FakeGateway`, `FakeTransport`, `ContractTestCase` | The fake for tests and the shared test set |
| `tests/Presets/*` | Worked preset definitions for the tested providers. Not shipped: they are in `tests/` |

Adapters built when this was written: chat completions (OpenAI-compatible servers, with the Mistral dialect),
Messages (Claude), generate-content (Gemini and Vertex AI). Still to come in the library: Chat v2 (Cohere), Responses
(OpenAI Platform, Azure OpenAI, Grok), the tool loop, the connection check, the model lists. The package can start on
Mistral and Claude and add entries as adapters land.

## 3. What the package holds

### 3.1 The preset catalogue

- One class per entry, each returning one `Preset`: `mistral`, `mistral-eu`, `claude` first, then the other entries
  of the library's `docs/design/adapters.md` section 9 as their adapters are built.
- The starting point for each entry is its file in the library's `tests/Presets/`. Copy it one to one. The facts in
  it (address, sign-in header, options, where data is processed, the storage note) come from the provider's
  documentation as recorded in the library's `docs/providers/<provider>.md`, with the link and the date read. Never
  from memory.
- A catalogue class that lists the entries and finds one by id. An application can add its own entries and switch
  shipped ones off, in config.
- Next to the named entries, the entry "other, OpenAI-compatible": a base URL, an optional key and a model name
  (requirement LR-21).
- Per entry, in plain words for the customer: what they need from their provider to connect (which key, which
  address, which model name). The library's requirement LR-45 asked the library's README for this; with the presets
  gone from the library it belongs here.

A provider's name may appear in a catalogue entry and in the documentation. Nowhere else in the package.

### 3.2 The wiring

- A service provider and a published config file `llm-gateway.php`: the retry policy (how many retries, the waits,
  the longest wait accepted from a provider), default timeouts, which catalogue entries are on.
- A factory the application calls with an entry id, credentials, an optional base URL and the customer's settings,
  and gets a `Gateway` back. The application decides which provider an organisation uses and reads the key from its
  vault; the package takes both as arguments. It never reads a key from `.env`, a file or the database by itself.
- A transport on Laravel's HTTP client (it is PSR-18 underneath, so `Psr18Transport` fits) that:
  - never follows a redirect;
  - never changes the host;
  - passes every status back as a response, also 4xx and 5xx;
  - runs the application's outbound rule (today `OutboundUrlGuard`) before a request leaves. The rule is the
    application's; the package only gives it the place to stand.
- An attempt observer that turns the library's `AttemptStarted` and `AttemptFinished` into Laravel events. The
  application listens and decides what to store. Secret headers arrive masked from the library; the package must not
  unmask them.
- The values the library marks as secret (`secrets()` on the credentials) handed to the application's redactor
  before the first request.

### 3.3 The tools

- `Gateway` bound to `Testing\FakeGateway` with one call, so the application's tests need no adapter and no network.
- An artisan command that runs the library's connection check for one entry, model and key, and prints yes, no or
  not supported per point. It sends only the library's fixed test text.
- Validation rules for the fields a customer fills in, built from the entry's `PresetField` list, so the settings
  form in the application and the library agree on what a valid value is.
- An architecture test the application can run: it fails when a provider's name, class or wire format is used outside
  the two packages (requirement LR-44).

## 4. What the package must never do

These are the library's rules; the package must not open a door the library closed.

1. **No tenant, user or organisation.** Which organisation uses which provider, and who may change it, stays in the
   application. The package has no migration and no model.
2. **No secret where it can be read.** No key in config files in the repository, in a log line, an event, an
   exception message, a queue payload or a cache entry.
3. **No connection of its own.** Everything goes through the transport, so the outbound rule, tracing and redaction
   apply to every provider and every attempt.
4. **No redirect followed, no host changed.** A base URL the customer gave is used exactly as the application
   approved it.
5. **No state between two calls**, other than what Laravel's container holds for one request. A short-lived token
   (Google, Microsoft Entra) is kept by the application's token supplier, not by the package.
6. **No tool is run.** Tool calls go to the executor the application supplies.
7. **No provider's JSON.** If an entry needs behaviour the adapters do not have, that is a change in the library, not
   a workaround here.
8. **No limits per organisation or per day, no pricing counters, no prompts.** Those are the application's.
9. **Tests never reach a provider.** The fake transport and synthetic answers only; no real key, account or customer
   text in the repository. A live check is run by a person, by hand.

## 5. How the two packages move together

- The package requires `organiseyou/llm-gateway` with a caret range. A new adapter in the library is a minor version
  there; a new entry here is a minor version here.
- An entry is only as good as its last comparison with the provider's documentation. Each entry records the date its
  facts were read. When the library's `docs/providers/<provider>.md` changes, the entry is checked again.
- Each entry has a test that builds a gateway from it with the fake transport and gets an answer, and a test that it
  never sends to another entry's host (the two Mistral entries, the two Kimi entries).
- The library's tests keep their own copy of the entries under `tests/Presets/`. Two copies can drift apart. Either
  the package's tests compare each entry with the library's copy, or one of the two is generated from the other.
  This is question 4 below.

## 6. What changes in the library because of this

For whoever reads the library's plan next to this briefing:

- Slice S4 (tasks LG-18 to LG-21 and LG-33) builds its entries under `tests/Presets/`, not `src/Preset/Catalogue/`.
  The acceptance per entry stays the same.
- These requirements of the library speak of presets as part of the library and now point here instead: LR-18,
  LR-19, LR-21, LR-22, LR-31 ("the preset says so"), LR-39 ("a new adapter or preset is a minor version") and LR-45
  ("per preset, what the customer needs"). "Done when" point 2 ("each of the fifteen presets passes the shared test
  set") is met by the library's test entries. The change to `docs/requirements.md` is proposed in decision G140; the
  file itself is Joris's to edit.
- LR-38 already said that Laravel wiring, if wanted, is a thin optional layer. This package is that layer, plus the
  catalogue.

## 7. Questions for Joris

| # | Question | Options | Proposal |
|---|---|---|---|
| 1 | The package name and where it lives. | `organiseyou/llm-gateway-laravel` in its own repository; a folder in the OrganiseYou application | Its own repository, same vendor, MIT as the library |
| 2 | Does the catalogue hold all entries of the library's section 9, or only the ones OrganiseYou offers its customers? | All; only the offered ones, the rest switched off in config | All of them, with a config list of which are on |
| 3 | Who keeps the key and the choice per organisation? | The application (as the library's requirements say); the package gets a small settings store | The application. The package stays without a database |
| 4 | Two copies of every entry: the library's tests and this package. | The package compares with the library's copy in its tests; the library drops its copy and tests against this package; a third small data-only package both use | The package compares in its tests: no new dependency for the library |
| 5 | Where the attempt traces go. | The package only fires events and the application stores them; the package ships a listener that stores them | Events only. The application stores them where it stores tracking data today |
| 6 | The lowest Laravel version. | The version the OrganiseYou application runs; one lower as well | The application's version only: one version to test |
| 7 | Does the package also wrap the tool loop (progress events for the builder chat)? | Yes, once the library's loop is built; no, the application calls the loop itself | Yes, as a thin pass-through of the loop's events to Laravel events |

## 8. Done when

1. A Laravel application installs the package, picks `mistral` or `claude` with a key from its own vault, and gets an
   answer, a stream and a tool round through `Gateway`, without naming a provider anywhere outside config.
2. Every catalogue entry builds, matches the capabilities the library's design states for it, and passes its tests on
   the fake transport.
3. The application's tests run on the fake with no network.
4. A person has run the connection check by hand against every provider Joris has an account for, and the package's
   README says which were and which were not.
