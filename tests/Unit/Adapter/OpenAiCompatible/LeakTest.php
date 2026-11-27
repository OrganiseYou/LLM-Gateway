<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\ChatCompletionsAdapter;
use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\ContentEntries;
use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\ErrorBody;
use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\InlineReasoning;
use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\ModelList;
use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\Reading;
use OrganiseYou\LlmGateway\Adapter\Shared\RawJson;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\Credentials\HeaderKey;
use OrganiseYou\LlmGateway\Contract\GatewayException;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFailed;
use OrganiseYou\LlmGateway\Contract\Stream\StreamFinished;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\Testing\AttemptRecorder;
use OrganiseYou\LlmGateway\Testing\FakeClock;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Tests\Support\ChatCompletions;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

// LR-26 for the chat-completions adapter: the key a gateway signs in with appears in no exception, no event and no
// other output, whatever a server repeats of it. In every scenario below the marker is the key, and the server
// repeats it wherever its format has room: in the headers, in a comment line of a stream, in fields the adapter
// does not read, in the words of an error and as its code.

/**
 * Runs one call in which the marker is the key and the server repeats it, and gives a word that says what left the
 * call with every output form of it, and of the requests in the reports of the attempts.
 *
 * The scenario is named, not handed in: a response among the arguments of this function would put the marker in
 * the trace of whatever is thrown below it, and that would be a leak of the test, not of the library.
 *
 * @return array{string, array<string, string>}
 */
