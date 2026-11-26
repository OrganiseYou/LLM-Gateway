# Gemini (Google Cloud / Vertex AI)

Read on 2026-11-27. Hosted text chat API: yes, two ways in.

- **Way A, the Gemini API** (Google calls it the "Gemini Developer API"): one global host, an API key.
- **Way B, Google Cloud**: a project, a location and a short-lived access token. Google's documentation now titles this
  product "Gemini Enterprise Agent Platform"; the addresses still contain `vertex-ai` and `aiplatform`, and the SDK
  flag is still `vertexai=True` [G22][C3]. This file says "Cloud" for way B.

Every link in "Sources" was opened on 2026-11-27. A tag such as [G2] or [C11] after a fact points to that list.
`ai.google.dev` now redirects (301) to `aistudio.google.com`, and `cloud.google.com/vertex-ai/...` to
`docs.cloud.google.com/...`; both targets are Google's own. Pages longer than about 100,000 characters were read in
parts; where a part was not reached, the fact is listed under "Not confirmed". All examples below are synthetic.

Both ways in also have a newer, second native API, the **Interactions API** (`/interactions`, typed steps, server-side
state on by default). Google recommends it "for all new development" and says `generateContent` "remains fully
supported"; no end date is given [G21]. Google's default guides now show Interactions; the `generateContent` variants
of the same guides are the ones with `?generate-content=true`. This file describes `generateContent`, because it is
stateless by design (LR-01). On Cloud, Interactions is in Preview and only on the `global` location [G22].

## Reaching it

- Base URL and the chat path:
  - A: `POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` and
    `...:streamGenerateContent` [G2][G3]. The method list shows these only under `v1beta` [G14].
  - B: `POST https://{service-endpoint}/v1/projects/{project}/locations/{location}/publishers/google/models/{model}:generateContent`
    and `...:streamGenerateContent` [C1][C2]. The service endpoint is `https://{location}-aiplatform.googleapis.com`
    for a region, `https://aiplatform.googleapis.com` with `locations/global`, `https://aiplatform.eu.rep.googleapis.com`
    with `locations/eu`, `https://aiplatform.us.rep.googleapis.com` with `locations/us` [C3][C15][C29]. A `v1beta1`
    path exists too and carries newer features (see call ids below) [C11][C10].
  - B with an API key ("express mode"): `POST https://aiplatform.googleapis.com/v1/publishers/google/models/{model}:streamGenerateContent`,
    no project and no location in the path [C25]. The matching `:generateContent` page was seen only in a search
    summary, not opened.
- Hosted by the vendor, run by the customer, or both: vendor, both ways in. B runs inside the customer's own Google
  Cloud project [C1].
- API format: both. Its own format (`generateContent`) is the fuller one. An OpenAI-compatible endpoint exists on both:
  - A: `https://generativelanguage.googleapis.com/v1beta/openai/` (`chat/completions`, `models`), key as bearer token.
    Status, in Google's words: "Support for the OpenAI libraries is still in beta while we extend feature support."
    The page shows `tools` with `tool_choice: "auto"`, `stream: true`, structured output through the SDK helper, and
    model listing; `parallel_tool_calls` and the other `tool_choice` values are not mentioned [G15].
  - B: `https://aiplatform.googleapis.com/v1/projects/{project}/locations/{location}/endpoints/openapi` (a second
    example on the same page uses `https://{location}-aiplatform.googleapis.com/v1beta1/...`; the page does not explain
    the difference). Model names are `google/{model}`. Sign-in is the Google Cloud access token passed as the key
    [C23]. Supported parameters include `tools`, `tool_choice` (`required` maps to mode `ANY`; `validated` is
    Google-specific), `response_format`, `stream`; unsupported parameters are ignored; "Fully recursive schemas are
    not supported". Recommended only for those already using the OpenAI libraries. No status word (GA, preview, beta)
    is on the pages read [C22].
- API versions or dated headers a request must send: none for `generateContent`; the version is in the path. A:
  `v1` is "stable", `v1beta` is early access [G13], yet `generateContent` is listed only under `v1beta` [G14]. (The
  Interactions guide sends `Api-Revision: 2026-05-20`; that is not `generateContent` [G23].)

## Signing in

