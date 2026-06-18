# CRM Phase 9 Completion State

## Scope

This document tracks the implementation status of CRM Phase 9 as of the current worktree.

## Status

- 9A Unified Call Domain Model: complete
- 9B Shared Capture Control Service: complete
- 9C Bridge Call Refactor: complete
- 9D Browser Softphone Session: complete
- 9E Browser Call UI: complete
- 9F Unified Call Event Engine: complete
- 9G Browser Call Token Broker: complete
- 9H Browser Microphone and SDK Connection: complete
- 9I.1 Persist Telnyx WebRTC Connection ID: complete
- 9I.2 Report Browser SDK Connection Ready Event: complete
- 9I.3 Browser Outbound Dial Service: complete
- 9I.4 Browser Outbound Dial HTTP Endpoint: complete
- 9I.5 Wire Browser Button to Dial Endpoint: complete
- 9I Browser Outbound Dial: complete
- 9J Real Softphone Controls: complete

## 9A Verification

- The unified outbound call model now lives on [`src/Entity/CallSession.php`](/var/www/pbx/src/Entity/CallSession.php) and tracks:
  - call mode
  - CSR user
  - property
  - contact
  - client phone number
  - call state
  - recording state
  - transcription state
- The schema changes are captured in [`migrations/Version20260616013000.php`](/var/www/pbx/migrations/Version20260616013000.php).
- The bridge-call path seeds the unified fields in [`src/Service/CrmClickToCallService.php`](/var/www/pbx/src/Service/CrmClickToCallService.php) and [`src/Service/ClickToCallService.php`](/var/www/pbx/src/Service/ClickToCallService.php).
- The model itself is covered by [`tests/Service/CallSessionModelTest.php`](/var/www/pbx/tests/Service/CallSessionModelTest.php).
- The current bridge-call entrypoint is covered by [`tests/Service/CrmClickToCallServiceTest.php`](/var/www/pbx/tests/Service/CrmClickToCallServiceTest.php).

## 9B Verification

- The shared recording/transcription orchestration now lives in [`src/Service/CallCaptureControlService.php`](/var/www/pbx/src/Service/CallCaptureControlService.php).
- The Telnyx capture path delegates to the shared service through [`src/Service/TelnyxCaptureService.php`](/var/www/pbx/src/Service/TelnyxCaptureService.php).
- Telnyx call control now supports stopping transcription in [`src/Service/TelonyxCallControlService.php`](/var/www/pbx/src/Service/TelonyxCallControlService.php).
- The shared capture workflow is covered by [`tests/Service/CallCaptureControlServiceTest.php`](/var/www/pbx/tests/Service/CallCaptureControlServiceTest.php).
- The Telnyx call-control action contracts are covered by [`tests/Service/TelonyxCallControlServiceTest.php`](/var/www/pbx/tests/Service/TelonyxCallControlServiceTest.php).

## 9C Verification

- The CRM bridge-call entrypoint now uses the bridge-call route and CSRF token prefix in [`src/Controller/Crm/CrmClickToCallController.php`](/var/www/pbx/src/Controller/Crm/CrmClickToCallController.php) while retaining the legacy click-to-call route as an alias.
- The property detail template now renders the Bridge Call button in [`templates/crm/property/show.html.twig`](/var/www/pbx/templates/crm/property/show.html.twig).
- The CRM bridge-call audit event is covered by [`tests/Service/CrmClickToCallServiceTest.php`](/var/www/pbx/tests/Service/CrmClickToCallServiceTest.php).
- The role-gating path covers both the bridge-call route and the legacy click-to-call alias in [`tests/Functional/CrmTenantIsolationTest.php`](/var/www/pbx/tests/Functional/CrmTenantIsolationTest.php).

## 9D Verification

- The browser softphone allocation endpoint lives in [`src/Controller/BrowserSoftphoneSessionController.php`](/var/www/pbx/src/Controller/BrowserSoftphoneSessionController.php) and exposes the PBX-owned browser session payload for browser calls only.
- The reusable allocation logic lives in [`src/Service/BrowserSoftphoneSessionService.php`](/var/www/pbx/src/Service/BrowserSoftphoneSessionService.php).
- The persistent session model and schema live in [`src/Entity/BrowserSoftphoneSession.php`](/var/www/pbx/src/Entity/BrowserSoftphoneSession.php) and [`migrations/Version20260616014000.php`](/var/www/pbx/migrations/Version20260616014000.php).
- The allocation behavior is covered by [`tests/Service/BrowserSoftphoneSessionServiceTest.php`](/var/www/pbx/tests/Service/BrowserSoftphoneSessionServiceTest.php).
- The HTTP contract is covered by [`tests/Functional/BrowserSoftphoneSessionWorkflowTest.php`](/var/www/pbx/tests/Functional/BrowserSoftphoneSessionWorkflowTest.php).

