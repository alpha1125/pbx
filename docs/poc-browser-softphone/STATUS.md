# STATUS

Append-only log. Do not overwrite prior entries.

## YYYY-MM-DD HH:mm:ss UTC
Phase:
Status:
Files Modified:
Tests:
Manual Verification Required:
Notes:

## 2026-06-19 01:32:16 UTC
Phase: 1 - Mute Controls
Status: Implemented and locally validated with targeted PHPUnit coverage.
Files Modified: assets/poc/browser_softphone_app.js, src/Service/BrowserSoftphonePocStateBuilder.php, tests/Service/BrowserSoftphonePocStateBuilderTest.php, tests/playwright/browser-softphone-poc.spec.ts, docs/poc-browser-softphone/TODO.md
Tests: `php bin/phpunit tests/Service/BrowserSoftphonePocStateBuilderTest.php tests/Controller/PocBrowserSoftphoneControllerTest.php` passed; `php bin/phpunit` still shows pre-existing unrelated repo failures; Playwright blocked by missing local `@playwright/test` module.
Manual Verification Required: Mute toggle label and local track enable/disable behavior.
Notes: Mute remains local-only and does not call backend.

## 2026-06-19 01:32:16 UTC
Phase: 2 - Settings Panel
Status: Implemented with desktop collapsed card and mobile bottom-sheet modal.
Files Modified: assets/poc/browser_softphone_app.js, assets/poc/browser_softphone_settings.js, tests/playwright/browser-softphone-poc.spec.ts, docs/poc-browser-softphone/TODO.md
Tests: `php bin/console lint:twig templates/` passed; `php bin/console lint:container` passed; `php bin/console doctrine:schema:validate` reports existing schema drift; Playwright blocked by missing local `@playwright/test` module.
Manual Verification Required: Desktop open/collapse, mobile bottom sheet open/close, and settings content visibility.
Notes: Settings are isolated to `/poc/browser-softphone` and do not affect CRM production flows.

## 2026-06-19 03:17:50 UTC
Phase: 3 - Audio Device Persistence
Status: Implemented and locally validated with targeted module-level coverage.
Files Modified: assets/poc/softphone_preferences.js, assets/poc/browser_softphone_app.js, tests/poc/softphone_preferences.test.mjs, tests/playwright/browser-softphone-poc.spec.ts, docs/poc-browser-softphone/TODO.md
Tests: `node --test tests/poc/softphone_preferences.test.mjs` passed; `php bin/phpunit --filter PocBrowserSoftphoneControllerTest` passed; `php bin/console lint:twig templates/` passed; `php bin/console lint:container` passed; `php bin/console doctrine:schema:validate` reports existing schema drift; Playwright run on this machine was repeatedly OOM-killed.
Manual Verification Required: Audio device persistence after reload, device list refresh on `devicechange`, and settings restore from localStorage in a browser with sufficient memory.
Notes: Preferences remain local to `pbx.softphone.preferences` and do not touch CRM production settings.

## 2026-06-19 03:17:50 UTC
Phase: 4 - Mercure Transcript Stream
Status: Implemented and locally validated at the PHP unit level; browser smoke coverage added but not runnable to completion in this memory-constrained environment.
Files Modified: src/Service/PocBrowserSoftphoneTranscriptService.php, src/Controller/Poc/BrowserSoftphoneTranscriptController.php, src/Controller/Poc/BrowserSoftphoneController.php, assets/poc/browser_softphone_app.js, tests/Service/PocBrowserSoftphoneTranscriptServiceTest.php, tests/playwright/browser-softphone-poc.spec.ts, docs/poc-browser-softphone/TODO.md
Tests: `php bin/phpunit --filter PocBrowserSoftphoneTranscriptServiceTest` passed; `php bin/phpunit --filter PocBrowserSoftphoneControllerTest` passed; `php bin/console lint:twig templates/` passed; `php bin/console lint:container` passed; `php bin/console doctrine:schema:validate` reports existing schema drift; Playwright transcript smoke test was OOM-killed before completion.
Manual Verification Required: Browser receipt of streamed transcript segments, deduplication behavior, and transcript panel rendering in a browser session with enough memory to complete Playwright.
Notes: Transcript topic is isolated to `/poc/browser-softphone/{callSessionId}/transcript` and does not modify production Mercure topics.

