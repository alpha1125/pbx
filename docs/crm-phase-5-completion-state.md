# CRM Phase 5 Completion State

## Scope

This document tracks the implementation status of CRM Phase 5 as of the current worktree.

## Status

- 5.1 Invoice Editor Completion: complete
- 5.2 Payment Model: complete
- 5.3 Billing Output and Reminders: complete
- 5.4 Accounting Integration Boundaries: complete
- 5F Export Error Logging and Audit Trail: complete
- 5G Tenant Invoice Settings: complete
- 5H Export Payload Preview: complete

## 5.3 Verification

- Branded invoice HTML renders through [`templates/crm/invoice/public.html.twig`](/var/www/pbx/templates/crm/invoice/public.html.twig).
- Printable invoice output is available through the invoice print route in [`src/Controller/Crm/InvoiceController.php`](/var/www/pbx/src/Controller/Crm/InvoiceController.php).
- PDF invoice output is available through the invoice PDF route in [`src/Controller/Crm/InvoiceController.php`](/var/www/pbx/src/Controller/Crm/InvoiceController.php).
- Email invoice delivery is available through the invoice send route in [`src/Controller/Crm/InvoiceController.php`](/var/www/pbx/src/Controller/Crm/InvoiceController.php).
- Overdue reminder delivery is available through the invoice reminder route in [`src/Controller/Crm/InvoiceController.php`](/var/www/pbx/src/Controller/Crm/InvoiceController.php).
- Aging views are available through [`templates/crm/invoice/aging.html.twig`](/var/www/pbx/templates/crm/invoice/aging.html.twig) and the aging route in [`src/Controller/Crm/InvoiceController.php`](/var/www/pbx/src/Controller/Crm/InvoiceController.php).

## 5.4 Verification

- Accounting boundary records are modeled by [`src/Entity/InvoiceAccountingSyncRecord.php`](/var/www/pbx/src/Entity/InvoiceAccountingSyncRecord.php).
- Provider-scoped upsert/export/failure handling is available in [`src/Service/InvoiceAccountingBoundaryService.php`](/var/www/pbx/src/Service/InvoiceAccountingBoundaryService.php).
- Accounting boundary records are surfaced on the invoice show page through [`src/Controller/Crm/InvoiceController.php`](/var/www/pbx/src/Controller/Crm/InvoiceController.php) and [`templates/crm/invoice/show.html.twig`](/var/www/pbx/templates/crm/invoice/show.html.twig).
- The schema migration for the boundary model is in [`migrations/Version20260615223000.php`](/var/www/pbx/migrations/Version20260615223000.php).

## 5E Verification

- Retry state handling for accounting exports is modeled by [`src/Entity/InvoiceAccountingSyncRecord.php`](/var/www/pbx/src/Entity/InvoiceAccountingSyncRecord.php).
- Retry scheduling and export backoff boundaries are available in [`src/Service/InvoiceAccountingBoundaryService.php`](/var/www/pbx/src/Service/InvoiceAccountingBoundaryService.php).
- Retry state is surfaced on the invoice show page through [`templates/crm/invoice/show.html.twig`](/var/www/pbx/templates/crm/invoice/show.html.twig).
- Due retry lookup is available in [`src/Repository/InvoiceAccountingSyncRecordRepository.php`](/var/www/pbx/src/Repository/InvoiceAccountingSyncRecordRepository.php).
- The retry-state schema migration is in [`migrations/Version20260615233000.php`](/var/www/pbx/migrations/Version20260615233000.php).

## 5F Verification

- Accounting export attempts and failures are logged through [`src/Service/InvoiceAccountingBoundaryService.php`](/var/www/pbx/src/Service/InvoiceAccountingBoundaryService.php) into the shared audit-log table.
- Invoice accounting export logs are queryable through [`src/Repository/AuditLogRepository.php`](/var/www/pbx/src/Repository/AuditLogRepository.php).
- Export log entries are surfaced on the invoice show page through [`src/Controller/Crm/InvoiceController.php`](/var/www/pbx/src/Controller/Crm/InvoiceController.php) and [`templates/crm/invoice/show.html.twig`](/var/www/pbx/templates/crm/invoice/show.html.twig).
- The invoice export log trail is covered by [`tests/Functional/CrmTenantIsolationTest.php`](/var/www/pbx/tests/Functional/CrmTenantIsolationTest.php).

