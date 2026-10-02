<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

/**
 * Signed JSON between a site and its console (2.9). The body is signed as it is sent (header X-Kaleta-Signature), so the
 * other side checks exactly the bytes it received. https only – plain http is allowed just for local test addresses.
 */
final class Http
{
    public const string HEADER = 'X-Kaleta-Signature';

    /** For tests: replaces the network – gets the URL, the body and the headers, returns [status, body, signature]. */
    public static ?\Closure $transport = null;

    /** Only https, except local addresses (tests, a console on the same machine). */
    public static function allowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || !in_array($scheme, ['https', 'http'], true) || isset($parts['user'])) {
            return false;
        }

        return $scheme === 'https' || in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true) || preg_match('/\.(localhost|test)$/', $host) === 1;
    }

    /**
     * POSTs the payload as JSON, signed by $sign (null = unsigned). Returns the status (0 = no answer), the body, the decoded
     * JSON and the signature header of the answer.
     *
     * @param array<string, mixed> $payload
     * @param (\Closure(string): string)|null $sign
     * @param array<string, string> $headers
     * @return array{status: int, body: string, json: ?array<string, mixed>, signature: string, error: string}
     */
    public static function post(string $url, array $payload, ?\Closure $sign, int $timeout = 15, array $headers = []): array
    {
        if (!self::allowedUrl($url)) {
            return ['status' => 0, 'body' => '', 'json' => null, 'signature' => '', 'error' => 'Only https addresses are allowed.'];
        }
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Kaleta/' . KALETA_VERSION] + $headers;
        if ($sign !== null) {
            $headers[self::HEADER] = $sign($body);
        }
        [$status, $answer, $signature, $error] = self::$transport !== null ? [...(self::$transport)($url, $body, $headers), ''] : self::send($url, $body, $headers, $timeout);
        $json = json_decode((string) $answer, true);

        return ['status' => (int) $status, 'body' => (string) $answer, 'json' => is_array($json) ? $json : null, 'signature' => (string) $signature, 'error' => (string) $error];
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: string, 2: string, 3: string}
     */
    private static function send(string $url, string $body, array $headers, int $timeout): array
    {
        $lines = array_map(fn (string $k, string $v): string => $k . ': ' . $v, array_keys($headers), $headers);
        if (function_exists('curl_init')) {
            $signature = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $lines, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$signature): int {
                    if (stripos($line, self::HEADER . ':') === 0) {
                        $signature = trim(substr($line, strlen(self::HEADER) + 1));
                    }

                    return strlen($line);
                }]);
            $answer = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = $answer === false ? curl_error($ch) : '';

            return [$status, is_string($answer) ? substr($answer, 0, 2_000_000) : '', $signature, $error];
        }
        $answer = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => $timeout, 'ignore_errors' => true,
            'follow_location' => 0, 'header' => implode("\r\n", $lines) . "\r\n", 'content' => $body]]), 0, 2_000_000);
        $status = 0;
        $signature = '';
        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($line, self::HEADER . ':') === 0) {
                $signature = trim(substr($line, strlen(self::HEADER) + 1));
            }
        }

        return [$status, $answer === false ? '' : $answer, $signature, $answer === false ? 'No answer.' : ''];
    }
}
