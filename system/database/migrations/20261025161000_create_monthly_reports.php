<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/**
 * The archive of the monthly reports (#161, Core\ReportArchive): once a month has turned, a background job stores the report of
 * the previous month (Core\MonthlyReport::build) as JSON and keeps it 24 months. Its sources are kept 30 to 180 days only, so this
 * is the only copy of an older month. Counts, titles and page paths, never personal data.
 */
final class CreateMonthlyReports extends AbstractMigration
{
    public function change(): void
    {
        $this->table('monthly_reports', ['id' => false, 'primary_key' => ['report_id']])
            ->addColumn('report_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('month', 'char', ['limit' => 7, 'null' => false, 'comment' => 'YYYY-MM'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('data', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => false, 'comment' => 'the built report as JSON (MonthlyReport::build, scrubbed)'])
            ->addIndex(['public_id'], ['name' => 'uq_monthly_reports_public_id', 'unique' => true])
            ->addIndex(['month'], ['name' => 'uq_monthly_reports_month', 'unique' => true])
            ->create();
    }
}
