<?php

declare(strict_types=1);

namespace Talea\Tests\Support;

/**
 * A small "old site" on 127.0.0.1 for the website import tests: PHP's built-in server over a folder (TALEA_IMPORT_LOCAL lets the
 * downloader reach it). Files are put in with put(); a path that is not there answers 404.
 */
final class LocalOldSite
{
    /** @var resource|null */
    private $process;
    private string $folder;
    public string $origin = '';
    private string|false $previous = false;

    public function start(): void
    {
        $this->folder = sys_get_temp_dir() . '/talea-old-site-' . bin2hex(random_bytes(4));
        mkdir($this->folder . '/site', 0775, true);
        file_put_contents($this->folder . '/router.php', <<<'PHP'
<?php
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = __DIR__ . '/site' . $path;
if (!str_contains($path, '..') && is_file($file)) {
    header('Content-Type: application/octet-stream');
    readfile($file);

    return;
}
http_response_code(404);
echo 'not found';
PHP);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->folder . '/router.php'], [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        $this->origin = 'http://127.0.0.1:' . $port;
        for ($i = 0; $i < 50 && @file_get_contents($this->origin . '/up') === false && !isset($http_response_header); $i++) {
            usleep(100_000);
        }
        $this->previous = getenv('TALEA_IMPORT_LOCAL');
        putenv('TALEA_IMPORT_LOCAL=1');
    }

    public function put(string $path, string $content): void
    {
        $file = $this->folder . '/site/' . ltrim($path, '/');
        @mkdir(dirname($file), 0775, true);
        file_put_contents($file, $content);
    }

    public function stop(): void
    {
        $this->previous === false ? putenv('TALEA_IMPORT_LOCAL') : putenv('TALEA_IMPORT_LOCAL=' . $this->previous);
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if (isset($this->folder) && is_dir($this->folder)) {
            exec('rm -rf ' . escapeshellarg($this->folder));
        }
    }
}
