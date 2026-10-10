<?php

declare(strict_types=1);

namespace Talea\Tests\Site\LinksConnectors;

/**
 * What sections 74 (connectors) and lib.sh gave the old sections 82, 84 and 85: the fake outside services (started by Site::boot,
 * port 'fake'), their request logs (FAKE_LOGS) and connect_fake, the sign-in of the site's OAuth app through the fake Google.
 */
trait FakeServices
{
    /** The request log the fake services write: FAKE_LOGS-<name> of the old script (name like "oauth.log"). */
    protected function fakeLog(string $name): string
    {
        return sys_get_temp_dir() . '/talea-fake-' . $this->site()->port('fake') . '-' . $name;
    }

    protected function fakeLogContents(string $name): string
    {
        return (string) @file_get_contents($this->fakeLog($name));
    }

    /** Old connect_fake: save the OAuth app with test credentials, start the sign-in, come back through the callback. Returns the authorize URL. */
    protected function connectFake(string $service): string
    {
        $page = '/admin.php?module=connectors';
        $admin = $this->site()->admin();
        $this->adminPost('/admin.php?module=connectors&action=save', ['service' => $service, 'client_id' => 'test-client', 'secret' => 'test-client-secret'], $page);
        $location = $this->adminPost('/admin.php?module=connectors&action=connect', ['service' => $service], $page)->redirect;
        preg_match('/[?&]state=([a-f0-9]*)/', $location, $match);
        $back = $admin->get('/admin.php?module=connectors&action=callback&code=test-code&state=' . ($match[1] ?? ''));
        if ($back->redirect !== '') {
            $admin->get($back->redirect);
        }

        return $location;
    }

    /** The text of an MCP tool as one string, slashes unescaped, for the old grep checks. @param array<string, mixed> $arguments */
    protected function mcpText(string $tool, array $arguments = []): string
    {
        $result = $this->site()->mcp($tool, $arguments)['result']['content'][0]['text'] ?? '';

        return str_replace('\/', '/', (string) $result);
    }

    /** The decoded result of an MCP tool, re-encoded as compact JSON with plain slashes and Unicode (the old sed 's#\\/#/#g'). @param array<string, mixed> $arguments */
    protected function mcpJson(string $tool, array $arguments = []): string
    {
        return json_encode($this->site()->mcpResult($tool, $arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** The site's own date (the CI shell is UTC, the site runs in its time zone). */
    protected function siteDate(string $when): string
    {
        return trim($this->site()->php('echo date("Y-m-d", strtotime(' . var_export($when, true) . '));'));
    }

    /** The name of the first (default language) news category, which the old sections took over from section 17. */
    protected function newsCategory(): string
    {
        return (string) $this->site()->value("SELECT name FROM tl_categories WHERE language = '' ORDER BY category_id LIMIT 1");
    }

    protected function runJob(string $job): string
    {
        $this->site()->exec("INSERT INTO tl_jobs (name, last_run) VALUES (?, NULL) ON DUPLICATE KEY UPDATE last_run = NULL", [$job]);

        return $this->site()->runTasks();
    }
}
