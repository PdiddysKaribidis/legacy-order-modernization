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
    /** @var resource|null */
    private $process;

    public readonly string $baseUrl;

    /**
     * @param array<string, string> $env
     * @param string $logFile server output, useful when a test fails in CI
     */
    public function __construct(string $documentRoot, array $env, public readonly string $logFile)
    {
        // The port is found by binding to port 0 and releasing it; another process could take it
        // in between, which is unlikely enough for a test suite and fails loudly if it happens.
        $port = self::freePort();
        $this->baseUrl = "http://127.0.0.1:{$port}";

        $command = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $documentRoot];
        $ini = php_ini_loaded_file();
        if ($ini !== false) {
            array_splice($command, 1, 0, ['-c', $ini]);
        }

        $process = proc_open($command, [['pipe', 'r'], ['file', $logFile, 'a'], ['file', $logFile, 'a']], $pipes, null, $env + getenv());
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP built-in server.');
        }
        $this->process = $process;

        $this->waitUntilListening($port);
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
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

        $this->stop();
        throw new RuntimeException(
            "PHP built-in server did not start on port {$port}. Log:\n" . @file_get_contents($this->logFile)
        );
    }
}
