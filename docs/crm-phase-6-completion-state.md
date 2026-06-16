# CRM Phase 6 Completion State

## Scope

This document tracks the implementation status of CRM Phase 6 as of the current worktree.

## Status

- 6.1 Job and Task Model: complete
- 6.2 Scheduling and Assignment: complete
- 6.3 Field Notes and Service History: complete
- 6.4 Follow-up Workflow: complete
- 6.5 Unresolved Issue Tracking: complete
- 6.6 Service Reminders: complete
- 6.7 Job Lifecycle Timeline Projection: complete
- 6.8 Quote Acceptance Job Creation: complete

## 6.1 Verification

- Job records are modeled by [`src/Entity/Job.php`](/var/www/pbx/src/Entity/Job.php).
- Task records are modeled by [`src/Entity/Task.php`](/var/www/pbx/src/Entity/Task.php).
- Job and task persistence boundaries are available through [`src/Repository/JobRepository.php`](/var/www/pbx/src/Repository/JobRepository.php) and [`src/Repository/TaskRepository.php`](/var/www/pbx/src/Repository/TaskRepository.php).
- The 6.1 schema migration is in [`migrations/Version20260616003000.php`](/var/www/pbx/migrations/Version20260616003000.php).
- Job/task defaults and relationship wiring are covered by [`tests/Service/JobTaskModelTest.php`](/var/www/pbx/tests/Service/JobTaskModelTest.php).

## 6.2 Verification

- Job and task assignment fields are modeled by [`src/Entity/Job.php`](/var/www/pbx/src/Entity/Job.php) and [`src/Entity/Task.php`](/var/www/pbx/src/Entity/Task.php).
- Dispatch board and technician queue routes are available through [`src/Controller/Crm/JobController.php`](/var/www/pbx/src/Controller/Crm/JobController.php).
- Dispatch board, technician queue, and per-job assignment screens are rendered in [`templates/crm/job/index.html.twig`](/var/www/pbx/templates/crm/job/index.html.twig), [`templates/crm/job/queue.html.twig`](/var/www/pbx/templates/crm/job/queue.html.twig), and [`templates/crm/job/show.html.twig`](/var/www/pbx/templates/crm/job/show.html.twig).
- Assignment persistence boundaries are available through [`src/Repository/JobRepository.php`](/var/www/pbx/src/Repository/JobRepository.php) and [`src/Repository/TaskRepository.php`](/var/www/pbx/src/Repository/TaskRepository.php).
- The 6.2 assignment schema migration is in [`migrations/Version20260616004000.php`](/var/www/pbx/migrations/Version20260616004000.php).
- Dispatch assignment and technician queue behavior are covered by [`tests/Functional/CrmJobWorkflowTest.php`](/var/www/pbx/tests/Functional/CrmJobWorkflowTest.php).

## 6.3 Verification

- Technician field notes and arrival/completion timestamps are modeled on [`src/Entity/Job.php`](/var/www/pbx/src/Entity/Job.php).
- Equipment service history records are modeled by [`src/Entity/EquipmentServiceRecord.php`](/var/www/pbx/src/Entity/EquipmentServiceRecord.php).
- Field note capture and service-history creation are handled in [`src/Controller/Crm/JobController.php`](/var/www/pbx/src/Controller/Crm/JobController.php).
- Service history is surfaced on the job detail page through [`templates/crm/job/show.html.twig`](/var/www/pbx/templates/crm/job/show.html.twig).
- Service history is surfaced on the property detail page through [`src/Controller/Crm/PropertyController.php`](/var/www/pbx/src/Controller/Crm/PropertyController.php) and [`templates/crm/property/show.html.twig`](/var/www/pbx/templates/crm/property/show.html.twig).
- Equipment service-history persistence boundaries are available through [`src/Repository/EquipmentServiceRecordRepository.php`](/var/www/pbx/src/Repository/EquipmentServiceRecordRepository.php).
- The 6.3 schema migration is in [`migrations/Version20260616005000.php`](/var/www/pbx/migrations/Version20260616005000.php).
- Field notes and service-history behavior are covered by [`tests/Functional/CrmJobWorkflowTest.php`](/var/www/pbx/tests/Functional/CrmJobWorkflowTest.php).

## 6.4 Verification

- Post-job follow-up generation is handled by [`src/Service/JobFollowUpService.php`](/var/www/pbx/src/Service/JobFollowUpService.php).
- Repair recommendations generate follow-up tasks, replacement recommendations generate follow-up tasks, and completed jobs with no unresolved issues generate a default service reminder in [`src/Service/JobFollowUpService.php`](/var/www/pbx/src/Service/JobFollowUpService.php).
- Follow-up generation is triggered from the completed-job field-notes flow in [`src/Controller/Crm/JobController.php`](/var/www/pbx/src/Controller/Crm/JobController.php).
- Follow-up tasks are surfaced on the job detail page through [`templates/crm/job/show.html.twig`](/var/www/pbx/templates/crm/job/show.html.twig) and on the property detail page through [`templates/crm/property/show.html.twig`](/var/www/pbx/templates/crm/property/show.html.twig).
- The 6.4 schema migration is in [`migrations/Version20260616006000.php`](/var/www/pbx/migrations/Version20260616006000.php).
- Follow-up workflow behavior is covered by [`tests/Service/JobFollowUpServiceTest.php`](/var/www/pbx/tests/Service/JobFollowUpServiceTest.php) and [`tests/Functional/CrmJobWorkflowTest.php`](/var/www/pbx/tests/Functional/CrmJobWorkflowTest.php).

## 6.5 Verification