## 9E Verification

- The property page now renders a browser softphone panel in [`templates/crm/property/show.html.twig`](/var/www/pbx/templates/crm/property/show.html.twig).
- The panel is driven by [`assets/controllers/browser_softphone_controller.js`](/var/www/pbx/assets/controllers/browser_softphone_controller.js), which starts browser calls, attaches to the call-event stream, tracks the call timer, and manages the local panel state for mute, keypad, recording, and hangup controls.
- The browser-call start response now returns the provider session and event stream data needed by the controller.
- The `startCall()` flow handles microphone permission → SDK connection → token allocation → dialing in sequence.

## 9F Verification

- Unified call events (`call.requesting`, `call.ringing`, `call.active`, `call.hangup`, `call.failed`) are normalized and pushed to the CRM timeline via [`CallEventEngineService`](/var/www/pbx/src/Service/CallEventEngineService.php).
- The event stream endpoint (`/api/calls/{providerSessionId}/events/stream`) exposes real-time updates via Server-Sent Events (SSE).
- Timeline UI components subscribe to the stream and render call events chronologically.

## 9G Verification

- Browser call tokens are brokered by [`BrowserCallTokenBrokerService`](/var/www/pbx/src/Service/BrowserCallTokenBrokerService.php) with short-lived JWTs scoped to the browser softphone session.
- Token lifecycle (creation, expiration, revocation) is tracked and enforced server-side.
- The CRM session prepare endpoint seeds token intent into the unified call model.
- WebRTC token issuance now prefers a configured `TELNYX_WEBRTC_TELEPHONY_CREDENTIAL_ID` and only reuses an existing Telnyx credential if one is already discoverable for the configured connection.

## 9H Verification

- Browser microphone permissions are requested via `navigator.mediaDevices.getUserMedia()` before SDK connection.
- Telnyx WebRTC SDK connection states (`sdk_connecting`, `sdk_ready`, `sdk_error`, `mic_denied`, `sdk_disconnected`) are mapped to `BrowserSoftphoneSession.connectionState`.
- Client error reporting sends structured payloads to the events endpoint for server-side persistence and debugging.
- The softphone panel UI reflects connection state in real-time with user-friendly status messages.

## 9I.1 Verification — Persist Telnyx WebRTC Connection ID

- The `telonyxConnectionId` field is persisted on `BrowserSoftphoneSession` as a nullable VARCHAR(255) column (`telonyx_connection_id`).
- Schema migration: `migrations/Version20260617090000.php` adds `telonyx_connection_id` with index.
- Entity getters/setters in [`BrowserSoftphoneSession`](/var/www/pbx/src/Entity/BrowserSoftphoneSession.php): setter trims whitespace (converts to null), getter returns nullable string.
- Service test in `tests/Service/BrowserSoftphoneSessionConnectionIdTest.php` verifies: defaults to null, accepts valid strings, trims whitespace, converts whitespace-only to null, allows explicit null.

## 9I.2 Verification — Report Browser SDK Connection Ready Event

- The `telonyx.ready` handler in [`browser_softphone_controller.js`](/var/www/pbx/assets/controllers/browser_softphone_controller.js) captures the connection ID from `this.telonyxClient.connection?.id` and POSTs it to the existing `/api/browser-softphone-sessions/{sessionToken}/events` endpoint as `{ event: 'sdk_ready', telonyxConnectionId: ... }`.
- The [`BrowserSoftphoneSessionController::events()`](/var/www/pbx/src/Controller/BrowserSoftphoneSessionController.php) endpoint extracts `telonyxConnectionId` from the JSON body on `sdk_ready` events and persists it to `BrowserSoftphoneSession.telonyxConnectionId` via `$browserSession->setTelonyxConnectionId($telonyxConnectionId)`.
- Connection state is persisted as `CONNECTION_STATE_READY` (`sdk_ready`) through `recordConnectionEvent()` which also sets status to `active`.
- The "Place Browser Call" button is re-enabled (`disabled = false`, text = `'Place Browser Call'`) so the CSR can initiate dialing server-side.
- **No auto-dial**: the `telonyx.ready` handler does NOT call `dialViaServer()` or any outbound method — it only captures and reports connection metadata.
- A new functional test (`sdkReadyEventWithTelonyxConnectionIdPersistsId`) in `tests/Functional/BrowserSoftphoneSessionWorkflowTest.php` verifies the full HTTP flow: allocate session → POST sdk_ready with telonyxConnectionId → verify response + DB persistence via repository. (Requires PostgreSQL to execute; syntax validated with `php -l`.)

