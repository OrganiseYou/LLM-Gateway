<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * What a model can do through this gateway. A caller asks these; it never asks who answers.
 */
final readonly class Capabilities
{
    /**
     * @param Support $toolCalling can the model ask for tool calls
     * @param Support $forcedToolCall can the caller force one named tool
     * @param Support $parallelToolCalls can one answer hold several tool calls
     * @param Support $structuredOutput can a response format be given
     * @param Support $toolsWithStructuredOutput can tools and a response format go in one request
     * @param Support $streaming native, or emulated (the whole answer as one text event); never none
     * @param Support $modelListing can the models be listed: native or none
     * @param int|null $contextLength in tokens, when it is known without a request
     * @param Support $temperature native: the temperature of a request is sent; none: the model takes none, and a
     *                             request that gives one is refused as not supported. Never emulated
     */
    public function __construct(
        public Support $toolCalling,
        public Support $forcedToolCall,
        public Support $parallelToolCalls,
        public Support $structuredOutput,
        public Support $toolsWithStructuredOutput,
        public Support $streaming,
        public Support $modelListing,
        public ?int $contextLength,
        public Support $temperature = Support::Native,
    ) {
        if ($contextLength !== null && $contextLength < 1) {
            throw new InvalidValue('The context length must be 1 or more.');
        }
        if ($toolCalling === Support::None && $forcedToolCall !== Support::None) {
            throw new InvalidValue('A forced tool call cannot be available without tool calling.');
        }
        if ($toolCalling === Support::None && $parallelToolCalls !== Support::None) {
            throw new InvalidValue('Parallel tool calls cannot be available without tool calling.');
        }
        if ($structuredOutput === Support::None && $toolsWithStructuredOutput !== Support::None) {
            throw new InvalidValue(
                'Tools with structured output cannot be available without structured output.',
            );
        }
        if ($streaming === Support::None) {
            throw new InvalidValue(
                'Streaming is native or emulated, never none: without it the whole answer comes as one event.',
            );
        }
        if ($modelListing === Support::Emulated) {
            throw new InvalidValue('Model listing is native or none; it cannot be emulated.');
        }
        if ($temperature === Support::Emulated) {
            throw new InvalidValue('A temperature is native or none; it cannot be emulated.');
        }
    }
}
