<?php

namespace App\Service\Dms;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Virus scanning of uploads through a clamd daemon (ClamAV), using its INSTREAM command over TCP or a Unix socket.
 * Configure DMS_CLAMAV_DSN, for example `tcp://clamav:3310` or `unix:///run/clamav/clamd.sock`. When it is empty,
 * scanning is disabled (development); production deployments must set it. When it is set, uploads fail closed:
 * no verdict, no upload.
 */
final class ClamAvScanner
{
    private const CHUNK_BYTES = 65536;

    /** @param (\Closure(): resource)|null $connect opens the connection instead of the DSN (tests) */
    public function __construct(
        #[Autowire('%app.dms_clamav_dsn%')] private readonly string $dsn = '',
        #[Autowire('%app.dms_clamav_timeout%')] private readonly int $timeoutSeconds = 30,
        private readonly ?\Closure $connect = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return trim($this->dsn) !== '' || $this->connect !== null;
    }

    /**
     * @return string|null the signature name when the file is infected, null when it is clean
     * @throws ScannerUnavailable when the daemon cannot be reached or gives no verdict
     */
    public function scan(string $path): ?string
    {
        $socket = $this->connect !== null ? ($this->connect)() : @stream_socket_client(trim($this->dsn), $errorCode, $errorMessage, $this->timeoutSeconds);
        if (!is_resource($socket)) {
            throw new ScannerUnavailable(sprintf('The virus scanner is not reachable%s.', isset($errorMessage) && $errorMessage !== '' ? ': '.$errorMessage : ''));
        }
        $file = fopen($path, 'rb');
        try {
            if ($file === false) {
                throw new ScannerUnavailable('The uploaded file could not be read for scanning.');
            }
            stream_set_timeout($socket, $this->timeoutSeconds);
            $this->write($socket, "zINSTREAM\0");
            while (!feof($file)) {
                $chunk = fread($file, self::CHUNK_BYTES);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $this->write($socket, pack('N', strlen($chunk)).$chunk);
            }
            $this->write($socket, pack('N', 0));
            $reply = '';
            while (!str_contains($reply, "\0") && !feof($socket)) {
                $part = fread($socket, 4096);
                if ($part === false || ($part === '' && stream_get_meta_data($socket)['timed_out'])) {
                    throw new ScannerUnavailable('The virus scanner did not answer in time.');
                }
                $reply .= $part;
            }
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            fclose($socket);
        }
        $reply = trim(strstr($reply, "\0", true) ?: $reply);
        if (preg_match('/^stream: OK$/', $reply) === 1) {
            return null;
        }
        if (preg_match('/^stream: (.+) FOUND$/', $reply, $match) === 1) {
            return $match[1];
        }
        throw new ScannerUnavailable(sprintf('The virus scanner gave no verdict (%s).', mb_substr($reply, 0, 200)));
    }

    /** @param resource $socket */
    private function write($socket, string $data): void
    {
        for ($written = 0; $written < strlen($data); $written += $sent) {
            $sent = fwrite($socket, substr($data, $written));
            if ($sent === false || $sent === 0) {
                throw new ScannerUnavailable('The connection to the virus scanner was lost.');
            }
        }
    }
}
