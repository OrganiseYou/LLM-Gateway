<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

use InvalidArgumentException;
use OrganiseYou\LlmGateway\Contract\Internal\Rules;

/**
 * One completion request in the neutral shape. Tools and a response format may be given together; whether a
 * provider can do that is a capability, not a rule of the request.
 */
final readonly class Request
{
    /**
     * @param string $model not empty, no control character
     * @param list<Message> $messages not empty; system messages come first
     * @param float $timeoutSeconds for the whole call; finite and above 0
     * @param list<ToolDefinition> $tools no two with the same name
     * @param int|null $maxOutputTokens 1 and up, or null for the default
     * @param float|null $temperature 0.0 to 1.0, or null for the default
     * @param string|null $traceId letters, digits and _ . : - up to 128 characters
     */
    public function __construct(
        public string $model,
        public array $messages,
        public float $timeoutSeconds,
        public array $tools = [],
        public ToolChoice $toolChoice = new ToolChoice(ToolChoiceMode::Auto),
        public ?ResponseFormat $responseFormat = null,
        public ?int $maxOutputTokens = null,
        public ?float $temperature = null,
        public ?string $traceId = null,
    ) {
        if (! Rules::isModelName($model)) {
            throw new InvalidArgumentException('The model must not be empty and must not hold a control character.');
        }

        self::assertMessages($messages);

        if (! Rules::isTimeout($timeoutSeconds)) {
            throw new InvalidArgumentException('The timeout must be a finite number of seconds above 0.');
        }

        $toolNames = self::toolNames($tools);

        if ($toolNames === [] && in_array($toolChoice->mode, [ToolChoiceMode::Required, ToolChoiceMode::Tool], true)) {
            throw new InvalidArgumentException('This tool choice needs at least one tool.');
        }
        if ($toolChoice->mode === ToolChoiceMode::Tool && ! isset($toolNames[$toolChoice->toolName])) {
            throw new InvalidArgumentException('The tool choice names a tool that is not in the tools.');
        }
        if ($responseFormat !== null && isset($toolNames[$responseFormat->name])) {
            throw new InvalidArgumentException('The response format has the same name as a tool.');
        }
        if ($maxOutputTokens !== null && $maxOutputTokens < 1) {
            throw new InvalidArgumentException('The maximum of output tokens must be 1 or more.');
        }
        if ($temperature !== null && (! is_finite($temperature) || $temperature < 0.0 || $temperature > 1.0)) {
            throw new InvalidArgumentException('The temperature must be between 0.0 and 1.0.');
        }
        if ($traceId !== null && ! Rules::isTraceId($traceId)) {
            throw new InvalidArgumentException('The trace id must match ^[A-Za-z0-9_.:-]{1,128}$.');
        }
    }

    /**
     * @param list<Message> $messages
     */
    public function withMessages(array $messages): self
    {
        return new self(
            $this->model,
            $messages,
            $this->timeoutSeconds,
            $this->tools,
            $this->toolChoice,
            $this->responseFormat,
            $this->maxOutputTokens,
            $this->temperature,
            $this->traceId,
        );
    }

    /**
     * @param list<ToolDefinition> $tools
     */
    public function withTools(array $tools): self
    {
        return new self(
            $this->model,
            $this->messages,
            $this->timeoutSeconds,
            $tools,
            $this->toolChoice,
            $this->responseFormat,
            $this->maxOutputTokens,
            $this->temperature,
            $this->traceId,
        );
    }

    public function withToolChoice(ToolChoice $choice): self
    {
        return new self(
            $this->model,
            $this->messages,
            $this->timeoutSeconds,
            $this->tools,
            $choice,
            $this->responseFormat,
            $this->maxOutputTokens,
            $this->temperature,
            $this->traceId,
        );
    }

    public function withResponseFormat(?ResponseFormat $format): self
    {
        return new self(
            $this->model,
            $this->messages,
            $this->timeoutSeconds,
            $this->tools,
            $this->toolChoice,
            $format,
            $this->maxOutputTokens,
            $this->temperature,
            $this->traceId,
        );
    }

    /**
     * @param array<mixed> $messages
     */
    private static function assertMessages(array $messages): void
    {
        if ($messages === []) {
            throw new InvalidArgumentException('A request needs at least one message.');
        }
        if (! array_is_list($messages)) {
            throw new InvalidArgumentException('The messages of a request must be a list.');
        }

        $otherRoleSeen = false;
        foreach ($messages as $message) {
            if (! $message instanceof Message) {
                throw new InvalidArgumentException('The messages of a request hold a value that is not a message.');
            }
            if ($message->role !== Role::System) {
                $otherRoleSeen = true;
            } elseif ($otherRoleSeen) {
                throw new InvalidArgumentException('A system message must come before the messages of other roles.');
            }
        }
    }

    /**
     * @param array<mixed> $tools
     * @return array<string, true> the tool names as keys
     */
    private static function toolNames(array $tools): array
    {
        if (! array_is_list($tools)) {
            throw new InvalidArgumentException('The tools of a request must be a list.');
        }

        $names = [];
        foreach ($tools as $tool) {
            if (! $tool instanceof ToolDefinition) {
                throw new InvalidArgumentException('The tools of a request hold a value that is not a tool definition.');
            }
            if (isset($names[$tool->name])) {
                throw new InvalidArgumentException('Two tools of a request have the same name.');
            }
            $names[$tool->name] = true;
        }

        return $names;
    }
}
