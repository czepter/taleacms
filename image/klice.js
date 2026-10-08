// Kaleta - passkeys (WebAuthn): registration in "Můj účet" (My account) and the second sign-in step.
// A form with the data-klice attribute carries the URL it posts to; the server creates the challenge and verifies it (Core\Passkey).
(function () {
	'use strict';

	function toBytes(text) {
		var b64 = text.replace(/-/g, '+').replace(/_/g, '/');
		while (b64.length % 4) { b64 += '='; }
		var chars = atob(b64), field = new Uint8Array(chars.length);
		for (var i = 0; i < chars.length; i++) { field[i] = chars.charCodeAt(i); }
		return field.buffer;
	}

	function toText(bytes) {
		var field = new Uint8Array(bytes), chars = '';
		for (var i = 0; i < field.length; i++) { chars += String.fromCharCode(field[i]); }
		return btoa(chars).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	document.querySelectorAll('form[data-klice]').forEach(function (formEl) {
		var address = formEl.getAttribute('data-klice');
		var error = formEl.querySelector('[data-klic-chyba]');
		var button = formEl.querySelector('[data-klic-pridat], [data-klic-prihlasit]');
		if (!button) { return; }

		if (!window.PublicKeyCredential || !navigator.credentials || !window.isSecureContext) {
			button.disabled = true;
			var unsupported = formEl.querySelector('[data-klic-nepodporuje]');
			if (unsupported) { unsupported.hidden = false; }
			return;
		}

		function showError(text) {
			if (error) { error.textContent = text; error.hidden = !text; }
			button.disabled = false;
		}

		function deliver(field) {
			var data = new FormData();
			data.append('_csrf', formEl.querySelector('input[name="_csrf"]').value);
			Object.keys(field).forEach(function (k) { data.append(k, field[k]); });
			return fetch(address, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
				.then(function (r) { return r.json(); })
				.then(function (j) { if (j && j.chyba) { throw new Error(j.chyba); } return j; });
		}

		button.addEventListener('click', function () {
			var register = button.hasAttribute('data-klic-pridat');
			button.disabled = true;
			showError('');
			button.disabled = true;

			// adding a passkey needs the current password (3.3.3): the server issues the challenge only with it
			var password = formEl.querySelector('[data-klic-heslo]');
			deliver(register ? { co: 'klic_moznosti', soucasne: password ? password.value : '' } : { krok: 'klic_moznosti' }).then(function (m) {
				m.challenge = toBytes(m.challenge);
				if (register) {
					m.user.id = toBytes(m.user.id);
					(m.excludeCredentials || []).forEach(function (c) { c.id = toBytes(c.id); });
					return navigator.credentials.create({ publicKey: m });
				}
				(m.allowCredentials || []).forEach(function (c) { c.id = toBytes(c.id); });
				return navigator.credentials.get({ publicKey: m });
			}).then(function (k) {
				var o = k.response, response = { id: toText(k.rawId), clientDataJSON: toText(o.clientDataJSON) };
				if (register) {
					if (!o.getPublicKey || !o.getAuthenticatorData) { throw new Error(formEl.querySelector('[data-klic-nepodporuje]').textContent); }
					response.authenticatorData = toText(o.getAuthenticatorData());
					response.publicKey = toText(o.getPublicKey());
					response.publicKeyAlgorithm = o.getPublicKeyAlgorithm();
					return deliver({ co: 'klic_uloz', odpoved: JSON.stringify(response), nazev: formEl.querySelector('[name="nazev"]').value });
				}
				response.authenticatorData = toText(o.authenticatorData);
				response.signature = toText(o.signature);
				return deliver({ krok: 'klic', odpoved: JSON.stringify(response) });
			}).then(function (j) {
				window.location.href = (j && j.kam) || window.location.href.split('#')[0];
			}).catch(function (e) {
				// the user cancelling the dialog is not an error worth reporting
				showError(e && e.name === 'NotAllowedError' ? '' : (e && e.message) || '');
			});
		});
	});
})();
