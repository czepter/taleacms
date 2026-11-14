<?php

declare(strict_types=1);

namespace Talea\Admin;

use Talea\Core\Response;

/**
 * Settings actions of the opening-hours exceptions (2.10, Core\Hours): add, remove, apply or discard a proposal, the door sign. Used by Admin\Modules\Settings.
 */
trait HoursActions
{
    /** An exception to the opening hours (2.10, Core\Hours). */
    protected function actionHoursAdd(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back('', '', ['tab' => 'company']);
        }
        $error = \Talea\Core\Hours::save($this->app, ['from' => $this->request->post('exception_from'), 'to' => $this->request->post('exception_to'),
            'closed' => $this->request->postBool('exception_closed'), 'hours' => $this->request->post('exception_hours'), 'note' => $this->request->post('exception_note'),
            'notice_days' => $this->request->postInt('exception_notice', 7)]);

        return $error !== null ? $this->back($error, '', ['tab' => 'company'], 'error') : $this->back('The exception is saved.', '', ['tab' => 'company']);
    }

    protected function actionHoursDelete(): Response
    {
        if ($this->request->isPost()) {
            \Talea\Core\Hours::delete($this->app, $this->request->postInt('exception'));
        }

        return $this->back('The exception is deleted.', '', ['tab' => 'company']);
    }

    /** Applies an exception Claude proposed (3.2): from now on the site uses it. */
    protected function actionHoursApply(): Response
    {
        if ($this->request->isPost() && \Talea\Core\Hours::apply($this->app, $this->request->postInt('exception'))) {
            return $this->back('The exception is applied.', '', ['tab' => 'company']);
        }

        return $this->back('The proposal no longer exists.', '', ['tab' => 'company'], 'error');
    }

    /** Discards an exception Claude proposed (3.2) – the site never used it. */
    protected function actionHoursDiscard(): Response
    {
        if ($this->request->isPost()) {
            \Talea\Core\Hours::discard($this->app, $this->request->postInt('exception'));
        }

        return $this->back('The proposal is discarded.', '', ['tab' => 'company']);
    }

    /** A door sign for an exception, printable in a new tab (2.10, Core\HoursSign) – GET that only renders, so the demo shows it too. */
    protected function actionHoursSign(): Response
    {
        $exception = \Talea\Core\Hours::find($this->db, $this->request->getInt('exception'));
        if ($exception === null) {
            return $this->error('The exception no longer exists.', 404);
        }
        $urls = [];
        foreach (\Talea\Core\HoursSign::FORMATS as $format) {
            $urls[$format] = $this->url('hours_sign', ['exception' => $exception['id']] + ($format === 'a4' ? [] : ['format' => $format]));
        }

        // its own inline styles and the Print button script, nothing else may run – like the report preview
        return new Response(\Talea\Core\HoursSign::render($this->app, $exception, $this->request->get('format'), $urls), 200, ['Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https: http:; script-src 'self'; frame-ancestors 'self'; base-uri 'none'"]);
    }
}
