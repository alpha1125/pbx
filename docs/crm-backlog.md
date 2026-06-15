# CRM Backlog

This document converts the CRM phase documents into a backlog structure that can be mapped directly into GitHub issues, milestones, or project-board columns.

Related docs:

- [CRM Roadmap](./crm-roadmap.md)
- [CRM Phase 1](./crm-phase-1.md)
- [CRM Phase 2](./crm-phase-2.md)
- [CRM Phase 3](./crm-phase-3.md)
- [CRM Phase 4](./crm-phase-4.md)
- [CRM Phase 5](./crm-phase-5.md)
- [CRM Phase 6](./crm-phase-6.md)
- [CRM Phase 7](./crm-phase-7.md)
- [CRM Phase 8](./crm-phase-8.md)

## How To Use This Backlog

- Treat each epic as a milestone-sized workstream.
- Treat each deliverable as a candidate issue or issue group.
- The priority labels below are relative:
  - `P0`: blocks safe production use
  - `P1`: core operational value
  - `P2`: important workflow depth
  - `P3`: optimization or expansion

## Milestone A: Tenant Safety and RBAC

Source:

- [CRM Phase 2](./crm-phase-2.md)

### Epic A1: Production-Safe Tenant Resolution

Priority:

- `P0`

Depends on:

- CRM Phase 1 entities and login

Deliverables:

1. Replace dev fallback tenant behavior in production code paths.
2. Resolve active tenant from authenticated membership and selected tenant context.
3. Handle user states:
   - no tenant memberships
   - one tenant membership
   - multiple tenant memberships
4. Add tenant selection persistence in session or profile state.
5. Add guardrails and error UX for unresolved tenant state.

Suggested issues:

- `tenant-resolver-remove-prod-default-fallback`
- `tenant-selector-session-state`
- `tenant-resolution-empty-membership-handling`

### Epic A2: Role Model and Authorization

Priority:

- `P0`

Depends on:

- Epic A1

Deliverables:

1. Define membership-level tenant roles.
2. Add Symfony voters/policies for tenant-scoped CRM entities.
3. Lock transcript, recording, and call detail access by tenant and role.
4. Add tests for tenant isolation on:
   - property access
   - RFQ invitation access
   - estimate/quote/invoice access
   - transcript/recording access

Suggested issues:

- `tenant-role-model`
- `crm-voters-property-contact-equipment`
- `crm-voters-sales-finance-records`
- `pbx-artifact-tenant-access-control`
- `tenant-isolation-functional-tests`

### Epic A3: User and Tenant Administration

Priority:

- `P1`

Depends on:

- Epic A2

Deliverables:

1. Tenant switcher UI for multi-tenant users.
2. User profile management:
   - display name
   - cell phone
   - password change/reset
3. Tenant admin screens for:
   - inviting users
   - creating memberships
   - assigning membership roles
   - setting default tenant

Suggested issues:

- `tenant-switcher-ui`
- `user-profile-management`
- `tenant-membership-admin-ui`
- `user-invitation-flow`

## Milestone B: CRUD Completion and CRM Usability

Source:

- [CRM Phase 2](./crm-phase-2.md)

### Epic B1: Property/Contact/Equipment CRUD

Priority:

- `P1`

Depends on:

- Milestone A

Deliverables:

1. Property create/edit/archive flows.
2. Contact create/edit/archive flows.
3. Property-contact relationship management.
4. Equipment create/edit/archive flows.
5. Form validation and normalization for:
   - phone
   - email
   - postal code
   - province/country

Suggested issues:

- `property-crud-forms`
- `contact-crud-forms`
- `property-contact-link-management`
- `equipment-crud-forms`
- `crm-input-normalization`

### Epic B2: Dedupe and Record Hygiene

Priority:

- `P1`

Depends on:

- Epic B1

Deliverables:

1. Address matching improvements for properties.
2. Contact duplicate detection by phone/email/name.
3. Merge or review UX for near-duplicate records.
4. Soft-delete or archive strategy for core CRM entities.

Suggested issues:

- `property-address-match-improvements`
- `contact-dedupe-rules`
- `crm-duplicate-review-ui`
- `crm-archive-strategy`

### Epic B3: UX Hardening

Priority:

- `P2`

Depends on:

- Epic B1

Deliverables:

1. Pagination across major CRM lists.
2. Reusable form partials/layout patterns.
3. Empty-state and access-denied screens.
4. Better flash and validation error handling.

Suggested issues:

