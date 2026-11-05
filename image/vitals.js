/* Talea - real-user speed (Core Web Vitals) for the site's own statistics. No libraries, no cookies, no identifiers:
 * LCP, CLS and INP of this page view go once to POST /vitals (navigator.sendBeacon) when the visitor leaves or hides the page.
 * Front\Seo::head() loads the script only when the built-in statistics are on; the endpoint is in data-vitals. Core\WebVitals
 * aggregates the values per page and day. Browsers without PerformanceObserver (or without a metric) simply send less. */
(function () {
	'use strict';

	var script = document.currentScript;

	/* The visit (Front\Stats::view): the server only renders the page; the visit is counted when this browser confirms the page was
	   shown – one beacon to POST /_view (no cookies, no identifiers) once the page is visible, not while it is prerendered or in a
	   background tab. The endpoint, the path and the signature are in data-view* of this tag (only where the statistics are on, for
	   anonymous visitors, not in previews). Without JavaScript nothing is counted. */
	var viewEndpoint = script && script.getAttribute('data-view');
	if (viewEndpoint && navigator.sendBeacon && !navigator.webdriver) {
		var counted = false;
		var confirmView = function () {
			if (counted || document.prerendering || document.visibilityState !== 'visible') { return; }
			counted = true;
			var beacon = new URLSearchParams();
			beacon.set('path', script.getAttribute('data-view-path') || '');
			beacon.set('news', script.getAttribute('data-view-news') || '');
			beacon.set('sig', script.getAttribute('data-view-sig') || '');
			beacon.set('ref', document.referrer);
			beacon.set('query', location.search);
			try { navigator.sendBeacon(viewEndpoint, beacon); } catch (e) { /* no counter */ }
		};
		document.addEventListener('visibilitychange', confirmView);
		document.addEventListener('prerenderingchange', confirmView);
		confirmView();
	}

	var endpoint = script && script.getAttribute('data-vitals');
	if (!endpoint || typeof PerformanceObserver !== 'function' || !navigator.sendBeacon || navigator.webdriver
		|| /bot|crawl|spider|slurp|preview|monitor|lighthouse|headless/i.test(navigator.userAgent)) { return; }

	var lcp = null, cls = 0, sent = false;
	var sessionValue = 0, sessionStart = 0, sessionLast = 0;
	var interactions = {};
	// a page loaded in a background tab paints when the visitor looks at it: no LCP from paints after the page was first
	// hidden (a prerendered page is hidden too, but it counts from its activation, see send())
	var firstHidden = document.visibilityState === 'hidden' && !document.prerendering ? 0 : Infinity;
	document.addEventListener('visibilitychange', function (e) {
		if (document.visibilityState === 'hidden') { firstHidden = Math.min(firstHidden, e.timeStamp); }
	}, true);

	function observe(type, each, options) {
		try {
			var o = new PerformanceObserver(function (list) { list.getEntries().forEach(each); });
			o.observe(Object.assign({ type: type, buffered: true }, options || {}));
		} catch (e) { /* the browser does not know this entry type */ }
	}

	// LCP: the last candidate before the first interaction; renderTime is missing for cross-origin images without Timing-Allow-Origin
	observe('largest-contentful-paint', function (e) {
		if (e.startTime < firstHidden) { lcp = e.renderTime || e.loadTime || e.startTime; }
	});

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
		// a prerendered page (the browser loaded it ahead) counts from the moment the visitor opened it
		var navigation = performance.getEntriesByType('navigation')[0];
		body.set('lcp', String(Math.round(Math.max(0, lcp - (navigation && navigation.activationStart || 0)))));
		body.set('cls', cls.toFixed(4));
		var responsiveness = inp();
		if (responsiveness !== null) { body.set('inp', String(Math.round(responsiveness))); }
		navigator.sendBeacon(endpoint, body);
	}

	document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') { send(); } });
	window.addEventListener('pagehide', send);
})();
