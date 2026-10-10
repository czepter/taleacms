<?php

declare(strict_types=1);

namespace KaletaExample\Hello;

use Kaleta\Extension\Api;
use Kaleta\Extension\ExtensionInterface;

/**
 * An example add-on for Kaleta 3.0 – copy the folder to extensions/hello/ and switch it on in Add-ons.
 * It shows every part of the extension API (docs/EXTENSIONS.md).
 */
final class Extension implements ExtensionInterface
{
    public function register(Api $api): void
    {
        // {{ext.hello.greeting name="Jana"}} in a text or a build – the add-on escapes what it prints
        $api->token('greeting', fn (array $attributes): string => '<span class="hello-greeting">' . htmlspecialchars($api->get('word', 'Hello') . ', ' . ($attributes['name'] ?? 'world'), ENT_QUOTES) . '!</span>');

        // a line at the end of every public page
        $api->filter('footer', fn (string $html): string => $html . '<!-- hello add-on -->');

        // count enquiries as they arrive
        $api->on('enquiry.received', function (string $type, array $data) use ($api): void {
            $api->set('enquiries', (string) ((int) $api->get('enquiries', '0') + 1));
        });

        // an administration page (Add-ons → Hello settings); POSTs are CSRF-checked by Kaleta
        $api->adminPage('settings', 'Hello settings', function (\Kaleta\Core\Request $request) use ($api): string {
            if ($request->isPost()) {
                $api->set('word', mb_substr(trim($request->post('word')), 0, 40));
            }

            return '<form method="post">' . $api->app()->session->csrfField()
                . '<p><label>Greeting word <input name="word" value="' . htmlspecialchars($api->get('word', 'Hello'), ENT_QUOTES) . '"></label> <button>Save</button></p></form>'
                . '<p>Enquiries counted since the add-on was switched on: ' . (int) $api->get('enquiries', '0') . '</p>';
        });

        // a tool for Claude: ext_hello_greet – read-only, so every connection may use it
        $api->mcpTool('greet', 'Says hello to a name (an example add-on tool).', ['properties' => ['name' => ['type' => 'string', 'description' => 'who to greet']]], 'read',
            fn (array $arguments): array => ['greeting' => $api->get('word', 'Hello') . ', ' . (is_string($arguments['name'] ?? null) ? $arguments['name'] : 'world') . '!']);

        // a daily job (Settings → System status lists it)
        $api->job('daily', 86400, 'Hello: daily check', fn (): string => 'ok');
    }
}
