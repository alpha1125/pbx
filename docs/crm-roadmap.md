# CRM Roadmap

This document is the index and sequencing guide for the CRM phases after [CRM Phase 1](./crm-phase-1.md).

## Phase Documents

- [CRM Phase 1](./crm-phase-1.md)
- [CRM Phase 2](./crm-phase-2.md)
- [CRM Phase 3](./crm-phase-3.md)
- [CRM Phase 4](./crm-phase-4.md)
- [CRM Phase 5](./crm-phase-5.md)
- [CRM Phase 6](./crm-phase-6.md)
- [CRM Phase 7](./crm-phase-7.md)
- [CRM Phase 8](./crm-phase-8.md)
- [CRM Backlog](./crm-backlog.md)

## Sequence

The sequence is opinionated:

- stabilize tenant and workflow correctness first
- then unify communications and operations
- then deepen revenue workflows
- then expand field-service, procurement, and automation

## Recommended Build Order

1. [CRM Phase 2](./crm-phase-2.md)
2. [CRM Phase 3](./crm-phase-3.md)
3. [CRM Phase 4](./crm-phase-4.md)
4. [CRM Phase 5](./crm-phase-5.md)
5. [CRM Phase 6](./crm-phase-6.md)
6. [CRM Phase 7](./crm-phase-7.md)
7. [CRM Phase 8](./crm-phase-8.md)

## Why This Order

- Phase 2 makes the CRM safe for real users.
- Phase 3 makes the PBX data operationally useful inside the CRM.
- Phases 4 and 5 make revenue workflow real.
- Phase 6 adds service execution.
- Phase 7 deepens the procurement platform side.
- Phase 8 adds automation once enough structured workflow data exists.

## Suggested Milestone Cuts

If you want to ship incrementally, these are the cleanest milestone bundles:

- Milestone A:
  - Phase 2.1 to 2.3
  - tenant safety, RBAC, transcript protection
- Milestone B:
  - Phase 2.4 to 2.5
  - CRUD completion and operational UX
- Milestone C:
  - Phase 3
  - communications and PBX/CRM unification
- Milestone D:
  - Phase 4 plus invoice model hardening from Phase 5.1
  - sales workflow completion
- Milestone E:
  - remaining Phase 5 and Phase 6
  - billing and dispatch
- Milestone F:
  - Phase 7 and Phase 8
  - procurement intelligence and automation

## Cross-Cutting Workstreams

These run across multiple phases and should be planned continuously.

### Data Quality

- phone normalization
- address normalization/geocoding
- deduplication and merge flows
- import/export tooling

### Security and Compliance

- transcript/recording access policy
- retention and purge rules
- PIPEDA/privacy wording and auditability
- secret management and production environment hardening

### Performance and Operability

- pagination for CRM lists and timelines
- background jobs for expensive conversions/imports
- observability for CRM workflows
- retry/recovery tooling for failed integrations

### Testing

- service tests for multi-step workflows
- controller/functional tests for tenant isolation
- migration smoke checks
- fixture strategies for local/dev/test
