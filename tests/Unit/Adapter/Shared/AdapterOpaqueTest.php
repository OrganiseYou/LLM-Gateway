<?php

declare(strict_types=1);

use OrganiseYou\LlmGateway\Adapter\Shared\AbstractAdapter;
use OrganiseYou\LlmGateway\Contract\Answer;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerKey;
use OrganiseYou\LlmGateway\Contract\Credentials\BearerToken;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Contract\Credentials\Credentials;
use OrganiseYou\LlmGateway\Contract\Credentials\NoCredentials;
use OrganiseYou\LlmGateway\Contract\Gateway;
use OrganiseYou\LlmGateway\Contract\InvalidValue;
use OrganiseYou\LlmGateway\Contract\JsonSchema;
use OrganiseYou\LlmGateway\Contract\Message;
use OrganiseYou\LlmGateway\Contract\OpaquePart;
use OrganiseYou\LlmGateway\Contract\Request;
use OrganiseYou\LlmGateway\Contract\RetryPolicy;
use OrganiseYou\LlmGateway\Contract\Role;
use OrganiseYou\LlmGateway\Contract\TextPart;
use OrganiseYou\LlmGateway\Contract\ToolCall;
use OrganiseYou\LlmGateway\Contract\ToolDefinition;
use OrganiseYou\LlmGateway\GatewayConfig;
use OrganiseYou\LlmGateway\GatewayFactory;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Testing\FakeTransport;
use OrganiseYou\LlmGateway\Testing\OutputForms;
use OrganiseYou\LlmGateway\Tests\Support\MarklessAdapter;
use OrganiseYou\LlmGateway\Tests\Support\Talk;
use OrganiseYou\LlmGateway\Transport\HttpResponse;

// The opaque part in the adapter base: how seal() binds what a provider asks back to the adapter and to its answer,
// and that unseal() gives it back for that adapter and that message only. The adapter is the made-up "talk" format,
// whose answers can hold an "aside" that goes back on the line of the assistant message.

const ASIDE = ['kind' => 'working', 'note' => 'look the city up first', 'stamp' => 'stamp-0001'];

/**
 * The answer of the format with one tool call and, when given, an aside beside it.
 *
 * @param array<mixed>|null $aside
 * @param array<string, mixed> $more
 */
function talkToolCall(?array $aside = ASIDE, string $say = '', array $more = []): HttpResponse
{
    return Talk::answered($say, [
        'calls' => [['ref' => 'ref_1', 'tool' => 'lookup', 'input' => '{"city":"Paris"}']],
        'end' => 'calls',
        ...($aside === null ? [] : ['aside' => $aside]),
        ...$more,
    ]);
}

function talkRequest(Message ...$more): Request
{
    return new Request(
        'model-a',
        [Message::user('How warm is it in Paris?'), ...array_values($more)],
        30.0,
        [new ToolDefinition('lookup', 'Looks up a city.', JsonSchema::fromArray(['type' => 'object']))],
        traceId: 'trace-1',
    );
}

/**
 * The body of the request the transport received last, decoded.
 *
 * @return array<mixed>
 */
function lastBody(FakeTransport $transport): array
{
    $requests = $transport->requests();
    $body = json_decode($requests[count($requests) - 1]->body, true);
    assert(is_array($body));

    return $body;
}

/**
 * What the request the transport received last holds on the line of its assistant message, or 'no line'.
 *
 * @return array<mixed>|string
 */
function assistantLine(FakeTransport $transport): array|string
{
    foreach (lastBody($transport)['lines'] as $line) {
        if ($line['who'] === 'assistant') {
            return $line;
        }
    }

    return 'no line';
}

/**
 * The hash that binds a part to its answer, as the docblock of seal() describes it: every field with its length.
 */
function hashOfAnswer(string $text, ToolCall ...$toolCalls): string
{
    $bound = strlen($text) . ':' . $text;
    foreach ($toolCalls as $toolCall) {
        foreach ([$toolCall->id, $toolCall->name, $toolCall->rawArguments] as $field) {
            $bound .= strlen($field) . ':' . $field;
        }
    }

    return hash('sha256', $bound);
}

/**
 * The second round of a tool loop on a new gateway: the assistant message, the tool result, and what was sent.
 *
 * @param array<string, bool|int|string> $options
 */
function secondRound(Message $assistant, array $options = []): FakeTransport
{
    $transport = (new FakeTransport())->willReturn(Talk::answered('It is 18 degrees.'));
    $answer = Talk::gateway($transport, options: $options)->complete(talkRequest($assistant, Message::toolResult('ref_1', 'lookup', '{"degrees":18}')));

    expect($answer->text)->toBe('It is 18 degrees.')
        ->and($transport->requests())->toHaveCount(1);

    return $transport;
}

function answerWithAside(): Answer
{
    return Talk::gateway((new FakeTransport())->willReturn(talkToolCall()))->complete(talkRequest());
}

function sealOn(Gateway $adapter, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(AbstractAdapter::class, 'seal'))->invoke($adapter, ...$arguments);
}

final readonly class MarkOfThreeAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = 'ab1';
}

final readonly class MarkInCapitalsAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = 'A1';
}

final readonly class MarkThatIsAWordAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = 'talk';
}

final readonly class MarkThatStartsWithADigitAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = '1a';
}

final readonly class MarkOfOneLetterAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = 'a';
}

