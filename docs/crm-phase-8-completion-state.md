# CRM Phase 8 Completion State

## Scope

This document tracks the implementation status of CRM Phase 8 as of the current worktree.

## Status

- 8.1 CRM Enrichment From Conversations: complete
- 8.2 Sales and Service Suggestions: complete
- 8.3 Reporting and Dashboards: complete
- 8.4 Search and Automation: partial

## 8.1 Verification

- AI-generated call summaries are already produced through [`src/Service/OllamaSummaryService.php`](/var/www/pbx/src/Service/OllamaSummaryService.php) and stored on call summary rows through [`src/Service/TranscriptionResultService.php`](/var/www/pbx/src/Service/TranscriptionResultService.php).
- Transcript and summary timeline items are enriched with conversation insights in [`src/Service/CallInsightService.php`](/var/www/pbx/src/Service/CallInsightService.php).
- Suggested property and contact matches are derived from call-session phone context, transcript text, and tenant CRM records in [`src/Service/CallInsightService.php`](/var/www/pbx/src/Service/CallInsightService.php).
- Suggested call disposition and next-step text are derived from transcript and summary signals in [`src/Service/CallInsightService.php`](/var/www/pbx/src/Service/CallInsightService.php).
- The communication timeline projects the enriched transcript and summary metadata through [`src/Service/CommunicationTimelineProjector.php`](/var/www/pbx/src/Service/CommunicationTimelineProjector.php) and renders it in [`templates/crm/communication/_timeline_item.html.twig`](/var/www/pbx/templates/crm/communication/_timeline_item.html.twig).
- The property timeline workflow exercises the enrichment and rendering path in [`tests/Functional/CrmTenantIsolationTest.php`](/var/www/pbx/tests/Functional/CrmTenantIsolationTest.php).
- The insight rules are covered directly by [`tests/Service/CallInsightServiceTest.php`](/var/www/pbx/tests/Service/CallInsightServiceTest.php).

## 8.2 Verification

- Deterministic sales and service suggestions are implemented in [`src/Service/CrmSuggestionService.php`](/var/www/pbx/src/Service/CrmSuggestionService.php).
- Estimate pages render suggested line items and follow-up actions through [`src/Controller/Crm/EstimateController.php`](/var/www/pbx/src/Controller/Crm/EstimateController.php) and [`templates/crm/estimate/show.html.twig`](/var/www/pbx/templates/crm/estimate/show.html.twig).
- Property pages render follow-up suggestions and equipment replacement flags through [`src/Controller/Crm/PropertyController.php`](/var/www/pbx/src/Controller/Crm/PropertyController.php) and [`templates/crm/property/show.html.twig`](/var/www/pbx/templates/crm/property/show.html.twig).
- Conversation context is projected from the latest property timeline items via [`src/Service/CommunicationTimelineProjector.php`](/var/www/pbx/src/Service/CommunicationTimelineProjector.php) and [`src/Service/CallInsightService.php`](/var/www/pbx/src/Service/CallInsightService.php).
- Equipment replacement flags are derived from equipment age, warranty state, and service history through [`src/Repository/EquipmentRepository.php`](/var/www/pbx/src/Repository/EquipmentRepository.php) and [`src/Repository/EquipmentServiceRecordRepository.php`](/var/www/pbx/src/Repository/EquipmentServiceRecordRepository.php).
- Estimate and property suggestion rendering is covered by [`tests/Functional/CrmTenantIsolationTest.php`](/var/www/pbx/tests/Functional/CrmTenantIsolationTest.php).

## 8.4 Verification

- Full-text transcript and CRM search is implemented in [`src/Controller/Crm/CommunicationSearchController.php`](/var/www/pbx/src/Controller/Crm/CommunicationSearchController.php).
- Timeline item search and transcript-segment search are handled by [`src/Repository/CommunicationTimelineItemRepository.php`](/var/www/pbx/src/Repository/CommunicationTimelineItemRepository.php) and [`src/Repository/CallTranscriptSegmentRepository.php`](/var/www/pbx/src/Repository/CallTranscriptSegmentRepository.php).
- Search results render transcript matches and transcript links in [`templates/crm/communication/search.html.twig`](/var/www/pbx/templates/crm/communication/search.html.twig).
- The transcript-aware search path is covered by [`tests/Functional/CrmTenantIsolationTest.php`](/var/www/pbx/tests/Functional/CrmTenantIsolationTest.php).

## 8.3 Verification

- Tenant-scoped reporting dashboards are implemented in [`src/Service/CrmReportingDashboardService.php`](/var/www/pbx/src/Service/CrmReportingDashboardService.php) and exposed through [`src/Controller/Crm/ReportingDashboardController.php`](/var/www/pbx/src/Controller/Crm/ReportingDashboardController.php).
- The dashboard renders lead-to-quote conversion, quote acceptance, pipeline totals, call volume, and service throughput in [`templates/crm/reporting/index.html.twig`](/var/www/pbx/templates/crm/reporting/index.html.twig).
- The report aggregates estimate, quote, call, and job metrics through [`src/Repository/EstimateRepository.php`](/var/www/pbx/src/Repository/EstimateRepository.php), [`src/Repository/QuoteRepository.php`](/var/www/pbx/src/Repository/QuoteRepository.php), [`src/Repository/CallSessionRepository.php`](/var/www/pbx/src/Repository/CallSessionRepository.php), and [`src/Repository/JobRepository.php`](/var/www/pbx/src/Repository/JobRepository.php).
- The dashboard math is covered in [`tests/Service/CrmReportingDashboardServiceTest.php`](/var/www/pbx/tests/Service/CrmReportingDashboardServiceTest.php).
- The functional coverage for the reporting page lives in [`tests/Functional/CrmTenantIsolationTest.php`](/var/www/pbx/tests/Functional/CrmTenantIsolationTest.php).

## Notes

- 8.1 is intentionally limited to conversation enrichment and suggestion surfacing.
- 8.2 is intentionally limited to deterministic suggestions and review surfaces, not auto-creation of records.
- 8.3 is intentionally limited to reporting dashboards and summary metrics, not forecasting or automation.
- 8.4 is intentionally limited to the transcript-aware search slice here; missed-call workflows, renewal reminders, warranty expiry reminders, and follow-up automations remain open.
