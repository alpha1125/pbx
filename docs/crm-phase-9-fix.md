Phase 9 Fix Prompts

Run 1 — Fix Browser Outbound Dial Endpoint Reachability

/goal Fix Phase 9 Browser Outbound Dial endpoint reachability and basic validation failures.

Context:
A Codex review found that the Browser outbound dial endpoint currently fails before meaningful validation because of missing imports, wrong connection-state comparison, and missing BrowserSoftphoneSessionService lookup methods.

Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md
* src/Controller/Crm/CrmBrowserOutboundDialController.php
* src/Controller/Crm/CrmBrowserCallControlController.php
* src/Service/BrowserSoftphoneSessionService.php
* src/Entity/BrowserSoftphoneSession.php
* src/Entity/CallSession.php
* tests/Functional/CrmBrowserOutboundDialTest.php
* tests/Service/CrmBrowserOutboundDialServiceTest.php

Scope:

1. Fix missing BrowserSoftphoneSessionService import/type resolution in CrmBrowserOutboundDialController.
2. Fix connection-state validation:
    * Browser softphone readiness must compare against BrowserSoftphoneSession::CONNECTION_STATE_READY, not CallSession::CALL_STATE_CONNECTED.
3. Add or repair BrowserSoftphoneSessionService::findByProviderSessionId() if controllers require it.
4. Ensure lookup is tenant/user safe:
    * do not allow provider session IDs from another tenant
    * do not load by provider session ID and trust it without ownership checks
5. Fix the focused functional tests so endpoint resolution no longer returns HTTP 500.
6. Fix service tests that misuse PHPUnit stubs, especially any createStub(...)->expects() misuse.

Rules:

* Do not change the Telnyx WebRTC ID model yet.
* Do not decide whether Browser Call should use SDK newCall() or server Call Control dial.
* Do not touch CSRF yet except if required to make existing tests run.
* Do not refactor Bridge Call.
* Do not implement new softphone features.
* Keep changes minimal and targeted.
* Preserve tenant isolation.
* Update docs/crm-phase-9-completion-state.md.

Acceptance criteria:

* php bin/phpunit tests/Functional/CrmBrowserOutboundDialTest.php no longer fails with unresolved controller/service dependency.
* Tests prove sdk_ready browser sessions are accepted for dial validation.
* Tests prove missing connection ID is rejected.
* Tests prove unready browser session is rejected.
* Tests prove terminal call states are rejected.
* Tests prove cross-tenant access is rejected.
* Bridge Call code path remains untouched unless a shared type/import issue requires a minimal fix.

Run:

* php bin/phpunit tests/Functional/CrmBrowserOutboundDialTest.php
* php bin/phpunit tests/Service/CrmBrowserOutboundDialServiceTest.php
* any smaller relevant tests needed

Output:

* Summary of files changed
* Tests run and results
* Remaining known gaps
* Whether 9I is still blocked by Telnyx ID-model uncertainty

Run 2 — Fix Browser Call CSRF Correctness

/goal Fix Phase 9 Browser Call CSRF enforcement and tests.

Context:
A Codex review found that CSRF validation only runs when _token is non-empty, and templates/tests use inconsistent token IDs. This creates two bad outcomes:

* real UI requests may be rejected because they send the wrong token
* tests with empty _token bypass CSRF entirely

Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md
* templates/crm/property/show.html.twig
* src/Controller/Crm/CrmBrowserCallTokenController.php
* src/Controller/Crm/CrmBrowserOutboundDialController.php
* src/Controller/Crm/CrmBrowserCallControlController.php
* tests/Functional/CrmBrowserOutboundDialTest.php
* tests/Functional/BrowserCall

Scope:

1. Require CSRF validation for every mutating Browser Call endpoint.
2. Remove behavior where an empty _token bypasses validation.
3. Align template token IDs with controller expectations.
4. Choose one of these strategies and apply it consistently:
    * action-specific tokens, such as crm_browser_call_prepare_*, crm_browser_call_dial_*, crm_browser_call_control_*
    * or one explicitly shared Browser Call token ID if simpler and safe
5. Update JavaScript/template data attributes if tokens are passed through DOM.
6. Update tests to use valid non-empty CSRF tokens.
7. Add tests for missing/invalid token rejection.

Rules:

