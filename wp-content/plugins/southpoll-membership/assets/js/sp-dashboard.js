/* SouthPoll Membership — member dashboard behaviours (sidebar fallbacks, keyboard shortcuts). */
(function () {
	'use strict';
	document.addEventListener('DOMContentLoaded', function () {
		var btn = document.querySelector('.sp-dash__menu-btn');
		var root = document.querySelector('.sp-dash');
		if (btn && root && !window.Alpine) {
			btn.addEventListener('click', function () { root.classList.toggle('sp-dash--nav-open'); });
		}
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && root) root.classList.remove('sp-dash--nav-open');
		});
		launchPopup();
	});

	/* S7: soft-launch welcome popup on the dashboard home — once per account per version (dismissal stored server-side). */
	function launchPopup() {
		var el = document.getElementById('sp-launch-popup');
		var SP = window.SP;
		if (!el || !SP || !SP.modal) return;
		var cfg;
		try { cfg = JSON.parse(el.textContent || '{}'); } catch (e) { return; }
		if (!cfg || !cfg.version) return;
		var I18N = (window.SP_CONFIG && window.SP_CONFIG.i18n) || {};
		var body = document.createElement('div');
		body.className = 'sp-launch-popup';
		String(cfg.text || '').split(/\r?\n/).forEach(function (line) {
			var p = document.createElement('p');
			p.textContent = line;
			if (!line.trim()) p.className = 'sp-launch-popup__gap';
			body.appendChild(p);
		});
		var footer = '<button type="button" class="sp-btn sp-btn--primary" data-sp-popup="ok">' + SP.escape(I18N.gotIt || 'Got it') + '</button>' +
			(cfg.feedback ? '<button type="button" class="sp-btn sp-btn--outline" data-sp-popup="feedback">' + SP.escape(I18N.sendFeedback || 'Send feedback') + '</button>' : '');
		var dismissed = false;
		var dismiss = function () {
			if (dismissed) return;
			dismissed = true;
			SP.api('account/dismiss', { method: 'POST', body: { key: 'launch_popup', value: cfg.version } }).catch(function () { /* shown again next visit; fine */ });
		};
		var modal = SP.modal({ title: cfg.title || '', node: body, footer: footer, size: 'sm', onClose: dismiss });
		modal.el.querySelectorAll('[data-sp-popup]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var what = btn.getAttribute('data-sp-popup');
				modal.close();
				if (what === 'feedback' && SP.feedback && SP.feedback.open) SP.feedback.open();
			});
		});
	}
})();
