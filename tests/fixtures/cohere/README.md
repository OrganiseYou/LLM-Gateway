# Fixtures of the Chat v2 format

The responses of a server that speaks the Chat v2 format, for `Adapter\Cohere\ChatV2Adapter`.
`tests/Support/ChatV2Fixtures.php` hands them to the shared test set, for two contract classes:
`ChatV2ContractTest` (a preset that sets no option, row 8 of the preset entries) and `ChatV2SchemaKeyContractTest`
(the other value of the one option that is not confirmed, `schema_key: schema`, with a setting of the preset filled
in that travels as a header). The option changes one name in a request and no shape of a response, so both classes
use the same files.

Every file was written by hand from the shapes recorded in `docs/providers/cohere.md` (read on 2026-11-27).
Nothing was captured from an account: ids and text are made up. `{{echo}}` in an error body is replaced by the text
the test set asks to repeat, written as JSON writes it inside a string.

- `<case>.json`: the whole response for one case of `Testing\ContractFixture`: `id`, `finish_reason` in upper case,
  `message` with the role `assistant`, its `content` as a list of `text` blocks, its `tool_calls`, its `tool_plan`
  where the case has one, and `usage` with `billed_units` and `tokens` ("The shape of a tool call in an answer",
  "Usage").
- `<case>.sse`: the same answer as a stream of named events: an `event:` line and a `data:` line whose object
  repeats the type in `type`, in the documented order: `message-start`; `content-start`, `content-delta` pieces and
  `content-end` for a text block; `tool-plan-delta` pieces; per call `tool-call-start` (one object under
  `tool_calls`, with `id`, `type`, `function.name` and empty `arguments`), `tool-call-delta` pieces of the arguments
  string and `tool-call-end`; and `message-end` with `finish_reason` and `usage` ("The stream"). There is no
  `[DONE]` line. The fixtures class cuts the stream into chunks after every event that carries a piece.
- Usage: every answer reports 3 input and 2 output tokens under `usage.tokens`; `billed_units` beside them holds the
  same numbers here and is never read. `structured_valid.*` writes the counts as `3.0` and `2.0`: the reference types
  every count as a double ("Usage").
- `tool_call.*`, `parallel_tool_calls.*`: calls whose `arguments` is a string that holds JSON, with the finish
  reason `TOOL_CALL`. The two calls of `parallel_tool_calls.*` stand in one assistant message, as the file says
  parallel calls arrive.
- `garbled_tool_call.*`: a call whose `arguments` string holds the cut-off text `{"city": "Par`. The file says the
  string "can be invalid JSON", so this is a documented shape.
- `tool_call_with_opaque_data.*`: the tool call of `tool_call.*` with a `tool_plan` beside it, the free text the
  model writes before its calls. The plan holds the words `opaque-tracer`; the adapter keeps it in the opaque part
  of the answer and sends it back with that assistant message. In the stream the plan arrives in two
  `tool-plan-delta` pieces, which the adapter joins to the plan of the whole answer; the two give the same part,
  byte for byte. **Not confirmed**: that a plan joined from its pieces is taken back as it is (decision G120).
- `structured_valid.*`, `structured_invalid.*`, `cut_off.*`: the answer of a response format as the text of one
  text block; `cut_off.*` ends with `MAX_TOKENS`.
- `empty_answer.*`: an empty `content` list with `COMPLETE`; the stream has only `message-start` and `message-end`.
- `failed.json`: the documented error body, `{"id", "message"}`. The format documents no code and no type, so the
  status alone decides the reason.
- `error_in_body.json`: the same body under a status 200. **No page documents it**: the documented error inside a
  successful response is the finish reason `ERROR` or `TIMEOUT`, which the adapter's own tests cover, because the
  shared set expects no code here and the adapter gives that word as the code. The adapter reads the error body
  under a 200 defensively, as an answer without a message (decision G119).
- `error_in_stream.sse`: `message-start`, then `message-end` whose `delta` holds `error`, the member the reference
  documents there ("an error message if an error occurred during the generation"). The documented event also
  carries `finish_reason` (`ERROR` or `TIMEOUT`) and `usage`; they are left out of this file because the shared set
  expects no code for this case, and the finish word would be the code. The adapter's own tests cover the event
  with them.
- No `refused.*` and no `withheld.*`: the provider file lists no finish reason and no field for an answer the model
  declined to give (it declines in ordinary text) or one the provider withheld.
- There is no response that forbids a retry: the provider file documents no header and no field for it, and no
  retry header at all.
