<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Cohere;

use JsonException;
use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Adapter\Shared\RawJson;
use OrganiseYou\LlmGateway\Adapter\Shared\StructuredOutputEmulation;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolChoiceMode;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use SensitiveParameter;

/**
 * The adapter for the Chat v2 format: `POST {base}/v2/chat`, with messages of the roles `system`, `user`,
 * `assistant` and `tool`, tool calls beside the content of an answer, and a stream of named events on the same
 * address. Every fact about the format is from `docs/providers/cohere.md`; the design is
 * `docs/design/adapters.md`, section 4.3 with 5 and 10.
 *
 * What it sends is a closed list: `model`, `messages`, `stream` (always, as `true` or `false`), `tools`,
 * `tool_choice`, `response_format`, `max_tokens` and `temperature`. Nothing else, ever: no `safety_mode`, no
 * `documents`, no `citation_options`, no `thinking`, no `priority`, no `strict_tools`, and no header that names
 * the caller.
 *
 * The format has no way to name the one tool a model must call: the adapter then sends that tool alone and says a
 * tool must be called. It refuses a response format together with tools: the adapter then sends the tools and puts
 * the schema into the system text. Neither shows to a caller, except through the capabilities.
 *
 * The tool plan of an answer, and the thinking in its content, never become answer text. They are kept in the
 * opaque part of the answer, as the JSON text the server wrote, and go back with that one assistant message
 * (ToolPlan). An assistant message without them (built by hand, changed, or of another adapter) is built from its
 * neutral fields.
 */
