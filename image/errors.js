/* Talea - browser errors for the site's error tracking (Core\ErrorReport, docs/specs/error-tracking.md). No libraries, no cookies
 * of its own, no identifiers: an error of the site's own scripts (image/…) goes once to the endpoint in data-errors
 * (POST /js-error, navigator.sendBeacon), which passes it on to Sentry or GlitchTip. Loaded only when the operator set a DSN:
 * in the admin and the builder (data-errors-area) always, on public pages only with TALEA_ERROR_BROWSER=1 (Front\Seo).
 * Errors of browser extensions and other sites' scripts are not ours and stay out; at most five reports per page view. */
(function () {
	'use strict';

	var script = document.currentScript;
	var endpoint = script && script.getAttribute('data-errors');
	if (!endpoint || !navigator.sendBeacon || navigator.webdriver) { return; }
	var area = script.getAttribute('data-errors-area') || 'site';
	// the folder of this script (…/image/): the site's own scripts live there, the builder's modules too
	var own = new URL(script.src, location.href);
	var prefix = own.origin + own.pathname.replace(/[^/]*$/, '');
	var sent = 0;
	var seen = {};

	function report(message, stack, source, line, column) {
		message = String(message || '');
		stack = String(stack || '');
		source = String(source || '');
		if (sent >= 5 || message === '' || (source.indexOf(prefix) !== 0 && stack.indexOf(prefix) === -1)) { return; }
		var key = message + '|' + source + '|' + line;
		if (seen[key]) { return; }
		seen[key] = true;
		sent++;
		navigator.sendBeacon(endpoint, JSON.stringify({
			message: message.slice(0, 1000), stack: stack.slice(0, 4000), source: source.slice(0, 300),
			line: line || 0, column: column || 0, area: area, path: location.pathname
		}));
	}

	window.addEventListener('error', function (event) {
		// a failed image or style also raises "error", but not on window and without a message
		if (!event.message && !event.error) { return; }
		report(event.error && event.error.name ? event.error.name + ': ' + event.error.message : event.message, event.error && event.error.stack, event.filename, event.lineno, event.colno);
	});
	window.addEventListener('unhandledrejection', function (event) {
		var reason = event.reason;
		var isError = reason instanceof Error;
		report(isError ? reason.name + ': ' + reason.message : 'Unhandled rejection: ' + String(reason), isError ? reason.stack : '', '', 0, 0);
	});
})();
