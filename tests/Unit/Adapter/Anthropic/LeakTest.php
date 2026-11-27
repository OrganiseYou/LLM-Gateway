<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Anthropic\ContentBlocks;
use OrganiseYou\LlmGateway\Adapter\Anthropic\ErrorBody;
use OrganiseYou\LlmGateway\Adapter\Anthropic\MessagesAdapter;
use OrganiseYou\LlmGateway\Adapter\Anthropic\ModelList;
use OrganiseYou\LlmGateway\Adapter\Anthropic\Reading;
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
use OrganiseYou\LlmGateway\Tests\Support\Messages;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

// LR-26 for the Messages adapter: the key a gateway signs in with appears in no exception, no event and no other
// output, whatever a server repeats of it. In every scenario below the marker is the key, and the server repeats it
// wherever its format has room: in the headers, in a comment line of a stream, in fields the adapter does not read,
// in thinking, in the words of an error, as its type and as its code.

/**
 * Runs one call in which the marker is the key and the server repeats it, and gives a word that says what left the
 * call with every output form of it, and of the requests in the reports of the attempts.
 *
 * The scenario is named, not handed in: a response among the arguments of this function would put the marker in
 * the trace of whatever is thrown below it, and that would be a leak of the test, not of the library.
 *
 * @return array{string, array<string, string>}
 */
