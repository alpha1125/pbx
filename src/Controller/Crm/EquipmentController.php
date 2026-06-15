<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Equipment;
use App\Repository\EquipmentRepository;
use App\Repository\PropertyRepository;
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

final class EquipmentController extends AbstractController
{
    #[Route('/crm/properties/{propertyId<\d+>}/equipment/new', name: 'crm_equipment_new', methods: ['GET', 'POST'])]
    public function create(
        int $propertyId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        CrmInputNormalizer $normalizer,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $propertyId);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);

        $equipment = new Equipment($tenant, $property, Equipment::TYPE_OTHER);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_equipment_form', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $this->applyEquipmentForm($equipment, $request, $normalizer);
            $errors = $validator->validate($equipment);
            if (0 === count($errors)) {
                $entityManager->persist($equipment);
                $auditLogger->log($tenant, 'equipment', 'new', 'equipment.created', null, [
                    'type' => $equipment->getEquipmentType(),
                    'propertyId' => $property->getId(),
                ]);
                $entityManager->flush();
                $this->addFlash('success', 'Equipment created.');

                return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/equipment/form.html.twig', [
            'equipment' => $equipment,
            'property' => $property,
            'formAction' => $this->generateUrl('crm_equipment_new', ['propertyId' => $property->getId()]),
            'title' => 'Add Equipment',
        ]);
    }

    #[Route('/crm/properties/{propertyId<\d+>}/equipment/{equipmentId<\d+>}/edit', name: 'crm_equipment_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $propertyId,
        int $equipmentId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        EquipmentRepository $equipmentRepository,
        CrmInputNormalizer $normalizer,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $propertyId);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $equipment = $equipmentRepository->findOneByTenantPropertyAndId($equipmentId, $property);
        if (null === $equipment) {
            throw $this->createNotFoundException('Equipment not found.');
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::EDIT, $property);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_equipment_form', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $this->applyEquipmentForm($equipment, $request, $normalizer);
            $errors = $validator->validate($equipment);
            if (0 === count($errors)) {
                $equipment->touch();
                $auditLogger->log($tenant, 'equipment', (string) $equipment->getId(), 'equipment.updated');
                $entityManager->flush();
                $this->addFlash('success', 'Equipment updated.');

                return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/equipment/form.html.twig', [
            'equipment' => $equipment,
            'property' => $property,
            'formAction' => $this->generateUrl('crm_equipment_edit', ['propertyId' => $property->getId(), 'equipmentId' => $equipment->getId()]),
            'title' => 'Edit Equipment',
        ]);
    }

    #[Route('/crm/properties/{propertyId<\d+>}/equipment/{equipmentId<\d+>}/archive', name: 'crm_equipment_archive', methods: ['POST'])]
    public function archive(
        int $propertyId,
        int $equipmentId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        EquipmentRepository $equipmentRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $property = $propertyRepository->findOneByTenantAndId($tenant, $propertyId);
        if (null === $property) {
            throw $this->createNotFoundException('Property not found.');
        }

        $equipment = $equipmentRepository->findOneByTenantPropertyAndId($equipmentId, $property);
        if (null === $equipment) {
            throw $this->createNotFoundException('Equipment not found.');
        }

        if (!$this->isCsrfTokenValid('crm_equipment_archive_'.$equipment->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $equipment->archive()->touch();
        $auditLogger->log($tenant, 'equipment', (string) $equipment->getId(), 'equipment.archived');
        $entityManager->flush();
        $this->addFlash('success', 'Equipment archived.');

        return $this->redirectToRoute('crm_property_show', ['id' => $property->getId()]);
    }

    private function applyEquipmentForm(Equipment $equipment, Request $request, CrmInputNormalizer $normalizer): void
    {
        $equipment
            ->setEquipmentType((string) ($normalizer->stringOrNull($request->request->get('equipmentType')) ?? Equipment::TYPE_OTHER))
            ->setBrand($normalizer->stringOrNull($request->request->get('brand')))
            ->setModelNumber($normalizer->stringOrNull($request->request->get('modelNumber')))
            ->setSerialNumber($normalizer->stringOrNull($request->request->get('serialNumber')))
            ->setInstalledAt($this->dateOrNull($request->request->get('installedAt')))
            ->setWarrantyExpiresAt($this->dateOrNull($request->request->get('warrantyExpiresAt')))
            ->setStatus((string) ($normalizer->stringOrNull($request->request->get('status')) ?? Equipment::STATUS_UNKNOWN))
            ->setNotes($normalizer->stringOrNull($request->request->get('notes')));
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return new \DateTimeImmutable(trim($value));
    }
}
