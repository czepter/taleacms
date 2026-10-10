// Talea - passkeys (WebAuthn): registration in "My account" and the second sign-in step.
// A form with the data-passkey attribute carries the URL it posts to; the server creates the challenge and verifies it (Core\Passkey).
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

	document.querySelectorAll('form[data-passkey]').forEach(function (formEl) {
		var address = formEl.getAttribute('data-passkey');
		var error = formEl.querySelector('[data-passkey-error]');
		var button = formEl.querySelector('[data-passkey-add], [data-passkey-signin]');
		if (!button) { return; }

		if (!window.PublicKeyCredential || !navigator.credentials || !window.isSecureContext) {
			button.disabled = true;
			var unsupported = formEl.querySelector('[data-passkey-unsupported]');
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
				.then(function (j) { if (j && j.error) { throw new Error(j.error); } return j; });
		}

		button.addEventListener('click', function () {
			var register = button.hasAttribute('data-passkey-add');
			button.disabled = true;
			showError('');
			button.disabled = true;

			// adding a passkey needs the current password (3.3.3): the server issues the challenge only with it
			var password = formEl.querySelector('[data-passkey-password]');
			deliver(register ? { op: 'passkey_options', current_password: password ? password.value : '' } : { step: 'passkey_options' }).then(function (m) {
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
					if (!o.getPublicKey || !o.getAuthenticatorData) { throw new Error(formEl.querySelector('[data-passkey-unsupported]').textContent); }
					response.authenticatorData = toText(o.getAuthenticatorData());
					response.publicKey = toText(o.getPublicKey());
					response.publicKeyAlgorithm = o.getPublicKeyAlgorithm();
					return deliver({ op: 'passkey_save', answer: JSON.stringify(response), name: formEl.querySelector('[name="name"]').value });
				}
				response.authenticatorData = toText(o.authenticatorData);
				response.signature = toText(o.signature);
				return deliver({ step: 'key', answer: JSON.stringify(response) });
			}).then(function (j) {
				window.location.href = (j && j.redirect) || window.location.href.split('#')[0];
			}).catch(function (e) {
				// the user cancelling the dialog is not an error worth reporting
				showError(e && e.name === 'NotAllowedError' ? '' : (e && e.message) || '');
			});
		});
	});
})();
