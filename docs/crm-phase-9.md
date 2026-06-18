# CRM Phase 9

## Goal

Introduce a unified outbound calling system that supports two call modes while preserving a single CRM workflow.

Call modes:

* Browser Call (CSR uses browser softphone)
* Bridge Call (Telnyx calls CSR phone, then bridges client)

Both modes must behave identically from the CRM perspective.

The PBX server is the control plane.

The browser must never directly communicate with Telnyx.

---

## Terminology Clarification

In Phase 9, avoid the ambiguous phrase "PBX server" unless it is clearly defined.

Use these terms:

- **Symfony PBX/CRM app**: the application server owned by this project.
- **Telnyx**: the external voice, WebRTC, PSTN, recording, transcription, and Call Control provider.
- **Browser softphone**: the CRM JavaScript UI using Telnyx WebRTC SDK.
- **Browser Call**: an outbound call placed from the browser through Telnyx WebRTC.
- **Bridge Call**: the existing two-leg flow where Telnyx calls the CSR phone first, then bridges the client.

The Symfony PBX/CRM app is the control plane.

Telnyx is the media/signaling/provider plane.

For Browser Call, the browser may connect directly to Telnyx WebRTC, but only with short-lived credentials issued by the Symfony PBX/CRM app.

---

## Why This Phase Exists

Bridge Call is reliable but consumes two PSTN legs.

Browser Call should:

* reduce PSTN costs
* improve audio quality
* simplify headset usage
* keep the CSR inside the CRM

The business workflow should remain identical regardless of transport.

---

## High Level Architecture

Browser Call:

CSR Browser
↓
PBX Server
↓
Telnyx
↓
Client PSTN

Bridge Call:

CSR Phone
↓
Telnyx
↓
PBX Server
↓
Telnyx
↓
Client PSTN

The PBX server owns:

* authentication
* call state
* call permissions
* recording
* transcription
* consent playback
* audit logs
* summaries
* CRM updates

The browser never directly talks to Telnyx.

---

## Unified Outbound Call Flow

CSR opens customer record.

CSR chooses:

* Browser Call
* Bridge Call

### Browser Call

CSR clicks Browser Call.

PBX server:

* creates call session
* allocates browser softphone session
* instructs Telnyx to dial customer

### Bridge Call

CSR clicks Bridge Call.

PBX server:

* creates call session
* instructs Telnyx to call CSR phone
* once answered
* play "Connecting your call..."
* dial customer
* bridge both legs

---

## Unified Conversation Flow

Customer answers.

CSR introduces themselves naturally.

Example:

"Hi John, this is Lloyd from FirstFire."

No recording or transcription begins automatically.

CSR decides when to begin capture.

CSR clicks:

Start Recording & Transcription

PBX server:

1. Play consent message

"This call will be recorded for transcription and quality purposes."

2. Start recording

3. Start transcription

Call continues.

CSR may stop recording/transcription at any time.

Call eventually ends.

PBX server:

* closes call session
* downloads recording if necessary
* stores transcript
* queues LLM summary job
* updates CRM

---

## Dependencies

* Existing Telnyx integration
* Existing transcription pipeline
* Existing CRM timeline
* Existing property/contact linking
* Existing tenant RBAC
* Existing LLM summary infrastructure

---

## Non-Negotiable Rules

* Browser Call and Bridge Call must share one CRM workflow.
* Browser never directly communicates with Telnyx.
* PBX server owns all telephony orchestration.
* Recording/transcription is manually started by CSR.
* Consent playback is mandatory before recording begins.
* Browser Call and Bridge Call share recording controls.
* Browser Call and Bridge Call share transcript storage.
* Browser Call and Bridge Call share LLM summarization.
* Preserve tenant isolation.
* Preserve Phase 1-8 behavior.

---

## 9A: Unified Call Domain Model (done)

### Goal

Create one outbound call model used by both Browser Call and Bridge Call.

### Scope

Add call mode:

* browser_call
* bridge_call

Track:

* tenant
* CSR user
* property
* contact
* client phone number
* call mode
* call state
* recording state
* transcription state

---

## 9B: Shared Capture Control Service (done)
### Goal

Create one reusable recording/transcription service.

### Scope

Support:

* play consent message
* start recording
* stop recording
* start transcription
* stop transcription

