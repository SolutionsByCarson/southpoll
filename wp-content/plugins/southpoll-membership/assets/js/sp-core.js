/* SouthPoll Membership — core UI kit (toast, modal, share, count-up, API client, Alpine components).
 * Loaded (deferred) before Alpine so components registered on `alpine:init` are available. */
(function () {
	'use strict';

	const CONFIG = window.SP_CONFIG || {};
	const I18N = CONFIG.i18n || {};
	const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	const SP = window.SP = window.SP || {};
	SP.config = CONFIG;

	/* ------------------------------------------------------------ helpers */
	SP.debounce = function (fn, wait) {
		let t;
		return function () {
			const args = arguments, ctx = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(ctx, args); }, wait || 250);
		};
	};

	SP.escape = function (str) {
		return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	};

	SP.formatMoney = function (cents, currency) {
		const n = (Number(cents) || 0) / 100;
		try { return new Intl.NumberFormat('en-US', { style: 'currency', currency: currency || 'USD', maximumFractionDigits: n % 1 === 0 ? 0 : 2 }).format(n); }
		catch (e) { return '$' + n.toFixed(2); }
	};

	SP.formatNumber = function (n, decimals) {
		return new Intl.NumberFormat('en-US', { minimumFractionDigits: decimals || 0, maximumFractionDigits: decimals || 0 }).format(Number(n) || 0);
	};

	SP.sprintf = function (str) {
		const args = Array.prototype.slice.call(arguments, 1);
		let i = 0;
		return String(str).replace(/%(\d+\$)?s/g, function (m, pos) {
			const idx = pos ? parseInt(pos, 10) - 1 : i++;
			return args[idx] == null ? '' : args[idx];
		});
	};

	/* --------------------------------------------------------------- API */
	class SPError extends Error {
		constructor(message, status, data) { super(message); this.status = status; this.data = data; }
	}
	SP.Error = SPError;

	SP.api = async function (path, options) {
		options = options || {};
		const method = (options.method || 'GET').toUpperCase();
		let url = path.indexOf('http') === 0 ? path : (CONFIG.restUrl || '/wp-json/sp/v1/') + path.replace(/^\//, '');
		if (options.query) {
			const qs = new URLSearchParams();
			Object.keys(options.query).forEach(function (k) {
				const v = options.query[k];
				if (v === undefined || v === null || v === '') return;
				if (Array.isArray(v)) v.forEach(function (x) { qs.append(k + '[]', x); }); else qs.append(k, v);
			});
			const s = qs.toString();
			if (s) url += (url.indexOf('?') === -1 ? '?' : '&') + s;
		}
		const headers = { 'Accept': 'application/json' };
		if (CONFIG.restNonce) headers['X-WP-Nonce'] = CONFIG.restNonce;
		const init = { method: method, headers: headers, credentials: 'same-origin' };
		if (options.body !== undefined) {
			if (options.body instanceof FormData) { init.body = options.body; }
			else { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(options.body); }
		}
		const res = await fetch(url, init);
		let data = null;
		try { data = await res.json(); } catch (e) { data = null; }
		if (!res.ok) {
			const msg = (data && (data.message || (data.error && data.error.message))) || I18N.error || 'Request failed';
			throw new SPError(msg, res.status, data);
		}
		return data;
	};

	/* ------------------------------------------------------------- toast */
	function toastContainer() {
		let el = document.getElementById('sp-toasts');
		if (!el) {
			el = document.createElement('div');
			el.id = 'sp-toasts';
			el.className = 'sp-toasts sp-scope';
			el.setAttribute('aria-live', 'polite');
			el.setAttribute('aria-atomic', 'false');
			document.body.appendChild(el);
		}
		return el;
	}

	SP.toast = function (opts) {
		if (typeof opts === 'string') opts = { message: opts };
		opts = opts || {};
		const type = ['success', 'info', 'warning', 'error'].indexOf(opts.type) !== -1 ? opts.type : 'info';
		const el = document.createElement('div');
		el.className = 'sp-toast sp-toast--' + type;
		el.setAttribute('role', type === 'error' ? 'alert' : 'status');
		let html = '';
		if (opts.title) html += '<div class="sp-toast__title">' + SP.escape(opts.title) + '</div>';
		html += '<div class="sp-toast__message">' + (opts.html ? opts.html : SP.escape(opts.message || '')) + '</div>';
		if (opts.actions && opts.actions.length) {
			html += '<div class="sp-toast__actions">' + opts.actions.map(function (a) {
				return '<a href="' + SP.escape(a.href || '#') + '"' + (a.onClick ? ' data-action="1"' : '') + '>' + SP.escape(a.label) + '</a>';
			}).join('') + '</div>';
		}
		html += '<button type="button" class="sp-toast__close" aria-label="' + SP.escape(I18N.close || 'Close') + '">&times;</button>';
		el.innerHTML = html;
		const container = toastContainer();
		container.appendChild(el);
		requestAnimationFrame(function () { el.classList.add('is-visible'); });
		const close = function () {
			el.classList.remove('is-visible');
			setTimeout(function () { el.remove(); }, reducedMotion ? 0 : 280);
		};
		el.querySelector('.sp-toast__close').addEventListener('click', close);
		if (opts.actions) {
			el.querySelectorAll('.sp-toast__actions a[data-action]').forEach(function (a, i) {
				const act = opts.actions.filter(function (x) { return x.onClick; })[i];
				a.addEventListener('click', function (e) { e.preventDefault(); act.onClick(); close(); });
			});
		}
		const timeout = opts.timeout === undefined ? (type === 'error' ? 20000 : 6000) : opts.timeout;
		if (timeout > 0) {
			let timer = setTimeout(close, timeout);
			el.addEventListener('mouseenter', function () { clearTimeout(timer); });
			el.addEventListener('mouseleave', function () { timer = setTimeout(close, 2500); });
		}
		return { el: el, close: close };
	};
	['success', 'info', 'warning', 'error'].forEach(function (type) {
		SP.toast[type] = function (message, opts) { return SP.toast(Object.assign({}, opts || {}, { type: type, message: message })); };
	});

	/* ------------------------------------------------------------- modal */
	let openModals = [];
	SP.modal = function (opts) {
		opts = opts || {};
		const backdrop = document.createElement('div');
		backdrop.className = 'sp-modal-backdrop sp-scope';
		const size = opts.size ? ' sp-modal--' + opts.size : '';
		backdrop.innerHTML =
			'<div class="sp-modal' + size + '" role="dialog" aria-modal="true" tabindex="-1"' + (opts.title ? ' aria-labelledby="sp-modal-title-' + openModals.length + '"' : '') + '>' +
			(opts.title !== false ? '<div class="sp-modal__header"><h2 class="sp-modal__title" id="sp-modal-title-' + openModals.length + '">' + SP.escape(opts.title || '') + '</h2><button type="button" class="sp-modal__close" aria-label="' + SP.escape(I18N.close || 'Close') + '">&times;</button></div>' : '') +
			'<div class="sp-modal__body"></div>' +
			(opts.footer ? '<div class="sp-modal__footer">' + opts.footer + '</div>' : '') +
			'</div>';
		const body = backdrop.querySelector('.sp-modal__body');
		if (opts.node) body.appendChild(opts.node); else body.innerHTML = opts.html || '';
		document.body.appendChild(backdrop);
		const dialog = backdrop.querySelector('.sp-modal');
		const previouslyFocused = document.activeElement;
		const api = {
			el: backdrop,
			body: body,
			close: function (result) {
				backdrop.classList.remove('is-visible');
				document.removeEventListener('keydown', onKey);
				setTimeout(function () { backdrop.remove(); }, reducedMotion ? 0 : 180);
				openModals = openModals.filter(function (m) { return m !== api; });
				if (!openModals.length) document.body.style.overflow = '';
				if (previouslyFocused && previouslyFocused.focus) previouslyFocused.focus();
				if (opts.onClose) opts.onClose(result);
			}
		};
		function onKey(e) {
			if (e.key === 'Escape') { api.close(); return; }
			if (e.key === 'Tab') {
				const f = dialog.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])');
				if (!f.length) return;
				const first = f[0], last = f[f.length - 1];
				if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
				else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
			}
		}
		document.addEventListener('keydown', onKey);
		backdrop.addEventListener('click', function (e) { if (e.target === backdrop && opts.dismissible !== false) api.close(); });
		backdrop.querySelectorAll('.sp-modal__close, [data-modal-close]').forEach(function (b) { b.addEventListener('click', function () { api.close(b.getAttribute('data-modal-close') || undefined); }); });
		openModals.push(api);
		document.body.style.overflow = 'hidden';
		requestAnimationFrame(function () {
			backdrop.classList.add('is-visible');
			const first = dialog.querySelector('input,select,textarea,button:not(.sp-modal__close)') || dialog;
			first.focus();
		});
		return api;
	};

	SP.confirm = function (opts) {
		opts = opts || {};
		return new Promise(function (resolve) {
			let settled = false;
			const m = SP.modal({
				title: opts.title || I18N.confirm || 'Confirm',
				size: 'sm',
				html: '<p>' + SP.escape(opts.message || '') + '</p>',
				footer: '<button type="button" class="sp-btn sp-btn--ghost" data-modal-close="cancel">' + SP.escape(opts.cancelLabel || I18N.cancel || 'Cancel') + '</button><button type="button" class="sp-btn ' + (opts.danger ? 'sp-btn--danger' : 'sp-btn--primary') + '" data-modal-close="ok">' + SP.escape(opts.confirmLabel || I18N.confirm || 'Confirm') + '</button>',
				onClose: function (result) { if (!settled) { settled = true; resolve(result === 'ok'); } }
			});
			return m;
		});
	};

	/* ------------------------------------------------------------- share */
	SP.share = function (opts) {
		opts = opts || {};
		const url = opts.url || location.href;
		const title = opts.title || document.title;
		const text = opts.text ? opts.text + ' ' : '';
		const mail = 'mailto:?subject=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(text + url);
		const sms = 'sms:?&body=' + encodeURIComponent(text + url);
		const fb = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url);
		const x = 'https://twitter.com/intent/tweet?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title);
		const icon = {
			email: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>',
			sms: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h16v11H9l-5 4z"/></svg>',
			fb: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M14 8h3V4h-3c-2.8 0-5 2.2-5 5v2H6v4h3v7h4v-7h3l1-4h-4V9c0-.6.4-1 1-1z"/></svg>',
			x: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.5 3h3l-7.3 8.3L21.7 21h-6.3l-4.9-6.4L4.8 21h-3l7.8-8.9L1 3h6.4l4.4 5.9zm-1.1 16.2h1.7L6.9 4.7H5.1z"/></svg>',
			copy: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a1 1 0 0 1 1-1h10"/></svg>'
		};
		const html =
			'<div class="sp-share">' +
			'<a class="sp-share__btn" href="' + SP.escape(mail) + '">' + icon.email + '<span>' + SP.escape(I18N.shareEmail || 'Email') + '</span></a>' +
			'<a class="sp-share__btn" href="' + SP.escape(sms) + '">' + icon.sms + '<span>' + SP.escape(I18N.shareText || 'Text') + '</span></a>' +
			'<a class="sp-share__btn" href="' + SP.escape(fb) + '" target="_blank" rel="noopener">' + icon.fb + '<span>' + SP.escape(I18N.shareFacebook || 'Facebook') + '</span></a>' +
			'<a class="sp-share__btn" href="' + SP.escape(x) + '" target="_blank" rel="noopener">' + icon.x + '<span>' + SP.escape(I18N.shareX || 'X') + '</span></a>' +
			'<button type="button" class="sp-share__btn" data-share-copy>' + icon.copy + '<span>' + SP.escape(I18N.shareCopy || 'Copy link') + '</span></button>' +
			'</div>' +
			'<div class="sp-share__url"><input class="sp-input" type="text" readonly value="' + SP.escape(url) + '"><button type="button" class="sp-btn sp-btn--outline sp-btn--sm" data-share-copy>' + SP.escape(I18N.shareCopy || 'Copy') + '</button></div>';
		const m = SP.modal({ title: opts.modalTitle || I18N.share || 'Share', html: html });
		m.el.querySelectorAll('[data-share-copy]').forEach(function (b) {
			b.addEventListener('click', async function () {
				try { await navigator.clipboard.writeText(url); SP.toast.success(I18N.copied || 'Link copied.'); }
				catch (e) { const inp = m.el.querySelector('.sp-share__url input'); inp.select(); SP.toast.warning(I18N.copyFailed || 'Copy failed.'); }
			});
		});
		if (navigator.share && opts.native !== false) {
			const nat = document.createElement('button');
			nat.type = 'button'; nat.className = 'sp-btn sp-btn--secondary sp-btn--block'; nat.style.marginTop = '12px';
			nat.textContent = 'More options…';
			nat.addEventListener('click', function () { navigator.share({ title: title, text: opts.text || '', url: url }).catch(function () {}); });
			m.body.appendChild(nat);
		}
		return m;
	};

	/* ---------------------------------------------------------- count-up */
	SP.countUp = function (el, opts) {
		opts = opts || {};
		const to = Number(opts.to !== undefined ? opts.to : el.getAttribute('data-countup')) || 0;
		const decimals = opts.decimals !== undefined ? opts.decimals : parseInt(el.getAttribute('data-countup-decimals') || '0', 10);
		const prefix = opts.prefix !== undefined ? opts.prefix : (el.getAttribute('data-countup-prefix') || '');
		const suffix = opts.suffix !== undefined ? opts.suffix : (el.getAttribute('data-countup-suffix') || '');
		const duration = opts.duration || 1100;
		const render = function (v) { el.textContent = prefix + SP.formatNumber(v, decimals) + suffix; };
		if (reducedMotion || to === 0) { render(to); return; }
		const start = performance.now();
		const from = 0;
		const step = function (now) {
			const p = Math.min(1, (now - start) / duration);
			const eased = 1 - Math.pow(1 - p, 3);
			render(from + (to - from) * eased);
			if (p < 1) requestAnimationFrame(step); else render(to);
		};
		requestAnimationFrame(step);
	};
	SP.initCountUps = function (root) {
		const els = (root || document).querySelectorAll('[data-countup]:not([data-countup-done])');
		if (!els.length) return;
		if ('IntersectionObserver' in window) {
			const io = new IntersectionObserver(function (entries) {
				entries.forEach(function (en) { if (en.isIntersecting) { en.target.setAttribute('data-countup-done', '1'); SP.countUp(en.target); io.unobserve(en.target); } });
			}, { threshold: 0.3 });
			els.forEach(function (el) { io.observe(el); });
		} else {
			els.forEach(function (el) { el.setAttribute('data-countup-done', '1'); SP.countUp(el); });
		}
	};

	/* ---------------------------------------------------- URL state sync */
	SP.url = {
		read: function (keys) {
			const params = new URLSearchParams(location.search);
			const out = {};
			(keys || Array.from(params.keys())).forEach(function (k) {
				const all = params.getAll(k.replace(/\[\]$/, '') + '[]');
				if (all.length) out[k.replace(/\[\]$/, '')] = all; else if (params.has(k)) out[k] = params.get(k);
			});
			return out;
		},
		write: function (values, defaults) {
			const params = new URLSearchParams();
			Object.keys(values).forEach(function (k) {
				const v = values[k];
				const d = defaults ? defaults[k] : undefined;
				if (v === undefined || v === null || v === '' || v === false || JSON.stringify(v) === JSON.stringify(d)) return;
				if (Array.isArray(v)) { if (v.length) v.forEach(function (x) { params.append(k + '[]', x); }); } else params.set(k, v);
			});
			const qs = params.toString();
			history.replaceState(null, '', location.pathname + (qs ? '?' + qs : '') + location.hash);
		}
	};

	/* --------------------------------------------------- Alpine components */
	document.addEventListener('alpine:init', function () {
		const Alpine = window.Alpine;

		/* spTable: client mode (rows provided) or server mode (endpoint provided). */
		Alpine.data('spTable', function (config) {
			config = config || {};
			const perPageDefault = config.perPage || (CONFIG.pagination && CONFIG.pagination.default) || 25;
			return {
				rows: config.rows || [],
				columns: config.columns || [],
				endpoint: config.endpoint || null,
				query: config.query || '',
				sortKey: config.sortKey || (config.columns && config.columns[0] ? config.columns[0].key : ''),
				sortDir: config.sortDir || 'asc',
				page: 1,
				perPage: perPageDefault,
				perPageOptions: config.perPageOptions || (CONFIG.pagination && CONFIG.pagination.options) || [10, 25, 50, 100, 'all'],
				total: 0,
				loading: false,
				extra: config.extra || {},
				init: function () {
					const self = this;
					this.$watch('query', SP.debounce(function () { self.page = 1; if (self.endpoint) self.load(); }, 250));
					this.$watch('sortKey', function () { if (self.endpoint) self.load(); });
					this.$watch('sortDir', function () { if (self.endpoint) self.load(); });
					this.$watch('page', function () { if (self.endpoint) self.load(); });
					this.$watch('perPage', function () { self.page = 1; if (self.endpoint) self.load(); });
					if (this.endpoint) this.load();
				},
				load: async function () {
					if (!this.endpoint) return;
					this.loading = true;
					try {
						const data = await SP.api(this.endpoint, { query: Object.assign({ q: this.query, sort: this.sortKey, dir: this.sortDir, page: this.page, per_page: this.perPage }, this.extra) });
						this.rows = data.items || [];
						this.total = data.total || this.rows.length;
					} catch (e) { SP.toast.error(e.message); }
					this.loading = false;
				},
				reload: function () { return this.load(); },
				setSort: function (key) {
					if (this.sortKey === key) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'; else { this.sortKey = key; this.sortDir = 'asc'; }
				},
				get filtered() {
					if (this.endpoint) return this.rows;
					const q = String(this.query || '').trim().toLowerCase();
					if (!q) return this.rows;
					return this.rows.filter(function (r) {
						return Object.keys(r).some(function (k) { const v = r[k]; return v != null && typeof v !== 'object' && String(v).toLowerCase().indexOf(q) !== -1; });
					});
				},
				get sorted() {
					if (this.endpoint) return this.rows;
					const key = this.sortKey, dir = this.sortDir === 'desc' ? -1 : 1;
					if (!key) return this.filtered;
					return this.filtered.slice().sort(function (a, b) {
						const x = a[key], y = b[key];
						if (x == null) return 1; if (y == null) return -1;
						if (typeof x === 'number' && typeof y === 'number') return (x - y) * dir;
						return String(x).localeCompare(String(y), undefined, { numeric: true, sensitivity: 'base' }) * dir;
					});
				},
				get count() { return this.endpoint ? this.total : this.filtered.length; },
				get pageCount() { return Math.max(1, Math.ceil(this.count / this.perPage)); },
				get paged() {
					if (this.endpoint) return this.rows;
					const start = (this.page - 1) * this.perPage;
					return this.sorted.slice(start, start + this.perPage);
				},
				get pageNumbers() {
					const n = this.pageCount, p = this.page, out = [];
					let from = Math.max(1, p - 2), to = Math.min(n, from + 4);
					from = Math.max(1, to - 4);
					for (let i = from; i <= to; i++) out.push(i);
					return out;
				},
				get summary() {
					if (!this.count) return I18N.noResults || 'No results.';
					const start = (this.page - 1) * this.perPage + 1;
					const end = Math.min(this.count, this.page * this.perPage);
					return SP.sprintf(I18N.showing || 'Showing %1$s–%2$s of %3$s', start, end, this.count);
				},
				goTo: function (p) { this.page = Math.min(this.pageCount, Math.max(1, p)); }
			};
		});

		/* spFilters: live filtering with URL sync; results fetched from an endpoint. */
		Alpine.data('spFilters', function (config) {
			config = config || {};
			const defaults = Object.assign({}, config.defaults || {});
			return {
				values: Object.assign({}, defaults, SP.url.read(Object.keys(defaults))),
				defaults: defaults,
				endpoint: config.endpoint || null,
				results: [],
				total: 0,
				page: 1,
				pageCount: 1,
				loading: false,
				loaded: false,
				meta: {},
				init: function () {
					const self = this;
					this.$watch('values', SP.debounce(function () { self.page = 1; self.load(); SP.url.write(self.values, self.defaults); }, 300));
					this.load();
				},
				load: async function () {
					if (!this.endpoint) return;
					this.loading = true;
					try {
						const data = await SP.api(this.endpoint, { query: Object.assign({}, this.values, { page: this.page }) });
						this.results = data.items || [];
						this.total = data.total || 0;
						this.pageCount = data.pages || 1;
						this.meta = data.meta || {};
						this.loaded = true;
						this.$nextTick(function () { SP.initCountUps(); });
					} catch (e) { SP.toast.error(e.message); }
					this.loading = false;
				},
				reset: function () { this.values = Object.assign({}, this.defaults); },
				get isDirty() { return JSON.stringify(this.values) !== JSON.stringify(this.defaults); },
				goTo: function (p) { this.page = Math.min(this.pageCount, Math.max(1, p)); this.load(); },
				apply: function (params) { this.values = Object.assign({}, this.defaults, params || {}); }
			};
		});

		Alpine.data('spTabs', function (initial) {
			return { active: initial || '', is: function (k) { return this.active === k; }, set: function (k) { this.active = k; } };
		});
	});

	/* ------------------------------------------------------------ boot */
	/* S8: floating feedback button → modal form → POST feedback (screenshot via POST feedback/upload first). */
	SP.feedback = {
		opened: 0,
		open: function () {
			const tpl = document.getElementById('sp-feedback-template');
			if (!tpl || !SP.modal) return null;
			const d = tpl.dataset;
			const node = tpl.content ? tpl.content.cloneNode(true) : null;
			if (!node) return null;
			const wrap = document.createElement('div');
			wrap.appendChild(node);
			const form = wrap.querySelector('form');
			const openedAt = Date.now();
			const footer = '<button type="button" class="sp-btn sp-btn--ghost" data-fb="cancel">' + SP.escape(d.cancel || 'Cancel') + '</button>' +
				'<button type="button" class="sp-btn sp-btn--primary" data-fb="send">' + SP.escape(d.send || 'Send feedback') + '</button>';
			const modal = SP.modal({ title: d.title || 'Feedback', node: wrap, footer: footer, size: 'sm' });
			modal.el.querySelector('.sp-modal').id = 'sp-feedback-dialog';
			const sendBtn = modal.el.querySelector('[data-fb="send"]');
			const errBox = form.querySelector('[data-form-error]');
			const fileInput = form.querySelector('input[type=file]');
			let screenshotId = 0, busy = false;
			const showError = function (message, field) {
				form.querySelectorAll('.sp-field--error').forEach(function (f) { f.classList.remove('sp-field--error'); });
				if (field) { const f = form.querySelector('[data-field="' + field + '"]'); if (f) f.classList.add('sp-field--error'); }
				errBox.textContent = message; errBox.hidden = false;
				SP.focusFirstError(form);
			};
			if (fileInput) {
				fileInput.addEventListener('change', async function () {
					const file = fileInput.files && fileInput.files[0];
					const label = form.querySelector('[data-screenshot-name]');
					if (!file) return;
					if (file.size > (Number(d.maxMb) || 5) * 1024 * 1024) { showError((I18N.tooLarge || 'That image is too large.'), 'screenshot'); fileInput.value = ''; return; }
					const fd = new FormData(); fd.append('file', file);
					try {
						label.textContent = I18N.sending || 'Sending…';
						const res = await SP.api('feedback/upload', { method: 'POST', body: fd });
						screenshotId = res.id || 0;
						label.textContent = file.name;
					} catch (e) { screenshotId = 0; label.textContent = ''; showError(e.message || I18N.error, 'screenshot'); fileInput.value = ''; }
				});
			}
			const token = function () {
				if (d.recaptcha !== '1' || !CONFIG.recaptchaSiteKey || !window.grecaptcha) return Promise.resolve('');
				return new Promise(function (resolve) {
					try { window.grecaptcha.ready(function () { window.grecaptcha.execute(CONFIG.recaptchaSiteKey, { action: d.recaptchaAction || 'feedback' }).then(resolve).catch(function () { resolve(''); }); }); } catch (e) { resolve(''); }
				});
			};
			const send = async function () {
				if (busy) return;
				const body = {};
				new FormData(form).forEach(function (v, k) { if (k !== 'screenshot') body[k] = v; });
				body.elapsed = Math.round((Date.now() - openedAt) / 1000);
				body.page_url = location.href;
				body.user_agent = navigator.userAgent;
				body.screenshot_id = screenshotId;
				if (!String(body.message || '').trim()) { showError(d.fix || 'Please check the highlighted field.', 'message'); return; }
				busy = true; sendBtn.disabled = true; sendBtn.textContent = d.sending || 'Sending…';
				try {
					body['g-recaptcha-response'] = await token();
					const res = await SP.api('feedback', { method: 'POST', body: body });
					wrap.innerHTML = '<div class="sp-feedback-done"><strong>' + SP.escape(d.sentTitle || 'Thank you!') + '</strong><p>' + SP.escape(res.message || d.sentText || '') + '</p></div>';
					modal.el.querySelector('.sp-modal__footer').innerHTML = '<button type="button" class="sp-btn sp-btn--primary" data-fb="cancel">' + SP.escape(d.done || 'Done') + '</button>';
					modal.el.querySelector('[data-fb="cancel"]').addEventListener('click', function () { modal.close(); });
					SP.toast.success(res.message || d.sentText || '');
				} catch (e) {
					const errs = e && e.data && e.data.data && e.data.data.errors ? e.data.data.errors : {};
					const field = Object.keys(errs).filter(function (k) { return ['name', 'email', 'message'].indexOf(k) !== -1; })[0];
					showError(field ? errs[field] : (e.message || I18N.error), field || '');
					busy = false; sendBtn.disabled = false; sendBtn.textContent = d.send || 'Send feedback';
				}
			};
			modal.el.querySelector('[data-fb="cancel"]').addEventListener('click', function () { modal.close(); });
			sendBtn.addEventListener('click', send);
			form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
			setTimeout(function () { const first = form.querySelector('input:not([type=hidden]), textarea'); if (first) first.focus(); }, 50);
			return modal;
		}
	};
	document.addEventListener('click', function (e) {
		const btn = e.target.closest('[data-sp-feedback-open]');
		if (btn) { e.preventDefault(); SP.feedback.open(); }
	});

	/* S2: after a form re-renders with errors, scroll to and focus the first field in error (or the error alert itself). */
	SP.focusFirstError = function (root) {
		root = root || document;
		const field = root.querySelector('.sp-field--error');
		const target = field ? (field.querySelector('input:not([type=hidden]), select, textarea') || field) : root.querySelector('.sp-alert--error');
		if (!target || target.closest('[x-cloak]') || target.offsetParent === null) return false;
		if (!/^(INPUT|SELECT|TEXTAREA|BUTTON|A)$/.test(target.tagName) && !target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
		try { target.scrollIntoView({ block: 'center', behavior: reducedMotion ? 'auto' : 'smooth' }); } catch (e) { target.scrollIntoView(); }
		try { target.focus({ preventScroll: true }); } catch (e) { target.focus(); }
		return true;
	};

	document.addEventListener('DOMContentLoaded', function () {
		SP.initCountUps();
		(CONFIG.flash || []).forEach(function (f) { SP.toast({ type: f.type, title: f.title, message: f.message, actions: f.actions }); });
		setTimeout(function () { SP.focusFirstError(document); }, 50); // after Alpine has shown the right tab/panel
		document.addEventListener('click', function (e) {
			const share = e.target.closest('[data-sp-share]');
			if (share) {
				e.preventDefault();
				SP.share({ url: share.getAttribute('data-sp-share') || location.href, title: share.getAttribute('data-sp-share-title') || document.title, text: share.getAttribute('data-sp-share-text') || '' });
			}
		});
	});
})();