- Method:
  - A: the header `x-goog-api-key: <key>` [G4][G6]. The reference examples also show `?key=<key>` in the address
    [G3][G12]; Google's own key guidance warns that the query form exposes the key in URLs [C27]. On the
    OpenAI-compatible endpoint: `Authorization: Bearer <key>` [G15].
  - B: `Authorization: Bearer <access token>` [C11][C12]. The token is a Google Cloud OAuth access token (scope
    `https://www.googleapis.com/auth/cloud-platform` [C10]); "By default, service account access tokens last for 1
    hour" [C23]. The caller needs the IAM permission `aiplatform.endpoints.predict` on the model [C1]. The project and
    the location are in the path, not in a header. With user credentials some Google APIs also want
    `x-goog-user-project` for the quota project [C27].
  - B also accepts a Google Cloud API key (express-mode keys for `@gmail.com` accounts; standard keys bound to a
    service account). Google: "Use an API key for testing and Application Default Credentials for production." [C24]
    The header for Google Cloud API keys in general is `X-goog-api-key` [C27]; that B's model endpoints take it in
    that header is from a search summary of the express-mode overview, not from a page opened.
- What the customer must create at the provider, in plain words:
  - A: a Google account, a project in Google AI Studio (it is a Google Cloud project underneath) and an API key on the
    API-keys page. For use with people in the EEA, Switzerland or the UK the project must be on paid billing [G11][G16].
  - B: a Google Cloud project with billing and the API switched on, a service account that may call the models, and a
    way to mint access tokens for it (the application supplies the token; the library never holds the service-account
    key, LR-25). Plus the choice of a location (`eu`, a region, or `global`) [C15][C27].

## What a request can do

| | Supported | How (field names) | Link |
|---|---|---|---|
| Tool calling | yes, both | `tools: [{functionDeclarations: [{name, description, parameters \| parametersJsonSchema}]}]`; the answer holds `functionCall` parts | [G2][G4][C11][C12] |
| Forced tool call | yes, both; one named function can be forced | `toolConfig.functionCallingConfig: {mode: "ANY", allowedFunctionNames: ["<name>"]}`. Modes: `AUTO` (default), `ANY`, `NONE`, `VALIDATED`. The pages disagree on whether `allowedFunctionNames` also works with `VALIDATED`: A's reference says `ANY` or `VALIDATED` [G2]; B's type page says "only ... when the Mode is ANY" [C7] | [G2][G4][C7][C12] |
| Parallel tool calls | yes, both; no documented switch to turn it off | several `functionCall` parts in one model turn; all `functionResponse` parts go back together in one `user` content | [G4][C11] |
| Structured output with a JSON Schema | yes, both; the field names differ per page (see "Schema dialect" below) | A, REST guide: `generationConfig.responseFormat.text: {mimeType: "APPLICATION_JSON", schema: {...}}` [G5]. B, guide: `generationConfig.responseMimeType: "application/json"` + `responseSchema` [C13]; B's type page marks those two and `responseJsonSchema` deprecated in favour of `responseFormat` [C5] | [G2][G5][C5][C13] |
| Tools together with structured output | A: yes on Gemini 3 and Gemini 4 series only, called a preview [G5]; mode `VALIDATED` is the default when both are on [G4]. B: the function-calling guide says it "can be combined with structured output", no model list given [C11]; the structured-output guide is silent [C13] | both fields in one request | [G4][G5][C11] |
| Streaming (and whether usage arrives in the stream) | yes, both. Usage: yes, `usageMetadata` is a field of every chunk [G6][C4] | `:streamGenerateContent?alt=sse` gives server-sent events, each `data:` line one `GenerateContentResponse` [G3][G6][C29]. `alt` is a Google-wide query parameter: "Supported values are `json` (default), `media`, `proto`, `sse` (JSON streams)" [C28]. What the body looks like without `alt=sse` is not stated on the pages read | [G3][G6][C2][C28][C29] |
| System instructions | a separate field, not a role | `systemInstruction: {parts: [{text}]}`; text only; B ignores its `role` [G2][C1][C10]. A's REST example spells it `system_instruction` [G6] | [G2][G6][C1] |
| Listing models | A: yes. B: only a catalogue list on `v1beta1` was found | A: `GET /v1beta/models` (`pageSize` default 50, max 1000; `pageToken`), answer `{models: [...], nextPageToken}`; each model has `name` (`models/{id}`), `baseModelId`, `inputTokenLimit`, `outputTokenLimit`, `supportedGenerationMethods`, `temperature`, `maxTemperature`, `thinking` [G12]. Also `GET /v1beta/openai/models` [G15]. B: `GET https://{service-endpoint}/v1beta1/publishers/google/models` (`filter`, `pageSize`, `pageToken`, `listAllVersions`), answer `{publisherModels: [...], nextPageToken}` [C26]; it lists the catalogue, not what the credentials may call | [G12][C26] |

