<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Anthropic;

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
 * The adapter for the Messages format: `POST {base}/v1/messages` with a top-level `system`, `messages` of the
 * roles `user` and `assistant` whose content is a list of blocks, and a stream of named events. Every fact about
 * the format is from `docs/providers/anthropic.md`; the design is `docs/design/adapters.md`, section 4.1 with 5 and
 * 10.
 *
 * What it sends is a closed list: `model`, `max_tokens`, `system`, `messages`, `tools`, `tool_choice`,
 * `output_config` (only its `format`), `temperature` and `stream`. Nothing else, ever: no `thinking`, no `metadata`,
 * no `service_tier`, no `inference_geo`, no `stop_sequences`, no flag on a tool, and no assistant message at the
 * end of the transcript, which the format reads as the start of the answer. The headers of its own are the version
 * of the API and the content type.
 *
 * The format requires a maximum of output tokens: a request that gives none is sent with the one of the preset,
 * which every preset on this adapter must set.
 * A temperature is sent only where the capabilities say the model takes one; on the current version of the API
 * the preset says it takes none, and the adapter base then refuses the request before anything is built.
 *
 * Thinking that arrives with an answer never becomes answer text. The list of content blocks it stands in is kept
 * in the opaque part of the answer, as the JSON text the server wrote, and goes back as the content of that one
 * assistant message (ContentBlocks).
 */
