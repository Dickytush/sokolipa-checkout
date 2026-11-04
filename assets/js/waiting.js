/* Sokolipa Checkout: the waiting screen on the order-received page. Polls the status route and offers a resend. */
(function () {
	'use strict';

	var cfg = window.sokolipaWaiting;
	var root = document.getElementById('sokolipa-waiting');
	if (!cfg || !root) {
		return;
	}

	var title = document.getElementById('sokolipa-waiting-title');
	var text = document.getElementById('sokolipa-waiting-text');
	var form = document.getElementById('sokolipa-resend');
	var error = document.getElementById('sokolipa-resend-error');
	var started = Date.now();
	var timer = null;
	var stopped = false;
	var generation = 0;

	function withKey(base) {
		return base + (base.indexOf('?') === -1 ? '?' : '&') + 'key=' + encodeURIComponent(cfg.key) + '&_=' + Date.now();
	}

	function setState(state) {
		root.setAttribute('data-status', state);
	}

	function showResend(show) {
		if (form) {
			form.hidden = !show;
		}
	}

	function poll() {
		if (stopped) {
			return;
		}
		var url = withKey(cfg.restUrl + 'status/' + cfg.orderId);
		var mine = generation;
		fetch(url, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data || !data.status || mine !== generation) {
					return;
				}
				if (data.status === 'paid') {
					stopped = true;
					setState('paid');
					title.textContent = cfg.i18n.paid;
					text.textContent = data.receipt ? (cfg.i18n.paid + ' ' + data.receipt) : cfg.i18n.paid;
					showResend(false);
					window.setTimeout(function () { window.location.reload(); }, 1500);
					return;
				}
				if (data.status === 'failed') {
					setState('failed');
					title.textContent = data.reason || cfg.i18n.failed;
					showResend(!!data.canRetry);
					return;
				}
				setState('pending');
				if (title.textContent !== cfg.i18n.sent) {
					title.textContent = cfg.i18n.waiting;
				}
				if (Date.now() - started > 40000) {
					text.textContent = cfg.i18n.slow;
					showResend(true);
				}
			})
			.catch(function () { /* keep polling */ })
			.then(function () {
				if (!stopped) {
					timer = window.setTimeout(poll, 3000);
				}
			});
	}

	if (form) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			error.hidden = true;
			var phone = document.getElementById('sokolipa_resend_phone');
			var network = document.getElementById('sokolipa_resend_network');
			var button = form.querySelector('button');
			button.disabled = true;
			title.textContent = cfg.i18n.sending;
			fetch(cfg.restUrl + 'resend/' + cfg.orderId, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					key: cfg.key,
					phone: phone ? phone.value : '',
					network: network ? network.value : ''
				})
			})
				.then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
				.then(function (res) {
					button.disabled = false;
					if (!res.ok) {
						error.textContent = (res.body && res.body.error) || cfg.i18n.error;
						error.hidden = false;
						title.textContent = cfg.i18n.failed;
						return;
					}
					started = Date.now();
					generation += 1;
					setState('pending');
					title.textContent = cfg.i18n.sent;
					text.textContent = res.body.message || cfg.i18n.waiting;
					showResend(false);
					if (!timer) {
						poll();
					}
				})
				.catch(function () {
					button.disabled = false;
					error.textContent = cfg.i18n.error;
					error.hidden = false;
				});
		});
	}

	if (cfg.status !== 'failed') {
		poll();
	} else {
		showResend(true);
	}
})();
