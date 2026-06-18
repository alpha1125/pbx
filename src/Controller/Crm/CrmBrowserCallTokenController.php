<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\User;
use App\Entity\UserTenantMembership;
use App\Repository\ContactRepository;
use App\Repository\PropertyRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\CrmBrowserCallTokenBrokerService;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TenantMembershipAccessService;
use App\Exception\BrowserCallTokenRateLimitException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CrmBrowserCallTokenController extends AbstractController
{
    #[Route('/crm/properties/{propertyId<\d+>}/contacts/{contactId<\d+>}/browser-call/prepare', name: 'crm_property_contact_browser_call_prepare', methods: ['POST'])]
    public function __invoke(
        int $propertyId,
        int $contactId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propertyRepository,
        ContactRepository $contactRepository,
        CrmBrowserCallTokenBrokerService $browserCallTokenBroker,
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
        if (is_string($token) && '' !== $token && !$this->isCsrfTokenValid('crm_browser_call_prepare_'.$property->getId().'_'.$contact->getId(), $token)) {
            return $this->respond($request, $propertyId, ['ok' => false, 'error' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $user = $this->getUser();
            if (!$user instanceof User) {
                throw new \RuntimeException('You must be logged in to prepare a browser call.');
            }

            $payload = $browserCallTokenBroker->prepare($property, $contact, $user);
        } catch (BrowserCallTokenRateLimitException $exception) {
            return $this->respond($request, $property->getId(), ['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (\Throwable $exception) {
            return $this->respond($request, $property->getId(), ['ok' => false, 'error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return $this->respond($request, $property->getId(), [
            'ok' => true,
            'callMode' => $payload['callMode'],
            'callSessionId' => $payload['callSession']->getId(),
            'providerSessionId' => $payload['callSession']->getProviderSessionId(),
            'approvedDestinationNumber' => $payload['approvedDestinationNumber'],
            'token' => $payload['token'],
            'tokenExpiresAt' => $payload['tokenExpiresAt']->format(DATE_ATOM),
            'statusStreamUrl' => $payload['statusStreamUrl'],
            'callSession' => [
                'id' => $payload['callSession']->getId(),
                'providerSessionId' => $payload['callSession']->getProviderSessionId(),
                'callMode' => $payload['callSession']->getCallMode(),
                'callState' => $payload['callSession']->getCallState(),
                'recordingState' => $payload['callSession']->getRecordingState(),
                'transcriptionState' => $payload['callSession']->getTranscriptionState(),
                'clientPhoneNumber' => $payload['callSession']->getClientPhoneNumber(),
            ],
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

        $this->addFlash($payload['ok'] ? 'success' : 'error', (string) ($payload['ok'] ? 'Browser call prepared.' : ($payload['error'] ?? 'Call could not be prepared.')));

        return $this->redirectToRoute('crm_property_show', ['id' => $propertyId]);
    }
}
