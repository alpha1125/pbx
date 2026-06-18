CRM Phase 10 — Qwen3.6 Implementation Plan

Operating Rules for Qwen3.6

Each sub-phase must be implemented independently.

Do not infer missing architecture. Inspect the existing Symfony codebase first, then follow existing conventions.

Each run must:

1. Keep the application working.
2. Preserve tenant isolation.
3. Preserve property-first CRM design.
4. Prefer additive changes.
5. Avoid large refactors.
6. Avoid AI/autonomous action execution.
7. Add deterministic services first, UI second.
8. Add tests where practical.
9. Update crm-phase-10-completion-state.md.

Do not implement more than one sub-phase per run.

⸻

10A — Customer Health Engine

Goal

Add deterministic property/customer health scoring.

Implement

Create a service that calculates health for a property using existing CRM data.

Inputs:

* equipment age
* days since last completed job/service
* days since last call
* unresolved issues
* open invoices
* warranty status
* maintenance plan status, if already present
* sentiment score, if already present

Health categories:

* healthy
* needs_attention
* at_risk
* dormant
* lost

Rules

Use simple deterministic thresholds. Put thresholds in constants or config-like service properties.

Do not use AI.

Do not create outreach actions.

Do not mutate customer records during calculation unless creating a dedicated health snapshot entity is clearly useful.

Acceptance

* Health can be calculated for one property.
* Health is tenant-scoped.
* Health category is deterministic.
* Basic UI or admin display exists.
* Completion state file updated.

⸻

10B — Retention Opportunity Engine

Goal

Generate deterministic retention opportunities.

Depends On

10A.

Implement

Create retention opportunity model/service.

Opportunity types:

* no_recent_service
* old_equipment
* no_recent_calls
* warranty_nearing_expiration
* dormant_customer
* open_invoice
* maintenance_plan_missing

Each opportunity must be linked to:

* tenant
* property
* optional contact
* optional equipment
* opportunity type
* status
* detected reason
* detected date

Statuses:

* open
* reviewed
* dismissed
* converted

Rules

Generation must be idempotent.

Do not create duplicate open opportunities for the same tenant/property/type/source.

Do not send messages.

Do not auto-create tasks unless existing CRM patterns require it.

Acceptance

* Opportunities can be generated.
* Opportunities are visible in CRM.
* Tenant isolation preserved.
* Completion state file updated.

⸻

10C — Maintenance Plan Engine

Goal

Add maintenance plans assignable to properties.

Implement

Entities:

* MaintenancePlan
* PropertyMaintenancePlan or equivalent assignment entity

Plan types:

* Bronze
* Silver
* Gold

Track:

* visit frequency
* discount percentage
* priority scheduling flag
* included services
* active/inactive status
* start date
* renewal date
* cancellation date

Rules

No payment subscriptions.

No billing automation.

Assignment must be tenant-scoped.

Acceptance

* Plans can be created or seeded.
* Plans can be assigned to properties.
* Assigned plans are visible on property page.
* Completion state file updated.

⸻

10D — Campaign Engine

Goal

Create reusable outreach campaign records.

Implement

Campaign types:

* spring_ac_tune_up
* fall_furnace_inspection
* filter_replacement
* warranty_reminder
* maintenance_renewal

Track:

* tenant
* name
* type
* audience description
* scheduled date
* status
* notes

Statuses:

* draft
* scheduled
* approved
* completed
* cancelled

Rules

Do not send email.

Do not send SMS.

Do not trigger automated phone calls.

Campaigns are planning objects only.

Acceptance

* Campaigns can be created and managed.
* Campaigns are tenant-scoped.
* Completion state file updated.

⸻

10E — CSR Playbook Engine

Goal

Provide fixed CSR playbooks for guided conversations.

Implement

Playbook types:

* maintenance_offer
* warranty_discussion
* replacement_discussion
* overdue_invoice_discussion
* dormant_customer_outreach

Each playbook should include:

* title
* purpose
* opening prompt
* qualification questions
* objection handling notes
* suggested next steps
* compliance notes

Attach playbooks to:

* property
* contact
* retention opportunity

Rules

No AI-generated scripts.

Use fixed templates.

CSR can view playbook during call/customer page.

Acceptance

* Playbooks visible from property/customer context.
* Playbooks can be associated with opportunities.
* Completion state file updated.

⸻

10F — Customer Sentiment Engine

Goal

Track sentiment history.

Implement

Sentiment values:

* positive
* neutral
* negative
* frustrated
* price_sensitive

Create sentiment history entity linked to:

* tenant
* property
* optional contact
* optional call
* sentiment
* note
* recorded by user
* recorded at

Rules

No autonomous decisions.

No AI sentiment unless existing transcript summary data already provides it.

Manual entry is acceptable.

Acceptance

* Sentiment can be stored.
* Sentiment history visible on property page.
* Completion state file updated.

⸻

10G — Homeowner Timeline

Goal

Create a searchable property lifecycle timeline.

Implement

Timeline should aggregate existing records:

* RFQs
* estimates
* quotes
* jobs
* equipment installs
* service visits
* invoices
* calls
* maintenance plans
* retention opportunities
* sentiment entries

Rules

Property remains anchor.

Prefer read-only aggregation service.

Do not duplicate source records.

Acceptance

* Timeline visible on property page.
* Timeline can filter/search by type if practical.
* Completion state file updated.

⸻

10H — Next Best Action Engine

Goal

Surface human-approved recommendations.

Implement

Suggestion types:

* book_maintenance
* call_customer
* replace_equipment
* offer_maintenance_plan
* inspect_system
* schedule_follow_up
* review_overdue_invoice

Each suggestion must have:

* tenant
* property
* optional opportunity
* type
* reason
* confidence label: low, medium, high
* status

Statuses:

* suggested
* approved
* dismissed
* completed

Rules

AI may suggest only if wired later.

No automatic execution.

Approval is required before action.

Acceptance

* Suggestions visible.
* User can approve/dismiss.
* Approval does not automatically call/email/SMS.
* Completion state file updated.

⸻

10I — Revenue Opportunity Dashboard

Goal

Aggregate retention revenue opportunities for managers.

Implement

Dashboard cards:

* dormant customers
* maintenance opportunities
* replacement opportunities
* warranty opportunities
* overdue invoice opportunities

Show:

* count
* estimated value if available
* linked detail list
* tenant-scoped data only

Rules

No forecasting.

No AI.

Read-only dashboard.

Acceptance

* Manager dashboard visible.
* Aggregates retention/opportunity data.
* Completion state file updated.

⸻

10J — Customer Journey Dashboard

Goal

Visualize customer lifecycle stages.

Implement

Journey stages:

1. RFQ
2. Estimate
3. Quote
4. Install
5. Invoice
6. Service
7. Maintenance
8. Renewal
9. Replacement

For each property, show current/completed stages and links to source records.

Rules

Property remains anchor.

Dashboard is navigational/read-only.

No predictive analytics.

Acceptance

* Journey visible.
* Journey links back to CRM records.
* Completion state file updated.

⸻

Required Completion State File

Each run must update:

crm-phase-10-completion-state.md

# CRM Phase 10 Completion State
## Current Sub-Phase
- Last completed sub-phase:
- Current recommended next sub-phase:
## Completed
-
## Files Changed
-
## Migrations Added
-
## Routes Added
-
## Services Added
-
## Tests Added
-
## Known Gaps
-
## Risk Notes
-
## Next Recommended Task
-
