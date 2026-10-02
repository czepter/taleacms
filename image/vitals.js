/* Kaleta - real-user speed (Core Web Vitals) for the site's own statistics. No libraries, no cookies, no identifiers:
 * LCP, CLS and INP of this page view go once to POST /vitals (navigator.sendBeacon) when the visitor leaves or hides the page.
 * Front\Seo::head() loads the script only when the built-in statistics are on; the endpoint is in data-vitals. Core\WebVitals
 * aggregates the values per page and day. Browsers without PerformanceObserver (or without a metric) simply send less. */
(function () {
	'use strict';

	var script = document.currentScript;
	var endpoint = script && script.getAttribute('data-vitals');
	if (!endpoint || typeof PerformanceObserver !== 'function' || !navigator.sendBeacon || navigator.webdriver
		|| /bot|crawl|spider|slurp|preview|monitor|lighthouse|headless/i.test(navigator.userAgent)) { return; }

	var lcp = null, cls = 0, sent = false;
	var sessionValue = 0, sessionStart = 0, sessionLast = 0;
	var interactions = {};

	function observe(type, each, options) {
		try {
			var o = new PerformanceObserver(function (list) { list.getEntries().forEach(each); });
			o.observe(Object.assign({ type: type, buffered: true }, options || {}));
		} catch (e) { /* the browser does not know this entry type */ }
	}

	// LCP: the last candidate before the first interaction; renderTime is missing for cross-origin images without Timing-Allow-Origin
	observe('largest-contentful-paint', function (e) { lcp = e.renderTime || e.loadTime || e.startTime; });

	// CLS: the largest session window of shifts (gap under 1 s, window under 5 s), shifts after input do not count
	observe('layout-shift', function (e) {
		if (e.hadRecentInput) { return; }
		if (sessionValue && e.startTime - sessionLast < 1000 && e.startTime - sessionStart < 5000) {
			sessionValue += e.value;
		} else {
			sessionValue = e.value;
			sessionStart = e.startTime;
		}
		sessionLast = e.startTime;
		if (sessionValue > cls) { cls = sessionValue; }
	});

	// INP: the longest duration of every interaction (events sharing an interactionId); the worst one is skipped per 50 interactions
	observe('event', function (e) {
		if (!e.interactionId) { return; }
		interactions[e.interactionId] = Math.max(interactions[e.interactionId] || 0, e.duration);
	}, { durationThreshold: 40 });

	function inp() {
		var durations = Object.keys(interactions).map(function (k) { return interactions[k]; }).sort(function (a, b) { return b - a; });
		return durations.length ? durations[Math.min(durations.length - 1, Math.floor(durations.length / 50))] : null;
	}

	function send() {
		if (sent || lcp === null) { return; } // a page hidden before it painted has nothing to say
		sent = true;
		var body = new URLSearchParams();
		body.set('path', location.pathname);
		body.set('lcp', String(Math.round(lcp)));
		body.set('cls', cls.toFixed(4));
		var responsiveness = inp();
		if (responsiveness !== null) { body.set('inp', String(Math.round(responsiveness))); }
		navigator.sendBeacon(endpoint, body);
	}

	document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { send(); } });
	window.addEventListener('pagehide', send);
})();