final readonly class ChatV2Adapter extends AbstractAdapter
{
    protected const string OPAQUE_FORMAT = 'a4';

    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** A call cannot be handed on without the tool it asks for, and an answer without the call would look final. */
    private const string NAMELESS_CALL = 'a tool call of the answer names no tool';

    private const string FAILED_GENERATION = 'the provider says it could not make the answer';

    // ---------------------------------------------------------------------------------------------------------
    // Capabilities.
    // ---------------------------------------------------------------------------------------------------------

    protected function modelCapabilities(string $model): Capabilities
    {
        // Read for what it refuses: a preset with an option this adapter cannot honour can do nothing.
        $this->options($model);

        // The defaults of the format: everything native, except one named tool, which the format cannot name, and
        // tools with a response format in one request, which it refuses.
        return $this->capabilitiesFromOptions($model, new Capabilities(
            toolCalling: Support::Native,
            forcedToolCall: Support::Emulated,
            parallelToolCalls: Support::Native,
            structuredOutput: Support::Native,
            toolsWithStructuredOutput: Support::Emulated,
            streaming: Support::Native,
            modelListing: Support::Native,
            contextLength: null,
        ));
    }

    // ---------------------------------------------------------------------------------------------------------
    // The request.
    // ---------------------------------------------------------------------------------------------------------

    protected function completionRequest(#[SensitiveParameter] Request $request, bool $stream): HttpRequest
    {
        $options = $this->options($request->model);
        $can = $this->modelCapabilities($request->model);

        // The response format goes to the server as a schema when the server takes one and can take this one, and
        // into the system text otherwise: always beside tools, unless the preset says the server takes both.
        $format = $request->responseFormat;
        $schema = null;
        $instructions = null;
        if ($format !== null) {
            $asAField = $can->structuredOutput === Support::Native
                && ($request->tools === [] || $can->toolsWithStructuredOutput === Support::Native);
            $schema = $asAField ? OutputSchema::forTheNativeMode($format->schema) : null;
            if ($schema === null) {
                $instructions = (new StructuredOutputEmulation())->instructions($format);
            }
        }

        // The messages are written one by one, further down: one of them may hold content that is JSON text already.
        $body = ['model' => $request->model, 'messages' => null];
        if ($request->tools !== []) {
            $body += self::toolFields($request);
        }
        if ($schema !== null) {
            $body['response_format'] = ['type' => 'json_object', $options->schemaKey => $schema->toObject()];
        }
        if ($request->maxOutputTokens !== null) {
            $body['max_tokens'] = $request->maxOutputTokens;
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        // One address serves the whole answer and the stream: the field says which, and is always written.
        $body['stream'] = $stream;

        try {
            $members = [];
            foreach ($body as $name => $value) {
                $members[$name] = json_encode($value, self::JSON);
            }
            $members['messages'] = RawJson::list($this->messages($request, $instructions));
            $json = RawJson::object($members);
        } catch (JsonException) {
            throw $this->failure(ErrorReason::Rejected, 'the request holds a value that cannot be written as JSON');
        }

        return new HttpRequest('POST', $this->url('/v2/chat'), ['content-type' => 'application/json'], [], $request->timeoutSeconds, $json);
    }

    /**
     * The messages in the shape of the format, each as JSON text. The system messages come first, one message
     * each; the instructions for a structured answer, when there are any, are the last of the system text. A tool
     * result is a message of its own, bound to its call by the id of the call.
     *
     * @return list<string>
     * @throws JsonException when a message holds a value that cannot be written as JSON
     */
    private function messages(#[SensitiveParameter] Request $request, #[SensitiveParameter] ?string $instructions): array
    {
        $system = [];
        $others = [];
        foreach ($request->messages as $message) {
            if ($message->role === Role::System) {
                $system[] = json_encode(['role' => 'system', 'content' => $message->text()], self::JSON);

                continue;
            }
            $others[] = match ($message->role) {
                Role::User => json_encode(['role' => 'user', 'content' => $message->text()], self::JSON),
                Role::Assistant => $this->assistantMessage($message),
                Role::Tool => json_encode(['role' => 'tool', 'tool_call_id' => (string) $message->toolCallId, 'content' => $message->text()], self::JSON),
            };
        }
        if ($instructions !== null) {
            $system[] = json_encode(['role' => 'system', 'content' => $instructions], self::JSON);
        }

        return [...$system, ...$others];
    }

    /**
     * An assistant message as JSON text: its text and its tool calls, built from the neutral fields, and with them
     * the plan and the content that came with the answer when this adapter sealed them with this message and the
     * message still says what they say.
     *
     * @throws JsonException when the message holds a value that cannot be written as JSON
     */
    private function assistantMessage(#[SensitiveParameter] Message $message): string
    {
        $text = $message->text();
        $members = ['role' => '"assistant"'];

        // Two strings at most are taken from the part, each for the one member of this one message it is named for.
        $sealed = $this->unseal($message);
        $kept = $sealed === null ? null : ToolPlan::agreeing($sealed, $text);
        if (($kept['tool_plan'] ?? null) !== null) {
            $members['tool_plan'] = $kept['tool_plan'];
        }
        if (($kept['content'] ?? null) !== null) {
            $members['content'] = $kept['content'];
        } elseif ($text !== '' || $message->toolCalls === []) {
            // The format lets the content of a message that only calls tools be absent.
            $members['content'] = json_encode($text, self::JSON);
        }

        $calls = [];
        foreach ($message->toolCalls as $toolCall) {
            $calls[] = json_encode([
                'id' => $toolCall->id,
                'type' => 'function',
                'function' => ['name' => $toolCall->name, 'arguments' => self::arguments($toolCall)],
            ], self::JSON);
        }
        if ($calls !== []) {
            $members['tool_calls'] = RawJson::list($calls);
        }

        return RawJson::object($members);
    }

    /**
     * The arguments of a tool call that goes back: the text as it arrived, also when it could not be read, because
     * the format carries the arguments as a string. Not a JSON text with a member name twice: the server may read
     * its first value where this library read the last, so such a text goes out as the arguments were decoded. A
     * call without a text goes out with its decoded arguments too.
     *
     * @throws JsonException when the arguments cannot be written as JSON
     */
    private static function arguments(#[SensitiveParameter] ToolCall $toolCall): string
    {
        $raw = $toolCall->rawArguments;
        if ($raw !== '' && ! (json_validate($raw) && RawJson::hasRepeatedName($raw))) {
            return $raw;
        }
        if ($toolCall->arguments === null || $toolCall->arguments === []) {
            return '{}';
        }

        return json_encode($toolCall->arguments, self::JSON);
    }

    /**
     * `tools`, and `tool_choice` when it is not "auto". Asked only for a request that has tools. The format has two
     * words, "a tool must be called" and "no tool may be called", and no way to name a tool: one named tool is
     * forced by sending that tool alone with the first word.
     *
     * @return array<string, mixed>
     */
    private static function toolFields(#[SensitiveParameter] Request $request): array
    {
        $choice = $request->toolChoice;

        $fields = ['tools' => []];
        foreach ($request->tools as $tool) {
            if ($choice->mode === ToolChoiceMode::Tool && $tool->name !== $choice->toolName) {
                continue;
            }
            $fields['tools'][] = ['type' => 'function', 'function' => ['name' => $tool->name, 'description' => $tool->description, 'parameters' => $tool->parameters->toObject()]];
        }

        $word = match ($choice->mode) {
            ToolChoiceMode::Auto => null,
            ToolChoiceMode::None => 'NONE',
            ToolChoiceMode::Required, ToolChoiceMode::Tool => 'REQUIRED',
        };
        if ($word !== null) {
            $fields['tool_choice'] = $word;
        }

        return $fields;
    }

    // ---------------------------------------------------------------------------------------------------------
    // The answer.
    // ---------------------------------------------------------------------------------------------------------

    protected function readCompletion(#[SensitiveParameter] HttpResponse $response, #[SensitiveParameter] Request $request): Answer
    {
        $body = ErrorBody::decoded($response->body);
        // An object by the mark it starts with: decoded, an empty object cannot be told from an empty list.
        if ($body === null || ($response->body[strspn($response->body, " \t\n\r")] ?? '') !== '{') {
            throw $this->failure(ErrorReason::ProviderError, 'the body is not a JSON object');
        }

        // A generation that failed arrives under a successful status, as a finish reason.
        $stopWord = Reading::string($body, 'finish_reason');
        $fault = Reading::faultOf($stopWord);
        if ($fault !== null) {
            throw $this->failure(ErrorReason::ProviderError, self::FAILED_GENERATION, $fault);
        }

        $message = $body['message'] ?? null;
        if (! is_array($message) || ($message !== [] && array_is_list($message))) {
            throw $this->failure(ErrorReason::ProviderError, ErrorBody::isIn($body) ? 'the response holds an error' : 'the response holds no answer');
        }

        $text = self::textOf($message['content'] ?? null);

        $entries = Reading::callsIn($message['tool_calls'] ?? null);
        // The ids the calls have, so that a call without one gets an id no other call of the answer has.
        $taken = [];
        foreach ($entries as $entry) {
            if (Reading::string($entry, 'id') !== '') {
                $taken[Reading::string($entry, 'id')] = true;
            }
        }
        $calls = [];
        foreach ($entries as $entry) {
            $function = Reading::members($entry, 'function');
            if (Reading::string($function, 'name') === '') {
                throw $this->failure(ErrorReason::ProviderError, self::NAMELESS_CALL);
            }
            $id = Reading::freeId(count($calls), $taken, Reading::string($entry, 'id'));
            $taken[$id] = true;
            $calls[] = Reading::toolCall($id, Reading::string($function, 'name'), $function['arguments'] ?? null);
        }

        return $this->answer(
            $request,
            $text,
            $calls,
            Reading::finishReason($stopWord, $calls !== []),
            Reading::usage($body),
            null,
            null,
            $this->sealed($text, $calls, $response->body, $message),
        );
    }

    /**
     * The text of an answer: the `text` blocks of its content, joined. A `thinking` block is no text of the answer,
     * and a block of a kind this adapter does not know is read past. A content that is a string, which the format
     * documents for a request only, is that text.
     */
    private static function textOf(#[SensitiveParameter] mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        $text = '';
        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= Reading::string($block, 'text');
            }
        }

        return $text;
    }

    protected function readStream(#[SensitiveParameter] iterable $events, #[SensitiveParameter] Request $request): iterable
    {
        $text = '';
        $stopWord = null;
        $usage = null;
        $finished = false;

        // The kind of the block every index started with, and the tool calls by the index they started at.
        $kinds = [];
        $calls = [];
        $last = null;
        $toolCalls = [];
        // The ids of the calls so far, as a set: a stream of many calls is not walked again for every call. And the
        // ids the adapter made up for a call without one: a later call whose server gives the same id yields.
        $taken = [];
        $madeUp = [];

        // The plan and the content, joined from the pieces of the events, for an answer that holds either.
        $pieces = ToolPlan::none();

        foreach ($events as $event) {
            $data = json_decode($event->data, true);
            if (! is_array($data) || array_is_list($data)) {
                continue;
            }
            $type = is_string($data['type'] ?? null) ? $data['type'] : (string) $event->event;
            $index = Reading::count($data['index'] ?? null);
            $delta = Reading::members($data, 'delta');
            $message = Reading::members($delta, 'message');

            // The error body of the format as an event that names no type: no page documents it, and it is no answer.
            if ($type === '' && ErrorBody::isIn($data)) {
                throw $this->failure(ErrorReason::ProviderError, 'the stream holds an error');
            }

            if ($type === 'message-end') {
                $stopWord = Reading::string($delta, 'finish_reason');
                $fault = Reading::faultOf($stopWord);
                // A failure during the generation arrives in the last event, as an error message or as a finish reason.
                if (self::holdsAnError($delta)) {
                    throw $this->failure(ErrorReason::ProviderError, 'the stream ended with an error', $fault);
                }
                if ($fault !== null) {
                    throw $this->failure(ErrorReason::ProviderError, self::FAILED_GENERATION, $fault);
                }
                $usage = Reading::usage($delta);
                $finished = true;

                break;
            }

            if ($type === 'content-start' && $index !== null && isset($kinds[$index])) {
                // A second start of an index gives no text, and ends the keeping of the content: which of the two
                // a server means cannot be told.
                ToolPlan::start($pieces, $index, $kinds[$index], '');
            } elseif ($type === 'content-start' && $index !== null) {
                $block = Reading::members($message, 'content');
                $kinds[$index] = Reading::string($block, 'type');
                $starts = in_array($kinds[$index], ['text', 'thinking'], true) ? Reading::string($block, $kinds[$index]) : '';
                ToolPlan::start($pieces, $index, $kinds[$index], $starts);

                if ($kinds[$index] === 'text' && $starts !== '') {
                    $text .= $starts;
                    yield new TextDelta($starts);
                }
            } elseif ($type === 'content-delta' && $index !== null && isset($kinds[$index]) && in_array($kinds[$index], ['text', 'thinking'], true)) {
                $piece = Reading::string(Reading::members($message, 'content'), $kinds[$index]);
                if ($piece === '') {
                    continue;
                }
                ToolPlan::add($pieces, $index, $piece);

                if ($kinds[$index] === 'text') {
                    $text .= $piece;
                    yield new TextDelta($piece);
                }
            } elseif ($type === 'tool-plan-delta') {
                ToolPlan::addToPlan($pieces, Reading::string($message, 'tool_plan'));
            } elseif ($type === 'tool-call-start') {
                // A start without an index is a call of its own; the pieces without one that follow belong to it.
                $key = $index ?? 'at-' . count($calls);
                if (isset($calls[$key])) {
                    continue;
                }
                $entry = self::callOf($message);
                if ($entry === null) {
                    // No object of a call: read past, as in a whole answer.
                    continue;
                }
                $function = Reading::members($entry, 'function');
                if (Reading::string($function, 'name') === '') {
                    throw $this->failure(ErrorReason::ProviderError, self::NAMELESS_CALL);
                }
                $position = count($calls);
                $given = Reading::string($entry, 'id');
                if ($given !== '' && isset($madeUp[$given])) {
                    // An id that was handed on is final: the later call gets a suffix to its server's id, and that id
                    // is one the adapter made, which a still later server id yields to as well.
                    $id = Reading::suffixed($given, $taken);
                    $madeUp[$id] = true;
                } else {
                    $id = Reading::freeId($position, $taken, $given);
                    if ($given === '') {
                        $madeUp[$id] = true;
                    }
                }
                $taken[$id] = true;
                $calls[$key] = ['position' => $position, 'id' => $id, 'name' => Reading::string($function, 'name'), 'json' => Reading::string($function, 'arguments'), 'done' => false];
                $last = $key;

                yield new ToolCallStarted($position, $id, $calls[$key]['name']);
            } elseif ($type === 'tool-call-delta' || $type === 'tool-call-end') {
                $key = $index ?? $last;
                if ($key === null || ! isset($calls[$key]) || $calls[$key]['done']) {
                    continue;
                }
                if ($type === 'tool-call-delta') {
                    // The pieces are one JSON text: they are joined here and read once, when the call ends.
                    $calls[$key]['json'] .= Reading::string(Reading::members(self::callOf($message) ?? [], 'function'), 'arguments');

                    continue;
                }
                $calls[$key]['done'] = true;
                $toolCall = Reading::toolCall($calls[$key]['id'], $calls[$key]['name'], $calls[$key]['json']);
                $toolCalls[] = $toolCall;

                yield new ToolCallCompleted($calls[$key]['position'], $toolCall);
            }
            // The end of a block, a citation, a debug event, an event of a type this adapter does not know, a piece
            // for a block or a call that never started: read past.
        }

        // A stream that ends without its last event is not complete: the base ends it as a failure.
        if (! $finished) {
            return;
        }

        // A tool call that never ended completes with what arrived of it.
        foreach ($calls as $call) {
            if (! $call['done']) {
                $toolCall = Reading::toolCall($call['id'], $call['name'], $call['json']);
                $toolCalls[] = $toolCall;

                yield new ToolCallCompleted($call['position'], $toolCall);
            }
        }

        if ($usage !== null && ($usage->inputTokens !== null || $usage->outputTokens !== null)) {
            yield new UsageReported($usage);
        }

        $kept = ToolPlan::sealable($pieces);

        yield new StreamFinished($this->answer(
            $request,
            $text,
            $toolCalls,
            Reading::finishReason($stopWord, $toolCalls !== []),
            $usage ?? Reading::usage([]),
            null,
            null,
            $kept === [] ? null : $this->seal($text, $toolCalls, $kept),
        ));
    }

    /**
     * The tool call an event of a stream carries: one object under `tool_calls`, as the format documents it, or
     * the first object of a list, which it does not; read by the same rule as a whole answer (Reading::callsIn()).
     * Null when it carries none.
     *
     * @param array<mixed> $message
     * @return array<mixed>|null
     */
    private static function callOf(#[SensitiveParameter] array $message): ?array
    {
        return Reading::callsIn($message['tool_calls'] ?? null)[0] ?? null;
    }

    /**
     * Whether the last event of a stream says the generation failed: an error message, or an error object.
     *
     * @param array<mixed> $delta
     */
    private static function holdsAnError(#[SensitiveParameter] array $delta): bool
    {
        $error = $delta['error'] ?? null;

        return (is_string($error) && $error !== '') || (is_array($error) && $error !== []);
    }

    /**
     * The plan and the thinking of a whole answer as its opaque part; null for an answer that holds neither, and
     * for what this adapter does not keep.
     *
     * Each is cut out of the body as the text it is, measured where it stands and brought to one spelling. It is
     * kept only when that text is the value that was read: a second lock, should the cut and the decoder ever part
     * ways.
     *
     * @param list<ToolCall> $toolCalls
     * @param array<mixed> $message the message of the answer, decoded
     */
    private function sealed(
        #[SensitiveParameter]
        string $text,
        #[SensitiveParameter]
        array $toolCalls,
        #[SensitiveParameter]
        string $body,
        #[SensitiveParameter]
        array $message,
    ): ?OpaquePart {
        $kept = [];

        if (Reading::string($message, 'tool_plan') !== '') {
            $written = RawJson::at($body, ['message', 'tool_plan'], ToolPlan::LIMIT_BYTES);
            $plan = $written === null ? null : ToolPlan::plan($written);
            if ($plan !== null && json_decode($plan) === $message['tool_plan']) {
                $kept['tool_plan'] = $plan;
            }
        }
        if (ToolPlan::holdsThinking($message['content'] ?? null)) {
            $written = RawJson::at($body, ['message', 'content'], ToolPlan::LIMIT_BYTES);
            $content = $written === null ? null : ToolPlan::content($written);
            if ($content !== null && json_decode($content, true) === $message['content']) {
                $kept['content'] = $content;
            }
        }

        // Each is held to its own limit above. The limit of the two together is the base's: it measures the part as
        // it is sealed, where the kept text is escaped once more, and drops a part that is over it.
        return $kept === [] ? null : $this->seal($text, $toolCalls, $kept);
    }

    // ---------------------------------------------------------------------------------------------------------
    // The model list.
    // ---------------------------------------------------------------------------------------------------------

    protected function modelsRequest(float $timeoutSeconds): ?HttpRequest
    {
        // The list is one of the gateway, not of a model: the options of the preset itself say how it is asked.
        $this->options(null);

        $listing = $this->option('model_listing') ?? 'native';
        if ($listing === 'none') {
            return null;
        }
        if ($listing !== 'native') {
            throw $this->failure(ErrorReason::NotConfigured, 'the option model_listing of the preset has a value outside its list');
        }

        // One page, the largest the format documents, of the models that chat. A next page is never asked for.
        return new HttpRequest('GET', $this->url('/v1/models', ['endpoint' => 'chat', 'page_size' => 1000]), [], [], $timeoutSeconds);
    }

    protected function readModels(#[SensitiveParameter] HttpResponse $response): array
    {
        $body = ErrorBody::decoded($response->body);
        if ($body === null) {
            throw $this->failure(ErrorReason::ProviderError, 'the body is not JSON');
        }

        $models = ModelList::read($body);
        if ($models === null && ErrorBody::isIn($body)) {
            throw $this->failure(ErrorReason::ProviderError, 'the response holds an error');
        }
        if ($models === null) {
            throw $this->failure(ErrorReason::ProviderError, 'the response holds no model list');
        }

        return $models;
    }

    // ---------------------------------------------------------------------------------------------------------
    // The options.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * The options of this adapter as they are for a model, or as the preset itself has them when no model is given.
     *
     * @throws GatewayException not_configured, when an option has a name this adapter does not know or holds a
     *                          value outside its list; the detail names a known option, never a value
     */
    private function options(#[SensitiveParameter] ?string $model): Options
    {
        $options = $model === null ? $this->preset->adapterOptions : $this->preset->optionsFor($model);

        // The name is whatever the preset wrote: it is not repeated.
        if (Options::hasAnUnknownOption($options)) {
            throw $this->failure(ErrorReason::NotConfigured, 'the preset has an adapter option this adapter does not know');
        }
        $wrong = Options::optionOutsideItsList($options);
        if ($wrong !== null) {
            throw $this->failure(ErrorReason::NotConfigured, 'the option ' . $wrong . ' of the preset has a value outside its list');
        }

        return new Options($options);
    }
}
