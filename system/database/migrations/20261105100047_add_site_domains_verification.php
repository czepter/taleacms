<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Multisite, domains (docs/specs/multisite.md, "Domains and certificates"): a host answers only once its owner has shown the host points
 * at this install. `verified_at` NULL = not verified (the host does not route), `token` is what the HTTP and DNS checks look for, `kind`
 * tells a host the owner brought ('domain') from the install's own preview subdomain ('preview'). Owner table, written once; the hosts
 * that exist now were put there by the owner or an operator, so they count as verified.
 */
final class AddSiteDomainsVerification extends AbstractMigration
{
    public function change(): void
    {
        $prefix = (string) $this->getAdapter()->getOption('table_prefix');

        $this->table('site_domains')
            ->addColumn('verified_at', 'datetime', ['null' => true, 'comment' => 'the host was shown to point at this install; NULL = it does not route'])
            ->addColumn('token', 'char', ['limit' => 32, 'null' => true, 'comment' => 'the secret the HTTP check and the DNS TXT record carry; NULL once verified'])
            ->addColumn('kind', 'string', ['limit' => 10, 'null' => false, 'default' => 'domain', 'comment' => 'domain | preview (TALEA_PREVIEW_DOMAIN)'])
            ->update();

        if ($this->isMigratingUp() && $prefix === (string) $this->getAdapter()->getOption('owner_prefix')) {
            $this->getUpdateBuilder()->update($prefix . 'site_domains')->set('verified_at', date('Y-m-d H:i:s'))->execute();
        }
    }
}
