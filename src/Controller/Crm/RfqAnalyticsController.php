<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\UserTenantMembership;
use App\Service\RfqVendorAnalyticsService;
use App\Service\TenantMembershipAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RfqAnalyticsController extends AbstractController
{
    #[Route('/crm/rfq-analytics', name: 'crm_rfq_analytics_index', methods: ['GET'])]
    public function index(
        Request $request,
        TenantMembershipAccessService $membershipAccess,
        RfqVendorAnalyticsService $analyticsService,
    ): Response {
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_SALES,
            UserTenantMembership::ROLE_DISPATCH,
        ]);

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = 10;
        $search = trim((string) $request->query->get('q', ''));

        $report = $analyticsService->buildReport($search ?: null);
        $totalVendors = count($report['vendors']);
        $totalPages = max(1, (int) ceil($totalVendors / $pageSize));
        $page = min($page, $totalPages);
        $vendors = array_slice($report['vendors'], ($page - 1) * $pageSize, $pageSize);

        return $this->render('crm/rfq/analytics.html.twig', [
            'vendors' => $vendors,
            'summary' => $report['summary'],
            'page' => $page,
            'pageSize' => $pageSize,
            'totalVendors' => $totalVendors,
            'totalPages' => $totalPages,
            'search' => $search,
        ]);
    }
}
