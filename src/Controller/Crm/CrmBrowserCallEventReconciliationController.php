<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\CallSession;
use App\Entity\UserTenantMembership;
use App\Repository\ContactRepository;
use App\Repository\PropertyRepository;
use App\Security\Voter\TenantScopedEntityVoter;
use App\Service\AuditLogger;
use App\Service\BrowserCallEventReconcilerService;
use App\Service\CurrentTenantProviderInterface;
use App\Service\TenantMembershipAccessService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives browser SDK events and reconciles them with Telnyx webhook state.
 *
 * Both Browser Call and Bridge Call sessions can report their local state here,
 * but only Browser Call mode sessions trigger reconciliation (Bridge Call uses
 * the existing Telnyx webhook path).
 */
final class CrmBrowserCallEventReconciliationController extends AbstractController
{
    #[Route('/api/calls/{providerSessionId}/browser-events', name: 'api_call_browser_events', methods: ['POST'])]
    public function __invoke(
        string $providerSessionId,
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
        PropertyRepository $propRepo,
        ContactRepository $contactRepo,
        TenantMembershipAccessService $membershipAccess,
        AuditLogger $auditLogger,
        BrowserCallEventReconcilerService $reconciler,
    ): JsonResponse {
        $tenant = $tenantProvider->requireCurrentTenant();

        // Minimal tenant scope check: the caller must have an active membership.
        $membershipAccess->requireAnyRole([
            UserTenantMembership::ROLE_TENANT_ADMIN,
            UserTenantMembership::ROLE_DISPATCH,
            UserTenantMembership::ROLE_SALES,
        ]);

        $payload = json_decode((string) $request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $eventType = is_string($payload['event'] ?? null) ? trim($payload['event']) : '';
        if ('' === $eventType) {
            return $this->json(['ok' => false, 'error' => 'Event type is required.'], Response::HTTP_BAD_REQUEST);
        }

        $callId = is_string($payload['callId'] ?? null) ? trim($payload['callId']) : null;
        $destinationNumber = is_string($payload['destinationNumber'] ?? null) ? trim($payload['destinationNumber']) : null;
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        // Look up the call session to verify tenant membership and mode.
        /** @var \App\Repository\CallSessionRepository $sessionRepo */
        $sessionRepo = static::getContainer()->get(\App\Repository\CallSessionRepository::class);
        $callSession = $sessionRepo->findOneBy(['providerSessionId' => trim($providerSessionId)]);

        if (null === $callSession) {
            return $this->json(['ok' => false, 'error' => 'Call session not found.'], Response::HTTP_NOT_FOUND);
        }

        // Verify tenant scope on the call session.
        if (null === $callSession->getTenant() || $callSession->getTenant()->getId() !== $tenant->getId()) {
            return $this->json(['ok' => false, 'error' => 'Call session does not belong to the current tenant.'], Response::HTTP_FORBIDDEN);
        }

        // Audit log before reconciliation.
        $auditLogger->log(
            $tenant,
            'browser_event',
            (string) ($callSession->getId() ?? 'new'),
            'call.browser_event_received',
            null,
            [
                'eventType' => $eventType,
                'callMode' => $callSession->getCallMode(),
                'callState' => $callSession->getCallState(),
            ],
            [
                'providerSessionId' => $callSession->getProviderSessionId(),
                'destinationNumber' => $destinationNumber,
            ],
        );

        // Run reconciliation (dedup + stale rejection + state update).
        $reconciler->reconcile(
            trim($providerSessionId),
            $eventType,
            $callId,
            $destinationNumber,
            $meta,
        );

        return $this->json([
            'ok' => true,
            'eventType' => $eventType,
            'callState' => $callSession->getCallState(),
        ]);
    }
}
