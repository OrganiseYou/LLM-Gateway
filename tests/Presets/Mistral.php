<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Presets;

use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\ChatCompletionsAdapter;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\Preset;

/**
 * The entry `mistral` of docs/design/adapters.md, section 9, row 13: Mistral's hosted API at its global address.
 *
 * The library ships no presets (decision G140). This file is the worked definition the tests prove, written so that
 * a host can copy it as it is. Every value comes from docs/providers/mistral.md (read on 2026-11-27) and the design.
 * A package that copies this file gives it its own namespace.
 */
final readonly class Mistral
{
    /**
     * The options of row 13: the field `max_tokens`, the word `any` for "must call some tool", the name on a tool
     * result, the content list as the carrier of reasoning, and a stream that is not asked for its usage.
     */
    public const array OPTIONS = [
        'max_tokens_field' => 'max_tokens',
        'required_word' => 'any',
        'tool_result_name' => true,
        'reasoning_carrier' => 'content_entries',
        'stream_usage' => 'unasked',
    ];

    public static function preset(): Preset
    {
        return new Preset(
            id: 'mistral',
            label: 'Mistral',
            description: 'Mistral\'s hosted API at its global address; you need an API key, made in the Studio console (console.mistral.ai) under "API Keys", which has an expiry date.',
            adapter: ChatCompletionsAdapter::class,
            baseUrlRule: BaseUrlRule::Fixed,
            defaultBaseUrl: 'https://api.mistral.ai/v1',
            credentialKinds: [CredentialKind::BearerKey],
            keyHeader: null,
            fields: [],
            dataLocation: 'No processing location is committed on this address: "Mistral does not commit to a specific inference location for requests sent to this endpoint." Processing in the EU is committed only on the EU address.',
            dataLocationSource: 'https://docs.mistral.ai/inference/regional-inference',
            sendsNoStoreOption: false,
            adapterOptions: self::OPTIONS,
            storageNote: 'No request option against storing: zero data retention is an account setting, "available only with pay-as-you-go and only for stateless API calls", asked from Mistral\'s support; Mistral\'s help-centre articles on training add that on the free mode input and output may be used for training unless that is switched off in the Admin panel.',
            storageNoteSource: 'https://help.mistral.ai/en/articles/347612-can-i-activate-zero-data-retention-zdr',
        );
    }
}