Track states:

* inactive
* consent_playing
* active
* stopping
* stopped
* failed

---

## 9C: Bridge Call Refactor (done)

### Goal

Refactor existing click-to-call.

Rename:

Click To Call

to

Bridge Call

Preserve behavior.

Move to unified call lifecycle.

---

## 9D: Browser Softphone Session (done)

### Goal

Add browser softphone support.

PBX server allocates browser session.

Browser receives only PBX session information.

Browser never communicates directly with Telnyx.

---

## 9E: Browser Call UI (done)

### Goal

Add browser softphone panel.

Features:

* call button
* mute
* hangup
* keypad
* recording button
* recording state
* call timer

Recording button disabled until connected.

---

## 9F: Unified Call Event Engine (done)

### Goal

Normalize all call events.

Support:

* initiated
* ringing
* answered
* failed
* completed
* CSR hangup
* customer hangup

Events update:

* CRM
* timeline
* audit logs

---

## Terminology Clarification

In Phase 9, avoid the ambiguous phrase "PBX server" unless it is clearly defined.

Use these terms:

* **Symfony PBX/CRM app**: the application server owned by this project.
* **Telnyx**: the external voice, WebRTC, PSTN, recording, transcription, and Call Control provider.
* **Browser softphone**: the CRM JavaScript UI using Telnyx WebRTC SDK.
* **Browser Call**: an outbound call placed from the browser through Telnyx WebRTC.
* **Bridge Call**: the existing two-leg flow where Telnyx calls the CSR phone first, then bridges the client.

The Symfony PBX/CRM app is the control plane.

Telnyx is the media/signaling/provider plane.

For Browser Call, the browser may connect directly to Telnyx WebRTC, but only with short-lived credentials issued by the Symfony PBX/CRM app.

## 9G: Browser Call Token Broker

### Goal

Allow authenticated CSRs to prepare a Browser Call by requesting a short-lived Telnyx WebRTC token from the Symfony PBX/CRM app.

The browser must never receive Telnyx API keys or unrestricted long-lived credentials.

### Scope

Add a Symfony endpoint to prepare Browser Call.

The Symfony PBX/CRM app must:

* authenticate the CSR
* resolve active tenant
* verify the CSR has Browser Call permission
* validate property/contact ownership
* validate the destination phone number
* normalize destination number to E.164
* create or update the CRM call session
* create a browser-call intent
* request/generate a short-lived Telnyx WebRTC token
* return minimal browser configuration
* audit token issuance

Return to browser:

* CRM call session ID
* short-lived Telnyx WebRTC token
* approved destination number
* token expiry timestamp
* status stream URL
* call mode `browser_call`

Do not return:

* Telnyx API key
* long-lived Telnyx credentials
* unrestricted SIP password
* unrelated tenant data
* unrestricted outbound dialing configuration

### Out of Scope

* Real softphone UI
* getUserMedia
* DTMF
* recording/transcription changes
* LLM summary changes

### Acceptance Criteria

* Authorized CSR can prepare Browser Call.
* Unauthorized user is denied.
* Cross-tenant contact/property access is denied.
* Invalid destination number is denied.
* Token issue is audit logged.
* Token metadata links to CRM call session.
* Token issuance is rate-limited or has a clear rate-limit hook.
* Completion-state file is updated.

### Suggested Codex Goal

/goal Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md

Implement Sub-Phase 9G only: Browser Call Token Broker.

Rules:

* 9A through 9F are already completed. Do not refactor them unless required by 9G.
* Use the term Symfony PBX/CRM app for this application server.
* Use Telnyx only for the external provider.
* Do not expose Telnyx API keys to the browser.
* Do not create a softphone UI yet.
* Do not start 9H.
* Preserve Bridge Call behavior.
* Preserve tenant isolation.
* Update docs/crm-phase-9-completion-state.md.
* Run relevant tests.
* Stop after 9G is complete.

## 9H: Browser Microphone and SDK Connection

### Goal

Turn the Browser Call panel into a real browser softphone session by requesting microphone access and connecting the Telnyx WebRTC SDK with the short-lived token from 9G.

### Scope

Browser softphone must:

