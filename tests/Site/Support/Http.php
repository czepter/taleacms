<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\Support;

/** A browser of the test site: its own cookie jar, so two instances are two sessions. */
final class Http
{
    private readonly string $jar;

    public function __construct(private readonly string $base, string $jarDir, string $name = 'session')
    {
        $this->jar = $jarDir . '/' . $name . '-' . bin2hex(random_bytes(4)) . '.jar';
    }

    /** @return array<string, string> the cookies of this browser (name => value) */
    public function cookies(): array
    {
        $cookies = [];
        foreach (is_file($this->jar) ? file($this->jar, FILE_IGNORE_NEW_LINES) : [] as $line) {
            $line = preg_replace('/^#HttpOnly_/', '', $line);
            $fields = explode("\t", (string) $line);
            if (count($fields) >= 7) {
                $cookies[$fields[5]] = $fields[6];
            }
        }

        return $cookies;
    }

    /** The PHP session id of this browser ('' before the first page). */
    public function sessionId(): string
    {
        foreach ($this->cookies() as $name => $value) {
            if (stripos($name, 'sess') !== false || $name === 'PHPSESSID') {
                return $value;
            }
        }

        return '';
    }

    /** @param list<string> $headers */
    public function get(string $path, array $headers = [], string $userAgent = 'Mozilla/5.0 test'): Response
    {
        return $this->request('GET', $path, null, $headers, $userAgent);
    }

    /**
     * @param array<string, mixed>|string|null $data form fields (arrays become name[]=…), a raw body, or null
     * @param list<string> $headers
     */
    public function post(string $path, array|string|null $data = null, array $headers = [], string $userAgent = 'Mozilla/5.0 test'): Response
    {
        return $this->request('POST', $path, $data, $headers, $userAgent);
    }

    /**
     * A multipart upload: fields plus files (field => path on disk, or [path, mime type, name]).
     *
     * @param array<string, string> $fields @param array<string, string|array{0: string, 1?: string, 2?: string}> $files
     */
    public function upload(string $path, array $fields, array $files): Response
    {
        $data = $fields;
        foreach ($files as $name => $file) {
            $data[$name] = is_array($file) ? new \CURLFile($file[0], $file[1] ?? '', $file[2] ?? '') : new \CURLFile($file);
        }

        return $this->request('POST', $path, $data, [], 'Mozilla/5.0 test', multipart: true);
    }

    /** @param array<string, mixed>|string|null $data @param list<string> $headers */
    private function request(string $method, string $path, array|string|null $data, array $headers, string $userAgent, bool $multipart = false): Response
    {
        $ch = curl_init(str_starts_with($path, 'http') ? $path : $this->base . $path);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => $userAgent,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }

                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) && !$multipart ? $this->encode($data) : ($data ?? ''));
        }
        $body = (string) curl_exec($ch);
        $response = new Response((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $body, (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL), $responseHeaders);

        return $response;
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    }
}
