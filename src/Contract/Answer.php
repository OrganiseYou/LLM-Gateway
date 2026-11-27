<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use LogicException;
use OrganiseYou\LlmGateway\Contract\Internal\Rules;
use SensitiveParameter;

/**
 * One answer in the neutral shape.
 */
final readonly class Answer
{
    /**
     * @param StructuredResult|null $structured null when the request had no response format or the answer holds tool
     *                                          calls (it is not the final answer yet); filled otherwise
     * @param list<ToolCall> $toolCalls no two with the same id
     * @param string $model the model that answered; the requested model when the provider does not say
     * @param int $latencyMs the whole call: every attempt and every wait
     * @param int $attempts HTTP attempts made for this call
     * @param OpaquePart|null $opaque data that came with the answer and must go back with it, unchanged, in the next
     *                                round; null when there is none. It has no meaning here: do not read it
     */
    public function __construct(
        public string $text,
        public ?StructuredResult $structured,
        public array $toolCalls,
        public FinishReason $finishReason,
        public string $model,
        public Usage $usage,
        public int $latencyMs,
        public int $attempts,
        public string $traceId,
        #[SensitiveParameter]
        public ?OpaquePart $opaque = null,
    ) {
        if (preg_match('//u', $text) !== 1) {
            throw new InvalidValue('The text of an answer must be valid UTF-8.');
        }

        self::assertToolCalls($toolCalls);

        if ($model === '') {
            throw new InvalidValue('An answer needs the model that answered.');
        }
        if ($latencyMs < 0) {
            throw new InvalidValue('The latency must be 0 or more.');
        }
        if ($attempts < 1) {
            throw new InvalidValue('The attempts must be 1 or more.');
        }
        if (! Rules::isTraceId($traceId)) {
            throw new InvalidValue('The trace id must match ^[A-Za-z0-9_.:-]{1,128}$.');
        }
    }

    /**
     * The answer as the assistant message of the next round: the opaque part when there is one, then the text (left
     * out when it is empty and there are tool calls), and the tool calls.
     *
     * @throws LogicException when the text is empty and there are no tool calls
     */
    public function toMessage(): Message
    {
        if ($this->text === '' && $this->toolCalls === []) {
            throw new WrongUse('An answer without text and without tool calls cannot become a message.');
        }

        $parts = $this->opaque === null ? [] : [$this->opaque];
        if ($this->text !== '') {
            $parts[] = new TextPart($this->text);
        }

        return new Message(Role::Assistant, $parts, $this->toolCalls);
    }

    /**
     * @param array<mixed> $toolCalls
     */
    private static function assertToolCalls(array $toolCalls): void
    {
        if (! array_is_list($toolCalls)) {
            throw new InvalidValue('The tool calls of an answer must be a list.');
        }

        $seen = [];
        foreach ($toolCalls as $toolCall) {
            if (! $toolCall instanceof ToolCall) {
                throw new InvalidValue('The tool calls of an answer hold a value that is not a tool call.');
            }
            if (isset($seen[$toolCall->id])) {
                throw new InvalidValue('Two tool calls of an answer have the same id.');
            }
            $seen[$toolCall->id] = true;
        }
    }
}
