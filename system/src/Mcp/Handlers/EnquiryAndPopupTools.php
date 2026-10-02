<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;

/**
 * MCP tools: enquiries and pop-ups (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait EnquiryAndPopupTools
{
    /** list_enquiries (seznam_poptavek) */
    private function toolListEnquiries(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        if (!\Kaleta\Core\Extensions::isEnabled($siteSettings, 'poptavky') || !$auth->hasModule('enquiries')) {
            throw new \DomainException('Poptávky smí číst jen uživatel s právem k Poptávkám (rozšíření Formuláře a poptávky musí být zapnuté).');
        }
        $whereParts = [];
        $params = [];
        $statuses = ['nove' => 0, 'prectene' => 1, 'vyrizene' => 2];
        if (isset($statuses[$a['stav'] ?? ''])) {
            $whereParts[] = 'stav = ?';
            $params[] = $statuses[$a['stav']];
        }
        if (($a['hledat'] ?? '') !== '') {
            $whereParts[] = '(email LIKE ? OR data LIKE ?)';
            $search = '%' . addcslashes((string) $a['hledat'], '%_\\') . '%';
            array_push($params, $search, $search);
        }
        $limit = max(1, min(50, (int) ($a['limit'] ?? 20)));
        $statusNames = array_flip($statuses);

        // about (2.12): what the form was about – the collection item, page or pop-up it was on (Front\EnquiryTopic)
        return array_map(fn (array $p): array => ['id' => (int) $p['idp'], 'datum' => substr((string) $p['datum'], 0, 16), 'formular' => $p['formular'], 'stranka' => $p['stranka'], 'about' => $p['tema'] !== '' ? $p['tema'] : null,
            'kampan' => \Kaleta\Front\Forms::campaignText((string) $p['kampan']), 'first_page' => $p['vstup'] !== '' ? $p['vstup'] : null, 'came_from' => $p['odkud'] !== '' ? $p['odkud'] : null, 'email' => $p['email'], 'stav' => $statusNames[(int) $p['stav']] ?? '',
            'pole' => array_map(fn (array $d): array => ['popisek' => $d[0], 'hodnota' => $d[1]], json_decode((string) $p['data'], true) ?: [])],
            $db->all('SELECT idp, datum, formular, stranka, tema, vstup, odkud, kampan, email, stav, data FROM {poptavky}' . ($whereParts !== [] ? ' WHERE ' . implode(' AND ', $whereParts) : '') . ' ORDER BY idp DESC LIMIT ' . $limit, $params));
    }

    /** update_enquiry and delete_enquiry */
    private function toolUpdateEnquiry(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->hasModule('enquiries'), 'Enquiries can be changed only by users with the Enquiries section.');
        $enquiry = $db->one('SELECT idp, data FROM {poptavky} WHERE idp = ?', [$id]) ?? throw new \InvalidArgumentException('The enquiry does not exist. Use list_enquiries.');
        if ($name === 'delete_enquiry') {
            \Kaleta\Admin\Modules\Enquiries::deleteAttachments([$enquiry]);
            $db->delete('poptavky', ['idp' => $id]);

            return ['deleted' => $id];
        }
        $changes = [];
        if (isset($a['status'])) {
            $status = ['new' => 0, 'read' => 1, 'resolved' => 2][(string) $a['status']] ?? throw new \InvalidArgumentException('status must be new, read or resolved.');
            $changes['stav'] = $status;
        }
        if (isset($a['note'])) {
            $changes['poznamka'] = mb_substr(trim((string) $a['note']), 0, 5000);
        }
        if ($changes !== []) {
            $db->update('poptavky', $changes, ['idp' => $id]);
        }

        return ['id' => $id, 'changed' => array_keys($changes)];
    }

    /** delete_enquiry: the same as update_enquiry */
    private function toolDeleteEnquiry(string $name, array $a): mixed
    {
        return $this->toolUpdateEnquiry($name, $a);
    }

    /** list_popups (seznam_popupu) */
    private function toolListPopups(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();

        return array_map($this->popup(...), \Kaleta\Builder\Popups::all($db));
    }

    /** save_popup (uloz_popup) */
    private function toolSavePopup(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();

        return $this->popup($this->savePopup($a), true);
    }

    /** delete_popup */
    private function toolDeletePopup(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->isAdmin(), 'Pop-ups can be deleted only by an administrator.');
        $need($db->delete('popupy', ['idpp' => $id]) > 0, 'The pop-up does not exist. Use list_popups.');
        \Kaleta\Front\Cache::clear();

        return ['deleted' => $id];
    }
}
