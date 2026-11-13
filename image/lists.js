/* Talea – collection lists on the site (Builder\Elements\CollectionList): filters and pages without reloading the page (2.10). The links
 * work without JavaScript too (they carry ?f-<id>= / ?s-<id>=); here only the list is fetched and swapped, the address changes, Back works.
 * Front\Seo::head() adds this file; Front\PageFrame keeps it only on a page with a list (data-collection). */
(function () {
	'use strict';

	/* #331: the list is only ever fetched from this very page – the same origin and the same path, only the query differs (that is
	   all a filter or page link changes). Any other address is an ordinary link: the browser follows it and nothing from it is put
	   into this page. The origin alone would not do – an uploaded file lives on it too. */
	function ownPage(url) {
		try {
			var u = new URL(url, location.href);
			return u.origin === location.origin && u.pathname.replace(/\/+$/, '') === location.pathname.replace(/\/+$/, '');
		} catch (e) { return false; }
	}

	function swapList(wrapper, url, push) {
		if (!ownPage(url)) { location.href = url; return; }
		var id = wrapper.getAttribute('data-collection');
		wrapper.setAttribute('aria-busy', 'true');
		fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'talea-list' } }).then(function (r) {
			// a redirect elsewhere, or an answer that is not a page: nothing of it is used (the catch loads the page normally)
			if (!r.ok || !ownPage(r.url) || !/^text\/html\b/i.test(r.headers.get('Content-Type') || '')) { throw new Error('HTTP ' + r.status); }
			return r.text();
		}).then(function (html) {
			var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-collection="' + CSS.escape(id) + '"]');
			if (!fresh) { throw new Error('no list'); }
			wrapper.replaceWith(fresh);
			document.dispatchEvent(new CustomEvent('talea:list')); // new cards: the comparison marks its boxes again
			if (push) { history.pushState({ collection: id }, '', url); }
			var current = fresh.querySelector('.tl-collection-filters [aria-current], .tl-collection-pages [aria-current]');
			if (current) { current.focus({ preventScroll: true }); }
		}).catch(function () { location.href = url; }); // anything unexpected: the ordinary page load
	}

	document.addEventListener('click', function (e) {
		var link = e.target.closest && e.target.closest('[data-collection] .tl-collection-filters a, [data-collection] .tl-collection-pages a');
		if (!link || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0 || !window.fetch || !window.DOMParser) { return; }
		if (!ownPage(link.href)) { return; } // not a link of this list's page: left to the browser
		e.preventDefault();
		swapList(link.closest('[data-collection]'), link.href, true);
	});
	window.addEventListener('popstate', function (e) {
		var id = e.state && e.state.collection;
		var wrapper = id ? document.querySelector('[data-collection="' + CSS.escape(id) + '"]') : null;
		if (wrapper) { swapList(wrapper, location.href, false); }
	});
})();
