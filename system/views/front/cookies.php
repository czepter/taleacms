<?php
/**
 * Built-in cookie bar. Consent is stored in the cookie "talea_consent" for 6 months. A browser sending Global Privacy
 * Control counts as "only necessary" without asking (2.3).
 * Scripts waiting for consent have type="text/plain" data-consent="analytics", marketing codes are in <template data-consent="marketing">.
 * The appearance is deliberately neutral and independent of the layout; the layout can override it with the .cookies-* classes.
 *
 * @var string $text
 * @var string $policy
 * @var bool $analytics
 * @var bool $marketing
 * @var string $evidence  url for recording consent, empty = do not record
 */
?>
<div class="cookies-bar" id="cookies-bar" role="dialog" aria-modal="false" aria-labelledby="cookies-heading" hidden>
	<div class="cookies-content">
		<strong id="cookies-heading"><?= e(t('Privacy and cookies')) ?></strong>
		<p><?= nl2br(e($text)) ?><?php if ($policy !== ''): ?> <a href="<?= e($policy) ?>"><?= e(t('More information')) ?></a><?php endif ?></p>
		<div class="cookies-options" hidden>
			<label><input type="checkbox" checked disabled> <?= e(t('Necessary – the site does not work without them')) ?></label>
<?php if ($analytics): ?>
			<label><input type="checkbox" data-category="analytics"> <?= e(t('Analytics – anonymous traffic measurement')) ?></label>
<?php endif ?>
<?php if ($marketing): ?>
			<label><input type="checkbox" data-category="marketing"> <?= e(t('Marketing – campaign measurement and ad targeting')) ?></label>
<?php endif ?>
		</div>
		<div class="cookies-buttons">
			<button type="button" data-cookies="all"><?= e(t('Accept all')) ?></button>
			<button type="button" data-cookies="none"><?= e(t('Only necessary')) ?></button>
			<button type="button" data-cookies="settings" class="cookies-link"><?= e(t('Settings')) ?></button>
			<button type="button" data-cookies="save" hidden><?= e(t('Save selection')) ?></button>
		</div>
	</div>
