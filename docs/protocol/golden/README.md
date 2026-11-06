# Golden files for the IPC wire format

These files pin the NDJSON wire format (FRAMEWORK 6.3 and 6.4) in a form that does not depend
on PHP. The PHP tests read them now. The Rust shim's tests will read the same files, so both
sides agree on the bytes.

| File | What it holds |
|---|---|
| `messages.ndjson` | One message of every kind, as the PHP encoder writes them: one compact JSON object per line, ASCII only. The PHP test requires the encoder to reproduce this file **byte for byte**, and the decoder to read it back. |
| `messages.json` | The same messages as a JSON array. Another implementation must decode `messages.ndjson` to values equal to these. It need not reproduce the exact bytes (key order can differ). |
| `malformed.json` | Malformed input and what a decoder must do with it. |

## `malformed.json`

A list of cases. Each case has:

- `name`: what the case shows.
- `base64`: the exact input bytes (base64, because some cases are not valid UTF-8).
- `maxLineBytes` (optional): the line size limit for the decoder. Default 8 MiB.
- `items`: what the decoder returns, in order, when it is given all the bytes and asked for items
  until it has none. An item is either a decoded object, or the string `error:<CODE>` for a
  problem with that line (`E_PROTOCOL` or `E_PAYLOAD_TOO_LARGE`).
- `atEnd`: what the decoder reports when the input ends: `null` for a clean end, or `error:<CODE>`
  if the input stopped in the middle of a message.

Rules the cases rely on:

- A bad line (not JSON, not an object, empty, invalid UTF-8) gives an `E_PROTOCOL` item and
  decoding **continues with the next line**.
- A line longer than the limit gives one `E_PAYLOAD_TOO_LARGE` item and then **nothing more**: the
  decoder has stopped, because it cannot find the next message safely. A line of exactly the
  limit is accepted. The limit does not count the newline.
- The result must not depend on how the input was split into chunks.
