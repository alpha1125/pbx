# CRM Phase 6

## Goal

Add operational field-service workflow after quote acceptance.

## Why This Phase Exists

After quote acceptance and invoicing, the CRM still lacks a work-execution layer for dispatch and technicians.

## Dependencies

- user roles from Phase 2
- accepted quotes from Phase 4
- invoice/billing status from Phase 5 is useful but not mandatory for the first slice

## Work Packages

### 6.1 Job and Task Model

- add `Job`
- add `Task`
- link jobs to:
  - property
  - contact
  - quote
  - invoice
  - equipment
- support statuses:
  - unscheduled
  - scheduled
  - in_progress
  - completed
  - cancelled

### 6.2 Scheduling and Assignment

- assign job/tasks to users or technicians
- appointment windows
- internal dispatch views
- technician work queue

### 6.3 Field Notes and Service History

- technician notes
- arrival/completion timestamps
- recommended repair/replacement notes
- equipment service history updates

### 6.4 Follow-up Workflow

- generate post-job tasks
- service reminders
- unresolved issues/follow-ups

## Schema Impact

- new `Job`
- new `Task`
- assignment and scheduling fields
- possibly equipment service-history model

## UI/API Impact

- dispatch board
- technician job/task screens
- job detail linked to property and communications

## PBX / Integration Impact

- dispatchers and technicians may need click-to-call from jobs/tasks
- job lifecycle should create timeline items

## Key Risks

- scheduling complexity
- user-role overlap between dispatch and technician flows
- prematurely building a calendar system that is too ambitious

## Recommended Order

1. job/task schema
2. assignment and status workflow
3. dispatch list views
4. technician notes and service history
5. reminders and follow-up tasks

## Done Criteria

- accepted work can become scheduled work
- dispatch can assign and track jobs
- service history is attached to property/equipment
