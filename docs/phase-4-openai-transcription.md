# Phase 4 OpenAI Transcription

Required env vars:

```dotenv
OPENAI_API_KEY=...
OPENAI_TRANSCRIPTION_MODEL=gpt-4o-mini-transcribe
OPENAI_TRANSCRIPTION_ENABLED=true
MESSENGER_TRANSPORT_DSN=redis://127.0.0.1:6379/transcription
```

Valkey check:

```bash
systemctl status valkey
valkey-cli ping
redis-cli ping
```

Messenger test:

```bash
php bin/console messenger:consume transcription -vv
php bin/console app:messenger:ping-transcription
```

Manual transcription test:

```bash
php bin/console app:transcriptions:start-pending
php bin/console app:transcriptions:recent
```

Worker command:

```bash
php bin/console messenger:consume transcription -vv
```

Notes:

- Call flow is `CallRecording` in S3 -> Messenger job -> OpenAI transcription -> `CallTranscript`.
- The instance orchestrates storage and API calls; the t4g.micro does not perform speech-to-text compute locally.
- Temporary audio files are created under `sys_get_temp_dir()` during worker processing and removed in a `finally` block.
