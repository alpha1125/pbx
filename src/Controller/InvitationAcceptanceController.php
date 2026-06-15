<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserTenantMembershipRepository;
use App\Service\CrmInputNormalizer;
use App\Service\TenantUserAdministrationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class InvitationAcceptanceController extends AbstractController
{
    #[Route('/invite/{token}', name: 'app_invitation_accept', methods: ['GET', 'POST'])]
    public function __invoke(
        string $token,
        Request $request,
        UserTenantMembershipRepository $membershipRepository,
        CrmInputNormalizer $normalizer,
        UserPasswordHasherInterface $passwordHasher,
        TenantUserAdministrationService $tenantAdmin,
        EntityManagerInterface $entityManager,
    ): Response|RedirectResponse {
        $membership = $membershipRepository->findPendingByInviteToken($token);
        if (null === $membership) {
            throw $this->createNotFoundException('Invitation not found or already accepted.');
        }

        $user = $membership->getUser();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('accept_invitation_'.$membership->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $newPassword = (string) $request->request->get('newPassword', '');
            $confirmNewPassword = (string) $request->request->get('confirmNewPassword', '');

            if (!$user->isActive() || '' !== trim($newPassword) || '' !== trim($confirmNewPassword)) {
                if (strlen($newPassword) < 8) {
                    $errors[] = 'Password must be at least 8 characters.';
                }

                if ($newPassword !== $confirmNewPassword) {
                    $errors[] = 'Password confirmation does not match.';
                }
            }

            $user
                ->setFirstName($normalizer->stringOrNull($request->request->get('firstName')))
                ->setLastName($normalizer->stringOrNull($request->request->get('lastName')))
                ->setDisplayName($normalizer->stringOrNull($request->request->get('displayName')))
                ->setCellPhone($normalizer->normalizePhoneOrNull($request->request->get('cellPhone')))
                ->touch();

            if ([] === $errors) {
                if ('' !== trim($newPassword)) {
                    $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
                }

                $tenantAdmin->acceptInvitation($membership);
                $entityManager->flush();
                $this->addFlash('success', sprintf('Invitation accepted for %s. Sign in to continue.', $membership->getTenant()->getName()));

                $authenticatedUser = $this->getUser();
                if ($authenticatedUser instanceof User && $authenticatedUser->getId() === $user->getId()) {
                    return $this->redirectToRoute('crm_home');
                }

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('security/accept_invitation.html.twig', [
            'membership' => $membership,
            'user' => $user,
            'errors' => $errors,
        ]);
    }
}
