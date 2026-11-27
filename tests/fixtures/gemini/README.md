# Fixtures of the generate-content format

The responses of a server that speaks the generate-content format, for `Adapter\Gemini\GenerateContentAdapter`.
`tests/Support/GenerateContentFixtures.php` hands them to the shared test set.

Every file was written by hand from the shapes recorded in `docs/providers/gemini.md`. Nothing was captured from an
account: ids, the model name, signatures, times and text are made up. `{{echo}}` in an error body is replaced by the
text the test set asks to repeat, `{{code}}` by the status and `{{status}}` by the status word the provider file
lists beside that status (`UNKNOWN` for a status it does not list).

There are two sets, one per way in the provider file describes. The adapter is the same; the shapes differ where
the file says they do.

| | `api-key/` | `cloud-token/` |
|---|---|---|
| Contract test | `GenerateContentContractTest` | `GenerateContentTokenContractTest` |
| Sign-in | a key in the header `x-goog-api-key` | a bearer token from a supplier of the host |
| Options of the preset | row 4 of the preset entries: `store_field: false` | rows 5 and 6: `model_listing: none`, `tools_with_structured_output: emulated` |
| Finish reason | the bare word (`STOP`) | with the prefix (`FINISH_REASON_STOP`), as one page of that way in lists it |
| Tool call | with an `id` | without an `id` (the file: not stated whether that way in returns one on `v1`) |
| Beside the usual fields | | `createTime`, and `trafficType` in the usage (its value here is made up) |

- `<case>.json`: the whole response for one case of `Testing\ContractFixture`: `candidates` with one candidate
  (`index`, `content` with the role `model` and a list of `parts`, `finishReason`), `usageMetadata`, `modelVersion`
  and `responseId`.
- `<case>.sse`: the same answer as a stream. Every event is one `data:` line with a whole response that holds the
  new parts only; there is no last event and no `[DONE]` line. The fixtures class hands every event over as a chunk
  of its own.
- Usage: every answer reports 3 input and 2 output tokens. `structured_valid.*` and `tool_call_with_opaque_data.*`
  report the output as 1 token of the candidates and 1 of the thoughts, which the adapter adds up. Every chunk of a
  stream carries `usageMetadata`, with a count that rises; the adapter takes the last value of each count.
- `tool_call.*`, `parallel_tool_calls.*`: `functionCall` parts whose `args` is an object, and the finish reason
  `STOP`: the format has no finish reason of its own for a tool call. The adapter keeps the parts of these answers
  in an opaque part too (decision G118), which the two contract classes tell the shared set with
  `keepsDataWithEveryToolCall()`. In `api-key/parallel_tool_calls.sse` the two
  calls arrive in two chunks, in `cloud-token/` in one.
- `garbled_tool_call.*`: a call whose `args` is a string that holds the cut-off text `{"city": "Par`. **No page
  documents that shape**: the provider file says `args` is "a JSON object, never a string", and the documented sign
  of a call the model garbled is the finish reason `MALFORMED_FUNCTION_CALL`, which the design throws as an error.
  The shared test set asks every format that calls tools for a call with unreadable arguments, the adapter reads
  an `args` that is no object defensively, and these files show that reading (decision G114).
- `tool_call_with_opaque_data.*`: the tool call of `tool_call.*` with a `thoughtSignature` on its part, the data
  this format wants back unchanged. In `api-key/` the signature itself holds the words `opaque-tracer` (the file
  calls a signature an opaque string). In `cloud-token/` the signature is beside a part with `thought: true` whose
  text holds the words; in the stream that thought arrives in two chunks, which the adapter joins to the one part
  of the whole answer. **Not confirmed**: that a list joined this way is taken back (decision G115).
- `withheld.*`: a candidate with the finish reason `SAFETY` and no `content`. The provider file says so of a
  stream on the second way in only ("`content` is empty"). **Not confirmed** for the other three files: a whole
  answer on either way in (`api-key/withheld.json`, `cloud-token/withheld.json`) and a stream on the first
  (`api-key/withheld.sse`); the file does not say what a withheld answer holds there. The adapter reads a candidate
  without content, with an empty content and with an empty text the same way, and its own tests cover each.
- No `refused.*`: the provider file lists no finish reason for an answer the model declined to give.
- `empty_answer.*`: a content with an empty list of parts and the finish reason `STOP`.
- `failed.json`: the error body of a failed request: `code`, `message`, `status` and one detail with a `reason`.
  The provider file shows it for the first way in and says the second uses the same error model without showing a
  body; both sets use this one file.
- `error_in_body.json`, `error_in_stream.sse`: the same body under a status 200, and as an event of a stream.
  **No page documents either**: the file says errors after a stream has started are not described. The adapter
  reads an `error` object defensively, only where there is no candidate beside it (decision G114). The documented
  errors inside a successful response are a blocked prompt and a finish reason that names a fault; the adapter's
  own tests cover both, because the shared set fixes the reason and the code of its case.
- There is no response that forbids a retry: the provider file documents no header and no field for it.
