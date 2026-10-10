<?php
/**
 * The "Webhooks" tab (1.8): addresses, the signing secret and the delivery log. For the variables and the $field function see list.php.
 *
 * @var string $webhookSecret
 * @var list<array<string, mixed>> $deliveries
 */
?>
<p class="notice"><?= e(t('A webhook sends data to another service the moment something happens on the site – a new enquiry to a CRM or Slack, a published news item to Make, Zapier or n8n, which then share it on social networks.')) ?></p>
<fieldset>
<legend><?= e(t('Addresses')) ?></legend>
<?php
$field('webhook_enquiries', 'New enquiry webhook', 'url', 'Where to send every new enquiry from a form (CRM, Make, Zapier, n8n, Slack). It receives the form name, the filled-in fields and the sender\'s e-mail.', 'placeholder="https://"');
$field('webhook_url', 'Webhook after publishing news', 'url', 'An address from Make, Zapier, IFTTT or n8n. When a news item is published, the system sends it the title, lead, address and image – the service then shares it on Facebook, X, Mastodon, Slack and so on.', 'placeholder="https://"');
?>
</fieldset>
<fieldset>
<legend><?= e(t('Signature')) ?></legend>
<div class="row">
	<label for="webhook_secret"><?= e(t('Signing secret')) ?></label>
	<div><input class="textfield wide code" type="text" id="webhook_secret" value="<?= e($webhookSecret) ?>" readonly autocomplete="off" spellcheck="false">
	<span class="help"><?= e(t('Every call carries the headers X-Talea-Timestamp and X-Talea-Signature (sha256=HMAC-SHA256 of “timestamp.body” with this secret). A receiver that checks the signature knows the call came from your site and was not changed. Keep the secret private.')) ?></span>
	<button class="navigation" type="submit" formaction="<?= e($module->url('new_webhook_secret')) ?>" data-confirm="<?= e(t('Create a new secret? Receivers that check the signature will reject calls until you give them the new one.')) ?>"><?= e(t('Create a new secret')) ?></button></div>
</div>
<details class="advanced">
<summary><?= e(t('How to check the signature')) ?></summary>
<pre class="code"><code>$body = file_get_contents('php://input');
$ts = $_SERVER['HTTP_X_TALEA_TIMESTAMP'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
$valid = hash_equals($expected, $_SERVER['HTTP_X_TALEA_SIGNATURE'] ?? '')
    &amp;&amp; abs(time() - (int) $ts) &lt; 300;</code></pre>
</details>
</fieldset>
<p><button class="navigation" type="submit" formaction="<?= e($module->url('test_webhook')) ?>"><?= e(t('Send a test call')) ?></button> <span class="small-text"><?= e(t('Save the settings first – the test uses the saved values.')) ?></span></p>
<h2><?= e(t('Delivery log')) ?></h2>
<?php if ($deliveries === []): ?>
<p class="small-text"><?= e(t('No webhook has been sent yet.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Time')) ?></th><th scope="col"><?= e(t('Event')) ?></th><th scope="col"><?= e(t('Address')) ?></th><th scope="col"><?= e(t('Status')) ?></th></tr></thead>
<tbody>
<?php foreach ($deliveries as $w): ?>
<tr>
	<td class="number"><?= e(format_date($w['created'], true)) ?></td>
	<td><code><?= e($w['event']) ?></code></td>
	<td><?= e((string) parse_url($w['url'], PHP_URL_HOST)) ?></td>
	<td><?php if ($w['delivered'] !== null): ?><span class="badge badge-published"><?= e(t('delivered')) ?></span> <small>HTTP <?= (int) $w['status'] ?></small><?= (int) $w['attempts'] > 1 ? ' ' . e(t('on attempt %s', (int) $w['attempts'])) : '' ?>
<?php elseif ($w['next_attempt'] !== null): ?><span class="badge badge-draft"><?= e(t('waiting for the next attempt')) ?></span> <?= e(format_date($w['next_attempt'], true)) ?><?= $w['error'] !== '' ? '<br><small>' . e(t($w['error'])) . '</small>' : '' ?>
<?php else: ?><span class="badge badge-draft"><?= e(t('not delivered')) ?></span><br><small><?= e(t($w['error'])) ?></small>
<?php if ((int) $w['resendable'] === 1): ?> <button class="navigation" type="submit" name="id" value="<?= (int) $w['id'] ?>" formaction="<?= e($module->url('retry_webhook')) ?>"><?= e(t('Send again')) ?></button><?php endif ?>
<?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="small-text"><?= e(t('A call that fails is retried after 1, 5 and 30 minutes and after 2 and 12 hours. Records are deleted after 30 days; the content of a delivered call is not kept.')) ?></p>