## 5G Verification

- Tenant invoice defaults are modeled on [`src/Entity/Tenant.php`](/var/www/pbx/src/Entity/Tenant.php).
- Quote-to-invoice conversion applies tenant invoice due-day and payment-instruction defaults through [`src/Service/QuoteToInvoiceService.php`](/var/www/pbx/src/Service/QuoteToInvoiceService.php).
- Tenant admins can edit invoice settings from the profile page through [`src/Controller/Crm/ProfileController.php`](/var/www/pbx/src/Controller/Crm/ProfileController.php) and [`templates/crm/profile.html.twig`](/var/www/pbx/templates/crm/profile.html.twig).
- Tenant invoice settings are rendered into invoice output through [`templates/crm/invoice/public.html.twig`](/var/www/pbx/templates/crm/invoice/public.html.twig).
- Tenant invoice settings are covered by [`tests/Service/QuoteToInvoiceServiceTest.php`](/var/www/pbx/tests/Service/QuoteToInvoiceServiceTest.php) and [`tests/Functional/CrmTenantIsolationTest.php`](/var/www/pbx/tests/Functional/CrmTenantIsolationTest.php).

## 5H Verification

- Structured accounting export payload previews are built in [`src/Service/InvoiceAccountingExportPayloadBuilder.php`](/var/www/pbx/src/Service/InvoiceAccountingExportPayloadBuilder.php).
- QuickBooks Online and Xero payload previews are surfaced on the invoice show page through [`src/Controller/Crm/InvoiceController.php`](/var/www/pbx/src/Controller/Crm/InvoiceController.php) and [`templates/crm/invoice/show.html.twig`](/var/www/pbx/templates/crm/invoice/show.html.twig).
- Payload preview shapes are covered by [`tests/Service/InvoiceAccountingExportPayloadBuilderTest.php`](/var/www/pbx/tests/Service/InvoiceAccountingExportPayloadBuilderTest.php).
- Invoice page payload preview rendering is covered by [`tests/Functional/CrmTenantIsolationTest.php`](/var/www/pbx/tests/Functional/CrmTenantIsolationTest.php).

## Validation

- `php vendor/bin/phpunit --filter InvoiceAccountingServiceTest`
- `php vendor/bin/phpunit --filter InvoiceAccountingBoundaryServiceTest`
- `php vendor/bin/phpunit --filter 'CrmTenantIsolationTest::testInvoiceEditorAndPaymentsUpdateBalanceAndStatus|CrmTenantIsolationTest::testInvoiceOutputSendReminderAndAgingAreAvailable'`
- `php vendor/bin/phpunit --filter 'CrmTenantIsolationTest::testInvoiceShowDisplaysAccountingBoundaryRecords'`
- `php vendor/bin/phpunit --filter 'CrmTenantIsolationTest::testInvoiceShowDisplaysRetryScheduledAccountingBoundaryRecords|CrmTenantIsolationTest::testRetryDueRepositoryFindsOnlyDueRecords'`
- `php vendor/bin/phpunit --filter 'CrmTenantIsolationTest::testInvoiceShowDisplaysAccountingExportLogTrail'`
- `php vendor/bin/phpunit --filter InvoiceAccountingExportPayloadBuilderTest`
- `php vendor/bin/phpunit --filter 'CrmTenantIsolationTest::testInvoiceShowDisplaysAccountingExportPayloadPreview'`
- `php vendor/bin/phpunit --filter QuoteToInvoiceServiceTest`
- `php vendor/bin/phpunit --filter 'CrmTenantIsolationTest::testTenantAdminCanEditInvoiceSettingsFromProfile'`
- `php bin/console doctrine:migrations:migrate --no-interaction --env=test`

## Notes

- 5D, 5E, and 5F are intentionally limited to the integration boundary model, not actual QuickBooks/Xero sync.
- 5G is intentionally limited to tenant invoice defaults and invoice output consumption, not a separate tenant settings subsystem.
- 5H is intentionally limited to structured export payload previews, not actual QuickBooks/Xero synchronization.