## Limits and shapes

### Request

```json
{
  "systemInstruction": {"parts": [{"text": "Answer briefly."}]},
  "contents": [
    {"role": "user", "parts": [{"text": "What is the status of order 1?"}]}
  ],
  "tools": [{"functionDeclarations": [{
    "name": "get_order",
    "description": "Looks up one order.",
    "parameters": {"type": "object", "properties": {"id": {"type": "integer"}}, "required": ["id"]}
  }]}],
  "toolConfig": {"functionCallingConfig": {"mode": "AUTO"}},
  "generationConfig": {"maxOutputTokens": 1000}
}
```

- Top-level fields, A: `contents` (required), `systemInstruction`, `tools`, `toolConfig`, `safetySettings`,
  `generationConfig`, `cachedContent`, `serviceTier`, `store`, `labels`, `continuationToken` [G2]. B: `contents`
  (required), `systemInstruction`, `tools`, `toolConfig`, `safetySettings`, `generationConfig`, `cachedContent`,
  `labels`, `modelArmorConfig` [C1]. B has no `store` field on `generateContent`.
- `contents[]` is `{role, parts[]}`. `role` is `user` or `model` only [G1][C6]. There is no system role and no tool
  role: a tool result is a `functionResponse` part in a `user` content [G4][C11] (one Cloud example uses the role
  `tool`; the reference allows only `user` and `model` [C11][C6]).
- A part holds one of `text`, `inlineData`, `fileData`, `functionCall`, `functionResponse`, and others not needed for
  text chat; next to it may sit `thought` (boolean) and `thoughtSignature` (opaque string) [G1][C6].
- Casing: the references use camelCase. Google's own REST examples mix in snake_case (`system_instruction` [G6],
  `generation_config`, `function_declarations` [C13][C11]). Build to the reference's camelCase.
- Required fields a neutral request may lack: none. `maxOutputTokens` is optional; the default differs per model
  [G2][C5].
- Temperature: A says 0.0 to 2.0, and it is "silently ignored" on Gemini 3.6 Flash and newer [G2]; the text guide adds
  that Gemini 4 and 3.6+ answer with deprecation warning headers and "will return `400` errors in a future release"
  [G6]. B's type page says greater than 0.0 up to 2.0 [C5], while B's function-calling guide recommends 0 [C11].
- Tool name rules: the pages disagree. A's reference: letters, digits, underscores, colons, dots, dashes, at most 128
  [G1]. B's type page: starts with a letter or underscore, then a-z, A-Z, 0-9, underscores, dots, colons, dashes, at
  most 128 [C8]. B's function-calling reference and guide: the same without colons, at most 64 [C12][C11]. Safe for
  both: starts with a letter or underscore, only letters, digits and underscores, at most 64. Parameter names on B:
  letter or underscore first, letters, digits, underscores, at most 64 [C8]. B allows at most 128 function
  declarations per request [C12]; A gives no number and advises 10 to 20 active tools [G4].
- Model name shape: a bare id such as `gemini-<version>-<tier>` in the path (A and B), `models/{id}` as a resource
  name on A, `google/{id}` on B's OpenAI-compatible endpoint [G2][G12][C10].

### Answer

```json
{
  "candidates": [{
    "index": 0,
    "content": {"role": "model", "parts": [
      {"functionCall": {"id": "call-1", "name": "get_order", "args": {"id": 1}}, "thoughtSignature": "c2lnLTE="}
    ]},
    "finishReason": "STOP"
  }],
  "usageMetadata": {"promptTokenCount": 40, "candidatesTokenCount": 12, "thoughtsTokenCount": 5, "totalTokenCount": 57},
  "modelVersion": "<model>",
  "responseId": "resp-1"
}
```

- Answer fields: `candidates[]` (`index`, `content`, `finishReason`, `finishMessage`, `safetyRatings`, ...),
  `promptFeedback` (`blockReason`, `safetyRatings`; B adds `blockReasonMessage`), `usageMetadata`, `modelVersion`,
  `responseId`; B adds `createTime` [G1][G2][C4].
- The text of an answer is the `text` of the parts; parts with `thought: true` are the model's reasoning, not the
  answer [G1][C6].