final readonly class MarkOfFourAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = 'ab12';
}

final readonly class MarkWithASpaceAdapter extends MarklessAdapter
{
    protected const string OPAQUE_FORMAT = 't1 ';
}

function unsealOn(Gateway $adapter, Message $message): mixed
{
    return (new ReflectionMethod(AbstractAdapter::class, 'unseal'))->invoke($adapter, $message);
}

/**
 * A part as seal() of the made-up format writes it, built by hand: the mark, the hash and the content as JSON text.
 */
function handMade(string $hash, string $contentAsJson, string $mark = 't1'): OpaquePart
{
    return new OpaquePart('{"f":"' . $mark . '","m":"' . $hash . '","c":' . $contentAsJson . '}');
}

/**
 * @param class-string<MarklessAdapter> $class
 */
function marklessAdapter(string $class = MarklessAdapter::class): Gateway
{
    $preset = new Preset(
        id: 'markless-test',
        label: 'Markless (test)',
        description: 'A made-up server for the tests.',
        adapter: $class,
        baseUrlRule: BaseUrlRule::CustomerMay,
        defaultBaseUrl: 'https://markless.example.test/api',
        credentialKinds: [CredentialKind::None],
        keyHeader: null,
        fields: [],
        dataLocation: 'not stated by the provider',
        dataLocationSource: null,
        sendsNoStoreOption: false,
    );

    return GatewayFactory::create($preset, new NoCredentials(), new FakeTransport(), new GatewayConfig(RetryPolicy::none()));
}

