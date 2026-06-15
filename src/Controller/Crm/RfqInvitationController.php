<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Repository\RfqInvitationRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\CurrentTenantProviderInterface;
use App\Service\RfqAcceptanceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RfqInvitationController extends AbstractController
{
    #[Route('/crm/rfq-invitations', name: 'crm_rfq_invitation_index', methods: ['GET'])]
    public function index(
        CurrentTenantProviderInterface $tenantProvider,
        RfqInvitationRepository $invitationRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();

        return $this->render('crm/rfq_invitation/index.html.twig', [
            'tenant' => $tenant,
            'invitations' => $invitationRepository->findByTenant($tenant),
        ]);
    }

    #[Route('/crm/rfq-invitations/{id<\d+>}/accept', name: 'crm_rfq_invitation_accept', methods: ['POST'])]
    public function accept(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        RfqInvitationRepository $invitationRepository,
        RfqAcceptanceService $rfqAcceptanceService,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invitation = $invitationRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invitation) {
            throw $this->createNotFoundException('RFQ invitation not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invitation);

        if (!$this->isCsrfTokenValid('accept_rfq_invitation_'.$invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $estimate = $rfqAcceptanceService->acceptInvitation($invitation);

        return $this->redirectToRoute('crm_estimate_show', ['id' => $estimate->getId()]);
    }

    #[Route('/crm/rfq-invitations/{id<\d+>}/decline', name: 'crm_rfq_invitation_decline', methods: ['POST'])]
    public function decline(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        RfqInvitationRepository $invitationRepository,
        RfqAcceptanceService $rfqAcceptanceService,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $invitation = $invitationRepository->findOneByTenantAndId($tenant, $id);
        if (null === $invitation) {
            throw $this->createNotFoundException('RFQ invitation not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $invitation);

        if (!$this->isCsrfTokenValid('decline_rfq_invitation_'.$invitation->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $rfqAcceptanceService->declineInvitation($invitation);

        return $this->redirectToRoute('crm_rfq_invitation_index');
    }
}