- **The shape of a tool call in an answer**: a part `{"functionCall": {"id", "name", "args"}}`. **`args` is a JSON
  object, never a string** [G1][C6]. With other tools active the call is not always the last part: walk all parts
  [G4].
- **The shape of a tool result in a request**: first the model's own content with its `functionCall` parts, unchanged,
  then one `user` content with one part per result:
  `{"functionResponse": {"id": "call-1", "name": "get_order", "response": {"output": {...}}}}`. `name` and `response`
  are required [G1][C6]. B: use the key `output` for the result and `error` for a failure; without either the whole
  object counts as output [C6]. A: any keys, with `error` for a failed call [G1].
- **How a result is matched to its call**:
  - A: by `id`. "The Gemini API maps each result back to its corresponding call using the `id` from the model's
    output." [G4] On order the same page says both that the results need not be in the same order and, elsewhere, to
    "include the responses in the same order as they were requested" [G4].
  - B: by `id` only on `v1beta1` with Gemini 3 and later; on `v1`, or without an `id`, by `name` and then by position:
    responses with that name "are matched sequentially in the order they appear in the `parts` array". A
    `functionResponse.name` that matches no call is a `400` [C11][C12].
  - For both: always echo `id` when one came, always send `name`, and keep the results in the order of the calls.
- **Thought signatures** (both ways in): Gemini 3 and Gemini 4 models put an opaque `thoughtSignature` on the part of
  a `functionCall`; with parallel calls only on the first one. It must be sent back in the same part, and all calls
  must precede all results (`FC1+signature, FC2, FR1, FR2`); a missing signature or interleaving is a `400`. On text
  parts returning it is recommended, not enforced. For a call the model did not produce, the value
  `skip_thought_signature_validator` skips the check ("a last resort") [G7][C14]. In a stream the signature can
  arrive in a part with empty text [G7]. Consequence for the library: the neutral tool call must carry the call `id`
  and one opaque continuation value back to the adapter, or a tool round fails on these models.
- **Finish reasons** (`candidates[].finishReason`):

  | Value | Meaning | A | B |
  |---|---|---|---|
  | `STOP` | natural end or a stop sequence; also the value when the model answered with a tool call (no separate tool-call reason is documented) | yes | yes |
  | `MAX_TOKENS` | the output limit was reached | yes | yes |
  | `SAFETY` | stopped by the safety filter; on B, when streaming, `content` is empty | yes | yes |
  | `RECITATION` | too close to existing text | yes | yes |
  | `BLOCKLIST`, `PROHIBITED_CONTENT`, `SPII` | forbidden terms, prohibited content, sensitive personal data | yes | yes |
  | `LANGUAGE` | unsupported language | yes | no |
  | `MALFORMED_FUNCTION_CALL` | the model produced a tool call that cannot be parsed | yes | yes |
  | `UNEXPECTED_TOOL_CALL` | a tool call although none was enabled or declared | yes | yes |
  | `TOO_MANY_TOOL_CALLS` | too many consecutive (server-side) tool calls | yes | yes |
  | `MISSING_THOUGHT_SIGNATURE` | a thought signature was missing from the request | yes | no |
  | `MALFORMED_RESPONSE` | malformed answer | yes | yes |
  | `CONTINUATION` | per-request token limit, continue with `continuationToken` | yes | yes (`v1beta1`) |
  | `MODEL_ARMOR` | blocked by Model Armor | no | yes |
  | `ESCALATION`, `PUP_LIMITED_DISABLED` | escalation rule; account limited for policy violations | yes | no |
  | `IMAGE_*`, `NO_IMAGE` | image generation only | yes | yes |
  | `OTHER`, `FINISH_REASON_UNSPECIFIED` | unknown; unused default | yes | yes |

  Sources [G1][C4]. B's inference page lists the same reasons with a `FINISH_REASON_` prefix (`FINISH_REASON_STOP`,
  ...), its type page without [C10][C4]; accept both spellings.
- **A blocked prompt is a 200 with no candidates**: `promptFeedback.blockReason` is `SAFETY`, `OTHER`, `BLOCKLIST`,
  `PROHIBITED_CONTENT`, `IMAGE_SAFETY`, and on B also `MODEL_ARMOR` and `JAILBREAK` (unset value:
  `BLOCK_REASON_UNSPECIFIED` on A, `BLOCKED_REASON_UNSPECIFIED` on B). On B it is "sent only in the first stream
  chunk" [G1][C4]. For LR-32 this is a provider error inside a 200.
