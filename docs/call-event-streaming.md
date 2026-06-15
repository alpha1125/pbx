# Call Event Streaming

The app now exposes a server-sent events endpoint for live call monitoring:

`GET /api/calls/{providerSessionId}/events/stream`

Example:

```bash
curl -N "http://127.0.0.1:8000/api/calls/<providerSessionId>/events/stream"
```

Browser example:

```js
const stream = new EventSource(
  `/api/calls/${providerSessionId}/events/stream?timeout=25&poll_ms=1000`
);

stream.addEventListener("ready", (event) => {
  console.log("stream ready", JSON.parse(event.data));
});

stream.addEventListener("call.initiated", (event) => {
  console.log("call started", JSON.parse(event.data));
});

stream.addEventListener("call.hangup", (event) => {
  console.log("call ended", JSON.parse(event.data));
});

stream.addEventListener("call.speak.ended", (event) => {
  console.log("speech finished", JSON.parse(event.data));
});

stream.addEventListener("call.recording.requested", (event) => {
  console.log("recording requested", JSON.parse(event.data));
});

stream.addEventListener("call.recording.saved", (event) => {
  console.log("recording saved", JSON.parse(event.data));
});

stream.addEventListener("call.transcript.available", (event) => {
  console.log("transcript available", JSON.parse(event.data));
});

stream.addEventListener("close", (event) => {
  const payload = JSON.parse(event.data);
  stream.close();

  // Reconnect with the returned cursors to continue tailing the stream.
  const nextUrl = new URL(`/api/calls/${providerSessionId}/events/stream`, window.location.origin);
  nextUrl.searchParams.set("cursor_telnyx", payload.cursor.telnyx);
  nextUrl.searchParams.set("cursor_recording", payload.cursor.recording);
  nextUrl.searchParams.set("cursor_transcript", payload.cursor.transcript);
});
```

## What is streamed

- `telnyx_event` rows for the root call session and any child call sessions
- `call_recording` inserts as synthetic `call.recording.{status}` events
- `call_transcript` inserts as synthetic `call.transcript.{status}` events

## Query params

- `timeout`: stream lifetime in seconds before the server closes the connection, default `25`, max `55`
- `poll_ms`: database poll interval in milliseconds, default `1000`, min `250`, max `2000`
- `cursor_telnyx`: last delivered `telnyx_event.id`
- `cursor_recording`: last delivered `call_recording.id`
- `cursor_transcript`: last delivered `call_transcript.id`

The endpoint is append-only. Reconnect using the cursors from the final `close` event.
