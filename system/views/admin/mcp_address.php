<?php
/**
 * The address of the site's MCP endpoint for the Claude connector with a Copy button (image/admin.js, data-kopirovat), and
 * a warning when the site is not on HTTPS – the Claude app adds custom connectors only on HTTPS (3.5). Used on the
 * dashboard, in Claude settings and in My account.
 *
 * @var string $url the MCP address (Core\AskClaude::mcpUrl)
 * @var string $id  the id of the address element (unique on the page)
 */
?>
<p class="mcp-adresa"><code id="<?= e($id) ?>"><?= e($url) ?></code> <button class="navigace" type="button" data-kopirovat="#<?= e($id) ?>"><?= e(t('Copy address')) ?></button></p>
<?php if (!Kaleta\Core\AskClaude::secure($url)): ?>
<p class="hlaska hlaska-varovani"><?= e(t('This site is not on HTTPS, so the Claude app cannot connect to it yet. Switch the site to HTTPS – most hosting does it for free – and open the administration at its https:// address.')) ?></p>
<?php endif ?>
