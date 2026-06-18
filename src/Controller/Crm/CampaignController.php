<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\Campaign;
use App\Repository\CampaignRepository;
use App\Service\AuditLogger;
use App\Service\CurrentTenantProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CampaignController extends AbstractController
{
    #[Route('/crm/campaigns', name: 'crm_campaign_index', methods: ['GET'])]
    public function index(
        CurrentTenantProviderInterface $tenantProvider,
        CampaignRepository $campaignRepository,
    ): Response {
        $tenant = $tenantProvider->requireCurrentTenant();

        return $this->render('crm/campaign/index.html.twig', [
            'tenant' => $tenant,
            'campaigns' => $campaignRepository->findByTenantOrdered($tenant),
            'campaignCount' => $campaignRepository->countByTenant($tenant),
        ]);
    }

    #[Route('/crm/campaigns/new', name: 'crm_campaign_new', methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $campaign = new Campaign($tenant, '');
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_campaign_form', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $this->applyCampaignForm($campaign, $request);
            $errors = $validator->validate($campaign);
            if (0 === count($errors)) {
                $entityManager->persist($campaign);
                $auditLogger->log($tenant, 'campaign', 'new', 'campaign.created', null, [
                    'name' => $campaign->getName(),
                    'campaignType' => $campaign->getCampaignType(),
                    'status' => $campaign->getStatus(),
                ]);
                $entityManager->flush();
                $this->addFlash('success', 'Campaign created.');

                return $this->redirectToRoute('crm_campaign_index');
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/campaign/form.html.twig', [
            'campaign' => $campaign,
            'formAction' => $this->generateUrl('crm_campaign_new'),
            'title' => 'Add Campaign',
            'errors' => iterator_to_array($errors),
            'campaignTypeChoices' => Campaign::getCampaignTypeChoices(),
            'statusChoices' => Campaign::getStatusChoices(),
        ]);
    }

    #[Route('/crm/campaigns/{id<\d+>}/edit', name: 'crm_campaign_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        CampaignRepository $campaignRepository,
        ValidatorInterface $validator,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $campaign = $campaignRepository->findOneByTenantAndId($tenant, $id);
        if (null === $campaign) {
            throw $this->createNotFoundException('Campaign not found.');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('crm_campaign_form_'.$campaign->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $before = [
                'name' => $campaign->getName(),
                'campaignType' => $campaign->getCampaignType(),
                'audienceDescription' => $campaign->getAudienceDescription(),
                'scheduledDate' => $campaign->getScheduledDate()?->format('Y-m-d'),
                'status' => $campaign->getStatus(),
                'notes' => $campaign->getNotes(),
            ];

            $this->applyCampaignForm($campaign, $request);
            $errors = $validator->validate($campaign);
            if (0 === count($errors)) {
                $auditLogger->log($tenant, 'campaign', (string) $campaign->getId(), 'campaign.updated', $before, [
                    'name' => $campaign->getName(),
                    'campaignType' => $campaign->getCampaignType(),
                    'audienceDescription' => $campaign->getAudienceDescription(),
                    'scheduledDate' => $campaign->getScheduledDate()?->format('Y-m-d'),
                    'status' => $campaign->getStatus(),
                    'notes' => $campaign->getNotes(),
                ]);
                $entityManager->flush();
                $this->addFlash('success', 'Campaign updated.');

                return $this->redirectToRoute('crm_campaign_index');
            }

            $this->addFlash('error', (string) $errors[0]->getMessage());
        }

        return $this->render('crm/campaign/form.html.twig', [
            'campaign' => $campaign,
            'formAction' => $this->generateUrl('crm_campaign_edit', ['id' => $campaign->getId()]),
            'title' => 'Edit Campaign: '.$campaign->getName(),
            'errors' => iterator_to_array($errors),
            'campaignTypeChoices' => Campaign::getCampaignTypeChoices(),
            'statusChoices' => Campaign::getStatusChoices(),
        ]);
    }

    private function applyCampaignForm(Campaign $campaign, Request $request): void
    {
        $campaign
            ->setName((string) $request->request->get('name', $campaign->getName()))
            ->setCampaignType((string) $request->request->get('campaignType', $campaign->getCampaignType()))
            ->setAudienceDescription((string) $request->request->get('audienceDescription', $campaign->getAudienceDescription()))
            ->setScheduledDate($this->parseDateOrNull($request->request->get('scheduledDate')))
            ->setStatus((string) $request->request->get('status', $campaign->getStatus()))
            ->setNotes($this->nullableString($request->request->get('notes')));
    }

    private function nullableString(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    private function parseDateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return false === $date ? null : $date;
    }
}