- `crm-pagination`
- `crm-form-partials`
- `crm-empty-state-patterns`
- `crm-flash-and-validation-ux`

## Milestone C: Communication Timeline and PBX/CRM Unification

Source:

- [CRM Phase 3](./crm-phase-3.md)

### Epic C1: Communication Timeline Data Model

Priority:

- `P1`

Depends on:

- Milestone A

Deliverables:

1. Add `CommunicationTimelineItem`.
2. Define timeline item types and linking rules.
3. Add tenant/property/contact/estimate/quote/invoice/call linkage.
4. Add timeline indexes and retrieval queries.

Suggested issues:

- `communication-timeline-entity`
- `communication-timeline-item-types`
- `timeline-query-and-indexing`

### Epic C2: PBX Event Projection Into CRM

Priority:

- `P1`

Depends on:

- Epic C1

Deliverables:

1. Project call lifecycle into timeline.
2. Project recording ready into timeline.
3. Project transcript ready into timeline.
4. Project summary ready into timeline.
5. Ensure replay/idempotency rules for timeline generation.

Suggested issues:

- `timeline-project-call-events`
- `timeline-project-recordings`
- `timeline-project-transcripts`
- `timeline-project-summaries`
- `timeline-idempotency-guards`

### Epic C3: Communication UI

Priority:

- `P1`

Depends on:

- Epic C2

Deliverables:

1. Property timeline UI.
2. Contact timeline UI.
3. Embedded transcript message view in CRM.
4. Manual note entry.
5. Call dispositions and filters.

Suggested issues:

- `property-timeline-ui`
- `contact-timeline-ui`
- `embed-transcript-view-in-crm`
- `manual-note-entry`
- `call-dispositions-and-filters`

### Epic C4: Communication Search

Priority:

- `P2`

Depends on:

- Epic C3

Deliverables:

1. Search by phone number, address, customer, quote/invoice number.
2. Search transcript text.
3. Define result surfaces and filters.

Suggested issues:

- `crm-global-search-shell`
- `transcript-text-search`
- `communication-search-results-ui`

## Milestone D: Sales Workflow Completion

Source:

- [CRM Phase 4](./crm-phase-4.md)

### Epic D1: Estimate Editor Completion

Priority:

- `P1`

Depends on:

- Milestone B

Deliverables:

1. Edit/delete/reorder line items.
2. Add scope sections and exclusions.
3. Add duplicate/clone estimate workflow.
4. Add pricing template hooks.

Suggested issues:

- `estimate-line-item-editing`
- `estimate-sections-and-exclusions`
- `estimate-clone-workflow`
- `estimate-pricing-template-hooks`

### Epic D2: Quote Lifecycle and Delivery

Priority:

- `P1`

Depends on:

- Epic D1

Deliverables:

1. Quote revision/version model.
2. Internal review and send states.
3. Quote valid-until and expiry handling.
4. Customer-facing accept/decline flow.
5. Send/view tracking.

Suggested issues:

- `quote-revision-model`
- `quote-review-and-send-state`
- `quote-expiry-processing`
- `quote-accept-decline-flow`
- `quote-delivery-tracking`

### Epic D3: Quote Rendering and Commercial Rules

Priority:

- `P2`

Depends on:

- Epic D2

Deliverables:

1. Branded HTML quote view.
2. Printable/PDF output.
3. Tenant tax configuration.
4. Discount and deposit support.
5. Quote numbering rules per tenant.

Suggested issues:

- `branded-quote-html`
- `quote-pdf-generation`
- `tenant-tax-config`
- `quote-discounts-and-deposits`
- `quote-numbering-rules`

## Milestone E: Billing and Dispatch

Source:

- [CRM Phase 5](./crm-phase-5.md)
- [CRM Phase 6](./crm-phase-6.md)

### Epic E1: Payments and Invoice Maturity

Priority:

- `P1`

Depends on:

- Milestone D

Deliverables:

1. Invoice editor completion.
2. Payment entity and allocation logic.
3. Invoice aging and overdue status flows.
4. Branded invoice output and sending.

Suggested issues:

- `invoice-editor-completion`
- `payment-entity-and-allocation`
- `invoice-aging-workflow`
- `invoice-pdf-and-send`

### Epic E2: Accounting Integration Boundary

Priority:

- `P2`

Depends on:

- Epic E1

Deliverables:

1. QuickBooks/Xero sync boundary design.
2. External IDs and sync status storage.
3. Export error logging and retry states.

Suggested issues:

