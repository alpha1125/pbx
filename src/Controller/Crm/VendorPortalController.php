<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Quote;
use App\Entity\RfqInvitation;
use App\Entity\UserTenantMembership;
use App\Repository\QuoteRepository;
use App\Repository\RfqInvitationRepository;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TenantMembershipAccessService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VendorPortalController extends AbstractController
{
    #[Route('/crm/vendor-portal', name: 'crm_vendor_portal', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $membershipAccess,
        RfqInvitationRepository $invitationRepository,
        QuoteRepository $quoteRepository,
        EntityManagerInterface $entityManager,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $membership = $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_SALES,
            UserTenantMembership::ROLE_DISPATCH,
        ]);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_vendor_portal_preferences', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            if (!$membership->hasRole(UserTenantMembership::ROLE_TENANT_ADMIN)) {
                throw $this->createAccessDeniedException('Only tenant admins can edit vendor portal preferences.');
            }

            $tenant
                ->setRfqVendorEmailNotificationsEnabled('1' === (string) $request->request->get('rfqVendorEmailNotificationsEnabled'))
                ->setRfqVendorSmsNotificationsEnabled('1' === (string) $request->request->get('rfqVendorSmsNotificationsEnabled'))
                ->touch();

            $entityManager->flush();
            $this->addFlash('success', 'Vendor portal preferences updated.');

            return $this->redirectToRoute('crm_vendor_portal');
        }

        $invitations = $invitationRepository->findByTenant($tenant);
        $invitationQuotes = [];
        foreach ($invitations as $invitation) {
            if (!$invitation instanceof RfqInvitation) {
                continue;
            }

            $estimate = $invitation->getCreatedEstimate();
            $quote = null !== $estimate ? $quoteRepository->findOneByEstimate($estimate) : null;
            $invitationQuotes[$invitation->getId()] = $quote;
        }

        return $this->render('crm/vendor_portal/index.html.twig', [
            'tenant' => $tenant,
            'membership' => $membership,
            'invitations' => $invitations,
            'invitationQuotes' => $invitationQuotes,
        ]);
    }
}
