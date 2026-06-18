# CRM Phase 10 Completion State

## Current Sub-Phase
- Last completed sub-phase: **10J — Customer Journey Dashboard**
- Current recommended next sub-phase: **None (Phase 10 complete)**

## Completed
- [x] **10A — Customer Health Engine**: Deterministic health scoring across equipment, service history, calls, invoices, and warranty status. Property page display card + CLI command.
- [x] **10B — Retention Opportunity Engine**: Deterministic retention opportunity model/service, tenant-scoped repository, CRM list page, property-page visibility, manual generation/status actions, migration, and focused tests.
- [x] **10C — Maintenance Plan Engine**: Two entities (MaintenancePlan, PropertyMaintenancePlan), CRUD controller with 5 routes, tenant-scoped admin panel, property assignment/cancellation UI on the property page, migration, and entity tests.
- [x] **10D — Campaign Engine**: Tenant-scoped campaign entity, repository, CRUD controller, campaign list/form UI, migration, and focused tests. Planning objects only; no email, SMS, or automated calling.
- [x] **10G — Homeowner Timeline**: Read-only property lifecycle timeline that aggregates RFQs, estimates, quotes, jobs, equipment installs, service visits, invoices, calls, maintenance plans, retention opportunities, and sentiment entries with type filtering/search.
- [x] **10H — Next Best Action Engine**: Tenant-scoped, human-approved recommendation entity/service/controller for book/call/replace/offer/inspect/follow-up/invoice actions on the property page. Manual approve/dismiss/complete only; no automatic execution.
- [x] **10I — Revenue Opportunity Dashboard**: Tenant-scoped reporting dashboard extension that groups open retention opportunities into dormant, maintenance, replacement, warranty, and overdue-invoice cards with linked property detail lists and invoice-backed estimated value where available.
- [x] **10J — Customer Journey Dashboard**: Property-anchored lifecycle dashboard that visualizes RFQ, estimate, quote, install, invoice, service, maintenance, renewal, and replacement stages with links back to the underlying CRM records.

## Files Changed

### NEW (Phase 10B)
- **src/Service/PropertyHealthCalculatorInterface.php** - Small abstraction for health scoring so 10B can depend on a testable contract without coupling to the concrete final service.
- **src/Entity/RetentionOpportunity.php** - Tenant-scoped retention opportunity entity. Fields: tenant, property, optional contact, optional equipment, opportunityType, status, sourceKey, detectedReason, detectedAt. Includes type/status constants and labels.
- **src/Repository/RetentionOpportunityRepository.php** - Tenant/property-scoped queries: findByTenantOrdered, findOpenByTenant, findByTenantAndProperty, findOpenByTenantAndProperty, findOpenByTenantPropertyTypeAndSourceKey, findOneByTenantAndId, countOpenByTenant.
- **src/Service/RetentionOpportunityEngineService.php** - Deterministic generator for no_recent_service, old_equipment, no_recent_calls, warranty_nearing_expiration, dormant_customer, open_invoice, and maintenance_plan_missing opportunities. Upserts only open opportunities by tenant/property/type/source.
- **src/Controller/Crm/RetentionOpportunityController.php** - CRM list page, manual generation action, and status update action (reviewed/dismissed/converted). Tenant-scoped and CSRF-protected.
- **templates/crm/retention_opportunity/index.html.twig** - CRM list page with tenant summary, generate button, and per-opportunity status actions.
- **migrations/Version20260617110000.php** - Creates retention_opportunity table with tenant/property/contact/equipment foreign keys, status/source indexes, and timestamp columns.

### NEW (Phase 10D)
- **src/Entity/Campaign.php** - Tenant-scoped campaign planning entity. Fields: tenant, name, campaignType, audienceDescription, scheduledDate, status, notes. Includes type/status constants, labels, and choice helpers.
- **src/Repository/CampaignRepository.php** - Tenant-scoped queries: findByTenantOrdered, countByTenant, findOneByTenantAndId.
- **src/Controller/Crm/CampaignController.php** - CRM campaign list page plus create/edit actions. Tenant-scoped and CSRF-protected.
- **templates/crm/campaign/index.html.twig** - Campaign list page with status badges and edit links.
- **templates/crm/campaign/form.html.twig** - Campaign create/edit form with type, audience, schedule, status, and notes fields.
- **migrations/Version20260617120000.php** - Creates campaign table with tenant foreign key, lifecycle/status indexes, and timestamp columns.

