<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\UserTenantMembership;
use App\Service\CrmReportingDashboardService;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TenantMembershipAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportingDashboardController extends AbstractController
{
    #[Route('/crm/reporting', name: 'crm_reporting_dashboard', methods: ['GET'])]
    public function __invoke(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        CrmReportingDashboardService $reportingService,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $access->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_SALES,
            UserTenantMembership::ROLE_DISPATCH,
        ]);

        $periodDays = $this->normalizePeriodDays((int) $request->query->get('days', 90));
        $report = $reportingService->buildReport($tenant, $periodDays);

        return $this->render('crm/reporting/index.html.twig', [
            'tenant' => $tenant,
            'report' => $report,
        ]);
    }

    private function normalizePeriodDays(int $days): int
    {
        return in_array($days, [30, 90, 180, 365], true) ? $days : 90;
    }
}
