<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Runs `php -S` for a document root, so the legacy pages can be tested as they are:
 * they call header() and exit and cannot be included into a test process.
 */
final class PhpServer
{
    /** @var resource */
    private $process;

    public readonly string $baseUrl;

    /**
     * @param array<string, string> $env
     */
    public function __construct(string $documentRoot, array $env)
    {
        $port = self::freePort();
        $this->baseUrl = "http://127.0.0.1:{$port}";

        $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $documentRoot];
        $ini = php_ini_loaded_file();
        if ($ini !== false) {
            array_splice($command, 1, 0, ['-c', $ini]);
        }

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [['pipe', 'r'], ['file', $null, 'w'], ['file', $null, 'w']], $pipes, null, $env + getenv());
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP built-in server.');
        }
        $this->process = $process;

        $this->waitUntilListening($port);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Cannot find a free port.');
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitUntilListening(int $port): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException("PHP built-in server did not start on port {$port}.");
    }
}