* request microphone permission using browser media APIs
* handle microphone permission denied
* initialize Telnyx WebRTC SDK
* authenticate SDK using the short-lived token from Symfony
* connect/disconnect cleanly
* display connection state
* report client-side SDK errors to Symfony for audit/debugging

Symfony PBX/CRM app must:

* persist browser connection attempt state
* receive client-side error reports
* reconcile local UI state with Telnyx webhook state where available

### Out of Scope

* Placing the outbound call
* DTMF
* recording/transcription changes
* advanced device selector
* inbound calls

### Acceptance Criteria

* Browser asks for microphone permission.
* Permission denied is shown cleanly.
* Telnyx SDK authenticates using short-lived token.
* SDK authentication failure is visible and logged.
* Browser connection state is reflected in the UI.
* No Telnyx API key is exposed.
* Completion-state file is updated.

### Suggested Codex Goal

/goal Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md

Implement Sub-Phase 9H only: Browser Microphone and SDK Connection.

Rules:

* 9A through 9G are already completed.
* Do not place outbound calls yet.
* Do not implement DTMF yet.
* Do not alter Bridge Call behavior.
* Use the existing Browser Call panel if present.
* Add real microphone permission handling.
* Add Telnyx WebRTC SDK connection using server-issued token.
* Do not expose Telnyx API keys.
* Do not start 9I.
* Update docs/crm-phase-9-completion-state.md.
* Run relevant tests.
* Stop after 9H is complete.

## 9I: Browser Outbound Dial

### Goal

Allow the authenticated browser softphone session to place an outbound Browser Call to the approved destination number.

### Scope

Browser softphone must:

* place outbound call through Telnyx WebRTC SDK
* use only the approved destination from the Symfony PBX/CRM app
* show call states:

  * connecting
  * dialing
  * ringing
  * connected
  * ended
  * failed
* send useful client-side call events to Symfony
* prevent changing destination number after token issuance

Symfony PBX/CRM app must:

* bind Telnyx/WebRTC call identifiers to the CRM call session
* reject stale call intents
* reject mismatched destination attempts
* update timeline/audit where appropriate
* reconcile Telnyx webhook events with browser-reported events

### Out of Scope

* DTMF
* mute/hangup hardening
* recording/transcription changes
* live transcript UI

### Acceptance Criteria

* CSR can place a real Browser Call from the browser.
* Browser Call produces real audio between CSR and client.
* Destination cannot be modified after approval.
* CRM call session updates with Telnyx/WebRTC identifiers.
* UI moves through real call states.
* Failed calls are persisted and visible.
* Completion-state file is updated.

### Suggested Codex Goal

/goal Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md

Implement Sub-Phase 9I only: Browser Outbound Dial.

Rules:

* 9A through 9H are already completed.
* Use the Telnyx WebRTC SDK session from 9H.
* Only call the server-approved destination.
* Do not allow arbitrary dialed numbers from the browser.
* Do not implement DTMF yet.
* Do not start 9J.
* Preserve Bridge Call behavior.
* Update docs/crm-phase-9-completion-state.md.
* Run relevant tests.
* Stop after 9I is complete.

## 9J: Real Softphone Controls

### Goal

Make Browser Call controls affect the actual call, not only local UI state.

### Scope

Implement real controls:

* mute
* unmute
* hangup
* keypad / DTMF
* call timer
* connected/ended/failed state transitions

Browser softphone must:

* mute/unmute actual local audio
* hang up the actual Telnyx WebRTC call
* send real DTMF digits
* disable keypad unless call is active
* disable recording/transcription until connected

Symfony PBX/CRM app must:

* persist hangup source where possible
* audit CSR hangup actions
* update CRM call session after hangup webhooks
* close status stream when call ends

### Out of Scope

* call transfer
* hold
* conference calls
* supervisor features
* live transcription UI

### Acceptance Criteria

* Mute affects real audio.
* Hangup ends the actual call.
* DTMF sends real digits.
* Capture button remains disabled until connected.
* UI does not claim connected unless SDK/webhook state supports it.
* Call end closes softphone UI state.
* Completion-state file is updated.

### Suggested Codex Goal

/goal Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md

Implement Sub-Phase 9J only: Real Softphone Controls.

Rules:

