<?php

declare(strict_types=1);

namespace Talea\Extension;

/**
 * Optional for an add-on of API 2: its class also implements this to hear about being switched on and uninstalled. Talea runs
 * the migrations (migrations/NNNN-name.sql) before onEnable(); when it throws, the add-on is not switched on.
 */
interface LifecycleInterface
{
    /** Just after the migrations, before the add-on is on: one-time work, such as carrying over the settings of an older version. Idempotent. */
    public function onEnable(Api $api): void;

    /**
     * The administrator uninstalls the (switched-off) add-on. $deleteData = false: keep what the add-on stored (tables, settings) for later;
     * true: Talea drops the add-on's tables (those its migrations create) and its settings afterwards – this hook removes anything else
     * (files, rows in Talea's own tables).
     */
    public function onUninstall(Api $api, bool $deleteData): void;
}