function messagesLeakOf(string $scenario): array
{
    $headers = [
        'Set-Cookie' => 'session=' . LEAK_MARKER, 'X-Echo' => LEAK_MARKER, 'retry-after' => '120', 'request-id' => LEAK_MARKER,
        'anthropic-organization-id' => LEAK_MARKER, 'anthropic-workspace-id' => LEAK_MARKER, 'Location' => 'https://other.example.test/?key=' . LEAK_MARKER,
        'anthropic-ratelimit-requests-reset' => LEAK_MARKER,
    ];
    // Every place of an answer the adapter reads past.
    $echo = [
        'id' => LEAK_MARKER, 'stop_sequence' => LEAK_MARKER, 'stop_details' => ['type' => 'refusal', 'category' => LEAK_MARKER, 'explanation' => 'The key ' . LEAK_MARKER . ' was refused.'],
        'usage' => ['input_tokens' => 3, 'output_tokens' => 2, 'service_tier' => LEAK_MARKER, 'inference_geo' => LEAK_MARKER, 'cache_creation' => [LEAK_MARKER => 1]],
        'container' => ['id' => LEAK_MARKER], 'echo' => ['authorization' => 'Bearer ' . LEAK_MARKER, 'x-api-key' => LEAK_MARKER],
    ];
    $text = ['type' => 'text', 'text' => 'Hello.', 'citations' => [['cited_text' => LEAK_MARKER]], LEAK_MARKER => true];
    $good = new HttpResponse(200, $headers, Messages::answered([$text], 'end_turn', $echo)->body);
    $fault = Messages::json(['type' => 'error', 'error' => ['type' => LEAK_MARKER, 'message' => 'Invalid key: ' . LEAK_MARKER, 'details' => ['error_code' => LEAK_MARKER, 'key' => LEAK_MARKER]], 'request_id' => LEAK_MARKER]);
    $thinking = 'The key of this caller is ' . LEAK_MARKER . ', the path is C:\\keys/' . LEAK_MARKER . '.';
    // The key as a server could write it in JSON text: every character as an escape with its number.
    $escaped = '';
    foreach (str_split(LEAK_MARKER) as $character) {
        $escaped .= "\x5cu" . sprintf('%04x', ord($character));
    }
    $start = Messages::event('message_start', ['message' => ['id' => LEAK_MARKER, 'type' => 'message', 'role' => 'assistant', 'model' => 'example-messages-1', 'content' => [], 'stop_reason' => null, 'stop_sequence' => LEAK_MARKER, 'usage' => ['input_tokens' => 3, 'output_tokens' => 1, 'service_tier' => LEAK_MARKER]], 'echo' => LEAK_MARKER]);
    $stop = [
        Messages::event('message_delta', ['delta' => ['stop_reason' => 'end_turn', 'stop_sequence' => LEAK_MARKER, 'stop_details' => ['explanation' => LEAK_MARKER]], 'usage' => ['output_tokens' => 2, 'owner' => LEAK_MARKER]]),
        Messages::event('message_stop', ['echo' => LEAK_MARKER]),
    ];
    $stream = [
        ': ' . LEAK_MARKER . "\n\n",
        $start,
        Messages::event('ping', ['echo' => LEAK_MARKER]),
        Messages::blockStarted(0, ['type' => 'text', 'text' => '', 'citations' => [LEAK_MARKER]]),
        Messages::event('content_block_delta', ['index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hel', 'echo' => LEAK_MARKER]]),
        Messages::event('content_block_delta', ['index' => 0, 'delta' => ['type' => 'citations_delta', 'citation' => ['cited_text' => LEAK_MARKER]]]),
        Messages::event('some_new_event', ['key' => LEAK_MARKER]),
        Messages::delta(0, 'text_delta', 'lo.'),
        Messages::event('content_block_stop', ['index' => 0, 'echo' => LEAK_MARKER]),
        ...$stop,
    ];
    $toolStop = Messages::stopped('tool_use');
    // A text block that repeats nothing, and the end of the stream: what follows the thinking of a scenario, so
    // that the thinking alone decides whether its part is kept.
    $said = [Messages::blockStarted(0, Messages::text('')), Messages::delta(0, 'text_delta', 'Hello.'), Messages::blockStopped(0), ...$stop];

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
        'a stream of a server that does not stream' => [...Messages::EARLIER_VERSION, 'streaming' => 'emulated'],
        'an option whose value is the key' => ['api_version' => LEAK_MARKER, 'default_max_output_tokens' => 16000],
        'an option whose name is the key' => [...Messages::EARLIER_VERSION, LEAK_MARKER => 'x'],
        'a temperature the model does not take' => Messages::CURRENT_VERSION,
        default => Messages::EARLIER_VERSION,
    };
    $gateway = Messages::gateway($transport, $options, new RetryPolicy([1.0], 60.0), $clock, $credentials, $recorder);
    $request = Messages::request();

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
                $transport->willReturn(new HttpResponse(529, ['X-Echo' => LEAK_MARKER, 'retry-after' => '1'], $fault))->willReturn($good);
                $left = $gateway->complete($request);
                break;
            case 'an answer with a tool call':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::answered([$text, [...Messages::call(), 'caller' => LEAK_MARKER]], 'tool_use', $echo)->body));
                $left = $gateway->complete(new Request(Messages::MODEL, [Message::user('Where to?')], 30.0, [Messages::lookup()]));
                break;
            case 'an answer with thinking that holds no key':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::answered([Messages::thinking(), Messages::text()], 'end_turn', $echo)->body));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose thinking repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::answered([Messages::thinking($thinking), Messages::text()], 'end_turn', $echo)->body));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose thinking repeats the key, written with escapes':
                $transport->willReturn(new HttpResponse(200, [], str_replace('written-with-escapes', $escaped, Messages::answered([Messages::thinking('The key is written-with-escapes.'), Messages::text()])->body)));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose signature repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::answered([Messages::thinking('Hm.', LEAK_MARKER), Messages::text()])->body));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose redacted thinking repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::answered([['type' => 'redacted_thinking', 'data' => LEAK_MARKER], Messages::text()])->body));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose thinking block repeats the key in a member no page names':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::answered([[...Messages::thinking(), 'more' => ['owner' => strtolower(LEAK_MARKER)]], Messages::text()])->body));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose block of an unknown kind repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::answered([Messages::thinking(), ['type' => 'server_tool_use', 'id' => LEAK_MARKER, 'input' => ['key' => LEAK_MARKER]], Messages::text()])->body));
                $left = $gateway->complete($request);
                break;
            case 'a stream':
                $transport->willStream(200, $headers, ...$stream);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream with thinking that holds no key':
                $transport->willStream(200, $headers, $stream[0], $start, Messages::blockStarted(1, Messages::thinking('', '')), Messages::delta(1, 'thinking_delta', 'Hm.'), Messages::delta(1, 'signature_delta', 'c2ln'), ...$said);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose thinking repeats the key':
                $transport->willStream(200, $headers, $stream[0], $start, Messages::blockStarted(1, Messages::thinking('', '')), Messages::delta(1, 'thinking_delta', $thinking), ...$said);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose thinking repeats the key in pieces':
                $pieces = [Messages::delta(1, 'thinking_delta', 'The key is ' . substr(LEAK_MARKER, 0, 9)), Messages::delta(1, 'thinking_delta', substr(LEAK_MARKER, 9) . '.')];
                $transport->willStream(200, [], $start, Messages::blockStarted(1, Messages::thinking('', '')), str_replace(substr(LEAK_MARKER, 0, 9), substr($escaped, 0, 54), $pieces[0]), $pieces[1], ...$said);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose signature repeats the key in pieces':
                $transport->willStream(200, [], $start, Messages::blockStarted(1, Messages::thinking('', '')), Messages::delta(1, 'thinking_delta', 'Hm.'), Messages::delta(1, 'signature_delta', substr(LEAK_MARKER, 0, 9)), Messages::delta(1, 'signature_delta', substr(LEAK_MARKER, 9)), ...$said);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose thinking block starts with the key':
                $transport->willStream(200, [], $start, Messages::blockStarted(1, ['type' => 'redacted_thinking', 'data' => LEAK_MARKER]), Messages::blockStarted(2, Messages::thinking(LEAK_MARKER, LEAK_MARKER)), ...$said);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream of a server that does not stream':
                $transport->willReturn($good);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that breaks':
                $transport->willStream(200, $headers, ...$stream)->willBreakStreamAfter(5, TransportFailure::ConnectionFailed);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that ends without its last event':
                $transport->willStream(200, $headers, ...array_slice($stream, 0, 8));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that is refused':
                $transport->willStream(401, $headers, $fault);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream with an error event':
                $transport->willStream(200, $headers, $stream[0], $stream[1], $stream[3], $stream[4], "event: error\ndata: " . $fault . "\n\n");
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream with an error event of a known type':
                $transport->willStream(200, $headers, $stream[0], Messages::event('error', ['error' => ['type' => 'overloaded_error', 'message' => LEAK_MARKER, 'details' => ['error_code' => 'code-' . LEAK_MARKER]], 'request_id' => LEAK_MARKER]));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream of lines that are no JSON':
                $transport->willStream(200, $headers, 'data: ' . LEAK_MARKER . "\n\n", 'event: ' . LEAK_MARKER . "\ndata: {\"type\":\"" . LEAK_MARKER . "\"}\n\n", "event: message_start\ndata: {\"message\":\"" . LEAK_MARKER . "\"}\n\n");
                $left = Talk::events($gateway->stream($request));
                break;
            case 'an answer whose model repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::answered(more: ['model' => 'deployment-of-' . LEAK_MARKER])->body));
                $left = $gateway->complete($request);
                break;
            case 'a stream whose model repeats the key':
                $transport->willStream(200, $headers, Messages::started(model: LEAK_MARKER), ...array_slice($stream, 3));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a tool call whose id repeats the key':
                $transport->willReturn(Messages::answered([Messages::call([], LEAK_MARKER)], 'tool_use'));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose name repeats the key':
                $transport->willReturn(Messages::answered([Messages::call([], 'toolu_example01', LEAK_MARKER)], 'tool_use'));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose input repeats the key':
                $transport->willReturn(Messages::answered([Messages::call(['headers' => ['authorization' => 'Bearer ' . LEAK_MARKER], 'path' => 'a/b'])], 'tool_use'));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose input repeats the key, written with escapes':
                $transport->willReturn(new HttpResponse(200, [], str_replace('written-with-escapes', $escaped, Messages::answered([Messages::call(['token' => 'written-with-escapes'])], 'tool_use')->body)));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose input is a string that repeats the key':
                $transport->willReturn(Messages::answered([Messages::call('{"token": "' . LEAK_MARKER)], 'tool_use'));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose input repeats the key, beside thinking':
                $transport->willReturn(Messages::answered([Messages::thinking(), Messages::call(['token' => LEAK_MARKER])], 'tool_use'));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose name repeats the key, in a stream':
                $transport->willStream(200, $headers, $start, Messages::blockStarted(0, Messages::call([], 'toolu_example01', LEAK_MARKER)), Messages::blockStopped(0), ...$toolStop);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a tool call whose id repeats the key, in a stream':
                $transport->willStream(200, $headers, $start, Messages::blockStarted(0, Messages::call([], strtolower(LEAK_MARKER))), Messages::blockStopped(0), ...$toolStop);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a tool call whose input repeats the key in pieces, in a stream':
                $transport->willStream(
                    200,
                    $headers,
                    $start,
                    Messages::blockStarted(0, Messages::call([])),
                    Messages::delta(0, 'input_json_delta', '{"key":"' . substr(LEAK_MARKER, 0, 9)),
                    Messages::delta(0, 'input_json_delta', substr(LEAK_MARKER, 9) . '"}'),
                    Messages::blockStopped(0),
                    ...$toolStop,
                );
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a tool call whose block starts with an input that repeats the key, in a stream':
                $transport->willStream(200, $headers, $start, Messages::blockStarted(0, Messages::call(['token' => LEAK_MARKER])), Messages::blockStopped(0), ...$toolStop);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a model list whose entries repeat the key':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::json(['data' => [['id' => LEAK_MARKER], ['id' => 'example-messages-1', 'display_name' => 'Model of ' . LEAK_MARKER], ['id' => 'example-messages-2/' . strtolower(LEAK_MARKER)], ['id' => 'example-messages-3']]])));
                $left = $gateway->models(10.0);
                break;
            case 'the model list':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::json(['data' => [['id' => 'example-messages-1', 'type' => LEAK_MARKER, 'lifecycle' => LEAK_MARKER, 'capabilities' => [LEAK_MARKER => ['supported' => true]], 'created_at' => LEAK_MARKER]], 'has_more' => true, 'first_id' => LEAK_MARKER, 'last_id' => LEAK_MARKER])));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is refused':
                $transport->willReturn(new HttpResponse(403, $headers, $fault));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is no list':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::json(['message' => LEAK_MARKER, 'data' => LEAK_MARKER])));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is an error under a 200':
                $transport->willReturn(new HttpResponse(200, $headers, $fault));
                $left = $gateway->models(10.0);
                break;
            case 'a sign-in that is refused':
            case 'a header key that comes back as the code':
            case 'a token that comes back as the code':
                $transport->willReturn(new HttpResponse(401, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a type with the key inside it':
                $transport->willReturn(new HttpResponse(400, $headers, Messages::errorBody('invalid_' . strtoupper(LEAK_MARKER) . '.x', null, LEAK_MARKER)));
                $left = $gateway->complete($request);
                break;
            case 'a code with the key inside it':
                $transport->willReturn(new HttpResponse(400, $headers, Messages::errorBody('invalid_request_error', 'code_' . strtolower(LEAK_MARKER), LEAK_MARKER)));
                $left = $gateway->complete($request);
                break;
            case 'the spend cap, in words that repeat the key':
                $transport->willReturn(new HttpResponse(429, $headers, Messages::errorBody('rate_limit_error', 'enforced_spend_limit_reached', 'The account of ' . LEAK_MARKER . ' reached its cap.')));
                $left = $gateway->complete($request);
                break;
            case 'a rate limit with a wait that is too long':
                $transport->willReturn(new HttpResponse(429, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a failure that must not be tried again':
                $transport->willReturn(new HttpResponse(529, [...$headers, 'x-should-retry' => 'false'], $fault));
                $left = $gateway->complete($request);
                break;
            case 'a header that forbids a retry with the key as its word':
                $transport->willReturn(new HttpResponse(529, ['x-should-retry' => LEAK_MARKER], $fault))->willReturn(new HttpResponse(529, ['x-should-retry' => LEAK_MARKER], $fault));
                $left = $gateway->complete($request);
                break;
            case 'a server error after the retries':
                $transport->willReturn(new HttpResponse(500, ['X-Echo' => LEAK_MARKER], $fault))->willReturn(new HttpResponse(502, ['X-Echo' => LEAK_MARKER], '<html>' . LEAK_MARKER . '</html>'));
                $left = $gateway->complete($request);
                break;
            case 'a request that is refused':
                $transport->willReturn(new HttpResponse(400, $headers, $fault));
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
            case 'a body that cannot be decoded':
                $transport->willReturn(new HttpResponse(200, $headers, 'not JSON: ' . LEAK_MARKER));
                $left = $gateway->complete($request);
                break;
            case 'a body without an answer':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::json(['content' => LEAK_MARKER, 'echo' => LEAK_MARKER])));
                $left = $gateway->complete($request);
                break;
            case 'an answer that holds nothing but the key where no text is read':
                $transport->willReturn(new HttpResponse(200, $headers, Messages::answered([['type' => 'text', 'text' => ['a' => LEAK_MARKER]], ['type' => LEAK_MARKER, 'text' => LEAK_MARKER]], LEAK_MARKER, $echo)->body));
                $left = $gateway->complete($request);
                break;
            case 'an option whose value is the key':
            case 'an option whose name is the key':
                $left = $gateway->complete($request);
                break;
            case 'a temperature the model does not take':
                $left = $gateway->complete(new Request(Messages::MODEL, [Message::user(LEAK_MARKER)], 30.0, temperature: 0.5));
                break;
            case 'a transcript that ends with an assistant message':
                $left = $gateway->complete(Messages::request(Message::user(LEAK_MARKER), Message::assistant(LEAK_MARKER)));
                break;
            case 'a tool name the format cannot carry':
                $left = $gateway->complete(new Request(Messages::MODEL, [Message::user(LEAK_MARKER)], 30.0, [Messages::lookup('lookup.' . LEAK_MARKER)]));
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
            default => 'models: ' . count($left),
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

describe('what leaves complete(), stream() and models() of the Messages adapter', function (): void {
    it('holds the key in no exception, no event and no other output form', function (string $scenario, string $whatLeft): void {
        foreach ([false, true] as $ignoreArgs) {
            withExceptionIgnoreArgs($ignoreArgs, function () use ($scenario, $whatLeft): void {
                [$left, $forms] = messagesLeakOf($scenario);

                expect($left)->toBe($whatLeft);
                expectNoMarker($forms);
            });
        }
    })->with([
        ['an answer', 'answer'],
        ['an answer after a retry', 'answer'],
        ['an answer with a tool call', 'answer'],
        ['an answer with thinking that holds no key', 'answer with an opaque part'],
        // The thinking held the key: the part that would carry it to wherever the conversation is stored is dropped.
        ['an answer whose thinking repeats the key', 'answer'],
        ['an answer whose thinking repeats the key, written with escapes', 'answer'],
        ['an answer whose signature repeats the key', 'answer'],
        ['an answer whose redacted thinking repeats the key', 'answer'],
        ['an answer whose thinking block repeats the key in a member no page names', 'answer'],
        // A block of a kind the adapter does not keep: nothing is kept, so nothing of it travels on.
        ['an answer whose block of an unknown kind repeats the key', 'answer'],
        ['a stream', 'finished'],
        ['a stream with thinking that holds no key', 'finished with an opaque part'],
        ['a stream whose thinking repeats the key', 'finished'],
        ['a stream whose thinking repeats the key in pieces', 'finished'],
        ['a stream whose signature repeats the key in pieces', 'finished'],
        ['a stream whose thinking block starts with the key', 'finished'],
        ['a stream of a server that does not stream', 'finished'],
        ['a stream that breaks', 'failed: unavailable'],
        ['a stream that ends without its last event', 'failed: provider_error'],
        ['a stream that is refused', 'failed: credentials_rejected'],
        ['a stream with an error event', 'failed: provider_error'],
        ['a stream with an error event of a known type', 'failed: unavailable'],
        ['a stream of lines that are no JSON', 'failed: provider_error'],
        // The name of the model falls back to the model of the request.
        ['an answer whose model repeats the key', 'answer'],
        ['a stream whose model repeats the key', 'finished'],
        // A tool call cannot be handed on in part: the answer fails.
        ['a tool call whose id repeats the key', 'provider_error'],
        ['a tool call whose name repeats the key', 'provider_error'],
        ['a tool call whose input repeats the key', 'provider_error'],
        ['a tool call whose input repeats the key, written with escapes', 'provider_error'],
        ['a tool call whose input is a string that repeats the key', 'provider_error'],
        ['a tool call whose input repeats the key, beside thinking', 'provider_error'],
        ['a tool call whose name repeats the key, in a stream', 'failed: provider_error'],
        ['a tool call whose id repeats the key, in a stream', 'failed: provider_error'],
        ['a tool call whose input repeats the key in pieces, in a stream', 'failed: provider_error'],
        ['a tool call whose block starts with an input that repeats the key, in a stream', 'failed: provider_error'],
        ['a model list whose entries repeat the key', 'models: 1'],
        ['the model list', 'models: 1'],
        ['a model list that is refused', 'credentials_rejected'],
        ['a model list that is no list', 'provider_error'],
        ['a model list that is an error under a 200', 'provider_error'],
        ['a sign-in that is refused', 'credentials_rejected'],
        ['a header key that comes back as the code', 'credentials_rejected'],
        ['a token that comes back as the code', 'credentials_rejected'],
        ['a type with the key inside it', 'rejected'],
        ['a code with the key inside it', 'rejected'],
        ['the spend cap, in words that repeat the key', 'rejected with a code'],
        ['a rate limit with a wait that is too long', 'rate_limited'],
        ['a failure that must not be tried again', 'unavailable'],
        ['a header that forbids a retry with the key as its word', 'unavailable'],
        ['a server error after the retries', 'unavailable'],
        ['a request that is refused', 'rejected'],
        ['a redirect', 'unavailable'],
        ['a lost connection', 'unavailable'],
        ['an error inside a 200', 'provider_error'],
        ['a body that cannot be decoded', 'provider_error'],
        ['a body without an answer', 'provider_error'],
        ['an answer that holds nothing but the key where no text is read', 'provider_error'],
        ['an option whose value is the key', 'not_configured'],
        ['an option whose name is the key', 'not_configured'],
        ['a temperature the model does not take', 'not_supported'],
        ['a transcript that ends with an assistant message', 'rejected'],
        ['a tool name the format cannot carry', 'rejected'],
        ['a token supplier that fails', 'credentials_rejected'],
    ]);

    it('drops the opaque part of an answer whose thinking repeats the credential, and keeps the answer', function (): void {
        $content = [Messages::thinking('The caller signs in with key-of-the-test.'), Messages::text()];
        $transport = (new FakeTransport())->willReturn(Messages::answered($content))->willReturn(Messages::answered([Messages::thinking('Nothing secret.'), Messages::text()]));
        $gateway = Messages::gateway($transport);

        $withTheKey = $gateway->complete(Messages::request());
        $without = $gateway->complete(Messages::request());

        expect($withTheKey->opaque)->toBeNull()
            ->and($withTheKey->text)->toBe('Hello.')
            ->and($without->opaque)->not->toBeNull();
    });

    it('names the model of the request when the model of an answer repeats a credential or is no name', function (string $model): void {
        $transport = (new FakeTransport())
            ->willReturn(Messages::answered(more: ['model' => $model]))
            ->willStream(200, [], Messages::started(model: $model), Messages::blockStarted(0, Messages::text('Hello.')), ...Messages::stopped());
        $gateway = Messages::gateway($transport);

        expect($gateway->complete(Messages::request())->model)->toBe(Messages::MODEL)
            ->and(Talk::finishedIn(Talk::events($gateway->stream(Messages::request())))->answer->model)->toBe(Messages::MODEL);
    })->with([
        'the credential' => ['key-of-the-test'],
        'the credential inside a longer name' => ['deployment/Key-Of-The-Test/v2'],
        'a line end' => ["example-messages-1\r\nX-Injected: 1"],
        'a tab' => ["example\tmessages"],
    ]);

    it('lists the models without the entries whose id or name repeats a credential', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(200, [], Messages::json(['data' => [
            ['id' => 'key-of-the-test'],
            ['id' => 'example-messages-1', 'display_name' => 'The KEY-OF-THE-TEST model'],
            ['id' => 'example-messages-3', 'display_name' => 'Example Messages 3'],
        ]])));

        expect(array_map(fn($model): string => $model->id, Messages::gateway($transport)->models(10.0)))->toBe(['example-messages-3']);
    });

    it('fails an answer whose tool call repeats a credential, with a detail that holds nothing of the call', function (): void {
        $transport = (new FakeTransport())->willReturn(Messages::answered([Messages::call(['city' => 'Paris']), Messages::call(['token' => 'key-of-the-test'], 'toolu_example02')], 'tool_use'));
        $error = Talk::errorOf(fn() => Messages::gateway($transport)->complete(Messages::request()));

        expect($error->reason->value)->toBe('provider_error')
            ->and($error->detail)->toBe('a tool call of the answer repeats a credential of the gateway and is not handed on')
            ->and($error->httpStatus)->toBe(200);
    });

    it('shows no key in a dump of the gateway itself, apart from what the transport of the host holds', function (): void {
        $gateway = new MessagesAdapter(Messages::preset(), new GatewayConfig(RetryPolicy::none(), clock: new FakeClock()), new BearerKey(LEAK_MARKER), new FakeTransport());

        expectNoMarker(valueForms($gateway));
    });

    it('never puts a name of the format or of its provider into an error, whatever failed', function (callable $call): void {
        $transport = new FakeTransport();
        $error = Talk::errorOf(fn() => $call($transport));

        // What a caller reads of an error: its reason, its code, its detail, its message and its JSON form.
        $read = implode("\n", [$error->reason->value, (string) $error->providerCode, (string) $error->detail, $error->getMessage(), (string) json_encode($error)]);

        expect(preg_match('/anthropic|claude|messages/i', $read))->toBe(0);
    })->with([
        'a request that is refused' => [fn(FakeTransport $transport) => Messages::gateway($transport->willReturn(Messages::failed(400)))->complete(Messages::request())],
        'a sign-in that is refused' => [fn(FakeTransport $transport) => Messages::gateway($transport->willReturn(Messages::failed(401, 'authentication_error')))->complete(Messages::request())],
        'a failure that must not be tried again' => [fn(FakeTransport $transport) => Messages::gateway($transport->willReturn(Messages::failed(529, 'overloaded_error', ['x-should-retry' => 'false'])))->complete(Messages::request())],
        'an error event in a stream' => [function (FakeTransport $transport): void {
            throw Talk::failedIn(Talk::events(Messages::gateway($transport->willStream(200, [], Messages::event('error', ['error' => ['type' => 'overloaded_error']])))->stream(Messages::request())))->error;
        }],
        'a stream without its last event' => [function (FakeTransport $transport): void {
            throw Talk::failedIn(Talk::events(Messages::gateway($transport->willStream(200, [], Messages::started()))->stream(Messages::request())))->error;
        }],
        'an option with a value outside its list' => [fn(FakeTransport $transport) => Messages::gateway($transport, ['api_version' => 'soon'])->complete(Messages::request())],
        'an option with a name the adapter does not know' => [fn(FakeTransport $transport) => Messages::gateway($transport, [...Messages::EARLIER_VERSION, 'other' => 1])->capabilities(Messages::MODEL)],
        'no version of the API' => [fn(FakeTransport $transport) => Messages::gateway($transport, [])->models(10.0)],
        'no maximum of output tokens' => [fn(FakeTransport $transport) => Messages::gateway($transport, ['api_version' => '2026-10-21'])->complete(Messages::request())],
        'a temperature the model does not take' => [fn(FakeTransport $transport) => Messages::gateway($transport, Messages::CURRENT_VERSION)->complete(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, temperature: 0.5))],
        'a transcript that ends with an assistant message' => [fn(FakeTransport $transport) => Messages::gateway($transport)->complete(Messages::request(Message::user('Hi.'), Message::assistant('Hello.')))],
        'a tool name the format cannot carry' => [fn(FakeTransport $transport) => Messages::gateway($transport)->complete(new Request(Messages::MODEL, [Message::user('Hi.')], 30.0, [Messages::lookup('look.up')]))],
        'a body without an answer' => [fn(FakeTransport $transport) => Messages::gateway($transport->willReturn(new HttpResponse(200, [], '{"id":"msg_example01"}')))->complete(Messages::request())],
        'an error body under a 200' => [fn(FakeTransport $transport) => Messages::gateway($transport->willReturn(new HttpResponse(200, [], Messages::errorBody('api_error'))))->complete(Messages::request())],
        'a model list that is no list' => [fn(FakeTransport $transport) => Messages::gateway($transport->willReturn(new HttpResponse(200, [], '{"models":[]}')))->models(10.0)],
        'a model list that cannot be asked for' => [fn(FakeTransport $transport) => Messages::gateway($transport, [...Messages::EARLIER_VERSION, 'model_listing' => 'none'])->models(10.0)],
    ]);
});

