# Fixtures of the "talk" format

"Talk" is a made-up format: no provider speaks it. Its adapter and its preset live under `tests/Support`, outside the
library, the way a host project would write its own; the shared test set runs on them with these files.

Every file was written by hand from the description of the format in `tests/Support/TalkAdapter.php`. Ids, model
names and text are made up. `{{echo}}` in `failed.json` is replaced by the text the test set asks to repeat.

- `<case>.json`: the whole response for one case of `Testing\ContractFixture`.
- `<case>.sse`: the same answer as a stream; one event per chunk.
- `failed.json`: the error body of a failed request; `error_in_body.json` and `error_in_stream.sse`: an error inside
  a response with the status 200.
