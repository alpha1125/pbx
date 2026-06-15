# Phase 4 Capture Policy

Capture is now controlled with explicit booleans instead of coupling transcription to recording:

- `recordAudio=false`, `transcribeAudio=false`: neither recording nor transcription
- `recordAudio=false`, `transcribeAudio=true`: transcription only
- `recordAudio=true`, `transcribeAudio=false`: recording only
- `recordAudio=true`, `transcribeAudio=true`: recording and transcription

`recordAudio` means the app should persist call audio.

`transcribeAudio` means the app should produce a text transcript.

## App-level design

The app keeps Telnyx actions separate:

- `startRecording()`
- `startTranscription()`

The app thinks in separate capture actions even if a specific Telnyx feature or account setting later requires some combined provider-side behavior.

## Config

Supported env vars:

- `TELNYX_RECORDING_ENABLED`
- `TELNYX_TRANSCRIPTION_ENABLED`
- `TELNYX_RECORDING_FORMAT`
- `TELNYX_RECORDING_CHANNELS`
- `TELNYX_TRANSCRIPTION_LANGUAGE`
- `TELNYX_TRANSCRIPTION_TRACK`
- `TELNYX_TRANSCRIPTION_MODEL`
- `TELNYX_TRANSCRIPTION_ENGINE`

Inspect the effective defaults with:

```bash
php bin/console app:capture-policy:debug
```

## Flows

- Inbound forwarding uses the default capture policy for `inbound_forward`.
- Click-to-call uses the default capture policy for `click_to_call`.
- Dev transcription testing can override `recordAudio` and `transcribeAudio` per request.

When both flags are true, the app starts recording first and transcription second.

## Dev transcription test

Endpoint:

`POST /api/dev/telnyx/transcription-test`

Example:

```bash
curl -X POST http://127.0.0.1:8000/api/dev/telnyx/transcription-test \
  -H "Content-Type: application/json" \
  -d '{"targetNumber":"+14168880123","targetName":"Lloyd","recordAudio":true,"transcribeAudio":true}'
```

The request dials `targetNumber` from `TELNYX_FROM_NUMBER` using `TELNYX_CONNECTION_ID`, announces the disclosure, then applies the capture policy after `call.speak.ended`.

## Telnyx transcription persistence

Webhook handling now stores Telnyx transcription results independently of recording:

- `call.transcription`
- `call.transcription.saved`
- `call.recording.transcription.saved`
- `call.transcription.error`

Best effort matching links transcription state to:

- `CallSession`
- `CallLeg`
- optional `CallRecording`
- `TranscriptionJob`
- `CallTranscript`

If Telnyx only returns a transcript URL, the app downloads the transcript and stores the text/raw payload internally instead of exposing the provider URL.
