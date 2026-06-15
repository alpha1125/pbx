<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\UserRepository;
use App\Repository\UserTenantMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class TenantUserAdministrationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserTenantMembershipRepository $membershipRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @param list<string> $membershipRoles
     */
    public function inviteOrCreateUser(
        Tenant $tenant,
        string $email,
        ?string $displayName,
        ?string $cellPhone,
        array $membershipRoles,
    ): UserTenantMembership {
        $email = mb_strtolower(trim($email));
        $user = $this->userRepository->findOneBy(['email' => $email]);
        $isNewUser = false;
        $inviteToken = bin2hex(random_bytes(24));

        if (null === $user) {
            $user = (new User())
                ->setEmail($email)
                ->setDisplayName($displayName)
                ->setCellPhone($cellPhone)
                ->setRoles(['ROLE_USER'])
                ->setIsActive(false);
            $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(12))));
            $this->entityManager->persist($user);
            $isNewUser = true;
        } else {
            $user
                ->setDisplayName($displayName ?? $user->getDisplayName())
                ->setCellPhone($cellPhone ?? $user->getCellPhone())
                ->touch();
        }

        $this->entityManager->flush();

        $membership = null !== $tenant->getId()
            ? $this->membershipRepository->findAnyByUserAndTenantId($user, $tenant->getId())
            : null;

        if (null === $membership) {
            $membership = (new UserTenantMembership($user, $tenant))
                ->setRoles($membershipRoles)
                ->markInvited($inviteToken);
            $this->entityManager->persist($membership);
        } elseif ($membership->isPending()) {
            $membership->setRoles($membershipRoles)->markInvited($inviteToken)->touch();
        } else {
            $membership->setRoles($membershipRoles)->touch();
        }

        $this->auditLogger->log(
            $tenant,
            'user_tenant_membership',
            (string) ($membership->getId() ?? 'new'),
            $membership->isPending()
                ? ($isNewUser ? 'tenant.user_created' : 'tenant.user_invited')
                : 'tenant.user_membership_updated',
            null,
            ['email' => $user->getEmail(), 'roles' => $membershipRoles],
            ['tenantId' => $tenant->getId()],
        );

        $this->entityManager->flush();

        return $membership;
    }

    public function reissueInvitation(UserTenantMembership $membership): UserTenantMembership
    {
        $membership->markInvited(bin2hex(random_bytes(24)))->touch();

        $this->auditLogger->log(
            $membership->getTenant(),
            'user_tenant_membership',
            (string) ($membership->getId() ?? 'unknown'),
            'tenant.user_invitation_reissued',
            null,
            ['email' => $membership->getUser()->getEmail(), 'roles' => $membership->getRoles()],
            ['tenantId' => $membership->getTenant()->getId()],
        );

        $this->entityManager->flush();

        return $membership;
    }

    public function acceptInvitation(UserTenantMembership $membership): void
    {
        $membership->accept()->touch();
        $user = $membership->getUser();
        $user->setIsActive(true)->touch();

        $hasDefault = false;
        foreach ($this->membershipRepository->findByUserOrdered($user) as $candidate) {
            if ($candidate->isDefault() && $candidate->isActive()) {
                $hasDefault = true;
                break;
            }
        }

        if (!$hasDefault) {
            foreach ($this->membershipRepository->findByUserOrdered($user) as $candidate) {
                $candidate->setIsDefault($candidate->getId() === $membership->getId() && $candidate->isActive())->touch();
            }
        }

        $this->auditLogger->log(
            $membership->getTenant(),
            'user_tenant_membership',
            (string) ($membership->getId() ?? 'unknown'),
            'tenant.user_invitation_accepted',
            null,
            ['email' => $user->getEmail(), 'roles' => $membership->getRoles()],
            ['tenantId' => $membership->getTenant()->getId()],
        );

        $this->entityManager->flush();
    }

    public function removeMembership(UserTenantMembership $membership): void
    {
        $tenant = $membership->getTenant();
        $user = $membership->getUser();
        $memberships = $this->membershipRepository->findByUserOrdered($user);

        $remainingMemberships = array_values(array_filter(
            $memberships,
            static fn (UserTenantMembership $candidate): bool => $candidate->getId() !== $membership->getId(),
        ));

        $wasDefault = $membership->isDefault();

        $this->auditLogger->log(
            $tenant,
            'user_tenant_membership',
            (string) ($membership->getId() ?? 'unknown'),
            'tenant.user_membership_removed',
            ['email' => $user->getEmail(), 'roles' => $membership->getRoles()],
            null,
            ['tenantId' => $tenant->getId()],
        );

        $this->entityManager->remove($membership);

        if ($wasDefault && [] !== $remainingMemberships) {
            foreach ($remainingMemberships as $candidate) {
                if ($candidate->isActive()) {
                    $candidate->setIsDefault(true)->touch();
                    break;
                }
            }
        }

        $this->entityManager->flush();
    }
}