function chatCompletionsLeakOf(string $scenario): array
{
    $headers = ['Set-Cookie' => 'session=' . LEAK_MARKER, 'X-Echo' => LEAK_MARKER, 'Retry-After' => '120', 'retry-after-ms' => '120000', 'Location' => 'https://other.example.test/?key=' . LEAK_MARKER, 'x-request-id' => LEAK_MARKER];
    $echo = ['system_fingerprint' => LEAK_MARKER, 'echo' => ['authorization' => 'Bearer ' . LEAK_MARKER], 'obfuscation' => LEAK_MARKER];
    $good = ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hello.', 'annotations' => [LEAK_MARKER]], 'stop', $echo);
    $good = new HttpResponse(200, $headers, $good->body);
    $fault = ChatCompletions::json(['error' => ['message' => 'Incorrect API key provided: ' . LEAK_MARKER, 'type' => LEAK_MARKER, 'param' => LEAK_MARKER, 'code' => LEAK_MARKER]]);
    $flat = ChatCompletions::json(['id' => null, 'code' => 401, 'error' => LEAK_MARKER, 'detail' => 'The key ' . LEAK_MARKER . ' is not valid.', 'type' => LEAK_MARKER]);
    $stream = [
        ': ' . LEAK_MARKER . "\n\n",
        ChatCompletions::chunk(['role' => 'assistant', 'content' => 'Hel'], null, $echo),
        ChatCompletions::chunk(['content' => 'lo.'], null, $echo),
        ChatCompletions::chunk([], 'stop', $echo),
        ChatCompletions::data(['choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'owner' => LEAK_MARKER]]),
        ChatCompletions::DONE,
    ];
    $thinking = 'The key of this caller is ' . LEAK_MARKER . ', the path is C:\\keys/' . LEAK_MARKER . '.';
    // The same reasoning as an entry of the content, beside a citation, an empty object and a number with a fraction.
    $entries = [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => $thinking]]], ['type' => 'reference', 'reference_ids' => [1], 'details' => (object) [], 'score' => 0.5], ['type' => 'text', 'text' => 'Hello.']];
    // The key as a server could write it in JSON text: every character as an escape with its number.
    $escaped = '';
    foreach (str_split(LEAK_MARKER) as $character) {
        $escaped .= "\x5cu" . sprintf('%04x', ord($character));
    }

    $clock = new FakeClock();
    $transport = new FakeTransport($clock);
    $recorder = new AttemptRecorder();
    $tokens = Talk::tokens();
    $tokens->next = LEAK_MARKER;
    $credentials = match ($scenario) {
        'a header key that comes back as the code' => new HeaderKey(LEAK_MARKER),
        'a token that comes back as the code', 'a token supplier that fails' => new BearerToken($tokens),
        default => new BearerKey(LEAK_MARKER),
    };
    $options = match ($scenario) {
        'a stream of a server that does not stream' => ['streaming' => 'emulated'],
        'an answer with reasoning that repeats the key', 'a stream with reasoning that repeats the key' => ['reasoning_carrier' => 'message_field'],
        'an answer whose content entry repeats the key', 'an answer whose content entry repeats the key, written with escapes',
        'a stream whose content entries repeat the key', 'a stream whose content entries repeat the key in pieces' => ChatCompletions::CONTENT_ENTRIES_SERVER,
        'an option whose value is the key' => ['system_role' => LEAK_MARKER],
        'a flat error that repeats the key' => ['tool_calling' => 'none'],
        default => [],
    };
    $gateway = ChatCompletions::gateway($transport, $options, new RetryPolicy([1.0], 60.0), $clock, $credentials, $recorder);
    $request = ChatCompletions::request();

    $left = null;
    $kind = null;
    $forms = [];
    try {
        switch ($scenario) {
            case 'an answer':
                $transport->willReturn($good);
                $left = $gateway->complete($request);
                break;
            case 'an answer after a retry':
                $transport->willReturn(new HttpResponse(503, ['X-Echo' => LEAK_MARKER, 'retry-after-ms' => '250'], $fault))->willReturn($good);
                $left = $gateway->complete($request);
                break;
            case 'an answer with a tool call':
                $transport->willReturn(new HttpResponse(200, $headers, ChatCompletions::answered(ChatCompletions::calling('{"city":"Paris"}'), 'tool_calls', $echo)->body));
                $left = $gateway->complete(new Request(ChatCompletions::MODEL, [Message::user('Where to?')], 30.0, [ChatCompletions::lookup()]));
                break;
            case 'an answer with reasoning that repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hello.', 'reasoning_content' => $thinking], 'stop', $echo)->body));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose content entry repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, ChatCompletions::answered(['role' => 'assistant', 'content' => $entries], 'stop', $echo)->body));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose content entry repeats the key, written with escapes':
                $entries[0]['thinking'][0]['text'] = 'The key of this caller is written-with-escapes.';
                $transport->willReturn(new HttpResponse(200, [], str_replace('written-with-escapes', $escaped, ChatCompletions::answered(['role' => 'assistant', 'content' => $entries])->body)));
                $left = $gateway->complete($request);
                break;
            case 'a stream whose content entries repeat the key':
                $transport->willStream(200, $headers, $stream[0], ChatCompletions::chunk(['content' => [$entries[0]]]), ChatCompletions::chunk(['content' => [$entries[1]]]), $stream[1], $stream[2], $stream[3], $stream[5]);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose content entries repeat the key in pieces':
                $pieces = [];
                foreach ([substr(LEAK_MARKER, 0, 9), substr(LEAK_MARKER, 9)] as $piece) {
                    $pieces[] = ChatCompletions::chunk(['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => $piece]]]]]);
                }
                $transport->willStream(200, [], str_replace(substr(LEAK_MARKER, 0, 9), substr($escaped, 0, 54), $pieces[0]), $pieces[1], $stream[1], $stream[2], $stream[3], $stream[5]);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream':
                $transport->willStream(200, $headers, ...$stream);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream with reasoning that repeats the key':
                $transport->willStream(200, $headers, $stream[0], ChatCompletions::chunk(['reasoning_content' => $thinking]), $stream[1], $stream[2], $stream[3], $stream[5]);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream of a server that does not stream':
                $transport->willReturn($good);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that breaks':
                $transport->willStream(200, $headers, ...$stream)->willBreakStreamAfter(2, TransportFailure::ConnectionFailed);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that is refused':
                $transport->willStream(401, $headers, $fault);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream with an error inside':
                $transport->willStream(200, $headers, $stream[0], $stream[1], 'data: ' . $fault . "\n\n");
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that ends with the word for an error':
                $transport->willStream(200, $headers, $stream[0], $stream[1], ChatCompletions::chunk([], 'error', $echo));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream of lines that are no JSON':
                $transport->willStream(200, $headers, 'data: ' . LEAK_MARKER . "\n\n", 'data: {"choices":"' . LEAK_MARKER . "\"}\n\n");
                $left = Talk::events($gateway->stream($request));
                break;
            case 'an answer whose model repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, ChatCompletions::answered(more: ['model' => 'deployment-of-' . LEAK_MARKER])->body));
                $left = $gateway->complete($request);
                break;
            case 'a stream whose model repeats the key':
                $transport->willStream(200, $headers, ChatCompletions::chunk(['content' => 'Hello.'], 'stop', ['model' => LEAK_MARKER]), ChatCompletions::DONE);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a tool call whose id repeats the key':
                $transport->willReturn(ChatCompletions::answered(['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => LEAK_MARKER, 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{}']]]], 'tool_calls'));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose name repeats the key':
                $transport->willReturn(ChatCompletions::answered(['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => LEAK_MARKER, 'arguments' => '{}']]]], 'tool_calls'));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose arguments repeat the key':
                $transport->willReturn(ChatCompletions::answered(ChatCompletions::calling(ChatCompletions::json(['authorization' => 'Bearer ' . LEAK_MARKER, 'path' => 'a/b'])), 'tool_calls'));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose arguments, as a map, repeat the key':
                $transport->willReturn(ChatCompletions::answered(ChatCompletions::calling(['headers' => ['authorization' => 'Bearer ' . LEAK_MARKER]]), 'tool_calls'));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose name repeats the key, in a stream':
                $transport->willStream(200, $headers, $stream[1], ChatCompletions::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => LEAK_MARKER, 'arguments' => '{']]]]), ChatCompletions::chunk([], 'tool_calls'), ChatCompletions::DONE);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a tool call whose arguments repeat the key in pieces, in a stream':
                $transport->willStream(
                    200,
                    $headers,
                    ChatCompletions::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'lookup', 'arguments' => '{"key":"' . substr(LEAK_MARKER, 0, 9)]]]]),
                    ChatCompletions::chunk(['tool_calls' => [['index' => 0, 'function' => ['arguments' => substr(LEAK_MARKER, 9) . '"}']]]]),
                    ChatCompletions::chunk([], 'tool_calls'),
                    ChatCompletions::DONE,
                );
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a model list whose entries repeat the key':
                $transport->willReturn(new HttpResponse(200, $headers, ChatCompletions::json(['data' => [['id' => LEAK_MARKER], ['id' => 'example-chat-1', 'name' => 'Model of ' . LEAK_MARKER], ['id' => 'example-chat-2', 'aliases' => ['latest', strtolower(LEAK_MARKER)]], ['id' => 'example-chat-3']]])));
                $left = $gateway->models(10.0);
                break;
            case 'the model list':
                $transport->willReturn(new HttpResponse(200, $headers, ChatCompletions::json(['object' => 'list', 'data' => [['id' => 'example-chat-1', 'owned_by' => LEAK_MARKER, 'permission' => [['id' => LEAK_MARKER]]]], 'echo' => LEAK_MARKER])));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is refused':
                $transport->willReturn(new HttpResponse(403, $headers, $fault));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is no list':
                $transport->willReturn(new HttpResponse(200, $headers, ChatCompletions::json(['message' => LEAK_MARKER])));
                $left = $gateway->models(10.0);
                break;
            case 'a sign-in that is refused':
            case 'a header key that comes back as the code':
            case 'a token that comes back as the code':
                $transport->willReturn(new HttpResponse(401, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a flat error that repeats the key':
                $transport->willReturn(new HttpResponse(401, $headers, $flat));
                $left = $gateway->complete($request);
                break;
            case 'a code with the key inside it':
                $transport->willReturn(new HttpResponse(400, $headers, ChatCompletions::json(['error' => ['message' => 'No.', 'type' => 'invalid_' . strtoupper(LEAK_MARKER) . '.x', 'code' => null]])));
                $left = $gateway->complete($request);
                break;
            case 'no credit, in words that repeat the key':
                $transport->willReturn(new HttpResponse(429, $headers, ChatCompletions::json(['error' => ['message' => 'The account of ' . LEAK_MARKER . ' is out of credit.', 'type' => 'insufficient_quota', 'code' => LEAK_MARKER]])));
                $left = $gateway->complete($request);
                break;
            case 'a rate limit with a wait that is too long':
                $transport->willReturn(new HttpResponse(429, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a server error after the retries':
                $transport->willReturn(new HttpResponse(500, ['X-Echo' => LEAK_MARKER], $fault))->willReturn(new HttpResponse(502, ['X-Echo' => LEAK_MARKER], '<html>' . LEAK_MARKER . '</html>'));
                $left = $gateway->complete($request);
                break;
            case 'a request that is refused':
                $transport->willReturn(new HttpResponse(422, $headers, ChatCompletions::json(['detail' => [['loc' => ['header', 'authorization'], 'msg' => LEAK_MARKER]]])));
                $left = $gateway->complete($request);
                break;
            case 'a redirect':
                $transport->willReturn(new HttpResponse(307, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a lost connection':
                $transport->willFail(TransportFailure::ConnectionFailed)->willFail(TransportFailure::ConnectionFailed);
                $left = $gateway->complete($request);
                break;
            case 'an error inside a 200':
                $transport->willReturn(new HttpResponse(200, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'an answer that ends with the word for an error':
                $transport->willReturn(new HttpResponse(200, $headers, ChatCompletions::answered(['role' => 'assistant', 'content' => LEAK_MARKER], 'error', $echo)->body));
                $left = $gateway->complete($request);
                break;
            case 'a body that cannot be decoded':
                $transport->willReturn(new HttpResponse(200, $headers, 'not JSON: ' . LEAK_MARKER));
                $left = $gateway->complete($request);
                break;
            case 'a body without an answer':
                $transport->willReturn(new HttpResponse(200, $headers, ChatCompletions::json(['choices' => [], 'echo' => LEAK_MARKER])));
                $left = $gateway->complete($request);
                break;
            case 'an option whose value is the key':
                $left = $gateway->complete($request);
                break;
            case 'a tool name the format cannot carry':
                $left = $gateway->complete(new Request(ChatCompletions::MODEL, [Message::user(LEAK_MARKER)], 30.0, [ChatCompletions::lookup('lookup.' . LEAK_MARKER)]));
                break;
            case 'a token supplier that fails':
                $tokens->fails = 'exception';
                $left = $gateway->complete($request);
                break;
            default:
                throw new LogicException('No such scenario.');
        }
    } catch (GatewayException $error) {
        $kind = $error->reason->value . ($error->providerCode === null ? '' : ' with a code');
        $forms = exceptionForms($error);
    } catch (Throwable $other) {
        $kind = $other::class;
        $forms = exceptionForms($other);
    }

    if (is_array($left)) {
        $last = $left[count($left) - 1] ?? null;
        $kind = match (true) {
            $last instanceof StreamFailed => 'failed: ' . $last->error->reason->value . ($last->error->providerCode === null ? '' : ' with a code'),
            $last instanceof StreamFinished => 'finished' . ($last->answer->opaque === null ? '' : ' with an opaque part'),
            default => 'models',
        };
        $forms = valueForms($left);
        foreach ($left as $index => $item) {
            foreach (valueForms($item) as $form => $output) {
                $forms[$index . ' ' . $form] = $output;
            }
            if ($item instanceof StreamFailed) {
                foreach (exceptionForms($item->error) as $form => $output) {
                    $forms['error ' . $form] = $output;
                }
            }
        }
    } elseif ($left instanceof Answer) {
        $kind = 'answer' . ($left->opaque === null ? '' : ' with an opaque part');
        $forms = valueForms($left);
    }

    // What the observer of the host was told of every request: the form for logs, and every other form.
    foreach ([...$recorder->started(), ...$recorder->finished()] as $at => $report) {
        $forms['report ' . $at . ', toArray'] = (string) json_encode($report->request->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR);
        foreach (valueForms($report->request) as $form => $output) {
            $forms['report ' . $at . ', ' . $form] = $output;
        }
    }

    return [(string) $kind, $forms];
}

describe('what leaves complete(), stream() and models() of the chat-completions adapter', function (): void {
    it('holds the key in no exception, no event and no other output form', function (string $scenario, string $whatLeft): void {
        foreach ([false, true] as $ignoreArgs) {
            withExceptionIgnoreArgs($ignoreArgs, function () use ($scenario, $whatLeft): void {
                [$left, $forms] = chatCompletionsLeakOf($scenario);

                expect($left)->toBe($whatLeft);
                expectNoMarker($forms);
            });
        }
    })->with([
        ['an answer', 'answer'],
        ['an answer after a retry', 'answer'],
        ['an answer with a tool call', 'answer'],
        // The reasoning held the key: the part that would carry it to wherever the conversation is stored is dropped.
        ['an answer with reasoning that repeats the key', 'answer'],
        ['a stream', 'finished'],
        ['a stream with reasoning that repeats the key', 'finished'],
        // The same for reasoning that is an entry of the content: the list that held the key is not kept.
        ['an answer whose content entry repeats the key', 'answer'],
        ['an answer whose content entry repeats the key, written with escapes', 'answer'],
        ['a stream whose content entries repeat the key', 'finished'],
        ['a stream whose content entries repeat the key in pieces', 'finished'],
        ['a stream of a server that does not stream', 'finished'],
        ['a stream that breaks', 'failed: unavailable'],
        ['a stream that is refused', 'failed: credentials_rejected'],
        ['a stream with an error inside', 'failed: provider_error'],
        ['a stream that ends with the word for an error', 'failed: provider_error with a code'],
        ['a stream of lines that are no JSON', 'failed: provider_error'],
        ['the model list', 'models'],
        ['an answer whose model repeats the key', 'answer'],
        ['a stream whose model repeats the key', 'finished'],
        ['a tool call whose id repeats the key', 'provider_error'],
        ['a tool call whose name repeats the key', 'provider_error'],
        ['a tool call whose arguments repeat the key', 'provider_error'],
        ['a tool call whose arguments, as a map, repeat the key', 'provider_error'],
        ['a tool call whose name repeats the key, in a stream', 'failed: provider_error'],
        ['a tool call whose arguments repeat the key in pieces, in a stream', 'failed: provider_error'],
        ['a model list whose entries repeat the key', 'models'],
        ['a model list that is refused', 'credentials_rejected'],
        ['a model list that is no list', 'provider_error'],
        ['a sign-in that is refused', 'credentials_rejected'],
        ['a header key that comes back as the code', 'credentials_rejected'],
        ['a token that comes back as the code', 'credentials_rejected'],
        ['a flat error that repeats the key', 'credentials_rejected'],
        ['a code with the key inside it', 'rejected'],
        ['no credit, in words that repeat the key', 'rejected'],
        ['a rate limit with a wait that is too long', 'rate_limited'],
        ['a server error after the retries', 'unavailable'],
        ['a request that is refused', 'rejected'],
        ['a redirect', 'unavailable'],
        ['a lost connection', 'unavailable'],
        ['an error inside a 200', 'provider_error'],
        ['an answer that ends with the word for an error', 'provider_error with a code'],
        ['a body that cannot be decoded', 'provider_error'],
        ['a body without an answer', 'provider_error'],
        ['an option whose value is the key', 'not_configured'],
        ['a tool name the format cannot carry', 'rejected'],
        ['a token supplier that fails', 'credentials_rejected'],
    ]);

    it('would show the marker if the code of the provider were not checked against the credentials: the scenarios do carry it', function (): void {
        // The same failure with another key: now the code is no credential, and it is kept.
        $transport = (new FakeTransport())->willReturn(new HttpResponse(401, [], ChatCompletions::json(['error' => ['message' => 'No.', 'type' => 'other', 'code' => LEAK_MARKER]])));
        $error = Talk::errorOf(fn() => ChatCompletions::gateway($transport, credentials: new BearerKey('another-key'))->complete(ChatCompletions::request()));

        expect($error->providerCode)->toBe(strtolower(LEAK_MARKER));
    });

    it('keeps an opaque part whose reasoning holds no credential, and drops one whose reasoning holds it', function (): void {
        $answerWith = function (string $reasoning, string $key): Answer {
            $transport = (new FakeTransport())->willReturn(ChatCompletions::answered(['role' => 'assistant', 'content' => 'Hello.', 'reasoning_content' => $reasoning]));

            return ChatCompletions::gateway($transport, ['reasoning_carrier' => 'message_field'], credentials: new BearerKey($key))->complete(ChatCompletions::request());
        };

        expect($answerWith('A thought about a/b.', 'key-of-the-test')->opaque)->not->toBeNull()
            ->and($answerWith('A thought about key-of-the-test.', 'key-of-the-test')->opaque)->toBeNull()
            // A slash is written with a backslash inside the sealed text: the key is found all the same.
            ->and($answerWith('A thought about a/key.', 'a/key')->opaque)->toBeNull()
            ->and($answerWith('A thought about "a quoted key".', '"a quoted key"')->opaque)->toBeNull();
    });

    it('keeps an opaque part whose content entries hold no credential, and drops one whose entries hold it', function (): void {
        $letter = fn(string $character): string => "\x5cu" . sprintf('%04x', ord($character));
        $answerWith = function (string $thought, string $key, bool $asStream = false): Answer {
            // The thought is JSON text already: so a test can write it with the escapes of its choice.
            $content = '[{"type":"thinking","thinking":[{"type":"text","text":"' . $thought . '"}]},{"type":"text","text":"Hello."}]';
            $transport = new FakeTransport();
            $gateway = ChatCompletions::gateway($transport, ChatCompletions::CONTENT_ENTRIES_SERVER, credentials: new BearerKey($key));
            if ($asStream) {
                $transport->willStream(200, [], 'data: {"choices":[{"index":0,"delta":{"content":' . $content . '},"finish_reason":"stop"}]}' . "\n\n", ChatCompletions::DONE);

                return Talk::finishedIn(Talk::events($gateway->stream(ChatCompletions::request())))->answer;
            }
            $transport->willReturn(new HttpResponse(200, [], '{"choices":[{"index":0,"message":{"role":"assistant","content":' . $content . '},"finish_reason":"stop"}]}'));

            return $gateway->complete(ChatCompletions::request());
        };

        foreach ([false, true] as $asStream) {
            expect($answerWith('A thought about a/b.', 'key-of-the-test', $asStream)->opaque)->not->toBeNull()
                ->and($answerWith('A thought about key-of-the-test.', 'key-of-the-test', $asStream)->opaque)->toBeNull()
                ->and($answerWith('A thought about KEY-of-the-TEST.', 'key-of-the-test', $asStream)->opaque)->toBeNull()
                // Written with escapes, by the letter or for a slash and a quote: the key is found all the same.
                ->and($answerWith('A thought about ' . $letter('k') . 'ey-of-the' . $letter('-') . 'test.', 'key-of-the-test', $asStream)->opaque)->toBeNull()
                ->and($answerWith('A thought about a\\/key.', 'a/key', $asStream)->opaque)->toBeNull()
                ->and($answerWith('A thought about \\"a quoted key\\".', '"a quoted key"', $asStream)->opaque)->toBeNull()
                // The answer itself is given either way.
                ->and($answerWith('A thought about key-of-the-test.', 'key-of-the-test', $asStream)->text)->toBe('Hello.');
        }
    });

    it('drops the part when what came with the answer repeats a key that holds a line end of Unicode, on either carrier', function (string $lineEnd, string $number): void {
        // JSON may write these two characters as they are or as an escape; one of PHP's encoders escapes them.
        $key = 'abc' . $lineEnd . 'def';
        foreach (['abc' . $lineEnd . 'def', "abc\x5c" . 'u' . $number . 'def'] as $written) {
            $entries = '[{"type":"thinking","thinking":[{"type":"text","text":"A thought about ' . $written . '."}]},{"type":"text","text":"Hello."}]';
            $cases = [
                [ChatCompletions::CONTENT_ENTRIES_SERVER, '"content":' . $entries],
                [['reasoning_carrier' => 'message_field'], '"content":"Hello.","reasoning_content":"A thought about ' . $written . '."'],
            ];
            foreach ($cases as [$options, $members]) {
                foreach ([$key, 'another-key'] as $signedInWith) {
                    $transport = (new FakeTransport())
                        ->willReturn(new HttpResponse(200, [], '{"choices":[{"index":0,"message":{"role":"assistant",' . $members . '},"finish_reason":"stop"}]}'))
                        ->willStream(200, [], 'data: {"choices":[{"index":0,"delta":{' . $members . '},"finish_reason":"stop"}]}' . "\n\n", ChatCompletions::DONE);
                    $gateway = ChatCompletions::gateway($transport, $options, credentials: new BearerKey($signedInWith));

                    $whole = $gateway->complete(ChatCompletions::request());
                    $streamed = Talk::finishedIn(Talk::events($gateway->stream(ChatCompletions::request())))->answer;

                    // With another key the same answer keeps its part: it is the key that drops it.
                    expect($whole->opaque === null)->toBe($signedInWith === $key)
                        ->and($streamed->opaque === null)->toBe($signedInWith === $key)
                        ->and($whole->text)->toBe('Hello.')
                        ->and($streamed->text)->toBe('Hello.');
                }
            }
        }
    })->with([
        'U+2028' => ["\xe2\x80\xa8", '2028'],
        'U+2029' => ["\xe2\x80\xa9", '2029'],
    ]);

    it('names the model of the request when the model of an answer repeats a credential or is no name', function (string $model): void {
        $transport = (new FakeTransport())
            ->willReturn(ChatCompletions::answered(more: ['model' => $model]))
            ->willStream(200, [], ChatCompletions::chunk(['content' => 'Hello.'], 'stop', ['model' => $model]), ChatCompletions::DONE);
        $gateway = ChatCompletions::gateway($transport);

        expect($gateway->complete(ChatCompletions::request())->model)->toBe(ChatCompletions::MODEL)
            ->and(Talk::finishedIn(Talk::events($gateway->stream(ChatCompletions::request())))->answer->model)->toBe(ChatCompletions::MODEL);
    })->with([
        'the credential' => ['key-of-the-test'],
        'the credential inside a longer name' => ['deployment/Key-Of-The-Test/v2'],
        'a line end' => ["example-chat-1\r\nX-Injected: 1"],
        'a tab' => ["example\tchat"],
    ]);

    it('lists the models without the entries whose id, name or alias repeats a credential', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], ChatCompletions::json(['data' => [
            ['id' => 'key-of-the-test'],
            ['id' => 'example-chat-1', 'name' => 'The key-of-the-test model'],
            ['id' => 'example-chat-2', 'aliases' => ['latest', 'KEY-OF-THE-TEST']],
            ['id' => 'example-chat-3', 'name' => 'Example Chat 3', 'aliases' => ['latest']],
        ]])));

        expect(array_map(fn($model): string => $model->id, ChatCompletions::gateway($transport)->models(10.0)))->toBe(['example-chat-3']);
    });

    it('fails an answer whose tool call repeats a credential, with a detail that holds nothing of the call', function (): void {
        $message = ChatCompletions::calling('{"city":"Paris"}', '{"token":"key-of-the-test"}');
        $transport = (new FakeTransport())->willReturn(ChatCompletions::answered($message, 'tool_calls'));
        $error = Talk::errorOf(fn() => ChatCompletions::gateway($transport)->complete(ChatCompletions::request()));

        expect($error->reason->value)->toBe('provider_error')
            ->and($error->detail)->toBe('a tool call of the answer repeats a credential of the gateway and is not handed on')
            ->and($error->httpStatus)->toBe(200);
    });

    it('shows no key in a dump of the gateway itself, apart from what the transport of the host holds', function (): void {
        $gateway = new ChatCompletionsAdapter(ChatCompletions::preset(), new GatewayConfig(RetryPolicy::none(), clock: new FakeClock()), new BearerKey(LEAK_MARKER), new FakeTransport());

        expectNoMarker(valueForms($gateway));
    });
});

describe('the parameters of the chat-completions adapter that carry a response, a request or a message', function (): void {
    it('are marked as sensitive, so that an error thrown below them keeps none of them in its printed trace', function (string $class, string $method, array $expected): void {
        $sensitive = [];
        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                $sensitive[] = $parameter->getName();
            }
        }

        expect($sensitive)->toBe($expected);
    })->with([
        'completionRequest' => [ChatCompletionsAdapter::class, 'completionRequest', ['request']],
        'messages' => [ChatCompletionsAdapter::class, 'messages', ['request', 'instructions']],
        'assistantMessage' => [ChatCompletionsAdapter::class, 'assistantMessage', ['message']],
        'toolMessage' => [ChatCompletionsAdapter::class, 'toolMessage', ['message']],
        'reasoningThatCameWith' => [ChatCompletionsAdapter::class, 'reasoningThatCameWith', ['message']],
        'contentThatCameWith' => [ChatCompletionsAdapter::class, 'contentThatCameWith', ['message']],
        'sealedContent' => [ChatCompletionsAdapter::class, 'sealedContent', ['text', 'toolCalls', 'body', 'content']],
        'sealedEntries' => [ChatCompletionsAdapter::class, 'sealedEntries', ['text', 'toolCalls', 'list']],
        'ContentEntries::holdsMoreThanText' => [ContentEntries::class, 'holdsMoreThanText', ['content']],
        'ContentEntries::kept' => [ContentEntries::class, 'kept', ['json']],
        'ContentEntries::isWithinTheLimits' => [ContentEntries::class, 'isWithinTheLimits', ['json']],
        'ContentEntries::addText' => [ContentEntries::class, 'addText', ['pieces', 'text']],
        'ContentEntries::addList' => [ContentEntries::class, 'addList', ['pieces', 'json']],
        'ContentEntries::entriesHoldMoreThanText' => [ContentEntries::class, 'entriesHoldMoreThanText', ['pieces']],
        'ContentEntries::written' => [ContentEntries::class, 'written', ['pieces']],
        'RawJson::at' => [RawJson::class, 'at', ['json']],
        'RawJson::members' => [RawJson::class, 'members', ['json']],
        'RawJson::items' => [RawJson::class, 'items', ['json']],
        'RawJson::plain' => [RawJson::class, 'plain', ['json']],
        'RawJson::containers' => [RawJson::class, 'containers', ['json']],
        'RawJson::string' => [RawJson::class, 'string', ['text']],
        'RawJson::object' => [RawJson::class, 'object', ['members']],
        'RawJson::list' => [RawJson::class, 'list', ['items']],
        'toolFields' => [ChatCompletionsAdapter::class, 'toolFields', ['request']],
        'readCompletion' => [ChatCompletionsAdapter::class, 'readCompletion', ['response', 'request']],
        'readStream' => [ChatCompletionsAdapter::class, 'readStream', ['events', 'request']],
        'sealedReasoning' => [ChatCompletionsAdapter::class, 'sealedReasoning', ['text', 'toolCalls', 'reasoning']],
        'readModels' => [ChatCompletionsAdapter::class, 'readModels', ['response']],
        'providerCode' => [ChatCompletionsAdapter::class, 'providerCode', ['response']],
        'refineReason' => [ChatCompletionsAdapter::class, 'refineReason', ['response']],
        'retryAfterSeconds' => [ChatCompletionsAdapter::class, 'retryAfterSeconds', ['response']],
        'ErrorBody::decoded' => [ErrorBody::class, 'decoded', ['body']],
        'ErrorBody::isIn' => [ErrorBody::class, 'isIn', ['body']],
        'ErrorBody::code' => [ErrorBody::class, 'code', ['body']],
        'ErrorBody::saysNoCredit' => [ErrorBody::class, 'saysNoCredit', ['body']],
        'ErrorBody::saysOverloaded' => [ErrorBody::class, 'saysOverloaded', ['body']],
        'Reading::text' => [Reading::class, 'text', ['content']],
        'Reading::string' => [Reading::class, 'string', ['holder']],
        'Reading::members' => [Reading::class, 'members', ['holder']],
        'Reading::reasoning' => [Reading::class, 'reasoning', ['holder']],
        'Reading::usage' => [Reading::class, 'usage', ['holder']],
        'Reading::toolCall' => [Reading::class, 'toolCall', ['name', 'arguments']],
        'ModelList::read' => [ModelList::class, 'read', ['body']],
        'InlineReasoning::stripped' => [InlineReasoning::class, 'stripped', ['text']],
        'InlineReasoning::feed' => [InlineReasoning::class, 'feed', ['held', 'piece']],
        'InlineReasoning::rest' => [InlineReasoning::class, 'rest', ['held']],
    ]);

    it('covers every method of the adapter and its helpers that takes a response, a request, a message or a body', function (): void {
        $carriers = ['HttpRequest', 'HttpResponse', 'Request', 'Message', 'Answer', 'iterable', 'mixed'];
        $unmarked = [];
        foreach (SourceCode::classesOfTheLibrary(dirname(__DIR__, 4) . '/src') as $path => $class) {
            if (! str_starts_with((string) $path, 'Adapter/OpenAiCompatible/')) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    $name = $type instanceof ReflectionNamedType ? ltrim((string) strrchr('\\' . $type->getName(), '\\'), '\\') : '';
                    if (in_array($name, $carriers, true) && $parameter->getAttributes(SensitiveParameter::class) === []) {
                        $unmarked[] = $class . '::' . $method->getName() . '($' . $parameter->getName() . ')';
                    }
                }
            }
        }

        expect($unmarked)->toBe([]);
    });
});
