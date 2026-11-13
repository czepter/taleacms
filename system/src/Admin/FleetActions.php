<?php

declare(strict_types=1);

namespace Talea\Admin;

use Talea\Core\Response;

/**
 * Settings actions of the fleet console (Fleet\Link, Fleet\Kit): pair, send a report, unpair, the shared design kit. Used by Admin\Modules\Settings.
 */
trait FleetActions
{
    /** Pairs the site with a fleet console (2.9, Fleet\Link) – the pairing key comes from the console. */
    protected function actionFleetPair(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'console']);
        }
        try {
            \Talea\Fleet\Link::pair($this->app, $this->request->post('pairing_key'));
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), '', ['tab' => 'console'], 'error');
        }

        return $this->back('The site is paired with the console and has sent its first report.', '', ['tab' => 'console']);
    }

    protected function actionFleetSend(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'console']);
        }
        try {
            \Talea\Fleet\Link::send($this->app);
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), '', ['tab' => 'console'], 'error');
        }

        return $this->back('The report has been sent to the console.', '', ['tab' => 'console']);
    }


    protected function actionFleetUnpair(): Response
    {
        if ($this->request->isPost()) {
            \Talea\Fleet\Link::unpair($this->app);
        }

        return $this->back('The site no longer reports to the console.', '', ['tab' => 'console']);
    }

    /** Opt in to (or out of) the console's shared design kit (2.16, Fleet\Kit) – it arrives as drafts with the next report. */
    protected function actionFleetKit(): Response
    {
        if ($this->request->isPost()) {
            $on = $this->request->postBool('fleet_kit');
            $this->app->settings()->set('fleet_kit', $on ? '1' : '0');
            \Talea\Admin\ChangeLog::write($this->app, 'settings', 'fleet_kit', $on ? 'on' : 'off');
        }

        return $this->back('The choice about the shared kit is saved.', '', ['tab' => 'console']);
    }
}
