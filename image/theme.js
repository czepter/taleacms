/* Talea - light / dark mode of the admin: set before the page renders so it does not flicker.
 * A separate file because of the admin's Content-Security-Policy (no inline scripts). */
try { var t = localStorage.getItem('talea-theme'); if (t) { document.documentElement.setAttribute('data-theme', t); } } catch (e) {}

// the folded settings sidebar of the Website screen and the item editor is applied before the first paint too (admin.css reads it)
try { if (localStorage.getItem('talea.websiteSettings') === 'closed') { document.documentElement.setAttribute('data-settings-closed', ''); } } catch (e) {}