* Do not change Telnyx/WebRTC behavior.
* Do not change endpoint business logic except CSRF handling.
* Do not change Bridge Call.
* Do not weaken CSRF for test convenience.
* Preserve tenant isolation.
* Update docs/crm-phase-9-completion-state.md.

Acceptance criteria:

* Empty _token is rejected.
* Missing _token is rejected.
* Invalid _token is rejected.
* Correct UI-rendered token is accepted.
* Functional tests no longer rely on empty token bypass.
* Browser Call prepare/dial/control endpoints use consistent CSRF policy.

Run:

* php bin/phpunit tests/Functional/CrmBrowserOutboundDialTest.php
* relevant Browser Call functional tests
* optionally php bin/console lint:container --env=test

Output:

* Token naming strategy chosen
* Files changed
* Tests run and results
* Any endpoints still missing CSRF coverage

Run 3 — Resolve Telnyx Browser Call ID Model

/goal Resolve the Phase 9 Telnyx WebRTC Browser Call ID model and produce a minimal patch plan. Implement only if the correct model is clear from code/docs.

Context:
A review found a high-risk Telnyx ID mismatch:

* frontend stores this.telnyxClient.connection?.id
* backend passes that value to TelnyxCallControlService::dial() as a Call Control connection_id
* Telnyx WebRTC SDK flows normally place outbound browser calls with SDK client.newCall({ destinationNumber })
* server Call Control /calls uses a Telnyx connection ID, which may not be the same as SDK WebSocket/session ID

This may mean current Browser Call is still not a real softphone flow.

Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md
* assets/controllers/browser_softphone_controller.js
* src/Controller/Crm/CrmBrowserOutboundDialController.php
* src/Service/TelnyxCallControlService.php
* src/Service/BrowserSoftphoneSessionService.php
* src/Entity/BrowserSoftphoneSession.php
* existing Telnyx SDK integration code
* any local Telnyx docs/comments/config in repo

Investigation questions:

1. Is TelnyxRTC.connection.id a valid Telnyx Call Control /calls connection_id?
2. If not, should Browser Call outbound dialing use Telnyx WebRTC SDK newCall() from the browser?
3. What identifier should be persisted for:
    * SDK connection/session
    * SDK call
    * Telnyx call/session
    * Telnyx call_control_id
4. At what point does the app receive the real call_control_id needed for:
    * hangup
    * DTMF
    * consent playback
    * recording
    * transcription
5. Should platform controls remain disabled until call_control_id is known from webhook/notification metadata?

If correct model is clear:

* Implement the minimal fix.
* Prefer one outbound dial design; do not leave both competing paths active.
* If SDK newCall() is the correct Browser Call path, make the browser use that path and stop using server Call Control /calls for Browser Call dialing.
* Keep Symfony as the token broker, authorization gate, audit logger, and webhook reconciler.
* Persist the correct IDs separately with clear names.
* Do not use providerSessionId as call_control_id.

If correct model is not clear:

* Do not guess.
* Add code comments/TODOs only where useful.
* Produce a concrete manual verification checklist for Telnyx support/docs.
* Mark Browser Call dial/capture as blocked in completion-state.

Rules:

* Do not break Bridge Call.
* Do not change Bridge Call server-side Call Control dialing.
* Do not expose Telnyx API keys to browser.
* Do not allow arbitrary browser destinations.
* Preserve tenant isolation.
* Keep patch small if implementing.
* Update docs/crm-phase-9-completion-state.md.

Acceptance criteria:

* There is no ambiguous use of SDK connection ID as Call Control connection ID unless verified.
* There is a single clear Browser Call dial path.
* ID fields are named clearly.
* Browser Call does not pretend capture/hangup/DTMF can work before required Telnyx call identifiers exist.
* Completion-state documents whether this is fixed or blocked pending Telnyx confirmation.

Run:

* targeted frontend/backend tests affected by the chosen path
* Browser Call functional tests if applicable
* do not spend time broadly refactoring unrelated tests

Output:

* Verdict on Telnyx ID model
* Chosen Browser Call dial design
* Files changed, if any
* Remaining manual verification items
* Tests run and results

Run 4 — Fix Browser Call Controls and False Capture State

/goal Fix Phase 9 Browser Call controls so providerSessionId is not treated as call_control_id, and capture is not falsely marked active.