### NEW (Phase 10E)
- **src/Entity/CsrPlaybookAttachment.php** - Tenant-scoped playbook attachment entity. Fields: tenant, optional property/contact/retention-opportunity context, playbookType. Includes fixed playbook type constants and labels.
- **src/Repository/CsrPlaybookAttachmentRepository.php** - Tenant/context-scoped lookups for property, contact, opportunity, and exact context/type deduplication.
- **src/Service/CsrPlaybookEngineService.php** - Fixed CSR playbook catalog for maintenance_offer, warranty_discussion, replacement_discussion, overdue_invoice_discussion, and dormant_customer_outreach, plus opportunity-based recommendation ordering.
- **src/Controller/Crm/CsrPlaybookController.php** - Tenant-scoped attach action for property/contact/opportunity contexts. CSRF-protected and additive only.
- **templates/crm/property/show.html.twig** - Added CSR playbook accordion with fixed template content, property attach action, and retention-opportunity attach actions.
- **templates/crm/contact/form.html.twig** - Added CSR playbook accordion for contact-specific attachment during contact edit.
- **migrations/Version20260617130000.php** - Creates csr_playbook_attachment with tenant/property/contact/retention-opportunity foreign keys and lookup indexes.
- **src/Controller/Crm/PropertyController.php** - Added playbook catalog/attachment data to the property page and fixed the maintenance-plan repository wiring that the page depends on.
- **src/Controller/Crm/ContactController.php** - Added contact-playbook data to the contact edit page.
- **src/Service/CustomerHealthCalculatorService.php** - Fixed a property-page runtime query bug by selecting scalar IDs correctly in the unresolved-issues health factor query.

### MODIFIED (Phase 10B integration)
- **src/Service/CustomerHealthCalculatorService.php** - Implements `PropertyHealthCalculatorInterface`.
- **src/Repository/JobRepository.php** - Added latest completed-at lookup by property for retention generation.
- **src/Repository/CallSessionRepository.php** - Added latest updated-at lookup by property for retention generation.
- **src/Controller/Crm/PropertyController.php** - Injects retention-opportunity repository and passes open opportunities to the property page.
- **src/Controller/Crm/PropertyController.php** - Added property-level maintenance-plan assign/cancel actions and passes available plans to the property page.
- **templates/base.html.twig** - Added CRM nav link for Opportunities.
- **templates/crm/property/show.html.twig** - Added Assigned Maintenance Plans controls and Retention Opportunities card with per-property generation button and open-opportunity list.
- **tests/Service/CustomerHealthCalculatorServiceTest.php** - Switched health-service setup to stubs so PHPUnit 13 stays clean.

### MODIFIED (Phase 10D integration)
- **templates/base.html.twig** - Added CRM nav link for Campaigns.
- **docs/crm-phase-10-completion-state.md** - Updated to reflect the current phase-10 implementation status and next task.

### MODIFIED (Phase 10E integration)
- **templates/crm/property/show.html.twig** - Added fixed CSR playbook templates and attachment forms in the property/customer context.
- **templates/crm/contact/form.html.twig** - Added fixed CSR playbook templates and contact attachment forms.
- **src/Controller/Crm/PropertyController.php** - Injects playbook catalog/attachment data and preserves existing property-page behavior.
- **src/Controller/Crm/ContactController.php** - Injects playbook catalog/attachment data for contact editing.
- **src/Service/CustomerHealthCalculatorService.php** - Small runtime fix required to keep the property page rendering reliably.

### NEW (Phase 10F)
- **src/Entity/CustomerSentimentHistory.php** - Tenant-scoped sentiment history entity. Fields: tenant, property, optional contact, optional call session, sentiment, note, recordedBy, recordedAt. Includes fixed sentiment constants and labels.
- **src/Repository/CustomerSentimentHistoryRepository.php** - Tenant/property-scoped history lookup with joined contact, call session, and recorder data for property-page display.
- **src/Controller/Crm/PropertyController.php** - Added sentiment display data and a property-scoped sentiment entry action.
- **templates/crm/property/show.html.twig** - Added customer sentiment card, manual entry form, and property-scoped sentiment history list.
- **migrations/Version20260617140000.php** - Creates customer_sentiment_history with tenant/property/contact/call session/recorded-by foreign keys and lookup indexes.

