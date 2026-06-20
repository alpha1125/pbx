# TODO

- [x] Phase 0 - Browser Call Stability Gate
- [x] Phase 1 - Mute controls
- [x] Phase 2 - Settings panel
- [x] Phase 3 - Audio device persistence
- [x] Phase 4 - Mercure transcript stream
- [x] Phase 5 - Transcript bubbles
- [x] Phase 6 - Interim/final transcript handling
- [x] Phase 7 - Post-call summary placeholder

## Open Playwright follow-ups

- [ ] Confirm Telnyx is pointing to `https://pbx.firstfire.ca/api/telnyx/webhook` for the live transcription path before re-testing the transcript pane.
- [ ] Verify the browser softphone call flow after click: `Mute` / `Hangup` state transitions are still not matching the current Playwright assertions.
- [ ] Tighten Playwright selectors for the settings panel: current tests hit strict-mode collisions on generic labels like `Audio Processing`, `Audio Devices`, and `Speaker`.
- [ ] Confirm the transcript panel expectations: the mocked transcript stream test still does not find the expected transcript topic text after the call starts.
- [ ] Confirm the post-call summary placeholder flow: the current Playwright spec still expects `Hangup` visibility/state that does not match the rendered UI.
- [ ] Re-test the mocked call flow after the transcript-panel extraction: Playwright still reports `Telnyx client error` in the call-state path, which keeps `Mute` and `Hangup` disabled in the mock harness.
- [x] Transcript panel is now visible before call start and renders from an explicit imported module; the remaining failures are in the mocked call activation path, not the panel shell.
- [x] Verified the live webhook bridge end-to-end with a synthetic `call.transcription.saved` event: the `telnyx_event` row is stored in Postgres and the POC SSE stream emits the transcript segment.
- [x] The POC transcript topic now stays on the stable browser session UUID and the dynamic Telnyx `call_control_id` is registered server-side for webhook routing.
- [ ] The real Telnyx account still needs to send transcription webhooks to `https://pbx.firstfire.ca/api/telnyx/webhook`; recent live calls only show lifecycle webhooks in Postgres, not transcription events.
- [ ] Revisit the softphone bundle for any remaining browser runtime issues if the above selector/state fixes still expose failures.
- [ ] The mock Telnyx call-state harness still reports `Telnyx client error` in Playwright, which keeps `Mute` and `Hangup` disabled until that separate issue is fixed.
- [ ] The live browser softphone repro should keep the transcript pane visible and log the call-control registration response while the call is active.
- [ ] The hangup path still needs verification after the `buildMockPostCallSummary` mismatch fix and asset rebuild, because the page was redrawing blank on disconnect.
