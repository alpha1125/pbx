<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Repository\ContactRepository;
use App\Repository\PropertyRepository;
use App\Entity\UserTenantMembership;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\CrmClickToCallService;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TenantMembershipAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CrmClickToCallController extends AbstractController
{
    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/bridge-call', name: 'crm_property_contact_bridge_call', methods: ['POST'])]
    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/click-to-call', name: 'crm_property_contact_click_to_call', methods: ['POST'])]
    public function __invoke(
        int $propertyId,
        int $contactId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        ContactRepository $contactRepository,
        CrmClickToCallService $crmClickToCallService,
        TenantMembershipAccessService $membershipAccess,
    ): JsonResponse|RedirectResponse {
        $tenant = $tenantProvider->requireCurrentTenant();
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_SALES,
        ]);
        $property = $propertyRepository->findOneByTenantAndId($tenant, $propertyId);
        $contact = $contactRepository->findOneByTenantAndId($tenant, $contactId);

        if (null === $property || null === $contact) {
            return $this->respond($request, $propertyId, ['ok' => false, 'error' => 'Property or contact not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $property);
        $this->denyAccessUnlessGranted(TenantScopedEntityVoter::VIEW, $contact);

        $token = $request->request->get('_token');
        if (is_string($token) && '' !== $token && !$this->isCsrfTokenValid('crm_bridge_call_'.$property->getId().'_'.$contact->getId(), $token)) {
            return $this->respond($request, $property->getId(), ['ok' => false, 'error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $session = $crmClickToCallService->start($property, $contact);
        } catch (\Throwable $exception) {
            return $this->respond($request, $property->getId(), ['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->respond($request, $property->getId(), [
            'ok' => true,
            'callSessionId' => $session->getId(),
            'status' => $session->getStatus(),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(Request $request, int $propertyId, array $payload, int $status = Response::HTTP_OK): JsonResponse|RedirectResponse
    {
        if ('json' === $request->getPreferredFormat()) {
            return $this->json($payload, $status);
        }

        $this->addFlash($payload['ok'] ? 'success' : 'error', (string) ($payload['ok'] ? 'Bridge call started.' : ($payload['error'] ?? 'Call could not be started.')));

        return $this->redirectToRoute('crm_property_show', ['id' => $propertyId]);
    }
}
