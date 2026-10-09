/* Kaleta – the Print button of a printable page (the door sign, Core\HoursSign). The administration allows no inline handlers (CSP). */

(function () {
	'use strict';

	document.querySelectorAll('[data-print]').forEach(function (button) {
		button.addEventListener('click', function () { window.print(); });
	});
})();
