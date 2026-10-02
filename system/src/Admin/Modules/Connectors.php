<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Admin\Module;
use Kaleta\Core\Connectors as Hub;
use Kaleta\Core\GoogleBusiness;
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
            'queue' => (int) $this->db->value('SELECT COUNT(*) FROM {connector_queue} WHERE next_attempt IS NOT NULL'),
            // the Business Profile section of Google (2.13, Core\GoogleBusiness): the locations to choose from, the chosen one, the last fetched rating
            'gbpLocations' => GoogleBusiness::knownLocations($this->app->settings()), 'gbpLocation' => GoogleBusiness::location($this->db), 'gbpSummary' => GoogleBusiness::summary($this->app->settings())]);
    }

    /** "Load my locations": the Business Profile locations of the connected Google account, for the location select. */
    protected function actionGbpLocations(): Response
    {
        if (!$this->request->isPost() || !Hub::isConnected($this->db, \Kaleta\Connectors\Google::KEY)) {
            return $this->back();
        }
        [$locations, $error] = GoogleBusiness::loadLocations($this->app);
        if ($error !== null) {
            return $this->back(t('The locations could not be loaded: %s', $error), '', [], 'chyba');
        }

        return $this->back($locations === [] ? t('The Google account manages no Business Profile location.') : t('%d locations loaded – choose one and save.', count($locations)));
    }

    /** "Sync now": the hours go to the chosen location and the reviews come in, without waiting for the daily job. */
    protected function actionGbpSync(): Response
    {
        if (!$this->request->isPost() || !GoogleBusiness::ready($this->db)) {
            return $this->back();
        }
        GoogleBusiness::hoursChanged($this->app);
        $error = GoogleBusiness::pullReviews($this->app);
        Hub::processQueue($this->app);

        return $error === null ? $this->back('The hours were sent and the reviews fetched.') : $this->back(t('The reviews could not be fetched: %s', $error), '', [], 'chyba');
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

    protected function actionDisconnect(): Response
    {
        if ($this->request->isPost() && Hub::service($this->request->post('service')) !== null) {
            Hub::disconnect($this->app, $this->request->post('service'));

            return $this->back('Disconnected – the stored sign-in was deleted.');
        }

        return $this->back();
    }
}
