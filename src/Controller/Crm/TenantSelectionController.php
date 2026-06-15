<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Entity\User;
use App\Service\CurrentTenantProviderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class TenantSelectionController extends AbstractController
{
    #[Route('/crm/tenant/select', name: 'crm_tenant_select', methods: ['POST'])]
    public function __invoke(
        Request $request,
        CurrentTenantProviderInterface $tenantProvider,
    ): RedirectResponse {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('crm_tenant_select', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $tenantId = $request->request->get('tenantId');
        if ((!is_int($tenantId) && !(is_string($tenantId) && ctype_digit($tenantId))) || !$tenantProvider->selectTenant($user, (int) $tenantId)) {
            $this->addFlash('error', 'Tenant could not be selected.');
        }

        return $this->redirect((string) ($request->headers->get('referer') ?: $this->generateUrl('crm_home')));
    }
}
