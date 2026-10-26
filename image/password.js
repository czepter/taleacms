/* Talea - the eye button in a password field of the sign-in pages shows or hides what was typed (views/admin/password_toggle.php). */
document.addEventListener('click', function (e) {
	var button = e.target.closest('button[data-show-password]');
	var field = button && document.getElementById(button.getAttribute('data-show-password'));
	if (!field) { return; }
	var show = field.type === 'password';
	field.type = show ? 'text' : 'password';
	button.setAttribute('aria-pressed', show ? 'true' : 'false');
});
