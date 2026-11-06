<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Visits are counted when the visitor's browser confirms the page was shown (Front\Stats::view, a beacon from image/web.js), not on
 * every request. The visitor of a day now remembers how many views were confirmed (the daily cap) and whether it behaved like a
 * machine (many views within seconds). The day counting changed is written once into the setting stats_counting_since, so the
 * Statistics screen, the dashboard, the monthly report and get_stats can mark the break in the figures.
 */
final class CountVisitsByBeacon extends AbstractMigration
{
    public function up(): void
    {
        $this->table('stats_visitors')
            ->addColumn('views', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('machine', 'boolean', ['null' => false, 'default' => false])
            ->update();

        $pdo = $this->getAdapter()->getConnection();
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');
        $read = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}settings WHERE name = ?");
        $read->execute(['stats_counting_since']);
        if ((int) $read->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO {$prefix}settings (name, value) VALUES (?, ?)")->execute(['stats_counting_since', date('Y-m-d')]);
        }
    }

    public function down(): void
    {
        $this->table('stats_visitors')->removeColumn('views')->removeColumn('machine')->update();
    }
}