describe('seal()', function (): void {
    it('writes one JSON object with the mark of the adapter, the hash of the answer and the content, and nothing else', function (): void {
        $answer = answerWithAside();
        $data = (string) $answer->opaque?->data;
        $envelope = json_decode($data, true);

        expect($data)->toStartWith('{"f":"t1","m":"')
            ->and($envelope)->toBe([
                'f' => 't1',
                'm' => hashOfAnswer('', ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')),
                'c' => ASIDE,
            ])
            // No preset id, no class name and no name of the format.
            ->and(stripos($data, 'talk'))->toBeFalse()
            ->and(stripos($data, 'adapter'))->toBeFalse();
    });

    it('seals nothing for an answer of plain text and tool calls', function (): void {
        $plain = Talk::gateway((new FakeTransport())->willReturn(Talk::answered('Hello.')))->complete(Talk::request());
        $call = Talk::gateway((new FakeTransport())->willReturn(talkToolCall(null)))->complete(talkRequest());

        expect($plain->opaque)->toBeNull()
            ->and($call->opaque)->toBeNull()
            ->and($call->toolCalls)->toHaveCount(1);
    });

    it('gives the same part through stream() as through complete()', function (): void {
        $whole = answerWithAside();

        $chunks = [
            Talk::event('aside', ASIDE),
            Talk::event('call_open', ['ref' => 'ref_1', 'tool' => 'lookup']),
            Talk::event('call', ['ref' => 'ref_1', 'tool' => 'lookup', 'input' => '{"city":"Paris"}']),
            Talk::event('used', ['in' => 3, 'out' => 2]),
            Talk::event('end', ['end' => 'calls', 'by' => 'model-a-0001']),
        ];
        $events = Talk::events(Talk::gateway((new FakeTransport())->willStream(200, [], ...$chunks))->stream(talkRequest()));
        $streamed = Talk::finishedIn($events)->answer;

        expect($streamed->opaque?->data)->toBe($whole->opaque?->data)
            ->and($streamed->toMessage())->toEqual($whole->toMessage())
            // No event before the last one carries the aside: it travels in the answer of the last event.
            ->and(count($events))->toBe(4);
        foreach (array_slice($events, 0, -1) as $event) {
            expect(OutputForms::holding(valueForms($event), 'look the city up'))->toBe([]);
        }
    });

    it('gives the same part for a model that cannot stream, read as a stream', function (): void {
        $transport = (new FakeTransport())->willReturn(talkToolCall());
        $events = Talk::events(Talk::gateway($transport, options: ['streams' => false])->stream(talkRequest()));

        expect(Talk::finishedIn($events)->answer->opaque?->data)->toBe(answerWithAside()->opaque?->data);
    });

    it('keeps text that is not ASCII and a slash as they are, and a float a float', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $content = ['note' => 'café / thé', 'score' => 1.0, 'tenth' => 0.1, 'small' => -2.5e-7, 'zero' => 0.0, 'third' => 1 / 3, 'count' => 3];
        $part = sealOn($gateway, 'hé', [], $content);
        assert($part instanceof OpaquePart);

        expect($part->data)->toContain('"note":"café / thé"')
            ->toContain('"score":1e+0')
            ->toContain('"tenth":1e-1')
            ->toContain('"count":3}')
            ->and(unsealOn($gateway, new Message(Role::Assistant, [$part, new TextPart('hé')])))->toBe($content);
    });

    it('writes the same bytes whatever the float precision of the host, and opens a part sealed under another', function (int $precision): void {
        $gateway = Talk::gateway(new FakeTransport());
        $content = ['tenth' => 0.1, 'third' => 1 / 3, 'pi' => 3.141592653589793, 'list' => [1.5, 2.0, 1e100]];
        $message = fn(OpaquePart $part): Message => new Message(Role::Assistant, [$part, new TextPart('x')]);

        $before = ini_set('serialize_precision', '-1');
        try {
            $usual = sealOn($gateway, 'x', [], $content);
            ini_set('serialize_precision', (string) $precision);
            $other = sealOn($gateway, 'x', [], $content);
            assert($usual instanceof OpaquePart && $other instanceof OpaquePart);

            // What json_encode() would have written here differs: that is what the fixed form is for.
            expect(json_encode(1 / 3))->not->toBe('0.3333333333333333')
                ->and($other->data)->toBe($usual->data)
                ->and(unsealOn($gateway, $message($usual)))->toBe($content);

            ini_set('serialize_precision', '-1');
            expect(unsealOn($gateway, $message($other)))->toBe($content);
        } finally {
            ini_set('serialize_precision', (string) $before);
        }
    })->with([17, 5]);

    it('does not give the bytes of the provider back: what must come back byte for byte is sealed as one string', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $raw = '{"input":{},"byNumber":{"0":"a","1":"b"},"big":12345678901234567890,"spelled":1e2,"long":0.10000000000000000555}';
        $part = sealOn($gateway, 'x', [], ['decoded' => json_decode($raw, true), 'raw' => $raw]);
        assert($part instanceof OpaquePart);

        $content = unsealOn($gateway, new Message(Role::Assistant, [$part, new TextPart('x')]));
        assert(is_array($content) && is_array($content['decoded']));

        expect($content['decoded']['input'])->toBe([])
            ->and($content['decoded']['byNumber'])->toBe(['a', 'b'])
            ->and($content['decoded']['big'])->toBeFloat()
            ->and($content['decoded']['spelled'])->toBe(100.0)
            ->and($content['decoded']['long'])->toBe(0.1)
            ->and($content['raw'])->toBe($raw);
    });

    it('keeps minus zero minus zero, in bytes that open again', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $part = sealOn($gateway, 'x', [], ['minus' => -0.0, 'plus' => 0.0, 'list' => [-0.0]]);
        assert($part instanceof OpaquePart);
        $content = unsealOn($gateway, new Message(Role::Assistant, [$part, new TextPart('x')]));
        assert(is_array($content) && is_array($content['list']));

        expect($part->data)->toEndWith('"c":{"minus":-0e+0,"plus":0e+0,"list":[-0e+0]}}')
            ->and($content['minus'])->toBeFloat()
            ->and(fdiv(1.0, $content['minus']))->toBe(-INF)
            ->and(fdiv(1.0, $content['plus']))->toBe(INF)
            ->and(fdiv(1.0, $content['list'][0]))->toBe(-INF);
    });

    it('writes a plain object as its fields and refuses any other object', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $part = sealOn($gateway, 'x', [], ['block' => (object) ['kind' => 'working'], 'none' => new stdClass()]);
        assert($part instanceof OpaquePart);

        expect($part->data)->toEndWith('"c":{"block":{"kind":"working"},"none":[]}}')
            ->and(unsealOn($gateway, new Message(Role::Assistant, [$part, new TextPart('x')])))->toBe(['block' => ['kind' => 'working'], 'none' => []])
            ->and(fn() => sealOn($gateway, 'x', [], ['at' => new DateTimeImmutable('2026-01-01')]))->toThrow(InvalidValue::class, 'cannot be written as JSON');
    });

    it('refuses to seal', function (array $arguments, string $message): void {
        expect(fn() => sealOn(Talk::gateway(new FakeTransport()), ...$arguments))->toThrow(InvalidValue::class, $message);
    })->with([
        'no content' => [['', [], []], 'There is nothing to seal'],
        'a tool call that is none' => [['', ['call_0'], ['a' => 1]], 'must be a list of tool calls'],
        'content that cannot be written as JSON' => [['', [], ['note' => "caf\xE9"]], 'cannot be written as JSON'],
        'a name that cannot be written as JSON' => [['', [], ["caf\xE9" => 1]], 'cannot be written as JSON'],
        'a number that is not finite' => [['', [], ['n' => INF]], 'cannot be written as JSON'],
        'no number' => [['', [], ['n' => NAN]], 'cannot be written as JSON'],
    ]);

    it('keeps the content out of its refusal', function (): void {
        withExceptionIgnoreArgs(false, function (): void {
            try {
                sealOn(Talk::gateway(new FakeTransport()), LEAK_MARKER, [], ['note' => LEAK_MARKER . "\xE9"]);
                $refusal = null;
            } catch (InvalidValue $thrown) {
                $refusal = $thrown;
            }

            expect($refusal)->not->toBeNull()
                ->and(OutputForms::holding(exceptionForms($refusal ?? new LogicException('not refused')), 'MARKER'))->toBe([]);
        });
    });

    it('is refused on an adapter without the constant, which opens nothing either', function (): void {
        $adapter = marklessAdapter();
        $part = answerWithAside()->toMessage();

        expect(fn() => sealOn($adapter, '', [], ['a' => 1]))->toThrow(InvalidValue::class, 'OPAQUE_FORMAT')
            ->and((new ReflectionMethod(AbstractAdapter::class, 'unseal'))->invoke($adapter, $part))->toBeNull()
            ->and((new ReflectionClassConstant(AbstractAdapter::class, 'OPAQUE_FORMAT'))->getValue())->toBe('');
    });

    it('accepts a mark of a small letter and one or two small letters or digits, and opens its own parts with it', function (): void {
        $adapter = marklessAdapter(MarkOfThreeAdapter::class);
        $part = sealOn($adapter, 'x', [], ['a' => 1]);
        assert($part instanceof OpaquePart);

        expect(json_decode($part->data, true)['f'])->toBe('ab1')
            ->and((new ReflectionMethod(AbstractAdapter::class, 'unseal'))->invoke($adapter, new Message(Role::Assistant, [$part, new TextPart('x')])))->toBe(['a' => 1])
            // Another adapter does not open it.
            ->and((new ReflectionMethod(AbstractAdapter::class, 'unseal'))->invoke(Talk::gateway(new FakeTransport()), new Message(Role::Assistant, [$part, new TextPart('x')])))->toBeNull();
    });

    it('neither seals nor opens with a mark in another form, also a part made by hand with that mark', function (string $class): void {
        assert(is_subclass_of($class, MarklessAdapter::class));
        $adapter = marklessAdapter($class);
        $mark = (new ReflectionClassConstant($class, 'OPAQUE_FORMAT'))->getValue();
        assert(is_string($mark));

        $byHand = new Message(Role::Assistant, [handMade(hashOfAnswer('x'), '{"a":1}', $mark), new TextPart('x')]);

        expect(fn() => sealOn($adapter, 'x', [], ['a' => 1]))->toThrow(InvalidValue::class, 'OPAQUE_FORMAT')
            ->and(unsealOn($adapter, $byHand))->toBeNull();
    })->with([
        'capitals' => [MarkInCapitalsAdapter::class],
        'a word' => [MarkThatIsAWordAdapter::class],
        'a digit first' => [MarkThatStartsWithADigitAdapter::class],
        'one letter' => [MarkOfOneLetterAdapter::class],
        'four characters' => [MarkOfFourAdapter::class],
        'a space at its end' => [MarkWithASpaceAdapter::class],
    ]);
});

