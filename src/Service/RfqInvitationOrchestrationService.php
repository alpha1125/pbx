<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Rfq;
use App\Entity\RfqInvitation;
use App\Entity\Tenant;
use App\Repository\RfqInvitationRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RfqInvitationOrchestrationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RfqInvitationRepository $invitationRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @param iterable<Tenant> $tenants
     *
     * @return list<RfqInvitation>
     */
    public function createInvitationsForRfq(
        Rfq $rfq,
        iterable $tenants,
        ?\DateInterval $expiresIn = null,
        ?\DateInterval $reminderIn = null,
        ?string $reminderNotes = null,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable();
        $created = [];

        return $this->entityManager->wrapInTransaction(function () use ($rfq, $tenants, $expiresIn, $reminderIn, $reminderNotes, $now, &$created): array {
            foreach ($tenants as $tenant) {
                if (!$tenant instanceof Tenant) {
                    continue;
                }

                $invitation = $this->invitationRepository->findOneByTenantAndRfq($tenant, $rfq);
                if (null === $invitation) {
                    $invitation = new RfqInvitation($tenant, $rfq);
                    $invitation
                        ->setInvitedAt($now)
                        ->setStatus(RfqInvitation::STATUS_SENT)
                        ->touch();

                    if (null !== $expiresIn) {
                        $invitation->setExpiresAt($now->add($expiresIn));
                    }

                    if (null !== $reminderIn) {
                        $invitation
                            ->setReminderAt($now->add($reminderIn))
                            ->setReminderNotes($reminderNotes);
                    }

                    $this->entityManager->persist($invitation);
                    $created[] = $invitation;
                }
            }

            if ([] !== $created) {
                $this->entityManager->flush();

                foreach ($created as $invitation) {
                    $this->auditLogger->log(
                        $invitation->getTenant(),
                        'rfq_invitation',
                        (string) $invitation->getId(),
                        'rfq_invitation.created',
                        null,
                        [
                            'status' => $invitation->getStatus(),
                            'invitedAt' => $invitation->getInvitedAt()?->format(DATE_ATOM),
                            'expiresAt' => $invitation->getExpiresAt()?->format(DATE_ATOM),
                            'reminderAt' => $invitation->getReminderAt()?->format(DATE_ATOM),
                        ],
                        ['rfqId' => $rfq->getId()],
                    );
                }

                $this->entityManager->flush();
            }

            return $created;
        });
    }

    public function markViewed(RfqInvitation $invitation, ?\DateTimeImmutable $viewedAt = null): RfqInvitation
    {
        return $this->entityManager->wrapInTransaction(function () use ($invitation, $viewedAt): RfqInvitation {
            $updated = $this->transitionInvitation(
                $invitation,
                RfqInvitation::STATUS_VIEWED,
                $viewedAt,
                function (RfqInvitation $invitation, \DateTimeImmutable $at): void {
                    $invitation->setViewedAt($at);
                },
                'rfq_invitation.viewed',
            );

            $this->entityManager->flush();

            return $updated;
        });
    }

    public function expire(RfqInvitation $invitation, ?\DateTimeImmutable $expiredAt = null): RfqInvitation
    {
        return $this->entityManager->wrapInTransaction(function () use ($invitation, $expiredAt): RfqInvitation {
            $updated = $this->transitionInvitation(
                $invitation,
                RfqInvitation::STATUS_EXPIRED,
                $expiredAt,
                function (RfqInvitation $invitation, \DateTimeImmutable $at): void {
                    $invitation->setExpiredAt($at);
                },
                'rfq_invitation.expired',
            );

            $this->entityManager->flush();

            return $updated;
        });
    }

    public function scheduleReminder(RfqInvitation $invitation, \DateTimeImmutable $reminderAt, ?string $notes = null): RfqInvitation
    {
        return $this->entityManager->wrapInTransaction(function () use ($invitation, $reminderAt, $notes): RfqInvitation {
            if (!in_array($invitation->getStatus(), [RfqInvitation::STATUS_SENT, RfqInvitation::STATUS_VIEWED], true)) {
                throw new \RuntimeException(sprintf('RFQ invitation %d cannot schedule a reminder from status "%s".', $invitation->getId(), $invitation->getStatus()));
            }

            $invitation
                ->setReminderAt($reminderAt)
                ->setReminderNotes($notes)
                ->touch();

            $this->auditLogger->log(
                $invitation->getTenant(),
                'rfq_invitation',
                (string) $invitation->getId(),
                'rfq_invitation.reminder_scheduled',
                null,
                [
                    'status' => $invitation->getStatus(),
                    'reminderAt' => $invitation->getReminderAt()?->format(DATE_ATOM),
                    'reminderNotes' => $invitation->getReminderNotes(),
                ],
                ['rfqId' => $invitation->getRfq()->getId()],
            );

            $this->entityManager->flush();

            return $invitation;
        });
    }

    /**
     * @return list<RfqInvitation>
     */
    public function expireDueInvitations(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return $this->entityManager->wrapInTransaction(function () use ($now): array {
            $expired = [];
            foreach ($this->invitationRepository->findDueForExpiry($now) as $invitation) {
                $expired[] = $this->transitionInvitation(
                    $invitation,
                    RfqInvitation::STATUS_EXPIRED,
                    $now,
                    function (RfqInvitation $invitation, \DateTimeImmutable $at): void {
                        $invitation->setExpiredAt($at);
                    },
                    'rfq_invitation.expired',
                );
            }

            if ([] !== $expired) {
                $this->entityManager->flush();
            }

            return $expired;
        });
    }

    /**
     * @param callable(RfqInvitation, \DateTimeImmutable):void $mutator
     */
    private function transitionInvitation(
        RfqInvitation $invitation,
        string $status,
        ?\DateTimeImmutable $at,
        callable $mutator,
        string $action,
    ): RfqInvitation
    {
        if (!in_array($invitation->getStatus(), [RfqInvitation::STATUS_SENT, RfqInvitation::STATUS_VIEWED], true)) {
            throw new \RuntimeException(sprintf('RFQ invitation %d cannot transition from status "%s".', $invitation->getId(), $invitation->getStatus()));
        }

        $at ??= new \DateTimeImmutable();
        $before = ['status' => $invitation->getStatus()];
        $invitation
            ->setStatus($status)
            ->touch($at);
        $mutator($invitation, $at);

        $this->auditLogger->log(
            $invitation->getTenant(),
            'rfq_invitation',
            (string) $invitation->getId(),
            $action,
            $before,
            ['status' => $invitation->getStatus()],
            ['rfqId' => $invitation->getRfq()->getId()],
        );

        $this->entityManager->persist($invitation);

        return $invitation;
    }
}
