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

### 7.1 RFQ Intake

- homeowner or admin RFQ intake UX/API
- validation and duplicate detection
- attachment/media handling if needed later

### 7.2 Vendor Invitation Orchestration

- select target HVAC tenants
- send invitations
- handle expiry and reminders
- define invitation eligibility/business rules

### 7.3 Platform Operator Tools

- admin screens for RFQ tracking
- vendor response status dashboards
- comparison views across invited vendors

### 7.4 Vendor Performance and Analytics

- metrics such as:
  - invitation open rate
  - accept rate
  - quote rate
  - response time
- notification preferences per tenant

## Schema Impact

- likely additions:
  - RFQ attachments/media
  - RFQ distribution metadata
  - vendor preference/notification settings
  - operator/admin audit entities if needed

## UI/API Impact

- platform-side RFQ intake/admin screens
- vendor comparison screens
- RFQ distribution management

## PBX / Integration Impact

- procurement staff may use call/transcript links during vendor/customer follow-up
- RFQ timeline events should project into both platform admin and tenant CRM where appropriate

## Key Risks

- mixing platform-global and tenant-local concerns in the same screens/services
- RFQ matching logic becoming brittle
- analytics requirements growing faster than core workflow reliability

## Recommended Order

1. RFQ intake/admin
2. invitation orchestration
3. operator dashboards
4. vendor analytics and preferences

## Done Criteria

- platform staff can intake and distribute RFQs cleanly
- vendors receive, respond to, and track invitations in a measurable way
