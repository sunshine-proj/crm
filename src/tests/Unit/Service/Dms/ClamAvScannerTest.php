<?php

namespace App\Tests\Unit\Service\Dms;

use App\Service\Dms\ClamAvScanner;
use App\Service\Dms\DmsFileStorage;
use App\Service\Dms\ScannerUnavailable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The clamd INSTREAM exchange, against a socket pair standing in for the daemon: the "daemon" side holds its reply
 * before the scan starts and is read afterwards to check the framing the scanner sent.
 */
final class ClamAvScannerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'scan');
        file_put_contents($this->file, str_repeat('A', 70000));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function test_a_clean_file_and_the_instream_framing(): void
    {
        [$scanner, $daemon] = $this->scannerReplying("stream: OK\0");
        self::assertNull($scanner->scan($this->file));

        $sent = stream_get_contents($daemon);
        self::assertStringStartsWith("zINSTREAM\0", $sent);
        $payload = substr($sent, strlen("zINSTREAM\0"));
        $chunks = [];
        while ($payload !== '') {
            $length = unpack('N', substr($payload, 0, 4))[1];
            $chunks[] = $length;
            $payload = substr($payload, 4 + $length);
        }
        self::assertSame([65536, 70000 - 65536, 0], $chunks, 'Length-prefixed chunks ending with a zero-length chunk.');
    }

    public function test_an_infected_file_returns_the_signature(): void
    {
        [$scanner, $daemon] = $this->scannerReplying("stream: Eicar-Test-Signature FOUND\0");
        self::assertSame('Eicar-Test-Signature', $scanner->scan($this->file));
        fclose($daemon);
    }

    public function test_no_verdict_means_unavailable(): void
    {
        [$scanner, $daemon] = $this->scannerReplying("INSTREAM size limit exceeded. ERROR\0");
        try {
            $this->expectException(ScannerUnavailable::class);
            $scanner->scan($this->file);
        } finally {
            fclose($daemon);
        }
    }

    public function test_an_unreachable_daemon_is_unavailable_and_an_empty_dsn_disables_scanning(): void
    {
        self::assertFalse((new ClamAvScanner(''))->isEnabled());
        $scanner = new ClamAvScanner('tcp://127.0.0.1:1', 2);
        self::assertTrue($scanner->isEnabled());
        $this->expectException(ScannerUnavailable::class);
        $scanner->scan($this->file);
    }

    public function test_storage_refuses_infected_uploads_and_keeps_nothing(): void
    {
        $directory = sys_get_temp_dir().'/dms-scan-'.bin2hex(random_bytes(4));
        file_put_contents($this->file, 'plain text body');
        [$scanner, $daemon] = $this->scannerReplying("stream: Win.Test.EICAR_HDB-1 FOUND\0");
        $storage = new DmsFileStorage($directory, $scanner, new NullLogger());
        try {
            $storage->store(new UploadedFile($this->file, 'note.txt', null, null, true));
            self::fail('An infected file was stored.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Win.Test.EICAR_HDB-1', $exception->getMessage());
        } finally {
            fclose($daemon);
        }
        self::assertFileExists($this->file, 'The upload was not moved into storage.');
        self::assertSame([], is_dir($directory) ? array_values(array_diff(scandir($directory), ['.', '..'])) : []);

        [$clean, $daemon] = $this->scannerReplying("stream: OK\0");
        $stored = (new DmsFileStorage($directory, $clean, new NullLogger()))->store(new UploadedFile($this->file, 'note.txt', null, null, true));
        fclose($daemon);
        self::assertFileExists($directory.'/'.$stored->storedName);
        unlink($directory.'/'.$stored->storedName);
        rmdir($directory);
    }

    /** @return array{0: ClamAvScanner, 1: resource} */
    private function scannerReplying(string $reply): array
    {
        [$client, $daemon] = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        fwrite($daemon, $reply);
        // A buffer large enough for the request, so the single-process exchange cannot block.
        stream_set_write_buffer($client, 0);

        return [new ClamAvScanner('', 5, static fn () => $client), $daemon];
    }
}
