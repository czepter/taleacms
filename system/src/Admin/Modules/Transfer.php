<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Admin\Module;
use Talea\Core\SiteExport;
use Talea\Core\SiteImport;
use Talea\Core\Language;
use Talea\Core\Response;
use Talea\Core\ImageDownloader;
use Talea\Core\MigrationReport;
use Talea\Core\WebImport;
use Talea\Core\WpImport;
use Talea\Core\WpFile;
use Talea\Import\Batch;
use Talea\Import\Fetch;
use Talea\Import\Mapping;
use Talea\Import\Remote;
use Talea\Import\Sources;

/**
 * Import and export: import from any website by its address (2.6, Core\WebImport), moving from WordPress (a WXR file),
 * from another system with a structured export (3.0, Import\Batch: Ghost, Blogger…), moving a whole Talea site into a
 * new installation (1.8, Core\SiteImport) and export of the whole site to an open format.
 *
 * The import has three steps on one screen: 1. file (uploaded with the form, or via FTP to storage/import/),
 * 2. preview – what is in the file and what will not be converted, 3. import in batches (the form submits itself,
 * data-auto-submit). Images from the old site are downloaded only in a separate step on explicit request. Core\WpImport
 * does all the work; here are only the form handlers. The state of an ongoing import is in a file next to the export,
 * not in the session.
 */
final class Transfer extends Module
{
    public const string IDENT = 'transfer';
    public const string NAME = 'Import and export';
    public const string GROUP = 'Administration';
    public const string ICON = 'b-archive';
    public const bool ADMIN_ONLY = true;

    protected function actionList(): Response
    {
        $files = [];
        foreach (WpFile::listAll() as $s) {
            $files[] = $s + ['status' => WpImport::loadState($s['file'])];
        }

        return $this->view('list', 'Import and export', [
            'files' => $files,
            'uploadLimit' => min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size'))),
            'missingXml' => !class_exists(\XMLReader::class) || !class_exists(\Dom\HTMLDocument::class),
            'exports' => SiteExport::listAll(),
            'taleaFiles' => array_map(fn (array $s): array => $s + ['status' => SiteImport::loadState($s['file'])], SiteImport::listAll()),
            'siteContent' => SiteImport::siteContent($this->db),
            'hasZip' => class_exists(\ZipArchive::class),
            'webImports' => array_values(array_filter(array_map(fn (string $f): ?array => WebImport::load(substr(basename($f, '.json'), 4)), glob(WpFile::folder() . '/web-*.json') ?: []))),
            'canDownload' => ImageDownloader::isAvailable() && extension_loaded('gd'),
            'languages' => Language::additional($this->app->settings()),
            'reports' => MigrationReport::listAll(),
            'sources' => Sources::all(),
            'remoteSources' => Sources::remote(),
            'canFetch' => function_exists('curl_init'),
            'sourceFiles' => array_map(fn (array $s): array => $s + ['status' => Batch::loadState($s['file'])], Batch::listAll()),
        ]);
    }

    /* ---------- import from a website (2.6) ---------- */

    /** Starts finding the pages of the site at the given address. */
    protected function actionWebStart(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $url = trim($this->request->post('url'));
        $url = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
        if (!WebImport::validUrl($url) || !ImageDownloader::isAvailable()) {
            return $this->back('Enter the address of the site, e.g. https://www.example.com.', type: 'error');
        }
        $state = WebImport::newState($url, [
            'language' => in_array($this->request->post('language'), Language::additional($this->app->settings()), true) ? $this->request->post('language') : '',
            'images' => $this->request->postBool('images'), 'redirects' => $this->request->postBool('redirects'), 'news' => $this->request->postBool('news'),
        ]);
        WebImport::save($state);

        return $this->back('', 'web_progress', ['id' => $state['id']]);
    }

