<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

/**
 * The fake outside services of section 74 as a reusable piece (the old FAKE_LOGS, connect_fake and connector_call), for the classes that
 * port sections 82, 84, 85 and 97. The site's harness already starts tools/fake-services.php (port `fake`) and points the site at it.
 */
trait ConnectorFakes
{
    /** The prefix of the fake service's request logs: `<prefix>-oauth.log`, `-search.log`, `-sheets.log`, `-crm.log`, `-google-business.log`… */
    private function fakeLogs(): string
    {
        return sys_get_temp_dir() . '/talea-fake-' . $this->site()->port('fake');
    }

    /** Empties the logs of the fake service (a port can be reused by an earlier class). */
    private function resetFakeLogs(): void
    {
        foreach (glob($this->fakeLogs() . '-*') ?: [] as $file) {
            unlink($file);
        }
    }

    /** The lines of one log, or an empty string when the fake wrote none. */
    private function fakeLog(string $name): string
    {
        return (string) @file_get_contents($this->fakeLogs() . '-' . $name . '.log');
    }

    /** The URL the sign-in was sent to by the last connectFake(). */
    private string $authorizeUrl = '';

    /** The site's OAuth app with test credentials, the sign-in through the fake service and back (the old connect_fake). */
    private function connectFake(string $service): void
    {
        $admin = $this->site()->admin();
        $page = '/admin.php?module=connectors';
        $admin->post($page . '&action=save', ['_csrf' => $admin->get($page)->csrf(), 'service' => $service, 'client_id' => 'test-client', 'secret' => 'test-client-secret']);
        $location = $admin->post($page . '&action=connect', ['_csrf' => $admin->get($page)->csrf(), 'service' => $service])->redirect;
        $this->authorizeUrl = $location;
        $this->assertSame(1, preg_match('/[?&]state=([a-f0-9]*)/', $location, $m), 'the sign-in address carries a state');

        $next = $this->site()->base . '/admin.php?module=connectors&action=callback&code=test-code&state=' . $m[1];
        for ($i = 0; $i < 5 && $next !== ''; $i++) { // the old curl -L
            $next = $admin->get($next)->redirect;
        }
    }

    /** One authorised call through Core\Connectors to the fake Google: "status|authorization header|error" (the old connector_call). */
    private function connectorCall(string $service = 'google', string $url = 'https://www.googleapis.com/echo'): string
    {
        $fake = 'http://127.0.0.1:' . $this->site()->port('fake');

        return trim($this->site()->php(sprintf('putenv("TALEA_CONNECTORS_FAKE=%s"); $app = new Talea\Core\App(require "config.php"); $app->applyTimezone(); $r = Talea\Core\Connectors::request($app, %s, "GET", %s); echo $r["status"], "|", $r["json"]["authorization"] ?? "", "|", $r["error"];',
            $fake, var_export($service, true), var_export($url, true))));
    }
}