final readonly class MessagesAdapter extends AbstractAdapter
{
    protected const string OPAQUE_FORMAT = 'a2';

    /** The rule of the format for a tool name. */
    private const string TOOL_NAME = '/^[a-zA-Z0-9_-]{1,128}$/D';

    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    // ---------------------------------------------------------------------------------------------------------
    // Capabilities.
    // ---------------------------------------------------------------------------------------------------------

    protected function modelCapabilities(string $model): Capabilities
    {
        // Read for what it refuses: a preset with an option this adapter cannot honour can do nothing.
        $this->options($model);

        // The defaults of the format: everything native, also tools with a response format in one request.
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

    // ---------------------------------------------------------------------------------------------------------
    // The request.
    // ---------------------------------------------------------------------------------------------------------

    protected function completionRequest(#[SensitiveParameter] Request $request, bool $stream): HttpRequest
    {
        $options = $this->options($request->model);
        $can = $this->modelCapabilities($request->model);

        $messages = $request->messages;
        $last = $messages[count($messages) - 1];
        if ($last->role === Role::Assistant) {
            throw $this->failure(ErrorReason::Rejected, 'the transcript ends with an assistant message, which this format reads as the start of the answer');
        }
        // System messages come first, so a transcript that ends with one holds nothing else: the format requires
        // messages, and its system text is not one.
        if ($last->role === Role::System) {
            throw $this->failure(ErrorReason::Rejected, 'the transcript holds only system messages, and this format needs a message beside its system text');
        }

        $outputLimit = $request->maxOutputTokens ?? $options->defaultOutputLimit;

        // The response format goes to the server as a schema when the server takes one and can take this one, and
        // into the system text otherwise.
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

        $system = [];
        foreach ($messages as $message) {
            if ($message->role === Role::System) {
                $system[] = $message->text();
            }
        }
        if ($instructions !== null) {
            $system[] = $instructions;
        }

        $body = ['model' => $request->model, 'max_tokens' => $outputLimit];
        if ($system !== []) {
            $body['system'] = implode("\n\n", $system);
        }
        // The messages are written one by one, further down: one of them may hold content that is JSON text already.
        $body['messages'] = null;
        if ($request->tools !== []) {
            $body += $this->toolFields($request);
        }
        if ($schema !== null) {
            $body['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $schema->toObject()]];
        }
        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }
        if ($stream) {
            $body['stream'] = true;
        }

        try {
            $members = [];
            foreach ($body as $name => $value) {
                $members[$name] = json_encode($value, self::JSON);
            }
            $members['messages'] = RawJson::list($this->messages($request));
            $json = RawJson::object($members);
        } catch (JsonException) {
            throw $this->failure(ErrorReason::Rejected, 'the request holds a value that cannot be written as JSON');
        }

        return new HttpRequest('POST', $this->url('/v1/messages'), $this->headers($options) + ['content-type' => 'application/json'], [], $request->timeoutSeconds, $json);
    }

    /**
     * The messages in the shape of the format, each as JSON text. Neighbouring messages that map to the same role
     * are one message. A tool result is a block of a user message: the results of one round stand together in one
     * message, before any text of it.
     *
     * @return list<string>
     * @throws JsonException when a message holds a value that cannot be written as JSON
     */
    private function messages(#[SensitiveParameter] Request $request): array
    {
        $turns = [];
        foreach ($request->messages as $message) {
            if ($message->role === Role::System) {
                continue;
            }

            $role = $message->role === Role::Assistant ? 'assistant' : 'user';
            $last = array_key_last($turns);
            if ($last === null || $turns[$last]['role'] !== $role) {
                $turns[] = ['role' => $role, 'results' => [], 'blocks' => []];
                $last = array_key_last($turns);
            }

            if ($message->role === Role::Tool) {
                $turns[$last]['results'][] = json_encode(['type' => 'tool_result', 'tool_use_id' => (string) $message->toolCallId, 'content' => $message->text()], self::JSON);
            } elseif ($message->role === Role::User) {
                $turns[$last]['blocks'][] = json_encode(['type' => 'text', 'text' => $message->text()], self::JSON);
            } else {
                $turns[$last]['blocks'] = [...$turns[$last]['blocks'], ...$this->assistantBlocks($message)];
            }
        }

        $messages = [];
        foreach ($turns as $turn) {
            $messages[] = RawJson::object([
                'role' => json_encode($turn['role'], self::JSON),
                'content' => RawJson::list([...$turn['results'], ...$turn['blocks']]),
            ]);
        }

        return $messages;
    }

    /**
     * The content blocks of an assistant message, each as JSON text: the list that came with the answer when this
     * adapter sealed one with this message and the message still says what that list says, otherwise a text block
     * and a block per tool call, built from the neutral fields.
     *
     * @return list<string>
     * @throws JsonException when the message holds a value that cannot be written as JSON
     */
    private function assistantBlocks(#[SensitiveParameter] Message $message): array
    {
        $kept = $this->blocksThatCameWith($message);
        if ($kept !== null) {
            return $kept;
        }

        $blocks = [];
        $text = $message->text();
        if ($text !== '' || $message->toolCalls === []) {
            $blocks[] = json_encode(['type' => 'text', 'text' => $text], self::JSON);
        }
        foreach ($message->toolCalls as $toolCall) {
            $blocks[] = RawJson::object([
                'type' => '"tool_use"',
                'id' => json_encode($toolCall->id, self::JSON),
                'name' => json_encode($toolCall->name, self::JSON),
                'input' => self::input($toolCall),
            ]);
        }

        return $blocks;
    }

    /**
     * The list of content blocks this adapter sealed with this message, each block as JSON text, or null: no part,
     * a part of another adapter, a message that was changed since, or a list that is not what this adapter keeps.
     * Only one string is taken from the part, and it stands as the content of this one message and nowhere else.
     *
     * @return list<string>|null
     */
    private function blocksThatCameWith(#[SensitiveParameter] Message $message): ?array
    {
        $sealed = $this->unseal($message);
        if ($sealed === null || array_keys($sealed) !== ['content'] || ! is_string($sealed['content'])) {
            return null;
        }

        $calls = [];
        foreach ($message->toolCalls as $toolCall) {
            $calls[] = [$toolCall->id, $toolCall->name, self::input($toolCall)];
        }

        return ContentBlocks::agreeing($sealed['content'], $message->text(), $calls);
    }

    /**
     * The `input` of a tool call that goes back, as JSON text: always an object, as the format asks. The arguments
     * as they arrived when they are the text of a JSON object; otherwise the decoded arguments; and for a call
     * whose arguments could not be read, an empty object: the format has no other way to carry such a call, and
     * the tool result that follows it tells the model what went wrong.
     *
     * @throws JsonException when the arguments cannot be written as JSON
     */
    private static function input(#[SensitiveParameter] ToolCall $toolCall): string
    {
        $raw = $toolCall->rawArguments;
        if ($raw !== '' && json_validate($raw) && ($raw[strspn($raw, " \t\n\r")] ?? '') === '{') {
            return $raw;
        }
        if ($toolCall->arguments === null || $toolCall->arguments === []) {
            return '{}';
        }

        return json_encode($toolCall->arguments, self::JSON);
    }

    /**
     * `tools`, and `tool_choice` when it is not "auto". Asked only for a request that has tools.
     *
     * @return array<string, mixed>
     */
    private function toolFields(#[SensitiveParameter] Request $request): array
    {
        $fields = ['tools' => []];
        foreach ($request->tools as $tool) {
            if (preg_match(self::TOOL_NAME, $tool->name) !== 1) {
                throw $this->failure(ErrorReason::Rejected, 'a tool name is not one this format carries: letters, digits, underscores and hyphens, at most 128');
            }
            $fields['tools'][] = ['name' => $tool->name, 'description' => $tool->description, 'input_schema' => $tool->parameters->toObject()];
        }

        $choice = match ($request->toolChoice->mode) {
            ToolChoiceMode::Auto => null,
            ToolChoiceMode::None => ['type' => 'none'],
            ToolChoiceMode::Required => ['type' => 'any'],
            ToolChoiceMode::Tool => ['type' => 'tool', 'name' => (string) $request->toolChoice->toolName],
        };
        if ($choice !== null) {
            $fields['tool_choice'] = $choice;
        }

        return $fields;
    }

    /**
     * The header every request of this adapter carries: the version of the API.
     *
     * @return array<string, string>
     */
    private function headers(Options $options): array
    {
        return ['anthropic-version' => $options->apiVersion];
    }

    // ---------------------------------------------------------------------------------------------------------
    // The answer.
    // ---------------------------------------------------------------------------------------------------------

    protected function readCompletion(#[SensitiveParameter] HttpResponse $response, #[SensitiveParameter] Request $request): Answer
    {
        $body = ErrorBody::decoded($response->body);
        if ($body === null || array_is_list($body)) {
            throw $this->failure(ErrorReason::ProviderError, 'the body is not a JSON object');
        }
        // An error under a successful status is documented for a stream only: here it counts only where there is
        // no answer beside it.
        $content = $body['content'] ?? null;
        if (! is_array($content) && ErrorBody::isIn($body)) {
            throw $this->failure(ErrorReason::ProviderError, 'the response holds an error', ErrorBody::code($body));
        }
        if (! is_array($content)) {
            throw $this->failure(ErrorReason::ProviderError, 'the response holds no answer');
        }

        // The ids the calls have, so that a call without one gets an id no other call of the answer has.
        $taken = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use') {
                $taken[Reading::string($block, 'id')] = true;
            }
        }

        $text = '';
        $calls = [];
        // The blocks as the body wrote them, cut once and only for an answer that holds a tool call: the input of
        // a call is read as that text, in which an empty object is not an empty list.
        $written = null;
        foreach ($content as $at => $block) {
            if (! is_array($block)) {
                continue;
            }
            $kind = $block['type'] ?? null;
            if ($kind === 'text') {
                $text .= Reading::string($block, 'text');
            } elseif ($kind === 'tool_use') {
                $written ??= RawJson::items(RawJson::at($response->body, ['content']) ?? '') ?? [];
                $id = Reading::freeId(count($calls), $taken, Reading::string($block, 'id'));
                $taken[$id] = true;
                $calls[] = Reading::toolCall(
                    $id,
                    Reading::string($block, 'name'),
                    $block['input'] ?? null,
                    is_array($block['input'] ?? null) && isset($written[$at]) ? RawJson::at($written[$at], ['input']) : null,
                );
            }
            // Thinking is no text of the answer, and a block of a kind this adapter does not know is read past.
        }

        $stopWord = is_string($body['stop_reason'] ?? null) ? $body['stop_reason'] : null;

        return $this->answer(
            $request,
            $text,
            $calls,
            Reading::finishReason($stopWord, $calls !== []),
            Reading::usage(Reading::counts([], $body)),
            Reading::string($body, 'model'),
            null,
            $this->sealedContent($text, $calls, $response->body, $content),
        );
    }

    protected function readStream(#[SensitiveParameter] iterable $events, #[SensitiveParameter] Request $request): iterable
    {
        $text = '';
        $stopWord = null;
        $model = '';
        $counts = [];
        $finished = false;

        // The kind of the block every index started with, and the tool calls by the index of their block.
        $kinds = [];
        $calls = [];
        $toolCalls = [];
        // The ids of the calls so far, as a set: a stream of many calls is not walked again for every call.
        $taken = [];

        // The blocks of the content, joined from the pieces of the events, for an answer that holds thinking.
        // `keeping` is false from the first piece on that is no block this adapter keeps, or with which the blocks
        // pass the limits of what is kept: from then on nothing is kept and nothing more is built.
        $pieces = ContentBlocks::none();
        $keeping = true;

        foreach ($events as $event) {
            $data = json_decode($event->data, true);
            if (! is_array($data)) {
                continue;
            }
            $type = is_string($data['type'] ?? null) ? $data['type'] : (string) $event->event;
            $index = is_int($data['index'] ?? null) ? $data['index'] : null;

            if ($type === 'error') {
                throw $this->failure(ErrorBody::reasonInAStream($data), 'the stream holds an error', ErrorBody::code($data));
            }

            if ($type === 'message_start') {
                $message = Reading::members($data, 'message');
                $model = Reading::string($message, 'model') !== '' ? Reading::string($message, 'model') : $model;
                $counts = Reading::counts($counts, $message);
            } elseif ($type === 'message_delta') {
                $delta = Reading::members($data, 'delta');
                $stopWord = is_string($delta['stop_reason'] ?? null) ? $delta['stop_reason'] : $stopWord;
                // The counts of this event are totals: the later value of each count replaces the earlier one.
                $counts = Reading::counts($counts, $data);
            } elseif ($type === 'message_stop') {
                $finished = true;

                break;
            } elseif ($type === 'content_block_start' && $index !== null && ! isset($kinds[$index])) {
                $block = Reading::members($data, 'content_block');
                $kinds[$index] = Reading::string($block, 'type');

                // The block as text, measured before it is cut into its members.
                $written = $keeping ? RawJson::at($event->data, ['content_block'], ContentBlocks::LIMIT_BYTES) : null;
                $keeping = $written !== null && ContentBlocks::start($pieces, $index, $written);

                if ($kinds[$index] === 'text' && Reading::string($block, 'text') !== '') {
                    $text .= Reading::string($block, 'text');
                    yield new TextDelta(Reading::string($block, 'text'));
                } elseif ($kinds[$index] === 'tool_use') {
                    $position = count($calls);
                    $id = Reading::freeId($position, $taken, Reading::string($block, 'id'));
                    $taken[$id] = true;
                    $calls[$index] = [
                        'position' => $position,
                        'id' => $id,
                        'name' => Reading::string($block, 'name'),
                        'json' => '',
                        'input' => $block['input'] ?? null,
                        'written' => is_array($block['input'] ?? null) ? RawJson::at($event->data, ['content_block', 'input']) : null,
                        'done' => false,
                    ];
                    yield new ToolCallStarted($position, $calls[$index]['id'], $calls[$index]['name']);
                }
            } elseif ($type === 'content_block_delta' && $index !== null && isset($kinds[$index])) {
                $delta = Reading::members($data, 'delta');
                $deltaType = Reading::string($delta, 'type');

                $piece = match (true) {
                    $deltaType === 'text_delta' && $kinds[$index] === 'text' => Reading::string($delta, 'text'),
                    $deltaType === 'input_json_delta' && $kinds[$index] === 'tool_use' => Reading::string($delta, 'partial_json'),
                    $deltaType === 'thinking_delta' && $kinds[$index] === 'thinking' => Reading::string($delta, 'thinking'),
                    $deltaType === 'signature_delta' && $kinds[$index] === 'thinking' => Reading::string($delta, 'signature'),
                    default => '',
                };
                if ($piece === '') {
                    continue;
                }

                $keeping = $keeping && ContentBlocks::add($pieces, $index, $deltaType, $piece);
                if ($deltaType === 'text_delta') {
                    $text .= $piece;
                    yield new TextDelta($piece);
                } elseif ($deltaType === 'input_json_delta' && ! $calls[$index]['done']) {
                    // The pieces are one JSON text: they are joined here and read once, when the block stops.
                    $calls[$index]['json'] .= $piece;
                }
            } elseif ($type === 'content_block_stop' && $index !== null && isset($calls[$index]) && ! $calls[$index]['done']) {
                $calls[$index]['done'] = true;
                $toolCall = self::toolCallOf($calls[$index]);
                $toolCalls[] = $toolCall;
                yield new ToolCallCompleted($calls[$index]['position'], $toolCall);
            }
            // A ping, an event of a type this adapter does not know, a delta for a block that never started: read past.

            if (! $keeping) {
                $pieces = ContentBlocks::none();
            }
        }

        // A stream that ends without its last event is not complete: the base ends it as a failure.
        if (! $finished) {
            return;
        }

        // A tool call whose block never stopped completes with what arrived of it.
        foreach ($calls as $index => $call) {
            if (! $call['done']) {
                $calls[$index]['done'] = true;
                $toolCall = self::toolCallOf($call);
                $toolCalls[] = $toolCall;
                yield new ToolCallCompleted($call['position'], $toolCall);
            }
        }

        $usage = Reading::usage($counts);
        if ($usage->inputTokens !== null || $usage->outputTokens !== null) {
            yield new UsageReported($usage);
        }

        $written = $keeping && ContentBlocks::holdThinking($pieces) ? ContentBlocks::written($pieces) : null;
        $kept = $written === null ? null : ContentBlocks::kept($written);

        yield new StreamFinished($this->answer(
            $request,
            $text,
            $toolCalls,
            Reading::finishReason($stopWord, $toolCalls !== []),
            $usage,
            $model,
            null,
            $kept === null ? null : $this->seal($text, $toolCalls, ['content' => $kept]),
        ));
    }

    /**
     * The tool call of a block of a stream: from the pieces of its JSON text, joined, or, when no piece arrived,
     * from the input the block started with.
     *
     * @param array{position: int, id: string, name: string, json: string, input: mixed, written: string|null, done: bool} $call
     */
    private static function toolCallOf(#[SensitiveParameter] array $call): ToolCall
    {
        return trim($call['json']) === ''
            ? Reading::toolCall($call['id'], $call['name'], $call['input'], $call['written'])
            : Reading::toolCallFromText($call['id'], $call['name'], $call['json']);
    }

    /**
     * The content of a whole answer as its opaque part, when it holds thinking; null for a content of text and
     * tool calls alone, and for one with a block of a kind this adapter does not keep.
     *
     * The list is cut out of the body as the text it is: decoded, an empty object would come back as an empty
     * list and a number in another spelling. It is kept only when that text is the content that was read, and is
     * within the limits of what is kept.
     *
     * @param list<ToolCall> $toolCalls
     * @param array<mixed> $content the content of the answer, decoded
     */
    private function sealedContent(
        #[SensitiveParameter]
        string $text,
        #[SensitiveParameter]
        array $toolCalls,
        #[SensitiveParameter]
        string $body,
        #[SensitiveParameter]
        array $content,
    ): ?OpaquePart {
        if (! ContentBlocks::mustGoBack($content)) {
            return null;
        }

        // Measured where it stands in the body, and cut out only when it is within the limit of bytes; then
        // brought to one spelling as text.
        $list = RawJson::at($body, ['content'], ContentBlocks::LIMIT_BYTES);
        $kept = $list === null ? null : ContentBlocks::kept($list);

        // Decoded once more, to see that the text that was cut out is the content that was read. No body is known
        // for which the two differ (the cut takes the last of a name written twice, as the decoder does, and both
        // read the same text): the comparison is a second lock, should RawJson and the decoder ever part ways.

        return $kept !== null && json_decode($kept, true) === $content ? $this->seal($text, $toolCalls, ['content' => $kept]) : null;
    }

    // ---------------------------------------------------------------------------------------------------------
    // The model list.
    // ---------------------------------------------------------------------------------------------------------

    protected function modelsRequest(float $timeoutSeconds): ?HttpRequest
    {
        // The list is one of the gateway, not of a model: the options of the preset itself say how it is asked.
        $options = $this->options(null);

        $listing = $this->option('model_listing') ?? 'native';
        if ($listing === 'none') {
            return null;
        }
        if ($listing !== 'native') {
            throw $this->failure(ErrorReason::NotConfigured, 'the option model_listing of the preset has a value outside its list');
        }

        // One page, the largest the format documents.
        return new HttpRequest('GET', $this->url('/v1/models', ['limit' => 1000]), $this->headers($options), [], $timeoutSeconds);
    }

    protected function readModels(#[SensitiveParameter] HttpResponse $response): array
    {
        $body = ErrorBody::decoded($response->body);
        if ($body === null) {
            throw $this->failure(ErrorReason::ProviderError, 'the body is not JSON');
        }

        $models = ModelList::read($body);
        if ($models === null && ErrorBody::isIn($body)) {
            throw $this->failure(ErrorReason::ProviderError, 'the response holds an error', ErrorBody::code($body));
        }
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
     * A status 429 whose code says the spend cap of the account was reached is a refusal that waiting does not
     * lift: it is "rejected", and so never tried again.
     */
    protected function refineReason(#[SensitiveParameter] HttpResponse $response, ErrorReason $reason): ErrorReason
    {
        return $response->status === 429 && ErrorBody::saysSpendLimit(ErrorBody::decoded($response->body))
            ? ErrorReason::Rejected
            : $reason;
    }

    /**
     * The format says itself whether a failed request may be tried again: the header `x-should-retry` with the
     * word `false` forbids it. Without the header, or with any other word, the reason decides.
     */
    protected function retryAllowed(#[SensitiveParameter] HttpResponse $response, ErrorReason $reason): bool
    {
        return strtolower(trim((string) $response->header('x-should-retry'))) !== 'false';
    }

    // ---------------------------------------------------------------------------------------------------------
    // The options.
    // ---------------------------------------------------------------------------------------------------------

    /**
     * The options of this adapter as they are for a model, or as the preset itself has them when no model is given.
     *
     * @throws GatewayException not_configured, when an option has a name this adapter does not know, holds a value
     *                          outside its list, or one of the two options the adapter needs is not set; the detail
     *                          names a known option, never a value
     */
    private function options(?string $model): Options
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
        $missing = Options::missingOption($options);
        if ($missing !== null) {
            throw $this->failure(ErrorReason::NotConfigured, 'the preset does not set the option ' . $missing . ', which this adapter needs');
        }

        return new Options($options);
    }
}
