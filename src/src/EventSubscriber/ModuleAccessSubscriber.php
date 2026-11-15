<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Module\ModuleCatalog;
use App\Service\Module\ModuleGate;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Blocks API routes of modules that are switched off (404) or hidden from the caller's roles (403), after the
 * firewall has authenticated the request and before any controller runs. Core routes are never blocked.
 */
final class ModuleAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ModuleGate $gate,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // The firewall listens at priority 8; run after it so the user is known.
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !str_starts_with($route, 'api_')) {
            return;
        }
        $user = $this->security->getUser();
        foreach (ModuleCatalog::modulesForRoute($route) ?? [] as $module) {
            $label = ModuleCatalog::MODULES[$module]['label'];
            if (!$this->gate->isEnabled($module)) {
                $event->setResponse(new JsonResponse(['error' => sprintf('The %s module is turned off for this organization.', $label), 'module' => $module], JsonResponse::HTTP_NOT_FOUND));

                return;
            }
            if (!$this->gate->isAvailableTo($module, $user instanceof User ? $user : null)) {
                $event->setResponse(new JsonResponse(['error' => sprintf('The %s module is not available for your role.', $label), 'module' => $module], JsonResponse::HTTP_FORBIDDEN));

                return;
            }
        }
    }
}
