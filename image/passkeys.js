// Talea - passkeys (WebAuthn): registration in "My account", sign-in on the first step and the second sign-in step.
// A form with the data-passkey attribute carries the URL it posts to; the server creates the challenge and verifies it (Core\Passkey).
(function () {
	'use strict';

	// the confirmation partial may sit on a page that already loads this script
	if (window.taleaPasskeys) { return; }
	window.taleaPasskeys = true;

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

	// A POST of the form's anti-forgery token and some fields; answers JSON, an {error} becomes an exception
	function post(form, url, field) {
		var data = new FormData();
		data.append('_csrf', form.querySelector('input[name="_csrf"]').value);
		Object.keys(field).forEach(function (k) { data.append(k, field[k]); });
		return fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
			.then(function (r) { return r.json(); })
			.then(function (j) { if (j && j.error) { throw new Error(j.error); } return j; });
	}

	var supported = !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);

	// "Confirm with your passkey" next to a password field (the partial views/admin/fresh_proof.php): the signed-in account proves itself with a
	// key instead of typing the password; the server remembers it for ten minutes. The form is not sent here: the person presses its own button.
	document.querySelectorAll('button[data-passkey-confirm]').forEach(function (button) {
		var form = button.form || button.closest('form'), box = button.parentNode;
		var done = box.querySelector('[data-passkey-confirmed]'), error = box.querySelector('[data-passkey-confirm-error]');
		if (!form || !supported) { button.hidden = true; return; }
		var address = button.getAttribute('data-passkey-confirm');

		function showError(text) {
			if (error) { error.textContent = text; error.hidden = !text; }
			button.disabled = false;
		}

		button.addEventListener('click', function () {
			button.disabled = true;
			showError('');
			post(form, address, { step: 'options' }).then(function (m) {
				m.challenge = toBytes(m.challenge);
				(m.allowCredentials || []).forEach(function (c) { c.id = toBytes(c.id); });
				return navigator.credentials.get({ publicKey: m });
			}).then(function (k) {
				var o = k.response;
				return post(form, address, { step: 'verify', answer: JSON.stringify({
					id: toText(k.rawId), clientDataJSON: toText(o.clientDataJSON), authenticatorData: toText(o.authenticatorData), signature: toText(o.signature)
				}) });
			}).then(function () {
				var flag = document.createElement('input');
				flag.type = 'hidden'; flag.name = 'passkey_confirmed'; flag.value = '1';
				form.appendChild(flag);
				button.hidden = true;
				if (done) { done.hidden = false; }
			}).catch(function (e) {
				showError(e && e.name === 'NotAllowedError' ? '' : (e && e.message) || '');
			});
		});
	});

	document.querySelectorAll('form[data-passkey]').forEach(function (formEl) {
		var address = formEl.getAttribute('data-passkey');
		var error = formEl.querySelector('[data-passkey-error]');
		var button = formEl.querySelector('[data-passkey-add], [data-passkey-signin]');
		if (!button) { return; }

		if (!supported) {
			// on the first sign-in step the passkey is only an extra: without support the form is not shown at all
			if (formEl.hasAttribute('data-passkey-optional')) { formEl.hidden = true; return; }
			button.disabled = true;
			var unsupported = formEl.querySelector('[data-passkey-unsupported]');
			if (unsupported) { unsupported.hidden = false; }
			return;
		}

		function showError(text) {
			if (error) { error.textContent = text; error.hidden = !text; }
			button.disabled = false;
		}

		function deliver(field) { return post(formEl, address, field); }

		button.addEventListener('click', function () {
			var register = button.hasAttribute('data-passkey-add');
			button.disabled = true;
			showError('');
			button.disabled = true;

			// adding a passkey needs the current password (3.3.3), or for a passkey-only account a backup code: the server issues the challenge only with it
			var password = formEl.querySelector('[data-passkey-password]'), backup = formEl.querySelector('[data-passkey-backup]');
			deliver(register ? { op: 'passkey_options', current_password: password ? password.value : '', backup_code: backup ? backup.value : '' } : { step: 'passkey_options' }).then(function (m) {
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
				// the "stay signed in" tick of the password form counts for the passkey too
				var remember = document.querySelector('input[name="remember"]');
				return deliver({ step: 'key', answer: JSON.stringify(response), remember: remember && remember.checked ? '1' : '' });
			}).then(function (j) {
				window.location.href = (j && j.redirect) || window.location.href.split('#')[0];
			}).catch(function (e) {
				// the user cancelling the dialog is not an error worth reporting
				showError(e && e.name === 'NotAllowedError' ? '' : (e && e.message) || '');
			});
		});
	});
})();
