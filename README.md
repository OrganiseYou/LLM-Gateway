# LLM gateway

`organiseyou/llm-gateway` is a PHP 8.4 library that talks to LLM providers through one interface. You give it a
provider preset, a model, credentials and a request in one neutral shape. It translates the request to the provider's
own API, sends it through a transport you supply (any PSR-18 client), and returns the answer in the same neutral
shape. The library opens no connection itself, keeps no state between calls and never runs a tool.

## Status

Under construction. There is no release yet, and the package is not published.

What works today:

- the interface `Contract\Gateway` and its value objects (request, message, tool, answer, stream events, errors,
  capabilities);
- the transport interface and the bridge to any PSR-18 client;
- the four kinds of credentials;
- the adapter base `Adapter\Shared\AbstractAdapter`, which you extend to add a format of your own;
- the check of a structured answer against its JSON Schema;
- `Testing\FakeGateway`, a gateway for your tests;
- `Testing\ContractTestCase`, the shared test set every adapter must pass.

What is coming, and is **not built yet**:

- the shipped adapters (one per API format) and the shipped presets (one per provider; the list is in
  [docs/requirements.md](docs/requirements.md), LR-18 and LR-21);
- the preset catalogue `Preset\Presets`, to list the providers the library knows;
- per preset, what a customer needs from their provider to connect;
- the tool loop (`src/Loop`): today you write the loop yourself, as shown under [Tools](#tools);
- the connection check: `Gateway::check()` exists, but a gateway built on the adapter base answers `not_supported`
  for now;
- model lists per shipped adapter: `Gateway::models()` exists and works for an adapter you write yourself.

So today you can use the library in two ways: with the fake gateway, and with an adapter you write yourself. Every
code sample below says which kind it is. "Runs today" uses only what is in `src/` now. "Not built yet" shows the
intended shape in a few lines; it may still change.

In a sample, a comment `// prints …` shows what the statement before it prints. The tests of this repository
execute every sample that runs today and compare what it prints (`tests/Readme`).

## Install

The library needs PHP 8.4 and a PSR-18 HTTP client of your choice, with PSR-17 factories. It requires no HTTP client
itself.

Once the package is published:

```bash
composer require organiseyou/llm-gateway
```

It is not published yet. Until then, add this repository to your `composer.json` as a `path` or `vcs` repository and
require it from there.

If your project has no PSR-18 client yet, install one. Guzzle is one example:

```bash
composer require guzzlehttp/guzzle
```

## How to use it

Every sample in this chapter runs today. The samples use `Testing\FakeGateway`, so you can run them without a
provider and without a key. The fake implements the same interface as a real gateway: code written against it works
unchanged against a provider. [A real provider](#a-real-provider) shows what changes: only the way the gateway is
built.

### A request and an answer

Runs today.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Testing\FakeGateway;

$gateway = (new FakeGateway())->willAnswerText('Hello, Utrecht.');

$request = new Request(
    model: 'example-model',
    messages: [
        Message::system('You are a friendly assistant.'),
        Message::user('Say hello to Utrecht.'),
    ],
    timeoutSeconds: 30.0,
    maxOutputTokens: 200,
    temperature: 0.2,
);

$answer = $gateway->complete($request);

echo $answer->text;                 // prints Hello, Utrecht.
echo $answer->finishReason->value;  // prints stop
echo $answer->model;                // prints example-model
echo $answer->attempts;             // prints 1
```

What to know about a request:

- `model`, `messages` and `timeoutSeconds` are required. **The timeout has no default.** It is one deadline for the
  whole call: every attempt and every wait between attempts.
- System messages come first. A system message after a message of another role is refused.
- `temperature` runs from 0.0 to 1.0. `maxOutputTokens` is 1 or more. Leave either out for the provider's default.
- `traceId` is optional. Give your own to find the call back in your logs; without one the library makes one.
- A request is immutable. `withMessages()`, `withTools()`, `withToolChoice()` and `withResponseFormat()` give a
  changed copy.
- A value that breaks a rule (an empty model, a timeout of 0) is an `InvalidArgumentException`, at once: the
  library's own `Contract\InvalidValue`, which extends it.

An answer also tells what the call used. `willAnswerText()` takes the token counts as a second argument; without
it the fake reports none. Runs today.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Testing\FakeGateway;

$gateway = (new FakeGateway())->willAnswerText('Hello, Utrecht.', new Usage(inputTokens: 12, outputTokens: 5));

$request = new Request('example-model', [Message::user('Say hello to Utrecht.')], 30.0, traceId: 'trace-1');
$answer = $gateway->complete($request);

echo $answer->usage->inputTokens;   // prints 12
echo $answer->usage->outputTokens;  // prints 5
echo $answer->traceId;              // prints trace-1
// The whole call in milliseconds, every attempt and every wait. The fake takes no time.
echo $answer->latencyMs;            // prints 0
```

A token count is `null` when the provider did not report it. The finish reason is one of `stop`, `tool_calls`,
`length` (cut off at the token limit), `content_filter` and `other`.

### The same answer as a stream

`stream()` gives the same answer as events. Nothing is sent before you start to read. Runs today.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Testing\FakeGateway;

$usage = new Usage(3, 2);
$whole = new Answer('Hello.', null, [], FinishReason::Stop, 'example-model', $usage, 0, 1, 'trace-1');

$gateway = (new FakeGateway())->willStream(
    new TextDelta('Hel'),
    new TextDelta('lo.'),
    new UsageReported($usage),
    new StreamFinished($whole),
);

$request = new Request('example-model', [Message::user('Say hello.')], 30.0);

$text = '';
foreach ($gateway->stream($request) as $event) {
    if ($event instanceof TextDelta) {
        $text .= $event->text;                 // show it to the user as it arrives
    } elseif ($event instanceof StreamFinished) {
        $answer = $event->answer;              // the same Answer complete() returns
    } elseif ($event instanceof StreamFailed) {
        $reason = $event->error->reason;       // why it failed
        $partial = $event->textSoFar;          // what arrived before it failed
    }
}

echo $text;  // prints Hello.
```

The events are `TextDelta`, `ToolCallStarted`, `ToolCallCompleted`, `UsageReported` (at most once) and one last
event: `StreamFinished` or `StreamFailed`.

- `stream()` never throws a `GatewayException`. Every failure is the last event, a `StreamFailed`.
- A model that cannot stream gives the events a stream would give, all at once: the text as one `TextDelta`, the
  two events of every tool call, the usage when it is known, and then `StreamFinished`. Your code stays the same.
- The timeout covers the stream until its end. The clock also runs while your code handles an event.

### Tools

You describe a tool; the model may ask to call it. **The library never runs a tool.** It hands you the call, you run
it, and you send the result back as a message in the next request. Runs today.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Testing\FakeGateway;

$weather = new ToolDefinition(
    name: 'get_weather',
    description: 'Gives the temperature in a city.',
    parameters: JsonSchema::fromArray([
        'type' => 'object',
        'properties' => ['city' => ['type' => 'string']],
        'required' => ['city'],
    ]),
);

$gateway = (new FakeGateway())
    ->willCallTools(ToolCall::decoded('call_1', 'get_weather', ['city' => 'Utrecht'], '{"city":"Utrecht"}'))
    ->willAnswerText('It is 18 degrees in Utrecht.');

$request = new Request(
    model: 'example-model',
    messages: [Message::user('How warm is it in Utrecht?')],
    timeoutSeconds: 30.0,
    tools: [$weather],
);

// Round 1: the model asks for a tool call.
$answer = $gateway->complete($request);

$messages = [...$request->messages, $answer->toMessage()];
foreach ($answer->toolCalls as $call) {
    $result = $call->isReadable()
        ? json_encode(['city' => $call->arguments['city'], 'degrees' => 18])   // your own code runs the tool
        : 'The arguments could not be read: ' . $call->argumentsError;         // tell the model, do not throw
    $messages[] = Message::toolResult($call->id, $call->name, (string) $result);
}

// Round 2: the same request with the result added.
$final = $gateway->complete($request->withMessages($messages));

echo $final->text;  // prints It is 18 degrees in Utrecht.
```

- A JSON Schema must have `"type": "object"` at its root. Build one with `JsonSchema::fromArray()` or
  `JsonSchema::fromJson()`.
- A model can garble a call. Arguments that cannot be read are not an exception: `isReadable()` is false and
  `argumentsError` says why. A tool name is given as the model wrote it; check it against your own tools.
- Build the assistant message of the next round with `$answer->toMessage()`. Some providers send data with an
  answer that must go back unchanged; `toMessage()` carries it as a `Contract\OpaquePart`, which you skip when you
  read a message and keep when you send or store one (`$part->data` is text; build it again with `new OpaquePart`).
  A part that holds your key, or is larger than 1 MiB, is dropped without a word; the next round then goes without
  it, and a provider that insists on that data may refuse the request (`rejected`).
- The tool choice is `ToolChoice::auto()` (the default), `ToolChoice::none()`, `ToolChoice::required()` (some tool)
  or `ToolChoice::tool('get_weather')` (this tool). Set it with `withToolChoice()` or the `toolChoice` argument.
- A tool loop that does these rounds for you is not built yet.

### A structured answer

Give a response format with a JSON Schema, and the answer comes back decoded and checked. **An answer that does not
match is not an exception.** It is returned with its reasons, so you can send them back to the model. Runs today.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Testing\FakeGateway;

$trip = new ResponseFormat(
    schema: JsonSchema::fromArray([
        'type' => 'object',
        'properties' => ['city' => ['type' => 'string'], 'days' => ['type' => 'integer']],
        'required' => ['city', 'days'],
        'additionalProperties' => false,
    ]),
    name: 'trip',
    description: 'A trip: the city and the number of days.',
);

$gateway = (new FakeGateway())
    ->willAnswerText('{"city":"Paris","days":"three"}')
    ->willAnswerText('{"city":"Paris","days":3}');

$request = new Request('example-model', [Message::user('Plan a short trip.')], 30.0, responseFormat: $trip);

$first = $gateway->complete($request);
$result = $first->structured;

var_dump($result?->isUsable());                 // prints bool(false)
// The data is decoded and kept, but it is not usable. There is one reason, on one line.
echo json_encode($result?->data);               // prints {"city":"Paris","days":"three"}
echo implode("\n", $result?->reasons() ?? []);  // prints /days: The data (string) must match the type: integer

// Send the reasons back and ask again.
$second = $gateway->complete($request->withMessages([
    ...$request->messages,
    $first->toMessage(),
    Message::user("That answer cannot be used:\n- " . implode("\n- ", $result?->reasons() ?? [])),
]));

var_dump($second->structured?->isUsable());    // prints bool(true)
echo json_encode($second->structured?->data);  // prints {"city":"Paris","days":3}
```

How to read `Answer::$structured`:

| What you see | What it means |
|---|---|
| `null` | The request had no response format, or the answer holds tool calls (it is not the final answer yet). |
| `isUsable()` is true | `data` is the decoded object and it matches the schema. |
| `isUsable()` is false | `reasons()` lists why. `data` is the decoded object when the text was JSON, else `null`. |

Each reason is one line. There are three kinds (`StructuredIssue::$kind`): `not_json`, `schema_mismatch` (with the
path, such as `/days: …`) and `cut_off` (the answer stopped at the token limit). The one exception here is a schema
that is itself wrong: that is a `GatewayException` with the reason `rejected`.

### Errors

A call that fails throws one exception, `Contract\GatewayException`, with one of eight fixed reasons. The reasons are
the same for every provider. Runs today.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Testing\FakeGateway;

$gateway = (new FakeGateway())->willFail(new GatewayException(
    reason: ErrorReason::RateLimited,
    traceId: 'trace-1',
    httpStatus: 429,
    retryAfterSeconds: 20,
    attempts: 3,
));

try {
    $gateway->complete(new Request('example-model', [Message::user('Say hello.')], 30.0));
} catch (GatewayException $error) {
    echo $error->reason->value;       // prints rate_limited
    echo $error->httpStatus;          // prints 429
    echo $error->retryAfterSeconds;   // prints 20
    echo $error->attempts;            // prints 3
    echo $error->traceId;             // prints trace-1
    echo $error->getMessage();        // prints rate_limited (HTTP 429)
}
```

| Reason | When | Tried again |
|---|---|---|
| `not_configured` | A setting is missing, the credentials do not fit the preset, or your transport refused the address. | no |
| `credentials_rejected` | The provider refused the sign-in (401, 403), or your token supplier failed. | no |
| `rate_limited` | The provider asks to slow down (429). | yes |
| `unavailable` | A server error (5xx, 408) or a lost connection. | yes |
| `unavailable` | The server answered with a redirect. A redirect is never followed. | no |
| `rejected` | The provider refused the request: an unknown model, an invalid schema, a value out of range. | no |
| `provider_error` | An answer without content, an error inside a successful response, a body that cannot be read. | no |
| `timeout` | The time of the call ran out. | no |
| `not_supported` | The model cannot do what the request asks. Nothing was sent. | no |

Beside it the library throws three small classes of its own, each the plain PHP class with the arguments of every
call left out of its trace, so that no key, request or response can be read from it: `Contract\InvalidValue` (an
`InvalidArgumentException`) for a value it refuses, `Contract\WrongUse` (a `LogicException`) for a call it cannot
serve, and `Contract\ForeignFault` (an `Error`) for a fault in code that is not its own, such as your HTTP client or
your adapter. Catch them as the PHP class.

The exception also carries `providerCode` (the provider's own code, when it is a plain word) and `detail` (a sentence
written by the library). The message never holds text from a provider and never a key. The provider's raw response
reaches you through the attempt observer only (see [A real provider](#a-real-provider)).

**The retry policy is required and has no default.** You give the list of waits; its length is the number of retries.
Runs today.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\RetryPolicy;

// Three retries: wait 1 second, then 2, then 4. Accept a wait of at most 30 seconds from the provider.
$retry = new RetryPolicy(waitsSeconds: [1.0, 2.0, 4.0], maxProviderWaitSeconds: 30.0);

// No retries at all.
$never = RetryPolicy::none();
```

- When the provider says how long to wait, that wait is used instead of yours. When it asks for more than
  `maxProviderWaitSeconds`, the call fails at once and `retryAfterSeconds` tells the wait.
- No retry waits past the deadline of the call.
- A stream is tried again only while it has given no event yet. After the first event a failure is final.
- The library adds no random spread to the waits, and has no limit of its own on calls per minute or per day.
- The fake gateway makes no HTTP attempts, so it never retries: a scripted failure is thrown as it is.

### Capabilities

Not every model can do everything. Ask what a model can do. **Never ask which provider it is.** Asking sends
nothing; it fails only on a malformed model name. Runs today.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Testing\FakeGateway;

// A model that cannot call tools. Without withCapabilities() the fake says it can do everything.
$gateway = (new FakeGateway())->withCapabilities(new Capabilities(
    toolCalling: Support::None,
    forcedToolCall: Support::None,
    parallelToolCalls: Support::None,
    structuredOutput: Support::Emulated,
    toolsWithStructuredOutput: Support::None,
    streaming: Support::Emulated,
    modelListing: Support::None,
    contextLength: 32000,
));

$can = $gateway->capabilities('example-model');

// No tool calling: leave the tools out, or choose another model. A response format works: emulated counts.
echo $can->toolCalling->value;       // prints none
echo $can->structuredOutput->value;  // prints emulated

// A request that asks for more is refused before anything is sent.
$tool = new ToolDefinition('get_weather', 'Gives the temperature in a city.', JsonSchema::fromArray(['type' => 'object']));

try {
    $gateway->complete(new Request('example-model', [Message::user('How warm is it?')], 30.0, tools: [$tool]));
} catch (GatewayException $error) {
    echo $error->reason->value;  // prints not_supported
    echo $error->attempts;       // prints 0
}
```

Each capability is `native`, `emulated` or `none`:

| Capability | The question |
|---|---|
| `toolCalling` | Can the model ask for tool calls? |
| `forcedToolCall` | Can you force one named tool (`ToolChoice::tool()`)? |
| `parallelToolCalls` | Can one answer hold several tool calls? |
| `structuredOutput` | Can you give a response format? |
| `toolsWithStructuredOutput` | Can tools and a response format go in one request? |
| `streaming` | `native`, or `emulated`: the whole answer arrives at once, as the same events. Never `none`. |
| `modelListing` | Can the models be listed? `native` or `none`. |
| `contextLength` | In tokens, when it is known without a request; else `null`. |

`emulated` means the library does the work for you and the result has the same shape. You do not have to treat it
differently.

### A real provider

Against a real provider, only the way you build the gateway changes. You need four things:

1. a **preset**: the data of one provider (which adapter, which address, which sign-in);
2. **credentials**: the secret and how it is sent;
3. a **transport**: your HTTP client behind the library's `Transport` interface;
4. a **config**: the retry policy, and optionally a base URL, settings, an observer and a clock.

`GatewayFactory::create()` turns them into a gateway. It sends nothing. This function runs today with any preset; a
shipped preset to pass to it is not built yet, so today the preset is one you make yourself (see
[Adding your own provider](#adding-your-own-provider)).

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Transport\Psr18Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

function connect(
    Preset $preset,
    #[\SensitiveParameter] string $apiKey,
    ClientInterface $client,
    RequestFactoryInterface $requestFactory,
    StreamFactoryInterface $streamFactory,
): Gateway {
    return GatewayFactory::create(
        $preset,
        new BearerKey($apiKey),
        new Psr18Transport($client, $requestFactory, $streamFactory),
        new GatewayConfig(retry: new RetryPolicy([1.0, 2.0, 4.0], 30.0)),
    );
}
```

The gateway you get back is used exactly as the fake in the samples above: `complete()`, `stream()`, `models()`,
`capabilities()`.

**Credentials.** There are four kinds. A preset says which it accepts; another kind is refused as `not_configured`.

| Class | What it sends |
|---|---|
| `Credentials\NoCredentials` | Nothing. For a server you run yourself without a key. |
| `Credentials\BearerKey` | The key as a bearer token in the `Authorization` header. |
| `Credentials\HeaderKey` | The key in the header the preset names. |
| `Credentials\BearerToken` | A short-lived token from a `TokenSupplier` you write. The library asks it before every attempt and keeps nothing; keeping the token until it runs out is your supplier's work. |

The library reads no environment variable, file or vault: you hand it the key. A key never shows in an exception, an
event, a log form or a dump. `$credentials->secrets()` gives the secret values, so you can register them with your
own redactor before the first request. For a `BearerToken` that list is empty: a token is not known before a call.
Register each token with your redactor in your own supplier, where you make it.

**Config.** `GatewayConfig` takes:

| Argument | What it is |
|---|---|
| `retry` | The retry policy. Required. |
| `baseUrl` | The customer's own address, for a preset that asks for one or allows one. |
| `settings` | The values for the fields of the preset, by field key. Never a secret. |
| `observer` | A `Transport\AttemptObserver`. It is told before and after every HTTP attempt, with the request (secret headers masked) and the response. Use it for your trace or audit log. What it throws is dropped: it never changes a call. |
| `clock` | The time and the waits. Leave it out in production; a test gives `Testing\FakeClock`, which never waits. |

`GatewayFactory::resolveBaseUrl($preset, $config)` gives the address every request will start with. Run your own
outbound rule on it before the first call. The library never follows a redirect and never changes the host.

**What you must set on your HTTP client.** The bridge `Transport\Psr18Transport` works with any PSR-18 client, but
there are things it cannot see or do. Set them on the client:

- **Redirects.** The client must never follow a redirect. The bridge treats a 3xx answer as a failure, but it cannot
  tell when the client already followed one.
- **Error statuses.** The client must return a 4xx or 5xx as a response, as PSR-18 asks, and not throw for it.
- **Time.** PSR-18 has no timeout per request, so the timeout of the request is not passed on. Set a connect timeout
  and a read timeout on the client. The library still checks its own deadline between attempts and between pieces of
  a stream. A read timeout of the client looks like a lost connection: it is reported as `unavailable`, not as
  `timeout`.
- **Streams.** For pieces as they arrive, the client must return the response as soon as the headers are in and read
  the body on demand. A client that reads the whole body first still works: the text is the same, it only arrives
  together at the end.
- **Reuse.** The client must keep nothing between two requests that changes the next one: no cookies, no remembered
  sign-in.

With Guzzle (checked against Guzzle 8.6): its PSR-18 method `sendRequest()` already switches redirects off and
returns error statuses as responses, whatever the client is set to. The option that makes the body arrive piece by
piece is `stream`. Guzzle hands such a request to its stream handler, which needs PHP's `allow_url_fopen` to be on;
`read_timeout` is the timeout that handler keeps. This runs today when Guzzle is installed; it builds the transport
and sends nothing.

<!-- example: runs -->
```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OrganiseYou\LlmGateway\Transport\Psr18Transport;

$client = new Client([
    'stream' => true,          // return the response when the headers are in; read the body on demand
    'connect_timeout' => 5,    // seconds to make the connection
    'read_timeout' => 60,      // seconds the connection may stay silent
    'cookies' => false,        // keep nothing between two requests
]);

$factory = new HttpFactory();
$transport = new Psr18Transport($client, $factory, $factory);
```

You can also write your own class that implements `Transport\Transport`, for example to pass the timeout of each
request on to your client. That is where your outbound rule, tracing and redaction go.

## Choosing a provider and a model

A screen where a customer chooses a provider and a model has two steps.

**Step 1: list the providers.** Not built yet. The library will ship a catalogue of its presets, `Preset\Presets`.
Each entry is a `Preset` with a label, a description, the fields the customer fills in, the kinds of credentials it
accepts and where the provider processes data. The method names below are not decided; they may change.

<!-- example: coming -->
```php
use OrganiseYou\LlmGateway\Preset\Presets;

// Not built yet.
foreach (Presets::all() as $preset) {
    echo $preset->id, ': ', $preset->label, ' - ', $preset->description, "\n";
}

$chosen = Presets::find('the-id-the-customer-chose');
```

A preset you made yourself is not in that catalogue. Keep your own next to it in your own list.

**Step 2: ask the chosen provider for its models.** `Gateway::models()` exists. It needs that customer's credentials
and it sends a request, so it takes a timeout and can fail like any call. The shipped adapters will fill it in later;
the fake shows the shape. Runs today.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Testing\FakeGateway;

$gateway = (new FakeGateway())->withModels(
    new ModelInfo(id: 'example-small', name: 'Example Small', contextLength: 32000),
    new ModelInfo(id: 'example-large', name: 'Example Large', contextLength: 128000, aliases: ['example-latest']),
    new ModelInfo(id: 'example-old', deprecated: true),
);

foreach ($gateway->models(timeoutSeconds: 10.0) as $model) {
    echo $model->id;                    // the value to put in a request's model
    echo $model->name ?? $model->id;    // a display name, when the provider gives one
    echo $model->contextLength ?? '?';  // in tokens, when the list tells it
    echo $model->deprecated ? ' (deprecated)' : '';
}
```

When a provider cannot list its models, `models()` throws a `GatewayException` with the reason `not_supported`, and
`capabilities()->modelListing` is `none`. Let the customer type the model name in that case: a model name is free
text.

There is no single call for "all providers with all their models". A model list belongs to an account: which models
you may use depends on the key, so it can only be asked with that customer's credentials, one provider at a time.
The library itself keeps no list of models.

## Adding your own provider

You do not have to wait for a new version of the library. There are three cases, from easy to hard.

### (a) A new model at a provider you already use

Do nothing. The model name is free text: put the new name in the request. No adapter keeps a closed list of models.
For a model it does not know, `capabilities()` answers with the defaults of its format.

### (b) A new provider that speaks a format the library already knows

Build your own `Preset` object in your own code. A preset is data: no class to write.

**Not built yet:** a preset must name an adapter class, and the shipped adapters do not exist yet. In the sample
`KnownFormatAdapter` is a placeholder for the shipped adapter of that format. Today this works only with an adapter
you write yourself, as in case (c). The names of the adapter options are the adapter's own, so they may change too.

<!-- example: coming, placeholder: KnownFormatAdapter -->
```php
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Preset\PresetField;

$preset = new Preset(
    // Your own id: lowercase letters, digits and hyphens. It shows in the attempt reports, nowhere else.
    id: 'example-cloud',
    // The name a customer reads in a list of providers.
    label: 'Example Cloud',
    // What the customer needs, in one plain sentence.
    description: 'You need an API key and the region of your Example Cloud account.',
    // The adapter that speaks the format. Placeholder: the shipped adapters are not built yet.
    adapter: KnownFormatAdapter::class,
    // Who gives the address: Fixed (only this one), CustomerMay (may replace it) or CustomerMust (no default).
    baseUrlRule: BaseUrlRule::Fixed,
    // The address every request starts with. {region} is filled from the field below. Null with CustomerMust.
    defaultBaseUrl: 'https://{region}.llm.example.test/v1',
    // The kinds of credentials this provider accepts.
    credentialKinds: [CredentialKind::BearerKey, CredentialKind::HeaderKey],
    // The header a HeaderKey is sent in. Null when the preset accepts no header key.
    keyHeader: 'X-Api-Key',
    // What the customer fills in beside the key: key, label, required, and a pattern the whole value must match.
    // A field used in the address must have a pattern. Never put a secret in a field.
    fields: [new PresetField('region', 'Region', true, '[a-z]{2}[0-9]')],
    // Where the provider processes data, as its own documentation says it.
    dataLocation: 'European Union, as the provider states',
    // The link to that statement, or null.
    dataLocationSource: 'https://llm.example.test/docs/data-location',
    // True when the adapter sends the provider's option not to store the conversation.
    sendsNoStoreOption: false,
    // Where this server differs from the defaults of its format. The adapter defines the names.
    adapterOptions: ['structured_output' => 'emulated'],
);
```

The customer's values for the fields go in `GatewayConfig::$settings`, here `['region' => 'eu1']`. A value that fills
a placeholder of the address may hold only letters, digits and `. _ ~ -`, so a setting can never move a request to
another host.

### (c) A new format

Write your own adapter: a class that extends `Adapter\Shared\AbstractAdapter`. You write how the format looks. The
base does everything that is the same for every format.

**What the base does for you:**

- the sign-in header, for every attempt;
- the retries, the waits and the deadline of the call;
- the mapping of a failure to one of the eight reasons;
- the refusal of a request the model cannot do, before anything is sent;
- the check of a structured answer against its schema;
- the events of a stream for a model that cannot stream;
- the trace id, the count of attempts, the latency and the reports to the observer;
- keeping secrets out: a provider code or a detail that holds a key is dropped.

**The methods you write:**

| Method | What it does |
|---|---|
| `modelCapabilities($model)` | Says what this model can do. Sends nothing. An unknown model gets the defaults of the format. |
| `completionRequest($request, $stream)` | Builds the HTTP request of one completion: method, address, headers, body. No sign-in header: the base adds it. |
| `readCompletion($response, $request)` | Reads a successful response into an answer, built with `$this->answer()`. |
| `readStream($events, $request)` | Turns the server-sent events of a stream into the neutral events. The last one is a `StreamFinished`. |
| `modelsRequest($timeoutSeconds)` | Builds the request for the model list, or returns `null` when there is none. |
| `readModels($response)` | Reads the model list. |
| `providerCode($response)` | Optional. The provider's own code in a failed response. |
| `refineReason($response, $reason)` | Optional. Changes the reason the status gave, for a code the documentation names. |
| `retryAfterSeconds($response)` | Optional. A wait the format tells outside the `Retry-After` header. |

**The helpers you use:** `$this->url($path, $query)` builds an address; `$this->answer(...)` builds an answer;
`$this->failure($reason, $detail, $providerCode)` builds an error to throw; `$this->setting($key)` reads a setting of
the customer; `$this->preset` holds the preset, with its `adapterOptions`.

**Optional: data that must go back unchanged.** Only for a format whose answers hold such data: give your adapter
the constant `OPAQUE_FORMAT`, hand `$this->seal($text, $toolCalls, $content)` to `answer()` as its last argument, and
send what `$this->unseal($message)` gives in place of the assistant message you would build; it gives `null` for a
part that is not yours or a message that was changed. The example below has no such data and uses none of the three.

- The content comes back as PHP arrays, not as the provider's bytes: an empty JSON object comes back as an empty
  list, and a number is written again in another spelling. Anything that must come back byte for byte is sealed as
  one string: the JSON text as you received it.
- The content is not the provider's word when it comes back. It comes from wherever the caller keeps its messages,
  and a part has no secret: whoever can write there can choose the content. Copy from it only the kinds of block and
  the fields your format documents, and never take an address, a header or a model name from it.
- Pick a mark no other adapter of your project uses; `a1` to `a9` are kept for the adapters of the library.

**What you must not do:**

- Do not build an address outside `url()`. A request whose address does not start with the base URL of the gateway
  is not sent.
- Do not put text of the provider in the `detail` of an error. Write your own short sentence. The provider's own
  code goes in `providerCode`.
- Do not keep state. The class is `readonly`; nothing may be remembered between two calls.
- Do not write a constructor. It is final: a gateway is always built as the factory builds it.
- Do not send the trace id of the request to the provider.
- Do not throw for an answer that does not match its schema, and do not check it yourself: the base does.
- Mark every parameter that takes a response `#[\SensitiveParameter]` yourself. PHP does not inherit the attribute.

The constructor and the protected methods of `AbstractAdapter` are extension points: from the first release,
changing them is a breaking change.

**A skeleton.** This adapter speaks a made-up format, "talk"; no provider speaks it. It is the format of the
repository's own worked example, `tests/Support/TalkAdapter.php`. Put the class in your own namespace. Runs today.

| | The made-up format |
|---|---|
| A completion | `POST /talk` with `{"model", "stream", "lines": [{"who", "say", "calls", "answers"}], "tools", "pick", "shape", "limit", "heat"}` |
| Its answer | `{"say", "calls": [{"ref", "tool", "input"}], "end", "by", "used": {"in", "out"}}` |
| An error | `{"fault": {"code"}}` |
| A stream | The events `say`, `call_open`, `call`, `used` and `end`; an error is a body with `fault` |
| The models | `GET /catalog` with `{"items": [{"key", "title", "window"}]}` |

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

final readonly class ExampleAdapter extends AbstractAdapter
{
    protected function modelCapabilities(string $model): Capabilities
    {
        // The same for every model of this format. A preset can say that its server lists no models.
        return new Capabilities(
            toolCalling: Support::Native,
            forcedToolCall: Support::Native,
            parallelToolCalls: Support::Native,
            structuredOutput: Support::Native,
            toolsWithStructuredOutput: Support::Native,
            streaming: Support::Native,
            modelListing: $this->listsModels() ? Support::Native : Support::None,
            contextLength: null,
        );
    }

    protected function completionRequest(Request $request, bool $stream): HttpRequest
    {
        $lines = [];
        foreach ($request->messages as $message) {
            $lines[] = [
                'who' => $message->role->value,
                'say' => $message->text(),
                'calls' => array_map(
                    static fn(ToolCall $call): array => ['ref' => $call->id, 'tool' => $call->name, 'input' => $call->rawArguments],
                    $message->toolCalls,
                ),
                'answers' => $message->toolCallId,
            ];
        }

        $tools = [];
        foreach ($request->tools as $tool) {
            $tools[] = ['name' => $tool->name, 'about' => $tool->description, 'input' => $tool->parameters->toObject()];
        }

        $format = $request->responseFormat;

        try {
            $body = json_encode([
                'model' => $request->model,
                'stream' => $stream,
                'lines' => $lines,
                'tools' => $tools,
                'pick' => ['mode' => $request->toolChoice->mode->value, 'tool' => $request->toolChoice->toolName],
                'shape' => $format === null ? null : [
                    'name' => $format->name,
                    'about' => $format->description,
                    'schema' => $format->schema->toObject(),
                ],
                'limit' => $request->maxOutputTokens,
                'heat' => $request->temperature,
            ], JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // A value this format cannot carry, such as raw tool-call arguments that are not valid UTF-8. Refuse it
            // with your own sentence. An exception that leaves this method ends the call as a provider error.
            throw $this->failure(ErrorReason::Rejected, 'the request holds a value that cannot be written as JSON');
        }

        return new HttpRequest(
            'POST',
            $this->url('/talk', $stream ? ['flow' => 1] : []),   // every address comes from url()
            ['Content-Type' => 'application/json'],              // no sign-in header: the base adds it
            [],
            $request->timeoutSeconds,                            // replaced by the base with the time that is left
            $body,
        );
    }

    protected function readCompletion(#[\SensitiveParameter] HttpResponse $response, Request $request): Answer
    {
        $body = $this->decoded($response->body);

        $calls = [];
        foreach (self::items($body, 'calls') as $at => $call) {
            $calls[] = self::toolCall($call, $at);
        }

        // The base adds the trace id, the attempts, the latency and the structured result.
        return $this->answer(
            $request,
            self::text($body, 'say'),
            $calls,
            self::finishReason(self::text($body, 'end')),
            self::usage($body['used'] ?? null),
            self::text($body, 'by'),
        );
    }

    protected function readStream(#[\SensitiveParameter] iterable $events, Request $request): iterable
    {
        $text = '';
        $calls = [];
        $usage = new Usage(null, null);
        $reported = false;   // usage is reported at most once
        $open = null;        // the id and the name of a tool call that started and did not complete yet

        // A server can break the rules of a stream. The events you give must keep them, whatever arrives.
        foreach ($events as $event) {
            $data = $this->decoded($event->data);

            if ($event->event === 'say') {
                $piece = self::text($data, 'piece');
                if ($piece !== '') {
                    $text .= $piece;
                    yield new TextDelta($piece);
                }
            } elseif ($event->event === 'call_open' && $open === null) {
                $id = self::text($data, 'ref');
                $open = [$id === '' ? 'call_' . count($calls) : $id, self::text($data, 'tool')];
                yield new ToolCallStarted(count($calls), $open[0], $open[1]);
            } elseif ($event->event === 'call') {
                $call = self::toolCall($data, count($calls));
                if ($open === null) {
                    // A call that completes without a start: start it here, so every call starts once.
                    yield new ToolCallStarted(count($calls), $call->id, $call->name);
                } elseif ($open !== [$call->id, $call->name]) {
                    throw $this->failure(ErrorReason::ProviderError, 'a tool call ended with another id or name than it started with');
                }
                $open = null;
                yield new ToolCallCompleted(count($calls), $call);
                $calls[] = $call;
            } elseif ($event->event === 'used' && ! $reported) {
                $reported = true;
                $usage = self::usage($data);
                yield new UsageReported($usage);
            } elseif ($event->event === 'end') {
                if ($open !== null) {
                    throw $this->failure(ErrorReason::ProviderError, 'the stream ended inside a tool call');
                }

                yield new StreamFinished($this->answer(
                    $request,
                    $text,
                    $calls,
                    self::finishReason(self::text($data, 'end')),
                    $usage,
                    self::text($data, 'by'),
                ));

                return;
            }
            // An event of a kind this format does not document, a second start and a second usage are skipped.
        }
    }

    protected function modelsRequest(float $timeoutSeconds): ?HttpRequest
    {
        if (! $this->listsModels()) {
            return null;
        }

        return new HttpRequest('GET', $this->url('/catalog'), ['Accept' => 'application/json'], [], $timeoutSeconds);
    }

    protected function readModels(#[\SensitiveParameter] HttpResponse $response): array
    {
        $models = [];
        foreach (self::items($this->decoded($response->body), 'items') as $item) {
            $key = self::text($item, 'key');
            if ($key === '') {
                continue;
            }
            $title = self::text($item, 'title');
            $window = $item['window'] ?? null;
            $models[] = new ModelInfo($key, $title === '' ? null : $title, is_int($window) && $window > 0 ? $window : null);
        }

        return $models;
    }

    protected function providerCode(#[\SensitiveParameter] HttpResponse $response): ?string
    {
        $body = json_decode($response->body, true);
        $code = is_array($body) && is_array($body['fault'] ?? null) ? $body['fault']['code'] ?? null : null;

        return is_string($code) ? $code : null;
    }

    protected function refineReason(#[\SensitiveParameter] HttpResponse $response, ErrorReason $reason): ErrorReason
    {
        // Only for codes the documentation of the format names.
        return match ($this->providerCode($response)) {
            'no_such_model' => ErrorReason::Rejected,
            'overloaded' => ErrorReason::Unavailable,
            default => $reason,
        };
    }

    private function listsModels(): bool
    {
        return ($this->preset->adapterOptions['lists_models'] ?? true) === true;
    }

    /**
     * @return array<mixed>
     */
    private function decoded(#[\SensitiveParameter] string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw $this->failure(ErrorReason::ProviderError, 'the body is not a JSON object');
        }
        if (is_array($data['fault'] ?? null)) {
            // An error inside a successful response. The detail is our own sentence, never the provider's words.
            $code = $data['fault']['code'] ?? null;

            throw $this->failure(ErrorReason::ProviderError, 'the response holds an error', is_string($code) ? $code : null);
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    private static function text(#[\SensitiveParameter] array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private static function items(#[\SensitiveParameter] array $data, string $key): array
    {
        return array_values(array_filter(is_array($data[$key] ?? null) ? $data[$key] : [], is_array(...)));
    }

    /**
     * @param array<mixed> $call
     */
    private static function toolCall(#[\SensitiveParameter] array $call, int $at): ToolCall
    {
        $id = self::text($call, 'ref');
        $id = $id === '' ? 'call_' . $at : $id;
        $raw = self::text($call, 'input');
        $arguments = json_decode($raw, true);

        // Arguments that cannot be read are a value, not an exception.
        return is_array($arguments) && ($arguments === [] || ! array_is_list($arguments))
            ? ToolCall::decoded($id, self::text($call, 'tool'), $arguments, $raw)
            : ToolCall::unreadable($id, self::text($call, 'tool'), 'the arguments are not a JSON object', $raw);
    }

    private static function finishReason(#[\SensitiveParameter] string $end): FinishReason
    {
        return match ($end) {
            'done' => FinishReason::Stop,
            'calls' => FinishReason::ToolCalls,
            'cut' => FinishReason::Length,
            'blocked' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    private static function usage(#[\SensitiveParameter] mixed $used): Usage
    {
        $in = is_array($used) && is_int($used['in'] ?? null) && $used['in'] >= 0 ? $used['in'] : null;
        $out = is_array($used) && is_int($used['out'] ?? null) && $used['out'] >= 0 ? $used['out'] : null;

        return new Usage($in, $out);
    }
}
```

A format without structured output of its own can emulate it: the adapter reports `structuredOutput` as `emulated`
and adds the text of `(new Adapter\Shared\StructuredOutputEmulation())->instructions($request->responseFormat)` as
the last of the system text. The base still checks the answer, so the caller sees the same structured result.

**The preset for it, and a first call.** The preset names your adapter. The sample uses `Testing\FakeTransport`, so it
sends nothing; with your own transport the same gateway reaches the real server. Runs today, after the class above.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

function examplePreset(): Preset
{
    return new Preset(
        id: 'example-talk',
        label: 'Example server',
        description: 'You need the address of your server and an API key from its settings page.',
        adapter: ExampleAdapter::class,
        baseUrlRule: BaseUrlRule::CustomerMay,
        defaultBaseUrl: 'https://llm.example.test/api',
        credentialKinds: [CredentialKind::BearerKey],
        keyHeader: null,
        fields: [],
        dataLocation: 'not stated by the provider',
        dataLocationSource: null,
        sendsNoStoreOption: false,
    );
}

$transport = (new FakeTransport())->willReturn(new HttpResponse(
    200,
    ['Content-Type' => 'application/json'],
    '{"say":"Hello.","end":"done","by":"example-model-1","used":{"in":3,"out":2}}',
));

$gateway = GatewayFactory::create(
    examplePreset(),
    new BearerKey('test-key-not-a-real-one'),
    $transport,
    new GatewayConfig(retry: RetryPolicy::none()),
);

$answer = $gateway->complete(new Request('example-model', [Message::user('Say hello.')], 30.0));

echo $answer->text;                    // prints Hello.
echo $answer->model;                   // prints example-model-1
echo $answer->usage->inputTokens;      // prints 3
echo $transport->requests()[0]->url;   // prints https://llm.example.test/api/talk
```

**Prove it with the shared test set.** `Testing\ContractTestCase` holds what every gateway must do: a plain answer, a
tool call, a garbled tool call, a structured answer that matches and one that does not, a stream, every error
reason, retries and waits, the timeout, data that must go back unchanged, and no key in any output. An adapter passes
it or it is not an adapter.

It is a PHPUnit test case, written for PHPUnit 12. The library does not require PHPUnit, so add it to your own
project. Pest 4 brings PHPUnit 12 and runs the class as it is.

```bash
composer require --dev phpunit/phpunit:^12.5
```

You write two classes. The first gives the responses of your format: it implements `Testing\AdapterFixtures`. Write
the responses by hand from the documentation of the format, with made-up ids and text. Never capture them from a real
account. Runs today, with the fixture files in the folder you give it.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Testing\AdapterFixtures;
use OrganiseYou\LlmGateway\Testing\ContractFixture;
use OrganiseYou\LlmGateway\Testing\NotApplicable;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

final readonly class ExampleFixtures implements AdapterFixtures
{
    public function __construct(private string $directory) {}

    public function answer(ContractFixture $fixture): HttpResponse|NotApplicable|null
    {
        $file = self::file($fixture);
        if ($file instanceof NotApplicable) {
            return $file;
        }

        return new HttpResponse(200, ['Content-Type' => 'application/json'], $this->read($file . '.json'));
    }

    public function stream(ContractFixture $fixture): array|NotApplicable|null
    {
        $file = self::file($fixture);
        if ($file instanceof NotApplicable) {
            return $file;
        }

        // One event per chunk: an event ends with a blank line.
        $chunks = preg_split('/(?<=\n\n)/', $this->read($file . '.sse'), -1, PREG_SPLIT_NO_EMPTY);

        return $chunks === false ? null : $chunks;
    }

    public function failed(int $status, string $echo): HttpResponse
    {
        // The body must repeat $echo where the format has room for words of the provider.
        return new HttpResponse($status, ['Content-Type' => 'application/json'], str_replace('{{echo}}', $echo, $this->read('failed.json')));
    }

    public function errorInBody(string $echo): HttpResponse|NotApplicable
    {
        return new HttpResponse(200, ['Content-Type' => 'application/json'], str_replace('{{echo}}', $echo, $this->read('error_in_body.json')));
    }

    public function errorInStream(string $echo): array|NotApplicable
    {
        return [str_replace('{{echo}}', $echo, $this->read('error_in_stream.sse'))];
    }

    public function requestTheFormatCannotCarry(Request $request): Request|NotApplicable
    {
        // This format writes the raw arguments of an earlier tool call as JSON, and these are not valid UTF-8.
        return $request->withMessages([
            ...$request->messages,
            Message::assistant('', ToolCall::unreadable('call_1', 'lookup', 'the arguments are not valid UTF-8', "\xFF")),
            Message::toolResult('call_1', 'lookup', 'The arguments could not be read.'),
        ]);
    }

    private static function file(ContractFixture $fixture): string|NotApplicable
    {
        // No default: a case that is added later then fails here instead of being left out.
        return match ($fixture) {
            ContractFixture::ToolCallWithOpaqueData => new NotApplicable('an answer of this format holds no data that must go back unchanged'),
            ContractFixture::PlainAnswer => 'plain_answer',
            ContractFixture::ToolCall, ContractFixture::ToolCallBesideResponseFormat => 'tool_call',
            ContractFixture::ParallelToolCalls => 'parallel_tool_calls',
            ContractFixture::GarbledToolCall => 'garbled_tool_call',
            ContractFixture::StructuredValid => 'structured_valid',
            ContractFixture::StructuredInvalid => 'structured_invalid',
            ContractFixture::CutOff => 'cut_off',
            ContractFixture::EmptyAnswer => 'empty_answer',
        };
    }

    private function read(string $file): string
    {
        $content = file_get_contents($this->directory . '/' . $file);
        if ($content === false) {
            throw new \RuntimeException('The fixture ' . $file . ' could not be read.');
        }

        return $content;
    }
}
```

The files this class reads: for each of the eight names above one `.json` (the whole response) and one `.sse` (the
same answer as a stream), and `failed.json`, `error_in_body.json` and `error_in_stream.sse`. What each must hold is
written on the cases of `Testing\ContractFixture`. Three things are easy to miss:

- every fixture reports 3 input and 2 output tokens, the way the format reports usage;
- the stream of the plain answer has two chunks or more, with the first piece of text in the first chunk;
- a failure repeats the text it is given (`{{echo}}` above) in its error body. The test set passes the test key
  there, because a provider can repeat a key, and then checks that no output of the gateway holds it.

A complete set of these files for the made-up format of this chapter is in `tests/fixtures/talk` of this repository.
The test below reads that folder: it is written for a test class one folder below `tests`, such as
`tests/Contract`. For a format of your own, write your own files and name your own folder.

The second class is the test. It extends `ContractTestCase` and writes one method. Runs today, after the classes
above.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Testing\AdapterSubject;
use OrganiseYou\LlmGateway\Testing\ContractSubject;
use OrganiseYou\LlmGateway\Testing\ContractTestCase;

final class ExampleAdapterContractTest extends ContractTestCase
{
    protected function subject(RetryPolicy $retry): ContractSubject
    {
        return new AdapterSubject(
            examplePreset(),
            new BearerKey(self::MARKER_KEY),                           // the test key: sign in with this one
            new ExampleFixtures(dirname(__DIR__) . '/fixtures/talk'),  // the folder with your fixture files
            'example-model',
            $retry,                                                    // the policy the test set hands you
        );
    }
}
```

`AdapterSubject` builds the gateway from your preset on a fake transport and a clock that never waits. Nothing is
sent, and no test takes real time.

**Expect skipped tests.** A skip is the test set saying what it did not check, each with its reason. It is not a
fault.

- A gateway that can do everything and streams natively skips six: the five tests of a refusal (tools, a forced tool
  call, a response format, tools with a response format, the model list), because there is nothing to refuse, and
  the test of a gateway without streaming.
- Fixtures can answer `NotApplicable` for an error inside a successful response, for a request the format cannot
  carry and for an answer with opaque data. That scenario is then skipped with the reason they give. The example
  above has a fixture for the first two, and its format has no opaque data: it skips those six and the five tests
  of an answer with opaque data.
- A gateway that lacks a capability skips the scenarios that need it. Its refusal test runs in their place.
- One more test is skipped when PHP leaves the arguments out of traces (`zend.exception_ignore_args`, which
  `php.ini-production` switches on). Set it to 0 for the tests, with
  `<ini name="zend.exception_ignore_args" value="0"/>` in your `phpunit.xml`.

A fixture that is missing for something your gateway says it can do is a failure, not a skip. A server with other
capabilities is another test class with another preset. A server without sign-in overrides `signsIn()`; a server that
reports no token counts overrides `reportsUsage()`.

## Using the fake in your own tests

`Testing\FakeGateway` implements `Contract\Gateway`. It sends nothing and answers what your test scripted, so the
tests of your application need no adapter, no key and no network.

Script one entry per call, in order:

| Method | The next call gets |
|---|---|
| `willAnswerText('…')` | An answer with this text, from the model of its request. A `Usage` as second argument sets its token counts. |
| `willAnswer($answer)` | This `Answer`: use it for a finish reason, a latency or a model name of your choice. |
| `willCallTools($call, …)` | An answer without text that asks for these tool calls. |
| `willStream($event, …)` | These events. They must be a whole stream, ending with `StreamFinished` or `StreamFailed`. |
| `willFail($error)` | This `GatewayException`: thrown by `complete()`, the one event of `stream()`. |

Set what stays the same for every call:

| Method | What it sets |
|---|---|
| `withCapabilities($capabilities)` | What every model can do. A request that needs more is refused as `not_supported`. |
| `withModels($model, …)` | What `models()` gives. |
| `withConnectionReport($report)` | What `check()` gives. |

Read what happened: `requests()` gives every request the fake received, in order; `remaining()` tells how many
scripted entries are unused. Runs today.

<!-- example: runs -->
```php
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Testing\FakeGateway;

$gateway = (new FakeGateway())
    ->willAnswerText('Done.')
    ->willFail(new GatewayException(ErrorReason::Unavailable, 'trace-2', httpStatus: 503, attempts: 3))
    ->willFail(new GatewayException(ErrorReason::Timeout, 'trace-3', attempts: 1));

$request = new Request('example-model', [Message::user('Tidy the list.')], 30.0, traceId: 'my-trace');

// 1. A scripted answer.
echo $gateway->complete($request)->text;  // prints Done.

// 2. A scripted failure, thrown by complete(). It carries the trace id of the request.
try {
    $gateway->complete($request);
} catch (GatewayException $error) {
    echo $error->reason->value;  // prints unavailable
    echo $error->traceId;        // prints my-trace
}

// 3. A scripted failure read as a stream: not thrown, it is the one event.
foreach ($gateway->stream($request) as $event) {
    if ($event instanceof StreamFailed) {
        echo $event->error->reason->value;  // prints timeout
    }
}

// What your code sent, and whether it used the whole script.
echo count($gateway->requests());                   // prints 3
echo $gateway->requests()[0]->messages[0]->text();  // prints Tidy the list.
echo $gateway->remaining();                         // prints 0
```

The fake keeps the rules of a real gateway, so a test sees what production will see:

- One scripted entry serves one call of `complete()` or `stream()`, whichever comes. A scripted answer read through
  `stream()` comes as the events of a model without streaming. A scripted stream read through `complete()` gives the
  answer of its last event, or throws its error.
- A call with nothing scripted throws a `LogicException`. So does `models()` or `check()` when you gave it nothing.
- A request with a response format gets a structured result: the text is checked against the schema exactly as a
  real gateway checks it. An answer with tool calls gets none.
- The fake never makes an opaque part. One you script with `willAnswer()` reaches your code in `Answer::$opaque` and
  in `toMessage()`, and `requests()` shows the parts your code sent in the next round.
- An answer without text and without a tool call is a `provider_error`, unless it was cut off at the token limit.
- `willStream()` refuses, where you script it, a stream that breaks a rule: a text that is not the text of its
  deltas, a tool call that starts and never completes, usage reported twice.
- The request of a stream is recorded when the stream is read, not when `stream()` is called.
- The fake makes no HTTP attempts: it has no retries, no waits and no deadline.

The fake holds its script and its record, so make a new one in every test.

## Development

```bash
composer install
composer check    # code style, PHPStan level 8 and the tests
```

Without PHP on the machine, run the same two commands in Docker from the project folder:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer check
```

CI runs the suite with PHP's `php.ini-production`, which differs from PHP without a `php.ini` in what the trace of an
exception shows. The tests set what they need themselves (`phpunit.xml.dist`), so both give the same result. To run
the tests as CI does:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.4-cli sh -c 'cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini && vendor/bin/pest'
```

## Licence

MIT, see [LICENSE](LICENSE).