### NEW (Phase 10G)
- **src/Service/PropertyLifecycleTimelineService.php** - Read-only aggregation service that gathers lifecycle items from RFQ, estimate, quote, job, equipment install, service visit, invoice, call session, maintenance plan, retention opportunity, and sentiment sources, then filters/searches the combined list in memory.

### MODIFIED (Phase 10G integration)
- **src/Controller/Crm/PropertyController.php** - Injects the lifecycle aggregation service and passes lifecycle timeline data to the property page using separate `lifecycleType` and `lifecycleQ` query params.
- **templates/crm/property/show.html.twig** - Added the Homeowner Timeline card with type filtering/search, sorted item rendering, and contextual open links where available.

### NEW (Phase 10H)
- **src/Entity/NextBestActionSuggestion.php** - Tenant-scoped next-best-action entity. Fields: tenant, property, optional opportunity, suggestionType, sourceKey, reason, confidence, status. Includes fixed suggestion type/confidence/status constants and labels.
- **src/Repository/NextBestActionSuggestionRepository.php** - Tenant/property-scoped lookups for ordered listing, id lookup, and deterministic type/source-key matching.
- **src/Service/NextBestActionEngineService.php** - Deterministic next-best-action engine that converts health, equipment, service, invoice, call, maintenance-plan, and retention signals into manual suggestions without executing any outreach.
- **src/Controller/Crm/NextBestActionController.php** - Property-scoped generate/approve/dismiss/complete actions with CSRF protection and tenant checks. Human approval remains mandatory.
- **migrations/Version20260617150000.php** - Creates next_best_action_suggestion with tenant/property/opportunity foreign keys, status/type indexes, and deterministic unique lookup constraints.

### MODIFIED (Phase 10H integration)
- **src/Service/CustomerHealthCalculatorService.php** - Fixed the warranty-expiry day calculation for PHP 8.5 so the health score remains usable by the next-best-action engine.
- **src/Controller/Crm/PropertyController.php** - Injects next-best-action suggestions into the property page.
- **templates/crm/property/show.html.twig** - Added the Next Best Actions card with generate, approve, dismiss, and complete controls plus stable selectors for the new workflow.

### NEW (Phase 10I)
- **src/Repository/InvoiceRepository.php** - Added tenant/property balance aggregation for open invoices so the reporting dashboard can surface invoice-backed estimated value.
- **src/Service/CrmReportingDashboardService.php** - Added tenant-scoped revenue-opportunity card aggregation for dormant, maintenance, replacement, warranty, and overdue-invoice retention signals.
- **templates/crm/reporting/index.html.twig** - Added the Revenue Opportunity Dashboard cards and property-linked detail lists to the existing reporting page.
- **tests/Service/CrmReportingDashboardServiceTest.php** - Extended service coverage for grouped revenue cards, tenant-scoped opportunity counts, and invoice-backed value aggregation.
- **tests/Functional/CrmReportingDashboardWorkflowTest.php** - Reporting-page smoke test for the new revenue dashboard section and its rendered property links/value output.

### NEW (Phase 10J)
- **src/Service/CustomerJourneyDashboardService.php** - Property-anchored lifecycle stage builder for the nine-step journey dashboard, including source-record links and anchor fallbacks.
- **src/Controller/Crm/PropertyController.php** - Injects the journey dashboard data into the property view.
- **templates/crm/property/show.html.twig** - Added the Customer Journey Dashboard card with stage status badges and direct record/section links.
- **tests/Service/CustomerJourneyDashboardServiceTest.php** - Service coverage for stage ordering, completion status, current-stage selection, and link targets.
- **tests/Functional/CrmCustomerJourneyWorkflowTest.php** - Property-page smoke test that verifies the journey card renders and stage links resolve.

### NEW (Tests)
- **tests/Service/NextBestActionEngineServiceTest.php** - Integration coverage for deterministic suggestion generation and the full seven suggestion types.
- **tests/Functional/CrmNextBestActionWorkflowTest.php** - Property-page workflow for generating suggestions and manually approving a recommendation.