- `accounting-sync-boundary-model`
- `invoice-external-id-and-sync-state`
- `accounting-export-error-handling`

### Epic E3: Jobs, Tasks, and Dispatch

Priority:

- `P1`

Depends on:

- Milestone D

Deliverables:

1. `Job` and `Task` entities.
2. Assignment and scheduling states.
3. Dispatch list/dashboard.
4. Technician work queue.
5. Field notes and service history updates.

Suggested issues:

- `job-and-task-entities`
- `job-assignment-and-scheduling`
- `dispatch-dashboard`
- `technician-work-queue`
- `field-notes-and-service-history`

### Epic E4: Follow-Up and Reminder Workflow

Priority:

- `P2`

Depends on:

- Epic E3

Deliverables:

1. Post-job follow-up tasks.
2. Service reminders.
3. Unresolved-issue workflows.

Suggested issues:

- `post-job-follow-up-tasks`
- `service-reminder-workflow`
- `unresolved-issue-tracking`

## Milestone F: Procurement Intelligence and Automation

Source:

- [CRM Phase 7](./crm-phase-7.md)
- [CRM Phase 8](./crm-phase-8.md)

### Epic F1: RFQ Intake and Distribution Operations

Priority:

- `P1`

Depends on:

- Milestone B

Deliverables:

1. RFQ intake UX/API.
2. Vendor targeting and invitation orchestration.
3. RFQ expiry and reminder flows.
4. Platform operator dashboards.

Suggested issues:

- `rfq-intake-ui-and-api`
- `rfq-vendor-targeting`
- `rfq-expiry-and-reminders`
- `rfq-operator-dashboard`

### Epic F2: Vendor Analytics and Comparison

Priority:

- `P2`

Depends on:

- Epic F1

Deliverables:

1. Vendor response metrics.
2. RFQ comparison views.
3. Tenant notification preferences for RFQs.

Suggested issues:

- `vendor-response-metrics`
- `rfq-vendor-comparison-view`
- `tenant-rfq-notification-preferences`

### Epic F3: AI-Assisted CRM Enrichment

Priority:

- `P2`

Depends on:

- Milestone C
- preferably Milestone D

Deliverables:

1. AI summaries into notes/timeline.
2. Suggested property/contact matching.
3. Suggested next steps and dispositions.
4. Suggested estimate line items.

Suggested issues:

- `ai-summary-to-crm-note`
- `ai-suggest-contact-property-match`
- `ai-suggest-call-disposition`
- `ai-suggest-estimate-line-items`

### Epic F4: Search, Reporting, and Automation

Priority:

- `P2`

Depends on:

- Epic F3

Deliverables:

1. Full-text transcript and CRM search.
2. Revenue and conversion dashboards.
3. Missed-call and follow-up automations.
4. Warranty/renewal reminders.

Suggested issues:

- `full-text-transcript-search`
- `crm-revenue-and-conversion-dashboards`
- `missed-call-automation`
- `warranty-and-renewal-reminders`

## Cross-Cutting Backlog

These should be tracked separately because they span multiple milestones.

### X1: Data Quality

Priority:

- `P1`

Deliverables:

1. Phone normalization strategy.
2. Address normalization/geocoding plan.
3. Merge and dedupe flows.
4. CSV import/export strategy.

### X2: Security and Compliance

Priority:

- `P0`

Deliverables:

1. Transcript/recording access policy.
2. Retention and purge rules.
3. PIPEDA/privacy review items.
4. Secret-management and environment-hardening checklist.

### X3: Performance and Operability

Priority:

- `P1`

Deliverables:

1. Pagination baseline.
2. Background job strategy for heavy CRM operations.
3. Workflow observability and error logging.
4. Retry/recovery tooling.

### X4: Testing

Priority:

- `P1`

Deliverables:

1. Tenant isolation functional tests.
2. Multi-step workflow service tests.
3. Migration smoke checks.
4. Fixture strategy for dev/test environments.

## Suggested Project Board Columns

1. `Backlog`
2. `Ready`
3. `In Progress`
4. `Blocked`
5. `Review`
6. `Done`

## Suggested Labels

- `crm`
- `crm-phase-2`
- `crm-phase-3`
- `crm-phase-4`
- `crm-phase-5`
- `crm-phase-6`
- `crm-phase-7`
- `crm-phase-8`
- `pbx-integration`
- `tenant-safety`
- `security`
- `billing`
- `dispatch`
- `procurement`
- `ai`
- `reporting`
- `tech-debt`
