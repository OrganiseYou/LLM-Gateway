<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\OpenAiCompatible;

use JsonException;
use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Adapter\Shared\StructuredOutputEmulation;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\ResponseFormat;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoiceMode;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use SensitiveParameter;

/**
 * The adapter for the chat-completions format: `POST {base}/chat/completions` with `messages` in and
 * `choices[].message` out, and a stream of data-only events that ends with `data: [DONE]`. Many servers speak this
 * format, each with its own dialect. A difference that is data is an adapter option of the preset (Dialect); a
 * difference that is a shape is read tolerantly for every server (Reading). Nothing is decided by the id of a
 * preset, and no model is known by name.
 *
 * What it sends is a closed list: `model`, `messages`, `tools`, `tool_choice`, `response_format`, the field of the
 * maximum of output tokens, `temperature`, `stream`, `stream_options` and `store`. Nothing else, ever: no
 * `parallel_tool_calls`, no `n`, no `stop`, no penalties, no seed, no reasoning effort, no field that only one
 * provider has. `tools` and `tool_choice` are left out when the request has no tools.
 *
 * The design: `docs/design/adapters.md`, sections 2, 5 and 10.
 */
final readonly class ChatCompletionsAdapter extends AbstractAdapter
{
    protected const string OPAQUE_FORMAT = 'a1';

    /** The rule for a tool name that every documented server of the format accepts. */
    private const string TOOL_NAME = '/^[A-Za-z0-9_-]{1,64}$/D';

    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    // ---------------------------------------------------------------------------------------------------------
    // Capabilities.
    // ---------------------------------------------------------------------------------------------------------

    protected function modelCapabilities(string $model): Capabilities
    {
        $howAToolIsForced = $this->dialect($model)->namedToolChoice->support();

        // The defaults of the format: everything native, except tools with a response format in one request, which
        // no provider file confirms. A forced tool call follows the way the server takes one.
        $can = $this->capabilitiesFromOptions($model, new Capabilities(
            toolCalling: Support::Native,
            forcedToolCall: $howAToolIsForced,
            parallelToolCalls: Support::Native,
            structuredOutput: Support::Native,
            toolsWithStructuredOutput: Support::Emulated,
            streaming: Support::Native,
            modelListing: Support::Native,
            contextLength: null,
        ));

        // The option forced_tool_call may say "none" for a model that cannot be forced. Any other value must be the
        // one named_tool_choice gives: the adapter cannot force a tool in a way the server does not take.
        if ($can->forcedToolCall !== Support::None && $can->forcedToolCall !== $howAToolIsForced) {
            throw $this->failure(ErrorReason::NotConfigured, 'the option forced_tool_call of the preset does not agree with its option named_tool_choice');
        }

        return $can;
    }

    // ---------------------------------------------------------------------------------------------------------
    // The request.
    // ---------------------------------------------------------------------------------------------------------

    protected function completionRequest(#[SensitiveParameter] Request $request, bool $stream): HttpRequest
    {
        $dialect = $this->dialect($request->model);
        $can = $this->modelCapabilities($request->model);

        // The response format goes to the server as it is when the server takes one, and into the system text when
        // it takes none, or takes none beside tools.
        $format = $request->responseFormat;
        $asAField = $format !== null
            && $can->structuredOutput === Support::Native
            && ($request->tools === [] || $can->toolsWithStructuredOutput === Support::Native);
        $instructions = $format !== null && ! $asAField ? (new StructuredOutputEmulation())->instructions($format) : null;

        $body = ['model' => $request->model, 'messages' => $this->messages($request, $dialect, $instructions)];

        if ($request->tools !== []) {
            $body += $this->toolFields($request, $dialect);
        }
        if ($format !== null && $asAField) {
            $body['response_format'] = self::responseFormat($format, $dialect);
        }
        if ($request->maxOutputTokens !== null) {
            $body[$dialect->outputLimitField] = $request->maxOutputTokens;
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($stream) {
            $body['stream'] = true;
            if ($dialect->asksUsageInAStream) {
                $body['stream_options'] = ['include_usage' => true];
            }
        }
        if ($dialect->sendsStoreFalse) {
            $body['store'] = false;
        }

        try {
            $json = json_encode($body, self::JSON);
        } catch (JsonException) {
            throw $this->failure(ErrorReason::Rejected, 'the request holds a value that cannot be written as JSON');
        }

        return new HttpRequest('POST', $this->url('/chat/completions'), ['Content-Type' => 'application/json'], [], $request->timeoutSeconds, $json);
    }

    /**
     * The messages in the shape of the format. The system messages come first, as one message each or joined to
     * one; the instructions for a structured answer, when there are any, are the last of the system text.
     *
     * @return list<array<string, mixed>>
     */
    private function messages(
        #[SensitiveParameter]
        Request $request,
        Dialect $dialect,
        #[SensitiveParameter]
        ?string $instructions,
    ): array {
        $system = [];
        $others = [];
        foreach ($request->messages as $message) {
            if ($message->role === Role::System) {
                $system[] = $message->text();

                continue;
            }
            $others[] = match ($message->role) {
                Role::User => ['role' => 'user', 'content' => $message->text()],
                Role::Assistant => $this->assistantMessage($message, $dialect),
                Role::Tool => self::toolMessage($message, $dialect),
            };
        }
        if ($instructions !== null) {
            $system[] = $instructions;
        }
        if ($dialect->joinsSystemMessages && $system !== []) {
            $system = [implode("\n\n", $system)];
        }

        $messages = [];
        foreach ($system as $text) {
            $messages[] = ['role' => $dialect->systemRole, 'content' => $text];
        }

        return [...$messages, ...$others];
    }

    /**
     * @return array<string, mixed>
     */
    private function assistantMessage(#[SensitiveParameter] Message $message, Dialect $dialect): array
    {
        $text = $message->text();
        $entry = [
            'role' => 'assistant',
            'content' => $text === '' && $message->toolCalls !== [] ? $dialect->contentWithoutText : $text,
        ];

        if ($dialect->reasoningCarrier === ReasoningCarrier::MessageField) {
            $reasoning = $this->reasoningThatCameWith($message);
            if ($reasoning !== null) {
                $entry['reasoning_content'] = $reasoning;
            }
        }

        $calls = [];
        foreach ($message->toolCalls as $toolCall) {
            // The arguments go back as they arrived, also when they could not be read.
            $arguments = $toolCall->rawArguments;
            if ($arguments === '' && $toolCall->arguments !== null) {
                $arguments = $toolCall->arguments === [] ? '{}' : (string) json_encode($toolCall->arguments, self::JSON & ~JSON_THROW_ON_ERROR);
            }
            $calls[] = ['id' => $toolCall->id, 'type' => 'function', 'function' => ['name' => $toolCall->name, 'arguments' => $arguments]];
        }
        if ($calls !== []) {
            $entry['tool_calls'] = $calls;
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toolMessage(#[SensitiveParameter] Message $message, Dialect $dialect): array
    {
        $entry = ['role' => 'tool', 'tool_call_id' => (string) $message->toolCallId];
        if ($dialect->namesToolResults) {
            $entry['name'] = (string) $message->toolName;
        }
        $entry['content'] = $message->text();

        return $entry;
    }

    /**
     * The reasoning this adapter sealed with this message, or null: no part, a part of another adapter, or a
     * message that was changed since. Only a string is taken from the part, and it goes into one field.
     */
    private function reasoningThatCameWith(#[SensitiveParameter] Message $message): ?string
    {
        $sealed = $this->unseal($message);
        if ($sealed === null || array_keys($sealed) !== ['reasoning'] || ! is_string($sealed['reasoning'])) {
            return null;
        }

        $reasoning = json_decode($sealed['reasoning']);

        return is_string($reasoning) && $reasoning !== '' ? $reasoning : null;
    }

    /**
     * `tools`, and `tool_choice` when it is not "auto". Asked only for a request that has tools.
     *
     * @return array<string, mixed>
     */
    private function toolFields(#[SensitiveParameter] Request $request, Dialect $dialect): array
    {
        foreach ($request->tools as $tool) {
            if (preg_match(self::TOOL_NAME, $tool->name) !== 1) {
                throw $this->failure(ErrorReason::Rejected, 'a tool name is not one this format carries: letters, digits, underscores and hyphens, at most 64');
            }
        }

        $choice = $request->toolChoice;
        $tools = $request->tools;
        $toolChoice = match ($choice->mode) {
            ToolChoiceMode::Auto => null,
            ToolChoiceMode::None => 'none',
            ToolChoiceMode::Required => $dialect->requiredWord,
            ToolChoiceMode::Tool => match ($dialect->namedToolChoice) {
                NamedToolChoice::NamedObject => ['type' => 'function', 'function' => ['name' => (string) $choice->toolName]],
                NamedToolChoice::RequiredSingle => $dialect->requiredWord,
                NamedToolChoice::Unsupported => throw $this->failure(ErrorReason::NotSupported, 'this model cannot be made to call one named tool'),
            },
        };
        if ($choice->mode === ToolChoiceMode::Tool && $dialect->namedToolChoice === NamedToolChoice::RequiredSingle) {
            // The server takes no name: it must call some tool, and the named one is the only one it is given.
            $only = [];
            foreach ($tools as $tool) {
                if ($tool->name === $choice->toolName) {
                    $only[] = $tool;
                }
            }
            $tools = $only;
        }

        $fields = ['tools' => []];
        foreach ($tools as $tool) {
            $fields['tools'][] = ['type' => 'function', 'function' => self::toolFunction($tool, $dialect)];
        }
        if ($toolChoice !== null) {
            $fields['tool_choice'] = $toolChoice;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toolFunction(ToolDefinition $tool, Dialect $dialect): array
    {
        $function = ['name' => $tool->name, 'description' => $tool->description, 'parameters' => $tool->parameters->toObject()];
        if ($dialect->writesToolStrictFalse) {
            $function['strict'] = false;
        }

        return $function;
    }

    /**
     * The response format as the format takes it: a JSON Schema with a name. The schema is the caller's, unchanged.
     * `strict` is written as true only when that schema already fits the documented subset.
     *
     * @return array<string, mixed>
     */
    private static function responseFormat(ResponseFormat $format, Dialect $dialect): array
    {
        $jsonSchema = ['name' => $format->name];
        if (trim($format->description) !== '') {
            $jsonSchema['description'] = $format->description;
        }
        $jsonSchema[$dialect->schemaMember] = $format->schema->toObject();

        $strict = match ($dialect->schemaStrictness) {
            SchemaStrictness::Omit => null,
            SchemaStrictness::AlwaysFalse => false,
            SchemaStrictness::WhenFits => (new StrictSubset())->fits($format->schema, $dialect->narrowSubset),
        };
        if ($strict !== null) {
            $jsonSchema['strict'] = $strict;
        }

        return ['type' => 'json_schema', 'json_schema' => $jsonSchema];
    }

    // ---------------------------------------------------------------------------------------------------------
    // The answer.
    // ---------------------------------------------------------------------------------------------------------

    protected function readCompletion(#[SensitiveParameter] HttpResponse $response, #[SensitiveParameter] Request $request): Answer
    {
        $dialect = $this->dialect($request->model);

        $body = ErrorBody::decoded($response->body);
        if ($body === null || array_is_list($body)) {
            throw $this->failure(ErrorReason::ProviderError, 'the body is not a JSON object');
        }
        // An error under a successful status is documented nowhere: it counts only where there is no answer beside it.
        if (ErrorBody::isIn($body) && Reading::members($body, 'choices') === []) {
            throw $this->failure(ErrorReason::ProviderError, 'the response holds an error', ErrorBody::code($body));
        }

        $choice = Reading::members($body, 'choices')[0] ?? null;
        $message = is_array($choice) ? ($choice['message'] ?? null) : null;
        if (! is_array($choice) || ! is_array($message)) {
            throw $this->failure(ErrorReason::ProviderError, 'the response holds no answer');
        }

        $text = Reading::text($message['content'] ?? null);
        if ($dialect->stripsInlineReasoning) {
            $text = InlineReasoning::stripped($text);
        }

        // The calls with a function; one without an id gets an id no other call of the answer has.
        $written = [];
        $taken = [];
        foreach (Reading::members($message, 'tool_calls') as $call) {
            if (is_array($call) && is_array($call['function'] ?? null)) {
                $written[] = $call;
                $taken[] = Reading::string($call, 'id');
            }
        }
        $calls = [];
        foreach ($written as $position => $call) {
            $function = Reading::members($call, 'function');
            $id = Reading::string($call, 'id');
            if ($id === '') {
                $id = Reading::freeId($position, $taken);
                $taken[] = $id;
            }
            $calls[] = Reading::toolCall($id, Reading::string($function, 'name'), $function['arguments'] ?? null);
        }

        // A refusal is the model's own word that it will not answer; it is the text when there is no other.
        $refused = Reading::string($message, 'refusal') !== '';
        if ($refused && $text === '') {
            $text = Reading::string($message, 'refusal');
        }

        $finishWord = is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null;

        return $this->answer(
            $request,
            $text,
            $calls,
            $this->finishReason($finishWord, $calls !== [], $refused, $dialect),
            Reading::usage($choice) ?? Reading::usage($body) ?? new Usage(null, null),
            Reading::string($body, 'model'),
            null,
            $this->sealedReasoning($text, $calls, Reading::reasoning($message), $dialect),
        );
    }

    protected function readStream(#[SensitiveParameter] iterable $events, #[SensitiveParameter] Request $request): iterable
    {
        $dialect = $this->dialect($request->model);

        $text = '';
        $reasoning = '';
        $refusal = '';
        $finishWord = null;
        $model = '';
        $usage = null;

        // The text that is held back while it can still be a block of inline reasoning.
        $held = '';
        $state = $dialect->stripsInlineReasoning ? InlineReasoning::UNDECIDED : InlineReasoning::TEXT;

        // The tool calls, in the order they first appeared: what was read of each, and the id it was announced
        // with once it was. `byIndex` and `byId` say which call a piece of a chunk belongs to.
        $calls = [];
        $byIndex = [];
        $byId = [];

        foreach ($events as $event) {
            if (trim($event->data) === '[DONE]') {
                break;
            }
            $chunk = json_decode($event->data, true);
            if (! is_array($chunk)) {
                continue;
            }
            if (ErrorBody::isIn($chunk) && Reading::members($chunk, 'choices') === []) {
                throw $this->failure(ErrorReason::ProviderError, 'the stream holds an error', ErrorBody::code($chunk));
            }

            if (Reading::string($chunk, 'model') !== '') {
                $model = Reading::string($chunk, 'model');
            }

            // A chunk without a choice may still hold the usage; a choice may hold it too. The last one counts.
            $choice = Reading::members($chunk, 'choices')[0] ?? null;
            $choice = is_array($choice) ? $choice : [];
            $usage = Reading::usage($choice) ?? Reading::usage($chunk) ?? $usage;

            if (is_string($choice['finish_reason'] ?? null)) {
                $finishWord = $choice['finish_reason'];
            }

            $delta = Reading::members($choice, 'delta');
            $reasoning .= Reading::reasoning($delta);
            $refusal .= Reading::string($delta, 'refusal');

            [$state, $held, $piece] = InlineReasoning::feed($state, $held, Reading::text($delta['content'] ?? null));
            if ($piece !== '') {
                $text .= $piece;
                yield new TextDelta($piece);
            }

            foreach (Reading::members($delta, 'tool_calls') as $part) {
                if (! is_array($part)) {
                    continue;
                }
                $function = Reading::members($part, 'function');
                $id = Reading::string($part, 'id');
                $name = Reading::string($function, 'name');
                $arguments = $function['arguments'] ?? null;
                $index = is_int($part['index'] ?? null) ? $part['index'] : null;

                // Which call this piece belongs to: the one of its index; without an index the one of its id;
                // without both, the last.
                $position = match (true) {
                    $index !== null => $byIndex[$index] ?? null,
                    $id !== '' => $byId[$id] ?? null,
                    default => array_key_last($calls),
                };
                // A piece begins a call of its own when it names another id than the call it would join, or
                // names a tool for a call whose arguments already began: a name never comes after them.
                if ($position !== null) {
                    $known = $calls[$position];
                    $argumentsBegan = $known['arguments'] !== '' || $known['whole'] !== null;
                    if (($id !== '' && $known['id'] !== '' && $id !== $known['id']) || ($name !== '' && $argumentsBegan)) {
                        $position = null;
                    }
                }
                if ($position === null) {
                    // The calls before it are whole as far as their id and name go: they are announced now.
                    foreach ($calls as $earlier => $call) {
                        if ($call['announced'] === null) {
                            $calls[$earlier]['announced'] = Reading::freeId($earlier, self::idsOf($calls), $call['id']);
                            yield new ToolCallStarted($earlier, $calls[$earlier]['announced'], $call['name']);
                        }
                    }
                    $position = count($calls);
                    $calls[] = ['id' => '', 'name' => '', 'arguments' => '', 'whole' => null, 'announced' => null];
                }
                if ($index !== null) {
                    $byIndex[$index] = $position;
                }

                // The id and the name are taken, a name in pieces joined, until the call is announced.
                if ($calls[$position]['announced'] === null) {
                    if ($calls[$position]['id'] === '' && $id !== '') {
                        $calls[$position]['id'] = $id;
                        $byId[$id] = $position;
                    }
                    $calls[$position]['name'] .= $name;
                }
                // The arguments arrive as pieces of a text to join, or whole, as a text or as a map.
                if (is_string($arguments)) {
                    $calls[$position]['arguments'] .= $arguments;
                } elseif (is_array($arguments)) {
                    $calls[$position]['whole'] = $arguments;
                }

                // A call is announced when its arguments begin: its id and its name came before them.
                if ($calls[$position]['announced'] === null && ($calls[$position]['arguments'] !== '' || $calls[$position]['whole'] !== null)) {
                    $calls[$position]['announced'] = Reading::freeId($position, self::idsOf($calls), $calls[$position]['id']);
                    yield new ToolCallStarted($position, $calls[$position]['announced'], $calls[$position]['name']);
                }
            }
        }

        // The stream ended, with its last line or with the end of the body.
        $finishReason = $this->finishReason($finishWord, $calls !== [], $refusal !== '', $dialect);

        $rest = InlineReasoning::rest($state, $held);
        if ($text === '' && $rest === '' && $refusal !== '') {
            $rest = $refusal;
        }
        if ($rest !== '') {
            $text .= $rest;
            yield new TextDelta($rest);
        }

        $toolCalls = [];
        foreach ($calls as $position => $call) {
            $id = $call['announced'];
            if ($id === null) {
                $id = Reading::freeId($position, self::idsOf($calls), $call['id']);
                $calls[$position]['announced'] = $id;
                yield new ToolCallStarted($position, $id, $call['name']);
            }
            $toolCall = Reading::toolCall($id, $call['name'], $call['whole'] ?? $call['arguments']);
            $toolCalls[] = $toolCall;
            yield new ToolCallCompleted($position, $toolCall);
        }

        if ($usage !== null) {
            yield new UsageReported($usage);
        }

        yield new StreamFinished($this->answer(
            $request,
            $text,
            $toolCalls,
            $finishReason,
            $usage ?? new Usage(null, null),
            $model,
            null,
            $this->sealedReasoning($text, $toolCalls, $reasoning, $dialect),
        ));
    }

    /**
     * What the end of an answer means. An answer that holds tool calls ends for tool calls unless it was cut off,
     * whatever word the server gave; a refusal of the model is told by its own field.
     */
    private function finishReason(?string $word, bool $hasToolCalls, bool $refused, Dialect $dialect): FinishReason
    {
        if ($word !== null && strtolower($word) === 'error') {
            throw $this->failure(ErrorReason::ProviderError, 'the server ended the answer with an error', 'error');
        }

        $reason = Reading::finishReason($word, $dialect->contentFilterMeansWithheld);
        if ($hasToolCalls) {
            return $reason === FinishReason::Length ? FinishReason::Length : FinishReason::ToolCalls;
        }
        if ($refused) {
            return FinishReason::Refusal;
        }

        // The word for tool calls on an answer that holds none: a caller that loops on that reason would spin.
        return $reason === FinishReason::ToolCalls ? FinishReason::Other : $reason;
    }

    /**
     * The ids the calls of a stream have so far: what the server gave, and what they were announced with.
     *
     * @param array<int, array{id: string, name: string, arguments: string, whole: array<mixed>|null, announced: string|null}> $calls
     * @return list<string>
     */
    private static function idsOf(#[SensitiveParameter] array $calls): array
    {
        $ids = [];
        foreach ($calls as $call) {
            $ids[] = $call['id'];
            $ids[] = (string) $call['announced'];
        }

        return $ids;
    }

    /**
     * The reasoning that came with an answer as its opaque part, for a server that wants it back with the answer;
     * null for every other server and for an answer without reasoning.
     *
     * The part holds the reasoning as JSON text in one string, not as a decoded value: what goes back is then the
     * value that arrived, whatever is done with the part in between.
     *
     * @param list<ToolCall> $toolCalls
     */
    private function sealedReasoning(
        #[SensitiveParameter]
        string $text,
        #[SensitiveParameter]
        array $toolCalls,
        #[SensitiveParameter]
        string $reasoning,
        Dialect $dialect,
    ): ?OpaquePart {
        if ($dialect->reasoningCarrier !== ReasoningCarrier::MessageField || $reasoning === '') {
            return null;
        }

        $json = json_encode($reasoning, self::JSON & ~JSON_THROW_ON_ERROR);

        return $json === false ? null : $this->seal($text, $toolCalls, ['reasoning' => $json]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // The model list.
    // ---------------------------------------------------------------------------------------------------------

    protected function modelsRequest(float $timeoutSeconds): ?HttpRequest
    {
        // The list is one of the gateway, not of a model: the options of the preset itself say where it is.
        $dialect = $this->dialect(null);

        $listing = $this->option('model_listing') ?? 'native';
        if ($listing === 'none') {
            return null;
        }
        if ($listing !== 'native') {
            throw $this->failure(ErrorReason::NotConfigured, 'the option model_listing of the preset has a value outside its list');
        }

        return new HttpRequest('GET', $this->url($dialect->modelsPath), ['Accept' => 'application/json'], [], $timeoutSeconds);
    }

    protected function readModels(#[SensitiveParameter] HttpResponse $response): array
    {
        $body = ErrorBody::decoded($response->body);
        if ($body === null) {
            throw $this->failure(ErrorReason::ProviderError, 'the body is not JSON');
        }
        // Decoded once more, as it was written: only so can an empty object be told from an empty list.
        $isAList = is_array(json_decode($response->body, false));
        if (! $isAList && ErrorBody::isIn($body) && ! is_array($body['data'] ?? null) && ! is_array($body['models'] ?? null)) {
            throw $this->failure(ErrorReason::ProviderError, 'the response holds an error', ErrorBody::code($body));
        }

        $models = ModelList::read($body, $isAList, $this->dialect(null)->modelIdField);
        if ($models === null) {
            throw $this->failure(ErrorReason::ProviderError, 'the response holds no model list');
        }

        return $models;
    }

    // ---------------------------------------------------------------------------------------------------------
    // A failed response.
    // ---------------------------------------------------------------------------------------------------------

    protected function providerCode(#[SensitiveParameter] HttpResponse $response): ?string
    {
        return ErrorBody::code(ErrorBody::decoded($response->body));
    }

    /**
     * A status 429 does not always mean "wait and try again": a body that says the account is out of credit is a
     * refusal that waiting does not lift, and one that says the provider is overloaded is not the customer's
     * limit. A server with other words keeps the reading of its status.
     */
    protected function refineReason(#[SensitiveParameter] HttpResponse $response, ErrorReason $reason): ErrorReason
    {
        if ($response->status !== 429) {
            return $reason;
        }

        $body = ErrorBody::decoded($response->body);

        return match (true) {
            ErrorBody::saysNoCredit($body) => ErrorReason::Rejected,
            ErrorBody::saysOverloaded($body) => ErrorReason::Unavailable,
            default => $reason,
        };
    }

    /**
     * The wait of the `Retry-After` header; without one, the wait of `retry-after-ms`, which is in milliseconds.
     */
    protected function retryAfterSeconds(#[SensitiveParameter] HttpResponse $response): ?float
    {
        $seconds = parent::retryAfterSeconds($response);
        if ($seconds !== null) {
            return $seconds;
        }

        $milliseconds = trim((string) $response->header('retry-after-ms'));

        return preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $milliseconds) === 1 ? (float) $milliseconds / 1000.0 : null;
    }

    // ---------------------------------------------------------------------------------------------------------
    // The options.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * The dialect options as they are for a model, or as the preset itself has them when no model is given.
     *
     * @throws GatewayException not_configured, when an option has a name this adapter does not know, or holds a
     *                          value outside its list; the detail names a known option, never a value
     */
    private function dialect(?string $model): Dialect
    {
        $options = $model === null ? $this->preset->adapterOptions : $this->preset->optionsFor($model);

        // The name is whatever the preset wrote: it is not repeated.
        if (Dialect::hasAnUnknownOption($options)) {
            throw $this->failure(ErrorReason::NotConfigured, 'the preset has an adapter option this adapter does not know');
        }
        $wrong = Dialect::optionOutsideItsList($options);
        if ($wrong !== null) {
            throw $this->failure(ErrorReason::NotConfigured, 'the option ' . $wrong . ' of the preset has a value outside its list');
        }

        return new Dialect($options);
    }
}
