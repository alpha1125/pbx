# CRM Phase 7

## Goal

Deepen the platform side where homeowner RFQs are distributed to multiple HVAC tenants.

## Why This Phase Exists

Phase 1 established the RFQ bridge into tenant CRM, but not the broader platform operations needed for Trusted Procurement.

## Dependencies

- Phase 2 tenant and role safety
- Phase 4 quote workflow maturity
- preferably Phase 3/8 analytics groundwork

## Work Packages

### 7A RFQ Intake Domain Core

- Goal: Establish a robust RFQ entity and core intake logic.
- Scope: RFQ entity hardening, homeowner/admin intake, validation, duplicate detection.
- Out of Scope: Attachments/media handling.
- Acceptance Criteria: RFQ entity supports core fields and validation; intake processes handle duplicates; no attachments yet.
- Suggested Codex Goal: Implement core RFQ domain logic with validation and duplicate detection.

### 7B RFQ Intake UI

- Goal: Provide user interfaces for RFQ management.
- Scope: List, detail, create, and edit screens; property/contact matching; tenant-safe boundaries.
- Out of Scope: Attachment UI.
- Acceptance Criteria: Users can create and manage RFQs with property/contact matching; UI respects tenant isolation.
- Suggested Codex Goal: Build RFQ intake and management UI with tenant safety.

### 7C Vendor Eligibility and Targeting

- Goal: Define and implement vendor selection criteria.
- Scope: HVAC tenant selection, eligibility rules, service area filtering.
- Out of Scope: Notifications.
- Acceptance Criteria: System selects eligible tenants based on rules and service areas.
- Suggested Codex Goal: Implement vendor eligibility and targeting logic.

### 7D RFQ Invitation Orchestration

- Goal: Manage RFQ invitation lifecycle.
- Scope: Create invitations, handle invitation states, expiry rules, reminder metadata, audit logging.
- Out of Scope: Notification delivery.
- Acceptance Criteria: Invitations created and transitioned through states; expiry and reminders tracked; audit logs maintained.
- Suggested Codex Goal: Build RFQ invitation orchestration with state management and auditing.

### 7E Operator Dashboard

- Goal: Provide operational visibility for platform staff.
- Scope: RFQ operational dashboard, invitation status dashboard, comparison views, pagination, filtering.
- Out of Scope: Vendor portal.
- Acceptance Criteria: Staff can view and filter RFQs and invitations; comparison views available; pagination supported.
- Suggested Codex Goal: Develop operator dashboards for RFQ and invitation monitoring.

### 7F Vendor Portal Experience

- Goal: Enable vendors to manage RFQ invitations.
- Scope: Vendor queue, accept/decline invitations, quote progress visibility, notification preferences.
- Out of Scope: Vendor analytics.
- Acceptance Criteria: Vendors can accept/decline invitations and track quote progress; preferences configurable.
- Suggested Codex Goal: Create vendor portal features for invitation and quote management.

### 7G Vendor Analytics

- Goal: Measure vendor engagement and performance.
- Scope: Open rate, accept rate, quote rate, response time, completion metrics.
- Out of Scope: Procurement intelligence.
- Acceptance Criteria: Analytics data collected and reportable for vendor metrics.
- Suggested Codex Goal: Implement vendor analytics tracking and reporting.

### 7H Procurement Intelligence

- Goal: Lay groundwork for procurement insights.
- Scope: Recommendation surfaces, vendor ranking placeholders, trend reporting.
- Out of Scope: AI automation features.
- Acceptance Criteria: Basic procurement intelligence views available without AI automation.
- Suggested Codex Goal: Build procurement intelligence features without AI.

## Non-Negotiable Rules

- Do not implement all of Phase 7 in one pass.
- Keep platform-global concerns separate from tenant CRM concerns.
- Preserve tenant isolation.
- Prefer additive changes.
- Update crm-phase-7-completion-state.md after every sub-phase.
- Do not build AI procurement features yet.
- Do not build homeowner self-service portals yet.

## Completion-State File

Each sub-phase must update `crm-phase-7-completion-state.md` following the patterns established in Phases 5 and 6, tracking progress and acceptance criteria.

## Recommended Implementation Order

1. 7A RFQ Intake Domain Core  
2. 7B RFQ Intake UI  
3. 7C Vendor Eligibility and Targeting  
4. 7D RFQ Invitation Orchestration  
5. 7E Operator Dashboard  
6. 7F Vendor Portal Experience  
7. 7G Vendor Analytics  
8. 7H Procurement Intelligence  

## Done Criteria

RFQs can be created, targeted, distributed, tracked, measured, and analyzed without AI automation.
