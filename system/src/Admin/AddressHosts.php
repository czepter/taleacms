<?php

declare(strict_types=1);

namespace Talea\Admin;

use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Domains;
use Talea\Core\Request;
use Talea\Core\Sites;

/** The host names of the current site for the Address and visibility tab (several websites only). */
final class AddressHosts
{
    /** @return array{site: array<string, mixed>, host: string, dnsOnly: bool}|null */
    public static function of(App $app, Db $db, Request $request): ?array
    {
        $id = (int) ($app->site['site_id'] ?? 1);
        foreach (Sites::overview($db, false) as $site) {
            if ((int) $site['site_id'] === $id) {
                return ['site' => $site, 'host' => Sites::requestHost($request), 'dnsOnly' => Domains::dnsOnly($db)];
            }
        }

        return null;
    }
}
