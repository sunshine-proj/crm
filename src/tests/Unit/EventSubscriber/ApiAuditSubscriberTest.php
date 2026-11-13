<?php

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\ApiAuditSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiAuditSubscriberTest extends TestCase
{
    /** @var list<array{level: string, context: array<string, mixed>}> */
    private array $records = [];

    public function test_a_successful_write_is_recorded_with_actor_route_and_record_ids_but_no_payload(): void
    {
        $request = Request::create('/api/invoices/12/payments?token=secret', 'POST', content: '{"amount":"30.00","reference":"private"}');
        $request->attributes->set('_route', 'api_sales_payments_create');
        $request->attributes->set('_route_params', ['id' => '12', 'slug' => 'free text']);

        $this->handle($request, 201, 'finance@example.test');

        self::assertCount(1, $this->records);
        self::assertSame('info', $this->records[0]['level']);
        self::assertSame(
            ['action' => 'api.write', 'route' => 'api_sales_payments_create', 'method' => 'POST', 'path' => '/api/invoices/12/payments', 'status' => 201, 'actor' => 'finance@example.test', 'record_ids' => ['id' => 12], 'ip' => '127.0.0.1'],
            $this->records[0]['context'],
        );
        self::assertStringNotContainsString('secret', json_encode($this->records));
        self::assertStringNotContainsString('private', json_encode($this->records));
    }

    public function test_reads_are_not_recorded_unless_they_are_refused(): void
    {
        $this->handle(Request::create('/api/clients', 'GET'), 200, 'user@example.test');
        self::assertSame([], $this->records);

        $this->handle(Request::create('/api/finance/journal-entries', 'GET'), 403, 'user@example.test');
        $this->handle(Request::create('/api/clients', 'GET'), 401, null);

        self::assertSame(['warning', 'warning'], array_column($this->records, 'level'));
        self::assertSame('api.refused', $this->records[0]['context']['action']);
        self::assertSame('user@example.test', $this->records[0]['context']['actor']);
        self::assertNull($this->records[1]['context']['actor']);
    }

    public function test_failed_writes_are_warnings_and_rejected_input_is_still_recorded(): void
    {
        $this->handle(Request::create('/api/clients', 'POST'), 500, 'admin@example.test');
        $this->handle(Request::create('/api/clients', 'POST'), 400, 'admin@example.test');

        self::assertSame(['warning', 'info'], array_column($this->records, 'level'));
        self::assertSame(400, $this->records[1]['context']['status']);
    }

    public function test_requests_outside_the_api_and_sub_requests_are_ignored(): void
    {
        $this->handle(Request::create('/health', 'POST'), 200, null);
        $this->handle(Request::create('/api/clients', 'POST'), 201, 'admin@example.test', HttpKernelInterface::SUB_REQUEST);

        self::assertSame([], $this->records);
    }

    private function handle(Request $request, int $status, ?string $actor, int $type = HttpKernelInterface::MAIN_REQUEST): void
    {
        $logger = new class($this->records) extends AbstractLogger {
            /** @param list<array{level: string, context: array<string, mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'context' => $context];
            }
        };
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($actor === null ? null : (new User())->setEmail($actor));

        (new ApiAuditSubscriber($logger, $security))->onResponse(
            new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, $type, new Response('', $status)),
        );
    }
}
