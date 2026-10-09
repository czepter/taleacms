<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Response;

/**
 * News subscribers (the Newsletter extension): who subscribed with the Newsletter subscription element and whether they
 * confirmed the subscription. Newsletters go to them from the Newsletters module (Core\Mailing); confirmed addresses can
 * also go to a mailing service or be exported to CSV, with an unsubscribe link, for another tool.
 */
final class Subscribers extends Module
{
    public const string IDENT = 'subscribers';
    public const string EXTENSION = 'newsletter_signup';
    public const string NAME = 'Subscribers';
    public const string GROUP = 'Customers';
    public const string ICON = 'newsletter_signup';

    protected function actionList(): Response
    {
        $search = mb_substr($this->request->get('search'), 0, 100);
        $pageNumber = max(1, $this->request->getInt('page', 1));
        $whereParts = $search !== '' ? ' WHERE email LIKE ?' : '';
        $params = $search !== '' ? ['%' . addcslashes($search, '%_\\') . '%'] : [];

        return $this->view('list', 'Subscribers', [
            'subscribers' => $this->db->all('SELECT * FROM {subscribers}' . $whereParts . ' ORDER BY subscriber_id DESC LIMIT 100 OFFSET ' . (($pageNumber - 1) * 100), $params),
            'total' => (int) $this->db->value('SELECT COUNT(*) FROM {subscribers}' . $whereParts, $params),
            'confirmed' => (int) $this->db->value('SELECT COUNT(*) FROM {subscribers} WHERE status = 1'),
            'service' => \Kaleta\Core\Newsletter::isEnabled($this->app->settings()) ? $this->app->settings()->get('newsletter_service') : '',
            'queue' => $this->db->one('SELECT SUM(next_attempt_at IS NOT NULL) AS ceka, SUM(next_attempt_at IS NULL) AS chyby FROM {subscription_queue}') ?? ['ceka' => 0, 'chyby' => 0],
            'search' => $search, 'pageNumber' => $pageNumber,
        ]);
    }

    protected function actionDelete(): Response
    {
        $o = $this->request->isPost() ? $this->db->one('SELECT email, status FROM {subscribers} WHERE subscriber_id = ?', [$this->request->postInt('subscriber_id')]) : null;
        if ($o !== null) {
            $this->db->delete('subscribers', ['subscriber_id' => $this->request->postInt('subscriber_id')]);
            if ((int) $o['status'] === 1) {
                \Kaleta\Core\Newsletter::enqueue($this->app, (string) $o['email'], 'remove'); // from the mailing service too
            }
        }

        return $this->back('The subscriber has been deleted.');
    }

    /** After connecting the service: all confirmed subscribers who are not in it yet go to the queue – and the first batch right away. */
    protected function actionSync(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return $this->back();
        }
        $count = \Kaleta\Core\Newsletter::enqueueAll($this->app);
        \Kaleta\Core\Newsletter::processQueue($this->app, 20);

        return $this->back(t('%d subscribers are going to the mailing service; the site sends the rest gradually in the background.', $count));
    }

    /** Retry failed transfers right away. */
    protected function actionRetry(): Response
    {
        if (!$this->request->isPost() || !$this->app->auth()->isAdmin()) {
            return $this->back();
        }
        \Kaleta\Core\Newsletter::retry($this->app);
        \Kaleta\Core\Newsletter::processQueue($this->app, 20);

        return $this->back('The failed transfers were tried again – see the result in the Service column.');
    }

    /** Confirmed subscribers to CSV (UTF-8 with BOM, semicolon) with an unsubscribe link. */
    protected function actionCsv(): Response
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Email'), t('Subscribed'), t('Confirmed'), t('Unsubscribe link')], ';', '"', '');
        foreach ($this->db->all('SELECT * FROM {subscribers} WHERE status = 1 ORDER BY subscriber_id') as $o) {
            $row = array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                [(string) $o['email'], (string) $o['created_at'], (string) $o['confirmed_at'], \Kaleta\Front\Subscription::unsubscribeLink($this->app, (string) $o['token'])]);
            fputcsv($f, $row, ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);
        \Kaleta\Admin\ChangeLog::write($this->app, 'subscribers', 'export CSV', '');

        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="odberatele-' . date('Y-m-d') . '.csv"']);
    }
}
