<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ExtensionApiTest extends TestCase
{
    /** 3.0: the extension API (Extension\Registry, Extension\Api) */
    public function testRegistry(): void
    {
        $this->assertSame([true, true, false, true, false, false, true, false], [
            \Talea\Extension\Registry::satisfies('3.0.0', '>=3.0'), \Talea\Extension\Registry::satisfies('3.2.1', '>=3.0 <4.0'), \Talea\Extension\Registry::satisfies('4.0.0', '>=3.0 <4.0'),
            \Talea\Extension\Registry::satisfies('3.1.0', '^3'), \Talea\Extension\Registry::satisfies('4.1.0', '^3'), \Talea\Extension\Registry::satisfies('2.16.0', '3.0'), \Talea\Extension\Registry::satisfies('3.0.0', ''),
            \Talea\Extension\Registry::satisfies('3.0.0', 'banana')], '3.0 Registry::satisfies – version requirements of add-ons');
        $reg = \Talea\Extension\Registry::get();
        $reg->addToken('unit', 'hi', fn (array $a): string => '<b>' . htmlspecialchars($a['name'] ?? '?') . '</b>');
        $reg->addToken('unit', 'boom', fn (array $a): string => throw new \RuntimeException('x'));
        $reg->addFilter('footer', fn (string $h): string => $h . '[a]');
        $reg->addFilter('footer', fn (string $h): string => throw new \RuntimeException('x'));
        $reg->addFilter('footer', fn (string $h): string => $h . '[b]');
        $this->assertSame(['<p><b>Jana</b> {{ext.unit.hi name=&quot;Petr&quot;}}  {{ext.other.x}}</p>', '[a][b]'], [
            \Talea\Extension\Registry::fillTokens('<p>{{ext.unit.hi name="Jana"}} {{ext.unit.hi name=&quot;Petr&quot;}} {{ext.unit.boom}} {{ext.other.x}}</p>'), \Talea\Extension\Registry::applyFilter('footer', '')], '3.0 add-on tokens and filters: attributes in quotes (3.3.2: never in escaped quotes – that is how a visitor\'s text looks), a failing token prints nothing, a failing filter is skipped, unknown tokens stay');
        $apiErrors = [];
        $api = new \Talea\Extension\Api($reg, 'unit', new \Talea\Core\App(['db' => []], new \Talea\Core\Request([], [], [])));
        foreach ([fn () => $api->filter('body', fn ($h) => $h), fn () => $api->mcpTool('x', 'd', [], 'admin', fn () => 1), fn () => $api->token('Bad Name', fn () => '')] as $call) {
            try { $call(); } catch (\InvalidArgumentException $e) { $apiErrors[] = $e->getMessage(); }
        }
        $api->mcpTool('greet', 'Greets.', ['properties' => []], 'write', fn (array $a): string => 'hi');
        $this->assertSame([3, 'write', 'write', false, true], [count($apiErrors), $reg->tool('ext_unit_greet')['access'] ?? null,
            \Talea\Mcp\Catalog::access('ext_unit_greet'), \Talea\Mcp\Catalog::allows('read', 'ext_unit_greet'), \Talea\Mcp\Catalog::allows('full', 'ext_unit_greet')], '3.0 Api: unknown filters, access levels and names are refused; a tool is ext_<slug>_<name> with its access in the catalog');
    }

    /** extension API 2 (issue #27): version gate, declared settings, event and alert types, the contract */
    public function testApiVersionTwo(): void
    {
        $reg = \Talea\Extension\Registry::get();
        $api1 = new \Talea\Extension\Api($reg, 'oldone', new \Talea\Core\App(['db' => []], new \Talea\Core\Request([], [], [])), 1);
        $gated = [];
        foreach ([fn () => $api1->healthRows(fn () => []), fn () => $api1->earlyRequest(fn () => null), fn () => $api1->job('x', 60, 'l', fn () => 'ok', 'cron'), fn () => $api1->eventType('oldone.x', 'd'), fn () => $api1->route('gallery', fn () => null)] as $call) {
            try { $call(); } catch (\LogicException $e) { $gated[] = true; }
        }
        $api1->job('plain', 60, 'A job', fn () => 'ok');
        $api2 = new \Talea\Extension\Api($reg, 'newone', new \Talea\Core\App(['db' => []], new \Talea\Core\Request([], [], [])), 2, ['early_request']);
        $api2->eventType('newone.expiring', 'Something expires.', alert: true);
        $api2->job('heavy', 60, 'Heavy', fn () => 'ok', 'cron');
        $api2Errors = [];
        foreach ([fn () => $api2->eventType('other.thing', 'd'), fn () => $api2->eventType('backup.failed', 'd'), fn () => $api2->job('x', 60, 'l', fn () => 'ok', 'sometimes'), fn () => $api2->httpGet('https://example.com/')] as $call) {
            try { $call(); } catch (\InvalidArgumentException | \LogicException $e) { $api2Errors[] = get_class($e); }
        }
        $this->assertSame([5, 'any', true, [1, 2]], [count($gated), $reg->jobs()['ext_oldone_plain'][1], \Talea\Extension\Api::V2_METHODS === ['earlyRequest', 'notFound', 'healthRows', 'handoverFindings', 'eventType', 'settings', 'httpGet', 'route'], \Talea\Extension\Api::SUPPORTED], 'API 2: the methods of version 2 and a runner refuse an add-on written for API 1; version 1 jobs stay "any"');
        $this->assertSame([['InvalidArgumentException', 'InvalidArgumentException', 'InvalidArgumentException', 'LogicException'], 'cron', true, true, false], [$api2Errors, $reg->jobs()['ext_newone_heavy'][1],
            isset(\Talea\Core\Events::types()['newone.expiring']), in_array('newone.expiring', \Talea\Core\Alerts::warnings(), true), isset(\Talea\Core\Events::TYPES['newone.expiring'])], 'API 2: an event type is <slug>.<name>, Talea\'s own names are taken, an unknown runner and an undeclared capability are refused; cron jobs are marked');
        $this->assertSame([1, 3], array_column(\Talea\Core\Alerts::worth([
            ['id' => 1, 'created_at' => '', 'type' => 'newone.expiring', 'severity' => 'warning', 'message' => '', 'data' => []],
            ['id' => 2, 'created_at' => '', 'type' => 'stranger.thing', 'severity' => 'warning', 'message' => '', 'data' => []],
            ['id' => 3, 'created_at' => '', 'type' => 'stranger.thing', 'severity' => 'error', 'message' => '', 'data' => []]]), 'id'), 'API 2: a warning of an add-on\'s alert type is worth an alert e-mail, one of an unknown type is not');
        $this->assertSame(['365', null, '1', '0', null, null, 'https://example.com/a', 'a b'], [
            \Talea\Extension\SettingsSchema::sanitize('number:1:365', '9999'), \Talea\Extension\SettingsSchema::sanitize('number:1:365', 'abc'), \Talea\Extension\SettingsSchema::sanitize('flag', 'on'), \Talea\Extension\SettingsSchema::sanitize('flag', ''),
            \Talea\Extension\SettingsSchema::sanitize('choice:a|b', 'c'), \Talea\Extension\SettingsSchema::sanitize('email', 'x'), \Talea\Extension\SettingsSchema::sanitize('url', 'https://example.com/a'), \Talea\Extension\SettingsSchema::sanitize('text', "a\nb")], 'API 2: declared settings – types are checked, numbers are clamped, a flag is 1 or 0');
        $schemaError = false;
        try { \Talea\Extension\SettingsSchema::normalize(['Bad Name' => ['label' => 'x', 'type' => 'text']]); } catch (\InvalidArgumentException) { $schemaError = true; }
        try { \Talea\Extension\SettingsSchema::normalize(['ok' => ['label' => 'x', 'type' => 'binary']]); $schemaError = false; } catch (\InvalidArgumentException) { }
        $this->assertTrue($schemaError, 'API 2: a setting with a bad name or an unknown type is refused');
        $apiContract = json_decode((string) file_get_contents(TALEA_ROOT . '/tools/contracts/extension-api.json'), true);
        $this->assertSame([2, [1, 2], ['earlyRequest', 'eventType', 'handoverFindings', 'healthRows', 'httpGet', 'notFound', 'route', 'settings'], ['string $name', 'int $interval', 'string $label', 'callable $run', 'string $runner']], [$apiContract['version'], $apiContract['supported'], $apiContract['v2_methods'], $apiContract['methods']['job']], 'API 2: the contract records version 2, both supported versions, and which methods are new in 2');
    }

    /** Core addresses always win (#228): Api::route() refuses Talea's own prefixes; add-on routes come after every core route, before pages */
    public function testRoutePrefixesOfTaleaAreRefused(): void
    {
        $api = new \Talea\Extension\Api(\Talea\Extension\Registry::get(), 'routetest', new \Talea\Core\App(['db' => []], new \Talea\Core\Request([], [], [])), 2, ['routes']);
        $refused = [];
        foreach (['news', 'form', 'health', 'tasks', 'screen', 'member', 'fleet', 'experiment', 'oauth', 'admin', 'media'] as $prefix) {
            try {
                $api->route($prefix, fn () => null);
            } catch (\RuntimeException $e) {
                $refused[$prefix] = $e->getMessage();
            }
        }
        $api->route('routetest-pics', fn () => null);
        $taken = '';
        try {
            $api->route('routetest-pics', fn () => null);
        } catch (\RuntimeException $e) {
            $taken = $e->getMessage();
        }
        $this->assertSame([11, 'The address /form/ belongs to Talea.', 'The address /routetest-pics/ already belongs to another add-on.'], [count($refused), $refused['form'] ?? '', $taken],
            'a prefix of a core route or a reserved slug is refused the way a prefix another add-on owns is; a free prefix is accepted once');
        $handlers = array_column(\Talea\Front\Kernel::routes()->routes(), 'handler');
        $addOn = array_search('Kernel::addOnRoute', $handlers, true);
        $this->assertSame([true, 'Endpoints::shortLink', 'Kernel::pageAt'], [$addOn === (int) array_search('Kernel::pageAt', $handlers, true) - 1, $handlers[$addOn - 1] ?? '', $handlers[$addOn + 1] ?? ''],
            'add-on routes are asked after the last core route and right before pages and collections');
        $this->assertSame([], array_diff(['news', 'search', 'mcp', 'api', 'admin', 'install', 'media', 'image', 'layout', 'system', 'storage', 'tools', 'docs', 'dist', 'rss', 'sitemap', 'robots', 'llms', 'feed', 'status', 'health', 'tasks', 'consent', 'form', 'form-step', 'popup', 'vitals', 'conversion', 'subscribe', 'download', 'screen', 'og', 'member', 'fleet', 'experiment', 'oauth', 's'],
            \Talea\Front\Kernel::reservedSlugs()), 'the reserved slugs come from the route table and keep the addresses outside it');
    }
}
