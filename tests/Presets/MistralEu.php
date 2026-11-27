<?php

declare(strict_types=1);

namespace OrganiseYou\LlmGateway\Tests\Presets;

use OrganiseYou\LlmGateway\Adapter\OpenAiCompatible\ChatCompletionsAdapter;
use OrganiseYou\LlmGateway\Contract\Credentials\CredentialKind;
use OrganiseYou\LlmGateway\Preset\BaseUrlRule;
use OrganiseYou\LlmGateway\Preset\Preset;

/**
 * The entry `mistral-eu` of docs/design/adapters.md, section 9, row 14: Mistral's hosted API at its EU address. A
 * second entry and not a setting of `mistral`, because one address cannot state its data location truthfully
 * (decision G73), and a setting must never choose the host (G43).
 *
 * The library ships no presets (decision G140). This file is the worked definition the tests prove, written so that
 * a host can copy it as it is. Every value comes from docs/providers/mistral.md (read on 2026-11-27) and the design.
 * A package that copies this file gives it its own namespace.
 */
final readonly class MistralEu
{
    /** The options of row 14: those of row 13. */
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
            id: 'mistral-eu',
            label: 'Mistral, Europe',
            description: 'Mistral\'s hosted API at its EU address, at 1.1 times the list price; you need an API key from the Studio console (console.mistral.ai), and since the models can differ per region, ask the model list for what this address serves.',
            adapter: ChatCompletionsAdapter::class,
            baseUrlRule: BaseUrlRule::Fixed,
            defaultBaseUrl: 'https://api.eu.mistral.ai/v1',
            credentialKinds: [CredentialKind::BearerKey],
            keyHeader: null,
            fields: [],
            dataLocation: 'Processed in data centres in EU and EFTA countries; "Regional inference does not make the Mistral control plane regional": account data, keys, billing and usage figures may be handled elsewhere.',
            dataLocationSource: 'https://docs.mistral.ai/inference/regional-inference',
            sendsNoStoreOption: false,
            adapterOptions: self::OPTIONS,
            storageNote: 'No request option against storing: zero data retention is an account setting, "available only with pay-as-you-go and only for stateless API calls", asked from Mistral\'s support; Mistral\'s help-centre articles on training add that on the free mode input and output may be used for training unless that is switched off in the Admin panel.',
            storageNoteSource: 'https://help.mistral.ai/en/articles/347612-can-i-activate-zero-data-retention-zdr',
        );
    }
}