describe('the parameters of the Messages adapter that carry a response, a request or a message', function (): void {
    it('are marked as sensitive, so that an error thrown below them keeps none of them in its printed trace', function (string $class, string $method, array $expected): void {
        $sensitive = [];
        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                $sensitive[] = $parameter->getName();
            }
        }

        expect($sensitive)->toBe($expected);
    })->with([
        'completionRequest' => [MessagesAdapter::class, 'completionRequest', ['request']],
        'messages' => [MessagesAdapter::class, 'messages', ['request']],
        'assistantBlocks' => [MessagesAdapter::class, 'assistantBlocks', ['message']],
        'blocksThatCameWith' => [MessagesAdapter::class, 'blocksThatCameWith', ['message']],
        'input' => [MessagesAdapter::class, 'input', ['toolCall']],
        'toolFields' => [MessagesAdapter::class, 'toolFields', ['request']],
        'readCompletion' => [MessagesAdapter::class, 'readCompletion', ['response', 'request']],
        'readStream' => [MessagesAdapter::class, 'readStream', ['events', 'request']],
        'toolCallOf' => [MessagesAdapter::class, 'toolCallOf', ['call']],
        'sealedContent' => [MessagesAdapter::class, 'sealedContent', ['text', 'toolCalls', 'body', 'content']],
        'readModels' => [MessagesAdapter::class, 'readModels', ['response']],
        'providerCode' => [MessagesAdapter::class, 'providerCode', ['response']],
        'refineReason' => [MessagesAdapter::class, 'refineReason', ['response']],
        'retryAllowed' => [MessagesAdapter::class, 'retryAllowed', ['response']],
        'ContentBlocks::mustGoBack' => [ContentBlocks::class, 'mustGoBack', ['content']],
        'ContentBlocks::kept' => [ContentBlocks::class, 'kept', ['json']],
        'ContentBlocks::isWithinTheLimits' => [ContentBlocks::class, 'isWithinTheLimits', ['json']],
        'ContentBlocks::agreeing' => [ContentBlocks::class, 'agreeing', ['list', 'text', 'calls']],
        'ContentBlocks::start' => [ContentBlocks::class, 'start', ['pieces', 'json']],
        'ContentBlocks::add' => [ContentBlocks::class, 'add', ['pieces', 'piece']],
        'ContentBlocks::holdThinking' => [ContentBlocks::class, 'holdThinking', ['pieces']],
        'ContentBlocks::written' => [ContentBlocks::class, 'written', ['pieces']],
        'ErrorBody::decoded' => [ErrorBody::class, 'decoded', ['body']],
        'ErrorBody::isIn' => [ErrorBody::class, 'isIn', ['body']],
        'ErrorBody::code' => [ErrorBody::class, 'code', ['body']],
        'ErrorBody::saysSpendLimit' => [ErrorBody::class, 'saysSpendLimit', ['body']],
        'ErrorBody::reasonInAStream' => [ErrorBody::class, 'reasonInAStream', ['body']],
        'Reading::string' => [Reading::class, 'string', ['holder']],
        'Reading::members' => [Reading::class, 'members', ['holder']],
        'Reading::counts' => [Reading::class, 'counts', ['holder']],
        'Reading::freeId' => [Reading::class, 'freeId', ['taken']],
        'Reading::toolCall' => [Reading::class, 'toolCall', ['name', 'input', 'written']],
        'Reading::toolCallFromText' => [Reading::class, 'toolCallFromText', ['name', 'text']],
        'ModelList::read' => [ModelList::class, 'read', ['body']],
    ]);

    it('covers every method of the adapter and its helpers that takes a response, a request, a message or a body', function (): void {
        $carriers = ['HttpRequest', 'HttpResponse', 'Request', 'Message', 'Answer', 'ToolCall', 'iterable', 'mixed'];
        $unmarked = [];
        $classes = 0;
        foreach (SourceCode::classesOfTheLibrary(dirname(__DIR__, 4) . '/src') as $path => $class) {
            if (! str_starts_with((string) $path, 'Adapter/Anthropic/')) {
                continue;
            }
            $classes++;
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

        expect($classes)->toBe(7)
            ->and($unmarked)->toBe([]);
    });
});
