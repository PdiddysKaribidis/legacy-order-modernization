<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimal browser: keeps the session cookie and does not follow redirects,
 * so tests can compare redirect targets of the legacy and modern pages.
 */
final class HttpClient
{
    private string $cookie = '';

    public function __construct(private readonly string $baseUrl)
    {
    }

    /**
     * @return array{status: int, location: ?string, body: string}
     */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, string> $data
     * @return array{status: int, location: ?string, body: string}
     */
    public function post(string $path, array $data): array
    {
        return $this->request('POST', $path, http_build_query($data));
    }

    /**
     * @return array{status: int, location: ?string, body: string}
     */
    private function request(string $method, string $path, string $content = ''): array
    {
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if ($this->cookie !== '') {
            $headers[] = 'Cookie: ' . $this->cookie;
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);

        $body = (string) file_get_contents($this->baseUrl . '/' . ltrim($path, '/'), false, $context);
        $responseHeaders = http_get_last_response_headers() ?? [];

        $status = 0;
        $location = null;
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            } elseif (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $m)) {
                $this->cookie = $m[1];
            }
        }

        return ['status' => $status, 'location' => $location, 'body' => $body];
    }
}
