<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * How the model may use the tools of a request.
 */
enum ToolChoiceMode: string
{
    case Auto = 'auto';
    case None = 'none';
    case Required = 'required';
    case Tool = 'tool';
}
