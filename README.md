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
- `Testing\ContractTestCase`, the shared test set every adapter must pass;
- three shipped adapters, each with its model list: `Adapter\OpenAiCompatible\ChatCompletionsAdapter`, for the
  chat-completions format that many servers speak, `Adapter\Anthropic\MessagesAdapter`, for the Messages format, and
  `Adapter\Gemini\GenerateContentAdapter`, for the generate-content format.
  See [The shipped adapters](#the-shipped-adapters).

What is coming, and is **not built yet**:

- the other shipped adapters (one per API format) and the shipped presets (one per provider; the list is in
  [docs/requirements.md](docs/requirements.md), LR-18 and LR-21);
- the preset catalogue `Preset\Presets`, to list the providers the library knows;
- per preset, what a customer needs from their provider to connect;
- the tool loop (`src/Loop`): today you write the loop yourself, as shown under [Tools](#tools);
- the connection check: `Gateway::check()` exists, but a gateway built on the adapter base answers `not_supported`
  for now;
- model lists for the other shipped adapters: `Gateway::models()` works for the three shipped adapters and for an
  adapter you write yourself.

So today you can use the library in three ways: with the fake gateway, with a shipped adapter and a preset
you make yourself, and with an adapter you write yourself. Every
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
`length` (cut off at the token limit), `content_filter` (the provider withheld the output), `refusal` (the model
declined to answer) and `other`. A later version may add one: give a `match` over `FinishReason` a default arm.

An answer can come without text and without a tool call. That happens with three finish reasons only: `length` (the
model spent every token before it wrote anything), `content_filter` and `refusal`. It is an answer, not an error:
look at the finish reason before you use the text. `Answer::toMessage()` throws a `LogicException` for such an
answer: there is nothing to send back, so check the finish reason before you build the next round. A refusal that has
words keeps them as its text. A prompt the
provider refused to take is something else: an error with the reason `rejected`.

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

// No tool call: there is no next round. An answer without text and without a tool call cannot even become a
// message (toMessage() throws), so look at the finish reason here instead.
if ($answer->toolCalls === []) {
    throw new \RuntimeException('The model asked for no tool call and ended with: ' . $answer->finishReason->value);
}

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
| `temperature` | Does the model take a temperature? `native` or `none`. The last argument of `Capabilities`; left out, it is `native`. |

A request that gives a temperature to a model that takes none is refused as `not_supported` before anything is sent.
The library never drops the value for you: ask the capability first, and leave the temperature out.

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
and it sends a request, so it takes a timeout and can fail like any call. The three shipped adapters read it; the
other adapters will follow. The fake shows the shape. Runs today.

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

A preset must name an adapter class. Three shipped adapters exist today, for the chat-completions format, for the
Messages format and for the generate-content format; the others are not built yet (see [The shipped adapters](#the-shipped-adapters)). In the sample `KnownFormatAdapter` is a
placeholder for the shipped adapter of your format: write the class of that adapter in its place, or an adapter you
wrote yourself, as in case (c).

<!-- example: coming, placeholder: KnownFormatAdapter -->
```php
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\ModelOverride;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Preset\PresetField;

$preset = new Preset(
    // Your own id: lowercase letters, digits and hyphens. It shows in the attempt reports, nowhere else.
    id: 'example-cloud',
    // The name a customer reads in a list of providers.
    label: 'Example Cloud',
    // What the customer needs, in one plain sentence.
    description: 'You need an API key and the region of your Example Cloud account.',
    // The adapter that speaks the format. Placeholder: put the class of a shipped adapter here, or your own.
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
    // A fifth value names a request header: the setting is then sent in it with every request, when filled in.
    fields: [
        new PresetField('region', 'Region', true, '[a-z]{2}[0-9]'),
        new PresetField('workspace', 'Workspace', false, 'ws_[a-z0-9]+', 'X-Example-Workspace'),
    ],
    // Where the provider processes data, as its own documentation says it.
    dataLocation: 'European Union, as the provider states',
    // The link to that statement, or null.
    dataLocationSource: 'https://llm.example.test/docs/data-location',
    // True when the adapter sends the provider's option not to store the conversation.
    sendsNoStoreOption: false,
    // Where this server differs from the defaults of its format. The capability options below are the same for
    // every adapter; the other names are the adapter's own.
    adapterOptions: ['structured_output' => 'emulated'],
    // Options for named models, where they differ from the rest. * stands for any run of characters.
    modelOverrides: [new ModelOverride('example-small-*', ['forced_tool_call' => 'none'])],
    // What the provider keeps of a request and how the customer arranges otherwise, in one plain sentence.
    storageNote: 'Requests are kept for 30 days; ask the provider for an agreement to keep nothing.',
    // The link to that statement, or null.
    storageNoteSource: 'https://llm.example.test/docs/data-storage',
);
```

The customer's values for the fields go in `GatewayConfig::$settings`, here `['region' => 'eu1']`. A value that fills
a placeholder of the address may hold only letters, digits and `. _ ~ -`, so a setting can never move a request to
another host.

**A setting as a header.** A field with a header name is sent in that header with every request of the gateway,
when the customer filled it in: `['region' => 'eu1', 'workspace' => 'ws_12ab']` sends `X-Example-Workspace: ws_12ab`.
It is a setting, not a secret: it shows in the attempt reports. Such a field must have a pattern, and its value must
be visible ASCII without a space; otherwise building the gateway fails as `not_configured` and nothing is sent. The
header name has no underscore, is not digits only, and can never be a header of the sign-in (`Authorization`,
`Cookie`, the header of the key), one that moves a request (`Host`, `Via`, every `Proxy-…`, `Sec-…` and
`X-Forwarded-…`), one of the connection and the body (`Content-Type`, `Content-Length`, and the like) or one that
changes the response (`Accept`, `Accept-Encoding`, `Range`). The list cannot know the headers of your provider: never
name a header that carries a credential there.

**Capability options.** Every adapter reads these options, with these words. An option you leave out keeps the
default of the format. A value outside its list fails as `not_configured` when the gateway is asked for capabilities
or for a call.

| Option | Values |
|---|---|
| `tool_calling` | `native`, `none` |
| `forced_tool_call` | `native`, `emulated`, `none` |
| `parallel_tool_calls` | `native`, `none` |
| `structured_output` | `native`, `emulated`, `none` |
| `tools_with_structured_output` | `native`, `emulated`, `none` |
| `streaming` | `native`, `emulated` |
| `model_listing` | `native`, `none` |
| `temperature` | `accepted`, `not_accepted` |
| `context_length` | an integer of 1 or more |

Without tool calling there is no forced tool call, there are no parallel tool calls and no tools with structured
output, whatever the other options say; without structured output there are no tools with structured output.

**Options per model.** What `capabilities()` answers for a model comes from the first that speaks: a model override
that fits the name, then the options of the preset, then the defaults of the format. Every override that fits is
used, in the order of the list; a later one wins from an earlier one, so write the general pattern first and the
exception after it. An override is not a list of the models a preset knows: a model that fits none gets the options
of the preset. `$preset->optionsFor('example-small-2')` gives the options as they are for that model.

**Changing an option later.** `$preset->withAdapterOptions(['streaming' => 'native'])` gives a copy of the preset
with these options laid over its own. Use it when you learned more about a server than its preset says. The model
overrides stay as they are, and still win for the models they fit.

#### The shipped adapters

One adapter per API format. A provider that speaks a format the library knows is a preset on that adapter, not a
class of its own.

| Format | Adapter class | Status |
|---|---|---|
| Chat completions (`POST {base}/chat/completions`, the format many servers call "OpenAI-compatible") | `Adapter\OpenAiCompatible\ChatCompletionsAdapter` | built |
| Messages (`POST {base}/v1/messages`) | `Adapter\Anthropic\MessagesAdapter` | built |
| Generate-content (`POST {base}/models/{model}:generateContent`) | `Adapter\Gemini\GenerateContentAdapter` | built |
| Chat v2 | `Adapter\Cohere\ChatV2Adapter` | not built yet |
| Responses | `Adapter\OpenAiResponses\ResponsesAdapter` | not built yet |

**The chat-completions adapter.** It sends a request to `{base URL}/chat/completions` and reads the model list at
`{base URL}/models`. The base URL is used exactly as the preset or the customer gives it, with or without `/v1` in
it. What it sends is a closed list of fields: `model`, `messages`, `tools`, `tool_choice`, `response_format`, the
maximum of output tokens, `temperature`, `stream`, `stream_options` and `store`. It never sends a field that only one
provider has, and it leaves `tools` out when the request has no tools. A tool name must be letters, digits,
underscores and hyphens, at most 64 characters; another name is refused as `rejected` before anything is sent.

Its defaults, for a preset that sets no option: every capability is native, except tools together with a response
format, which is emulated (the tools are sent and the schema goes into the system text).

Servers of this format differ in small ways. Where the difference is data, the preset says so with one of the
options below, in `adapterOptions` or, for named models, in a `ModelOverride`. An option you leave out has its
default, the first value. A value outside the list fails as `not_configured` when the gateway is asked for
capabilities or for a call, and nothing is sent. So does an option whose name the adapter does not know: a preset on
this adapter holds only the options below and the capability options, so a name written wrongly is found. The word
`false` may be written as the string or as the boolean.

| Option | Values (default first) | What it changes |
|---|---|---|
| `max_tokens_field` | `max_completion_tokens`, `max_tokens` | the name of the field for the maximum of output tokens |
| `system_role` | `system`, `developer` | the role a system message is sent with |
| `system_messages` | `separate`, `joined` | one message per system message, or one message with the texts joined by a blank line |
| `assistant_content_without_text` | `null`, `empty_string` | the `content` of an assistant message that holds only tool calls |
| `required_word` | `required`, `any` | the word of `tool_choice` for "must call some tool" |
| `named_tool_choice` | `object`, `required_single`, `unsupported` | how one named tool is forced: the named-function object; the word above with only that tool in `tools`; or not at all. The capability "forced tool call" follows it: native, emulated, none |
| `tool_strict` | `omit`, `false` | whether `strict: false` is written on every tool |
| `tool_result_name` | `false`, `true` (booleans) | whether a tool message also carries the name of its tool |
| `json_schema_strict` | `omit`, `false`, `when_fits` | the `strict` flag of a response format: not sent; always `false`; or `true` only when your schema already fits the subset of `strict_subset`, and `false` otherwise. Your schema is never changed |
| `strict_subset` | `standard`, `narrow` | which documented subset `when_fits` tests |
| `json_schema_key` | `schema`, `schema_definition` | the name under which the schema stands in the response format |
| `stream_usage` | `on_request`, `unasked` | whether a stream is asked to report its token usage |
| `store_field` | `omit`, `false` | whether `store: false` is sent. Set the preset's `sendsNoStoreOption` to true exactly when this is `false` |
| `reasoning_carrier` | `none`, `message_field`, `content_entries` | whether reasoning that came with an answer goes back with that answer in the next round, in the opaque part of `Answer::toMessage()`, and where it sits: in a field beside the content, or as entries of a content that is a list |
| `inline_reasoning` | `keep`, `strip` | whether one leading `<think>…</think>` block is cut from the text of an answer |
| `finish_content_filter` | `withheld`, `unknown` | whether the finish reason `content_filter` means withheld output, or only that the server did not understand why the answer ended |
| `models_path` | `/models`, or another path under the base URL | where the model list is |
| `model_id_field` | `id`, or another field name | the field of an entry of the model list that goes into a request's `model` |

Set `forced_tool_call` only to say `none` for a model that cannot be forced; any other value must be the one
`named_tool_choice` gives, or the preset fails as `not_configured`.

**Reasoning that must go back.** Some servers want the reasoning of an answer back in the next round of a tool
loop. With `message_field` the reasoning is a string beside the content, and it goes back in the same field. With
`content_entries` the content of the answer is a list of entries, and some are no text (reasoning, a citation, a kind
of entry the adapter does not know): the adapter keeps the whole list, as the JSON text the server wrote, and sends
it back as the content of that assistant message. Either way the data travels in the opaque part of the answer, the
text of the answer is only its text entries, and you do nothing but use `$answer->toMessage()` for the next round. A
message whose text or tool calls you changed goes back without the data, built from what it holds now. In a stream
the list arrives in pieces; the adapter joins them into one list before it gives the answer, and no event of the
stream holds reasoning. A list of more than 1 MiB or of more than 10,000 objects and lists is not kept: the answer
then comes without the data. A part is only as trustworthy as the transcript it comes from. The library holds no
secret, so a part with this adapter's mark that someone built by hand can put any list of entries into the content
of its own assistant message, also kinds of entry the provider acts on; if you keep transcripts where others can
write, check them before you pass them on.

**When `when_fits` sends `strict: true`.** Only when every part of your schema is something the provider's
documentation names as accepted. For `standard`: the keywords `type`, `properties`, `required`,
`additionalProperties: false`, `items`, `enum`, `const`, `anyOf` (not at the root), `$defs` and `$ref` to `#` or
`#/$defs/…`, `pattern`, `format` (`date-time`, `time`, `date`, `duration`, `email`, `hostname`, `ipv4`, `ipv6`,
`uuid`), `multipleOf`, `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `minItems`, `maxItems`; every
property required and every object closed; at most 10 levels and 5000 properties. `narrow` is the same without
`pattern`, `format`, `minimum`, `maximum`, `multipleOf`, `minItems` and `maxItems`, with at most 5 levels and 100
properties. Any other keyword makes the flag `false`, also `description`, `title`, `default`, `minLength` and
`oneOf`: the documentation the library was built from does not name them as accepted. The answer is checked against
your whole schema either way.

What the adapter reads without an option, for every server: an answer whose `content` is a string, `null` or a list
of entries; tool-call arguments as a string that holds JSON or as an object; a tool call by its presence, whatever
finish reason came with it; reasoning beside the answer, which never becomes answer text; the usage wherever a
stream puts it; a stream with or without its closing `data: [DONE]`; and four shapes of an error body (an `error`
object, a flat object with a type and a code, a flat object whose `error` is a word, and a body with only a `detail`,
as a sentence or as a list with an item per refused field). Only the provider's code is taken from an error body,
never its sentences. A status 429
whose body says the account is out of credit ends as `rejected` with the provider's code and is not tried again; one
that says the provider is overloaded ends as `unavailable`. A finish reason that means "tool calls" on an answer
without a tool call is given as `other`.

**The Messages adapter.** It sends a request to `{base URL}/v1/messages` and reads the model list at
`{base URL}/v1/models`, one page of at most 1000 models. What it sends is a closed list of fields: `model`,
`max_tokens`, `system`, `messages`, `tools`, `tool_choice`, `output_config` (the schema of a response format),
`temperature` and `stream`. Every request carries the header `anthropic-version`. The key goes as a bearer, or in the
header your preset names in `keyHeader`. A tool name must be letters, digits, underscores and hyphens, at most 128
characters; another name is refused as `rejected` before anything is sent.

A preset on this adapter must set two options of its own, beside the capability options:

| Option | Values | What it changes |
|---|---|---|
| `api_version` | a date, such as `2026-10-21` or `2023-06-01`; no default | the value of the `anthropic-version` header |
| `default_max_output_tokens` | a whole number of 1 or more; no default | the `max_tokens` of a request that gives no maximum of output tokens. The format requires that field, so the adapter always sends one: the maximum of your request, or this number |

A preset that leaves one of the two out fails as `not_configured` the first time the gateway is asked for
capabilities, a call or the model list, also for a request that gives its own maximum: the library holds no version
and no number of its own. A value outside its list fails the same way, and nothing is sent. So does an option whose
name the adapter does not know. Both options can be set per model with a `ModelOverride`.

Its defaults, for a preset that sets only these two: every capability is native, also tools together with a response
format, and a temperature is sent. Set `'temperature' => 'not_accepted'` for the API version `2026-10-21`, on which
the provider refuses a temperature for every model: a request with a temperature is then refused as `not_supported`
before anything is sent.

What it does with a transcript:

- The system messages go into the one top-level `system` field, joined by a blank line.
- Neighbouring messages of the same role become one message. The format has no role for a tool result: the tool
  results of one round become one `user` message, a block per result, before any text of that message.
- A transcript that ends with an assistant message is refused as `rejected` before anything is sent. The format
  reads such a message as the start of the answer, and current models refuse that. So is a transcript that holds
  only system messages: the format needs a message beside its system text.
- A tool call goes back with its arguments as an object. A call whose arguments could not be read goes back with an
  empty object, because the format takes nothing else there; your tool result tells the model what went wrong.

**Thinking that must go back.** An answer of this format can hold thinking blocks, with a signature, before its text
and its tool calls. The provider wants them back unchanged in the next round of a tool loop. The adapter never gives
thinking as answer text, and no stream event holds it. It keeps the whole list of content blocks, as the JSON text the
server wrote, in the opaque part of the answer, and sends that list back as the content of that assistant message.
You do nothing but use `$answer->toMessage()` for the next round. A message whose text or tool calls you changed goes
back without the list, built from what it holds now; the provider may then refuse the request, which you see as
`rejected`. In a stream the blocks arrive in pieces; the adapter joins them into the same list before it gives the
answer. The adapter keeps a list only when every block in it is one of the four kinds the provider documents for
text chat (text, a tool call, thinking, redacted thinking) with exactly the members the provider documents for that
kind. A content with another kind of block, with a member the adapter does not know, with an object that holds the
same name twice, or of more than 1 MiB or more than 10,000 objects and lists is not kept: the answer then comes
without the data, and its text and tool calls are read all the same. A part is only as trustworthy as the transcript
it comes from. The library holds no secret, so a part with this adapter's mark that someone built by hand is sent
when it passes that same rule and says what the message says: the same text, and the same tool calls with the same
id, name and arguments. What that someone can then still choose is what has no other place in the message: the text
of the thinking, its signature and the data of redacted thinking. If you keep transcripts where others can write,
check them before you pass them on.

**A response format.** The format has structured output of its own, and it refuses some JSON Schema keywords. Your
schema is never changed, and the answer is always checked against all of it. What the adapter sends is your schema,
or a looser copy: without `minimum`, `maximum`, `multipleOf`, `minLength`, `maxLength`, `maxItems`, `uniqueItems`,
`not`, and without `minItems` when it is above 1, and with `oneOf` written as `anyOf`. When your schema is one the
format cannot take at all, the adapter puts it into the system text for that one request instead and asks for JSON
in words: a schema that refers back to itself, one with an object that does not say `additionalProperties: false`,
a `$ref` to another document, an `enum` value that is an object or a list, a `pattern` with a lookaround or a
backreference (by number or by name), or a schema in which leaving a keyword out would make it stricter (a refused keyword under `if`, for
example). You see no difference: the capability stays native and the structured result is the same. The name and the
description of a response format are not sent on the native route; the format has no place for them.

What the adapter reads without an option: the text of the text blocks, joined; a tool call per `tool_use` block,
whatever stop reason came with it; the usage with the tokens written to and read from the prompt cache added to the
input; and, in a stream, text and tool arguments in pieces, `ping` events, and events, blocks and deltas of a kind
it does not know, which it skips. A stream that ends without its last event fails as `provider_error`, with the text
that arrived. An `error` event inside a stream ends it with the reason of its type: `unavailable` when the provider
is overloaded, `rate_limited` for a rate limit, `provider_error` otherwise. The finish reason `refusal` means the
model declined; such an answer may have no text. From an error body only the provider's code is taken (its error
code when it has one, otherwise its type), never its sentences. A status 429 whose code says the spend cap of the
account was reached ends as `rejected` and is not tried again. A failed response with the header
`x-should-retry: false` is not tried again and tells no wait.

**The generate-content adapter.** It sends a request to `{base URL}/models/{model}:generateContent`, a stream to
`{base URL}/models/{model}:streamGenerateContent?alt=sse`, and reads the model list at
`{base URL}/models?pageSize=1000`: one page, and it never asks for a next one. The base URL carries everything
before `/models`, so the same adapter serves both ways in the provider has: they differ in the base URL, in how a
request signs in and in the options of the preset. What it sends is a closed list of fields: `systemInstruction`,
`contents`, `tools`, `toolConfig`, `generationConfig` (the maximum of output tokens, the temperature and the schema
of a response format) and `store`. The key goes in the header your preset names in `keyHeader`, a token as a bearer;
neither is ever written into the address.

The model stands in the path of the address. So a model name must be letters, digits, dots, underscores and
hyphens, starting with a letter or a digit, without two dots in a row and at most 128 characters; another name
(with a slash, a colon, a question mark, a percent sign, a whole address) is refused as `rejected` before anything
is sent. A tool name must
start with a letter or an underscore and hold only letters, digits and underscores, at most 64 characters; another
name is refused the same way.

A preset on this adapter can set two options of its own, beside the capability options:

| Option | Values (default first) | What it changes |
|---|---|---|
| `structured_output_field` | `response_json_schema`, `response_format` | where the schema of a response format is written: in `generationConfig.responseJsonSchema` with `responseMimeType: "application/json"`, or in `generationConfig.responseFormat.text` with `mimeType` and `schema`. The provider's pages do not agree on the field, so it is an option |
| `store_field` | `omit`, `false` | whether `store: false` is sent. Only one of the two ways in documents the field |

A value outside its list fails as `not_configured` the first time the gateway is asked for capabilities, a call or
the model list, and nothing is sent. So does an option whose name the adapter does not know. Both options can be set
per model with a `ModelOverride`.

Its defaults, for a preset that sets nothing: every capability is native, except tools together with a response
format, which is emulated: the tools are sent and the schema goes into the system text. The provider documents
both in one request for some of its models only; set `'tools_with_structured_output' => 'native'` in a
`ModelOverride` for a model you know takes both.

What it does with a transcript:

- The system messages go into the one `systemInstruction` field, joined by a blank line. A transcript that holds
  only system messages is refused as `rejected` before anything is sent.
- A user message is a content of the role `user`, an assistant message one of the role `model`. The format has no
  role for a tool result: the tool results that follow one assistant message become one `user` content, a part per
  result, in the order of the calls of that message, each with the name of its tool and your text as its output.
- A tool call goes back with its arguments as an object. A call whose arguments could not be read goes back with an
  empty object, because the format takes nothing else there; your tool result tells the model what went wrong.
  Arguments you wrote yourself as a text that holds the same name twice go back as they were decoded, not as that
  text.
- The format does not always give a tool call an id. A call without one gets an id from the adapter (`call-0`,
  `call-1`, by its place in the answer), which you use as every other id. The adapter does not send such an id
  back, not with the call and not with its result: the result goes back with the name of its tool, in the place
  of its call. An id the server gave goes back as it came.

**Tool calls and thought signatures that must go back.** An answer of this format can carry a signature on a part:
an opaque value the provider wants back, in the same part, with the next round of a tool loop. And a tool call of
this format has an id of the server or none, which the neutral tool call cannot say. The adapter never gives a
signature, or a thought, as answer text, and no stream event holds it. For every answer that holds a signature or a
tool call it keeps the whole list of parts, as the JSON text the server wrote, in the opaque part of the answer, and
sends that list back as the parts of that one turn. You do nothing but use `$answer->toMessage()` for the next
round. In a stream the parts arrive chunk by chunk; the adapter puts them together into the list of the whole
answer.

When the skip value is sent: the provider documents the value `skip_thought_signature_validator` in place of a
signature, for a tool call the model did not produce. The adapter sends it, on the first tool call of an assistant
message, exactly when that message goes back without the list of parts that came with it: a message you built by
hand, a message whose text or tool calls you changed, a message of another provider, a message whose opaque part
you dropped, and an answer whose parts the adapter did not keep (see below). It is never sent with an answer that
goes back as `$answer->toMessage()` gave it and whose parts were kept. Such a message is built from what it holds
now, and an id of the form `call-0` in it is taken for one the adapter made and is not sent.

The adapter keeps a list only when every part in it is one of the two kinds the provider documents for text chat
(text, a tool call) with exactly the members the provider documents. Parts of another kind, a member the adapter
does not know, a tool call whose arguments could not be read, an object that holds the same name twice, or a list
of more than 1 MiB or more than 10,000 objects and lists is not kept: the answer then comes without the data, and
its text and tool calls are read all the same. A part is only as trustworthy as the transcript it comes from. The
library holds no secret, so a part with this adapter's mark that someone built by hand is sent when it passes that
same rule and says what the message says: the same text, and the same tool calls in the same order, each with the
same id, the same name and the same arguments. The arguments are compared as JSON text, not as values: a message
whose arguments you wrote again with a number in another spelling (`1.0` as `1`) or with the members in another
order goes back built from what it holds, as a changed message does. What that someone can then still choose is
what has no other place in the message, and nothing else: the value of a signature and the part it stands on;
thoughts, with their text and their place; where the text of the message stands among its tool calls and over how
many parts it is cut; and, for a call whose id is of the form `call-0`, whether the server gave that id. If you
keep transcripts where others can write, check them before you pass them on.

**A response format.** The format has structured output of its own, and the provider says it ignores the JSON
Schema keywords it does not support. So the adapter sends your schema as it is, takes nothing out and never leaves
the native route for a keyword. The answer is always checked against all of your schema by the library: a keyword
the provider ignored is still held. A schema the provider finds too large or too deep comes back as `rejected`.
The name and the description of a response format are not sent on the native route.

What the adapter reads without an option: the text of the parts that are no thought, joined; a tool call per
`functionCall` part, wherever it stands; a finish reason in both spellings the provider's pages use (`STOP` and
`FINISH_REASON_STOP`); the usage, with the tokens of the thoughts added to the output; and, in a stream, a chunk
without an answer in it, a chunk with only the usage, and a part of a kind it does not know, which it skips. The
format has no finish reason for a tool call: an answer that holds one ends as `tool_calls`. A stream of this format
has no last event: it is complete when its body ends. A prompt the provider refused arrives as a successful
response without an answer; it ends as `rejected`, with the provider's reason as the code. An answer the provider
withheld is an answer with the finish reason `content_filter`, and may have no text. A finish reason that says the
answer itself is a fault (a tool call the model garbled, for example) ends as `provider_error` with that word as
the code. So does an answer with a tool call that names no tool. From an error body only the provider's code is taken (the first reason among its details, otherwise its
status word), never its sentences. A status 400 whose body says the key is not valid ends as
`credentials_rejected`. A status 402 ends as `rejected` and is not tried again. The format documents no sign that
forbids a retry and no wait.

**A credential a server repeats.** For every adapter built on the adapter base: when a server repeats the key or the
token it was sent, the model name of the answer falls back to the model of the request, an entry of the model list
that holds it is skipped, and an answer with a tool call that holds it fails as `provider_error`, in a stream as the
failed event.

### (c) A new format

Write your own adapter: a class that extends `Adapter\Shared\AbstractAdapter`. You write how the format looks. The
base does everything that is the same for every format.

**What the base does for you:**

- the sign-in header, for every attempt;
- the settings of the customer that the preset sends as a header;
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
| `retryAllowed($response, $reason)` | Optional. Returns `false` for a failed response that itself says "do not retry". The call then fails at once with its reason. Asked only for `rate_limited` and `unavailable`. |

**The helpers you use:** `$this->url($path, $query)` builds an address; `$this->answer(...)` builds an answer;
`$this->failure($reason, $detail, $providerCode)` builds an error to throw; `$this->setting($key)` reads a setting of
the customer; `$this->option($name)` reads an adapter option of the preset, and `$this->option($name, $model)` reads
it as the model overrides say it for that model; both give `null` when the option is not set.
`$this->capabilitiesFromOptions($model, $formatDefaults)` reads the capability options of the preset and its model
overrides over the defaults of your format: end `modelCapabilities()` with it. `$this->preset` holds the preset.

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
- Do not set a header yourself that a field of the preset fills. The base adds it, and refuses a request that
  already has it.
- Do not name a method `option` or `capabilitiesFromOptions`: the base has them, and they are final.
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
        // The defaults of this format, the same for every model. The capability options of the preset and of its
        // model overrides are read over them: a preset can say that its server lists no models ('model_listing'
        // => 'none') or that one model cannot call tools. This example emulates nothing, so a preset for it must
        // not say "emulated".
        return $this->capabilitiesFromOptions($model, new Capabilities(
            toolCalling: Support::Native,
            forcedToolCall: Support::Native,
            parallelToolCalls: Support::Native,
            structuredOutput: Support::Native,
            toolsWithStructuredOutput: Support::Native,
            streaming: Support::Native,
            modelListing: Support::Native,
            contextLength: null,
        ));
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
        // An option of the preset itself, the same for every model; null when the preset does not set it. The same
        // words as capabilities() takes, and the same refusal for any other value: nothing is sent then.
        return match ($this->option('model_listing')) {
            null, 'native' => true,
            'none' => false,
            default => throw $this->failure(ErrorReason::NotConfigured, 'the option model_listing of the preset has a value outside its list'),
        };
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
            'refused' => FinishReason::Refusal,
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

A format with structured output of its own often takes only a part of JSON Schema. `Adapter\Shared\SchemaShape`
answers what an adapter then needs to know about the caller's schema, with the adapter's own lists of keywords:
`uses($schema, $keywords)` and `valuesOf($schema, $keyword)` to find what the server refuses,
`without($schema, $keywords)` and `renamed($schema, $keyword, $as)` for a copy to send instead, and `isRecursive()`,
`everyPropertyIsRequired()`, `everyObjectIsClosed()`, `everyObjectHasARequiredProperty()`, `depth()` and
`propertyCount()` for the rules on its shape. A copy may be looser than the caller's schema, never tighter, and
taking a keyword out does not always loosen: without `minimum`, a schema that had it under `not` refuses more than
before. Ask `mayTighten($schema, $keywords)` first; when it says true, do not send the copy and use the emulation
above for that request. The base checks the answer against the caller's schema in full, whatever was sent.

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
tool call, a garbled tool call, a structured answer that matches and one that does not, a refusal and a withheld
answer, a stream, every error reason, retries and waits, the timeout, data that must go back unchanged, and no key in
any output. An adapter passes
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

    public function retryForbidden(HttpResponse $failed): HttpResponse|NotApplicable
    {
        return new NotApplicable('no response of this format says that a request must not be tried again');
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
            ContractFixture::Refused => 'refused',
            ContractFixture::Withheld => 'withheld',
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

The files this class reads: for each of the ten names above one `.json` (the whole response) and one `.sse` (the
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

- A gateway that can do everything and streams natively skips seven: the six tests of a refusal (tools, a forced
  tool call, a response format, tools with a response format, a temperature, the model list), because there is
  nothing to refuse, and the test of a gateway without streaming.
- Fixtures can answer `NotApplicable` for an error inside a successful response, for a request the format cannot
  carry, for a response that forbids a retry, for an answer with opaque data, for a refusal and for a withheld
  answer. That scenario is then skipped with the reason they give. The example above has a fixture for the first
  two, for a refusal and for a withheld answer. Its format has no opaque data and no way to forbid a retry: it skips
  those seven, the five tests of an answer with opaque data and the one test of a response that forbids a retry.
- A gateway that lacks a capability skips the scenarios that need it. Its refusal test runs in their place.
- One more test is skipped when PHP leaves the arguments out of traces (`zend.exception_ignore_args`, which
  `php.ini-production` switches on). Set it to 0 for the tests, with
  `<ini name="zend.exception_ignore_args" value="0"/>` in your `phpunit.xml`.

A fixture that is missing for something your gateway says it can do is a failure, not a skip. A server with other
capabilities is another test class with another preset. A server without sign-in overrides `signsIn()`; a server that
reports no token counts overrides `reportsUsage()`. An adapter that keeps data of its format with every answer that
holds a tool call, also a plain one, overrides `keepsDataWithEveryToolCall()`: the set then allows an opaque part
on such an answer.

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
- An answer without text and without a tool call is returned when its finish reason is `length`, `refusal` or
  `content_filter`, as a real gateway returns it. With any other finish reason it is a `provider_error`.
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