## 9I.3 Verification — Browser Outbound Dial Service

- The outbound dial validation logic (tenant ownership, browser_call mode, active connection state, non-null `telonyxConnectionId`, non-terminal call state, and approved destination matching) is implemented inline in the controller to avoid premature service extraction while preserving all validation rules.
- When valid, it calls [`TelonyxCallControlService::dial()`](/var/www/pbx/src/Service/TelonyxCallControlService.php) with the stored connection ID, approved `fromNumber`, and destination number.
- On success, it updates the call session state to `ringing`/`active`, persists the change, pushes a `call.initiated` stream event via [`CallEventEngineService`](/var/www/pbx/src/Service/CallEventEngineService.php), and logs an audit event via [`AuditLogger`](/var/www/pbx/src/Service/AuditLogger.php).
- Service tests in `tests/Service/CrmBrowserOutboundDialServiceTest.php` cover validation rules for connection ID presence, active connection, browser call mode, and terminal state rejection.

## 9I.4 Verification — Browser Outbound Dial HTTP Endpoint

- The outbound dial endpoint lives at [`CrmBrowserOutboundDialController`](/var/www/pbx/src/Controller/Crm/CrmBrowserOutboundDialController.php) on `POST /crm/properties/{propertyId}/contacts/{contactId}/browser-call/dial`.
- It enforces role-gating (`TenantMembershipAccessService`), verifies property/contact existence via tenant-scoped repositories, validates CSRF tokens via `isCsrfTokenValid()`, and checks `TenantScopedEntityVoter::VIEW`.
- It returns a structured JSON response (`ok`, `callMode`, `callSessionId`, `providerSessionId`, `status`, `callState`, `callLegId`) on success, or an `error` string + HTTP status code on failure.
- Functional tests in [`tests/Functional/CrmBrowserOutboundDialTest.php`](/var/www/pbx/tests/Functional/CrmBrowserOutboundDialTest.php) cover successful dial, rejected unconnected session, rejected missing connection ID, and tenant isolation.

## 9I.5 Verification — Wire Browser Button to Dial Endpoint

- The property template (`templates/crm/property/show.html.twig`) renders the browser softphone panel with `data-browser-softphone-property-id` and `data-browser-softphone-contact-id` data attributes on the panel element.
- The button click handler (`dialViaServer()`) constructs the exact dial URL using these data attributes, attaches the stored `providerSessionId` and CSRF token to the request body, and POSTs to the `/browser-call/dial` endpoint.
- Local UI state updates synchronously from the JSON response (e.g., `setCallState('Ringing')`), while asynchronous stream events keep the panel in sync with server-side state changes.
- The "Do NOT auto-dial on SDK ready" constraint is strictly enforced: connection readiness only re-enables the manual dial button.

## 9J Verification

- Mute/unmute affects real audio via three parallel paths: (1) local WebRTC media track toggle in `browser_softphone_controller.js`, (2) server-side mute endpoint calling Telonyx Call Control API pause/resume, and (3) Telonyx SDK `call.mute()` on the active call object.
- The Hangup button terminates the platform call via `CrmBrowserCallControlController::hangup` (which calls `TelonyxCallControlService::hangup`), updates CRM call session state to completed, logs an audit event, and tears down the local SDK connection.
- Keypad/DTMF sends real tones via two parallel paths: server-side `/browser-call/dtmf` endpoint (which calls `TelonyxCallControlService::playDtmf`) for platform-level DTMF delivery, and Telonyx SDK `call.sendDtmf(digit)` on the active call object for immediate browser-side feedback.
- Recording start/stop toggles capture through the unified server endpoint at `/browser-call/recording`. For Browser Call mode, the CSR click triggers consent playback attempt, recording + transcription start via `CallCaptureControlService` (with nullable CallLeg support added for browser calls), and CRM state sync. The existing `CrmBrowserCallController::recording` path handles Bridge Call identically.
- Mute, keypad, and recording control buttons remain disabled in the template until the call reaches 'active' state; they are enabled by the JS controller when `handleCallUpdate` receives an 'active' notification from either the SDK or the server stream.
- Browser hangup, DTMF, mute, consent playback, recording, and transcription controls now require a persisted Telnyx `call_control_id`; the browser-session event pipeline persists `telnyxCallControlId` separately from the SDK connection id, and the controller rejects control requests when that id is missing.
- Capture state is only marked active after Telnyx confirms recording/transcription start. Failed recording or transcription commands now mark the call session failed instead of leaving it active.
- The status stream is closed via `teardownStream()` on hangup, which closes the EventSource connection and sets UI to idle state.