* 9A through 9I are already completed.
* Do not add transfer, hold, conference, or supervisor features.
* Make mute/hangup/keypad affect the real Telnyx WebRTC call.
* Preserve Bridge Call behavior.
* Do not start 9K.
* Update docs/crm-phase-9-completion-state.md.
* Run relevant tests.
* Stop after 9J is complete.

## 9K: Browser Call Webhook Reconciliation

### Goal

Make Browser Call state authoritative by reconciling browser SDK events, Symfony state, and Telnyx webhooks.

### Scope

Normalize events:

* token_issued
* sdk_connecting
* sdk_ready
* dial_requested
* dialing
* ringing
* answered
* connected
* failed
* completed
* csr_hangup
* client_hangup
* abandoned
* timed_out

Symfony PBX/CRM app must:

* deduplicate events
* reject stale events
* reconcile browser-reported events with Telnyx webhooks
* update call session
* update call legs where present
* update timeline
* update audit logs
* publish status to UI stream

### Out of Scope

* LLM summary
* dashboard analytics
* live transcription UI

### Acceptance Criteria

* Duplicate events do not create duplicate timeline items.
* Stale events are ignored or logged.
* Browser-reported state does not override stronger Telnyx webhook state incorrectly.
* Call termination reliably closes UI state.
* Tests cover answered, failed, completed, duplicate, and stale event cases.
* Completion-state file is updated.

### Suggested Codex Goal

/goal Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md

Implement Sub-Phase 9K only: Browser Call Webhook Reconciliation.

Rules:

* 9A through 9J are already completed.
* Do not change the softphone UX unless needed for event state.
* Do not alter Bridge Call behavior except to reuse shared event projection if safe.
* Prefer idempotent event handling.
* Do not start 9L.
* Update docs/crm-phase-9-completion-state.md.
* Run relevant tests.
* Stop after 9K is complete.

## 9L: Unified Capture for Browser Call

### Goal

Make recording/transcription start and stop work for Browser Call using the existing shared capture service.

### Scope

Use the existing shared capture service from 9B.

For Browser Call:

* play consent message
* start recording
* start transcription
* stop recording
* stop transcription
* persist capture state
* persist capture failures
* update UI capture state
* update timeline/audit

### Out of Scope

* Redesigning capture service
* LLM summary prompt design
* live transcript UI

### Acceptance Criteria

* Browser Call capture uses the same service as Bridge Call.
* Consent playback happens before capture starts.
* Recording starts on the correct Telnyx call/control ID.
* Transcription starts on the correct Telnyx call/control ID.
* Stop capture works.
* Capture failures are logged.
* Completion-state file is updated.

### Suggested Codex Goal

/goal Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md

Implement Sub-Phase 9L only: Unified Capture for Browser Call.

Rules:

* 9A through 9K are already completed.
* Reuse the existing shared capture service from 9B.
* Do not redesign Bridge Call capture.
* Consent playback must happen before recording/transcription starts.
* Do not start 9M.
* Update docs/crm-phase-9-completion-state.md.
* Run relevant tests.
* Stop after 9L is complete.

## 9M: Transcript, Recording, and LLM Summary Pipeline

### Goal

Process Browser Call and Bridge Call identically after capture is started.

### Scope

Store for both modes:

* recording metadata
* recording download/storage reference
* transcript metadata
* transcript text
* summary JSON
* summary failure logs

When transcript is complete:

* queue LLM summary job
* use designated LLM provider
* require valid JSON
* store valid JSON
* log malformed JSON
* attach summary to:

  * call session
  * property
  * contact
  * timeline

### Out of Scope

* Final LLM prompt tuning
* AI auto-updating CRM records without review
* live transcript UI

### Acceptance Criteria

* Browser Call and Bridge Call use the same post-call pipeline.
* Valid JSON summaries are stored.
* Invalid JSON does not break call record.
* Summary links back to CRM records.
* Summary failures are visible in logs/completion state.
* Completion-state file is updated.

### Suggested Codex Goal

/goal Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md

Implement Sub-Phase 9M only: Transcript, Recording, and LLM Summary Pipeline.

Rules:

* 9A through 9L are already completed.
* Reuse existing transcript/recording/summary infrastructure.
* Do not design final prompt text.
* LLM must return valid JSON only.
* Do not allow AI to mutate CRM records automatically.
* Do not start 9N.
* Update docs/crm-phase-9-completion-state.md.
* Run relevant tests.
* Stop after 9M is complete.