describe('unseal(), through the next request', function (): void {
    it('sends the content back on the message it came with, in a new gateway that kept nothing', function (): void {
        $answer = answerWithAside();

        $line = assistantLine(secondRound($answer->toMessage()));

        expect($line)->toBe(['who' => 'assistant', 'say' => '', 'aside' => ASIDE]);
    });

    it('sends it back when the part was stored as text and built again', function (): void {
        $message = answerWithAside()->toMessage();
        $stored = (string) json_encode(['data' => $message->parts[0] instanceof OpaquePart ? $message->parts[0]->data : null]);

        $data = json_decode($stored, true)['data'];
        $rebuilt = new Message(Role::Assistant, [new OpaquePart($data)], [ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')]);

        expect(assistantLine(secondRound($rebuilt)))->toBe(['who' => 'assistant', 'say' => '', 'aside' => ASIDE]);
    });

    it('sends the message without the part, and throws nothing, when', function (Closure $changed): void {
        $message = answerWithAside()->toMessage();
        $part = $message->parts[0];
        assert($part instanceof OpaquePart);

        $transport = secondRound($changed($message, $part));
        $line = assistantLine($transport);

        expect($line)->toBeArray()
            ->and($line)->not->toHaveKey('aside')
            ->and(str_contains((string) $transport->requests()[0]->body, 'stamp-0001'))->toBeFalse();
    })->with([
        'the text was changed' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [$p, new TextPart('One moment.')], $m->toolCalls)],
        'an empty text part became a space' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [$p, new TextPart(' ')], $m->toolCalls)],
        'the arguments of the tool call were changed' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [$p], [ToolCall::decoded('ref_1', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}')])],
        'the raw arguments were written again with a space' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [$p], [ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city": "Paris"}')])],
        'the id of the tool call was changed' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [$p], [ToolCall::decoded('ref_2', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}')])],
        'the name of the tool was changed' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [$p], [ToolCall::decoded('ref_1', 'search', ['city' => 'Paris'], '{"city":"Paris"}')])],
        'a tool call was added' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [$p], [...$m->toolCalls, ToolCall::decoded('ref_2', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}')])],
        'the tool call was taken out and a text put in' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [$p, new TextPart('Paris.')])],
        'a field moved into the next one' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [$p], [ToolCall::decoded('ref_', '1lookup', ['city' => 'Paris'], '{"city":"Paris"}')])],
        'the part is the part of another answer' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart((string) json_encode(['f' => 't1', 'm' => hashOfAnswer('Another answer.'), 'c' => ASIDE])),
        ], $m->toolCalls)],
        'the part has the mark of another adapter and the right hash' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart((string) json_encode(['f' => 'zz', 'm' => hashOfAnswer('', ...$m->toolCalls), 'c' => ASIDE])),
        ], $m->toolCalls)],
        'the mark is the right one in capitals' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart((string) json_encode(['f' => 'T1', 'm' => hashOfAnswer('', ...$m->toolCalls), 'c' => ASIDE])),
        ], $m->toolCalls)],
        'the part holds a key more' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart((string) json_encode(['f' => 't1', 'm' => hashOfAnswer('', ...$m->toolCalls), 'c' => ASIDE, 'x' => 1])),
        ], $m->toolCalls)],
        'the content is no object and no list' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart((string) json_encode(['f' => 't1', 'm' => hashOfAnswer('', ...$m->toolCalls), 'c' => 'stamp-0001'])),
        ], $m->toolCalls)],
        'the content is empty' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart((string) json_encode(['f' => 't1', 'm' => hashOfAnswer('', ...$m->toolCalls), 'c' => []])),
        ], $m->toolCalls)],
        'the hash is no text' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart((string) json_encode(['f' => 't1', 'm' => ['x'], 'c' => ASIDE])),
        ], $m->toolCalls)],
        'the mark is missing' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart((string) json_encode(['m' => hashOfAnswer('', ...$m->toolCalls), 'c' => ASIDE, 'x' => 't1'])),
        ], $m->toolCalls)],
        'the mark stands twice, the foreign one first' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart('{"f":"zz","f":"t1","m":"' . hashOfAnswer('', ...$m->toolCalls) . '","c":{"stamp":"stamp-0001"}}'),
        ], $m->toolCalls)],
        'the mark stands twice, the foreign one last' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart('{"f":"t1","m":"' . hashOfAnswer('', ...$m->toolCalls) . '","c":{"stamp":"stamp-0001"},"f":"zz"}'),
        ], $m->toolCalls)],
        'the content stands twice' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart('{"f":"t1","m":"' . hashOfAnswer('', ...$m->toolCalls) . '","c":{"a":1},"c":{"stamp":"stamp-0001"}}'),
        ], $m->toolCalls)],
        'the hash stands twice' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart('{"f":"t1","m":"x","m":"' . hashOfAnswer('', ...$m->toolCalls) . '","c":{"stamp":"stamp-0001"}}'),
        ], $m->toolCalls)],
        'a name is written with an escape' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart('{"f":"t1","m":"' . hashOfAnswer('', ...$m->toolCalls) . '","\\u0063":{"stamp":"stamp-0001"}}'),
        ], $m->toolCalls)],
        'the mark is written with an escape' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart('{"f":"\\u00741","m":"' . hashOfAnswer('', ...$m->toolCalls) . '","c":{"stamp":"stamp-0001"}}'),
        ], $m->toolCalls)],
        'a name inside the content stands twice' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            handMade(hashOfAnswer('', ...$m->toolCalls), '{"stamp":"x","stamp":"stamp-0001"}'),
        ], $m->toolCalls)],
        'a slash inside the content is escaped' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            handMade(hashOfAnswer('', ...$m->toolCalls), '{"stamp":"stamp-0001\\/x"}'),
        ], $m->toolCalls)],
        'a number inside the content is spelled another way' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            handMade(hashOfAnswer('', ...$m->toolCalls), '{"stamp":"stamp-0001","n":1.0}'),
        ], $m->toolCalls)],
        'a space follows the object' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [new OpaquePart($p->data . ' ')], $m->toolCalls)],
        'a line break follows the object' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [new OpaquePart($p->data . "\n")], $m->toolCalls)],
        'a space stands before the object' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [new OpaquePart(' ' . $p->data)], $m->toolCalls)],
        'the object is written with spaces' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart((string) json_encode(json_decode($p->data, true), JSON_PRETTY_PRINT)),
        ], $m->toolCalls)],
        'the part is a JSON list' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [new OpaquePart('["t1","stamp-0001"]')], $m->toolCalls)],
        'the part is the made-up object of the shared test set' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [new OpaquePart('{"f":"zz"}')], $m->toolCalls)],
        'the part is plain text' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [new OpaquePart('stamp-0001, and this is no JSON')], $m->toolCalls)],
        'the part is cut off' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [new OpaquePart(substr($p->data, 0, -5))], $m->toolCalls)],
        'one character of the content was changed' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [new OpaquePart(str_replace('"f":"t1"', '"f":"t2"', $p->data))], $m->toolCalls)],
        'the part is nested deeper than JSON is read' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [
            new OpaquePart('{"f":"t1","m":"' . hashOfAnswer('', ...$m->toolCalls) . '","c":' . str_repeat('[', 600) . '"stamp-0001"' . str_repeat(']', 600) . '}'),
        ], $m->toolCalls)],
        'the part is large and no JSON' => [fn(Message $m, OpaquePart $p): Message => new Message(Role::Assistant, [new OpaquePart('{' . str_repeat('stamp-0001 ', 200000))], $m->toolCalls)],
    ]);

    it('opens a part made by hand only in the exact bytes seal() writes', function (): void {
        $call = ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $exact = handMade(hashOfAnswer('', $call), '{"stamp":"stamp-0001","n":1e+0}');

        expect(unsealOn(Talk::gateway(new FakeTransport()), new Message(Role::Assistant, [$exact], [$call])))->toBe(['stamp' => 'stamp-0001', 'n' => 1.0]);
    });

    it('does send what a caller sealed by hand: the mark and the hash are a check against mix-ups, not a signature', function (): void {
        $call = ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $forged = new OpaquePart((string) json_encode(['f' => 't1', 'm' => hashOfAnswer('', $call), 'c' => ['note' => 'written by the caller']]));

        $line = assistantLine(secondRound(new Message(Role::Assistant, [$forged], [$call])));

        expect($line)->toBe(['who' => 'assistant', 'say' => '', 'aside' => ['note' => 'written by the caller']]);
    });

    it('opens a part in an assistant message only', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $unseal = new ReflectionMethod(AbstractAdapter::class, 'unseal');
        $message = answerWithAside()->toMessage();

        expect($unseal->invoke($gateway, $message))->toBe(ASIDE)
            ->and($unseal->invoke($gateway, Message::assistant('', ...$message->toolCalls)))->toBeNull()
            ->and($unseal->invoke($gateway, Message::user('Hello.')))->toBeNull()
            ->and($unseal->invoke($gateway, Message::toolResult('ref_1', 'lookup', '{}')))->toBeNull();
    });

    it('gives an empty JSON object back as an empty list: a format that needs its bytes seals them as one text', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $raw = '{"input":{},"list":[],"n":1.0}';
        $part = sealOn($gateway, 'x', [], ['shape' => json_decode($raw, true), 'raw' => $raw]);
        assert($part instanceof OpaquePart);

        $content = (new ReflectionMethod(AbstractAdapter::class, 'unseal'))->invoke($gateway, new Message(Role::Assistant, [$part, new TextPart('x')]));

        expect($content['shape']['input'])->toBe([])
            ->and($content['raw'])->toBe($raw);
    });
});