- **Usage**: `usageMetadata` has `promptTokenCount` (includes cached tokens), `candidatesTokenCount`,
  `thoughtsTokenCount`, `toolUsePromptTokenCount`, `cachedContentTokenCount`, `totalTokenCount` (prompt + tool-use
  prompt + thoughts + candidates) and per-modality details; A adds `serviceTier`, B adds `trafficType` [G1][C4].
  Thinking tokens are counted apart from `candidatesTokenCount`; output tokens for LR-07 are the two together.
- **Streaming**: each event is a whole `GenerateContentResponse` with the new parts only; the text guide's chunks each
  carry `candidates`, `usageMetadata`, `modelVersion` and `responseId` [G6]. `finishReason` is "empty if the model has
  not stopped" [C4]. Structured output streams as partial JSON strings to concatenate [G5]. Tool-call arguments arrive
  whole unless `toolConfig.functionCallingConfig.streamFunctionCallArguments` is set (B, Gemini 3+, preview), which
  sends `partialArgs` and `willContinue` [C11][C7].

### Schema dialect

Two schema fields exist on both ways in, for tools and for structured output alike.

- **The OpenAPI-subset `Schema`** (`parameters`, `responseSchema`). Fields on A: `type` (`STRING`, `NUMBER`,
  `INTEGER`, `BOOLEAN`, `ARRAY`, `OBJECT`, `NULL`; the examples also use lowercase), `format`, `title`, `description`,
  `nullable`, `enum` (strings), `items`, `minItems`, `maxItems`, `properties`, `required`, `minProperties`,
  `maxProperties`, `minimum`, `maximum`, `minLength`, `maxLength`, `pattern`, `example`, `anyOf`, `propertyOrdering`,
  `default` (accepted and ignored) [G1][G2]. B has the same plus `additionalProperties`, and **`ref` and `defs`
  without the `$`** (`#/defs/Name`, root level only) [C9]. Not in this type: `$ref`, `$defs`, `oneOf`, `allOf`,
  `not`, `const`, `additionalProperties` (A).
  - B, what structured output honours: `anyOf`, `enum` (non-empty strings; an empty one is `400 INVALID_ARGUMENT`),
    `format` (`date`, `date-time`, `duration`, `time`), `items`, `maximum`, `maxItems`, `minimum`, `minItems`,
    `nullable`, `properties` (an empty key is a `400`), `propertyOrdering`, `required`, `description`. "If you use an
    unsupported field, Agent Platform can still handle your request but ignores the field." [C13]
  - B, what function declarations honour: `type`, `nullable`, `required`, `format`, `description`, `properties`,
    `items`, `enum`, `anyOf`, `$ref`, `$defs`; "Remaining attributes are not supported." In `generateContent` write
    `ref` and `defs` without `$`. Nesting at most 32 deep; self-reference at most two deep [C11].
- **Plain JSON Schema** (`parametersJsonSchema` for a tool, mutually exclusive with `parameters` [G1][C8]; for the
  answer `responseFormat.text.schema` on A [G5], `responseJsonSchema` or `responseFormat` on B [C5]).
  - A accepts "a subset of the JSON Schema specification": types `string`, `number`, `integer`, `boolean`, `object`,
    `array`, `null` (nullable as `{"type": ["string", "null"]}`); `title`, `description`; `properties`, `required`,
    `additionalProperties`; `enum`; `format` (`date-time`, `date`, `time`); `minimum`, `maximum`; `items`,
    `prefixItems`, `minItems`, `maxItems`; `anyOf`; `$defs` and `$ref`. "The model ignores unsupported properties."
    [G5] The Interactions variant of the same page names the ignored ones: `minLength`, `maxLength`, `minProperties`,
    `maxProperties`, `uniqueItems`, `contains`, `minContains`, `maxContains` are stripped, not refused [G23].
  - B: which JSON Schema keywords `responseJsonSchema` and `parametersJsonSchema` accept is not listed on the pages
    read; the type page's example uses `type`, `properties`, `additionalProperties`, `required`, `propertyOrdering`
    [C8].
- Refused, both: a schema that is too large or too deeply nested is `400 INVALID_ARGUMENT` [G5][C13]. No numeric limit
  on A.
- Key order: A follows the order of the keys in the schema (Gemini 2.0 models need `propertyOrdering`) [G5]. B does
  not follow `properties` order; use `propertyOrdering` [C13].
