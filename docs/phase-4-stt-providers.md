# Phase 4 STT Providers

Phase 4 now routes transcription jobs through a provider abstraction instead of assuming one STT backend.

Supported provider names:

- `telnyx`
- `local_worker`
- `openai_whisper`
- `aws_transcribe`

## Provider Abstraction

Symfony creates a `TranscriptionJob` for each imported recording and sets `provider` from `STT_PROVIDER`.

- `telnyx` submits or waits for Telnyx recording transcription and completes via webhook
- `local_worker` leaves the job claimable through `/api/worker/transcription-jobs/*`
- `openai_whisper` is a placeholder
- `aws_transcribe` is a placeholder

## Why Telnyx Is Active Now

Telnyx is the easiest active provider because recording and transcription can be tied to Telnyx call control and webhook delivery. When recording starts with Telnyx transcription enabled, Telnyx can deliver `call.recording.transcription.saved` later without downloading audio into Symfony.

Local worker remains available for self-hosted faster-whisper polling over the DB-backed job flow.

## Environment

Telnyx example:

```dotenv
STT_PROVIDER=telnyx
TELNYX_TRANSCRIPTION_ENABLED=true
TELNYX_TRANSCRIPTION_MODEL=
TELNYX_TRANSCRIPTION_LANGUAGE=en
TELNYX_TRANSCRIPTION_TRACK=both
TELNYX_TRANSCRIPTION_ENGINE=telnyx
TELNYX_TRANSCRIPTION_PROFANITY_FILTER=false
TELNYX_TRANSCRIPTION_SPEAKER_DIARIZATION=false
```

Local worker example:

```dotenv
STT_PROVIDER=local_worker
LOCAL_WORKER_ENABLED=true
LOCAL_WORKER_SHARED_SECRET=replace-with-a-long-random-secret
TRANSCRIPTION_JOB_LOCK_SECONDS=900
```

OpenAI later:

```dotenv
STT_PROVIDER=openai_whisper
OPENAI_TRANSCRIPTION_ENABLED=true
```

## Provider, Model, Config

The app separates:

- `provider`: `telnyx`, `local_worker`, and later others
- `providerModel`: the configured model name if the provider supports one
- `providerConfig`: the effective configuration used for the job

For Telnyx, `providerConfig` stores:

```json
{
  "model": "",
  "language": "en",
  "track": "both",
  "engine": "telnyx",
  "profanityFilter": false,
  "speakerDiarization": false
}
```

If the exact Telnyx endpoint does not accept a configured option, Symfony omits it from the API request instead of sending an invalid field. Based on the Telnyx `record_start` docs, the request can use `transcription`, `transcription_engine`, `transcription_language`, and Google-only extras where supported. The endpoint does not document a `model` field, so the app stores that value in job metadata only for now.

## Commands

```bash
php bin/console app:transcription-jobs:create-pending --limit=50
php bin/console app:transcription-jobs:submit-pending --limit=25
php bin/console app:transcription-jobs:recent
php bin/console app:stt:providers
php bin/console app:transcripts:recent
php bin/console app:summaries:run-pending --limit=10
php bin/console app:summaries:recent
```

## Notes

- Telnyx webhooks are still persisted before provider-specific processing.
- Local-worker endpoints remain available even when `STT_PROVIDER=telnyx`.
- OpenAI Whisper and AWS Transcribe are placeholders only in this phase.