describe('a credential and the opaque part', function (): void {
    it('keeps a key that the response repeats beside the aside out of the part', function (): void {
        $transport = (new FakeTransport())->willReturn(new HttpResponse(
            200,
            ['X-Echo' => LEAK_MARKER, 'Set-Cookie' => 'session=' . LEAK_MARKER],
            talkToolCall(ASIDE, '', ['echo' => LEAK_MARKER, 'by' => 'model-a-0001'])->body,
        ));

        $answer = Talk::gateway($transport, credentials: new BearerKey(LEAK_MARKER))->complete(talkRequest());

        expect($answer->opaque)->not->toBeNull()
            ->and(OutputForms::holding(valueForms($answer), 'MARKER'))->toBe([])
            ->and(OutputForms::holding(valueForms($answer->toMessage()), 'MARKER'))->toBe([]);
    });

    it('drops a part whole when the provider repeats the credential inside it, and returns the answer', function (Credentials $credentials, string $repeated): void {
        $aside = [...ASIDE, 'note' => 'you signed in with ' . $repeated];

        $answer = Talk::gateway((new FakeTransport())->willReturn(talkToolCall($aside)), credentials: $credentials)->complete(talkRequest());

        expect($answer->opaque)->toBeNull()
            ->and($answer->toolCalls)->toHaveCount(1)
            ->and($answer->toMessage()->parts)->toBe([]);

        $chunks = [
            Talk::event('aside', $aside),
            Talk::event('call_open', ['ref' => 'ref_1', 'tool' => 'lookup']),
            Talk::event('call', ['ref' => 'ref_1', 'tool' => 'lookup', 'input' => '{"city":"Paris"}']),
            Talk::event('end', ['end' => 'calls', 'by' => 'model-a-0001']),
        ];
        $streamed = Talk::finishedIn(Talk::events(Talk::gateway((new FakeTransport())->willStream(200, [], ...$chunks), credentials: $credentials)->stream(talkRequest())))->answer;

        expect($streamed->opaque)->toBeNull()
            ->and($streamed->toolCalls)->toHaveCount(1);
    })->with([
        'a key' => [new BearerKey(LEAK_MARKER), LEAK_MARKER],
        'a key in other letters' => [new BearerKey(LEAK_MARKER), strtoupper(LEAK_MARKER)],
        'a key with a quote, a backslash and a slash' => [new BearerKey('k"e\\y/' . LEAK_MARKER), 'k"e\\y/' . LEAK_MARKER],
        'a key that is not ASCII' => [new BearerKey('clé-' . LEAK_MARKER), 'clé-' . LEAK_MARKER],
        'a key with a slash, in JSON text of the provider that was sealed as one string' => [new BearerKey('sk/AbC/' . LEAK_MARKER), '{\"echo\":\"sk\\/AbC\\/' . LEAK_MARKER . '\"}'],
        'a key with a slash, in JSON text that was escaped twice' => [new BearerKey('sk/AbC/' . LEAK_MARKER), 'sk\\\\\\/AbC\\\\\\/' . LEAK_MARKER],
        'a key with a backslash, written without it' => [new BearerKey('sk\\AbC-' . LEAK_MARKER), 'skAbC-' . LEAK_MARKER],
        'the token of a supplier, asked for this attempt' => [new BearerToken((function () {
            $tokens = Talk::tokens();
            $tokens->next = 'token-' . LEAK_MARKER;

            return $tokens;
        })()), 'token-' . LEAK_MARKER],
    ]);

    it('keeps a part that holds no credential when a credential is in use', function (): void {
        $answer = Talk::gateway((new FakeTransport())->willReturn(talkToolCall()), credentials: new BearerKey(LEAK_MARKER))->complete(talkRequest());

        expect($answer->opaque)->not->toBeNull();
    });

    it('sends no credential in the body when a part goes back', function (): void {
        $transport = (new FakeTransport())->willReturn(talkToolCall())->willReturn(Talk::answered('It is 18 degrees.'));
        $gateway = Talk::gateway($transport, credentials: new BearerKey(LEAK_MARKER));

        $first = $gateway->complete(talkRequest());
        $gateway->complete(talkRequest($first->toMessage(), Message::toolResult('ref_1', 'lookup', '{"degrees":18}')));

        expect(assistantLine($transport))->toHaveKey('aside')
            ->and(holdsMarker($transport->requests()[1]->body))->toBeFalse();
    });
});

