# Phase 4 Local Worker

This phase moves STT away from the OpenAI API and Messenger/Valkey consumption for home processing. Symfony now persists transcription jobs in PostgreSQL, and a worker on the private network can poll for work, download recordings from S3 with a pre-signed URL, run local Whisper or faster-whisper, and push transcripts back into the app.

## Why Whisper/faster-whisper is separate from Ollama

Whisper or faster-whisper handles speech-to-text. Ollama does not replace that STT pipeline here. Ollama is used only after transcription to summarize already-transcribed call text.

## Environment

Add these values in `.env.local`:

```dotenv
LOCAL_WORKER_ENABLED=true
LOCAL_WORKER_SHARED_SECRET=replace-with-a-long-random-secret
OLLAMA_BASE_URL=http://100.115.177.42:11434
OLLAMA_SUMMARY_MODEL=llama3.1
STT_PROVIDER=local_worker
TRANSCRIPTION_JOB_LOCK_SECONDS=900
```

## Flow

1. Telnyx call handling and recording import continue unchanged.
2. When a `CallRecording` becomes `imported` and has `s3Bucket` and `s3Key`, Symfony creates a `TranscriptionJob`.
3. A home worker claims a job through the worker API.
4. The worker downloads the recording from the returned pre-signed URL and runs local STT.
5. The worker posts transcript results back to Symfony.
6. Symfony stores a `CallTranscript` and creates a pending `CallSummary`.
7. `app:summaries:run-pending` sends transcript text to Ollama and stores the summary JSON/text.

## Worker API

All worker endpoints require:

```http
X-Worker-Secret: <LOCAL_WORKER_SHARED_SECRET>
```

Endpoints:

- `POST /api/worker/transcription-jobs/claim`
- `POST /api/worker/transcription-jobs/{id}/status`
- `POST /api/worker/transcription-jobs/{id}/complete`
- `POST /api/worker/transcription-jobs/{id}/fail`

These endpoints are intended for private development use over Tailscale or another private network. They still require the shared secret. Before production, replace this with proper auth, RBAC, and service identity.

## Curl Examples

Claim a job:

```bash
curl -sS \
  -H 'Content-Type: application/json' \
  -H 'X-Worker-Secret: REPLACE_ME' \
  -X POST http://127.0.0.1:8000/api/worker/transcription-jobs/claim \
  -d '{
    "workerId": "home-gpu-1",
    "capabilities": {
      "stt": "faster-whisper",
      "models": ["large-v3", "medium"]
    }
  }'
```

Mark processing:

```bash
curl -sS \
  -H 'Content-Type: application/json' \
  -H 'X-Worker-Secret: REPLACE_ME' \
  -X POST http://127.0.0.1:8000/api/worker/transcription-jobs/123/status \
  -d '{
    "workerId": "home-gpu-1",
    "status": "processing"
  }'
```

Complete a job:

```bash
curl -sS \
  -H 'Content-Type: application/json' \
  -H 'X-Worker-Secret: REPLACE_ME' \
  -X POST http://127.0.0.1:8000/api/worker/transcription-jobs/123/complete \
  -d '{
    "workerId": "home-gpu-1",
    "provider": "faster-whisper",
    "model": "large-v3",
    "language": "en",
    "durationSeconds": 123,
    "transcriptText": "Caller says the air conditioner stopped cooling...",
    "transcriptJson": {"segments": []},
    "channelMapping": {"mode": "stereo"}
  }'
```

Fail a job:

```bash
curl -sS \
  -H 'Content-Type: application/json' \
  -H 'X-Worker-Secret: REPLACE_ME' \
  -X POST http://127.0.0.1:8000/api/worker/transcription-jobs/123/fail \
  -d '{
    "workerId": "home-gpu-1",
    "errorMessage": "faster-whisper GPU OOM"
  }'
```

## CLI

```bash
php bin/console app:transcription-jobs:create-pending --limit=50
php bin/console app:transcription-jobs:recent
php bin/console app:transcripts:recent
php bin/console app:summaries:run-pending --limit=10
php bin/console app:summaries:recent
```

## Notes

- Tailscale is the recommended connectivity path for the home worker.
- Do not expose Valkey or Redis to the home worker.
- This phase does not add SQS, SIP/PBX/WebRTC, or CRM note insertion.
- Ollama is used for summaries only.
