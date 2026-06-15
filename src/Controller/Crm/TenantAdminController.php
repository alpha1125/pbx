<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\UserTenantMembership;
use App\Repository\UserTenantMembershipRepository;
use App\Service\AuditLogger;
use App\Service\CrmInputNormalizer;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TenantMembershipAccessService;
use App\Service\TenantUserAdministrationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TenantAdminController extends AbstractController
{
    #[Route('/crm/admin/users', name: 'crm_admin_users', methods: ['GET', 'POST'])]
    public function users(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        UserTenantMembershipRepository $membershipRepository,
        TenantUserAdministrationService $admin,
        CrmInputNormalizer $normalizer,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $access->requireRole(UserTenantMembership::ROLE_TENANT_ADMIN);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_admin_user_create', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $email = $normalizer->normalizeEmailOrNull($request->request->get('email'));
            if (null === $email) {
                $this->addFlash('error', 'Email is required.');
            } else {
                $roles = array_values(array_filter(array_map('strval', (array) $request->request->all('roles'))));
                if ([] === $roles) {
                    $roles = [UserTenantMembership::ROLE_SALES];
                }

                $admin->inviteOrCreateUser(
                    $tenant,
                    $email,
                    $normalizer->stringOrNull($request->request->get('displayName')),
                    $normalizer->normalizePhoneOrNull($request->request->get('cellPhone')),
                    $roles,
                );
                $this->addFlash('success', 'Tenant user saved.');

                return $this->redirectToRoute('crm_admin_users');
            }
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = 20;

        return $this->render('crm/admin/users.html.twig', [
            'tenant' => $tenant,
            'memberships' => $membershipRepository->findByTenantOrdered($tenant, $page, $pageSize),
            'page' => $page,
            'pageSize' => $pageSize,
            'totalMemberships' => $membershipRepository->countByTenant($tenant),
            'roleOptions' => $this->roleOptions(),
        ]);
    }

    #[Route('/crm/admin/users/{membershipId<\d+>}/roles', name: 'crm_admin_user_roles', methods: ['POST'])]
    public function updateRoles(
        int $membershipId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        UserTenantMembershipRepository $membershipRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $access->requireRole(UserTenantMembership::ROLE_TENANT_ADMIN);

        $membership = $membershipRepository->find($membershipId);
        if (!$membership instanceof UserTenantMembership || $membership->getTenant()->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Tenant membership not found.');
        }

        if (!$this->isCsrfTokenValid('crm_admin_user_roles_'.$membership->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $membership->setRoles(array_values(array_filter(array_map('strval', (array) $request->request->all('roles')))))->touch();
        $auditLogger->log($tenant, 'user_tenant_membership', (string) $membership->getId(), 'tenant.user_roles_updated', null, [
            'roles' => $membership->getRoles(),
            'email' => $membership->getUser()->getEmail(),
        ]);
        $entityManager->flush();
        $this->addFlash('success', 'Tenant roles updated.');

        return $this->redirectToRoute('crm_admin_users');
    }

    #[Route('/crm/admin/users/{membershipId<\d+>}/reinvite', name: 'crm_admin_user_reinvite', methods: ['POST'])]
    public function reissueInvitation(
        int $membershipId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        UserTenantMembershipRepository $membershipRepository,
        TenantUserAdministrationService $admin,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $access->requireRole(UserTenantMembership::ROLE_TENANT_ADMIN);

        $membership = $membershipRepository->find($membershipId);
        if (!$membership instanceof UserTenantMembership || $membership->getTenant()->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Tenant membership not found.');
        }

        if (!$this->isCsrfTokenValid('crm_admin_user_reinvite_'.$membership->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $admin->reissueInvitation($membership);
        $this->addFlash('success', 'Invitation link refreshed.');

        return $this->redirectToRoute('crm_admin_users');
    }

    #[Route('/crm/admin/users/{membershipId<\d+>}/remove', name: 'crm_admin_user_remove', methods: ['POST'])]
    public function removeMembership(
        int $membershipId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        TenantMembershipAccessService $access,
        UserTenantMembershipRepository $membershipRepository,
        TenantUserAdministrationService $admin,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $access->requireRole(UserTenantMembership::ROLE_TENANT_ADMIN);

        $membership = $membershipRepository->find($membershipId);
        if (!$membership instanceof UserTenantMembership || $membership->getTenant()->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Tenant membership not found.');
        }

        if (!$this->isCsrfTokenValid('crm_admin_user_remove_'.$membership->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $admin->removeMembership($membership);
        $this->addFlash('success', 'Tenant membership removed.');

        return $this->redirectToRoute('crm_admin_users');
    }

    /**
     * @return array<string, string>
     */
    private function roleOptions(): array
    {
        return [
            UserTenantMembership::ROLE_TENANT_ADMIN => 'Tenant Admin',
            UserTenantMembership::ROLE_DISPATCH => 'Dispatch',
            UserTenantMembership::ROLE_SALES => 'Sales',
            UserTenantMembership::ROLE_ACCOUNTING => 'Accounting',
            UserTenantMembership::ROLE_TECHNICIAN => 'Technician',
        ];
    }
}
