# CRM Phase 2

## Goal

Make the CRM safe and usable for real tenant users instead of only local/demo operation.

## Why This Phase Exists

Phase 1 created the tenant-scoped data model and first workflows, but tenant resolution still has a development fallback and the CRM does not yet have production-grade authorization or CRUD completeness.

## Dependencies

- CRM Phase 1 complete
- persisted `User`, `Tenant`, and `UserTenantMembership`
- Symfony security login already in place

## Work Packages

### 2.1 Tenant Resolution Hardening

- remove production reliance on default tenant fallback
- resolve current tenant from:
  - authenticated user
  - active tenant membership
  - explicit tenant selection in session
- add guardrails when a user has:
  - zero tenants
  - one tenant
  - multiple tenants

### 2.2 Tenant Membership and User Admin

- add tenant administration screens
- add user invitation flow
- add membership create/update/remove flow
- store tenant role(s) per membership
- allow one user to belong to multiple HVAC companies
- add user profile editing:
  - display name
  - cell phone
  - password reset/change

### 2.3 RBAC and Authorization

- define role model such as:
  - `ROLE_TENANT_ADMIN`
  - `ROLE_DISPATCH`
  - `ROLE_SALES`
  - `ROLE_ACCOUNTING`
  - `ROLE_TECHNICIAN`
- add voters/policies for:
  - property
  - contact
  - equipment
  - RFQ invitation
  - estimate
  - quote
  - invoice
  - call session
  - transcript
  - recording
- ensure `/crm/*` and future download endpoints enforce both:
  - authentication
  - tenant ownership

### 2.4 Core CRUD Completion

- add create/edit/archive flows for:
  - properties
  - contacts
  - property-contact links
  - equipment
- improve property/contact matching and dedupe prompts
- add field validation and normalization:
  - phone
  - email
  - postal code
  - province/country

### 2.5 Operational UX Hardening

- improve flash and error handling
- add pagination for property/contact/equipment lists
- add reusable form partials
- add empty states and permission-denied states

## Schema Impact

- likely additions:
  - tenant selection state or preference on user
  - invitation token / invite status tables or fields
  - archival/soft-delete markers on CRM entities
- no expected rewrite of core PBX tables

## UI/API Impact

- login/profile pages
- tenant switcher
- user admin
- CRUD forms for property/contact/equipment
- authorization-aware transcript/call views

## PBX / Integration Impact

- transcript and recording pages must stop being dev-only and become tenant-aware
- CRM click-to-call should validate role permissions before launching calls

## Key Risks

- accidental cross-tenant data exposure
- weak authorization around recordings/transcripts
- overloading global `ROLE_USER` without membership-level checks

## Recommended Order

1. tenant resolver hardening
2. authorization voters/policies
3. tenant/user admin
4. CRUD forms and archival
5. UX hardening and pagination

## Done Criteria

- no CRM record can be accessed across tenants through normal routes
- one user can safely belong to multiple tenants
- transcripts/recordings/calls are tenant-protected
- property/contact/equipment can be created and edited through UI