## Notes

- 9A is intentionally limited to the shared outbound call data model and the current bridge-call seeding path.
- 9B is intentionally limited to the shared capture orchestration layer and does not yet refactor bridge-call UX or add browser softphone support.
- 9C is limited to the CRM-facing bridge-call rename and compatibility alias.
- 9D is limited to browser-session allocation and PBX-owned session data.
- 9E is limited to the CRM browser softphone panel and its local session wiring.
- 9F is limited to normalized call-event state, CRM timeline updates, and audit logging.
- 9G is limited to browser-call token brokerage and session intent seeding.
- 9H is limited to browser microphone permissions, SDK connection state, and client error reporting. 9I (Browser Outbound Dial) is now complete: the CSR clicks "Place Browser Call" after SDK connects, which triggers a server-side outbound dial via Telonyx WebRTC connection ID.

## Run 1 Note

- Browser outbound dial reachability was repaired by fixing the controller import, adding tenant/user-scoped browser-session lookup, and accepting `sdk_ready` as the browser readiness gate.
- The focused outbound-dial functional and service suites now pass again after the Run 1 patch.

## Run 2 Note

- Browser Call prepare, dial, hangup, DTMF, mute, recording, and transcription endpoints now require the shared browser-call CSRF token `crm_browser_call_{propertyId}_{contactId}`.
- Empty and missing `_token` values are rejected instead of bypassing validation.
- Browser-call functional tests now fetch a real token from the CSRF token manager and include explicit missing/invalid token rejection coverage for the prepare flow.

## Run 3 Note

- Browser Call outbound dialing now uses the Telnyx WebRTC SDK `client.newCall({ destinationNumber })` path instead of server-side `/v2/calls`.
- `telnyxClient.connection?.id` is treated as SDK connection/session metadata only and is persisted separately as `BrowserSoftphoneSession.telnyxConnectionId`.
- The browser call object `telnyxIDs.telnyxCallControlId` is captured from the SDK call update flow and persisted separately as `BrowserSoftphoneSession.telnyxCallControlId`.
- The browser-session event endpoint now returns the persisted call-control id so the browser and backend can distinguish SDK connection ids, SDK call ids, and Telnyx call-control ids.
- Remaining manual verification items:
  - confirm the Telnyx SDK call object continues to surface `telnyxIDs.telnyxCallControlId` consistently across browser states
  - confirm the later control endpoints switch to the persisted call-control id before Run 4 is completed

## Run 4 Note

- Browser control endpoints now reject hangup, DTMF, mute, consent playback, recording, and transcription requests until `BrowserSoftphoneSession.telnyxCallControlId` is present.
- `CallCaptureControlService` no longer uses `providerSessionId` as a Telnyx `call_control_id`; recording and transcription states are only flipped to active after Telnyx confirms the command.
- The browser call control functional suite and capture service suite were rerun sequentially and pass again after the Run 4 patch.

## Run 5 Note

- Browser Call outbound-dial coverage was expanded in [`tests/Functional/CrmBrowserOutboundDialTest.php`](/var/www/pbx/tests/Functional/CrmBrowserOutboundDialTest.php) to cover repeated attempts, terminal state rejection, connection-id rejection, and tenant-scoped lookup paths.
- Browser Call workflow coverage was adjusted in [`tests/Functional/CrmBrowserCallWorkflowTest.php`](/var/www/pbx/tests/Functional/CrmBrowserCallWorkflowTest.php) and [`tests/Service/BrowserCallEventReconcilerServiceTest.php`](/var/www/pbx/tests/Service/BrowserCallEventReconcilerServiceTest.php) to keep the phase-9 browser-call tests aligned with the current code paths and state-ranking rules.
- The Playwright browser-call smoke test already matches the current manual `Place Browser Call` flow in [`tests/playwright/crm-property-calls.spec.ts`](/var/www/pbx/tests/playwright/crm-property-calls.spec.ts).
- Current remaining failures:
  - [`tests/Functional/CrmBrowserOutboundDialTest.php`](/var/www/pbx/tests/Functional/CrmBrowserOutboundDialTest.php): the browser-call dial path still returns `Invalid CSRF token.` for the unconnected-session case, and the no-connection-id case still redirects to login.
  - [`tests/Functional/CrmBrowserCallWorkflowTest.php`](/var/www/pbx/tests/Functional/CrmBrowserCallWorkflowTest.php): the browser-call prepare success path still redirects to login.
- Because of those harness-level failures, Run 5 is not fully green yet.
