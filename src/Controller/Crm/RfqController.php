<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Contact;
use App\Entity\Property;
use App\Entity\Rfq;
use App\Repository\ContactRepository;
use App\Repository\PropertyRepository;
use App\Repository\RfqRepository;
use App\Service\AuditLogger;
use App\Service\CrmInputNormalizer;
use App\Service\CurrentTenantProviderInterface;
use App\Service\RfqIntakeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RfqController extends AbstractController
{
    #[Route('/crm/rfqs', name: 'crm_rfq_index', methods: ['GET'])]
    public function index(RfqRepository $rfqRepository): Response
    {
        return $this->render('crm/rfq/index.html.twig', [
            'rfqs' => $rfqRepository->findBy([], ['updatedAt' => 'DESC', 'createdAt' => 'DESC']),
        ]);
    }

    #[Route('/crm/rfqs/new', name: 'crm_rfq_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        ContactRepository $contactRepository,
        CrmInputNormalizer $normalizer,
        ValidatorInterface $validator,
        RfqIntakeService $rfqIntakeService,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $rfq = new Rfq('', '', '', '');
        $propertyChoices = $propertyRepository->findByTenant($tenant, 1, 200);
        $contactChoices = $contactRepository->findByTenant($tenant);
        $selectedPropertyId = $this->selectedId($request, 'propertyMatchId');
        $selectedContactId = $this->selectedId($request, 'contactMatchId');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_rfq_form', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $this->applyRfqForm($rfq, $request, $normalizer, $tenant, $propertyRepository, $contactRepository);
            $errors = $validator->validate($rfq);
            if (0 === count($errors)) {
                $saved = $rfqIntakeService->intakeHomeownerRfq($rfq);

                if ($saved->getId() !== $rfq->getId()) {
                    $this->addFlash('warning', 'Matched an existing RFQ instead of creating a duplicate.');
                } else {
                    $this->addFlash('success', 'RFQ created.');
                }

                return $this->redirectToRoute('crm_rfq_show', ['id' => $saved->getId()]);
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/rfq/form.html.twig', [
            'rfq' => $rfq,
            'formAction' => $this->generateUrl('crm_rfq_new'),
            'title' => 'Add RFQ',
            'propertyChoices' => $propertyChoices,
            'contactChoices' => $contactChoices,
            'selectedPropertyId' => $selectedPropertyId,
            'selectedContactId' => $selectedContactId,
        ]);
    }

    #[Route('/crm/rfqs/{id<\d+>}', name: 'crm_rfq_show', methods: ['GET'])]
    public function show(int $id, RfqRepository $rfqRepository): Response
    {
        $rfq = $rfqRepository->find($id);
        if (null === $rfq) {
            throw $this->createNotFoundException('RFQ not found.');
        }

        return $this->render('crm/rfq/show.html.twig', [
            'rfq' => $rfq,
        ]);
    }

    #[Route('/crm/rfqs/{id<\d+>}/edit', name: 'crm_rfq_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        RfqRepository $rfqRepository,
        PropertyRepository $propertyRepository,
        ContactRepository $contactRepository,
        CrmInputNormalizer $normalizer,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $rfq = $rfqRepository->find($id);
        if (null === $rfq) {
            throw $this->createNotFoundException('RFQ not found.');
        }

        $propertyChoices = $propertyRepository->findByTenant($tenant, 1, 200);
        $contactChoices = $contactRepository->findByTenant($tenant);
        $selectedPropertyId = $this->selectedId($request, 'propertyMatchId');
        $selectedContactId = $this->selectedId($request, 'contactMatchId');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_rfq_form', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $before = [
                'status' => $rfq->getStatus(),
                'addressLine1' => $rfq->getAddressLine1(),
                'addressLine2' => $rfq->getAddressLine2(),
                'city' => $rfq->getCity(),
                'province' => $rfq->getProvince(),
                'postalCode' => $rfq->getPostalCode(),
                'country' => $rfq->getCountry(),
                'customerName' => $rfq->getCustomerName(),
                'customerPhone' => $rfq->getCustomerPhone(),
                'customerEmail' => $rfq->getCustomerEmail(),
                'projectType' => $rfq->getProjectType(),
                'description' => $rfq->getDescription(),
            ];

            $this->applyRfqForm($rfq, $request, $normalizer, $tenant, $propertyRepository, $contactRepository);
            $errors = $validator->validate($rfq);
            if (0 === count($errors)) {
                $duplicate = $rfqRepository->findDuplicateForIntake($rfq);
                if (null !== $duplicate && $duplicate->getId() !== $rfq->getId()) {
                    $this->addFlash('error', 'Another RFQ already matches those details.');

                    return $this->redirectToRoute('crm_rfq_show', ['id' => $duplicate->getId()]);
                }

                $rfq->touch();
                $auditLogger->log(
                    null,
                    'rfq',
                    (string) $rfq->getId(),
                    'rfq.updated',
                    $before,
                    [
                        'status' => $rfq->getStatus(),
                        'addressLine1' => $rfq->getAddressLine1(),
                        'addressLine2' => $rfq->getAddressLine2(),
                        'city' => $rfq->getCity(),
                        'province' => $rfq->getProvince(),
                        'postalCode' => $rfq->getPostalCode(),
                        'country' => $rfq->getCountry(),
                        'customerName' => $rfq->getCustomerName(),
                        'customerPhone' => $rfq->getCustomerPhone(),
                        'customerEmail' => $rfq->getCustomerEmail(),
                        'projectType' => $rfq->getProjectType(),
                        'description' => $rfq->getDescription(),
                    ],
                );
                $entityManager->flush();
                $this->addFlash('success', 'RFQ updated.');

                return $this->redirectToRoute('crm_rfq_show', ['id' => $rfq->getId()]);
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/rfq/form.html.twig', [
            'rfq' => $rfq,
            'formAction' => $this->generateUrl('crm_rfq_edit', ['id' => $rfq->getId()]),
            'title' => 'Edit RFQ',
            'propertyChoices' => $propertyChoices,
            'contactChoices' => $contactChoices,
            'selectedPropertyId' => $selectedPropertyId,
            'selectedContactId' => $selectedContactId,
        ]);
    }

    /**
     * @param list<Property> $propertyChoices
     * @param list<Contact> $contactChoices
     */
    private function applyRfqForm(
        Rfq $rfq,
        Request $request,
        CrmInputNormalizer $normalizer,
        \App\Entity\Tenant $tenant,
        PropertyRepository $propertyRepository,
        ContactRepository $contactRepository,
    ): void {
        $rfq
            ->setExternalReference($normalizer->stringOrNull($request->request->get('externalReference')))
            ->setAddressLine1((string) $request->request->get('addressLine1', $rfq->getAddressLine1()))
            ->setAddressLine2($normalizer->stringOrNull($request->request->get('addressLine2')))
            ->setCity((string) $request->request->get('city', $rfq->getCity()))
            ->setProvince((string) $request->request->get('province', $rfq->getProvince()))
            ->setPostalCode($normalizer->normalizePostalCodeOrNull((string) $request->request->get('postalCode', $rfq->getPostalCode())) ?? $rfq->getPostalCode())
            ->setCountry($normalizer->normalizeCountryOrNull((string) $request->request->get('country', $rfq->getCountry())) ?? $rfq->getCountry())
            ->setCustomerName($normalizer->stringOrNull($request->request->get('customerName')))
            ->setCustomerPhone($normalizer->normalizePhoneOrNull((string) $request->request->get('customerPhone')))
            ->setCustomerEmail($normalizer->normalizeEmailOrNull((string) $request->request->get('customerEmail')))
            ->setProjectType($normalizer->stringOrNull($request->request->get('projectType')))
            ->setDescription($normalizer->stringOrNull($request->request->get('description')));

        $propertyId = $normalizer->stringOrNull($request->request->get('propertyMatchId'));
        if (null !== $propertyId) {
            $property = $propertyRepository->findOneByTenantAndId($tenant, (int) $propertyId);
            if ($property instanceof Property) {
                $rfq
                    ->setAddressLine1($property->getAddressLine1())
                    ->setAddressLine2($property->getAddressLine2())
                    ->setCity($property->getCity())
                    ->setProvince($property->getProvince())
                    ->setPostalCode($property->getPostalCode())
                    ->setCountry($property->getCountry());
            }
        }

        $contactId = $normalizer->stringOrNull($request->request->get('contactMatchId'));
        if (null !== $contactId) {
            $contact = $contactRepository->findOneByTenantAndId($tenant, (int) $contactId);
            if ($contact instanceof Contact) {
                $rfq
                    ->setCustomerName($contact->getDisplayName())
                    ->setCustomerPhone($contact->getPrimaryPhone())
                    ->setCustomerEmail($contact->getPrimaryEmail());
            }
        }
    }

    private function selectedId(Request $request, string $field): ?string
    {
        $value = $request->request->get($field, $request->query->get($field));
        if (null === $value) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
