<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Support;

use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Adapter\Shared\StructuredOutputEmulation;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Capabilities;
use OrganiseYou\LlmGateway\Contract\ErrorReason;
use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\ModelInfo;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\Contract\Stream\TextDelta;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallCompleted;
use OrganiseYou\LlmGateway\Contract\Stream\ToolCallStarted;
use OrganiseYou\LlmGateway\Contract\Stream\UsageReported;
use OrganiseYou\LlmGateway\Contract\StructuredIssue;
use OrganiseYou\LlmGateway\Contract\StructuredIssueKind;
use OrganiseYou\LlmGateway\Contract\StructuredResult;
use OrganiseYou\LlmGateway\Contract\Support;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\Usage;
use OrganiseYou\LlmGateway\Transport\HttpRequest;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use RuntimeException;
use SensitiveParameter;

/**
 * An adapter for a made-up format, written outside the library the way a host project would write one: it uses
 * only the constructor and the protected methods of the adapter base. The tests of the base run on it.
 *
 * The format, "talk": a completion is POST /talk with {"model", "stream", "lines": [{"who", "say"}], "tools",
 * "shape"}; its answer is {"say", "calls": [{"ref", "tool", "input"}], "end", "by", "used": {"in", "out"}}; an
 * error is {"fault": {"code", "wait"}}; a stream has the events say, call_open, call, used, end and fault; the
 * models are GET /catalog with {"items": [{"key", "title", "window"}]}.
 *
 * What a server of this format can do is told by the adapter options of the preset. With the option "structured" as
 * "emulated" the server takes no "shape": the adapter puts the schema in the system text instead. A body with
 * "shaped" stands for a format that gets the structured answer its own way: "shaped_issue" makes it an unusable one.
 */