Context:
A review found Browser Call control paths use providerSessionId as if it were a Telnyx call_control_id. It also found recording/transcription paths may catch failure and still mark capture active.

Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md
* src/Controller/Crm/CrmBrowserCallControlController.php
* src/Service/CallCaptureControlService.php
* src/Service/TelnyxCallControlService.php
* src/Entity/CallSession.php
* src/Entity/BrowserSoftphoneSession.php
* assets/controllers/browser_softphone_controller.js
* relevant tests

Scope:

1. Stop using providerSessionId as call_control_id.
2. Identify where real Telnyx call_control_id is stored or should be stored.
3. Disable or reject platform actions until required Telnyx call_control_id exists:
    * hangup via Telnyx Call Control
    * DTMF via Telnyx Call Control
    * consent playback
    * recording
    * transcription
4. Ensure capture state is only marked active after Telnyx confirms recording/transcription start, or after the call command succeeds according to the existing reliable pattern.
5. Remove or clearly block any fake “client-side capture via SDK” success path unless implemented and verified.
6. Add tests for missing call_control_id rejection.
7. Add tests that capture is not marked active after Telnyx command failure.

Rules:

* Do not redesign the whole Browser Call flow.
* Do not change Bridge Call capture behavior unless fixing a shared bug.
* Do not start live transcription UI.
* Preserve existing shared capture service where possible.
* Preserve tenant isolation.
* Update docs/crm-phase-9-completion-state.md.

Acceptance criteria:

* Browser Call controls fail safely when call_control_id is missing.
* providerSessionId is never used as call_control_id.
* Failed recording/transcription command does not mark capture active.
* Capture state remains accurate.
* Tests cover missing call_control_id and command failure.

Run:

* relevant service tests around CallCaptureControlService
* relevant Browser Call control functional tests
* Bridge Call capture regression test if available

Output:

* Files changed
* Tests run and results
* Any remaining blocked behavior awaiting Telnyx webhook/call ID reconciliation

Run 5 — Repair and Expand Phase 9 Tests

/goal Repair and expand Phase 9 Browser Call tests after endpoint, CSRF, and ID-model fixes.

Context:
Review found test gaps and some tests that duplicate production validation logic instead of exercising production services. Frontend tests may also expect old auto-dial behavior.

Read:

* docs/crm-phase-9.md
* docs/crm-phase-9-completion-state.md
* tests/Service/CrmBrowserOutboundDialServiceTest.php
* tests/Functional/CrmBrowserOutboundDialTest.php
* tests/Functional/BrowserCall
* frontend/Playwright tests if present
* assets/controllers/browser_softphone_controller.js
* relevant controllers/services/entities

Scope:

1. Remove copied validation helper logic from service tests if it bypasses production code.
2. Ensure service tests exercise real production services or narrow extracted validators.
3. Add/repair tests for:
    * sdk_ready accepted
    * missing connection ID rejected
    * unready session rejected
    * terminal state rejected
    * stale session rejected
    * repeated dial attempt rejected
    * cross-tenant provider session denied
    * destination mismatch denied
    * invalid/missing CSRF rejected
    * valid CSRF accepted
    * Bridge Call regression
4. Update frontend/Playwright tests to match the chosen Browser Call behavior:
    * no auto-dial after SDK ready
    * manual Place Browser Call click
    * or SDK newCall() path if Run 3 changed design
5. Add tests for SDK failure/mic denial UI if the frontend test harness supports it.
6. Keep test fixtures minimal.

Rules:

* Do not add new production features.
* Do not broadly refactor unrelated tests.
* Do not weaken assertions just to pass.
* Do not skip failing Phase 9 tests unless there is a documented external dependency.
* Preserve tenant isolation.
* Update docs/crm-phase-9-completion-state.md.

Acceptance criteria:

* Tests prove production code behavior, not copied helper logic.
* CSRF tests no longer rely on empty token bypass.
* Browser Call tests match the actual chosen dial model.
* Bridge Call regression coverage exists.
* Remaining failures are documented with cause.

Run:

* php bin/phpunit tests/Service/CrmBrowserOutboundDialServiceTest.php
* php bin/phpunit tests/Functional/CrmBrowserOutboundDialTest.php
* relevant Browser Call control/capture tests
* relevant frontend tests if available

Output:

* Tests added/changed
* Tests run and results
* Remaining failures
* Whether Phase 9 can proceed to next sub-phase

