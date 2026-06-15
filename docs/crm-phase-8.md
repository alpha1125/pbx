# CRM Phase 8

## Goal

Use the existing call/transcript foundation to automate CRM work and surface decision support.

## Why This Phase Exists

The PBX system already collects rich call and transcript data. This phase turns that into automation, insight, and operational leverage.

## Dependencies

- Phase 3 communication timeline
- mature call/transcript access controls from Phase 2
- stable estimate/quote/job workflows from Phases 4 to 6

## Work Packages

### 8.1 CRM Enrichment From Conversations

- AI summaries written into timeline or notes
- suggested property/contact matching from phone/transcript context
- suggested call disposition and next step

### 8.2 Sales and Service Suggestions

- estimate line item suggestions from transcripts
- follow-up task suggestions
- equipment replacement flags based on age/service history

### 8.3 Reporting and Dashboards

- lead-to-quote conversion
- quote acceptance rate
- revenue pipeline
- call volume by tenant/property/contact
- service throughput by technician/dispatcher

### 8.4 Search and Automation

- full-text transcript and CRM search
- missed-call workflows
- renewal reminders
- warranty expiry reminders
- follow-up automations triggered by call or workflow state

## Schema Impact

- likely additions:
  - automation run logs
  - AI suggestion entities or metadata
  - reporting/materialized view support

## UI/API Impact

- dashboards
- suggestion/review surfaces
- automation status views

## PBX / Integration Impact

- depends directly on existing transcript/summary data
- should build on existing provider abstractions, not replace them

## Key Risks

- low-quality suggestions eroding trust
- privacy/compliance concerns around AI processing
- expensive search/reporting queries without background indexing

## Recommended Order

1. transcript-aware search
2. AI summaries and suggested dispositions
3. reporting dashboards
4. workflow automations and recommendations

## Done Criteria

- users get actionable suggestions from call/transcript data
- reporting supports operational decision-making
- automation reduces repetitive CRM work
