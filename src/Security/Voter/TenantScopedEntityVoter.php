<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\CallRecording;
use App\Entity\CallSession;
use App\Entity\CallTranscript;
use App\Entity\Contact;
use App\Entity\Equipment;
use App\Entity\Estimate;
use App\Entity\Invoice;
use App\Entity\Property;
use App\Entity\Quote;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\UserTenantMembershipRepository;
use App\Service\CurrentTenantProviderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

final class TenantScopedEntityVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';

    public function __construct(
        private readonly CurrentTenantProviderInterface $tenantProvider,
        private readonly UserTenantMembershipRepository $membershipRepository,
        private readonly Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::EDIT], true)) {
            return false;
        }

        return $subject instanceof Property
            || $subject instanceof Contact
            || $subject instanceof Equipment
            || $subject instanceof Estimate
            || $subject instanceof Quote
            || $subject instanceof Invoice
            || $subject instanceof RfqInvitation
            || $subject instanceof CallSession
            || $subject instanceof CallRecording
            || $subject instanceof CallTranscript;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (!$this->security->isGranted('ROLE_USER')) {
            return false;
        }

        $currentTenant = $this->tenantProvider->getCurrentTenant();
        if (null === $currentTenant) {
            return false;
        }

        $entityTenant = $this->resolveTenant($subject);

        if (null === $entityTenant) {
            return false;
        }

        $tenantMatches = $entityTenant === $currentTenant || (null !== $entityTenant->getId()
            && null !== $currentTenant->getId()
            && $entityTenant->getId() === $currentTenant->getId());
        if (!$tenantMatches) {
            return false;
        }

        $membership = null !== $currentTenant->getId()
            ? $this->membershipRepository->findOneByUserAndTenantId($user, $currentTenant->getId())
            : null;
        if (!$membership instanceof UserTenantMembership) {
            return false;
        }

        if (self::VIEW === $attribute) {
            return [] !== $membership->getRoles();
        }

        return match (true) {
            $subject instanceof Invoice => $this->membershipHasAnyRole($membership, [
                UserTenantMembership::ROLE_TENANT_ADMIN,
                UserTenantMembership::ROLE_ACCOUNTING,
                UserTenantMembership::ROLE_SALES,
            ]),
            $subject instanceof Estimate, $subject instanceof Quote, $subject instanceof RfqInvitation => $this->membershipHasAnyRole($membership, [
                UserTenantMembership::ROLE_TENANT_ADMIN,
                UserTenantMembership::ROLE_SALES,
                UserTenantMembership::ROLE_DISPATCH,
            ]),
            $subject instanceof Property, $subject instanceof Contact, $subject instanceof Equipment, $subject instanceof CallSession => $this->membershipHasAnyRole($membership, [
                UserTenantMembership::ROLE_TENANT_ADMIN,
                UserTenantMembership::ROLE_SALES,
                UserTenantMembership::ROLE_DISPATCH,
                UserTenantMembership::ROLE_TECHNICIAN,
            ]),
            $subject instanceof CallRecording, $subject instanceof CallTranscript => $this->membershipHasAnyRole($membership, [
                UserTenantMembership::ROLE_TENANT_ADMIN,
                UserTenantMembership::ROLE_SALES,
                UserTenantMembership::ROLE_DISPATCH,
                UserTenantMembership::ROLE_ACCOUNTING,
                UserTenantMembership::ROLE_TECHNICIAN,
            ]),
            default => false,
        };
    }

    private function resolveTenant(object $subject): ?Tenant
    {
        if (method_exists($subject, 'getTenant')) {
            $tenant = $subject->getTenant();

            return $tenant instanceof Tenant ? $tenant : null;
        }

        if ($subject instanceof CallRecording) {
            return $subject->getCallSession()->getTenant();
        }

        if ($subject instanceof CallTranscript) {
            return $subject->getCallSession()?->getTenant();
        }

        if ($subject instanceof CallSession) {
            return $subject->getTenant();
        }

        return null;
    }

    /**
     * @param list<string> $roles
     */
    private function membershipHasAnyRole(UserTenantMembership $membership, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($membership->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