describe('the size of an opaque part', function (): void {
    // 1 MiB, the constant OPAQUE_LIMIT_BYTES of the adapter base.
    $limit = 1_048_576;

    it('has the limit of 1 MiB', function () use ($limit): void {
        expect((new ReflectionClassConstant(AbstractAdapter::class, 'OPAQUE_LIMIT_BYTES'))->getValue())->toBe($limit);
    });

    it('never decodes a large part of another adapter: opening it takes no memory', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $call = ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        // About 2.4 MB of small objects: decoded, it takes more than a process of 128 MB has.
        $foreign = new Message(Role::Assistant, [
            new OpaquePart('{"f":"zz","m":"' . hashOfAnswer('', $call) . '","c":[' . str_repeat('{"a":1},', 300000) . '{"a":1}]}'),
        ], [$call]);

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $content = unsealOn($gateway, $foreign);
        $taken = memory_get_peak_usage() - $before;

        expect($content)->toBeNull()
            ->and($taken)->toBeLessThan(1_048_576, 'a part with another mark must not be decoded');
    });

    it('sends a request that carries a large part of another adapter, without the part and within a memory bound', function (): void {
        $call = ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $foreign = new Message(Role::Assistant, [
            new OpaquePart('{"f":"zz","m":"' . hashOfAnswer('', $call) . '","c":[' . str_repeat('{"a":1},', 300000) . '{"a":1}]}'),
        ], [$call]);

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $transport = secondRound($foreign);
        $taken = memory_get_peak_usage() - $before;

        expect(assistantLine($transport))->not->toHaveKey('aside')
            ->and(strlen((string) $transport->requests()[0]->body))->toBeLessThan(2000)
            ->and($taken)->toBeLessThan(4 * 1_048_576);
    });

    it('opens a part of its own of exactly the limit, and not one byte more', function () use ($limit): void {
        $gateway = Talk::gateway(new FakeTransport());
        $call = ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $empty = strlen(handMade(hashOfAnswer('', $call), '{"pad":""}')->data);

        $atTheLimit = handMade(hashOfAnswer('', $call), '{"pad":"' . str_repeat('x', $limit - $empty) . '"}');
        $over = handMade(hashOfAnswer('', $call), '{"pad":"' . str_repeat('x', $limit - $empty + 1) . '"}');

        expect(strlen($atTheLimit->data))->toBe($limit)
            ->and(unsealOn($gateway, new Message(Role::Assistant, [$atTheLimit], [$call])))->toHaveKey('pad')
            ->and(strlen($over->data))->toBe($limit + 1)
            ->and(unsealOn($gateway, new Message(Role::Assistant, [$over], [$call])))->toBeNull();
    });

    it('does not open a part of its own above the limit, whatever it holds, and takes no memory for it', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $call = ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $large = new Message(Role::Assistant, [handMade(hashOfAnswer('', $call), '[' . str_repeat('{"a":1},', 300000) . '{"a":1}]')], [$call]);

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $content = unsealOn($gateway, $large);
        $taken = memory_get_peak_usage() - $before;

        expect($content)->toBeNull()
            ->and($taken)->toBeLessThan(1_048_576);
    });

    it('opens a part of its own with 10,000 objects and lists, and not with one more', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $call = ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        // The part itself is one object and its content one list: 9,998 more make 10,000.
        $part = fn(int $lists): Message => new Message(Role::Assistant, [handMade(hashOfAnswer('', $call), '[' . rtrim(str_repeat('[1],', $lists), ',') . ']')], [$call]);

        expect((new ReflectionClassConstant(AbstractAdapter::class, 'OPAQUE_LIMIT_CONTAINERS'))->getValue())->toBe(10_000)
            ->and(unsealOn($gateway, $part(9_998)))->toHaveCount(9_998)
            ->and(unsealOn($gateway, $part(9_999)))->toBeNull();
    });

    it('does not count a bracket inside a text', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        $text = str_repeat('[{ "a\\b" ', 20_000);
        $part = sealOn($gateway, 'x', [], ['raw' => $text, 'list' => [$text]]);
        assert($part instanceof OpaquePart);

        expect(unsealOn($gateway, new Message(Role::Assistant, [$part, new TextPart('x')])))->toBe(['raw' => $text, 'list' => [$text]]);
    });

    it('counts the same whatever the limits of regular expressions on the host', function (): void {
        $gateway = Talk::gateway(new FakeTransport());
        // 3 KB of a letter, a backslash and a quote: as JSON, a string of escapes. Two objects and lists in all.
        $text = str_repeat('a\\"', 1_000);
        $before = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000');
        try {
            $part = sealOn($gateway, 'x', [], ['s' => $text, 'list' => ['[{']]);
            assert($part instanceof OpaquePart);
            $content = unsealOn($gateway, new Message(Role::Assistant, [$part, new TextPart('x')]));
            $answer = Talk::gateway((new FakeTransport())->willReturn(talkToolCall(['s' => $text])))->complete(talkRequest());
        } finally {
            ini_set('pcre.backtrack_limit', (string) $before);
        }

        expect(ini_get('pcre.backtrack_limit'))->toBe($before)
            ->and($content)->toBe(['s' => $text, 'list' => ['[{']])
            ->and($answer->opaque)->not->toBeNull();
    });

    it('keeps and opens a part just under 1 MiB that is one text of escapes', function (string $unit, int $times) use ($limit): void {
        $gateway = Talk::gateway(new FakeTransport());
        $text = str_repeat($unit, $times);
        $part = sealOn($gateway, 'x', [], ['s' => $text]);
        assert($part instanceof OpaquePart);
        $answer = Talk::gateway((new FakeTransport())->willReturn(talkToolCall(['s' => $text])))->complete(talkRequest());

        expect(strlen($part->data))->toBeLessThan($limit)->toBeGreaterThan(1_000_000)
            ->and(unsealOn($gateway, new Message(Role::Assistant, [$part, new TextPart('x')])))->toBe(['s' => $text])
            ->and($answer->opaque)->not->toBeNull();
    })->with([
        // Each is two bytes in the part: 500,000 of them are 1,000,000 bytes.
        'quotes' => ['"', 500_000],
        'backslashes' => ['\\', 500_000],
        'a quote after a backslash' => ['\\"', 250_000],
    ]);

    it('does not open a part whose text never ends, and reads no byte past its end', function (string $content): void {
        $gateway = Talk::gateway(new FakeTransport());
        $call = ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $part = new OpaquePart('{"f":"t1","m":"' . hashOfAnswer('', $call) . '","c":' . $content);

        expect(unsealOn($gateway, new Message(Role::Assistant, [$part], [$call])))->toBeNull();
    })->with([
        'no closing quote' => ['{"a":"b'],
        'a backslash as the last byte' => ['{"a":"b\\'],
        'a quote as the last byte' => ['{"a":"'],
    ]);

    it('never takes much memory to open a part of its own, whatever its shape', function (Closure $shape, bool $opened) use ($limit): void {
        // Built here and not in the dataset: a dataset stays in memory for the whole run.
        $content = $shape();
        assert(is_string($content));
        $gateway = Talk::gateway(new FakeTransport());
        $call = ToolCall::decoded('ref_1', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}');
        $message = new Message(Role::Assistant, [handMade(hashOfAnswer('', $call), $content)], [$call]);
        expect(strlen($content))->toBeLessThan($limit)->toBeGreaterThan(900_000);

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $content = unsealOn($gateway, $message);
        $taken = memory_get_peak_usage() - $before;

        expect($content !== null)->toBe($opened)
            ->and($taken)->toBeLessThan(32 * 1_048_576);
        unset($content);
    })->with([
        // Decoded, each of the first three takes 50 to 90 MB: they are not decoded.
        'small objects' => [fn(): string => '[' . str_repeat('{"":1},', 149_000) . '1]', false],
        'lists of one item' => [fn(): string => '[' . str_repeat('[1],', 262_000) . '1]', false],
        'lists inside lists' => [fn(): string => '[' . str_repeat('[[[[1]]]],', 104_000) . '1]', false],
        'numbers' => [fn(): string => '[' . str_repeat('1,', 520_000) . '1]', true],
        'short texts' => [fn(): string => '[' . str_repeat('"[",', 260_000) . '1]', true],
        'the most lists a part may hold, and numbers' => [fn(): string => '[' . str_repeat('[1],', 9_990) . str_repeat('1,', 480_000) . '1]', true],
    ]);

    it('returns the answer with a part of exactly the limit, and without a part that is over a limit', function () use ($limit): void {
        $sealedWith = fn(int $pad): Answer => Talk::gateway((new FakeTransport())->willReturn(talkToolCall(['pad' => str_repeat('x', $pad)])))->complete(talkRequest());
        $empty = strlen((string) $sealedWith(0)->opaque?->data);

        $atTheLimit = $sealedWith($limit - $empty);
        $over = $sealedWith($limit - $empty + 1);

        expect(strlen((string) $atTheLimit->opaque?->data))->toBe($limit)
            ->and($over->opaque)->toBeNull()
            ->and($over->toolCalls)->toHaveCount(1)
            ->and($over->toMessage()->parts)->toBe([]);

        // The other limit: more objects and lists than a part may hold.
        $many = Talk::gateway((new FakeTransport())->willReturn(talkToolCall(['items' => array_fill(0, 10_000, [1])])))->complete(talkRequest());
        $few = Talk::gateway((new FakeTransport())->willReturn(talkToolCall(['items' => array_fill(0, 9_000, [1])])))->complete(talkRequest());

        expect($many->opaque)->toBeNull()
            ->and($many->toolCalls)->toHaveCount(1)
            ->and($few->opaque)->not->toBeNull();

        $chunks = [
            Talk::event('aside', ['pad' => str_repeat('x', $limit - $empty + 1)]),
            Talk::event('call_open', ['ref' => 'ref_1', 'tool' => 'lookup']),
            Talk::event('call', ['ref' => 'ref_1', 'tool' => 'lookup', 'input' => '{"city":"Paris"}']),
            Talk::event('end', ['end' => 'calls', 'by' => 'model-a-0001']),
        ];
        $streamed = Talk::finishedIn(Talk::events(Talk::gateway((new FakeTransport())->willStream(200, [], ...$chunks))->stream(talkRequest())))->answer;

        expect($streamed->opaque)->toBeNull()
            ->and($streamed->toolCalls)->toHaveCount(1);
    });
});