final readonly class TalkAdapter extends AbstractAdapter
{
    protected function modelCapabilities(string $model): Capabilities
    {
        if ($this->option('capabilities_fail', false) === true) {
            throw $this->failure(ErrorReason::NotConfigured, 'this server is not set up for the model');
        }

        $tools = $this->support('tools');
        $structured = $this->support('structured');

        return new Capabilities(
            toolCalling: $tools,
            forcedToolCall: $tools === Support::None ? Support::None : $this->support('forced'),
            parallelToolCalls: $tools,
            structuredOutput: $structured,
            toolsWithStructuredOutput: $tools === Support::None || $structured === Support::None ? Support::None : $this->support('tools_with_structured'),
            streaming: $this->option('streams', true) === true ? Support::Native : Support::Emulated,
            modelListing: $this->option('lists_models', true) === true ? Support::Native : Support::None,
            contextLength: null,
        );
    }

    protected function completionRequest(Request $request, bool $stream): HttpRequest
    {
        $longest = $this->option('max_tool_name', 0);
        foreach ($request->tools as $tool) {
            if (is_int($longest) && $longest > 0 && strlen($tool->name) > $longest) {
                throw $this->failure(ErrorReason::Rejected, 'a tool name is longer than this format allows');
            }
        }

        // A server without structured output of its own: the schema goes into the system text, as the last of it.
        $emulated = $request->responseFormat !== null && $this->support('structured') === Support::Emulated;
        $instructions = $request->responseFormat !== null && $emulated
            ? ['who' => Role::System->value, 'say' => (new StructuredOutputEmulation())->instructions($request->responseFormat)]
            : null;

        $lines = [];
        foreach ($request->messages as $message) {
            if ($instructions !== null && $message->role !== Role::System) {
                $lines[] = $instructions;
                $instructions = null;
            }
            $lines[] = ['who' => $message->role->value, 'say' => $message->text()];
        }
        if ($instructions !== null) {
            $lines[] = $instructions;
        }

        $foreign = $this->option('foreign_url', '');
        $url = is_string($foreign) && $foreign !== '' ? $foreign : $this->url('/talk', $stream ? ['flow' => 1] : []);

        return new HttpRequest(
            'POST',
            $url,
            // What a careless adapter does: the trace id of the caller, sent to the provider.
            $this->option('sends_trace', false) === true ? ['Content-Type' => 'application/json', 'X-Trace' => (string) $request->traceId] : ['Content-Type' => 'application/json'],
            [],
            $request->timeoutSeconds,
            (string) json_encode([
                'model' => $request->model,
                'stream' => $stream,
                'lines' => $lines,
                'tools' => array_map(fn($tool): string => $tool->name, $request->tools),
                'shape' => $emulated ? null : $request->responseFormat?->name,
                'dialect' => $this->setting('dialect'),
            ]),
        );
    }

    protected function readCompletion(#[SensitiveParameter] HttpResponse $response, Request $request): Answer
    {
        $body = $this->decoded($response->body);

        $calls = [];
        foreach (self::listIn($body, 'calls') as $at => $call) {
            $calls[] = self::toolCall($call, $at);
        }

        return $this->answer(
            $request,
            self::textIn($body, 'say'),
            $calls,
            self::finishReason(self::textIn($body, 'end')),
            self::usage($body['used'] ?? null),
            self::textIn($body, 'by'),
            // A format that gets the structured answer its own way hands the result to answer().
            is_array($body['shaped'] ?? null) ? new StructuredResult($body['shaped'], self::issues($body['shaped_issue'] ?? null)) : null,
        );
    }

    protected function readStream(#[SensitiveParameter] iterable $events, Request $request): iterable
    {
        $text = '';
        $calls = [];
        $usage = new Usage(null, null);

        foreach ($events as $event) {
            $data = $this->decoded($event->data);

            if ($event->event === 'say') {
                $piece = self::textIn($data, 'piece');
                if ($piece !== '') {
                    $text .= $piece;
                    yield new TextDelta($piece);
                }
            } elseif ($event->event === 'call_open') {
                yield new ToolCallStarted(count($calls), self::textIn($data, 'ref'), self::textIn($data, 'tool'));
            } elseif ($event->event === 'call') {
                $call = self::toolCall($data, count($calls));
                yield new ToolCallCompleted(count($calls), $call);
                $calls[] = $call;
            } elseif ($event->event === 'junk') {
                // A fault in the code of an adapter: a value that is no event.
                yield untyped(self::textIn($data, 'piece'));
            } elseif ($event->event === 'used') {
                $usage = self::usage($data);
                yield new UsageReported($usage);
            } elseif ($event->event === 'end') {
                yield new StreamFinished($this->answer(
                    $request,
                    $text,
                    $calls,
                    self::finishReason(self::textIn($data, 'end')),
                    $usage,
                    self::textIn($data, 'by'),
                ));

                return;
            }
            // An event of a kind this adapter does not know is skipped.
        }
    }

    protected function modelsRequest(float $timeoutSeconds): ?HttpRequest
    {
        if ($this->option('lists_models', true) !== true) {
            return null;
        }

        return new HttpRequest('GET', $this->url('/catalog'), ['Accept' => 'application/json'], [], $timeoutSeconds);
    }

    protected function readModels(#[SensitiveParameter] HttpResponse $response): array
    {
        $models = [];
        foreach (self::listIn($this->decoded($response->body), 'items') as $item) {
            $key = self::textIn($item, 'key');
            if ($key === '') {
                continue;
            }
            $title = self::textIn($item, 'title');
            $window = $item['window'] ?? null;
            $models[] = new ModelInfo($key, $title === '' ? null : $title, is_int($window) && $window > 0 ? $window : null);
        }

        return $models;
    }

    protected function providerCode(#[SensitiveParameter] HttpResponse $response): ?string
    {
        $fault = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);

        return is_array($fault) && is_array($fault['fault'] ?? null) && is_string($fault['fault']['code'] ?? null)
            ? $fault['fault']['code']
            : null;
    }

    protected function refineReason(#[SensitiveParameter] HttpResponse $response, ErrorReason $reason): ErrorReason
    {
        return match ($this->providerCode($response)) {
            'no_such_model', 'bad_version' => ErrorReason::Rejected,
            'overloaded' => ErrorReason::Unavailable,
            'adapter_breaks' => $reason === ErrorReason::Rejected ? ErrorReason::from(self::errorWith($response->body)) : $reason,
            default => $reason,
        };
    }

    protected function retryAfterSeconds(#[SensitiveParameter] HttpResponse $response): ?float
    {
        $fault = json_decode($response->body, true);
        $wait = is_array($fault) && is_array($fault['fault'] ?? null) ? $fault['fault']['wait'] ?? null : null;

        if ($wait === 'not a number') {
            return NAN;
        }

        return is_int($wait) || is_float($wait) ? (float) $wait : parent::retryAfterSeconds($response);
    }

    private function option(string $name, bool|int|string $default): bool|int|string
    {
        return $this->preset->adapterOptions[$name] ?? $default;
    }

    private function support(string $name): Support
    {
        $value = $this->option($name, 'native');

        return is_string($value) ? Support::from($value) : Support::None;
    }

    /**
     * @return array<mixed>
     */
    private function decoded(#[SensitiveParameter] string $body): array
    {
        $data = json_decode($body, true);
        if (! is_array($data)) {
            throw $this->failure(ErrorReason::ProviderError, 'the body is not a JSON object');
        }
        if (is_array($data['fault'] ?? null)) {
            $code = $data['fault']['code'] ?? null;
            // What a careless adapter does: words of the provider in the detail.
            $words = $data['fault']['words'] ?? null;

            throw $this->failure(ErrorReason::ProviderError, is_string($words) ? $words : 'the response holds an error', is_string($code) ? $code : null);
        }
        if (($data['explode'] ?? null) === 'exception') {
            // What a careless adapter does: the text of the provider in the message of an exception.
            throw new RuntimeException('Could not read: ' . $body);
        }
        if (($data['explode'] ?? null) === 'error') {
            self::errorWith($body);
        }

        return $data;
    }

    /**
     * A fault in the code of the adapter, with the body among the arguments of the call that fails.
     */
    private static function errorWith(string $body): string
    {
        $zero = strlen($body) - strlen($body);

        return (string) intdiv(1, $zero);
    }

    /**
     * @param array<mixed> $data
     */
    private static function textIn(#[SensitiveParameter] array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private static function listIn(#[SensitiveParameter] array $data, string $key): array
    {
        return array_values(array_filter(is_array($data[$key] ?? null) ? $data[$key] : [], is_array(...)));
    }

    /**
     * @param array<mixed> $call
     */
    private static function toolCall(#[SensitiveParameter] array $call, int $at): ToolCall
    {
        $id = self::textIn($call, 'ref');
        $id = $id === '' ? 'call_' . $at : $id;
        $raw = self::textIn($call, 'input');
        $arguments = json_decode($raw, true);

        return is_array($arguments) && ($arguments === [] || ! array_is_list($arguments))
            ? ToolCall::decoded($id, self::textIn($call, 'tool'), $arguments, $raw)
            : ToolCall::unreadable($id, self::textIn($call, 'tool'), 'the arguments are not a JSON object', $raw);
    }

    /**
     * @return list<StructuredIssue>
     */
    private static function issues(#[SensitiveParameter] mixed $issue): array
    {
        return is_string($issue) && $issue !== '' ? [new StructuredIssue(StructuredIssueKind::SchemaMismatch, $issue, '/')] : [];
    }

    private static function finishReason(string $end): FinishReason
    {
        return match ($end) {
            'done' => FinishReason::Stop,
            'calls' => FinishReason::ToolCalls,
            'cut' => FinishReason::Length,
            'blocked' => FinishReason::ContentFilter,
            default => FinishReason::Other,
        };
    }

    private static function usage(#[SensitiveParameter] mixed $used): Usage
    {
        $in = is_array($used) && is_int($used['in'] ?? null) && $used['in'] >= 0 ? $used['in'] : null;
        $out = is_array($used) && is_int($used['out'] ?? null) && $used['out'] >= 0 ? $used['out'] : null;

        return new Usage($in, $out);
    }
}
