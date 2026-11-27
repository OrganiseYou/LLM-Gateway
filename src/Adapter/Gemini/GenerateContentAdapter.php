<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Adapter\Gemini;

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
 * The adapter for the generate-content format: `POST {base}/models/{model}:generateContent`, with the system text
 * in a field of its own, `contents` of the roles `user` and `model` whose content is a list of parts, and a stream
 * at `:streamGenerateContent?alt=sse` in which every event is a whole response with the new parts only. Every fact
 * about the format is from `docs/providers/gemini.md`; the design is `docs/design/adapters.md`, section 4.2 with 5
 * and 10.
 *
 * One adapter for both ways in the provider has: they differ in the base URL, in how a request signs in and in the
 * options of the preset, and the adapter base does the sign-in. The key or the token travels in a header, never
 * in the address.
 *
 * What it sends is a closed list: `systemInstruction`, `contents`, `tools`, `toolConfig`, `generationConfig` (only
 * `maxOutputTokens`, `temperature` and the fields of a response format) and `store` (only as `false`, and only
 * where the preset says so). Nothing else, ever: no `safetySettings`, no `cachedContent`, no `labels`, no
 * `serviceTier`, no request for arguments in pieces. The model is in the path, so a model name that cannot stand
 * there as one segment is refused before anything is sent.
 *
 * A thought signature that arrives on a part never becomes answer text. The list of parts of an answer that holds
 * a signature or a tool call is kept in the opaque part of the answer, as the JSON text the server wrote, and goes
 * back as the parts of that one model turn (ThoughtParts): with the signatures and the call ids the server gave,
 * and without an id the adapter made. A model turn without such a list (a message a caller built by hand or
 * changed) is built from its neutral fields and carries, on its first call, the value the format documents for a
 * call the model did not produce.
 */
