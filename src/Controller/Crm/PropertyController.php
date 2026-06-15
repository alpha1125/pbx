<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Property;
use App\Repository\AuditLogRepository;
use App\Repository\CallSessionRepository;
use App\Repository\CallTranscriptRepository;
use App\Repository\EquipmentRepository;
use App\Repository\EstimateRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PropertyContactRepository;
use App\Repository\PropertyRepository;
use App\Repository\QuoteRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\CrmInputNormalizer;
use App\Service\CurrentTenantProviderInterface;
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
        EstimateRepository $estimateRepository,
        QuoteRepository $quoteRepository,
        InvoiceRepository $invoiceRepository,
        CallSessionRepository $callSessionRepository,
        CallTranscriptRepository $callTranscriptRepository,
        AuditLogRepository $auditLogRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $id);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $property);

        $calls = $callSessionRepository->findByTenantAndProperty($tenant, $property);
        $contactPage = max(1, (int) $request->query->get('contactPage', 1));
        $equipmentPage = max(1, (int) $request->query->get('equipmentPage', 1));
        $sublistPageSize = 10;

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
            'estimates' => $estimateRepository->findByProperty($property),
            'quotes' => $quoteRepository->findByProperty($property),
            'invoices' => $invoiceRepository->findByProperty($property),
            'calls' => $calls,
            'transcripts' => $callTranscriptRepository->findBySessions($calls),
            'auditLogs' => $auditLogRepository->findRecentByProperty($tenant, $property),
        ]);
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
}
