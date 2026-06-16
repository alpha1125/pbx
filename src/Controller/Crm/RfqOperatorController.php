<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Repository\RfqInvitationRepository;
use App\Repository\RfqRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RfqOperatorController extends AbstractController
{
    #[Route('/crm/rfq-operations', name: 'crm_rfq_operator_index', methods: ['GET'])]
    public function index(
        Request $request,
        RfqRepository $rfqRepository,
        RfqInvitationRepository $invitationRepository,
    ): Response {
        $rfqPage = max(1, (int) $request->query->get('rfqPage', 1));
        $rfqPageSize = 10;
        $rfqStatus = trim((string) $request->query->get('rfqStatus', ''));
        $rfqSearch = trim((string) $request->query->get('rfqQ', ''));

        $invitationPage = max(1, (int) $request->query->get('invitationPage', 1));
        $invitationPageSize = 10;
        $invitationStatus = trim((string) $request->query->get('invitationStatus', ''));
        $invitationSearch = trim((string) $request->query->get('invitationQ', ''));

        return $this->render('crm/rfq/operator_dashboard.html.twig', [
            'rfqs' => $rfqRepository->findForOperatorDashboard($rfqStatus ?: null, $rfqSearch ?: null, $rfqPage, $rfqPageSize),
            'totalRfqs' => $rfqRepository->countForOperatorDashboard($rfqStatus ?: null, $rfqSearch ?: null),
            'rfqPage' => $rfqPage,
            'rfqPageSize' => $rfqPageSize,
            'rfqStatus' => $rfqStatus,
            'rfqSearch' => $rfqSearch,
            'rfqStatuses' => [
                Rfq::STATUS_DRAFT,
                Rfq::STATUS_SUBMITTED,
                Rfq::STATUS_SENT_TO_VENDORS,
                Rfq::STATUS_QUOTED,
                Rfq::STATUS_CLOSED,
                Rfq::STATUS_CANCELLED,
            ],
            'invitations' => $invitationRepository->findForOperatorDashboard($invitationStatus ?: null, $invitationSearch ?: null, $invitationPage, $invitationPageSize),
            'totalInvitations' => $invitationRepository->countForOperatorDashboard($invitationStatus ?: null, $invitationSearch ?: null),
            'invitationPage' => $invitationPage,
            'invitationPageSize' => $invitationPageSize,
            'invitationStatus' => $invitationStatus,
            'invitationSearch' => $invitationSearch,
            'invitationStatuses' => [
                RfqInvitation::STATUS_SENT,
                RfqInvitation::STATUS_VIEWED,
                RfqInvitation::STATUS_DECLINED,
                RfqInvitation::STATUS_ACCEPTED_FOR_QUOTE,
                RfqInvitation::STATUS_QUOTE_SUBMITTED,
                RfqInvitation::STATUS_EXPIRED,
            ],
        ]);
    }

    #[Route('/crm/rfq-operations/{id<\d+>}/compare', name: 'crm_rfq_operator_compare', methods: ['GET'])]
    public function compare(
        int $id,
        RfqRepository $rfqRepository,
        RfqInvitationRepository $invitationRepository,
    ): Response {
        $rfq = $rfqRepository->find($id);
        if (null === $rfq) {
            throw $this->createNotFoundException('RFQ not found.');
        }

        $invitations = $invitationRepository->findByRfqForOperatorDashboard($rfq);

        $statusCounts = [];
        foreach ($invitations as $invitation) {
            $statusCounts[$invitation->getStatus()] = ($statusCounts[$invitation->getStatus()] ?? 0) + 1;
        }

        return $this->render('crm/rfq/compare.html.twig', [
            'rfq' => $rfq,
            'invitations' => $invitations,
            'statusCounts' => $statusCounts,
        ]);
    }
}