## 2026-06-19 03:24:06 UTC
Phase: 5 - Transcript Bubbles
Status: Implemented with Apple Messages-style transcript bubbles, timestamp rendering, and auto-scroll in the POC transcript pane.
Files Modified: assets/poc/browser_softphone_app.js, tests/playwright/browser-softphone-poc.spec.ts, docs/poc-browser-softphone/TODO.md
Tests: `node --check assets/poc/browser_softphone_app.js` passed; `php bin/phpunit --filter PocBrowserSoftphoneTranscriptServiceTest` passed; `php bin/console lint:twig templates/` passed; `php bin/console lint:container` passed; `php bin/console doctrine:schema:validate` reports existing schema drift; Playwright transcript smoke test could not run because no local browser binary is installed.
Manual Verification Required: Bubble alignment, timestamps, and auto-scroll in a browser session with Playwright Chromium installed.
Notes: The transcript pane remains isolated to `/poc/browser-softphone` and continues to use the Phase 4 topic contract without touching production Mercure topics.

## 2026-06-19 03:33:12 UTC
Phase: 6 - Interim vs Final Transcript
Status: Implemented with in-place interim-to-final transcript promotion in the browser UI and transcript persistence merge logic.
Files Modified: assets/poc/browser_softphone_app.js, src/Service/PocBrowserSoftphoneTranscriptMergeService.php, src/Service/PocBrowserSoftphoneTranscriptService.php, tests/Service/PocBrowserSoftphoneTranscriptMergeServiceTest.php, tests/Service/PocBrowserSoftphoneTranscriptServiceTest.php, tests/playwright/browser-softphone-poc.spec.ts, docs/poc-browser-softphone/TODO.md
Tests: `node --check assets/poc/browser_softphone_app.js` passed; `php bin/phpunit --filter 'PocBrowserSoftphoneTranscript(Service|MergeService)Test'` passed; `php bin/phpunit` was OOM-killed in this environment after partial progress; `php bin/console lint:twig templates/` passed; `php bin/console lint:container` passed; `php bin/console doctrine:schema:validate` reports existing schema drift; `npx playwright test tests/playwright/browser-softphone-poc.spec.ts --list` passed; `npx playwright test` was killed by the local memory limit.
Manual Verification Required: Interim bubbles should render lighter and italic with a typing indicator, then update in place to the final bubble in a browser session with enough memory and installed Playwright Chromium.
Notes: Interim/final handling stays isolated to `/poc/browser-softphone`; the merge key uses `sourceEventId` when available so final segments replace the interim bubble instead of creating duplicates.

## 2026-06-19 03:39:06 UTC
Phase: 7 - Post Call Summary Placeholder
Status: Implemented with a mock post-call summary panel that appears after hangup.
Files Modified: assets/poc/browser_softphone_app.js, tests/playwright/browser-softphone-poc.spec.ts, docs/poc-browser-softphone/TODO.md
Tests: `node --check assets/poc/browser_softphone_app.js` passed; `php bin/phpunit --filter 'PocBrowserSoftphoneTranscript(Service|MergeService)Test'` passed; `php bin/console lint:twig templates/` passed; `php bin/console lint:container` passed; `php bin/console doctrine:schema:validate` reports existing schema drift; `npx playwright test tests/playwright/browser-softphone-poc.spec.ts --list` passed; a targeted Playwright run for the new Phase 7 spec failed before page load because the Chromium binary is missing in this environment; `php bin/phpunit` still fails on pre-existing unrelated repository issues.
Manual Verification Required: Hang up a live browser call and confirm the placeholder summary panel renders with call summary, customer concerns, action items, and keywords.
Notes: The panel is mock data only and remains isolated to `/poc/browser-softphone`; no CRM production flow was changed.
