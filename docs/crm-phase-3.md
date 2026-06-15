# CRM Phase 3

## Goal

Turn calls, transcripts, recordings, and summaries into first-class CRM communication history.

## Why This Phase Exists

The PBX stack already works and Phase 1 links `CallSession` to CRM records, but call-derived data is not yet modeled as a unified operational timeline.

## Dependencies

- Phase 2 authorization complete
- stable tenant-aware transcript and recording access

## Work Packages

### 3.1 Communication Timeline Model

- add `CommunicationTimelineItem`
- support item types such as:
  - call
  - recording
  - transcript
  - summary
  - manual_note
  - status_change
  - quote_event
  - invoice_event
- link timeline items to:
  - tenant
  - property
  - contact
  - estimate
  - quote
  - invoice
  - RFQ invitation
  - call session

### 3.2 PBX-to-CRM Event Projection

- create service(s) that mirror important PBX events into timeline items
- project:
  - call created
  - call answered
  - call completed
  - recording ready
  - transcript ready
  - summary ready
- avoid duplicating raw Telnyx event storage; derive timeline items from normalized PBX entities

### 3.3 Property Communication UI

- replace the current property “calls/transcripts” block with a real timeline
- support filters:
  - calls only
  - transcripts only
  - notes only
  - all activity
- embed transcript “message view” inside CRM
- add call metadata display:
  - direction
  - duration
  - outcome
  - linked contact

### 3.4 Manual Notes and Dispositions

- allow staff to add notes to property/contact timeline
- add call disposition values such as:
  - no_answer
  - quote_requested
  - follow_up_required
  - job_booked
  - spam

### 3.5 Search Across Communication History

- search by:
  - phone number
  - address
  - customer
  - quote/invoice number
  - transcript text
- likely start with database text search, then improve later

## Schema Impact

- new `CommunicationTimelineItem`
- possible note/disposition support tables or enums
- optional indexes for transcript and timeline search

## UI/API Impact

- property timeline screen
- contact timeline screen
- embedded transcript pages
- manual note creation

## PBX / Integration Impact

- add timeline projection layer from existing PBX entities
- preserve existing Telnyx event persistence and PBX projection services

## Key Risks

- duplicate timeline entries when replaying or reprocessing events
- poor query performance on transcript-heavy accounts
- confusing separation between raw PBX data and CRM timeline data

## Recommended Order

1. timeline schema
2. projection service from PBX entities
3. property timeline UI
4. manual notes/dispositions
5. communication search

## Done Criteria

- a property shows a unified chronological communication history
- calls, recordings, transcripts, and summaries appear in CRM without opening raw dev pages
- manual notes coexist with PBX-derived events
