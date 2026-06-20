# PBX Browser Softphone POC v2

## Objective

Enhance the existing isolated `/poc/browser-softphone` implementation to prove browser-based PBX capabilities before any CRM integration.

This work must remain isolated from CRM production functionality.

## Non-Negotiable Guardrails

- Do not modify CRM customer profile pages.
- Do not modify existing inbound forwarding.
- Do not modify existing Telnyx Call Control flows.
- Do not modify existing recording logic.
- Do not modify existing transcription logic.
- Do not modify existing Mercure production topics.
- Do not modify existing browser call implementations outside `/poc/browser-softphone`.
- Do not introduce shared behavior into CRM production paths.

## Delivery Rules

- Implement in serialized phases only.
- Do not begin a later phase until the current phase is complete.
- At the end of every phase:
  - `php bin/phpunit`
  - `php bin/console lint:twig templates/`
  - `php bin/console lint:container`
  - `php bin/console doctrine:schema:validate`
  - `npx playwright test`
- If you prefer Docker for Playwright, use the `playwright` service in `compose.yaml`:
  - `docker compose --profile test run --rm playwright`
- A phase is complete only if:
  - previous phase behavior still works
  - the code compiles
  - PHPUnit passes
  - Playwright passes
- If Playwright is missing, create the minimal Playwright tests required for the phase before proceeding.
- Do not implement multiple phases at the same time.

## Recommended Architecture

- Controller
- Service
- DTO
- Twig
- Stimulus Controller
- Mercure Subscriber

Keep components small and isolated.

Recommended limits:

- Stimulus controller: 400 lines
- Service: 300 lines
- Controller: 200 lines

Extract helpers when needed.

## Phase Gate Order

### Phase 0 - Browser Call Stability Gate

This gate must pass before any transcript work is enabled.

Required proof points:

- Browser Call
- Two-way audio
- Mute
- Hangup
- DTMF
- Device selection
- Device persistence

If this gate is not stable, stop here and do not touch transcript streaming.

### Phase 1 - Mute Controls

Add:

- `[Browser Call]`
- `[Mute]`
- `[Hangup]`
- `[Dialpad]`
- `[Settings ⚙]`

Requirements:

- mute / unmute
- visual indicator
- local state only
- keyboard shortcut placeholder only
- do not call backend

UI:

- `Muted 🔴`
- `Live 🟢`

Tests:

- PHPUnit: state generation tests
- Playwright: mute button toggles, button label updates

### Phase 2 - Settings Panel

Desktop:

- collapsed by default

Mobile:

- settings opens a bottom-sheet modal

Requirements:

- responsive
- no CSS framework beyond the existing project stack

Tests:

- Playwright desktop: open settings
- Playwright mobile: bottom sheet opens

### Phase 3 - Audio Device Persistence

Implement `SoftphonePreferences`.

Single storage key:

- `pbx.softphone.preferences`

Structure:

```json
{
  "selectedMicrophone": null,
  "selectedSpeaker": null,
  "audio": {
    "echoCancellation": true,
    "noiseSuppression": true,
    "autoGainControl": false
  },
  "diagnostics": {
    "enabled": true
  }
}
```

Requirements:

- load
- save
- restore
- fallback to defaults
- use `navigator.mediaDevices.enumerateDevices()`
- listen for `devicechange`

Tests:

- Playwright: preferences persist after page reload, changing device updates storage
- PHPUnit: DTO/helper tests where practical

### Phase 4 - Mercure Transcript Stream

Goal:

- receive live transcript updates

Architecture:

- Telnyx Webhook
- Symfony
- Persist segment
- Mercure publish
- Browser subscribe
- Update UI

Telnyx webhook target:

- `https://pbx.firstfire.ca/api/telnyx/webhook`

The POC transcript stream is keyed to the active Telnyx call control ID, so the browser panel only fills once Telnyx has delivered transcription webhooks for that call.

Topic:

- `/poc/browser-softphone/{callSessionId}/transcript`

Requirements:

- deduplicate events
- do not append duplicate transcript segments
- do not modify production Mercure topics

Tests:

- PHPUnit: Mercure payload creation, topic generation
- Playwright: mocked event appends transcript

### Phase 5 - Transcript Bubble UI

Look and feel:

- Apple Messages

Requirements:

- Customer: left, gray bubble
- CSR: right, blue bubble

Data model:

```json
{
  "id": 1,
  "speaker": "customer",
  "text": "Hello",
  "occurredAt": "2026-06-18T12:00:00Z",
  "displayTime": "11:14 PM",
  "isFinal": true
}
```

Display:

- `11:14 PM`

Requirements:

- use Carbon
- auto-scroll
- maximum visible height before overflow

Tests:

- Playwright: bubble alignment, timestamps render, auto-scroll

### Phase 6 - Interim vs Final Transcript

Requirements:

- interim: lighter, italic, typing indicator
- final: normal
- update existing interim bubble
- do not create duplicates

Tests:

- Playwright: interim becomes final
- PHPUnit: transcript merge service

### Phase 7 - Post Call Summary Placeholder

After hangup, create a panel with:

- Call Summary
- Customer concerns
- Action items
- Keywords

No AI yet.

Placeholder only.

Mock data accepted.

Tests:

- Playwright: panel appears after hangup

## Diagnostics Panel

Add a collapsed card that shows:

- Codec
- Latency
- Jitter
- Packet Loss
- Microphone
- Speaker
- Noise Suppression
- Echo Cancellation
- Auto Gain Control

Populate only what is available.

If unavailable, display `N/A`.

Do not fabricate values.

## Database Policy

- Do not create migrations unless absolutely required.
- If a migration is required:
  1. Create the migration.
  2. Execute the migration.
  3. Document it in `STATUS.md`.

## Manual Testing

Update `MANUAL_TESTING.md` at completion with the required manual test list.

## Output Requirements

At completion, report:

1. Completed phases
2. Remaining phases
3. Files created
4. Files modified
5. PHPUnit results
6. Playwright results
7. Manual tests still required
8. Known limitations
