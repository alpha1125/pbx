# CRM Phase 1

This phase adds the first tenant-scoped HVAC CRM foundation on top of the existing PBX and transcription system without rewriting the Telnyx webhook/call-control pipeline.

See also:

- [CRM Roadmap](./crm-roadmap.md)
- [Suggested Milestone Cuts](./crm-roadmap.md#suggested-milestone-cuts)
- [CRM Phase 2](./crm-phase-2.md)
- [CRM Backlog](./crm-backlog.md)

## Scope

- Doctrine-backed `User`, `Tenant`, and `UserTenantMembership`
- Tenant-scoped CRM entities for properties, contacts, equipment, estimates, quotes, invoices, RFQ invitations, and audit logs
- Platform-level `Rfq` records with tenant-scoped `RfqInvitation` bridge records
- Nullable CRM linkage fields on `CallSession`
- Current-tenant resolution through `CurrentTenantProvider`
- RFQ accept/decline workflow with audit logging
- Estimate to quote and quote to invoice conversion services
- Minimal Bootstrap/Twig CRM screens for:
  - property list/detail
  - RFQ invitation list
  - estimate detail
  - quote detail
  - invoice detail
- CRM click-to-call endpoint that reuses the existing Telnyx click-to-call flow and links the resulting `CallSession` back to CRM records
- Local development fixtures

## Tenant scoping

- CRM entities use explicit `tenant_id` foreign keys and indexes.
- Repositories expose tenant-aware query methods for controller and service use.
- `/crm/*` controllers resolve the current tenant before loading records.
- `CurrentTenantProvider` prefers the logged-in user's default tenant membership and falls back to the configured/default tenant for local development.

## Security

- Symfony form login is enabled.
- `/crm/*` requires `ROLE_USER`.
- Existing `/api/*` PBX endpoints remain publicly reachable so webhook and call-control behavior is preserved.

## RFQ bridge model

- `Rfq` is platform/global by design because one homeowner request can be invited to multiple HVAC tenants.
- `RfqInvitation` is tenant-scoped and becomes the handoff object that creates tenant-local CRM data when accepted.

## Workflow notes

- Accepting an RFQ invitation:
  - matches or creates a tenant property
  - matches or creates a tenant contact
  - creates the property-contact relation
  - creates a draft estimate
  - updates the invitation status
  - writes audit entries
- Converting estimate to quote copies line items and financial totals.
- Converting quote to invoice currently requires an accepted quote.

## Fixtures

`doctrine:fixtures:load` seeds:

- tenant: `FirstFire HVAC Demo`
- user: `demo@firstfire.example`
- password: `demo1234`
- one property, contacts, and equipment
- one RFQ and one RFQ invitation to the demo tenant

## Follow-on work

- Add proper RBAC and tenant switching UX
- Add create/edit forms for properties, contacts, and equipment
- Add richer audit views spanning related entities, not only direct property entries
- Add production-ready click-to-call UI around the JSON endpoint
- Add quote/invoice PDF/email delivery
- Add CRM search and communication timeline screens