</div>
<button type="button" class="cookies-reopen" id="cookies-reopen" hidden><?= e(t('Cookie settings')) ?></button>
<style>
.cookies-bar { position: fixed; z-index: 1000; left: 16px; right: 16px; bottom: 16px; max-width: 560px; margin: 0 auto 0 0; padding: 18px 20px; border: 1px solid #D0D5DD; border-radius: 10px; background: #FFFFFF; color: #14171F; box-shadow: 0 12px 40px rgb(0 0 0 / 0.18); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
.cookies-bar p { margin: 6px 0 12px; }
.cookies-bar a { color: inherit; }
.cookies-options { display: grid; gap: 6px; margin-bottom: 14px; }
.cookies-buttons { display: flex; flex-wrap: wrap; gap: 8px; }
.cookies-buttons button { padding: 9px 16px; border: 1px solid #14171F; border-radius: 6px; background: #14171F; color: #FFFFFF; font: inherit; font-weight: 600; cursor: pointer; }
.cookies-buttons button[data-cookies="none"], .cookies-buttons button[data-cookies="save"] { background: #FFFFFF; color: #14171F; }
.cookies-buttons .cookies-link { border-color: transparent; background: none; color: #14171F; text-decoration: underline; font-weight: 400; padding-left: 4px; padding-right: 4px; }
.cookies-reopen { position: fixed; z-index: 999; left: 12px; bottom: 12px; padding: 5px 10px; border: 1px solid #D0D5DD; border-radius: 6px; background: #FFFFFF; color: #475467; font: 12px system-ui, sans-serif; cursor: pointer; opacity: 0.85; }
</style>
<script>
(function () {
	var bar = document.getElementById('cookies-bar'), reopen = document.getElementById('cookies-reopen');
	var options = bar.querySelector('.cookies-options');
	function readConsent() { var m = document.cookie.match(/(?:^|; )talea_consent=([^;]*)/); return m ? decodeURIComponent(m[1]).split(',') : null; }
	function allow(category) {
		// Google consent mode first, so tags that start now already see the granted consent
		if (window.gtag) {
			gtag('consent', 'update', category === 'analytics' ? { analytics_storage: 'granted' } : { ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' });
		}
		// Google Tag Manager (2.6) starts with the first consent to analytics or marketing; its tags follow consent mode
		document.querySelectorAll('script[type="text/plain"][data-consent="' + category + '"], script[type="text/plain"][data-gtm]').forEach(function (s) {
			var n = document.createElement('script');
			if (s.src || s.getAttribute('src')) { n.src = s.getAttribute('src'); n.async = true; } else { n.text = s.text; }
			s.replaceWith(n);
		});
		document.querySelectorAll('template[data-consent="' + category + '"]').forEach(function (t) {
			var box = document.createElement('div');
			box.appendChild(t.content.cloneNode(true));
			box.querySelectorAll('script').forEach(function (s) { var n = document.createElement('script'); Array.prototype.forEach.call(s.attributes, function (a) { n.setAttribute(a.name, a.value); }); n.text = s.text; s.replaceWith(n); });
			t.replaceWith.apply(t, Array.prototype.slice.call(box.childNodes));
		});
		if (category === 'marketing') { origin(true); }
		if (Array.isArray(window.dataLayer)) { window.dataLayer.push({ event: 'talea_consent', consent: category === 'analytics' ? 'analytics' : 'marketing' }); }
	}
	// where a lead came from (2.3): with consent to marketing, the first page of this visit, its campaign and the site that
	// sent the visitor are remembered for this tab only (sessionStorage) and go with forms and newsletter sign-ups
	function origin(allowed) {
		var key = 'tl-origin', data = null;
		try {
			if (!allowed) { sessionStorage.removeItem(key); return; }
			data = JSON.parse(sessionStorage.getItem(key) || 'null');
			if (!data) {
				var q = new URLSearchParams(location.search), utm = new URLSearchParams(), referrer = '';
				['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function (k) { if (q.get(k)) { utm.set(k, q.get(k).slice(0, 80)); } });
				try { referrer = document.referrer ? new URL(document.referrer).hostname.replace(/^www\./, '') : ''; } catch (e) { referrer = ''; }
				if (referrer === location.hostname.replace(/^www\./, '')) { referrer = ''; }
				data = { landing: location.pathname, campaign: utm.toString(), referrer: referrer };
				sessionStorage.setItem(key, JSON.stringify(data));
			}
		} catch (e) { return; }
		var fill = function (name, value) { document.querySelectorAll('input[name="' + name + '"]').forEach(function (i) { i.value = value || ''; }); };
		fill('tl_landing', data.landing); fill('tl_campaign', data.campaign); fill('tl_referrer', data.referrer);
	}
	function save(category) {
		var had = readConsent() || [];
		document.cookie = 'talea_consent=' + encodeURIComponent(category.join(',') || 'none') + '; path=/; max-age=' + (180 * 86400) + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
		bar.hidden = true; reopen.hidden = false;
		var evidence = <?= json_encode($evidence) ?>;
		if (evidence) {
			var id = (document.cookie.match(/(?:^|; )talea_consent_id=([a-f0-9]{32})/) || [])[1];
			if (!id) { id = Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(16)), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); document.cookie = 'talea_consent_id=' + id + '; path=/; max-age=' + (180 * 86400) + '; SameSite=Lax'; }
			var data = new FormData(); data.append('id', id); data.append('category', category.join(',') || 'none');
			if (navigator.sendBeacon) { navigator.sendBeacon(evidence, data); } else { fetch(evidence, { method: 'POST', body: data }); }
		}
		// a withdrawn consent takes effect after the page reloads (a script that already ran cannot be stopped)
		if (had.some(function (k) { return k !== 'none' && category.indexOf(k) === -1; })) { location.reload(); return; }
		category.forEach(allow);
	}
	bar.addEventListener('click', function (e) {
		var action = e.target.getAttribute && e.target.getAttribute('data-cookies');
		if (action === 'all') { save(Array.prototype.map.call(options.querySelectorAll('[data-category]'), function (c) { return c.getAttribute('data-category'); })); }
		if (action === 'none') { save([]); }
		if (action === 'save') { save(Array.prototype.map.call(options.querySelectorAll('[data-category]:checked'), function (c) { return c.getAttribute('data-category'); })); }
		if (action === 'settings') { options.hidden = false; e.target.hidden = true; bar.querySelector('[data-cookies="save"]').hidden = false; }
	});
	reopen.addEventListener('click', function () {
		var has = readConsent() || [];
		options.querySelectorAll('[data-category]').forEach(function (c) { c.checked = has.indexOf(c.getAttribute('data-category')) !== -1; });
		options.hidden = false; bar.querySelector('[data-cookies="settings"]').hidden = true; bar.querySelector('[data-cookies="save"]').hidden = false;
		bar.hidden = false; reopen.hidden = true;
	});
	var consent = readConsent();
	if (consent === null || consent.indexOf('marketing') === -1) { origin(false); }
	// Global Privacy Control (2.3): a browser that asks not to be tracked counts as "only necessary" until the visitor chooses
	// otherwise – the bar does not ask, the settings button stays
	if (consent === null && navigator.globalPrivacyControl === true) { reopen.hidden = false; return; }
	if (consent === null) { bar.hidden = false; } else { reopen.hidden = false; consent.forEach(function (k) { if (k !== 'none') { allow(k); } }); }
})();
</script>
