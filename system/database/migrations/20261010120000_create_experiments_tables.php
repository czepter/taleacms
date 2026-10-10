<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/** A/B tests (Builder\Experiments): the experiments and their cookie-free daily counts. */
final class CreateExperimentsTables extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix'); // foreign key names are unique per database

        $this->table('experiments', ['id' => false, 'primary_key' => ['experiment_id']])
            ->addColumn('experiment_id', 'integer', ['signed' => false, 'identity' => true, 'null' => false])
            ->addColumn('public_id', ...MigrationSupport::publicId($this->getAdapter()))
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('kind', 'string', ['limit' => 10, 'null' => false, 'comment' => 'element (a section or one element of a page, variant B is a component) | page (variant B is another page)'])
            ->addColumn('page_id', 'integer', ['signed' => false, 'null' => false, 'comment' => 'the page the visitor sees (variant A)'])
            ->addColumn('element_id', 'string', ['limit' => 40, 'null' => true, 'comment' => 'the id of the element in the page build (kind element)'])
            ->addColumn('variant_component_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'variant B of an element test'])
            ->addColumn('variant_page_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'variant B of a page test'])
            ->addColumn('goal_type', 'string', ['limit' => 10, 'null' => false, 'comment' => 'form | click | booking | page'])
            ->addColumn('goal_target', 'string', ['limit' => 255, 'null' => false, 'default' => '', 'comment' => 'the link address of a click goal'])
            ->addColumn('goal_page_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'the page of a "page reached" goal'])
            ->addColumn('status', 'string', ['limit' => 10, 'null' => false, 'default' => 'draft', 'comment' => 'draft | running | stopped | promoted'])
            ->addColumn('auto_promote', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('winner', 'string', ['limit' => 1, 'null' => true, 'comment' => 'a | b once promoted'])
            ->addColumn('previous_build', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true, 'comment' => 'what the promotion replaced, for undo'])
            ->addColumn('promoted_as', 'string', ['limit' => 10, 'null' => true, 'comment' => 'published | draft'])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('started_at', 'datetime', ['null' => true])
            ->addColumn('ended_at', 'datetime', ['null' => true])
            ->addIndex(['public_id'], ['name' => 'uq_experiments_public_id', 'unique' => true])
            ->addIndex(['page_id', 'status'], ['name' => 'ix_experiments_page_id_status'])
            ->addForeignKey('page_id', 'pages', 'page_id', ['constraint' => $prefix . 'fk_experiments_page_id', 'delete' => 'CASCADE'])
            ->addForeignKey('variant_component_id', 'components', 'component_id', ['constraint' => $prefix . 'fk_experiments_variant_component_id', 'delete' => 'SET_NULL'])
            ->addForeignKey('variant_page_id', 'pages', 'page_id', ['constraint' => $prefix . 'fk_experiments_variant_page_id', 'delete' => 'SET_NULL'])
            ->addForeignKey('goal_page_id', 'pages', 'page_id', ['constraint' => $prefix . 'fk_experiments_goal_page_id', 'delete' => 'SET_NULL'])
            ->create();

        $this->table('stats_experiments', ['id' => false, 'primary_key' => ['day', 'experiment_id', 'variant']])
            ->addColumn('day', 'date', ['null' => false])
            ->addColumn('experiment_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('variant', 'string', ['limit' => 1, 'null' => false, 'comment' => 'a | b'])
            ->addColumn('views', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('goals', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addIndex(['experiment_id'], ['name' => 'ix_stats_experiments_experiment_id'])
            ->addForeignKey('experiment_id', 'experiments', 'experiment_id', ['constraint' => $prefix . 'fk_stats_experiments_experiment_id', 'delete' => 'CASCADE'])
            ->create();
    }
}