final readonly class GenerateContentAdapter extends AbstractAdapter
{
    protected const string OPAQUE_FORMAT = 'a3';

    /** The rule for a tool name that both ways in accept. */
    private const string TOOL_NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';

    /** The value the format documents in place of a signature, for a call the model did not produce. */
    private const string NO_SIGNATURE = 'skip_thought_signature_validator';

    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** A call cannot be handed on without the tool it asks for, and an answer without the call would look final. */
    private const string NAMELESS_CALL = 'a tool call of the answer names no tool';

    /** Where the parts of the one candidate stand in a response and in a chunk of a stream. */
    private const array PARTS = ['candidates', 0, 'content', 'parts'];

    // ---------------------------------------------------------------------------------------------------------
    // Capabilities.
    // ---------------------------------------------------------------------------------------------------------

    protected function modelCapabilities(string $model): Capabilities
    {
        // Read for what it refuses: a preset with an option this adapter cannot honour can do nothing.
        $this->options($model);

        // The defaults of the format: everything native, except tools with a response format in one request,
        // which the provider file confirms for some models only.
        return $this->capabilitiesFromOptions($model, new Capabilities(
            toolCalling: Support::Native,
            forcedToolCall: Support::Native,
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

        // The model stands in the path of the address: only a name that is one segment there, as it is written.
        if (! Reading::isModelInAPath($request->model)) {
            throw $this->failure(ErrorReason::Rejected, 'the model name cannot stand in the address of this format: a letter or a digit, then letters, digits, underscores, hyphens and single dots, at most 128');
        }

        $messages = $request->messages;
        // System messages come first, so a transcript that ends with one holds nothing else: the format requires
        // contents, and its system text is not one.
        if ($messages[count($messages) - 1]->role === Role::System) {
            throw $this->failure(ErrorReason::Rejected, 'the transcript holds only system messages, and this format needs a message beside its system text');
        }

        // The response format goes to the server as a schema when the server takes one for this request, and
        // into the system text otherwise. The schema that is sent is the caller's, as it is.
        $format = $request->responseFormat;
        $asAField = $format !== null
            && $can->structuredOutput === Support::Native
            && ($request->tools === [] || $can->toolsWithStructuredOutput === Support::Native);

        $system = [];
        foreach ($messages as $message) {
            if ($message->role === Role::System) {
                $system[] = $message->text();
            }
        }
        if ($format !== null && ! $asAField) {
            $system[] = (new StructuredOutputEmulation())->instructions($format);
        }

        $body = [];
        if ($system !== []) {
            $body['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $system)]]];
        }
        // The contents are written one by one, further down: one of them may hold parts that are JSON text already.
        $body['contents'] = null;
        if ($request->tools !== []) {
            $body += $this->toolFields($request, $asAField);
        }

        $generation = [];
        if ($request->maxOutputTokens !== null) {
            $generation['maxOutputTokens'] = $request->maxOutputTokens;
        }
        if ($request->temperature !== null) {
            $generation['temperature'] = $request->temperature;
        }
        if ($format !== null && $asAField) {
            $generation += $options->schemaInResponseFormat
                ? ['responseFormat' => ['text' => ['mimeType' => 'APPLICATION_JSON', 'schema' => $format->schema->toObject()]]]
                : ['responseMimeType' => 'application/json', 'responseJsonSchema' => $format->schema->toObject()];
        }
        if ($generation !== []) {
            $body['generationConfig'] = $generation;
        }
        if ($options->sendsStoreFalse) {
            $body['store'] = false;
        }

        try {
            $members = [];
            foreach ($body as $name => $value) {
                $members[$name] = json_encode($value, self::JSON);
            }
            $members['contents'] = RawJson::list($this->contents($request));
            $json = RawJson::object($members);
        } catch (JsonException) {
            throw $this->failure(ErrorReason::Rejected, 'the request holds a value that cannot be written as JSON');
        }

        $url = $stream
            ? $this->url('/models/' . $request->model . ':streamGenerateContent', ['alt' => 'sse'])
            : $this->url('/models/' . $request->model . ':generateContent');

        return new HttpRequest('POST', $url, ['content-type' => 'application/json'], [], $request->timeoutSeconds, $json);
    }

    /**
     * The contents in the shape of the format, each as JSON text: a user message is a `user` content, an assistant
     * message a `model` content, and the tool results that follow one assistant message are together one `user`
     * content, one `functionResponse` part per result, in the order of the calls they answer.
     *
     * @return list<string>
     * @throws JsonException when a message holds a value that cannot be written as JSON
     */
    private function contents(#[SensitiveParameter] Request $request): array
    {
        $contents = [];
        // The tool results that wait to be written, each with the id of its call.
        $results = [];
        // Of the calls of the last assistant message: their place, and whether their id is the server's own.
        $places = [];
        $given = [];

        foreach ($request->messages as $message) {
            if ($message->role !== Role::Tool && $results !== []) {
                $contents[] = self::resultsContent($results, $places);
                $results = [];
            }

            if ($message->role === Role::Tool) {
                $id = (string) $message->toolCallId;
                $response = ['name' => (string) $message->toolName, 'response' => ['output' => $message->text()]];
                // The id goes back only when the server gave one: an id this adapter made is not the server's.
                if ($given[$id] ?? ! Reading::isAMadeId($id)) {
                    $response = ['id' => $id, ...$response];
                }
                $results[] = [$id, json_encode(['functionResponse' => $response], self::JSON)];
            } elseif ($message->role === Role::User) {
                $contents[] = self::content('user', [json_encode(['text' => $message->text()], self::JSON)]);
            } elseif ($message->role === Role::Assistant) {
                [$parts, $given] = $this->modelParts($message);
                $places = [];
                foreach ($message->toolCalls as $place => $toolCall) {
                    $places[$toolCall->id] ??= $place;
                }
                $contents[] = self::content('model', $parts);
            }
        }
        if ($results !== []) {
            $contents[] = self::resultsContent($results, $places);
        }

        return $contents;
    }

    /**
     * One content as JSON text.
     *
     * @param list<string> $parts each part as JSON text
     */
    private static function content(string $role, #[SensitiveParameter] array $parts): string
    {
        return RawJson::object(['role' => '"' . $role . '"', 'parts' => RawJson::list($parts)]);
    }

    /**
     * The tool results of one round as one `user` content, in the order of the calls they answer. A result whose
     * call is not among those of the assistant message before it comes after the others, in the order it was given.
     *
     * @param list<array{string, string}> $results of each result: the id of its call, and its part as JSON text
     * @param array<array-key, int> $places the place of every call of the assistant message before, by its id
     */
    private static function resultsContent(#[SensitiveParameter] array $results, #[SensitiveParameter] array $places): string
    {
        $ordered = [];
        foreach ($results as $at => [$id, $part]) {
            $ordered[] = [$places[$id] ?? PHP_INT_MAX, $at, $part];
        }
        usort($ordered, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return self::content('user', array_column($ordered, 2));
    }

    /**
     * The parts of a model turn, each as JSON text, and which of its call ids are the server's own (true) or made
     * by this adapter (false). The parts are the list that came with the answer when this adapter sealed one with
     * this message and the message still says what that list says. Otherwise they are built from the neutral
     * fields: a text part and a part per tool call, the first call with the value the format documents for a call
     * the model did not produce.
     *
     * @return array{list<string>, array<string, bool>}
     * @throws JsonException when the message holds a value that cannot be written as JSON
     */
    private function modelParts(#[SensitiveParameter] Message $message): array
    {
        $calls = [];
        $given = [];
        foreach ($message->toolCalls as $toolCall) {
            $calls[] = [$toolCall->id, $toolCall->name, self::arguments($toolCall)];
            $given[$toolCall->id] = ! Reading::isAMadeId($toolCall->id);
        }

        // Only one string is taken from the part, and it stands as the parts of this one turn and nowhere else.
        $sealed = $this->unseal($message);
        if ($sealed !== null && array_keys($sealed) === ['parts'] && is_string($sealed['parts'])) {
            $kept = ThoughtParts::agreeing($sealed['parts'], $message->text(), $calls);
            if ($kept !== null) {
                foreach (array_keys($given) as $id) {
                    $given[$id] = isset($kept[1][$id]);
                }

                return [$kept[0], $given];
            }
        }

        $parts = [];
        $text = $message->text();
        if ($text !== '' || $calls === []) {
            $parts[] = json_encode(['text' => $text], self::JSON);
        }
        foreach ($calls as $at => [$id, $name, $arguments]) {
            $call = ['name' => json_encode($name, self::JSON), 'args' => $arguments];
            if ($given[$id]) {
                $call = ['id' => json_encode($id, self::JSON), ...$call];
            }
            $part = ['functionCall' => RawJson::object($call)];
            if ($at === 0) {
                $part['thoughtSignature'] = '"' . self::NO_SIGNATURE . '"';
            }
            $parts[] = RawJson::object($part);
        }

        return [$parts, $given];
    }

    /**
     * The `args` of a tool call that goes back, as JSON text: always an object, as the format asks. The arguments
     * as they arrived when they are the text of a JSON object; otherwise the decoded arguments; and for a call
     * whose arguments could not be read, an empty object: the format has no other way to carry such a call, and
     * the tool result that follows it tells the model what went wrong.
     *
     * @throws JsonException when the arguments cannot be written as JSON
     */
    private static function arguments(#[SensitiveParameter] ToolCall $toolCall): string
    {
        $raw = $toolCall->rawArguments;
        // Not a text with a member name twice: the server may read its first value where this library read the last.
        if ($raw !== '' && json_validate($raw) && ($raw[strspn($raw, " \t\n\r")] ?? '') === '{' && ! RawJson::hasRepeatedName($raw)) {
            return $raw;
        }
        if ($toolCall->arguments === null || $toolCall->arguments === []) {
            return '{}';
        }

        return json_encode($toolCall->arguments, self::JSON);
    }

    /**
     * `tools` and `toolConfig`. Asked only for a request that has tools. The mode "auto" is written as the format
     * names it, except beside a schema for the answer: there the format documents a default of its own, and
     * nothing is written over it.
     *
     * @return array<string, mixed>
     */
    private function toolFields(#[SensitiveParameter] Request $request, bool $besideASchema): array
    {
        $declarations = [];
        foreach ($request->tools as $tool) {
            if (preg_match(self::TOOL_NAME, $tool->name) !== 1) {
                throw $this->failure(ErrorReason::Rejected, 'a tool name is not one this format carries: a letter or an underscore, then letters, digits and underscores, at most 64');
            }
            $declarations[] = ['name' => $tool->name, 'description' => $tool->description, 'parametersJsonSchema' => $tool->parameters->toObject()];
        }
        $fields = ['tools' => [['functionDeclarations' => $declarations]]];

        $calling = match ($request->toolChoice->mode) {
            ToolChoiceMode::Auto => $besideASchema ? null : ['mode' => 'AUTO'],
            ToolChoiceMode::None => ['mode' => 'NONE'],
            ToolChoiceMode::Required => ['mode' => 'ANY'],
            ToolChoiceMode::Tool => ['mode' => 'ANY', 'allowedFunctionNames' => [(string) $request->toolChoice->toolName]],
        };
        if ($calling !== null) {
            $fields['toolConfig'] = ['functionCallingConfig' => $calling];
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

        $candidate = Reading::candidate($body);
        if ($candidate === null) {
            throw $this->withoutACandidate($body) ?? $this->failure(ErrorReason::ProviderError, 'the response holds no answer');
        }

        $stopWord = Reading::string($candidate, 'finishReason');
        $fault = Reading::faultOf($stopWord);
        if ($fault !== null) {
            throw $this->failure(ErrorReason::ProviderError, 'the answer ended as a fault of the model', $fault);
        }

        $parts = Reading::parts($candidate);
        $text = '';
        $taken = self::idsIn($parts);
        $calls = [];
        // The parts as the body wrote them, cut once and only for an answer that holds a tool call: the arguments
        // of a call are read as that text, in which an empty object is not an empty list.
        $written = null;
        foreach ($parts as $at => $part) {
            if (! is_array($part)) {
                continue;
            }
            if (is_array($part['functionCall'] ?? null)) {
                $written ??= RawJson::items(RawJson::at($response->body, self::PARTS) ?? '') ?? [];
                $call = $part['functionCall'];
                if (Reading::string($call, 'name') === '') {
                    throw $this->failure(ErrorReason::ProviderError, self::NAMELESS_CALL);
                }
                $id = Reading::freeId(count($calls), $taken, Reading::string($call, 'id'));
                $taken[$id] = true;
                $calls[] = Reading::toolCall(
                    $id,
                    Reading::string($call, 'name'),
                    $call['args'] ?? null,
                    is_array($call['args'] ?? null) && isset($written[$at]) ? RawJson::at($written[$at], ['functionCall', 'args']) : null,
                );
            } elseif (($part['thought'] ?? null) !== true) {
                $text .= Reading::string($part, 'text');
            }
            // A thought is no text of the answer, and a part of a kind this adapter does not know is read past.
        }

        return $this->answer(
            $request,
            $text,
            $calls,
            Reading::finishReason($stopWord, $calls !== []),
            Reading::usage(Reading::counts([], $body)),
            Reading::string($body, 'modelVersion'),
            null,
            $this->sealedParts($text, $calls, $response->body, $parts),
        );
    }

    protected function readStream(#[SensitiveParameter] iterable $events, #[SensitiveParameter] Request $request): iterable
    {
        $text = '';
        $stopWord = null;
        $model = '';
        $counts = [];
        $toolCalls = [];
        // The ids of the calls so far, as a set: a stream of many calls is not walked again for every call.
        $taken = [];

        // The parts of the answer, put together from the chunks, for an answer with a signature or a call. `keeping`
        // is false from the first chunk on whose parts this adapter does not keep, or with which the parts pass
        // the limits of what is kept: from then on nothing is kept and nothing more is built.
        $pieces = ThoughtParts::none();
        $keeping = true;

        foreach ($events as $event) {
            $data = json_decode($event->data, true);
            if (! is_array($data) || array_is_list($data)) {
                continue;
            }

            // Every chunk may tell the usage and the model: of each the last value counts.
            $counts = Reading::counts($counts, $data);
            $model = Reading::string($data, 'modelVersion') !== '' ? Reading::string($data, 'modelVersion') : $model;

            $candidate = Reading::candidate($data);
            if ($candidate === null) {
                $failure = $this->withoutACandidate($data);
                if ($failure !== null) {
                    throw $failure;
                }

                // A chunk that holds only the usage, or nothing this adapter reads.
                continue;
            }

            $stopWord = Reading::string($candidate, 'finishReason') !== '' ? Reading::string($candidate, 'finishReason') : $stopWord;
            $fault = Reading::faultOf($stopWord);
            if ($fault !== null) {
                throw $this->failure(ErrorReason::ProviderError, 'the answer ended as a fault of the model', $fault);
            }

            $parts = Reading::parts($candidate);
            if ($parts === []) {
                continue;
            }

            // The parts as text, measured before they are cut into their members.
            $list = $keeping ? RawJson::at($event->data, self::PARTS, ThoughtParts::LIMIT_BYTES) : null;
            $keeping = $list !== null && ThoughtParts::add($pieces, $list);
            if (! $keeping) {
                $pieces = ThoughtParts::none();
            }

            foreach (self::idsIn($parts) as $id => $isTaken) {
                $taken[$id] = $isTaken;
            }
            $written = null;
            foreach ($parts as $at => $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (is_array($part['functionCall'] ?? null)) {
                    // The arguments of a call arrive whole: the adapter never asks for them in pieces.
                    $written ??= RawJson::items(RawJson::at($event->data, self::PARTS) ?? '') ?? [];
                    $call = $part['functionCall'];
                    if (Reading::string($call, 'name') === '') {
                        throw $this->failure(ErrorReason::ProviderError, self::NAMELESS_CALL);
                    }
                    $position = count($toolCalls);
                    $id = Reading::freeId($position, $taken, Reading::string($call, 'id'));
                    $taken[$id] = true;
                    $toolCall = Reading::toolCall(
                        $id,
                        Reading::string($call, 'name'),
                        $call['args'] ?? null,
                        is_array($call['args'] ?? null) && isset($written[$at]) ? RawJson::at($written[$at], ['functionCall', 'args']) : null,
                    );
                    $toolCalls[] = $toolCall;

                    yield new ToolCallStarted($position, $toolCall->id, $toolCall->name);
                    yield new ToolCallCompleted($position, $toolCall);
                } elseif (($part['thought'] ?? null) !== true && Reading::string($part, 'text') !== '') {
                    $text .= Reading::string($part, 'text');

                    yield new TextDelta(Reading::string($part, 'text'));
                }
            }
        }

        // The format has no last event: the stream is complete when its body ends. A stream that ended without a
        // finish reason is still an answer when it held text or a call; without either, the base says it is none.
        $usage = Reading::usage($counts);
        if ($usage->inputTokens !== null || $usage->outputTokens !== null) {
            yield new UsageReported($usage);
        }

        $kept = $keeping && ThoughtParts::holdWhatGoesBack($pieces) ? ThoughtParts::kept(ThoughtParts::written($pieces)) : null;

        yield new StreamFinished($this->answer(
            $request,
            $text,
            $toolCalls,
            Reading::finishReason($stopWord, $toolCalls !== []),
            $usage,
            $model,
            null,
            $kept === null ? null : $this->seal($text, $toolCalls, ['parts' => $kept]),
        ));
    }

    /**
     * What a response, or a chunk of a stream, without a candidate says went wrong, or null when it says nothing:
     * a prompt the provider refused is "rejected" with the block reason as its code, and an error object is an
     * error of the provider with its code. Neither counts beside a candidate: an answer is not thrown away.
     *
     * @param array<mixed> $body
     */
    private function withoutACandidate(#[SensitiveParameter] array $body): ?GatewayException
    {
        $blocked = Reading::blockReason($body);
        if ($blocked !== null) {
            return $this->failure(ErrorReason::Rejected, 'the prompt was refused before an answer was made', $blocked);
        }
        if (ErrorBody::isIn($body)) {
            return $this->failure(ErrorReason::ProviderError, 'the response holds an error', ErrorBody::code($body));
        }

        return null;
    }

    /**
     * The ids the calls among these parts have, as a set, so that a call without one gets an id no other call has.
     *
     * @param array<mixed> $parts
     * @return array<array-key, true>
     */
    private static function idsIn(#[SensitiveParameter] array $parts): array
    {
        $taken = [];
        foreach ($parts as $part) {
            $call = is_array($part) && is_array($part['functionCall'] ?? null) ? $part['functionCall'] : [];
            if (Reading::string($call, 'id') !== '') {
                $taken[Reading::string($call, 'id')] = true;
            }
        }

        return $taken;
    }

    /**
     * The parts of a whole answer as its opaque part, when one of them holds a signature or a tool call; null for
     * parts of text alone, and for a list with a part of a kind this adapter does not keep.
     *
     * The list is cut out of the body as the text it is: decoded, an empty object would come back as an empty
     * list and a number in another spelling. It is kept only when that text is the parts that were read, and is
     * within the limits of what is kept.
     *
     * @param list<ToolCall> $toolCalls
     * @param array<mixed> $parts the parts of the answer, decoded
     */
    private function sealedParts(
        #[SensitiveParameter]
        string $text,
        #[SensitiveParameter]
        array $toolCalls,
        #[SensitiveParameter]
        string $body,
        #[SensitiveParameter]
        array $parts,
    ): ?OpaquePart {
        if (! ThoughtParts::mustGoBack($parts)) {
            return null;
        }

        // Measured where it stands in the body, and cut out only when it is within the limit of bytes; then
        // brought to one spelling as text.
        $list = RawJson::at($body, self::PARTS, ThoughtParts::LIMIT_BYTES);
        $kept = $list === null ? null : ThoughtParts::kept($list);

        // Decoded once more, to see that the text that was cut out is the list that was read: a second lock,
        // should the cut and the decoder ever part ways.
        return $kept !== null && json_decode($kept, true) === $parts ? $this->seal($text, $toolCalls, ['parts' => $kept]) : null;
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

        // One page, the largest the format documents. A next page is never asked for.
        return new HttpRequest('GET', $this->url('/models', ['pageSize' => 1000]), [], [], $timeoutSeconds);
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
     * The format answers a key it does not accept with the status 400 and a reason of its own: that is a sign-in
     * that was refused, not a request that was.
     */
    protected function refineReason(#[SensitiveParameter] HttpResponse $response, ErrorReason $reason): ErrorReason
    {
        return $response->status === 400 && ErrorBody::saysInvalidKey(ErrorBody::decoded($response->body))
            ? ErrorReason::CredentialsRejected
            : $reason;
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
