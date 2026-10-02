<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Connectors as Hub;
use Kaleta\Core\Response;

/**
 * Connections to outside services (2.13, Core\Connectors): for each service of the curated list its credentials (the
 * site's own OAuth app or an API token – never shown back), its settings, connecting and disconnecting, and the last
 * calls. Administrators only.
 */
final class Connectors extends Module
{
    public const string IDENT = 'connectors';
    public const string NAME = 'Connections';
    public const string GROUP = 'Administration';
    public const string ICON = 'rozsireni';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $services = [];
        foreach (Hub::SERVICES as $class) {
            $services[$class::KEY] = ['class' => $class, 'row' => Hub::row($this->db, $class::KEY), 'config' => Hub::config($this->db, $class::KEY)];
        }

        return $this->view('list', 'Connections', ['services' => $services, 'status' => array_column(Hub::status($this->db), null, 'service'), 'redirectUri' => Hub::redirectUri($this->app),
            'log' => $this->db->all('SELECT created_at, service, action, status, ok, ms, error FROM {connector_log} ORDER BY id DESC LIMIT 30'),
            'queue' => (int) $this->db->value('SELECT COUNT(*) FROM {connector_queue} WHERE next_attempt IS NOT NULL')]);
    }

    /** The credentials and settings of one service; an empty secret field keeps the stored secret. */
    protected function actionSave(): Response
    {
        $key = $this->request->post('service');
        if (!$this->request->isPost() || Hub::service($key) === null) {
            return $this->back();
        }
        $config = is_array($_POST['config'] ?? null) ? array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $_POST['config']) : [];
        Hub::saveCredentials($this->app, $key, $this->request->post('client_id'), $this->request->post('secret'), $this->request->post('account'), $config);

        return $this->back('Saved.');
    }

    /** Starts the sign-in of an OAuth service: to the service, which sends the administrator back to the callback. */
    protected function actionConnect(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            return Response::redirect(Hub::authorizeUrl($this->app, $this->request->post('service')));
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), '', [], 'chyba');
        }
    }

    protected function actionCallback(): Response
    {
        if ($this->request->get('error') !== '') {
            return $this->back(t('The sign-in was cancelled: %s', mb_substr($this->request->get('error'), 0, 80)), '', [], 'chyba');
        }
        try {
            $key = Hub::callback($this->app, $this->request->get('code'), $this->request->get('state'));
        } catch (\DomainException $e) {
            return $this->back($e->getMessage(), '', [], 'chyba');
        }

        return $this->back(t('%s is connected.', (string) (Hub::service($key))::NAME));
    }

    /** "Create the sheet" (2.13, Core\EnquirySheet): the Google spreadsheet the enquiries go to; its id is kept in the settings. */
    protected function actionSheet(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $error = \Kaleta\Core\EnquirySheet::create($this->app);

        return $error === '' ? $this->back('The sheet was created – tick “Enquiries to a sheet” and new enquiries will appear in it.') : $this->back($error, '', [], 'chyba');
    }

    protected function actionDisconnect(): Response
    {
        if ($this->request->isPost() && Hub::service($this->request->post('service')) !== null) {
            Hub::disconnect($this->app, $this->request->post('service'));

            return $this->back('Disconnected – the stored sign-in was deleted.');
        }

        return $this->back();
    }
}
