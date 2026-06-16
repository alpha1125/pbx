<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Property;
use App\Entity\CommunicationTimelineItem;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\EquipmentRepository;
use App\Repository\EquipmentServiceRecordRepository;
use App\Repository\CommunicationTimelineItemRepository;
use App\Repository\PropertyContactRepository;
use App\Repository\PropertyRepository;
use App\Repository\TaskRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\CommunicationTimelineProjector;
use App\Service\CrmInputNormalizer;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TranscriptMessageViewBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class PropertyController extends AbstractController
{
    #[Route('/crm/properties', name: 'crm_property_index', methods: ['GET'])]
    public function index(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        PropertyContactRepository $propertyContactRepository,
        EquipmentRepository $equipmentRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = 20;
        $properties = $propertyRepository->findByTenant($tenant, $page, $pageSize);
        $primaryContacts = $propertyContactRepository->findPrimaryByProperties($properties);
        $equipmentCounts = $equipmentRepository->countByProperties($properties);
        $totalProperties = $propertyRepository->countByTenant($tenant);

        return $this->render('crm/property/index.html.twig', [
            'tenant' => $tenant,
            'properties' => $properties,
            'primaryContacts' => $primaryContacts,
            'equipmentCounts' => $equipmentCounts,
            'page' => $page,
            'pageSize' => $pageSize,
            'totalProperties' => $totalProperties,
        ]);
    }

    #[Route('/crm/properties/new', name: 'crm_property_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        CrmInputNormalizer $normalizer,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = new Property($tenant, '', '', '', '');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_property_form', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $this->applyPropertyForm($property, $request, $normalizer);
            $errors = $validator->validate($property);
            if (0 === count($errors)) {
                $existing = $propertyRepository->findOneByTenantAndAddress(
                    $tenant,
                    $property->getAddressLine1(),
                    $property->getAddressLine2(),
                    $property->getCity(),
                    $property->getProvince(),
                    $property->getPostalCode(),
                    $property->getCountry(),
                );
                if ($existing instanceof Property) {
                    $this->addFlash('error', 'A property with that address already exists.');

                    return $this->redirectToRoute('crm_property_show', ['id' => $existing->getId()]);
                }

                $entityManager->persist($property);
                $auditLogger->log($tenant, 'property', 'new', 'property.created', null, [
                    'address' => $property->getDisplayAddress(),
                ]);
                $entityManager->flush();
                $this->addFlash('success', 'Property created.');

                return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/property/form.html.twig', [
            'property' => $property,
            'formAction' => $this->generateUrl('crm_property_new'),
            'title' => 'Add Property',
        ]);
    }

    #[Route('/crm/properties/{id<\d+>}/edit', name: 'crm_property_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        CrmInputNormalizer $normalizer,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $id);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_property_form', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $this->applyPropertyForm($property, $request, $normalizer);
            $errors = $validator->validate($property);
            if (0 === count($errors)) {
                $existing = $propertyRepository->findOneByTenantAndAddress(
                    $tenant,
                    $property->getAddressLine1(),
                    $property->getAddressLine2(),
                    $property->getCity(),
                    $property->getProvince(),
                    $property->getPostalCode(),
                    $property->getCountry(),
                );
                if ($existing instanceof Property && $existing->getId() !== $property->getId()) {
                    $this->addFlash('error', 'Another property with that address already exists.');

                    return $this->redirectToRoute('crm_property_show', ['id' => $existing->getId()]);
                }

                $property->touch();
                $auditLogger->log($tenant, 'property', (string) $property->getId(), 'property.updated', null, [
                    'address' => $property->getDisplayAddress(),
                ]);
                $entityManager->flush();
                $this->addFlash('success', 'Property updated.');

                return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/property/form.html.twig', [
            'property' => $property,
            'formAction' => $this->generateUrl('crm_property_edit', ['id' => $property->getId()]),
            'title' => 'Edit Property',
        ]);
    }

    #[Route('/crm/properties/{id<\d+>}/archive', name: 'crm_property_archive', methods: ['POST'])]
    public function archive(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $id);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);

        if (!$this->isCsrfTokenValid('crm_property_archive_'.$property->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $property->archive()->touch();
        $auditLogger->log($tenant, 'property', (string) $property->getId(), 'property.archived');
        $entityManager->flush();
        $this->addFlash('success', 'Property archived.');

        return $this->redirectToRoute('crm_property_index');
    }

    #[Route('/crm/properties/{id<\d+>}', name: 'crm_property_show', methods: ['GET'])]
    public function show(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        PropertyContactRepository $propertyContactRepository,
        EquipmentRepository $equipmentRepository,
        AuditLogRepository $auditLogRepository,
        CommunicationTimelineItemRepository $timelineRepository,
        CommunicationTimelineProjector $timelineProjector,
        TranscriptMessageViewBuilder $messageBuilder,
        EquipmentServiceRecordRepository $serviceRecordRepository,
        TaskRepository $taskRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $id);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $property);
        $timelineProjector->syncProperty($property);

        $contactPage = max(1, (int) $request->query->get('contactPage', 1));
        $equipmentPage = max(1, (int) $request->query->get('equipmentPage', 1));
        $sublistPageSize = 10;
        $timelineFilter = (string) $request->query->get('activity', 'all');
        $searchQuery = trim((string) $request->query->get('q', ''));
        $timelineTypes = $this->timelineTypesForFilter($timelineFilter);
        $timelineItems = $timelineRepository->findByTenantAndPropertyOrdered(
            $tenant,
            $property,
            $timelineTypes,
            '' !== $searchQuery ? $searchQuery : null,
            100,
        );
        $transcriptMessages = [];
        foreach ($timelineItems as $item) {
            if (CommunicationTimelineItem::TYPE_TRANSCRIPT !== $item->getItemType()) {
                continue;
            }

            $transcript = $item->getCallTranscript();
            if (null !== $transcript && null !== $transcript->getId()) {
                $transcriptMessages[$transcript->getId()] = $messageBuilder->build($transcript);
            }
        }

        return $this->render('crm/property/show.html.twig', [
            'tenant' => $tenant,
            'property' => $property,
            'propertyContacts' => $propertyContactRepository->findByPropertyPaginated($property, $contactPage, $sublistPageSize),
            'contactPage' => $contactPage,
            'contactPageSize' => $sublistPageSize,
            'totalPropertyContacts' => $propertyContactRepository->countByProperty($property),
            'equipment' => $equipmentRepository->findByPropertyPaginated($property, $equipmentPage, $sublistPageSize),
            'equipmentPage' => $equipmentPage,
            'equipmentPageSize' => $sublistPageSize,
            'totalEquipment' => $equipmentRepository->countByProperty($property),
            'serviceHistoryRecords' => $serviceRecordRepository->findByProperty($property, 20),
            'followUpTasks' => $taskRepository->findFollowUpsByProperty($property),
            'timelineItems' => $timelineItems,
            'timelineFilter' => $timelineFilter,
            'timelineSearch' => $searchQuery,
            'transcriptMessages' => $transcriptMessages,
            'auditLogs' => $auditLogRepository->findRecentByProperty($tenant, $property),
        ]);
    }

    #[Route('/crm/properties/{id<\d+>}/timeline/notes', name: 'crm_property_timeline_note', methods: ['POST'])]
    public function addTimelineNote(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        CommunicationTimelineItemRepository $timelineRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $id);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);

        if (!$this->isCsrfTokenValid('crm_property_timeline_note_'.$property->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $noteText = trim((string) $request->request->get('noteText', ''));
        if ('' === $noteText) {
            $this->addFlash('error', 'Note text is required.');

            return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
        }

        $note = (new CommunicationTimelineItem($tenant, CommunicationTimelineItem::TYPE_MANUAL_NOTE, new \DateTimeImmutable()))
            ->setProperty($property)
            ->setBodyText($noteText)
            ->setDisposition($this->normalizeDisposition($request->request->get('disposition')))
            ->setCreatedBy($this->getUser() instanceof User ? $this->getUser() : null)
            ->setSourceKey(sprintf('manual_note:%d:%s', $property->getId(), bin2hex(random_bytes(8))));

        $entityManager->persist($note);
        $entityManager->flush();
        $auditLogger->log($tenant, 'communication_timeline_item', (string) $note->getId(), 'timeline.note_added', null, [
            'propertyId' => $property->getId(),
            'disposition' => $note->getDisposition(),
        ]);

        return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
    }

    #[Route('/crm/properties/{id<\d+>}/timeline/{itemId<\d+>}/disposition', name: 'crm_property_timeline_disposition', methods: ['POST'])]
    public function updateTimelineDisposition(
        int $id,
        int $itemId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        CommunicationTimelineItemRepository $timelineRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $id);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);

        $item = $timelineRepository->find($itemId);
        if (null === $item || $item->getTenant()->getId() !== $tenant->getId() || null === $item->getProperty() || $item->getProperty()->getId() !== $property->getId()) {
            throw $this->createNotFoundException('Timeline item not found.');
        }

        if (!$this->isCsrfTokenValid('crm_property_timeline_disposition_'.$item->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $item
            ->setDisposition($this->normalizeDisposition($request->request->get('disposition')))
            ->touch();
        $entityManager->flush();
        $auditLogger->log($tenant, 'communication_timeline_item', (string) $item->getId(), 'timeline.disposition_updated', null, [
            'propertyId' => $property->getId(),
            'itemType' => $item->getItemType(),
            'disposition' => $item->getDisposition(),
        ]);

        return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
    }

    private function applyPropertyForm(Property $property, Request $request, CrmInputNormalizer $normalizer): void
    {
        $property
            ->setAddressLine1((string) $normalizer->stringOrNull($request->request->get('addressLine1')))
            ->setAddressLine2($normalizer->stringOrNull($request->request->get('addressLine2')))
            ->setCity((string) $normalizer->stringOrNull($request->request->get('city')))
            ->setProvince((string) ($normalizer->normalizeProvinceOrNull($request->request->get('province')) ?? ''))
            ->setPostalCode((string) ($normalizer->normalizePostalCodeOrNull($request->request->get('postalCode')) ?? ''))
            ->setCountry((string) ($normalizer->normalizeCountryOrNull($request->request->get('country')) ?? 'CA'))
            ->setPropertyType($normalizer->stringOrNull($request->request->get('propertyType')))
            ->setApproximateSquareFeet($this->intOrNull($request->request->get('approximateSquareFeet')))
            ->setYearBuilt($this->intOrNull($request->request->get('yearBuilt')))
            ->setNotes($normalizer->stringOrNull($request->request->get('notes')));
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function timelineTypesForFilter(string $filter): ?array
    {
        return match ($filter) {
            'calls' => [
                CommunicationTimelineItem::TYPE_CALL,
                CommunicationTimelineItem::TYPE_RECORDING,
            ],
            'transcripts' => [
                CommunicationTimelineItem::TYPE_TRANSCRIPT,
                CommunicationTimelineItem::TYPE_SUMMARY,
            ],
            'notes' => [
                CommunicationTimelineItem::TYPE_MANUAL_NOTE,
                CommunicationTimelineItem::TYPE_STATUS_CHANGE,
                CommunicationTimelineItem::TYPE_QUOTE_EVENT,
                CommunicationTimelineItem::TYPE_INVOICE_EVENT,
            ],
            default => null,
        };
    }

    private function normalizeDisposition(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        if ('' === $value) {
            return null;
        }

        return in_array($value, [
            CommunicationTimelineItem::DISPOSITION_NO_ANSWER,
            CommunicationTimelineItem::DISPOSITION_QUOTE_REQUESTED,
            CommunicationTimelineItem::DISPOSITION_FOLLOW_UP_REQUIRED,
            CommunicationTimelineItem::DISPOSITION_JOB_BOOKED,
            CommunicationTimelineItem::DISPOSITION_SPAM,
        ], true) ? $value : null;
    }
}