- Unresolved-job-issue notes are modeled on [`src/Entity/Job.php`](/var/www/pbx/src/Entity/Job.php).
- Unresolved issue notes are captured from the completed-job field-notes flow in [`src/Controller/Crm/JobController.php`](/var/www/pbx/src/Controller/Crm/JobController.php) and rendered on the job detail page in [`templates/crm/job/show.html.twig`](/var/www/pbx/templates/crm/job/show.html.twig).
- Unresolved issue notes generate post-job follow-up tasks in [`src/Service/JobFollowUpService.php`](/var/www/pbx/src/Service/JobFollowUpService.php).
- The 6.5 schema migration is in [`migrations/Version20260616007000.php`](/var/www/pbx/migrations/Version20260616007000.php).
- Unresolved issue tracking is covered by [`tests/Service/JobFollowUpServiceTest.php`](/var/www/pbx/tests/Service/JobFollowUpServiceTest.php), [`tests/Service/JobTaskModelTest.php`](/var/www/pbx/tests/Service/JobTaskModelTest.php), and [`tests/Functional/CrmJobWorkflowTest.php`](/var/www/pbx/tests/Functional/CrmJobWorkflowTest.php).

## 6.6 Verification

- Service reminder dates and notes are modeled on [`src/Entity/Job.php`](/var/www/pbx/src/Entity/Job.php).
- Service reminder capture is handled in [`src/Controller/Crm/JobController.php`](/var/www/pbx/src/Controller/Crm/JobController.php) and rendered on the job detail page in [`templates/crm/job/show.html.twig`](/var/www/pbx/templates/crm/job/show.html.twig).
- Explicit service reminders generate `service_reminder` tasks in [`src/Service/JobFollowUpService.php`](/var/www/pbx/src/Service/JobFollowUpService.php).
- The 6.6 schema migration is in [`migrations/Version20260616008000.php`](/var/www/pbx/migrations/Version20260616008000.php).
- Service reminder behavior is covered by [`tests/Service/JobFollowUpServiceTest.php`](/var/www/pbx/tests/Service/JobFollowUpServiceTest.php), [`tests/Service/JobTaskModelTest.php`](/var/www/pbx/tests/Service/JobTaskModelTest.php), and [`tests/Functional/CrmJobWorkflowTest.php`](/var/www/pbx/tests/Functional/CrmJobWorkflowTest.php).

## 6.7 Verification

- Job lifecycle timeline entries are projected by [`src/Service/CommunicationTimelineProjector.php`](/var/www/pbx/src/Service/CommunicationTimelineProjector.php).
- Job assignment and completion events are recorded from [`src/Controller/Crm/JobController.php`](/var/www/pbx/src/Controller/Crm/JobController.php).
- Job lifecycle timeline items are surfaced through the property timeline in [`src/Controller/Crm/PropertyController.php`](/var/www/pbx/src/Controller/Crm/PropertyController.php) and [`templates/crm/communication/_timeline_item.html.twig`](/var/www/pbx/templates/crm/communication/_timeline_item.html.twig).
- Job lifecycle timeline behavior is covered by [`tests/Functional/CrmJobWorkflowTest.php`](/var/www/pbx/tests/Functional/CrmJobWorkflowTest.php).

## 6.8 Verification

- Accepted quotes are converted into jobs by [`src/Service/QuoteToJobService.php`](/var/www/pbx/src/Service/QuoteToJobService.php) and invoked from [`src/Controller/QuotePublicController.php`](/var/www/pbx/src/Controller/QuotePublicController.php).
- Quote acceptance job creation is idempotent through [`src/Repository/JobRepository.php`](/var/www/pbx/src/Repository/JobRepository.php).
- Quote-acceptance job creation records a lifecycle timeline item through [`src/Service/CommunicationTimelineProjector.php`](/var/www/pbx/src/Service/CommunicationTimelineProjector.php).
- Quote acceptance job creation is covered by [`tests/Service/QuoteToJobServiceTest.php`](/var/www/pbx/tests/Service/QuoteToJobServiceTest.php) and [`tests/Functional/CrmTenantIsolationTest.php`](/var/www/pbx/tests/Functional/CrmTenantIsolationTest.php).

## Validation

- `php vendor/bin/phpunit --filter JobTaskModelTest`
- `php vendor/bin/phpunit --filter JobFollowUpServiceTest`
- `php vendor/bin/phpunit --filter CrmJobWorkflowTest`
- `php vendor/bin/phpunit --filter QuoteToJobServiceTest`
- `php vendor/bin/phpunit --filter CrmTenantIsolationTest::testPublicQuoteAcceptanceAndPdfAreAvailable`
- `php bin/console doctrine:migrations:migrate --no-interaction --env=test`

## Notes

- 6.1 is intentionally limited to the job/task data model and schema, not dispatch UI or assignment workflows.
- 6.2 is intentionally limited to scheduling and assignment UI/workflow, not field notes or follow-up automation.
- 6.3 is intentionally limited to technician notes, timestamps, and equipment service-history records, not automated follow-up generation.
- 6.4 is intentionally limited to post-job task generation and service-reminder follow-up, not a broader automation engine.
- 6.5 is intentionally limited to unresolved issue capture and follow-up task generation, not a broader case-management workflow.
- 6.6 is intentionally limited to explicit service reminder scheduling for completed jobs, not a general recurring scheduler.
- 6.7 is intentionally limited to projecting core job lifecycle milestones into the existing communication timeline, not a separate job audit subsystem.
- 6.8 is intentionally limited to creating a job/work order when a quote is accepted, not a broader estimate-to-dispatch automation engine.
