<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\User;
use App\Repository\UserTenantMembershipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NoTenantController extends AbstractController
{
    #[Route('/crm/no-tenant', name: 'crm_no_tenant', methods: ['GET'])]
    public function __invoke(
        UserTenantMembershipRepository $membershipRepository,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $memberships = $membershipRepository->findByUserOrdered($user);

        return $this->render('crm/no_tenant.html.twig', [
            'memberships' => $memberships,
            'activeMemberships' => array_values(array_filter($memberships, static fn ($membership): bool => $membership->isActive())),
            'pendingMemberships' => array_values(array_filter($memberships, static fn ($membership): bool => $membership->isPending())),
        ]);
    }
}
