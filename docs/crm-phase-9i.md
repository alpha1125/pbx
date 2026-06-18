Better breakdown for Qwen3.6:35B

9I.1 — Persist Telnyx WebRTC Connection ID

Goal

Capture and persist the Telnyx WebRTC connection/session ID after SDK connect.

Scope

* Add telnyxConnectionId to BrowserSoftphoneSession.
* Add migration.
* Add getter/setter.
* Update any serializer/DTO if needed.
* Add service test for storing connection ID.

Prompt

/goal Read:
- docs/crm-phase-9.md
- docs/crm-phase-9-completion-state.md
- src/Entity/BrowserSoftphoneSession.php
- existing BrowserSoftphoneSession repository/service/tests
Implement 9I.1 only: Persist Telnyx WebRTC Connection ID.
Scope:
- Add telnyxConnectionId to BrowserSoftphoneSession.
- Add Doctrine migration.
- Add getter/setter.
- Add or update tests proving the field can be persisted.
Rules:
- Do not modify browser JavaScript yet.
- Do not add outbound dial endpoint.
- Do not call Telnyx.
- Do not change Bridge Call behavior.
- Preserve tenant isolation.
- Update docs/crm-phase-9-completion-state.md.
- Run relevant tests.
- Stop after 9I.1 is complete.

⸻

9I.2 — Report Browser SDK Connection Ready Event

Goal

Have the browser report the Telnyx connection ID back to Symfony when the SDK is ready.

Scope

* Update browser_softphone_controller.js.
* On telnyx.ready, capture connection ID.
* POST connection ID to existing events endpoint or a narrow new endpoint.
* Persist connection state as ready.
* Do not auto-dial.

Prompt

/goal Read:
- docs/crm-phase-9.md
- docs/crm-phase-9-completion-state.md
- assets/controllers/browser_softphone_controller.js
- existing browser softphone controller/service/event endpoint
Implement 9I.2 only: Report Browser SDK Connection Ready Event.
Scope:
- Capture the Telnyx WebRTC connection ID from the SDK when ready.
- Report the connection ID to Symfony.
- Persist the browser softphone session connection state as ready.
- Re-enable the Place Browser Call button after SDK connect.
- Do not auto-dial.
Rules:
- Do not add outbound dial endpoint yet.
- Do not modify Doctrine schema unless 9I.1 was incomplete.
- Do not implement DTMF.
- Do not implement mute/hangup changes.
- Do not change Bridge Call behavior.
- Update docs/crm-phase-9-completion-state.md.
- Run relevant tests.
- Stop after 9I.2 is complete.

⸻

9I.3 — Browser Outbound Dial Service

Goal

Create a backend service that validates whether a Browser Call may be dialed.

Scope

* Add CrmBrowserOutboundDialService.
* Validate:
    * call session exists
    * tenant ownership
    * mode is browser_call
    * browser session has telnyxConnectionId
    * browser session is ready
    * call session is not terminal
    * destination belongs to tenant contact/property
* Call TelnyxCallControlService::dial() with:
    * stored Telnyx connection ID
    * approved from number
    * approved destination
* Update call state.
* Emit audit event.
* Push stream event.

Prompt

/goal Read:
- docs/crm-phase-9.md
- docs/crm-phase-9-completion-state.md
- existing CallSession entity/service patterns
- existing BrowserSoftphoneSession entity/service patterns
- existing TelnyxCallControlService
- existing audit/timeline/stream patterns
Implement 9I.3 only: Browser Outbound Dial Service.
Scope:
- Add a backend service that validates and starts a Browser Call dial.
- Validate tenant ownership, browser_call mode, ready browser session, non-null telnyxConnectionId, non-terminal call state, and approved destination.
- Call TelnyxCallControlService::dial() with the stored telnyxConnectionId, from number, and approved destination.
- Update call state to ringing or equivalent existing state.
- Log audit event.
- Push call.initiated stream event.
Rules:
- Do not add HTTP controller yet.
- Do not modify browser JavaScript.
- Do not implement DTMF/mute/hangup.
- Do not change Bridge Call behavior.
- Preserve existing call lifecycle patterns.
- Add service tests.
- Update docs/crm-phase-9-completion-state.md.
- Run relevant tests.
- Stop after 9I.3 is complete.

⸻

9I.4 — Browser Outbound Dial HTTP Endpoint

Goal

Expose the backend dial service through a tenant-protected HTTP endpoint.

Scope

* Add CrmBrowserOutboundDialController.
* Route:

POST /crm/properties/{propertyId}/contacts/{contactId}/browser-call/dial

* Validate property/contact access.
* Accept providerSessionId or session identifier.
* Call CrmBrowserOutboundDialService.
* Return JSON response.
* Add functional tests.

Prompt

/goal Read:
- docs/crm-phase-9.md
- docs/crm-phase-9-completion-state.md
- existing CRM controller patterns
- existing tenant/RBAC patterns
- existing property/contact access patterns
- existing CrmBrowserOutboundDialService from 9I.3
Implement 9I.4 only: Browser Outbound Dial HTTP Endpoint.
Scope:
- Add CrmBrowserOutboundDialController.
- Add POST endpoint: /crm/properties/{propertyId}/contacts/{contactId}/browser-call/dial
- Validate property/contact tenant access.
- Accept providerSessionId or existing browser session identifier.
- Call CrmBrowserOutboundDialService.
- Return JSON success/failure response.
- Add functional tests for successful dial, unconnected session, missing connection ID, and tenant isolation.
Rules:
- Do not modify browser JavaScript yet.
- Do not change the dial service unless needed for endpoint integration.
- Do not implement DTMF/mute/hangup.
- Do not change Bridge Call behavior.
- Update docs/crm-phase-9-completion-state.md.
- Run relevant tests.
- Stop after 9I.4 is complete.

⸻

9I.5 — Wire Browser Button to Dial Endpoint

Goal

Connect the Browser Call button to the new outbound dial endpoint.

Scope

* Update templates/crm/property/show.html.twig.
* Add needed data attributes:
    * property ID
    * contact ID
    * provider session ID
    * dial URL if preferable
* Update browser_softphone_controller.js.
* On button click:
    * ensure SDK ready
    * ensure provider session ID exists
    * POST to dial endpoint
    * update UI state from response/stream
* Do not auto-dial on SDK ready.

Prompt

/goal Read:
- docs/crm-phase-9.md
- docs/crm-phase-9-completion-state.md
- templates/crm/property/show.html.twig
- assets/controllers/browser_softphone_controller.js
- CrmBrowserOutboundDialController from 9I.4
Implement 9I.5 only: Wire Browser Button to Dial Endpoint.
Scope:
- Add required data attributes to the Browser Call panel/template.
- Update browser_softphone_controller.js so Place Browser Call posts to the dial endpoint.
- Send providerSessionId or the correct browser session identifier.
- Do not auto-dial after SDK connect.
- Update UI state based on response and existing status stream.
Rules:
- Do not modify backend dial validation unless strictly necessary.
- Do not implement DTMF/mute/hangup.
- Do not change Bridge Call behavior.
- Do not start 9J.
- Update docs/crm-phase-9-completion-state.md.
- Run relevant tests.
- Stop after 9I.5 is complete.

⸻

Recommended Qwen execution order

9I.1 Persist Telnyx WebRTC Connection ID
9I.2 Report Browser SDK Connection Ready Event
9I.3 Browser Outbound Dial Service
9I.4 Browser Outbound Dial HTTP Endpoint
9I.5 Wire Browser Button to Dial Endpoint



