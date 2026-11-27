# Fixtures of the Messages format

The responses of a server that speaks the Messages format, for `Adapter\Anthropic\MessagesAdapter`.
`tests/Support/MessagesFixtures.php` hands them to the shared test set.

Every file was written by hand from the shapes recorded in `docs/providers/anthropic.md`. Nothing was captured from
an account: ids, the model name, signatures and text are made up. `{{echo}}` in an error body is replaced by the text
the test set asks to repeat.

There is one set. Two contract test classes run on it (`tests/Contract/Messages…ContractTest.php`): a preset with the
least it must set, and a preset with the options of row 3 of the preset entries (`docs/design/adapters.md`, section
9). Those options change no shape of a response, so both read the same files.

- `<case>.json`: the whole response for one case of `Testing\ContractFixture`: `id`, `type`, `role`, `model`, a
  `content` list of blocks, `stop_reason`, `stop_sequence`, `stop_details` and `usage`.
- `<case>.sse`: the same answer as a stream of named events, in the documented order: `message_start`, per content
  block `content_block_start`, its `content_block_delta` events and `content_block_stop`, then `message_delta` with
  the stop reason and `message_stop`. There is no `[DONE]` line. The fixtures class cuts a stream into chunks after
  every `content_block_delta`, so the events before the first piece of content arrive with it, as a server sends
  them.
- Usage: every answer reports 3 input and 2 output tokens. `structured_valid.*` reports the input as 1 token after
  the cache point, 1 written to the cache and 1 read from it, which the adapter adds up, and
  `output_tokens_details.thinking_tokens`, which it reads past. A stream reports its usage
  twice, in `message_start` (with a first output count of 1) and as totals in `message_delta`; in
  `plain_answer.sse` the totals hold the other counts as `null`.
- `plain_answer.sse` holds a `ping` event, which the adapter reads past.
- `tool_call.*`, `parallel_tool_calls.*`: `tool_use` blocks whose `input` is an object. In a stream the block starts
  with `"input": {}` and the arguments arrive as `input_json_delta` pieces of one JSON text; the first piece of
  `tool_call.sse` is an empty string.
- `garbled_tool_call.sse`: the pieces of the input, joined, are the cut-off text `{"city": "Par`. This is the
  documented shape of a stream. `garbled_tool_call.json` holds the same text as an `input` that is a string. **No
  page documents that shape**: the provider file says `input` is always an object. The shared test set asks every
  format that calls tools for a call with unreadable arguments, the adapter reads an `input` that is no object
  defensively, and this file shows that reading (decision G107).
- `tool_call_with_opaque_data.*`: the tool call of `tool_call.*` after a `thinking` block with its `signature`, the
  data this format wants back unchanged with the answer. The thinking holds the words `opaque-tracer`. In the stream
  the thinking arrives in two `thinking_delta` pieces and the signature in a `signature_delta`; the adapter joins
  them to the block of the whole answer. **Not confirmed**: the provider file says a block starts with "the empty
  block" and shows one only for a tool call; the stream starts its thinking block with
  `{"type":"thinking","thinking":""}` (decision G109). There is no `redacted_thinking` block in this pair: the file
  does not say how one arrives in a stream. The adapter's own tests cover it.
- `refused.*`: the model declined; the stop reason is `refusal` and the answer holds one text block. In the whole
  answer `stop_details` says so too; the stream carries only the stop reason, because the file does not say where
  a stream puts `stop_details`. Whether the content of a refusal can be empty is not confirmed; the adapter's own tests cover both.
- No `withheld.*`: the provider file lists no stop reason for output the provider withheld.
- `empty_answer.*`: an empty `content` with the stop reason `end_turn`.
- `failed.json`: the error body of a failed request, with a `type`, a `message`, a code under `details` and a
  `request_id`. `error_in_stream.sse`: the documented `error` event of a stream. `error_in_body.json`: the same
  body under a status 200, which no page documents for a whole response; the adapter reads it defensively.
- A response that forbids a retry is `failed.json` with the header `x-should-retry: false`, which the fixtures class
  adds.
