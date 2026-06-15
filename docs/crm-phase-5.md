# CRM Phase 5

## Goal

Move from basic invoice records to operational billing.

## Why This Phase Exists

Phase 1 invoices are placeholders generated from accepted quotes, not yet a real accounts-receivable workflow.

## Dependencies

- Phase 4 quote acceptance workflow
- tenant settings and RBAC from Phase 2

## Work Packages

### 5.1 Invoice Editor Completion

- edit/delete/reorder invoice line items
- issue and due dates
- void and credit flows
- notes and payment instructions

### 5.2 Payment Model

- add payment records
- allocate payments to invoices
- support statuses:
  - unpaid
  - partially_paid
  - paid
  - refunded
- add balance calculations

### 5.3 Billing Output and Reminders

- branded invoice HTML/PDF
- email invoices
- overdue reminders
- aging views

### 5.4 Accounting Integration Boundaries

- define integration model for:
  - QuickBooks Online
  - Xero
- add sync status, external IDs, error storage
- start with export boundaries even if sync itself is later

## Schema Impact

- likely additions:
  - payment entity
  - accounting sync metadata
  - tenant invoice settings

## UI/API Impact

- invoice editor
- payment entry screen
- invoice aging and AR views
- invoice delivery logs

## PBX / Integration Impact

- invoice reminders and collection calls should link back into property communication history

## Key Risks

- monetary correctness and rounding
- payment allocation bugs
- accounting sync becoming a project of its own

## Recommended Order

1. payment model
2. invoice editor and totals
3. invoice output and reminders
4. accounting integration boundary model

## Done Criteria

- invoices can track real balances
- payments can be recorded and reflected in status
- invoices can be issued and sent cleanly
