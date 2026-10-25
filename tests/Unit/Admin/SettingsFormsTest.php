<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

final class SettingsFormsTest extends TestCase
{
    public function testFormsPostOnlyFieldsTheSaveReads(): void
    {
        // 2.3.1: every field a settings tab posts is read by the save – a setting, the "remove" box of a secret setting, or one of the
        // few the save reads on purpose (a field under an old name was silently dropped: saving General removed the language versions)
        $settingsFields = [];
        foreach ((new \ReflectionClass(\Talea\Admin\Modules\Settings::class))->getReflectionConstant('FIELDS')->getValue() as $tabFields) {
            foreach ($tabFields as $key => $type) {
                $settingsFields[$key] = true;
                if (str_starts_with($type, 'secret')) {
                    $settingsFields[$key . '_delete'] = true;
                }
            }
        }
        $unknownFields = [];
        foreach (glob(TALEA_SYSTEM . '/views/admin/settings/*.php') as $view) {
            preg_match_all('/name="([a-z_]+)(?:\[\])?"/', (string) file_get_contents($view), $viewNames);
            foreach (array_unique($viewNames[1]) as $name) {
                if (!isset($settingsFields[$name]) && !in_array($name, ['extensions', 'ai_provider_previous', 'reload_models', 'new_tasks_token', 'new_token', 'file', 'tab', 'id', 'ip', 'pairing_key',
                    'exception', 'exception_from', 'exception_to', 'exception_closed', 'exception_hours', 'exception_note', 'exception_notice', 'viewport', 'robots', // viewport, robots: <meta> of the door sign
                    'fleet_kit', // 2.16: the console tab's own button (Settings::actionFleetKit)
                    'service', 'connect', 'hosting', 'disconnect', // the analytics tab: which service's page, the wizard step, cloud or own installation, "Disconnect" (Settings::actionSave)
                    'screen_collections', 'new_screen_token', 'smtp_ses_region', // smtp_ses_region: read by MailServices::settle(); 2.11 screen mode: the collections list is added by fields() from the site's collections, the button makes a new address
                    'instructions_seen', 'month'], true)) { // #149: the acknowledge button (ClaudeSettings::actionAcknowledgeInstructions); #161: the month a report send or view names
                    $unknownFields[] = basename($view) . ': ' . $name;
                }
            }
        }
        $this->assertSame([], $unknownFields, '2.3.1: settings forms post only fields the save reads');
    }
}