### NEW (Tests)
- **tests/Entity/RetentionOpportunityTest.php** - Entity constants, labels, status transitions, and normalization checks.
- **tests/Service/RetentionOpportunityEngineServiceTest.php** - Deterministic generation and open-opportunity reuse behavior.
- **tests/Entity/CampaignTest.php** - Campaign defaults, normalization, labels, and key coverage.
- **tests/Functional/CrmCampaignWorkflowTest.php** - Campaign list/create/edit smoke test with tenant-scoped persistence.
- **tests/Entity/CsrPlaybookAttachmentTest.php** - Playbook attachment entity coverage for context linking, labels, and type normalization.
- **tests/Service/CsrPlaybookEngineServiceTest.php** - Fixed template coverage and recommendation ordering.
- **tests/Functional/CrmCsrPlaybookWorkflowTest.php** - Property/contact/opportunity attach workflow and visibility smoke test.
- **tests/Entity/CustomerSentimentHistoryTest.php** - Sentiment labels, normalization, and optional contact/call linkage checks.
- **tests/Functional/CrmCustomerSentimentWorkflowTest.php** - Property-page sentiment entry and redisplay smoke test.
- **tests/Service/PropertyLifecycleTimelineServiceTest.php** - Integration coverage for lifecycle aggregation, type filtering, and search matching.
- **tests/Functional/CrmPropertyLifecycleTimelineWorkflowTest.php** - Property-page smoke test for lifecycle card rendering and invoice filtering/search.

## Migrations Added
- **migrations/Version20260617110000.php** - Adds `retention_opportunity` with tenant/property/contact/equipment foreign keys and lookup indexes.
- **migrations/Version20260617120000.php** - Adds `campaign` with tenant foreign key and lifecycle/status indexes.
- **migrations/Version20260617130000.php** - Adds `csr_playbook_attachment` with tenant/property/contact/retention-opportunity foreign keys and lookup indexes.
- **migrations/Version20260617140000.php** - Adds `customer_sentiment_history` with tenant/property/contact/call session/recorded-by foreign keys and lookup indexes.
- **migrations/Version20260617150000.php** - Adds `next_best_action_suggestion` with tenant/property/opportunity foreign keys, type/status indexes, and a deterministic tenant/property/type/source-key unique constraint.

## Routes Added
| Route | HTTP | Path | Purpose |
|-------|------|------|---------|
| crm_retention_opportunity_index | GET | /crm/retention-opportunities | List tenant retention opportunities |
| crm_retention_opportunity_generate | POST | /crm/retention-opportunities/generate | Generate opportunities for one property or the whole tenant |
| crm_retention_opportunity_update_status | POST | /crm/retention-opportunities/{id}/status/{status} | Mark an opportunity reviewed, dismissed, or converted |
| crm_property_maintenance_plan_assign | POST | /crm/properties/{id}/maintenance-plans | Assign a maintenance plan to a property |
| crm_property_maintenance_plan_cancel | POST | /crm/properties/{id}/maintenance-plans/{assignmentId}/cancel | Cancel a maintenance plan assignment |
| crm_campaign_index | GET | /crm/campaigns | List tenant campaigns |
| crm_campaign_new | GET, POST | /crm/campaigns/new | Create a campaign |
| crm_campaign_edit | GET, POST | /crm/campaigns/{id}/edit | Edit a campaign |
| crm_csr_playbook_attach | POST | /crm/playbooks/attach | Attach a fixed CSR playbook to a property, contact, or retention opportunity |
| crm_property_sentiment_add | POST | /crm/properties/{id}/sentiments | Record manual customer sentiment for a property |
| crm_property_next_best_action_generate | POST | /crm/properties/{id}/next-best-actions/generate | Generate deterministic next-best-action suggestions for a property |
| crm_property_next_best_action_update_status | POST | /crm/properties/{id}/next-best-actions/{suggestionId}/status/{status} | Update a next-best-action suggestion status to approved, dismissed, or completed |

Phase 10G did not add new routes. The homeowner timeline reuses `crm_property_show` with `lifecycleType` and `lifecycleQ` query parameters.
Phase 10I did not add new routes. The revenue opportunity dashboard reuses `crm_reporting_dashboard`.
Phase 10J did not add new routes. The customer journey dashboard reuses `crm_property_show`.

