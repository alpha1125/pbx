<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\MaintenancePlan;
use App\Repository\MaintenancePlanRepository;
use App\Service\AuditLogger;
use App\Service\CurrentTenantProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class MaintenancePlanController extends AbstractController
{
    #[Route('/crm/maintenance-plans', name: 'crm_maintenance_plan_index', methods: ['GET'])]
    public function index(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        MaintenancePlanRepository $repo,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();

        return $this->render('crm/maintenance_plan/index.html.twig', [
            'plans' => $repo->findByTenantOrdered($tenant, 50),
            'drafts' => $repo->findDraftsByTenant($tenant),
        ]);
    }

    #[Route('/crm/maintenance-plans/new', name: 'crm_maintenance_plan_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        MaintenancePlanRepository $repo,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();

        $plan = new MaintenancePlan($tenant, '');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_maintenance_plan_form', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $plan->setName($request->request->get('name', ''));
            $plan->setPlanType($request->request->get('planType', MaintenancePlan::PLAN_BRONZE));
            $plan->setVisitFrequencyDays((int) ($request->request->get('visitFrequencyDays', 180)));
            $plan->setDiscountPercentage((int) ($request->request->get('discountPercentage', 0)));
            $plan->setPriorityScheduling((bool) $request->request->get('priorityScheduling', false));
            $plan->setIsActive($request->request->has('isActive') ? true : false);
            if ($startDate = $request->request->get('startDate')) {
                $plan->setStartDate(\DateTimeImmutable::createFromFormat('Y-m-d', $startDate) ?: null);
            }

            $errors = $validator->validate($plan);
            if (0 === count($errors)) {
                $entityManager->persist($plan);
                $auditLogger->log($tenant, 'maintenance_plan', 'new', 'maintenance_plan.created', null, [
                    'name' => $plan->getName(),
                    'planType' => $plan->getPlanType(),
                ]);
                $entityManager->flush();
                $this->addFlash('success', 'Maintenance plan created.');

                return $this->redirectToRoute('crm_maintenance_plan_index');
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/maintenance_plan/form.html.twig', [
            'plan' => $plan,
            'formAction' => $this->generateUrl('crm_maintenance_plan_new'),
            'title' => 'Add Maintenance Plan',
            'errors' => iterator_to_array($errors),
        ]);
    }

    #[Route('/crm/maintenance-plans/{id}/edit', name: 'crm_maintenance_plan_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        MaintenancePlanRepository $repo,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $plan = $repo->findOneByTenantAndId($tenant, $id);

        if (null === $plan) {
            throw $this->createNotFoundException('Maintenance plan not found.');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_maintenance_plan_form_' . $plan->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $plan->setName($request->request->get('name', $plan->getName()));
            $plan->setPlanType($request->request->get('planType', $plan->getPlanType()));
            $plan->setVisitFrequencyDays((int) ($request->request->get('visitFrequencyDays', $plan->getVisitFrequencyDays())));
            $plan->setDiscountPercentage((int) ($request->request->get('discountPercentage', $plan->getDiscountPercentage())));
            $plan->setPriorityScheduling((bool) $request->request->get('priorityScheduling', false));
            $plan->setIsActive($request->request->has('isActive') ? true : false);

            $errors = $validator->validate($plan);
            if (0 === count($errors)) {
                $auditLogger->log($tenant, 'maintenance_plan', 'edit', 'maintenance_plan.updated', null, [
                    'id' => $plan->getId(),
                ]);
                $entityManager->flush();
                $this->addFlash('success', 'Maintenance plan updated.');

                return $this->redirectToRoute('crm_maintenance_plan_index');
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/maintenance_plan/form.html.twig', [
            'plan' => $plan,
            'formAction' => $this->generateUrl('crm_maintenance_plan_edit', ['id' => $plan->getId()]),
            'title' => 'Edit Maintenance Plan: ' . $plan->getName(),
            'errors' => [],
        ]);
    }

    #[Route('/crm/maintenance-plans/{id}/deactivate', name: 'crm_maintenance_plan_deactivate', methods: ['POST'])]
    public function deactivate(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        MaintenancePlanRepository $repo,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $plan = $repo->findOneByTenantAndId($tenant, $id);

        if (null === $plan || !$this->isCsrfTokenValid('crm_maintenance_plan_deactivate_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token or plan not found.');
        }

        $plan->deactivate();
        $auditLogger->log($tenant, 'maintenance_plan', 'deactivate', 'maintenance_plan.deactivated', null, [
            'id' => $plan->getId(),
        ]);
        $entityManager->flush();
        $this->addFlash('success', 'Maintenance plan deactivated.');

        return $this->redirectToRoute('crm_maintenance_plan_index');
    }

    #[Route('/crm/maintenance-plans/{id}/activate', name: 'crm_maintenance_plan_activate', methods: ['POST'])]
    public function activate(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        MaintenancePlanRepository $repo,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $plan = $repo->findOneByTenantAndId($tenant, $id);

        if (null === $plan || !$this->isCsrfTokenValid('crm_maintenance_plan_activate_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token or plan not found.');
        }

        $plan->activate();
        $auditLogger->log($tenant, 'maintenance_plan', 'activate', 'maintenance_plan.activated', null, [
            'id' => $plan->getId(),
        ]);
        $entityManager->flush();
        $this->addFlash('success', 'Maintenance plan activated.');

        return $this->redirectToRoute('crm_maintenance_plan_index');
    }
}
