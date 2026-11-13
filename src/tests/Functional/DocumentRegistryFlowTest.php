<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Document registry API. Structure: ACME (head: admin) > SALES (head: manager; staff: user) and IT (staff: finance).
 * "clerk" holds ROLE_DMS_ADMIN only.
 */
final class DocumentRegistryFlowTest extends ApiTestCase
{
    /** @var array<string, int> */
    private array $ids = [];
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $clerk = (new User())->setEmail('clerk@example.test')->setPasswordHash('x')->setRoles(['ROLE_DMS_ADMIN']);
        $entityManager->persist($clerk);
        $entityManager->flush();
        $this->users['clerk'] = $clerk;

        foreach ([['ACME', 'organization', null], ['SALES', 'unit', 'ACME'], ['IT', 'unit', 'ACME']] as [$code, $kind, $parent]) {
            $body = ['code' => $code, 'name' => ucfirst(strtolower($code)), 'kind' => $kind] + ($parent ? ['parent_id' => $this->ids[$parent]] : []);
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/units', json_encode($body)), $this->lastBody);
            $this->ids[$code] = $this->json()['id'];
        }
        foreach ([['admin', 'ACME', true], ['manager', 'SALES', true], ['user', 'SALES', false], ['finance', 'IT', false]] as [$who, $unit, $head]) {
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/positions', json_encode(['unit_id' => $this->ids[$unit], 'title' => $who, 'is_head' => $head])));
            $position = $this->json()['id'];
            self::assertSame(201, $this->callAs('admin', 'POST', '/api/org/employments', json_encode(['user_id' => $this->users[$who]->getId(), 'position_id' => $position, 'starts_on' => '2026-01-01'])), $this->lastBody);
        }
        $this->ids['MEMO'] = $this->createType(['code' => 'MEMO', 'name' => 'Memo', 'requires_approval' => false, 'number_pattern' => 'MEM-{YYYY}-{SEQ:5}']);
        $this->ids['ORDER'] = $this->createType(['code' => 'ORDER', 'name' => 'Order', 'requires_approval' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_a_memo_moves_from_draft_to_registered_with_gapless_numbers_and_wider_visibility(): void
    {
        $id = $this->createDocument('user', ['type_id' => $this->ids['MEMO'], 'title' => 'Pricing update', 'tags' => ['Pricing', 'q3']]);
        $document = $this->json();
        self::assertSame('draft', $document['status']);
        self::assertSame('Sales', $document['owner_unit']['name'], 'Owner unit defaults to the author\'s unit.');
        self::assertTrue($document['permissions']['can_edit']);
        self::assertFalse($document['permissions']['can_register']);

        // Drafts: visible to the author and to heads of the owner unit, hidden from others (404, not 403).
        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/documents/$id"));
        self::assertFalse($this->json()['permissions']['can_manage']);
        self::assertSame(404, $this->callAs('finance', 'GET', "/api/dms/documents/$id"));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/dms/documents'));
        self::assertSame(0, $this->json()['total']);
        self::assertSame(403, $this->callAs('manager', 'PATCH', "/api/dms/documents/$id", '{"title":"Hijack"}'));

        self::assertSame(201, $this->upload('user', "/api/dms/documents/$id/versions", $this->textFile('New price list applies to wholesale customers.', 'prices.txt'), ['comment' => 'First draft']), $this->lastBody);
        $version = $this->json()['current_version'];
        self::assertSame(1, $version['number']);
        self::assertSame(hash('sha256', 'New price list applies to wholesale customers.'), $version['sha256']);
        self::assertTrue($version['has_text']);
        self::assertTrue($version['previewable']);

        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/documents?q=WHOLESALE%20price'));
        self::assertSame([$id], array_column($this->json()['items'], 'id'), 'File content is searchable.');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/documents?q=a_b'));
        self::assertSame(0, $this->json()['total'], 'LIKE wildcards in a query are literal characters.');
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/documents?tag=pricing'));
        self::assertSame(1, $this->json()['total']);
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/documents?tag=pric'));
        self::assertSame(0, $this->json()['total'], 'Tags match exactly.');

        self::assertSame(403, $this->callAs('user', 'POST', "/api/dms/documents/$id/register"));
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$id/register"), $this->lastBody);
        $registered = $this->json();
        $year = date('Y');
        self::assertSame("MEM-$year-00001", $registered['registration_number']);
        self::assertSame('in_force', $registered['status']);
        self::assertSame('clerk@example.test', $registered['registered_by']);
        self::assertContains('registered', array_column($registered['events'], 'action'));
        self::assertSame(409, $this->callAs('clerk', 'POST', "/api/dms/documents/$id/register"));

        $second = $this->createDocument('user', ['type_id' => $this->ids['MEMO'], 'title' => 'Second memo']);
        self::assertSame(409, $this->callAs('clerk', 'POST', "/api/dms/documents/$second/register"), 'No file yet.');
        self::assertSame(201, $this->upload('user', "/api/dms/documents/$second/versions", $this->textFile('Two', 'two.txt')));
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$second/register"));
        self::assertSame("MEM-$year-00002", $this->json()['registration_number'], 'The failed attempt did not use up a number.');

        // Registered: readable by everyone, frozen for everyone.
        self::assertSame(200, $this->callAs('finance', 'GET', "/api/dms/documents/$id"));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/dms/documents?q=mem-'.$year));
        self::assertSame(2, $this->json()['total']);
        self::assertSame(409, $this->callAs('user', 'PATCH', "/api/dms/documents/$id", '{"title":"Changed"}'));
        self::assertSame(409, $this->upload('user', "/api/dms/documents/$id/versions", $this->textFile('v2', 'v2.txt')));
        self::assertSame(409, $this->callAs('user', 'DELETE', "/api/dms/documents/$id"));

        self::assertSame(200, $this->callAs('finance', 'GET', "/api/dms/documents/$id/versions/1/file?inline=1"));
        $response = $this->client->getResponse();
        self::assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame(404, $this->callAs('finance', 'GET', "/api/dms/documents/$id/versions/9/file"));
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$id"));
        $downloads = array_values(array_filter($this->json()['events'], static fn (array $event): bool => $event['action'] === 'downloaded'));
        self::assertSame('finance@example.test', $downloads[0]['actor']);

        self::assertSame(200, $this->callAs('finance', 'GET', "/api/dms/documents/$id/card.pdf"));
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', $this->lastBody);

        self::assertSame(403, $this->callAs('user', 'POST', "/api/dms/documents/$id/archive"));
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$id/archive"));
        self::assertSame('archived', $this->json()['status']);
        self::assertSame(409, $this->callAs('clerk', 'POST', "/api/dms/documents/$id/archive"));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/documents?status=archived'));
        self::assertSame(1, $this->json()['total']);
    }

    public function test_approval_types_required_fields_and_future_effective_dates_gate_registration(): void
    {
        $order = $this->createDocument('user', ['type_id' => $this->ids['ORDER'], 'title' => 'Order']);
        self::assertSame(201, $this->upload('user', "/api/dms/documents/$order/versions", $this->textFile('Order text', 'order.txt')));
        self::assertSame(409, $this->callAs('clerk', 'POST', "/api/dms/documents/$order/register"));
        self::assertStringContainsString('must be approved', $this->lastBody);

        $policyType = $this->createType(['code' => 'POL', 'name' => 'Policy', 'requires_approval' => false, 'required_fields' => ['summary', 'effective_on']]);
        $policy = $this->createDocument('user', ['type_id' => $policyType, 'title' => 'Policy']);
        self::assertSame(['summary', 'effective_on'], $this->json()['missing_required_fields']);
        self::assertSame(201, $this->upload('user', "/api/dms/documents/$policy/versions", $this->textFile('Policy', 'policy.txt')));
        self::assertSame(409, $this->callAs('clerk', 'POST', "/api/dms/documents/$policy/register"));
        self::assertStringContainsString('summary, effective_on', $this->lastBody);

        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        self::assertSame(200, $this->callAs('user', 'PATCH', "/api/dms/documents/$policy", json_encode(['summary' => 'Why', 'effective_on' => $future])), $this->lastBody);
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$policy/register"), $this->lastBody);
        self::assertSame('registered', $this->json()['status'], 'Takes effect later.');
        self::assertSame('POL-'.date('Y').'-0001', $this->json()['registration_number']);
    }

    public function test_confidential_documents_are_visible_only_to_the_author_named_readers_and_clerks(): void
    {
        $id = $this->createDocument('user', ['type_id' => $this->ids['MEMO'], 'title' => 'Salary review', 'confidentiality' => 'confidential']);
        self::assertSame(201, $this->upload('user', "/api/dms/documents/$id/versions", $this->textFile('Salaries', 's.txt')));
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$id/register"));

        self::assertSame(404, $this->callAs('manager', 'GET', "/api/dms/documents/$id"), 'Even the unit head.');
        self::assertSame(404, $this->callAs('finance', 'GET', "/api/dms/documents/$id/versions/1/file"));
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/dms/documents'));
        self::assertSame(0, $this->json()['total']);

        self::assertSame(200, $this->callAs('user', 'PUT', "/api/dms/documents/$id/readers", json_encode(['user_ids' => [$this->users['finance']->getId(), $this->users['user']->getId()]])), $this->lastBody);
        self::assertSame(['finance@example.test'], array_column($this->json()['readers'], 'email'), 'The author is not listed as a reader.');
        self::assertSame(404, $this->callAs('user', 'PUT', "/api/dms/documents/$id/readers", '{"user_ids":[99999]}'));

        self::assertSame(200, $this->callAs('finance', 'GET', "/api/dms/documents/$id"));
        $confidentialReads = array_filter($this->auditRecords(), static fn (array $record): bool => $record['message'] === 'Confidential document read.');
        self::assertNotEmpty($confidentialReads);
        self::assertSame(200, $this->callAs('finance', 'GET', '/api/dms/documents'));
        self::assertSame(1, $this->json()['total']);
        self::assertSame(403, $this->callAs('finance', 'PUT', "/api/dms/documents/$id/readers", '{"user_ids":[]}'), 'Readers cannot share further.');
        self::assertSame(200, $this->callAs('clerk', 'GET', "/api/dms/documents/$id"));
    }

    public function test_check_out_blocks_other_editors_until_checked_in_or_overridden_by_a_clerk(): void
    {
        $id = $this->createDocument('user', ['type_id' => $this->ids['MEMO'], 'title' => 'Locked']);
        self::assertSame(200, $this->callAs('user', 'POST', "/api/dms/documents/$id/checkout"), $this->lastBody);
        self::assertSame('user@example.test', $this->json()['checked_out_by']['email']);
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/documents/$id/checkout"));

        self::assertSame(409, $this->callAs('clerk', 'PATCH', "/api/dms/documents/$id", '{"title":"Clerk edit"}'));
        self::assertSame(409, $this->callAs('clerk', 'POST', "/api/dms/documents/$id/checkout"));
        self::assertSame(403, $this->callAs('manager', 'POST', "/api/dms/documents/$id/checkin"));
        self::assertSame(200, $this->callAs('clerk', 'POST', "/api/dms/documents/$id/checkout", '{"force":true}'));
        self::assertSame('clerk@example.test', $this->json()['checked_out_by']['email']);
        self::assertContains('lock_overridden', array_column($this->json()['events'], 'action'));

        self::assertSame(409, $this->upload('user', "/api/dms/documents/$id/versions", $this->textFile('mine', 'm.txt')));
        self::assertSame(201, $this->upload('clerk', "/api/dms/documents/$id/versions", $this->textFile('clerk version', 'c.txt'), ['check_in' => '1']));
        self::assertNull($this->json()['checked_out_by'], 'Uploading with check_in releases the lock.');
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/documents/$id/checkin"));
        self::assertSame(200, $this->callAs('user', 'PATCH', "/api/dms/documents/$id", '{"title":"Unlocked again"}'));
    }

    public function test_attachments_and_links(): void
    {
        $a = $this->createDocument('user', ['type_id' => $this->ids['MEMO'], 'title' => 'Main']);
        $b = $this->createDocument('user', ['type_id' => $this->ids['MEMO'], 'title' => 'Earlier memo']);
        $hidden = $this->createDocument('finance', ['type_id' => $this->ids['MEMO'], 'title' => 'Finance draft']);

        self::assertSame(201, $this->upload('user', "/api/dms/documents/$a/attachments", $this->textFile('annex', 'annex.csv')), $this->lastBody);
        $attachment = $this->json()['attachments'][0];
        self::assertSame('annex.csv', $attachment['original_name']);
        self::assertSame(200, $this->callAs('manager', 'GET', "/api/dms/documents/$a/attachments/{$attachment['id']}/file"));
        self::assertStringStartsWith('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame(404, $this->callAs('user', 'GET', "/api/dms/documents/$b/attachments/{$attachment['id']}/file"), 'Attachment of another document.');
        self::assertSame(403, $this->callAs('manager', 'DELETE', "/api/dms/documents/$a/attachments/{$attachment['id']}"));
        self::assertSame(204, $this->callAs('user', 'DELETE', "/api/dms/documents/$a/attachments/{$attachment['id']}"));
        self::assertSame(404, $this->callAs('user', 'DELETE', "/api/dms/documents/$a/attachments/{$attachment['id']}"));

        self::assertSame(201, $this->callAs('user', 'POST', "/api/dms/documents/$a/links", json_encode(['target_id' => $b, 'kind' => 'amends'])), $this->lastBody);
        $link = $this->json()['links'][0];
        self::assertSame(['amends', 'outgoing', 'Earlier memo'], [$link['kind'], $link['direction'], $link['document']['title']]);
        self::assertSame(409, $this->callAs('user', 'POST', "/api/dms/documents/$a/links", json_encode(['target_id' => $b, 'kind' => 'amends'])));
        self::assertSame(404, $this->callAs('user', 'POST', "/api/dms/documents/$a/links", json_encode(['target_id' => $hidden, 'kind' => 'refers_to'])), 'Cannot link to what you cannot read.');
        self::assertInvalid($this->callAs('user', 'POST', "/api/dms/documents/$a/links", json_encode(['target_id' => $a, 'kind' => 'amends'])));
        self::assertInvalid($this->callAs('user', 'POST', "/api/dms/documents/$a/links", json_encode(['target_id' => $b, 'kind' => 'copies'])));
        self::assertSame(200, $this->callAs('user', 'GET', "/api/dms/documents/$b"));
        self::assertSame('incoming', $this->json()['links'][0]['direction']);
        self::assertSame(404, $this->callAs('user', 'DELETE', "/api/dms/documents/$b/links/{$link['id']}"), 'Links are removed from their source.');
        self::assertSame(204, $this->callAs('user', 'DELETE', "/api/dms/documents/$a/links/{$link['id']}"));
    }

    public function test_drafts_are_deleted_with_their_files_and_units_owning_documents_stay(): void
    {
        $id = $this->createDocument('user', ['type_id' => $this->ids['MEMO'], 'title' => 'Throwaway', 'owner_unit_id' => $this->ids['IT']]);
        self::assertSame('It', $this->json()['owner_unit']['name']);
        self::assertSame(201, $this->upload('user', "/api/dms/documents/$id/versions", $this->textFile('bye', 'bye.txt')));
        self::assertSame(201, $this->upload('user', "/api/dms/documents/$id/attachments", $this->textFile('annex', 'a.txt')));
        $directory = static::getContainer()->getParameter('app.dms_storage_dir');
        $before = count(glob($directory.'/*') ?: []);

        self::assertSame(409, $this->callAs('admin', 'DELETE', '/api/org/units/'.$this->ids['IT']), $this->lastBody);
        self::assertSame(404, $this->callAs('manager', 'DELETE', "/api/dms/documents/$id"), 'An IT draft is invisible to the head of Sales.');
        self::assertSame(204, $this->callAs('user', 'DELETE', "/api/dms/documents/$id"));
        self::assertSame($before - 2, count(glob($directory.'/*') ?: []), 'Version and attachment files are removed.');
        self::assertSame(404, $this->callAs('user', 'GET', "/api/dms/documents/$id"));
    }

    public function test_validation_and_document_type_administration(): void
    {
        self::assertSame(404, $this->callAs('user', 'POST', '/api/dms/documents', '{"type_id":99999,"title":"X"}'));
        self::assertSame(400, $this->callAs('user', 'POST', '/api/dms/documents', json_encode(['type_id' => $this->ids['MEMO']])));
        self::assertSame(404, $this->callAs('user', 'POST', '/api/dms/documents', json_encode(['type_id' => $this->ids['MEMO'], 'title' => 'X', 'client_id' => 99999])));
        self::assertSame(400, $this->callAs('user', 'POST', '/api/dms/documents', json_encode(['type_id' => $this->ids['MEMO'], 'title' => 'X', 'effective_on' => '2026-05-02', 'expires_on' => '2026-05-01'])));
        self::assertInvalid($this->callAs('user', 'POST', '/api/dms/documents', json_encode(['type_id' => $this->ids['MEMO'], 'title' => 'X', 'confidentiality' => 'secret'])));
        $id = $this->createDocument('user', ['type_id' => $this->ids['MEMO'], 'title' => 'Valid']);
        self::assertSame(400, $this->callAs('user', 'PATCH', "/api/dms/documents/$id", json_encode(['type_id' => $this->ids['ORDER']])));
        self::assertSame(400, $this->upload('user', "/api/dms/documents/$id/versions", null));
        $binary = $this->textFile(random_bytes(64), 'tool.exe');
        self::assertSame(400, $this->upload('user', "/api/dms/documents/$id/versions", $binary));
        self::assertSame(404, $this->callAs('user', 'GET', '/api/dms/documents/99999'));
        foreach (['status=lost', 'type_id=x', 'from=2026-13-01', 'limit=500', 'offset=-1'] as $query) {
            self::assertSame(400, $this->callAs('user', 'GET', '/api/dms/documents?'.$query), $query);
        }
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/documents?mine=1&from=2020-01-01&to=2099-12-31&owner_unit_id='.$this->ids['SALES']));
        self::assertSame(1, $this->json()['total']);
        self::assertSame(200, $this->callAs('clerk', 'GET', '/api/dms/documents?mine=1'));
        self::assertSame(0, $this->json()['total'], 'mine=1 narrows even a clerk to their own documents.');

        self::assertSame(403, $this->callAs('user', 'POST', '/api/dms/types', '{"code":"X","name":"X"}'));
        self::assertSame(409, $this->callAs('clerk', 'POST', '/api/dms/types', '{"code":"memo","name":"Dup"}'));
        self::assertSame(400, $this->callAs('clerk', 'POST', '/api/dms/types', '{"code":"BAD","name":"Bad","number_pattern":"BAD-{SEQ}"}'));
        self::assertSame(400, $this->callAs('clerk', 'POST', '/api/dms/types', '{"name":"No code"}'));
        self::assertSame(400, $this->callAs('clerk', 'PATCH', '/api/dms/types/'.$this->ids['MEMO'], '{"code":"NOTE"}'));
        self::assertSame(404, $this->callAs('clerk', 'PATCH', '/api/dms/types/99999', '{"name":"X"}'));
        self::assertSame(200, $this->callAs('admin', 'PATCH', '/api/dms/types/'.$this->ids['MEMO'], '{"active":false,"retention_years":7}'), 'Administrators are clerks too.');
        self::assertSame(7, $this->json()['retention_years']);
        self::assertSame(409, $this->callAs('user', 'POST', '/api/dms/documents', json_encode(['type_id' => $this->ids['MEMO'], 'title' => 'Inactive'])));
        self::assertSame(200, $this->callAs('user', 'GET', '/api/dms/types?active_only=1'));
        self::assertSame(['Order'], array_column($this->json(), 'name'));
        self::assertSame(200, $this->callAs('clerk', 'PATCH', '/api/dms/types/'.$this->ids['MEMO'], '{"clear_retention_years":true}'));
        self::assertNull($this->json()['retention_years']);
    }

    /** @param array<string, mixed> $body */
    private function createType(array $body): int
    {
        self::assertSame(201, $this->callAs('clerk', 'POST', '/api/dms/types', json_encode($body)), $this->lastBody);

        return $this->json()['id'];
    }

    /** @param array<string, mixed> $body */
    private function createDocument(string $as, array $body): int
    {
        self::assertSame(201, $this->callAs($as, 'POST', '/api/dms/documents', json_encode($body)), $this->lastBody);

        return $this->json()['id'];
    }

    private function textFile(string $content, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dms-upload');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @param array<string, string> $fields */
    private function upload(string $as, string $uri, ?UploadedFile $file, array $fields = []): int
    {
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $managed = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->users[$as]->getId());
        $this->client->loginUser($managed, 'api');
        $this->client->request('POST', $uri, $fields, $file === null ? [] : ['file' => $file]);
        $this->lastBody = (string) $this->client->getResponse()->getContent();

        return $this->client->getResponse()->getStatusCode();
    }
}
