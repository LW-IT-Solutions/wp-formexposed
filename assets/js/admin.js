/**
 * formexposed - settings page (Settings -> formexposed).
 *
 * While the page shows a running check (#fe-wait), asks admin-ajax (action
 * formexposed_status) every 5 s and reloads the page once the result is in -
 * at most 60 times. Links with
 * data-formexposed-confirm ask before they delete. The values come from
 * Formexposed::admin_assets() (window.formexposedAdmin).
 */
(function (c) {
	document.addEventListener('click', function (e) {
		var a = e.target && e.target.closest ? e.target.closest('[data-formexposed-confirm]') : null;
		if (a && !window.confirm(a.getAttribute('data-formexposed-confirm'))) {
			e.preventDefault();
		}
	});
	if (!c || !document.getElementById('fe-wait')) {
		return;   // no check running on this page
	}
	var n = 0;
	function tick() {
		if (n++ > 60) {
			return;
		}
		var d = new FormData();
		d.append('action', 'formexposed_status');
		d.append('nonce', c.nonce);
		fetch(c.ajax, { method: 'POST', credentials: 'same-origin', body: d })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				var s = j && j.data ? j.data.status : '';
				if (s === 'queued' || s === 'running') {
					setTimeout(tick, 5000);
				} else {
					window.location.reload();
				}
			})
			.catch(function () { setTimeout(tick, 8000); });
	}
	setTimeout(tick, 5000);
})(window.formexposedAdmin);
