<?php
/**
 * Kaleta 3.3.3 (N63): content imported before 3.3.2 is checked again with today's sanitizers (Core\ImportRecheck) –
 * imported news, pages and collection items that could still hold markup the old importers made out of attribute text.
 * Only risky HTML changes; the version before goes into the item's history.
 *
 * The work starts here and runs in batches: what does not fit into a few seconds of this request (a large site) is
 * finished by the background job "import_recheck". Safe to run again: the state is kept in the setting imported_recheck
 * and a checked row is never changed twice. Nothing here may stop the update – after a failure the job carries on.
 */

declare(strict_types=1);

use Kaleta\Core\Db;
use Kaleta\Core\ImportRecheck;
use Kaleta\Core\Settings;

return static function (Db $db, Settings $settings): void {
    ImportRecheck::start($settings);
    // the update request of the release before may have loaded its own sanitizers: run now only with those of 3.3.2 and
    // newer (Html::transform came with them), otherwise the background job does all of it with the new code
    if (!method_exists(\Kaleta\Core\Html::class, 'transform')) {
        return;
    }
    try {
        ImportRecheck::run($db, $settings, 8.0);
    } catch (\Throwable $e) {
        error_log('Import recheck: ' . $e->getMessage()); // the background job goes on from the saved position
    }
};
