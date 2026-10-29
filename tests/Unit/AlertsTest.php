<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Alerts;

final class AlertsTest extends TestCase
{
    /** The owner picks what is worth an alert e-mail by kind; an unknown type is 'other'. */
    public function testKinds(): void
    {
        $this->assertSame(['backups', 'deliveries', 'jobs', 'content', 'other'],
            array_map(Alerts::kind(...), ['backup.failed', 'connector.failed', 'agent_run.missed', 'content.review', 'shop.order_failed']));
        $event = fn (int $id, string $type, string $severity): array => ['id' => $id, 'created_at' => '', 'type' => $type, 'severity' => $severity, 'message' => '', 'data' => []];
        $events = [$event(1, 'backup.failed', 'error'), $event(2, 'mail.failed', 'error'), $event(3, 'notfound.spike', 'warning'), $event(4, 'shop.order_failed', 'error')];
        $this->assertSame([1, 2, 3, 4], array_column(Alerts::worth($events), 'id'), 'no choice: every kind');
        $this->assertSame([2, 4], array_column(Alerts::worth($events, ['mail', 'other']), 'id'), 'only the chosen kinds');
        $this->assertSame([], Alerts::worth($events, ['']), 'nothing chosen: nothing');
        $this->assertSame(['list:' . implode('|', array_keys(Alerts::KINDS)), 'choice:' . implode('|', array_keys(Alerts::INTERVALS)), implode(',', array_keys(Alerts::KINDS))],
            [\Talea\Admin\Modules\Settings::fieldType('alerts_kinds'), \Talea\Admin\Modules\Settings::fieldType('alerts_interval'), \Talea\Core\Settings::DEFAULTS['alerts_kinds']], 'the settings form offers every kind and interval; all kinds are on by default');
    }
}
