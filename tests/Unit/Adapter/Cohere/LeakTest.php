<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Cohere\ChatV2Adapter;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
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
use OrganiseYou\LlmGateway\Tests\Support\ChatV2;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

// LR-26 for the Chat v2 adapter: the key or the token a gateway signs in with appears in no exception, no event and
// no other output, whatever a server repeats of it. In every scenario below the marker is the credential (a key as a
// bearer, or the token a supplier of the host gave), and the server repeats it wherever its format has room: in the
// headers, in a comment line and an event name of a stream, in fields the adapter does not read, in the tool plan,
// in thinking, in a tool call's id, name and arguments, in the model list, and in the words of an error.

/**
 * Runs one call in which the marker is the credential and the server repeats it, and gives a word that says what
 * left the call with every output form of it, and of the requests in the reports of the attempts.
 *
 * The scenario is named, not handed in: a response among the arguments of this function would put the marker in
 * the trace of whatever is thrown below it, and that would be a leak of the test, not of the library.
 *
 * @param string $signIn `key`: a key as a bearer; `token`: a token from a supplier
 * @return array{string, array<string, string>}
 */
function chatV2LeakOf(string $scenario, string $signIn): array
{
    $headers = [
        'Set-Cookie' => 'session=' . LEAK_MARKER, 'X-Echo' => LEAK_MARKER, 'retry-after' => '120',
        'Authorization' => 'Bearer ' . LEAK_MARKER, 'Location' => 'https://other.example.test/?key=' . LEAK_MARKER, 'x-request-id' => LEAK_MARKER,
    ];
    // Every place of an answer the adapter reads past.
    $echo = [
        'id' => LEAK_MARKER,
        'meta' => ['api_version' => ['version' => LEAK_MARKER], 'warnings' => ['The key ' . LEAK_MARKER . ' was seen.']],
        'usage' => ['billed_units' => ['input_tokens' => 1, 'output_tokens' => 1, 'search_units' => 0], 'tokens' => ['input_tokens' => 3, 'output_tokens' => 2], 'cached_tokens' => 0, LEAK_MARKER => LEAK_MARKER],
        'logprobs' => [['token_ids' => [1], 'text' => LEAK_MARKER]],
        'echo' => ['authorization' => 'Bearer ' . LEAK_MARKER, 'url' => 'https://llm.example.test/v2/chat?key=' . LEAK_MARKER],
    ];
    // A body whose message holds this content, these calls and this plan, beside what the adapter reads past.
    $answer = function (?array $content, array $calls = [], ?string $finish = 'auto', ?string $plan = null, array $more = []) use ($echo): string {
        $body = ChatV2::body($content === null ? null : array_values($content), array_values($calls), $finish, $plan, [...$echo, ...$more]);
        $body['message'] = [...$body['message'], 'citations' => [['start' => 0, 'end' => 1, 'text' => LEAK_MARKER, 'sources' => [['type' => 'tool', 'id' => LEAK_MARKER]]]], LEAK_MARKER => true];

        return ChatV2::json($body);
    };
    $text = ['type' => 'text', 'text' => 'Hello.'];
    $good = new HttpResponse(200, $headers, $answer([$text]));
    $fault = ChatV2::json(['id' => LEAK_MARKER, 'message' => 'invalid api token: ' . LEAK_MARKER, 'data' => [LEAK_MARKER => LEAK_MARKER]]);
    $plan = 'The key of this caller is ' . LEAK_MARKER . ', the path is C:\\keys/' . LEAK_MARKER . '.';
    // The credential as a server could write it in JSON text: every character as an escape with its number.
    $escaped = '';
    foreach (str_split(LEAK_MARKER) as $character) {
        $escaped .= "\x5cu" . sprintf('%04x', ord($character));
    }
    $comment = ': ' . LEAK_MARKER . "\n\n";
    $piece = fn(string $piece, int $index = 0): string => ChatV2::event('content-delta', ['index' => $index, 'delta' => ['message' => ['content' => ['text' => $piece]]], 'echo' => LEAK_MARKER]);
    $ended = fn(?string $finish = 'COMPLETE', array $more = []): string => ChatV2::event('message-end', ['id' => LEAK_MARKER, 'delta' => ['finish_reason' => $finish, 'usage' => $echo['usage'], ...$more]]);
    $started = ChatV2::event('message-start', ['id' => LEAK_MARKER, 'delta' => ['message' => ['role' => 'assistant']]]);
    $stream = [$comment, $started, ChatV2::blockStart(), $piece('Hel'), 'event: ' . LEAK_MARKER . "\ndata: {\"key\":\"" . LEAK_MARKER . "\"}\n\n", $piece('lo.'), ChatV2::blockEnd(), $ended()];
    $withTools = new Request(ChatV2::MODEL, [Message::user('Where to?')], 30.0, [ChatV2::lookup()]);

    $clock = new FakeClock();
    $transport = new FakeTransport($clock);
    $recorder = new AttemptRecorder();
    $tokens = Talk::tokens();
    $tokens->next = LEAK_MARKER;
    $credentials = $signIn === 'token' ? new BearerToken($tokens) : new BearerKey(LEAK_MARKER);
    $options = match ($scenario) {
        'a stream of a server that does not stream' => ['streaming' => 'emulated'],
        'an option whose value is the key' => ['schema_key' => LEAK_MARKER],
        'an option whose name is the key' => [LEAK_MARKER => 'x'],
        'a temperature the model does not take' => ['temperature' => 'not_accepted'],
        default => [],
    };
    $gateway = ChatV2::gateway($transport, $options, new RetryPolicy([1.0], 60.0), $clock, $credentials, $recorder, ['workspace' => 'example-host']);
    $request = ChatV2::request();

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
                $transport->willReturn(new HttpResponse(503, ['X-Echo' => LEAK_MARKER, 'retry-after' => '1'], $fault))->willReturn($good);
                $left = $gateway->complete($request);
                break;
            case 'an answer with a tool call':
                $transport->willReturn(new HttpResponse(200, $headers, $answer([$text], [[...ChatV2::call(), 'caller' => LEAK_MARKER]])));
                $left = $gateway->complete($withTools);
                break;
            case 'an answer with a plan and thinking that hold no key':
                $transport->willReturn(new HttpResponse(200, $headers, $answer([ChatV2::thinking('One city.'), $text], [ChatV2::call()], plan: 'I will look.')));
                $left = $gateway->complete($withTools);
                break;
            case 'an answer whose plan repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, $answer(null, [ChatV2::call()], plan: $plan)));
                $left = $gateway->complete($withTools);
                break;
            case 'an answer whose plan repeats the key in another case':
                $transport->willReturn(new HttpResponse(200, $headers, $answer(null, [ChatV2::call()], plan: 'plan-' . strtolower(LEAK_MARKER) . '-end')));
                $left = $gateway->complete($withTools);
                break;
            case 'an answer whose plan repeats the key, written with escapes':
                $transport->willReturn(new HttpResponse(200, [], str_replace('written-with-escapes', $escaped, ChatV2::json(ChatV2::body(null, [ChatV2::call()], plan: 'The key is written-with-escapes.')))));
                $left = $gateway->complete($withTools);
                break;
            case 'an answer whose thinking repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, $answer([ChatV2::thinking($plan), $text])));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose thinking repeats the key, written with escapes':
                $transport->willReturn(new HttpResponse(200, [], str_replace('written-with-escapes', $escaped, ChatV2::json(ChatV2::body([ChatV2::thinking('The key is written-with-escapes.'), $text])))));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose block of an unknown kind repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, $answer([ChatV2::thinking('Hm.'), ['type' => 'image_url', 'image_url' => ['url' => 'https://example.test/' . LEAK_MARKER]], $text])));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose thinking block repeats the key in a member no page names':
                $transport->willReturn(new HttpResponse(200, $headers, $answer([[...ChatV2::thinking('Hm.'), 'signature' => strtolower(LEAK_MARKER)], $text])));
                $left = $gateway->complete($request);
                break;
            case 'a stream':
                $transport->willStream(200, $headers, ...$stream);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream with a plan that holds no key':
                $transport->willStream(200, $headers, $comment, $started, ChatV2::planPiece('I will '), ChatV2::planPiece('look.'), ...[...ChatV2::calling(), $ended('TOOL_CALL')]);
                $left = Talk::events($gateway->stream($withTools));
                break;
            case 'a stream whose plan repeats the key in pieces':
                $transport->willStream(
                    200,
                    [],
                    $started,
                    str_replace('first-half', substr($escaped, 0, 54), ChatV2::planPiece('The key is first-half')),
                    ChatV2::planPiece(substr(LEAK_MARKER, 9) . '.'),
                    ...[...ChatV2::calling(), $ended('TOOL_CALL')],
                );
                $left = Talk::events($gateway->stream($withTools));
                break;
            case 'a stream whose thinking repeats the key in pieces':
                $transport->willStream(
                    200,
                    $headers,
                    $started,
                    ChatV2::blockStart(0, 'thinking'),
                    ChatV2::piece(substr(LEAK_MARKER, 0, 9), 0, 'thinking'),
                    ChatV2::piece(substr(LEAK_MARKER, 9), 0, 'thinking'),
                    ChatV2::blockStart(1),
                    $piece('Hello.', 1),
                    $ended(),
                );
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream of a server that does not stream':
                $transport->willReturn($good);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that breaks':
                $transport->willStream(200, $headers, ...$stream)->willBreakStreamAfter(4, TransportFailure::ConnectionFailed);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream without its last event':
                $transport->willStream(200, $headers, ...array_slice($stream, 0, 6));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that is refused':
                $transport->willStream(401, $headers, $fault);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose last event holds an error that repeats the key':
                $transport->willStream(200, $headers, $started, ChatV2::blockStart(), $piece('Hel'), $ended('ERROR', ['error' => 'Failed for ' . LEAK_MARKER]));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose last event holds an error object':
                $transport->willStream(200, $headers, $started, ChatV2::blockStart(), $piece('Hel'), $ended('COMPLETE', ['error' => ['message' => LEAK_MARKER, LEAK_MARKER => LEAK_MARKER]]));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream with the error body as an event':
                $transport->willStream(200, $headers, $started, 'data: ' . $fault . "\n\n");
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose finish reason is the key':
                $transport->willStream(200, $headers, $started, ChatV2::blockStart(), $piece('Hello.'), $ended(LEAK_MARKER));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream of lines that are no JSON':
                $transport->willStream(200, $headers, 'data: ' . LEAK_MARKER . "\n\n", 'event: ' . LEAK_MARKER . "\ndata: {\"delta\":\"" . LEAK_MARKER . "\"}\n\n", 'event: content-delta' . "\ndata: [\"" . LEAK_MARKER . "\"]\n\n");
                $left = Talk::events($gateway->stream($request));
                break;
            case 'an answer whose finish reason is the key':
                $transport->willReturn(new HttpResponse(200, $headers, $answer([$text], [], LEAK_MARKER)));
                $left = $gateway->complete($request);
                break;
            case 'an answer that ended as a failed generation, in words that repeat the key':
                $transport->willReturn(new HttpResponse(200, $headers, $answer([['type' => 'text', 'text' => 'Failed for ' . LEAK_MARKER]], [], 'ERROR')));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose id repeats the key':
                $transport->willReturn(ChatV2::answered(null, [ChatV2::call('{}', LEAK_MARKER)]));
                $left = $gateway->complete($withTools);
                break;
            case 'a tool call whose name repeats the key':
                $transport->willReturn(ChatV2::answered(null, [ChatV2::call('{}', 'lookup_example01', LEAK_MARKER)]));
                $left = $gateway->complete($withTools);
                break;
            case 'a tool call whose arguments repeat the key':
                $transport->willReturn(ChatV2::answered(null, [ChatV2::call('{"headers":{"authorization":"Bearer ' . LEAK_MARKER . '"}}')]));
                $left = $gateway->complete($withTools);
                break;
            case 'a tool call whose arguments repeat the key, written with escapes':
                $transport->willReturn(new HttpResponse(200, [], str_replace('written-with-escapes', str_replace("\x5c", "\x5c\x5c", $escaped), ChatV2::json(ChatV2::body(null, [ChatV2::call('{"token":"written-with-escapes"}')])))));
                $left = $gateway->complete($withTools);
                break;
            case 'a tool call whose arguments that cannot be read repeat the key':
                $transport->willReturn(ChatV2::answered(null, [ChatV2::call('{"token": "' . LEAK_MARKER)]));
                $left = $gateway->complete($withTools);
                break;
            case 'a tool call whose id repeats the key, in a stream':
                $transport->willStream(200, $headers, $started, ...[...ChatV2::calling(0, strtolower(LEAK_MARKER)), $ended('TOOL_CALL')]);
                $left = Talk::events($gateway->stream($withTools));
                break;
            case 'a tool call whose name repeats the key, in a stream':
                $transport->willStream(200, $headers, $started, ...[...ChatV2::calling(0, 'lookup_example01', ['{}'], LEAK_MARKER), $ended('TOOL_CALL')]);
                $left = Talk::events($gateway->stream($withTools));
                break;
            case 'a tool call whose arguments repeat the key in pieces, in a stream':
                $transport->willStream(200, $headers, $started, ...[...ChatV2::calling(0, 'lookup_example01', ['{"token":"' . substr(LEAK_MARKER, 0, 9), substr(LEAK_MARKER, 9) . '"}']), $ended('TOOL_CALL')]);
                $left = Talk::events($gateway->stream($withTools));
                break;
            case 'a model list whose entries repeat the key':
                $transport->willReturn(new HttpResponse(200, $headers, ChatV2::json(['models' => [
                    ['name' => LEAK_MARKER],
                    ['name' => 'example-' . strtolower(LEAK_MARKER)],
                    ['name' => 'example-chat-a-03-2025'],
                ]])));
                $left = $gateway->models(10.0);
                break;
            case 'the model list':
                $transport->willReturn(new HttpResponse(200, $headers, ChatV2::json(['models' => [[
                    'name' => 'example-chat-a-03-2025', 'endpoints' => ['chat', LEAK_MARKER], 'default_endpoints' => [LEAK_MARKER], 'finetuned' => false,
                    'context_length' => 256000.0, 'tokenizer_url' => 'https://other.example.test/' . LEAK_MARKER, 'features' => [LEAK_MARKER],
                    'sampling_defaults' => [LEAK_MARKER => 1], LEAK_MARKER => true,
                ]], 'next_page_token' => LEAK_MARKER])));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is refused':
                $transport->willReturn(new HttpResponse(403, $headers, $fault));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is no list':
                $transport->willReturn(new HttpResponse(200, $headers, ChatV2::json(['message' => LEAK_MARKER, 'models' => LEAK_MARKER])));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is an error under a 200':
                $transport->willReturn(new HttpResponse(200, $headers, $fault));
                $left = $gateway->models(10.0);
                break;
            case 'a sign-in that is refused':
                $transport->willReturn(new HttpResponse(401, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'no credit, in words that repeat the key':
                $transport->willReturn(new HttpResponse(402, $headers, ChatV2::json(['id' => LEAK_MARKER, 'message' => 'The account of ' . LEAK_MARKER . ' has no credit.'])));
                $left = $gateway->complete($request);
                break;
            case 'a deny-listed token':
                $transport->willReturn(new HttpResponse(498, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a rate limit with a wait that is too long':
                $transport->willReturn(new HttpResponse(429, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a server error after the retries':
                $transport->willReturn(new HttpResponse(500, ['X-Echo' => LEAK_MARKER], $fault))->willReturn(new HttpResponse(503, ['X-Echo' => LEAK_MARKER], '<html>' . LEAK_MARKER . '</html>'));
                $left = $gateway->complete($request);
                break;
            case 'a request that is refused':
                $transport->willReturn(new HttpResponse(422, $headers, $fault));
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
                $transport->willReturn(new HttpResponse(200, $headers, ChatV2::json(['message' => [LEAK_MARKER], 'echo' => LEAK_MARKER])));
                $left = $gateway->complete($request);
                break;
            case 'an answer that holds nothing but the key where no text is read':
                $transport->willReturn(new HttpResponse(200, $headers, $answer([['type' => 'text', 'text' => ['a' => LEAK_MARKER]], [LEAK_MARKER => LEAK_MARKER], ['type' => LEAK_MARKER, 'text' => LEAK_MARKER]])));
                $left = $gateway->complete($request);
                break;
            case 'an option whose value is the key':
            case 'an option whose name is the key':
                $left = $gateway->complete($request);
                break;
            case 'a temperature the model does not take':
                $left = $gateway->complete(new Request(ChatV2::MODEL, [Message::user(LEAK_MARKER)], 30.0, temperature: 0.5));
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

    // What the observer of the host was told of every request: the form for logs, and every other form. The
    // address is among them: the credential never stands in it.
    foreach ([...$recorder->started(), ...$recorder->finished()] as $at => $report) {
        $forms['report ' . $at . ', toArray'] = (string) json_encode($report->request->toArray(), JSON_PARTIAL_OUTPUT_ON_ERROR);
        $forms['report ' . $at . ', the address'] = $report->request->url;
        $forms['report ' . $at . ', the body'] = $report->request->body;
        foreach (valueForms($report->request) as $form => $output) {
            $forms['report ' . $at . ', ' . $form] = $output;
        }
    }

    return [(string) $kind, $forms];
}

describe('what leaves complete(), stream() and models() of the Chat v2 adapter', function (): void {
    it('holds the credential in no exception, no event and no other output form, for a bearer key and for a token from a supplier', function (string $scenario, string $whatLeft, ?string $withAToken = null): void {
        foreach (['key', 'token'] as $signIn) {
            foreach ([false, true] as $ignoreArgs) {
                withExceptionIgnoreArgs($ignoreArgs, function () use ($scenario, $whatLeft, $withAToken, $signIn): void {
                    [$left, $forms] = chatV2LeakOf($scenario, $signIn);

                    expect($left)->toBe($signIn === 'token' ? ($withAToken ?? $whatLeft) : $whatLeft, $signIn);
                    expectNoMarker($forms);
                });
            }
        }
    })->with([
        ['an answer', 'answer'],
        ['an answer after a retry', 'answer'],
        ['an answer with a tool call', 'answer'],
        ['an answer with a plan and thinking that hold no key', 'answer with an opaque part'],
        // The plan or the thinking held the credential: the part that would carry it to wherever the conversation
        // is stored is dropped, and the answer is kept.
        ['an answer whose plan repeats the key', 'answer'],
        ['an answer whose plan repeats the key in another case', 'answer'],
        ['an answer whose plan repeats the key, written with escapes', 'answer'],
        ['an answer whose thinking repeats the key', 'answer'],
        ['an answer whose thinking repeats the key, written with escapes', 'answer'],
        // A block the adapter does not keep: nothing is kept, so nothing of it travels on.
        ['an answer whose block of an unknown kind repeats the key', 'answer'],
        ['an answer whose thinking block repeats the key in a member no page names', 'answer'],
        ['a stream', 'finished'],
        ['a stream with a plan that holds no key', 'finished with an opaque part'],
        ['a stream whose plan repeats the key in pieces', 'finished'],
        ['a stream whose thinking repeats the key in pieces', 'finished'],
        ['a stream of a server that does not stream', 'finished'],
        ['a stream that breaks', 'failed: unavailable'],
        ['a stream without its last event', 'failed: provider_error'],
        ['a stream that is refused', 'failed: credentials_rejected'],
        ['a stream whose last event holds an error that repeats the key', 'failed: provider_error with a code'],
        ['a stream whose last event holds an error object', 'failed: provider_error'],
        ['a stream with the error body as an event', 'failed: provider_error'],
        ['a stream whose finish reason is the key', 'finished'],
        ['a stream of lines that are no JSON', 'failed: provider_error'],
        ['an answer whose finish reason is the key', 'answer'],
        ['an answer that ended as a failed generation, in words that repeat the key', 'provider_error with a code'],
        // A tool call cannot be handed on in part: the answer fails.
        ['a tool call whose id repeats the key', 'provider_error'],
        ['a tool call whose name repeats the key', 'provider_error'],
        ['a tool call whose arguments repeat the key', 'provider_error'],
        ['a tool call whose arguments repeat the key, written with escapes', 'provider_error'],
        ['a tool call whose arguments that cannot be read repeat the key', 'provider_error'],
        ['a tool call whose id repeats the key, in a stream', 'failed: provider_error'],
        ['a tool call whose name repeats the key, in a stream', 'failed: provider_error'],
        ['a tool call whose arguments repeat the key in pieces, in a stream', 'failed: provider_error'],
        ['a model list whose entries repeat the key', 'models: 1'],
        ['the model list', 'models: 1'],
        ['a model list that is refused', 'credentials_rejected'],
        ['a model list that is no list', 'provider_error'],
        ['a model list that is an error under a 200', 'provider_error'],
        ['a sign-in that is refused', 'credentials_rejected'],
        ['no credit, in words that repeat the key', 'rejected'],
        ['a deny-listed token', 'rejected'],
        ['a rate limit with a wait that is too long', 'rate_limited'],
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
        // With a key there is no supplier to fail: the call is sent and finds no response scripted.
        ['a token supplier that fails', OrganiseYou\LlmGateway\Contract\WrongUse::class, 'credentials_rejected'],
    ]);

    it('drops the opaque part of an answer whose plan or thinking repeats the credential, and keeps the answer', function (array $content, ?string $plan): void {
        $transport = (new FakeTransport())
            ->willReturn(ChatV2::answered(array_values($content), [ChatV2::call()], plan: $plan))
            ->willReturn(ChatV2::answered([ChatV2::thinking('Hm.'), ChatV2::text()], [ChatV2::call()], plan: 'I will look.'));
        $gateway = ChatV2::gateway($transport);
        $request = ChatV2::request()->withTools([ChatV2::lookup()]);

        $withTheKey = $gateway->complete($request);
        $without = $gateway->complete($request);

        expect($withTheKey->opaque)->toBeNull()
            ->and($withTheKey->toolCalls)->toHaveCount(1)
            ->and($without->opaque)->not->toBeNull();
    })->with([
        'the plan' => [[ChatV2::text()], 'The caller signs in with Key-Of-The-Test.'],
        'the thinking' => [[ChatV2::thinking('The caller signs in with key-of-the-test.'), ChatV2::text()], 'I will look.'],
    ]);

    it('drops the opaque part of an answer that repeats the token of the attempt', function (): void {
        $tokens = Talk::tokens();
        $transport = (new FakeTransport())->willReturn(ChatV2::answered(null, [ChatV2::call()], plan: 'plan-token-1-end'));

        $answer = ChatV2::gateway($transport, credentials: new BearerToken($tokens))->complete(ChatV2::request()->withTools([ChatV2::lookup()]));

        expect($answer->opaque)->toBeNull()
            ->and($answer->toolCalls)->toHaveCount(1);
    });

    it('fails an answer whose tool call repeats a credential, with a detail that holds nothing of the call', function (): void {
        $transport = (new FakeTransport())->willReturn(ChatV2::answered(null, [ChatV2::call(), ChatV2::call('{"token":"key-of-the-test"}', 'lookup_example02')]));
        $error = Talk::errorOf(fn() => ChatV2::gateway($transport)->complete(ChatV2::request()->withTools([ChatV2::lookup()])));

        expect($error->reason->value)->toBe('provider_error')
            ->and($error->detail)->toBe('a tool call of the answer repeats a credential of the gateway and is not handed on')
            ->and($error->httpStatus)->toBe(200);
    });

    it('shows no key in a dump of the gateway itself, apart from what the transport of the host holds', function (): void {
        $gateway = new ChatV2Adapter(ChatV2::preset(), new GatewayConfig(RetryPolicy::none(), clock: new FakeClock()), new BearerKey(LEAK_MARKER), new FakeTransport());

        expectNoMarker(valueForms($gateway));
    });

    it('never puts a name of the format or of its provider into an error, whatever failed', function (callable $call): void {
        $transport = new FakeTransport();
        $error = Talk::errorOf(fn() => $call($transport));

        // What a caller reads of an error: its reason, its code, its detail, its message and its JSON form.
        $read = implode("\n", [$error->reason->value, (string) $error->providerCode, (string) $error->detail, $error->getMessage(), (string) json_encode($error)]);

        expect(preg_match('/cohere|chat.?v2|command|tool.?plan/i', $read))->toBe(0);
    })->with([
        'a request that is refused' => [fn(FakeTransport $transport) => ChatV2::gateway($transport->willReturn(ChatV2::failed(422)))->complete(ChatV2::request())],
        'a sign-in that is refused' => [fn(FakeTransport $transport) => ChatV2::gateway($transport->willReturn(ChatV2::failed(401, 'invalid api token')))->complete(ChatV2::request())],
        'a deny-listed token' => [fn(FakeTransport $transport) => ChatV2::gateway($transport->willReturn(ChatV2::failed(498)))->complete(ChatV2::request())],
        'a failed generation' => [fn(FakeTransport $transport) => ChatV2::gateway($transport->willReturn(ChatV2::answered(finish: 'ERROR')))->complete(ChatV2::request())],
        'an error in the last event of a stream' => [function (FakeTransport $transport): void {
            throw Talk::failedIn(Talk::events(ChatV2::gateway($transport->willStream(200, [], ChatV2::started(), ChatV2::ended('ERROR', ['error' => 'internal'])))->stream(ChatV2::request())))->error;
        }],
        'a stream that holds nothing' => [function (FakeTransport $transport): void {
            throw Talk::failedIn(Talk::events(ChatV2::gateway($transport->willStream(200, [], ": nothing\n\n"))->stream(ChatV2::request())))->error;
        }],
        'a call that names no tool' => [fn(FakeTransport $transport) => ChatV2::gateway($transport->willReturn(ChatV2::answered(null, [ChatV2::call('{}', 'a', null)])))->complete(ChatV2::request())],
        'an option with a value outside its list' => [fn(FakeTransport $transport) => ChatV2::gateway($transport, ['schema_key' => 'soon'])->complete(ChatV2::request())],
        'an option with a name the adapter does not know' => [fn(FakeTransport $transport) => ChatV2::gateway($transport, ['other' => 1])->capabilities(ChatV2::MODEL)],
        'a temperature the model does not take' => [fn(FakeTransport $transport) => ChatV2::gateway($transport, ['temperature' => 'not_accepted'])->complete(new Request(ChatV2::MODEL, [Message::user('Hi.')], 30.0, temperature: 0.5))],
        'a body without an answer' => [fn(FakeTransport $transport) => ChatV2::gateway($transport->willReturn(new HttpResponse(200, [], '{"id":"synthetic-answer-1"}')))->complete(ChatV2::request())],
        'an error body under a 200' => [fn(FakeTransport $transport) => ChatV2::gateway($transport->willReturn(new HttpResponse(200, [], ChatV2::errorBody())))->complete(ChatV2::request())],
        'a model list that is no list' => [fn(FakeTransport $transport) => ChatV2::gateway($transport->willReturn(new HttpResponse(200, [], '{"data":[]}')))->models(10.0)],
        'a model list that cannot be asked for' => [fn(FakeTransport $transport) => ChatV2::gateway($transport, ['model_listing' => 'none'])->models(10.0)],
    ]);
});

describe('the parameters of the Chat v2 adapter that carry a response, a request or a message', function (): void {
    it('are marked as sensitive in every method of the adapter and its helpers, so that an error thrown below them keeps none of them in its printed trace', function (): void {
        // By type: what carries a request or a response. By name: what holds text of one.
        $carriers = ['HttpRequest', 'HttpResponse', 'Request', 'Message', 'Answer', 'ToolCall', 'iterable', 'mixed'];
        $names = ['body', 'json', 'list', 'text', 'plan', 'piece', 'pieces', 'holder', 'message', 'delta', 'content', 'calls', 'toolCalls', 'members', 'arguments', 'raw', 'written', 'word', 'given', 'taken', 'id', 'model', 'sealed', 'instructions', 'entry'];
        $unmarked = [];
        $classes = 0;
        foreach (SourceCode::classesOfTheLibrary(dirname(__DIR__, 4) . '/src') as $path => $class) {
            if (! str_starts_with((string) $path, 'Adapter/Cohere/')) {
                continue;
            }
            $classes++;
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    $typeName = $type instanceof ReflectionNamedType ? ltrim((string) strrchr('\\' . $type->getName(), '\\'), '\\') : '';
                    $carries = in_array($typeName, $carriers, true) || in_array($parameter->getName(), $names, true);
                    // The model of capabilities() is checked by the base before the adapter sees it, and is no text of a response.
                    if ($carries && $method->getName() !== 'modelCapabilities' && $parameter->getAttributes(SensitiveParameter::class) === []) {
                        $unmarked[] = $class . '::' . $method->getName() . '($' . $parameter->getName() . ')';
                    }
                }
            }
        }

        expect($classes)->toBe(7)
            ->and($unmarked)->toBe([]);
    });
});
