<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Presets;

use OrganiseYou\LlmGateway\Adapter\Anthropic\MessagesAdapter;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\ModelOverride;
use OrganiseYou\LlmGateway\Preset\Preset;
use OrganiseYou\LlmGateway\Preset\PresetField;

/**
 * The entry `claude` of docs/design/adapters.md, section 9, row 3: Anthropic's own Messages API.
 *
 * The library ships no presets (decision G140). This file is the worked definition the tests prove, written so that
 * a host can copy it as it is. Every value comes from docs/providers/anthropic.md (read on 2026-11-27) and the
 * design. A package that copies this file gives it its own namespace.
 */
final readonly class Claude
{
    /**
     * The options of row 3: the current version of the API, on which a temperature is a 400 on every model; and the
     * maximum of output tokens for a request that gives none, which the format requires and the provider gives no
     * default for (16000 is this library's choice, decision G69).
     */
    public const array OPTIONS = [
        'api_version' => '2026-10-21',
        'default_max_output_tokens' => 16000,
        'temperature' => 'not_accepted',
    ];

    /**
     * The six models on which a forced tool call (`any` or one named tool) is always a 400, by the Claude API ids
     * the provider file records ("Forced tool call" and "Model name", read on 2026-11-27; decision G141). Each id is
     * a pinned snapshot without a date, so each is matched exactly.
     */
    public const array WITHOUT_FORCED_TOOL_CALL = [
        'claude-fable-5-2',
        'claude-mythos-5-2',
        'claude-opus-5-5',
        'claude-sonnet-5-5',
        'claude-fable-5-1',
        'claude-mythos-5-1',
    ];

    public static function preset(): Preset
    {
        return new Preset(
            id: 'claude',
            label: 'Claude (Anthropic)',
            description: 'Claude through Anthropic\'s own API: you need an API key from the "API keys" page of the Claude Console, and the workspace id (wrkspc_…) when the key is not tied to one workspace; thinking tokens count toward the maximum of output tokens.',
            adapter: MessagesAdapter::class,
            baseUrlRule: BaseUrlRule::Fixed,
            defaultBaseUrl: 'https://api.anthropic.com',
            credentialKinds: [CredentialKind::BearerKey, CredentialKind::HeaderKey],
            keyHeader: 'x-api-key',
            fields: [new PresetField('workspace_id', 'Workspace id', false, 'wrkspc_[A-Za-z0-9_-]+', 'anthropic-workspace-id')],
            dataLocation: 'No EU-only option: the provider states that for the inference location only "us" and "global" are available, that "global", the default, means "inference may run in any available geography", and that for storage "us" is the only available workspace geo.',
            dataLocationSource: 'https://platform.claude.com/docs/en/manage-claude/data-residency',
            sendsNoStoreOption: false,
            adapterOptions: self::OPTIONS,
            modelOverrides: array_map(
                fn(string $model): ModelOverride => new ModelOverride($model, ['forced_tool_call' => 'none']),
                self::WITHOUT_FORCED_TOOL_CALL,
            ),
            storageNote: 'No request option against storing: zero data retention is an arrangement per organisation through Anthropic\'s sales team; Claude Fable 5.2, 5.1, 5 and Claude Mythos 5.2, 5.1, 5 require 30-day retention and are refused with a 400 for an organisation whose retention setting does not allow it; a JSON Schema for a structured answer is cached for up to 24 hours, also under zero data retention; "Retained data is never used for model training without your express permission"; and without zero data retention the privacy-centre article "How long do you store my organization\'s data" gives deletion "within 30 days of receipt or generation", with exceptions (condensed reading).',
            storageNoteSource: 'https://platform.claude.com/docs/en/manage-claude/api-and-data-retention',
        );
    }
}
