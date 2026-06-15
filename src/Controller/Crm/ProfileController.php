<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\User;
use App\Repository\UserTenantMembershipRepository;
use App\Service\CrmInputNormalizer;
use App\Service\CurrentTenantProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    #[Route('/crm/profile', name: 'crm_profile', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        UserTenantMembershipRepository $membershipRepository,
        CrmInputNormalizer $normalizer,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response|RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $memberships = $membershipRepository->findByUserOrdered($user);
        $activeMemberships = array_values(array_filter($memberships, static fn ($membership): bool => $membership->isActive()));
        $pendingMemberships = array_values(array_filter($memberships, static fn ($membership): bool => $membership->isPending()));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_profile', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $errors = [];
            $newPassword = (string) $request->request->get('newPassword', '');
            $confirmNewPassword = (string) $request->request->get('confirmNewPassword', '');
            $currentPassword = (string) $request->request->get('currentPassword', '');

            if ('' !== trim($newPassword) || '' !== trim($confirmNewPassword) || '' !== trim($currentPassword)) {
                if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $errors[] = 'Current password is incorrect.';
                }

                if (strlen($newPassword) < 8) {
                    $errors[] = 'New password must be at least 8 characters.';
                }

                if ($newPassword !== $confirmNewPassword) {
                    $errors[] = 'New password confirmation does not match.';
                }
            }

            $user
                ->setDisplayName($normalizer->stringOrNull($request->request->get('displayName')))
                ->setFirstName($normalizer->stringOrNull($request->request->get('firstName')))
                ->setLastName($normalizer->stringOrNull($request->request->get('lastName')))
                ->setCellPhone($normalizer->normalizePhoneOrNull($request->request->get('cellPhone')))
                ->touch();

            $defaultTenantId = $request->request->get('defaultTenantId');
            if (is_int($defaultTenantId) || (is_string($defaultTenantId) && ctype_digit($defaultTenantId))) {
                $matchedActiveMembership = false;
                foreach ($activeMemberships as $membership) {
                    $isDefault = $membership->getTenant()->getId() === (int) $defaultTenantId;
                    $membership->setIsDefault($isDefault)->touch();
                    $matchedActiveMembership = $matchedActiveMembership || $isDefault;
                }

                if ($matchedActiveMembership) {
                    $tenantProvider->selectTenant($user, (int) $defaultTenantId);
                }
            }

            if ([] !== $errors) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }

                return $this->render('crm/profile.html.twig', [
                    'currentTenant' => $tenantProvider->getCurrentTenant(),
                    'activeMemberships' => $activeMemberships,
                    'memberships' => $memberships,
                    'pendingMemberships' => $pendingMemberships,
                    'user' => $user,
                ]);
            }

            if ('' !== trim($newPassword)) {
                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            }

            $entityManager->flush();
            $this->addFlash('success', 'Profile updated.');

            return $this->redirectToRoute('crm_profile');
        }

        return $this->render('crm/profile.html.twig', [
            'currentTenant' => $tenantProvider->getCurrentTenant(),
            'activeMemberships' => $activeMemberships,
            'memberships' => $memberships,
            'pendingMemberships' => $pendingMemberships,
            'user' => $user,
        ]);
    }
}
