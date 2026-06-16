<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Rfq;
use App\Repository\RfqRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RfqIntakeService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RfqRepository $rfqRepository,
        private readonly ValidatorInterface $validator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function intakeHomeownerRfq(Rfq $rfq): Rfq
    {
        return $this->intakeRfq($rfq, 'homeowner');
    }

    public function intakeAdminRfq(Rfq $rfq): Rfq
    {
        return $this->intakeRfq($rfq, 'admin');
    }

    private function intakeRfq(Rfq $rfq, string $source): Rfq
    {
        return $this->entityManager->wrapInTransaction(function () use ($rfq, $source): Rfq {
            $violations = $this->validator->validate($rfq);
            if (0 !== count($violations)) {
                throw new \InvalidArgumentException($this->describeViolations($violations));
            }

            $existing = $this->rfqRepository->findDuplicateForIntake($rfq);
            if (null !== $existing) {
                return $existing;
            }

            $rfq->setStatus(Rfq::STATUS_SUBMITTED);
            $this->entityManager->persist($rfq);

            $this->auditLogger->log(
                null,
                'rfq',
                'new',
                'rfq.submitted',
                null,
                [
                    'status' => $rfq->getStatus(),
                    'address' => $rfq->getAddressLine1(),
                    'city' => $rfq->getCity(),
                    'province' => $rfq->getProvince(),
                    'postalCode' => $rfq->getPostalCode(),
                ],
                [
                    'source' => $source,
                    'externalReference' => $rfq->getExternalReference(),
                    'customerEmail' => $rfq->getCustomerEmail(),
                    'customerPhone' => $rfq->getCustomerPhone(),
                ],
            );

            $this->entityManager->flush();

            return $rfq;
        });
    }

    /**
     * @param iterable<object> $violations
     */
    private function describeViolations(iterable $violations): string
    {
        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = sprintf('%s %s', $violation->getPropertyPath(), $violation->getMessage());
        }

        return sprintf('RFQ validation failed: %s', implode('; ', $messages));
    }
}
