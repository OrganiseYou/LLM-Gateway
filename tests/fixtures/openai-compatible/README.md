# Fixtures of the chat-completions format

The responses of servers that speak the chat-completions format, for `Adapter\OpenAiCompatible\ChatCompletionsAdapter`.
`tests/Support/ChatCompletionsFixtures.php` hands them to the shared test set.

Every file was written by hand from the shapes recorded in `docs/providers/`. Nothing was captured from an account:
ids, model names, fingerprints and text are made up. `{{echo}}` in an error body is replaced by the text the test set
asks to repeat, and `"{{status}}"` by the status of the response as a number.

- `<case>.json`: the whole response for one case of `Testing\ContractFixture`.
- `<case>.sse`: the same answer as a stream; one event per chunk.
- `failed.json`: the error body of a failed request. `error_in_body.json` and `error_in_stream.sse`: an error under a
  status 200. No provider file documents an error under a successful status for this format; the adapter reads one
  defensively, and these two files use the documented error body to show it.

There are four sets, one folder each. Each set is what one kind of server gives, and one contract test class runs on
it (`tests/Contract/ChatCompletions…ContractTest.php`).

## `default`

A server with the defaults of the format. The plain shape, with the fields the provider files record beside it and
that the adapter reads past: `refusal: null`, `logprobs`, `system_fingerprint`, `service_tier`, the details of the
usage, a padding string `obfuscation` in a chunk, and the results of a content filter. A stream reports its usage in
an extra chunk with an empty `choices`, before `data: [DONE]`. A tool call arrives with its id and name first and its
arguments in pieces.

- `refused.*`: the model declined; its words are in `message.refusal` (in a stream `delta.refusal`), and the finish
  reason is `stop`.
- `withheld.*`: the output was withheld; the finish reason is `content_filter` and there is no content. The stream
  begins as a server with an asynchronous filter begins it: a chunk with an empty id, an empty model and no choice,
  and later a chunk whose choice has no `delta`.
- No `tool_call_with_opaque_data`: a server without a reasoning carrier asks nothing back.

## `minimal`

A small server: only the fields its reference lists, no tool calls, no stream (the adapter gives the whole answer as
the events of a stream), and a flat error body with `error` as a word and `code` as the status. Its preset sets
`tool_calling: none`, `structured_output: emulated`, `streaming: emulated` and `stream_usage: unasked`. There are no
`.sse` files, no tool-call files, and no refusal or withheld output: such a server documents neither.

## `stream-without-usage`

The small server of `minimal`, but one that streams: its preset sets `tool_calling: none`,
`structured_output: emulated` and `stream_usage: unasked`, and streams natively. A whole answer tells its usage; a
stream does not, in any chunk, and ends with `data: [DONE]`. Its contract test says so with `reportsUsage()`, and the
shared test set then checks that the gateway reports no usage for a stream either.

## `reasoning`

A server that writes its reasoning beside the answer and wants it back (`reasoning_carrier: message_field`), takes no
temperature and is forced to one tool by being given only that tool. An assistant message with tool calls has an
empty string as its `content`, the usage has more fields, and a stream reports the usage twice: inside the choice of
the chunk that ends the answer and in the extra chunk after it. The error body has a `type` and no `code`.

- `tool_call_with_opaque_data.*`: the tool call of `tool_call.*` with `reasoning_content` beside it, the data this
  server wants back unchanged with the answer. It holds the words `opaque-tracer`. In the stream the reasoning
  arrives in two pieces, before the tool call.
- The other answers hold no reasoning: an answer of plain text and tool calls has no opaque part, and the shared
  test set checks that.
- No refusal and no withheld output: the server this set stands for documents no field for either.
