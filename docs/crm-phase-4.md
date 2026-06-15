# CRM Phase 4

## Goal

Make estimate and quote handling complete enough for real HVAC sales use.

## Why This Phase Exists

Phase 1 created the basic estimate-to-quote path, but it is still a skeletal internal workflow.

## Dependencies

- Phase 2 CRUD and RBAC
- preferably Phase 3 timeline integration so sales activity is traceable

## Work Packages

### 4.1 Estimate Editing Maturity

- edit/delete/reorder estimate line items
- sections and scope blocks
- estimate notes, exclusions, assumptions
- duplicate estimate / clone from existing
- pricing helpers and templates

### 4.2 Quote Lifecycle Maturity

- quote revision/version model
- internal review state before send
- valid-until rules and expiry processing
- explicit send/view/accept/decline state transitions
- customer acceptance capture

### 4.3 Quote Rendering and Delivery

- branded HTML quote view
- printable output
- PDF generation
- email sending with tracking

### 4.4 Commercial Rules

- tax configuration by tenant
- discounts
- deposits
- financing placeholders or fields
- quote numbering rules per tenant

## Schema Impact

- likely additions:
  - quote revision/version entities
  - send log or delivery event entity
  - tenant tax configuration
  - deposit/discount fields

## UI/API Impact

- richer estimate editor
- send quote workflow
- printable/PDF quote pages
- quote acceptance screens

## PBX / Integration Impact

- quotes and quote events should appear in the CRM timeline
- sales reps may want one-click follow-up calling from quote screens

## Key Risks

- state explosion in quote revisions
- unclear distinction between estimate and quote once editing becomes richer
- PDF/email generation complexity

## Recommended Order

1. estimate editor completion
2. quote revision and send state model
3. branded HTML/PDF output
4. email delivery and tracking
5. tax/discount/deposit rules

## Done Criteria

- a sales user can create, revise, send, and track a real quote
- quotes have printable/PDF output
- accept/decline is captured in-app