    /** GET shows where the import is; POST does one batch (finding pages, or importing them). The page submits itself until done. */
    protected function actionWebProgress(): Response
    {
        $state = WebImport::load($this->request->post('id') ?: $this->request->get('id'));
        if ($state === null) {
            return $this->back('The import does not exist any more.', type: 'error');
        }
        if ($this->request->isPost() && in_array($state['phase'], ['finding', 'import'], true)) {
            $lock = fopen(WpFile::folder() . '/web-import.lock', 'c');
            if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
                try {
                    @set_time_limit(60);
                    $state = WebImport::load($state['id']) ?? $state;
                    (new WebImport($this->db, $this->app->settings(), $this->app->auth()->id(), new ImageDownloader($state['web'], true)))->step($state);
                } finally {
                    WebImport::save($state);
                    flock($lock, LOCK_UN);
                }
            }
        }

        return $this->view('web', 'Import from a website', ['state' => $state]);
    }

    /** After the preview: import the pages found. */
    protected function actionWebRun(): Response
    {
        $state = WebImport::load($this->request->post('id'));
        if (!$this->request->isPost() || $state === null || $state['phase'] !== 'preview') {
            return $this->back();
        }
        $state['phase'] = 'import';
        $state['position'] = 0;
        WebImport::save($state);

        return $this->back('', 'web_progress', ['id' => $state['id']]);
    }

    /** Removes the record of an import; the imported pages stay. */
    protected function actionWebDelete(): Response
    {
        if ($this->request->isPost()) {
            WebImport::delete($this->request->post('id'));
        }

        return $this->back();
    }

    /* ---------- the migration parity report (2.7) ---------- */

    /** Starts checking an old site against this one. */
    protected function actionReportStart(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $url = trim($this->request->post('url'));
        $url = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
        if (!WebImport::validUrl($url) || !ImageDownloader::isAvailable()) {
            return $this->back('Enter the address of the site, e.g. https://www.example.com.', type: 'error');
        }
        $state = MigrationReport::newState($url);
        MigrationReport::save($state);

        return $this->back('', 'report', ['id' => $state['id']]);
    }

    /** GET shows the report; POST does one batch while it runs (the page submits itself until it is done). */
    protected function actionReport(): Response
    {
        $state = MigrationReport::load($this->request->post('id') ?: $this->request->get('id'));
        if ($state === null) {
            return $this->back('The report does not exist any more.', type: 'error');
        }
        $report = new MigrationReport($this->app, new ImageDownloader($state['web'], true));
        if ($this->request->isPost() && $state['phase'] !== 'done') {
            @set_time_limit(60);
            $report->step($state);
            MigrationReport::save($state);
        }

        return $this->view('report', 'Check the move', ['state' => $state, 'result' => $report->result($state)]);
    }

    /** Removes a saved report. */
    protected function actionReportDelete(): Response
    {
        if ($this->request->isPost()) {
            MigrationReport::delete($this->request->post('id'));
        }

        return $this->back();
    }

    /* ---------- import: 1. file ---------- */

    /** Uploading the export with the form; the file ends up in storage/import/ just like one uploaded via FTP. */
    protected function actionUpload(): Response
    {
        $file = $this->request->file('file');
        if (!$this->request->isPost() || $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return $this->back('The file could not be uploaded. If it is larger than the server allows, upload it over FTP into the storage/import/ folder.', type: 'error');
        }
        if (in_array(strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)), ['zip', 'json'], true)) {
            // an export of another Talea site
            $name = SiteImport::uploadName((string) $file['name']);
            try {
                if (!move_uploaded_file((string) $file['tmp_name'], WpFile::folder() . '/' . $name)) {
                    throw new \RuntimeException('The file could not be saved – check write permissions for storage/import.');
                }
            } catch (\RuntimeException $e) {
                return $this->back(self::message($e), type: 'error');
            }

            return $this->startTalea($name);
        }
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'xml') {
            return $this->back('The file must have the .xml extension – it is an export from WordPress (Tools → Export).', type: 'error');
        }
        try {
            $name = WpFile::uploadName((string) $file['name']);
            $target = WpFile::folder() . '/' . $name;
            // verify first, only then save: nothing that is not a WordPress export gets into the folder
            (new WpFile((string) $file['tmp_name']))->verifyContent();
            if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                throw new \RuntimeException('The file could not be saved – check write permissions for storage/import.');
            }
        } catch (\RuntimeException $e) {
            return $this->back(self::message($e), type: 'error');
        }

        return $this->start($name);
    }

    /** Choosing a file that already lies in storage/import/ (uploaded via FTP or earlier). */
    protected function actionSelect(): Response
    {
        $file = $this->request->post('file');
        $path = WpFile::path($file);
        if (!$this->request->isPost() || $path === null) {
            return $this->back('The file does not exist.', type: 'error');
        }
        try {
            (new WpFile($path))->verify();
        } catch (\RuntimeException $e) {
            return $this->back(self::message($e), type: 'error');
        }

        return $this->start($file);
    }

    private function start(string $file): Response
    {
        WpImport::saveState(WpImport::newState($file));

        return $this->back('', 'progress', ['file' => $file]);
    }

    protected function actionDeleteFile(): Response
    {
        $path = WpFile::path($this->request->post('file'));
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
            WpImport::deleteState($this->request->post('file'));
        }

        return $this->back('The file has been deleted. The imported content stays on the site.');
    }

    /* ---------- import: 2. preview and options ---------- */

    protected function actionPreview(): Response
    {
        $state = $this->state();
        if ($state === null || $state['phase'] === 'analysis') {
            return $this->back('', $state === null ? '' : 'progress', $state === null ? [] : ['file' => $state['file']]);
        }
        $settings = $this->app->settings();

        return $this->view('preview', 'Import from WordPress', [
            'state' => $state,
            'languages' => array_merge([Language::defaults($settings)], Language::additional($settings)),
            'categories' => $this->db->all('SELECT category_id, public_id, name, language FROM {categories} ORDER BY language, name'),
            'redirectsEnabled' => \Talea\Core\Extensions::isEnabled($settings, 'redirects'),
        ]);
    }

    /** Saves the options from the preview and starts the import. */
    protected function actionRun(): Response
    {
        $state = $this->state();
        if (!$this->request->isPost() || $state === null || $state['phase'] === 'analysis') {
            return $this->back();
        }
        $r = $this->request;
        $state['options'] = [
            'language' => in_array($r->post('language'), Language::additional($this->app->settings()), true) ? $r->post('language') : '',
            'drafts' => $r->postBool('drafts'), 'pages' => $r->postBool('pages'), 'builder' => $r->postBool('builder'),
            'redirects' => $r->postBool('redirects'), 'category' => $this->db->internalId('categories', $r->post('category')), 'collections' => $r->postBool('collections'),
        ];
        $state['phase'] = 'import';
        $state['position'] = 0;
        $state['result'] = WpImport::newState($state['file'])['result'];
        WpImport::saveState($state);

        return $this->back('', 'progress', ['file' => $state['file']]);
    }

    /* ---------- import: 3. progress in batches (preview, content and images) ---------- */

    /**
     * GET only shows where the import is; POST does one batch. Until it is done, the template submits the form again by itself.
     * A lock on the state file prevents two browser windows from importing at the same time.
     */
    protected function actionProgress(): Response
    {
        $state = $this->state();
        if ($state === null) {
            return $this->back('The file does not exist.', type: 'error');
        }
        $error = '';
        if ($this->request->isPost() && in_array($state['phase'], ['analysis', 'import', 'images'], true)) {
            $lock = fopen(WpFile::folder() . '/import.lock', 'c');
            if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
                try {
                    @set_time_limit(60);
                    $state = WpImport::loadState($state['file']) ?? $state; // fresh state only under the lock
                    $this->batch($state);
                } catch (\RuntimeException $e) {
                    $error = self::message($e);
                } finally {
                    WpImport::saveState($state);
                    flock($lock, LOCK_UN);
                }
            }
        }
        if ($state['phase'] === 'preview' && $error === '') {
            return $this->back('', 'preview', ['file' => $state['file']]);
        }

        return $this->view('progress', 'Import from WordPress', [
            'state' => $state, 'error' => $error,
            'canDownload' => ImageDownloader::isAvailable() && extension_loaded('gd'),
            'domain' => ImageDownloader::domainFromUrl((string) $state['web']['url']),
        ]);
    }

    /** @param array<string, mixed> $state */
    private function batch(array &$state): void
    {
        $import = new WpImport($this->db, $this->app->settings(), $this->request->basePath(), $this->app->auth()->id());
        match ($state['phase']) {
            'analysis' => WpImport::analyze($state),
            'import' => $import->import($state),
            'images' => $import->images($state, new ImageDownloader((string) $state['web']['url'])),
        };
    }

    /** Explicit start of downloading images from the old site (only after the content import). */
    protected function actionImages(): Response
    {
        $state = $this->state();
        if (!$this->request->isPost() || $state === null || !in_array($state['phase'], ['done', 'images_done'], true) || !ImageDownloader::isAvailable()) {
            return $this->back();
        }
        (new WpImport($this->db, $this->app->settings(), $this->request->basePath(), $this->app->auth()->id()))->startImages($state);
        WpImport::saveState($state);

        return $this->back('', 'progress', ['file' => $state['file']]);
    }

    /** @return array<string, mixed>|null import state of the file from the URL or the form */
    private function state(): ?array
    {
        $file = $this->request->isPost() && $this->request->post('file') !== '' ? $this->request->post('file') : $this->request->get('file');

        return WpFile::path($file) === null ? null : WpImport::loadState($file);
    }

    /* ---------- import from another system (3.0, Import\Batch): Ghost, Blogger… ---------- */

    /** Uploading an export of another system; WordPress keeps its own flow (actionUpload). The file is verified before it is saved. */
    protected function actionSourceUpload(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $key = $this->request->post('system');
        if ($key === 'wordpress') {
            return $this->actionUpload();
        }
        $class = Sources::byKey($key);
        $file = $this->request->file('file');
        if ($class !== null && is_subclass_of($class, Remote::class)) {
            return $this->back(t('%s has no export file – enter the site address below and the content is fetched from its API.', $class::name()), type: 'error');
        }
        if ($class === null || $file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return $this->back('The file could not be uploaded. If it is larger than the server allows, upload it over FTP into the storage/import/sources/ folder.', type: 'error');
        }
        if (!in_array(strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)), $class::extensions(), true)) {
            return $this->back(t('The file must have the .%s extension.', $class::extensions()[0]) . ' ' . t($class::hint()), type: 'error');
        }
        try {
            $name = Batch::uploadName($key, (string) $file['name']);
            (new $class((string) $file['tmp_name']))->verify(); // verify first, only then save
            if (!move_uploaded_file((string) $file['tmp_name'], Batch::folder() . '/' . $name)) {
                throw new \RuntimeException('The file could not be saved – check write permissions for storage/import.');
            }
        } catch (\RuntimeException $e) {
            return $this->back(self::message($e), type: 'error');
        }

        return $this->startSource($name);
    }

    /** A file already in storage/import/sources (uploaded over FTP or earlier): read it again from the start. */
    protected function actionSourceSelect(): Response
    {
        $file = $this->request->post('file');
        $path = Batch::path($file);
        if (!$this->request->isPost() || $path === null) {
            return $this->back('The file does not exist.', type: 'error');
        }
        try {
            Sources::open((string) Sources::keyOfFile($file), $path)->verify();
        } catch (\RuntimeException $e) {
            return $this->back(self::message($e), type: 'error');
        }

        return $this->startSource($file);
    }

    private function startSource(string $file): Response
    {
        Batch::saveState(Batch::newState($file));

        return $this->back('', 'source_progress', ['file' => $file]);
    }

    /**
     * A system without an export file (Import\Remote: Joomla, Drupal): the site address, the optional API token and the steps
     * to fetch. The token goes into the session only – Fetch sends it as a header, the progress step forgets it when the
     * fetch ends; it is never written to the state, the file, the database or the change log. A fetch from the same site
     * replaces the earlier fetched file.
     */
    protected function actionSourceFetch(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        $class = Sources::remote()[$this->request->post('system')] ?? null;
        if ($class === null) {
            return $this->back('Unknown system.', type: 'error');
        }
        if (!function_exists('curl_init')) {
            return $this->back('The PHP extension curl is missing on the server – the site’s API cannot be read without it.', type: 'error');
        }
        $url = trim($this->request->post('url'));
        $url = $url !== '' && !preg_match('#^https?://#i', $url) ? 'https://' . $url : $url;
        $url = rtrim((string) preg_replace('#[?\#].*$#', '', $url), '/');
        if (!WebImport::validUrl($url) || !Fetch::allowedSite($url)) {
            return $this->back('Enter the public address of the site, e.g. https://www.example.com – not an internal address.', type: 'error');
        }
        $token = trim($this->request->post('token'));
        if (strlen($token) > 500 || preg_match('/[\x00-\x1f\x7f]/', $token)) {
            return $this->back('The token has an unexpected form.', type: 'error');
        }
        $file = Fetch::fileName($class::key(), $url);
        if (!Batch::isValidName($file)) {
            return $this->back('Enter the public address of the site, e.g. https://www.example.com – not an internal address.', type: 'error');
        }
        Batch::deleteState($file);
        file_put_contents(Batch::folder() . '/' . $file, (string) json_encode(Fetch::skeleton($class::key(), $url)), LOCK_EX);
        $state = Batch::newState($file);
        $state['phase'] = 'download';
        $state['download'] = Fetch::state($class, $url, $this->request->postList('steps'));
        Batch::saveState($state);
        $this->app->session->set(Fetch::sessionKey($file), $token);

        return $this->back('', 'source_progress', ['file' => $file]);
    }

    protected function actionSourceDelete(): Response
    {
        $path = Batch::path($this->request->post('file'));
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
            Batch::deleteState($this->request->post('file'));
            $this->app->session->remove(Fetch::sessionKey($this->request->post('file')));
        }

        return $this->back('The file has been deleted. The imported content stays on the site.');
    }

    /** Step 2: what the file contains and the mapping – what becomes what, whose the posts will be. */
    protected function actionSourcePreview(): Response
    {
        $state = $this->sourceState();
        if ($state === null || in_array($state['phase'], ['download', 'analysis'], true)) {
            return $this->back('', $state === null ? '' : 'source_progress', $state === null ? [] : ['file' => $state['file']]);
        }
        $settings = $this->app->settings();

        return $this->view('source-preview', t('Import from %s', Sources::byKey($state['source'])::name()), [
            'state' => $state,
            'source' => Sources::byKey($state['source']),
            'languages' => array_merge([Language::defaults($settings)], Language::additional($settings)),
            'categories' => $this->db->all('SELECT category_id, public_id, name, language FROM {categories} ORDER BY language, name'),
            'users' => $this->db->all('SELECT user_id, public_id, name, username FROM {users} WHERE blocked = 0 ORDER BY name, username'),
            'redirectsEnabled' => \Talea\Core\Extensions::isEnabled($settings, 'redirects'),
        ]);
    }

    /** Saves the mapping from the preview and starts the import. */
    protected function actionSourceRun(): Response
    {
        $state = $this->sourceState();
        if (!$this->request->isPost() || $state === null || in_array($state['phase'], ['download', 'analysis'], true)) {
            return $this->back();
        }
        $r = $this->request;
        $authors = [];
        foreach ($state['dictionary']['authors'] as $key => $name) {
            $authors[$key] = $this->db->internalId('users', $r->post('author_' . substr(sha1((string) $key), 0, 12)));
        }
        $state['mapping'] = Mapping::normalize([
            'posts' => $r->post('posts'), 'pages' => $r->post('pages'), 'categories' => $r->post('categories'), 'tags' => $r->post('tags'),
            'authors' => $authors, 'language' => $r->post('language'),
            'drafts' => $r->postBool('drafts'), 'builder' => $r->postBool('builder'), 'redirects' => $r->postBool('redirects'),
            'default_category' => $this->db->internalId('categories', $r->post('default_category')), 'site_url' => $r->post('site_url'),
        ], Language::additional($this->app->settings()), array_map('intval', array_column($this->db->all('SELECT user_id FROM {users} WHERE blocked = 0'), 'user_id')));
        if ($state['web']['url'] === '' && $state['mapping']['site_url'] === '' && $r->post('site_url') !== '') {
            return $this->back('Enter the address of the site, e.g. https://www.example.com.', 'source_preview', ['file' => $state['file']], 'error');
        }
        $state['phase'] = 'import';
        $state['position'] = 0;
        $state['result'] = Batch::newState($state['file'])['result'];
        Batch::saveState($state);

        return $this->back('', 'source_progress', ['file' => $state['file']]);
    }

    /** GET only shows where the import is; POST does one batch under the import lock. The page submits itself until done. */
    protected function actionSourceProgress(): Response
    {
        $state = $this->sourceState();
        if ($state === null) {
            return $this->back('The file does not exist.', type: 'error');
        }
        $error = '';
        if ($this->request->isPost() && in_array($state['phase'], ['download', 'analysis', 'import', 'images'], true)) {
            $lock = fopen(WpFile::folder() . '/import.lock', 'c');
            if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
                $tokenKey = Fetch::sessionKey((string) $state['file']);
                try {
                    @set_time_limit(60);
                    $state = Batch::loadState($state['file']) ?? $state; // fresh state only under the lock
                    $import = new Batch($this->db, $this->app->settings(), $this->request->basePath(), $this->app->auth()->id());
                    match ($state['phase']) {
                        'download' => Fetch::step($state, (string) $this->app->session->get($tokenKey, '')),
                        'analysis' => Batch::analyze($state),
                        'import' => $import->import($state),
                        'images' => $import->images($state, Batch::downloader($state)),
                        default => null,
                    };
                } catch (\RuntimeException $e) {
                    $error = self::message($e);
                } finally {
                    if ($state['phase'] !== 'download' || $error !== '') {
                        $this->app->session->remove($tokenKey); // the token lives only while the fetch runs
                    }
                    Batch::saveState($state);
                    flock($lock, LOCK_UN);
                }
            }
        }
        if ($state['phase'] === 'preview' && $error === '') {
            return $this->back('', 'source_preview', ['file' => $state['file']]);
        }

        return $this->view('source-progress', t('Import from %s', Sources::byKey($state['source'])::name()), [
            'state' => $state, 'error' => $error, 'source' => Sources::byKey($state['source']),
            'canDownload' => ImageDownloader::isAvailable() && extension_loaded('gd'),
            'domain' => ImageDownloader::domainFromUrl(Batch::siteUrl($state)),
            'anyHost' => Batch::sourceFor($state)->imagesFromAnyHost(),
        ]);
    }

    /** Explicit start of downloading images from the old site (only after the content import). */
    protected function actionSourceImages(): Response
    {
        $state = $this->sourceState();
        if (!$this->request->isPost() || $state === null || !in_array($state['phase'], ['done', 'images_done'], true) || !ImageDownloader::isAvailable()) {
            return $this->back();
        }
        (new Batch($this->db, $this->app->settings(), $this->request->basePath(), $this->app->auth()->id()))->startImages($state);
        Batch::saveState($state);

        return $this->back('', 'source_progress', ['file' => $state['file']]);
    }

    /** @return array<string, mixed>|null import state of the file from the URL or the form */
    private function sourceState(): ?array
    {
        $file = $this->request->isPost() && $this->request->post('file') !== '' ? $this->request->post('file') : $this->request->get('file');

        return Batch::path($file) === null ? null : Batch::loadState($file);
    }

    /* ---------- import of a Talea export (1.8) ---------- */

    /** Reads the export (header, rows per table) and shows the preview; a file that is not a Talea export is deleted. */
    private function startTalea(string $file): Response
    {
        SiteImport::deleteState($file);
        $state = SiteImport::newState($file);
        try {
            @set_time_limit(120);
            SiteImport::prepare($state);
        } catch (\RuntimeException $e) {
            SiteImport::deleteState($file);
            @unlink(WpFile::FOLDER . '/' . $file);

            return $this->back(self::message($e), type: 'error');
        }
        SiteImport::saveState($state);

        return $this->back('', 'talea', ['file' => $file]);
    }

    /** An export already in storage/import (uploaded over FTP): read it again from the start. */
    protected function actionTaleaSelect(): Response
    {
        $file = $this->request->post('file');
        if (!$this->request->isPost() || SiteImport::path($file) === null) {
            return $this->back('The file does not exist.', type: 'error');
        }

        return $this->startTalea($file);
    }

    protected function actionTaleaDelete(): Response
    {
        $file = $this->request->post('file');
        if ($this->request->isPost() && ($path = SiteImport::path($file)) !== null) {
            unlink($path);
            SiteImport::deleteState($file);
        }

        return $this->back('The file has been deleted. The imported content stays on the site.');
    }

    /**
     * Preview, progress and result of the import. GET only shows; POST (the form submits itself) does one batch under
     * a lock, so two browser windows never import at the same time.
     */
    protected function actionTalea(): Response
    {
        $file = $this->request->isPost() ? $this->request->post('file') : $this->request->get('file');
        $state = SiteImport::path($file) === null ? null : SiteImport::loadState($file);
        if ($state === null) {
            return $this->back('The file does not exist.', type: 'error');
        }
        $error = '';
        if ($this->request->isPost() && in_array($state['phase'], ['data', 'media'], true)) {
            $lock = fopen(WpFile::folder() . '/import.lock', 'c');
            if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
                try {
                    @set_time_limit(60);
                    $state = SiteImport::loadState($file) ?? $state;
                    $import = new SiteImport($this->db, $this->app->settings(), (int) $this->app->auth()->id());
                    $state['phase'] === 'data' ? $import->importData($state) : $import->importMedia($state);
                    if ($state['phase'] === 'done') {
                        SiteImport::cleanUp($file);
                        \Talea\Admin\ChangeLog::write($this->app, 'transfer', 'import of a Talea export', $file);
                    }
                } catch (\RuntimeException $e) {
                    $error = self::message($e);
                } finally {
                    SiteImport::saveState($state);
                    flock($lock, LOCK_UN);
                }
            }
        }

        return $this->view('talea', 'Import from Talea', ['state' => $state, 'error' => $error, 'siteContent' => SiteImport::siteContent($this->db)]);
    }

    /** Confirmation in the preview: the import starts (the first batch backs up the database and empties the content). */
    protected function actionTaleaRun(): Response
    {
        $file = $this->request->post('file');
        $state = SiteImport::path($file) === null ? null : SiteImport::loadState($file);
        if (!$this->request->isPost() || $state === null || $state['phase'] !== 'preview' || !$this->request->postBool('confirmation')) {
            return $this->back('Confirm that the content of this site will be replaced.', $state === null ? '' : 'talea', $state === null ? [] : ['file' => $file], 'error');
        }
        if (!SiteImport::siteContent($this->db)['empty']) {
            return $this->back('The site already has its own content. A Talea export can be imported only into a new, empty site.', 'talea', ['file' => $file], 'error');
        }
        $state['phase'] = 'data';
        SiteImport::saveState($state);

        return $this->back('', 'talea', ['file' => $file]);
    }

    /* ---------- export ---------- */

    protected function actionExport(): Response
    {
        if (!$this->request->isPost()) {
            return $this->back();
        }
        try {
            $result = SiteExport::create($this->db, $this->app->settings());
        } catch (\RuntimeException $e) {
            return $this->back($e->getMessage(), type: 'error');
        }
        if ($result['reason'] !== '') {
            $this->app->session->flash('info', $result['reason']);
        }

        return $this->back('The export is ready – download it from the list below.');
    }

    /**
     * Download of the export. The archive can have hundreds of MB, so it is not sent via Response (which holds the whole body
     * in memory), but in chunks directly from the file. Access is guarded by the admin (the module is administrator only),
     * the file name by Core\SiteExport::path().
     */
    protected function actionDownload(): Response
    {
        $path = SiteExport::path($this->request->get('file'));
        if ($path === null) {
            return $this->error('The export does not exist.', 404);
        }
        $this->sendFile($path, basename($path));
    }

    protected function actionDeleteExport(): Response
    {
        $path = SiteExport::path($this->request->post('file'));
        if ($this->request->isPost() && $path !== null) {
            unlink($path);
        }

        return $this->back('The export has been deleted.');
    }

    /** Exception message in the admin language; the number (XML line, response code) is carried by getCode(), so that the text can be translated. */
    private static function message(\RuntimeException $e): string
    {
        return t($e->getMessage()) . (is_int($e->getCode()) && $e->getCode() > 0 ? ' ' . $e->getCode() : '');
    }

    /** "8M" from php.ini → bytes. */
    private static function bytes(string $value): int
    {
        $number = (int) $value;

        return $number <= 0 ? PHP_INT_MAX : $number * match (strtolower(substr(trim($value), -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
    }
}
