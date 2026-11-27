<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Gemini\GenerateContentAdapter;
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
use OrganiseYou\LlmGateway\Tests\Support\GenerateContent;
use OrganiseYou\LlmGateway\Tests\Support\SourceCode;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;
use OrganiseYou\LlmGateway\Transport\TransportFailure;

// LR-26 for the generate-content adapter: the key or the token a gateway signs in with appears in no exception, no
// event and no other output, whatever a server repeats of it. In every scenario below the marker is the credential
// (a key in the header of the preset, or the token a supplier of the host gave), and the server repeats it wherever
// its format has room: in the headers, in a comment line of a stream, in fields the adapter does not read, in a
// thought, in a signature, in a tool call, in the words of an error, as its status word and as its reason.

/**
 * Runs one call in which the marker is the credential and the server repeats it, and gives a word that says what
 * left the call with every output form of it, and of the requests in the reports of the attempts.
 *
 * The scenario is named, not handed in: a response among the arguments of this function would put the marker in
 * the trace of whatever is thrown below it, and that would be a leak of the test, not of the library.
 *
 * @param string $signIn `key`: a key in the header of the preset; `token`: a token from a supplier
 * @return array{string, array<string, string>}
 */
function generateLeakOf(string $scenario, string $signIn): array
{
    $headers = [
        'Set-Cookie' => 'session=' . LEAK_MARKER, 'X-Echo' => LEAK_MARKER, 'retry-after' => '120', 'x-goog-api-key' => LEAK_MARKER,
        'Authorization' => 'Bearer ' . LEAK_MARKER, 'Location' => 'https://other.example.test/?key=' . LEAK_MARKER, 'x-request-id' => LEAK_MARKER,
    ];
    // Every place of an answer the adapter reads past.
    $echo = [
        'responseId' => LEAK_MARKER, 'createTime' => LEAK_MARKER,
        'promptFeedback' => ['blockReasonMessage' => 'The key ' . LEAK_MARKER . ' was seen.', 'safetyRatings' => [['category' => LEAK_MARKER]]],
        'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 2, 'serviceTier' => LEAK_MARKER, 'trafficType' => LEAK_MARKER, 'promptTokensDetails' => [['modality' => LEAK_MARKER]]],
        'echo' => ['authorization' => 'Bearer ' . LEAK_MARKER, 'x-goog-api-key' => LEAK_MARKER, 'url' => 'https://llm.example.test/v1beta/models?key=' . LEAK_MARKER],
    ];
    // What a candidate holds beside its parts.
    $beside = ['finishMessage' => 'Stopped for ' . LEAK_MARKER, 'safetyRatings' => [['category' => LEAK_MARKER, 'probability' => LEAK_MARKER]], 'citationMetadata' => ['citations' => [['uri' => 'https://example.test/' . LEAK_MARKER]]], LEAK_MARKER => true];
    $withParts = function (array $parts, string $finish = 'STOP', array $more = []) use ($echo, $beside): string {
        $body = GenerateContent::body(array_values($parts), $finish, [...$echo, ...$more]);
        $body['candidates'][0] = [...$body['candidates'][0], ...$beside];

        return GenerateContent::json($body);
    };
    $text = ['text' => 'Hello.', LEAK_MARKER => LEAK_MARKER, 'videoMetadata' => ['owner' => LEAK_MARKER]];
    $good = new HttpResponse(200, $headers, $withParts([$text]));
    $fault = GenerateContent::json(['error' => [
        'code' => 400, 'message' => 'API key not valid: ' . LEAK_MARKER, 'status' => LEAK_MARKER,
        'details' => [['@type' => LEAK_MARKER, 'reason' => LEAK_MARKER, 'domain' => LEAK_MARKER, 'metadata' => ['key' => LEAK_MARKER, LEAK_MARKER => 'x']]],
    ]]);
    $thought = 'The key of this caller is ' . LEAK_MARKER . ', the path is C:\\keys/' . LEAK_MARKER . '.';
    // The credential as a server could write it in JSON text: every character as an escape with its number.
    $escaped = '';
    foreach (str_split(LEAK_MARKER) as $character) {
        $escaped .= "\x5cu" . sprintf('%04x', ord($character));
    }
    $chunk = fn(array $parts, ?string $finish = null, array $more = []): string => 'data: ' . str_replace('"finishReason":"NONE",', '', $withParts($parts, $finish ?? 'NONE', $more)) . "\n\n";
    $comment = ': ' . LEAK_MARKER . "\n\n";
    $stream = [$comment, $chunk([['text' => 'Hel', 'echo' => LEAK_MARKER]]), 'event: ' . LEAK_MARKER . "\ndata: {\"key\":\"" . LEAK_MARKER . "\"}\n\n", $chunk([['text' => 'lo.']], 'STOP')];
    $signed = fn(string $mark): array => GenerateContent::call(['city' => 'Paris'], 'fc-example01', 'lookup', $mark);

    $clock = new FakeClock();
    $transport = new FakeTransport($clock);
    $recorder = new AttemptRecorder();
    $tokens = Talk::tokens();
    $tokens->next = LEAK_MARKER;
    $credentials = match (true) {
        $scenario === 'a bearer key that comes back as the code' => new BearerKey(LEAK_MARKER),
        $signIn === 'token' => new BearerToken($tokens),
        default => new HeaderKey(LEAK_MARKER),
    };
    $options = match ($scenario) {
        'a stream of a server that does not stream' => ['streaming' => 'emulated'],
        'an option whose value is the key' => ['store_field' => LEAK_MARKER],
        'an option whose name is the key' => [LEAK_MARKER => 'x'],
        'a temperature the model does not take' => ['temperature' => 'not_accepted'],
        default => GenerateContent::KEY_ENTRY,
    };
    $gateway = GenerateContent::gateway($transport, $options, new RetryPolicy([1.0], 60.0), $clock, $credentials, $recorder, ['quota_project' => 'example-project']);
    $request = GenerateContent::request();

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
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([$text, [...GenerateContent::call(), 'caller' => LEAK_MARKER]])));
                $left = $gateway->complete(new Request(GenerateContent::MODEL, [Message::user('Where to?')], 30.0, [GenerateContent::lookup()]));
                break;
            case 'an answer with a signature that holds no key':
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([GenerateContent::text('Hm.', ['thought' => true]), $signed('c2ln')])));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose signature repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([GenerateContent::text('Hello.'), GenerateContent::text('', ['thoughtSignature' => LEAK_MARKER])])));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose signature repeats the key in another case':
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([GenerateContent::text('Hello.', ['thoughtSignature' => 'sig-' . strtolower(LEAK_MARKER) . '-end'])])));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose thought repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([GenerateContent::text($thought, ['thought' => true]), GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln'])])));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose thought repeats the key, written with escapes':
                $transport->willReturn(new HttpResponse(200, [], str_replace('written-with-escapes', $escaped, GenerateContent::json(GenerateContent::body([GenerateContent::text('The key is written-with-escapes.', ['thought' => true]), GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln'])])))));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose signed part repeats the key in a member no page names':
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln', 'more' => ['owner' => strtolower(LEAK_MARKER)]])])));
                $left = $gateway->complete($request);
                break;
            case 'an answer whose part of an unknown kind repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln']), ['inlineData' => ['mimeType' => LEAK_MARKER, 'data' => LEAK_MARKER]]])));
                $left = $gateway->complete($request);
                break;
            case 'a stream':
                $transport->willStream(200, $headers, ...$stream);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream with a signature that holds no key':
                $transport->willStream(200, $headers, $comment, $chunk([GenerateContent::text('Hm.', ['thought' => true])]), $chunk([GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln'])], 'STOP'));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose signature repeats the key':
                $transport->willStream(200, $headers, $comment, $chunk([GenerateContent::text('Hello.')]), $chunk([GenerateContent::text('', ['thoughtSignature' => LEAK_MARKER])], 'STOP'));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose thought repeats the key in pieces':
                $transport->willStream(
                    200,
                    [],
                    str_replace('first-half', substr($escaped, 0, 54), $chunk([GenerateContent::text('The key is first-half', ['thought' => true])])),
                    $chunk([GenerateContent::text(substr(LEAK_MARKER, 9) . '.', ['thought' => true])]),
                    $chunk([GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln'])], 'STOP'),
                );
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
            case 'a stream that ends without a finish reason':
                $transport->willStream(200, $headers, ...array_slice($stream, 0, 3));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that is refused':
                $transport->willStream(401, $headers, $fault);
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream with an error object':
                $transport->willStream(200, $headers, $stream[0], $stream[1], 'data: ' . $fault . "\n\n");
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream whose prompt was refused with the key as the reason':
                $transport->willStream(200, $headers, $comment, 'data: ' . GenerateContent::json(['promptFeedback' => ['blockReason' => LEAK_MARKER, 'blockReasonMessage' => LEAK_MARKER], ...array_diff_key($echo, ['promptFeedback' => 1])]) . "\n\n");
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream that ends as a fault of the model':
                $transport->willStream(200, $headers, $comment, $chunk([], 'MALFORMED_FUNCTION_CALL'));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a stream of lines that are no JSON':
                $transport->willStream(200, $headers, 'data: ' . LEAK_MARKER . "\n\n", 'event: ' . LEAK_MARKER . "\ndata: {\"candidates\":\"" . LEAK_MARKER . "\"}\n\n", "data: {\"candidates\":[\"" . LEAK_MARKER . "\"]}\n\n");
                $left = Talk::events($gateway->stream($request));
                break;
            case 'an answer whose model repeats the key':
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([$text], 'STOP', ['modelVersion' => 'deployment-of-' . LEAK_MARKER])));
                $left = $gateway->complete($request);
                break;
            case 'a stream whose model repeats the key':
                $transport->willStream(200, $headers, $chunk([$text], 'STOP', ['modelVersion' => LEAK_MARKER]));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'an answer whose finish reason is the key':
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([$text], LEAK_MARKER)));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose id repeats the key':
                $transport->willReturn(GenerateContent::answered([GenerateContent::call([], LEAK_MARKER)]));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose name repeats the key':
                $transport->willReturn(GenerateContent::answered([GenerateContent::call([], 'fc-example01', LEAK_MARKER)]));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose arguments repeat the key':
                $transport->willReturn(GenerateContent::answered([GenerateContent::call(['headers' => ['authorization' => 'Bearer ' . LEAK_MARKER], 'path' => 'a/b'])]));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose arguments repeat the key, written with escapes':
                $transport->willReturn(new HttpResponse(200, [], str_replace('written-with-escapes', $escaped, GenerateContent::json(GenerateContent::body([GenerateContent::call(['token' => 'written-with-escapes'])])))));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose arguments are a string that repeats the key':
                $transport->willReturn(GenerateContent::answered([GenerateContent::call('{"token": "' . LEAK_MARKER)]));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose arguments repeat the key, beside a signature':
                $transport->willReturn(GenerateContent::answered([GenerateContent::call(['token' => LEAK_MARKER], 'fc-example01', 'lookup', 'c2ln')]));
                $left = $gateway->complete($request);
                break;
            case 'a tool call whose name repeats the key, in a stream':
                $transport->willStream(200, $headers, $chunk([GenerateContent::call([], null, LEAK_MARKER)], 'STOP'));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a tool call whose id repeats the key, in a stream':
                $transport->willStream(200, $headers, $chunk([GenerateContent::call([], strtolower(LEAK_MARKER))], 'STOP'));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a tool call whose arguments repeat the key, in a stream':
                $transport->willStream(200, $headers, $chunk([GenerateContent::text('Let me look.')]), $chunk([GenerateContent::call(['token' => LEAK_MARKER])], 'STOP'));
                $left = Talk::events($gateway->stream($request));
                break;
            case 'a model list whose entries repeat the key':
                $methods = ['supportedGenerationMethods' => ['generateContent']];
                $transport->willReturn(new HttpResponse(200, $headers, GenerateContent::json(['models' => [
                    ['name' => 'models/' . LEAK_MARKER, ...$methods],
                    ['name' => LEAK_MARKER, ...$methods],
                    ['name' => 'models/example-' . strtolower(LEAK_MARKER), ...$methods],
                    ['name' => 'models/example-generate-3', ...$methods],
                ]])));
                $left = $gateway->models(10.0);
                break;
            case 'the model list':
                $transport->willReturn(new HttpResponse(200, $headers, GenerateContent::json(['models' => [[
                    'name' => 'models/example-generate-1', 'baseModelId' => LEAK_MARKER, 'displayName' => LEAK_MARKER, 'description' => 'Of ' . LEAK_MARKER, 'version' => LEAK_MARKER,
                    'supportedGenerationMethods' => ['generateContent', LEAK_MARKER], 'inputTokenLimit' => 1000, LEAK_MARKER => true,
                ]], 'nextPageToken' => LEAK_MARKER])));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is refused':
                $transport->willReturn(new HttpResponse(403, $headers, $fault));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is no list':
                $transport->willReturn(new HttpResponse(200, $headers, GenerateContent::json(['message' => LEAK_MARKER, 'models' => LEAK_MARKER])));
                $left = $gateway->models(10.0);
                break;
            case 'a model list that is an error under a 200':
                $transport->willReturn(new HttpResponse(200, $headers, $fault));
                $left = $gateway->models(10.0);
                break;
            case 'a sign-in that is refused':
            case 'a bearer key that comes back as the code':
                $transport->willReturn(new HttpResponse(401, $headers, $fault));
                $left = $gateway->complete($request);
                break;
            case 'a key that is not valid, in the words of the provider':
                $transport->willReturn(new HttpResponse(400, $headers, GenerateContent::json(['error' => ['code' => 400, 'message' => 'API key not valid: ' . LEAK_MARKER, 'status' => 'INVALID_ARGUMENT', 'details' => [['reason' => 'API_KEY_INVALID', 'metadata' => ['key' => LEAK_MARKER]]]]])));
                $left = $gateway->complete($request);
                break;
            case 'a status word with the key inside it':
                $transport->willReturn(new HttpResponse(400, $headers, GenerateContent::errorBody(400, 'INVALID_' . strtoupper(LEAK_MARKER) . '.X', null, LEAK_MARKER)));
                $left = $gateway->complete($request);
                break;
            case 'a reason with the key inside it':
                $transport->willReturn(new HttpResponse(400, $headers, GenerateContent::errorBody(400, 'INVALID_ARGUMENT', 'REASON_' . strtolower(LEAK_MARKER), LEAK_MARKER)));
                $left = $gateway->complete($request);
                break;
            case 'no credit, in words that repeat the key':
                $transport->willReturn(new HttpResponse(402, $headers, GenerateContent::errorBody(402, 'RESOURCE_EXHAUSTED', null, 'The account of ' . LEAK_MARKER . ' has no credit.')));
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
            case 'a prompt that was refused, with the key as the reason':
                $transport->willReturn(new HttpResponse(200, $headers, GenerateContent::json(['promptFeedback' => ['blockReason' => LEAK_MARKER, 'blockReasonMessage' => LEAK_MARKER]])));
                $left = $gateway->complete($request);
                break;
            case 'a prompt that was refused, in words that repeat the key':
                $transport->willReturn(new HttpResponse(200, $headers, GenerateContent::json(['promptFeedback' => ['blockReason' => 'SAFETY', 'blockReasonMessage' => LEAK_MARKER, 'safetyRatings' => [[LEAK_MARKER => LEAK_MARKER]]], ...array_diff_key($echo, ['promptFeedback' => 1])])));
                $left = $gateway->complete($request);
                break;
            case 'an answer that ended as a fault of the model, in words that repeat the key':
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([$text], 'MALFORMED_FUNCTION_CALL')));
                $left = $gateway->complete($request);
                break;
            case 'a body that cannot be decoded':
                $transport->willReturn(new HttpResponse(200, $headers, 'not JSON: ' . LEAK_MARKER));
                $left = $gateway->complete($request);
                break;
            case 'a body without an answer':
                $transport->willReturn(new HttpResponse(200, $headers, GenerateContent::json(['candidates' => LEAK_MARKER, 'echo' => LEAK_MARKER])));
                $left = $gateway->complete($request);
                break;
            case 'an answer that holds nothing but the key where no text is read':
                $transport->willReturn(new HttpResponse(200, $headers, $withParts([['text' => ['a' => LEAK_MARKER]], [LEAK_MARKER => LEAK_MARKER], ['text' => LEAK_MARKER, 'thought' => true]], 'STOP')));
                $left = $gateway->complete($request);
                break;
            case 'an option whose value is the key':
            case 'an option whose name is the key':
                $left = $gateway->complete($request);
                break;
            case 'a temperature the model does not take':
                $left = $gateway->complete(new Request(GenerateContent::MODEL, [Message::user(LEAK_MARKER)], 30.0, temperature: 0.5));
                break;
            case 'a model name that is the key':
                $left = $gateway->complete(new Request(LEAK_MARKER . '/x', [Message::user('Hi.')], 30.0));
                break;
            case 'a transcript of system messages alone':
                $left = $gateway->complete(GenerateContent::request(Message::system(LEAK_MARKER)));
                break;
            case 'a tool name the format cannot carry':
                $left = $gateway->complete(new Request(GenerateContent::MODEL, [Message::user(LEAK_MARKER)], 30.0, [GenerateContent::lookup('lookup.' . LEAK_MARKER)]));
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

describe('what leaves complete(), stream() and models() of the generate-content adapter', function (): void {
    it('holds the credential in no exception, no event and no other output form, for a key in a header and for a token from a supplier', function (string $scenario, string $whatLeft, ?string $withAToken = null): void {
        foreach (['key', 'token'] as $signIn) {
            foreach ([false, true] as $ignoreArgs) {
                withExceptionIgnoreArgs($ignoreArgs, function () use ($scenario, $whatLeft, $withAToken, $signIn): void {
                    [$left, $forms] = generateLeakOf($scenario, $signIn);

                    expect($left)->toBe($signIn === 'token' ? ($withAToken ?? $whatLeft) : $whatLeft, $signIn);
                    expectNoMarker($forms);
                });
            }
        }
    })->with([
        ['an answer', 'answer'],
        ['an answer after a retry', 'answer'],
        ['an answer with a tool call', 'answer'],
        ['an answer with a signature that holds no key', 'answer with an opaque part'],
        // The signature or the thought held the credential: the part that would carry it to wherever the
        // conversation is stored is dropped.
        ['an answer whose signature repeats the key', 'answer'],
        ['an answer whose signature repeats the key in another case', 'answer'],
        ['an answer whose thought repeats the key', 'answer'],
        ['an answer whose thought repeats the key, written with escapes', 'answer'],
        // A member or a part the adapter does not keep: nothing is kept, so nothing of it travels on.
        ['an answer whose signed part repeats the key in a member no page names', 'answer'],
        ['an answer whose part of an unknown kind repeats the key', 'answer'],
        ['a stream', 'finished'],
        ['a stream with a signature that holds no key', 'finished with an opaque part'],
        ['a stream whose signature repeats the key', 'finished'],
        ['a stream whose thought repeats the key in pieces', 'finished'],
        ['a stream of a server that does not stream', 'finished'],
        ['a stream that breaks', 'failed: unavailable'],
        ['a stream that ends without a finish reason', 'finished'],
        ['a stream that is refused', 'failed: credentials_rejected'],
        ['a stream with an error object', 'failed: provider_error'],
        ['a stream whose prompt was refused with the key as the reason', 'failed: rejected'],
        ['a stream that ends as a fault of the model', 'failed: provider_error with a code'],
        ['a stream of lines that are no JSON', 'failed: provider_error'],
        // The name of the model falls back to the model of the request.
        ['an answer whose model repeats the key', 'answer'],
        ['a stream whose model repeats the key', 'finished'],
        ['an answer whose finish reason is the key', 'answer'],
        // A tool call cannot be handed on in part: the answer fails.
        ['a tool call whose id repeats the key', 'provider_error'],
        ['a tool call whose name repeats the key', 'provider_error'],
        ['a tool call whose arguments repeat the key', 'provider_error'],
        ['a tool call whose arguments repeat the key, written with escapes', 'provider_error'],
        ['a tool call whose arguments are a string that repeats the key', 'provider_error'],
        ['a tool call whose arguments repeat the key, beside a signature', 'provider_error'],
        ['a tool call whose name repeats the key, in a stream', 'failed: provider_error'],
        ['a tool call whose id repeats the key, in a stream', 'failed: provider_error'],
        ['a tool call whose arguments repeat the key, in a stream', 'failed: provider_error'],
        ['a model list whose entries repeat the key', 'models: 1'],
        ['the model list', 'models: 1'],
        ['a model list that is refused', 'credentials_rejected'],
        ['a model list that is no list', 'provider_error'],
        ['a model list that is an error under a 200', 'provider_error'],
        ['a sign-in that is refused', 'credentials_rejected'],
        ['a bearer key that comes back as the code', 'credentials_rejected'],
        ['a key that is not valid, in the words of the provider', 'credentials_rejected with a code'],
        ['a status word with the key inside it', 'rejected'],
        ['a reason with the key inside it', 'rejected'],
        ['no credit, in words that repeat the key', 'rejected with a code'],
        ['a rate limit with a wait that is too long', 'rate_limited'],
        ['a server error after the retries', 'unavailable'],
        ['a request that is refused', 'rejected'],
        ['a redirect', 'unavailable'],
        ['a lost connection', 'unavailable'],
        ['an error inside a 200', 'provider_error'],
        ['a prompt that was refused, with the key as the reason', 'rejected'],
        ['a prompt that was refused, in words that repeat the key', 'rejected with a code'],
        ['an answer that ended as a fault of the model, in words that repeat the key', 'provider_error with a code'],
        ['a body that cannot be decoded', 'provider_error'],
        ['a body without an answer', 'provider_error'],
        ['an answer that holds nothing but the key where no text is read', 'provider_error'],
        ['an option whose value is the key', 'not_configured'],
        ['an option whose name is the key', 'not_configured'],
        ['a temperature the model does not take', 'not_supported'],
        ['a model name that is the key', 'rejected'],
        ['a transcript of system messages alone', 'rejected'],
        ['a tool name the format cannot carry', 'rejected'],
        // With a key there is no supplier to fail: the call is sent and finds no response scripted.
        ['a token supplier that fails', OrganiseYou\LlmGateway\Contract\WrongUse::class, 'credentials_rejected'],
    ]);

    it('drops the opaque part of an answer whose signature or thought repeats the credential, and keeps the answer', function (array $parts): void {
        $transport = (new FakeTransport())
            ->willReturn(GenerateContent::answered(array_values($parts)))
            ->willReturn(GenerateContent::answered([GenerateContent::text('Hm.', ['thought' => true]), GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln'])]));
        $gateway = GenerateContent::gateway($transport);

        $withTheKey = $gateway->complete(GenerateContent::request());
        $without = $gateway->complete(GenerateContent::request());

        expect($withTheKey->opaque)->toBeNull()
            ->and($withTheKey->text)->toBe('Hello.')
            ->and($without->opaque)->not->toBeNull();
    })->with([
        'the signature' => [[GenerateContent::text('Hello.', ['thoughtSignature' => 'key-of-the-test'])]],
        'the thought' => [[GenerateContent::text('The caller signs in with Key-Of-The-Test.', ['thought' => true]), GenerateContent::text('Hello.', ['thoughtSignature' => 'c2ln'])]],
    ]);

    it('drops the opaque part of an answer that repeats the token of the attempt', function (): void {
        $tokens = Talk::tokens();
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered([GenerateContent::text('Hello.', ['thoughtSignature' => 'sig-token-1-end'])]));

        $answer = GenerateContent::gateway($transport, credentials: new BearerToken($tokens))->complete(GenerateContent::request());

        expect($answer->opaque)->toBeNull()
            ->and($answer->text)->toBe('Hello.');
    });

    it('names the model of the request when the model of an answer repeats a credential or is no name', function (string $model): void {
        $transport = (new FakeTransport())
            ->willReturn(GenerateContent::answered(more: ['modelVersion' => $model]))
            ->willStream(200, [], GenerateContent::chunk([GenerateContent::text()], 'STOP', ['modelVersion' => $model]));
        $gateway = GenerateContent::gateway($transport);

        expect($gateway->complete(GenerateContent::request())->model)->toBe(GenerateContent::MODEL)
            ->and(Talk::finishedIn(Talk::events($gateway->stream(GenerateContent::request())))->answer->model)->toBe(GenerateContent::MODEL);
    })->with([
        'the credential' => ['key-of-the-test'],
        'the credential inside a longer name' => ['deployment/Key-Of-The-Test/v2'],
        'a line end' => ["example-generate-1\r\nX-Injected: 1"],
        'a tab' => ["example\tgenerate"],
    ]);

    it('fails an answer whose tool call repeats a credential, with a detail that holds nothing of the call', function (): void {
        $transport = (new FakeTransport())->willReturn(GenerateContent::answered([GenerateContent::call(['city' => 'Paris']), GenerateContent::call(['token' => 'key-of-the-test'], 'fc-example02')]));
        $error = Talk::errorOf(fn() => GenerateContent::gateway($transport)->complete(GenerateContent::request()));

        expect($error->reason->value)->toBe('provider_error')
            ->and($error->detail)->toBe('a tool call of the answer repeats a credential of the gateway and is not handed on')
            ->and($error->httpStatus)->toBe(200);
    });

    it('shows no key in a dump of the gateway itself, apart from what the transport of the host holds', function (): void {
        $gateway = new GenerateContentAdapter(GenerateContent::preset(), new GatewayConfig(RetryPolicy::none(), clock: new FakeClock()), new HeaderKey(LEAK_MARKER), new FakeTransport());

        expectNoMarker(valueForms($gateway));
    });

    it('never puts a name of the format or of its provider into an error, whatever failed', function (callable $call): void {
        $transport = new FakeTransport();
        $error = Talk::errorOf(fn() => $call($transport));

        // What a caller reads of an error: its reason, its code, its detail, its message and its JSON form.
        $read = implode("\n", [$error->reason->value, (string) $error->providerCode, (string) $error->detail, $error->getMessage(), (string) json_encode($error)]);

        expect(preg_match('/gemini|google|vertex|generate.?content|aiplatform|generativelanguage/i', $read))->toBe(0);
    })->with([
        'a request that is refused' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport->willReturn(GenerateContent::failed(400)))->complete(GenerateContent::request())],
        'a sign-in that is refused' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport->willReturn(GenerateContent::failed(401, 'UNAUTHENTICATED')))->complete(GenerateContent::request())],
        'a key that is not valid' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport->willReturn(GenerateContent::failed(400, 'INVALID_ARGUMENT', 'API_KEY_INVALID')))->complete(GenerateContent::request())],
        'a prompt that was refused' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport->willReturn(new HttpResponse(200, [], '{"promptFeedback":{"blockReason":"SAFETY"}}')))->complete(GenerateContent::request())],
        'an answer that ended as a fault' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport->willReturn(GenerateContent::answered(null, 'MALFORMED_FUNCTION_CALL')))->complete(GenerateContent::request())],
        'an error object in a stream' => [function (FakeTransport $transport): void {
            throw Talk::failedIn(Talk::events(GenerateContent::gateway($transport->willStream(200, [], 'data: ' . GenerateContent::errorBody(500, 'INTERNAL') . "\n\n"))->stream(GenerateContent::request())))->error;
        }],
        'a stream that holds nothing' => [function (FakeTransport $transport): void {
            throw Talk::failedIn(Talk::events(GenerateContent::gateway($transport->willStream(200, [], ": nothing\n\n"))->stream(GenerateContent::request())))->error;
        }],
        'an option with a value outside its list' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport, ['structured_output_field' => 'soon'])->complete(GenerateContent::request())],
        'an option with a name the adapter does not know' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport, ['other' => 1])->capabilities(GenerateContent::MODEL)],
        'a temperature the model does not take' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport, ['temperature' => 'not_accepted'])->complete(new Request(GenerateContent::MODEL, [Message::user('Hi.')], 30.0, temperature: 0.5))],
        'a model name that cannot stand in the address' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport)->complete(new Request('models/x', [Message::user('Hi.')], 30.0))],
        'a transcript of system messages alone' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport)->complete(GenerateContent::request(Message::system('Hi.')))],
        'a tool name the format cannot carry' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport)->complete(new Request(GenerateContent::MODEL, [Message::user('Hi.')], 30.0, [GenerateContent::lookup('look.up')]))],
        'a body without an answer' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport->willReturn(new HttpResponse(200, [], '{"responseId":"resp-example01"}')))->complete(GenerateContent::request())],
        'an error body under a 200' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport->willReturn(new HttpResponse(200, [], GenerateContent::errorBody(500, 'INTERNAL'))))->complete(GenerateContent::request())],
        'a model list that is no list' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport->willReturn(new HttpResponse(200, [], '{"data":[]}')))->models(10.0)],
        'a model list that cannot be asked for' => [fn(FakeTransport $transport) => GenerateContent::gateway($transport, ['model_listing' => 'none'])->models(10.0)],
    ]);
});

describe('the parameters of the generate-content adapter that carry a response, a request or a message', function (): void {
    it('are marked as sensitive in every method of the adapter and its helpers, so that an error thrown below them keeps none of them in its printed trace', function (): void {
        // By type: what carries a request or a response. By name: what holds text of one.
        $carriers = ['HttpRequest', 'HttpResponse', 'Request', 'Message', 'Answer', 'ToolCall', 'iterable', 'mixed'];
        $names = ['body', 'json', 'list', 'text', 'parts', 'pieces', 'holder', 'candidate', 'calls', 'toolCalls', 'results', 'members', 'before', 'arguments', 'written', 'word', 'given', 'taken', 'id', 'model'];
        $unmarked = [];
        $classes = 0;
        foreach (SourceCode::classesOfTheLibrary(dirname(__DIR__, 4) . '/src') as $path => $class) {
            if (! str_starts_with((string) $path, 'Adapter/Gemini/')) {
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

        expect($classes)->toBe(6)
            ->and($unmarked)->toBe([]);
    });
});