## 9N: Browser Call Abuse Hardening

### Goal

Ensure Browser Call cannot become an open dialer or credential abuse path.

### Scope

Add hardening for:

* unauthorized token request
* cross-tenant token request
* stale call intent
* expired token/session
* invalid destination
* destination not linked to tenant record
* repeated token requests
* multiple active Browser Calls by same CSR
* failed microphone permission
* SDK connection failure
* abandoned browser session
* mismatched Telnyx webhook/call IDs

Add operational logs for:

* token issued
* token denied
* call intent expired
* dial attempt denied
* call failed before connection
* browser disconnected
* Telnyx webhook mismatch

### Out of Scope

* fraud scoring engine
* automatic tenant suspension
* complex anomaly detection

### Acceptance Criteria

* Browser Call cannot be used as an unrestricted dialer.
* Token issuance is rate-limited or has a concrete rate-limit hook.
* Destination validation is enforced.
* Stale sessions are rejected or expired.
* Multiple active Browser Calls are prevented or policy-controlled.
* Failure states are visible and logged.
* Completion-state file is updated.

### Suggested Codex Goal

/goal Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md

Implement Sub-Phase 9N only: Browser Call Abuse Hardening.

Rules:

* 9A through 9M are already completed.
* Do not add fraud scoring.
* Do not add automatic tenant suspension.
* Focus on deterministic hardening, validation, rate-limit hooks, and tests.
* Do not start 9O.
* Update docs/crm-phase-9-completion-state.md.
* Run relevant tests.
* Stop after 9N is complete.

## 9O: Compliance and UX Hardening

### Goal

Make capture behavior explicit, safe, and auditable across Browser Call and Bridge Call.

### Scope

Add or verify:

* hold-to-record or explicit confirmation
* clear capture states
* consent playback state
* recording indicator
* transcription indicator
* capture failure messages
* tenant policy hooks

Audit:

* capture requested
* consent playback requested
* consent played
* recording started
* transcription started
* recording stopped
* transcription stopped
* capture failed

### Out of Scope

* Legal advice generation
* automatic capture by tenant policy
* supervisor monitoring
* live transcript UI

### Acceptance Criteria

* CSR cannot accidentally start capture with one stray click.
* Capture state is obvious.
* Capture actions are audit logged.
* Browser Call and Bridge Call use the same UX pattern.
* Tenant policy hooks exist for future defaults.
* Completion-state file is updated.

### Suggested Codex Goal

/goal Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md

Implement Sub-Phase 9O only: Compliance and UX Hardening.

Rules:

* 9A through 9N are already completed.
* Do not implement automatic capture.
* Do not add supervisor features.
* Keep UX simple and explicit.
* Update docs/crm-phase-9-completion-state.md.
* Run relevant tests.
* Stop after 9O is complete.

## Updated Implementation Order

Since 9A through 9F are already completed, continue here:

1. 9G Browser Call Token Broker
2. 9H Browser Microphone and SDK Connection
3. 9I Browser Outbound Dial
4. 9J Real Softphone Controls
5. 9K Browser Call Webhook Reconciliation
6. 9L Unified Capture for Browser Call
7. 9M Transcript, Recording, and LLM Summary Pipeline
8. 9N Browser Call Abuse Hardening
9. 9O Compliance and UX Hardening

## Updated Phase 9 Done Criteria

Phase 9 is complete when:

* Browser Call works as a real Telnyx WebRTC browser softphone.
* Bridge Call works as the existing two-leg phone bridge.
* Both modes share the same CRM lifecycle.
* Browser Call uses direct Telnyx WebRTC with short-lived Symfony-issued credentials.
* Browser never receives Telnyx API keys.
* Browser cannot call arbitrary destinations without Symfony PBX/CRM app approval.
* Recording/transcription can be manually started.
* Consent playback occurs before capture starts.
* Mute, hangup, and keypad affect the real call.
* Recordings are stored.
* Transcripts are stored.
* LLM summaries are stored as valid JSON.
* Invalid LLM JSON is logged safely.
* Timeline is updated.
* Audit logs are written.
* Tenant isolation is preserved.
* Token issuance and Browser Call attempts are abuse-hardened.
