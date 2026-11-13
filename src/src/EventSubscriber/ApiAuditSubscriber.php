<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Baseline audit trail for the whole API: every state-changing request and every refused request is written
 * to the audit channel with who, what route, which record ids and the outcome. Controllers add richer,
 * domain-specific audit events on top; this subscriber guarantees that no write or denial goes unrecorded.
 * Request bodies, query strings and tokens are never logged.
 */
final class ApiAuditSubscriber implements EventSubscriberInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        #[Autowire(service: 'monolog.logger.audit')] private readonly LoggerInterface $auditLogger,
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }
        $status = $event->getResponse()->getStatusCode();
        $refused = $status === 401 || $status === 403;
        if (!$refused && in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }

        $context = [
            'action' => 'api.'.($refused ? 'refused' : 'write'),
            'route' => $request->attributes->get('_route'),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $status,
            'actor' => $this->security->getUser()?->getUserIdentifier(),
            'record_ids' => $this->recordIds((array) $request->attributes->get('_route_params', [])),
            'ip' => $request->getClientIp(),
        ];
        if ($refused || $status >= 500) {
            $this->auditLogger->warning('API request refused or failed.', $context);

            return;
        }
        $this->auditLogger->info('API write request.', $context);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, int> numeric route parameters only, so free text never reaches the log
     */
    private function recordIds(array $parameters): array
    {
        $ids = [];
        foreach ($parameters as $name => $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[$name] = (int) $value;
            }
        }

        return $ids;
    }
}
