<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\NoCurrentTenantException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NoCurrentTenantSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof NoCurrentTenantException) {
            return;
        }

        $request = $event->getRequest();
        if ('json' === $request->getPreferredFormat()) {
            $event->setResponse(new JsonResponse([
                'ok' => false,
                'error' => 'No tenant membership is active for the current user.',
            ], 409));

            return;
        }

        if ('crm_no_tenant' === $request->attributes->get('_route')) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('crm_no_tenant')));
    }
}