- The answer is "syntactically valid JSON", not checked values: the library still validates (LR-07) [G5]. On B,
  `application/json` without a schema is only "a strong hint" [C13].
- Field names for structured output, as the pages disagree: A's guide (REST) uses `responseFormat.text` [G5]; A's
  reference lists `responseMimeType`, `responseFormat`, and `responseJsonSchema` with the self-referring note "An
  internal detail. Use `responseJsonSchema` rather than this field." [G2]; A's migration page says `generateContent`
  uses `response_mime_type` and `response_schema` [G21]. B's guide uses `responseMimeType` + `responseSchema` [C13];
  B's type page marks `responseMimeType`, `responseSchema` and `responseJsonSchema` deprecated, "Use
  `responseFormat` instead", and shows `responseFormat` as a list [C5] where A shows an object [G2].

### Errors

- Body, both (Google's common error model [C19]):

  ```json
  {"error": {"code": 429, "message": "Synthetic example.", "status": "RESOURCE_EXHAUSTED",
             "details": [{"@type": "type.googleapis.com/google.rpc.ErrorInfo", "reason": "EXAMPLE_REASON",
                          "domain": "googleapis.com", "metadata": {}}]}}
  ```

  `code` is the HTTP status, `status` an upper-case word, `details` a list of typed objects such as `ErrorInfo` [G8].
  B's error page shows no example body [C19].

| HTTP | `status` | A [G8][G9] | B [C19][C21] |
|---|---|---|---|
| 400 | `INVALID_ARGUMENT` | malformed request; schema too complex; **also the page's example for a wrong key** (`details[].reason: "API_KEY_INVALID"`) | failed validation, input too long, bad schema |
| 400 | `FAILED_PRECONDITION` | free tier not available in the country, billing needed | model needs allow-listing or is blocked by policy |
| 401 | `UNAUTHENTICATED` | not on the page | token missing, invalid or expired |
| 402 | `RESOURCE_EXHAUSTED` | prepaid credit used up; do not retry | not on the page |
| 403 | `PERMISSION_DENIED` | key lacks permission, is wrong, blocked or leaked; project needs verification or billing | caller lacks permission |
| 404 | `NOT_FOUND` | "file not found" (a referenced media file) | "No valid object exists at the specified URL" |
| 429 | `RESOURCE_EXHAUSTED` | rate limit (per project, not per key [G10]) | quota exceeded or shared capacity overloaded |
| 499 | `CANCELLED` | client closed the connection | client cancelled |
| 500 | `INTERNAL` (B also `UNKNOWN`) | error on Google's side | overload or dependency failure |
| 503 | `UNAVAILABLE` | overloaded or down | temporarily unavailable |
| 504 | `DEADLINE_EXCEEDED` | not finished in time | deadline passed |

- Bad credentials: B is 401 `UNAUTHENTICATED` [C19]. A is inconsistent on its own page: the example body for an
  invalid key is 400 `INVALID_ARGUMENT` with reason `API_KEY_INVALID`, the table puts a wrong key under 403 [G8]. Map
  400 with that reason, 401 and 403 to "credentials rejected".
- Rate limit: 429 `RESOURCE_EXHAUSTED` on both. On A, 402 has the same `status` word but means no credit: tell them
  apart by the HTTP code [G8].
- Unknown model: not documented on either way in (see "Not confirmed").
- Retry hint: none documented. No `Retry-After` header, no rate-limit headers and no delay field in the body are
  described on [G8][G9][G10][C19][C20][C21]. The advice is exponential backoff with jitter on 408, 429 and 5xx, and no
  retry on other 4xx [G9][C21].

## Data

- Where requests are processed, as the provider states it:
  - A: no region can be chosen and no location is promised. The terms: "This data may be stored transiently or cached
    in any country in which Google or its agents maintain facilities." [G16] The regions page lists only the countries
    where the service may be used (all EU member states, the Netherlands included) and says nothing on processing
    [G20].
  - B: the customer chooses. Multi-region endpoints "let you ensure that machine learning processing of Customer Data
    by the service stays within a specific jurisdictional boundary, such as the United States or the European Union"
    [C15]; the `eu` multi-region covers EU member states and excludes the United Kingdom and Switzerland [C16]. A
    regional endpoint keeps ML processing within that region's country or multi-region jurisdiction; stored data
    "remains at rest in that location" [C16]. The `global` endpoint: "Don't use the global endpoint if you have ML
    processing requirements, because you can't control or know which region your ML processing requests are sent to."
    [C15] European regions: `europe-west1` Belgium, `europe-west2` London, `europe-west3` Frankfurt, `europe-west4`
    Netherlands, `europe-west6` Zürich, `europe-west8` Milan, `europe-west9` Paris, `europe-southwest1` Madrid,
    `europe-north1` Finland, `europe-central2` Warsaw [C15]. Which Gemini model runs where is per model: as read, the
    residency table has most Gemini models under the `eu` multi-region, a few under Germany and France, and no Gemini
    model under the Netherlands region [C16].
- The option not to store requests, and whether training on them is off by default:
  - A, training: off for paid use ("Google doesn't use your prompts ... or responses to improve our products"); on for
    the unpaid tier, with human review; for users in the EEA, Switzerland and the UK the paid terms apply to all use
    [G16].
  - A, storing: `generateContent` keeps no conversation. Three things still store: (1) abuse monitoring keeps prompts
    and outputs, 55 days per the policy page, "a limited period" per the terms, with no opt-out described [G18][G16];
    (2) developer logs, visible to the project, are **on by default** for `generateContent` in projects created on or
    after 2026-10-26, kept up to 55 days, not used for training unless shared; the request field `store` "takes
    precedence over the project-level" setting [G17][G2]; (3) explicit caches and uploaded files, which the library
    does not use. For guaranteed zero retention Google's page points to Cloud [G19].
  - B, training: "Google won't use your data to train or fine-tune any AI/ML models without your prior permission or
    instruction." [C17]
  - B, storing: there is no per-request field on `generateContent`. Caching is on by default (in memory, at most 24
    hours; durable caching on supported models) and is switched off per project with a `PATCH` on
    `/v1/projects/{project}/cacheConfig` (`disableCache: true`) by a project admin [C17]. Prompts may be logged for
    abuse monitoring when flagged, up to 90 days in the chosen region; customers with a Google Cloud Master Agreement
    are exempt by default; the newest "Frontier" models log prompts and answers for up to 30 days [C18].
    Request-response logging is off by default [C17].

## Not confirmed

- **HTTP status for an unknown model**, both ways in. The error pages tie 404 to a missing file or object, not to a
  model name [G8][C19]. Tried: both error pages, the troubleshooting page and a search of Google's documentation;
  only forum posts mention it, and a forum is not a source.
- **A retry hint in a 429** (a header or a delay in `error.details`): not on any page read [G8][G9][G10][C19][C20]
  [C21]. Google's general API error guide was not read (its address redirects off the documentation host).
- **The stream's body without `alt=sse`**. The Google-wide parameter page says the default `alt` is `json` [C28]; no
  page read shows that body for `streamGenerateContent`. Build to `alt=sse` only.
- **Which chunk carries the final `usageMetadata` and `finishReason`**: every chunk has `usageMetadata` in A's example
  [G6]; no page says the last one holds the totals. Take the last value seen.
- **Errors after a stream has started**: not described on either way in.
- **A finish reason that marks a tool call**: none exists in the lists read; a tool call is recognised by its part.
- **`generateContent` under `/v1/` on A**: the version page calls `v1` stable [G13], the method list shows only
  `v1beta` [G14], one guide example posts to `/v1/` [G4].
- **A `v1` model list on B**, and a list of the models one project may call: only the `v1beta1` catalogue list was
  found [C26]; its required permission and the fields of a `publisherModel` were not on the page.
- **The JSON Schema keywords `responseJsonSchema` and `parametersJsonSchema` accept on B** [C5][C8].
- **Whether `functionCall.id` is returned on B's `v1`**: the guide says ids are "supported in the `v1beta1` API for
  Gemini 3 and later models" [C11][C12]; `v1` is not stated either way.
- **The status of B's OpenAI-compatible endpoint** (GA or preview) and whether it supports `parallel_tool_calls` and
  `stream_options` [C22]. The sentence "Only Google Cloud Auth" quoted in the earlier overview was not found on the
  pages read [C22][C23].
- **A switch to turn parallel tool calls off**: not found on [G4][C11][C12].
- **A maximum number of function declarations on A**: not stated [G4].
- **Tail of long pages not reached**: A's `generateContent` method page beyond its data models read in two parts
  [G2]; the last part of A's streaming reference showed only schema types [G3]; B's REST index beyond the first part
  (so `v1beta1` resources and the OpenAI-compatible resource were not seen there) [C3]; the last part of A's
  migration page [G21].
- **The availability marks per model and region on B's locations page** did not come through; the per-model reading
  above rests on the data-residency page only [C15][C16].
- **Abuse-monitoring retention on A**: 55 days [G18] against "a limited period of time" [G16]; both recorded.

## Sources

All opened on 2026-11-27.

Gemini API (way A):

- [G1] https://aistudio.google.com/api-reference/generate-content
- [G2] https://aistudio.google.com/api-reference/generate-content/generate-content.md
- [G3] https://aistudio.google.com/api-reference/generate-content/stream-generate-content.md
- [G4] https://aistudio.google.com/docs/function-calling.md?generate-content=true
- [G5] https://aistudio.google.com/docs/structured-output.md?generate-content=true
- [G6] https://aistudio.google.com/docs/text-generation.md?generate-content=true
- [G7] https://aistudio.google.com/docs/thought-signatures.md?generate-content=true
- [G8] https://aistudio.google.com/docs/api-errors.md?generate-content=true
- [G9] https://aistudio.google.com/docs/troubleshooting.md
- [G10] https://aistudio.google.com/docs/rate-limits.md
- [G11] https://aistudio.google.com/docs/api-key.md?generate-content=true
- [G12] https://aistudio.google.com/api-reference/models/list-models.md
- [G13] https://aistudio.google.com/api-reference/api-versions.md
- [G14] https://aistudio.google.com/api-reference/all-methods.md
- [G15] https://aistudio.google.com/docs/openai
- [G16] https://aistudio.google.com/docs/terms.md (terms effective 2026-03-23)
- [G17] https://aistudio.google.com/docs/logs-policy.md
- [G18] https://aistudio.google.com/docs/usage-policies.md
- [G19] https://aistudio.google.com/docs/zdr
- [G20] https://aistudio.google.com/docs/available-regions.md
- [G21] https://aistudio.google.com/docs/migrate-to-interactions.md
- [G22] https://aistudio.google.com/docs/migrate-to-cloud.md
- [G23] https://aistudio.google.com/docs/function-calling and https://aistudio.google.com/docs/structured-output
  (the Interactions variants)

Google Cloud (way B):

- [C1] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/reference/rest/v1/projects.locations.publishers.models/generateContent
- [C2] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/reference/rest/v1/projects.locations.publishers.models/streamGenerateContent
- [C3] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/reference/rest
- [C4] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/reference/rest/v1/GenerateContentResponse
- [C5] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/reference/rest/v1/GenerationConfig
- [C6] https://docs.cloud.google.com/gemini-enterprise-agent-platform/reference/rest/Shared.Types/Content
- [C7] https://docs.cloud.google.com/gemini-enterprise-agent-platform/reference/rest/Shared.Types/FunctionCallingConfig
- [C8] https://docs.cloud.google.com/gemini-enterprise-agent-platform/reference/rest/Shared.Types/FunctionDeclaration
- [C9] https://docs.cloud.google.com/gemini-enterprise-agent-platform/reference/rest/Shared.Types/Schema
- [C10] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/model-reference/inference
- [C11] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/multimodal/function-calling
- [C12] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/model-reference/function-calling
- [C13] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/multimodal/control-generated-output
- [C14] https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/thinking/thought-signatures
- [C15] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/learn/locations
- [C16] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/learn/data-residency
- [C17] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/data-governance
- [C18] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/learn/abuse-monitoring
- [C19] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/model-reference/api-errors
- [C20] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/provisioned-throughput/error-code-429
- [C21] https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/retry-strategy
- [C22] https://docs.cloud.google.com/vertex-ai/generative-ai/docs/migrate/openai/overview
- [C23] https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/migrate/openai/auth-and-credentials
- [C24] https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/start/api-keys
- [C25] https://docs.cloud.google.com/gemini-enterprise-agent-platform/reference/express-mode/rest/v1/publishers.models/streamGenerateContent
- [C26] https://docs.cloud.google.com/gemini-enterprise-agent-platform/reference/rest/v1beta1/publishers.models/list
- [C27] https://docs.cloud.google.com/docs/authentication/rest
- [C28] https://docs.cloud.google.com/apis/docs/system-parameters
- [C29] https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/text-to-speech/overview (the only
  `streamGenerateContent?alt=sse` request with `data:` handling found for way B)

Tried and not usable: `.../reference/rest/v1/Content`, `.../v1/ToolConfig` and `.../v1/Tool` under the `vertex-ai`
address (a different type, or 404); the express-mode quickstart and the Cloud API-keys page (no REST example).
