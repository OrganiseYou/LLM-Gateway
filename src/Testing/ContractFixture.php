<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Testing;

use OrganiseYou\LlmGateway\Contract\FinishReason;
use OrganiseYou\LlmGateway\Contract\ToolCall;

/**
 * The answers the shared test set lets a provider give. A fixture of an adapter holds exactly what its case says
 * here, in the format of that adapter; the set then asks for the same neutral answer from every gateway.
 *
 * Every fixture also reports the usage of the constants below, 3 input and 2 output tokens, the way the format
 * reports usage; a stream reports it once. Only the fixtures of a server that reports no usage leave it out, and
 * the test of that server then says so (ContractTestCase::reportsUsage()).
 *
 * A stream is sent after one comment line of the subject, so its first chunk is the second the gateway reads.
 */
enum ContractFixture: string
{
    /** The input tokens every fixture reports, where the format reports usage. */
    public const int INPUT_TOKENS = 3;

    /** The output tokens every fixture reports, where the format reports usage. */
    public const int OUTPUT_TOKENS = 2;

    /** The words that stand in the opaque data of ToolCallWithOpaqueData, and nowhere else in that answer. */
    public const string OPAQUE_MARKER = 'opaque-tracer';

    /** The text "Hello.", ended by itself. As a stream: in two pieces or more, the first of them in the first chunk. */
    case PlainAnswer = 'plain_answer';

    /** No text needed; one call of the tool "lookup" with the arguments {"city":"Paris"}; ended for tool calls. */
    case ToolCall = 'tool_call';

    /** Two calls of "lookup" in one answer: {"city":"Paris"}, then {"city":"Rome"}; ended for tool calls. */
    case ParallelToolCalls = 'parallel_tool_calls';

    /** One call of "lookup" whose arguments cannot be read: the cut-off text {"city": "Par */
    case GarbledToolCall = 'garbled_tool_call';

    /** The text {"city":"Paris","days":3}, ended by itself: the answer to a request with the response format "trip". */
    case StructuredValid = 'structured_valid';

    /** The text {"city":"Paris","days":"three"}, ended by itself: JSON that does not match the schema of "trip". */
    case StructuredInvalid = 'structured_invalid';

    /** The text {"city":"Par, cut off at the token limit: the answer to a request with the response format "trip". */
    case CutOff = 'cut_off';

    /**
     * The answer of ToolCall, to a request that gave the tool "lookup" and the response format "trip" together. Its
     * own case because a format that gets a structured answer its own way may answer such a request differently.
     */
    case ToolCallBesideResponseFormat = 'tool_call_beside_response_format';

    /** A successful response with no text and no tool call, ended by itself. */
    case EmptyAnswer = 'empty_answer';

    /**
     * The answer of ToolCall, and beside it data of the format that has no neutral form and that the provider wants
     * back, unchanged, in the next request. That data holds the words "opaque-tracer"; the text and the tool call of
     * the answer do not. The gateway gives it as the opaque part of the answer, and sends it back only with the
     * message it came with. The fixtures of a format that has no such data give NotApplicable for this case, for the
     * whole response and for the stream alike. The words are chosen so that the search for the key cannot find them.
     */
    case ToolCallWithOpaqueData = 'tool_call_with_opaque_data';

    /**
     * The text the neutral answer must hold; null: any text, the set does not look at it.
     */
    public function text(): ?string
    {
        return match ($this) {
            self::PlainAnswer => 'Hello.',
            self::StructuredValid => '{"city":"Paris","days":3}',
            self::StructuredInvalid => '{"city":"Paris","days":"three"}',
            self::CutOff => '{"city":"Par',
            self::EmptyAnswer => '',
            self::ToolCall, self::ParallelToolCalls, self::GarbledToolCall, self::ToolCallBesideResponseFormat,
            self::ToolCallWithOpaqueData => null,
        };
    }

    /**
     * The tool calls the neutral answer must hold, by name and arguments. The ids are the provider's.
     *
     * @return list<ToolCall>
     */
    public function toolCalls(): array
    {
        return match ($this) {
            self::ToolCall, self::ToolCallBesideResponseFormat, self::ToolCallWithOpaqueData => [
                ToolCall::decoded('call_0', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'),
            ],
            self::ParallelToolCalls => [
                ToolCall::decoded('call_0', 'lookup', ['city' => 'Paris'], '{"city":"Paris"}'),
                ToolCall::decoded('call_1', 'lookup', ['city' => 'Rome'], '{"city":"Rome"}'),
            ],
            self::GarbledToolCall => [
                ToolCall::unreadable('call_0', 'lookup', 'the arguments are not a JSON object', '{"city": "Par'),
            ],
            default => [],
        };
    }

    public function finishReason(): FinishReason
    {
        return match ($this) {
            self::CutOff => FinishReason::Length,
            self::PlainAnswer, self::StructuredValid, self::StructuredInvalid, self::EmptyAnswer => FinishReason::Stop,
            default => FinishReason::ToolCalls,
        };
    }

    /**
     * Whether the request this fixture answers gives the tool "lookup".
     */
    public function needsTools(): bool
    {
        return $this->toolCalls() !== [];
    }

    /**
     * Whether the request this fixture answers gives the response format "trip".
     */
    public function needsResponseFormat(): bool
    {
        return in_array($this, [self::StructuredValid, self::StructuredInvalid, self::CutOff, self::ToolCallBesideResponseFormat], true);
    }
}
