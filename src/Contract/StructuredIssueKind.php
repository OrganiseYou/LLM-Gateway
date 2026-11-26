<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Contract;

/**
 * Why a structured answer is unusable.
 */
enum StructuredIssueKind: string
{
    case NotJson = 'not_json';
    case SchemaMismatch = 'schema_mismatch';
    case CutOff = 'cut_off';
}