## Services Added
- **PropertyHealthCalculatorInterface** - Lightweight contract used by the retention engine.
- **RetentionOpportunityEngineService** - Deterministic opportunity generator with idempotent open-opportunity reuse.
- **CsrPlaybookEngineService** - Fixed CSR playbook catalog and opportunity-based recommendation ordering.
- **PropertyLifecycleTimelineService** - Read-only aggregation service for the homeowner lifecycle timeline.
- **NextBestActionEngineService** - Deterministic next-best-action generator that turns tenant/property health, service, invoice, call, maintenance, and retention signals into manual suggestions only.
- **CrmReportingDashboardService** - Extended reporting service that now aggregates tenant revenue-opportunity cards from retention opportunities and open invoice balances.
- **CustomerJourneyDashboardService** - Property lifecycle stage builder that maps the sequential customer journey back to CRM source records.

## Tests Added
| File | Tests | Assertions |
|------|-------|------------|
| tests/Entity/RetentionOpportunityTest.php | 4 | 10 |
| tests/Service/RetentionOpportunityEngineServiceTest.php | 2 | 16 |
| tests/Entity/CampaignTest.php | 3 | 17 |
| tests/Functional/CrmCampaignWorkflowTest.php | 1 | 20 |
| tests/Entity/CsrPlaybookAttachmentTest.php | 2 | 11 |
| tests/Service/CsrPlaybookEngineServiceTest.php | 2 | 9 |
| tests/Functional/CrmCsrPlaybookWorkflowTest.php | 1 | 33 |
| tests/Entity/CustomerSentimentHistoryTest.php | 2 | 12 |
| tests/Functional/CrmCustomerSentimentWorkflowTest.php | 1 | 17 |
| tests/Service/NextBestActionEngineServiceTest.php | 1 | 12 |
| tests/Functional/CrmNextBestActionWorkflowTest.php | 1 | 21 |
| tests/Service/CrmReportingDashboardServiceTest.php | 1 | 91 |
| tests/Functional/CrmReportingDashboardWorkflowTest.php | 1 | 17 |
| tests/Service/CustomerJourneyDashboardServiceTest.php | 1 | 11 |
| tests/Functional/CrmCustomerJourneyWorkflowTest.php | 1 | 10 |
| **Total** | **26** | **320** |

## Known Gaps
- Opportunity generation is manual today through the CRM page or property-page button; there is no scheduled/background generator.
- Retention opportunities are visible and manageable in the CRM, but there is no bulk workflow beyond per-item status changes.
- No messaging, task auto-creation, or AI execution paths were added.
- Campaigns are planning records only; there is no email, SMS, or automated calling execution flow.
- CSR playbooks are fixed templates only. There is no standalone playbook administration UI, no detach workflow, and no versioning for the stored attachment records.
- Sentiment is manual only for now. There is no automatic sentiment extraction from transcripts or summary payloads in this phase.
- Homeowner Timeline is read-only and in-memory aggregated. There is no background indexing job or separate denormalized timeline table in this phase.
- Timeline retrieval is capped by the service limit parameter; there is no dedicated pagination UI yet.
- Revenue Opportunity Dashboard only estimates value where an open invoice balance exists; the other cards are count-led until a pricing model is added.
- Revenue dashboards remain read-only and do not schedule outreach or auto-create work items.

## Risk Notes
- Tenant isolation is preserved by tenant-scoped repositories, tenant checks in the controller, and property-linked lookups for generation.
- Generation is deterministic and idempotent for open opportunities because the engine reuses open rows for the same tenant/property/type/source key.
- The retention engine depends on existing health scoring and uses a thin interface so the concrete health service stays final and testable.
- The migration uses manual SQL and cascading foreign keys to prevent orphaned retention rows.
- Campaigns are tenant-scoped planning objects and only use create/edit forms plus status selection.
- CSR playbooks remain fixed and non-AI. Attachments are stored only as contextual links so the scripts themselves stay static in code.
- Sentiment entries are tenant-scoped, property-anchored, and only created through an explicit CSR action on the property page.
- Revenue opportunity aggregation is tenant-scoped and reads from open retention opportunities plus open invoice balances only.

## Next Recommended Task
- **No remaining Phase 10 tasks**: start the next planning/implementation phase.

---

### Phase 10A Reference
- Service: CustomerHealthCalculatorService with 6 evaluation factors, 5 health categories (healthy >=80, needs_attention >=70, at_risk >=50, dormant >=20, lost <20).
- Property show page includes "Customer Health" card with score/100 badge and factor breakdown.
- CLI command: app:crm:health (table/json output, tenant/property filter options).
- Tests: tests/Service/CustomerHealthCalculatorServiceTest.php (4 tests).
