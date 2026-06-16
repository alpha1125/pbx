<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\UserTenantMembership;
use App\Service\ProcurementIntelligenceService;
use App\Service\TenantMembershipAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProcurementIntelligenceController extends AbstractController
{
    #[Route('/crm/procurement-intelligence', name: 'crm_procurement_intelligence_index', methods: ['GET'])]
    public function index(
        Request $request,
        TenantMembershipAccessService $membershipAccess,
        ProcurementIntelligenceService $procurementIntelligenceService,
    ): Response {
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_SALES,
            UserTenantMembership::ROLE_DISPATCH,
        ]);

        $search = trim((string) $request->query->get('q', ''));
        $report = $procurementIntelligenceService->buildReport($search ?: null);

        return $this->render('crm/procurement_intelligence/index.html.twig', [
            'summary' => $report['summary'],
            'trendRows' => $report['trendRows'],
            'rankedVendors' => $report['rankedVendors'],
            'recommendations' => $report['recommendations'],
            'search' => $search,
        ]);
    }
}
