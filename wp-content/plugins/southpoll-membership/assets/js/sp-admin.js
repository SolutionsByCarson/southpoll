/* SouthPoll Membership — wp-admin behaviours.
 * spAdminTable: server-side tables (search/filter/sort/paging kept in the URL), row selection + bulk actions, and the
 * detail drawer whose HTML comes from the REST detail endpoint. Forms inside the drawer speak a tiny protocol:
 *   <form data-sp-action="verify" [data-sp-confirm="…"] [data-sp-danger] [data-sp-endpoint="rest/path"] [data-sp-after="close"]>
 *   <form data-sp-patch>  → PATCH {drawer}/{id} with the form fields
 * Also: spAdminTiers, spAdminTaxonomies, spAdminImport, and the Integrations "test" buttons. */
(function () {
	'use strict';

	const SP = window.SP = window.SP || {};
	const CONFIG = window.SP_CONFIG || {};
	const I18N = CONFIG.i18n || {};
	const RESERVED = ['page', 'tab', 'tax'];

	function t(key, fallback) { return I18N[key] || fallback; }

	function readUrl(keys) {
		const params = new URLSearchParams(location.search);
		const out = {};
		keys.forEach(function (k) { if (params.has(k)) out[k] = params.get(k); });
		return out;
	}

	function writeUrl(values, defaults) {
		const params = new URLSearchParams(location.search);
		Object.keys(values).forEach(function (k) {
			if (RESERVED.indexOf(k) !== -1) return;
			const v = values[k];
			const d = defaults ? defaults[k] : undefined;
			if (v === undefined || v === null || v === '' || String(v) === String(d)) params.delete(k); else params.set(k, v);
		});
		history.replaceState(null, '', location.pathname + '?' + params.toString());
	}

	function pick(obj, keys) {
		const out = {};
		keys.forEach(function (k) { if (obj[k] !== undefined) out[k] = obj[k]; });
		return out;
	}

	/* Collect a form's fields (checkboxes become '1'/'0'; name[] becomes an array). */
	function formFields(form) {
		const out = {};
		Array.prototype.forEach.call(form.elements, function (el) {
			if (!el.name || el.disabled || el.tagName === 'BUTTON' || el.type === 'submit' || el.type === 'file') return;
			const isList = el.name.slice(-2) === '[]';
			const name = isList ? el.name.slice(0, -2) : el.name;
			if (el.type === 'checkbox') {
				if (isList) { if (!out[name]) out[name] = []; if (el.checked) out[name].push(el.value); }
				else out[name] = el.checked ? (el.value || '1') : '0';
				return;
			}
			if (el.type === 'radio') { if (el.checked) out[name] = el.value; return; }
			out[name] = el.value;
		});
		return out;
	}

	SP.badge = function (status) {
		const map = {
			active: 'success', public: 'success', live: 'success', available: 'success', succeeded: 'success', approved: 'success', verified: 'success',
			expiring: 'warning', pending: 'warning', coming_soon: 'warning', received: 'warning',
			draft: 'muted', none: 'muted', inactive: 'muted', refunded: 'muted', sold: 'muted',
			expired: 'danger', cancelled: 'danger', suspended: 'danger', failed: 'danger', rejected: 'danger', hidden: 'danger'
		};
		return 'sp-badge sp-badge--' + (map[status] || 'muted');
	};

	SP.label = function (s) {
		s = String(s == null ? '' : s).replace(/_/g, ' ');
		return s.charAt(0).toUpperCase() + s.slice(1);
	};

	function errorMessage(e) {
		const errs = e && e.data && e.data.data && e.data.data.errors;
		if (errs && typeof errs === 'object') return Object.keys(errs).map(function (k) { return errs[k]; }).join(' ');
		return (e && e.message) || t('error', 'Something went wrong.');
	}

	/* Modal with a few fields; resolves with the values or null when cancelled. */
	SP.promptForm = function (opts) {
		opts = opts || {};
		return new Promise(function (resolve) {
			let settled = false;
			let form = null;
			const fields = (opts.fields || []).map(function (f) {
				const id = 'sp-pf-' + f.name;
				let input;
				const req = f.required ? ' required' : '';
				if (f.type === 'textarea') input = '<textarea class="sp-textarea" id="' + id + '" name="' + SP.escape(f.name) + '" rows="' + (f.rows || 5) + '"' + req + '>' + SP.escape(f.value || '') + '</textarea>';
				else if (f.type === 'select') input = '<select class="sp-select" id="' + id + '" name="' + SP.escape(f.name) + '">' + Object.keys(f.options || {}).map(function (k) { return '<option value="' + SP.escape(k) + '"' + (String(f.value) === String(k) ? ' selected' : '') + '>' + SP.escape(f.options[k]) + '</option>'; }).join('') + '</select>';
				else input = '<input class="sp-input" id="' + id + '" type="' + (f.type || 'text') + '" name="' + SP.escape(f.name) + '" value="' + SP.escape(f.value == null ? '' : f.value) + '"' + (f.min != null ? ' min="' + f.min + '"' : '') + (f.max != null ? ' max="' + f.max + '"' : '') + req + '>';
				return '<div class="sp-field"><label class="sp-label" for="' + id + '">' + SP.escape(f.label || f.name) + '</label>' + input + (f.help ? '<p class="sp-help">' + SP.escape(f.help) + '</p>' : '') + '</div>';
			}).join('');
			const m = SP.modal({
				title: opts.title || '',
				html: '<form class="sp-prompt-form">' + (opts.message ? '<p>' + SP.escape(opts.message) + '</p>' : '') + fields + '<button type="submit" class="sp-sr-only" tabindex="-1">ok</button></form>',
				footer: '<button type="button" class="sp-btn sp-btn--ghost" data-modal-close="cancel">' + SP.escape(opts.cancelLabel || t('cancel', 'Cancel')) + '</button><button type="button" class="sp-btn ' + (opts.danger ? 'sp-btn--danger' : 'sp-btn--primary') + '" data-modal-close="ok">' + SP.escape(opts.confirmLabel || t('confirm', 'Confirm')) + '</button>',
				onClose: function (result) {
					if (settled) return;
					settled = true;
					resolve(result === 'ok' && form ? formFields(form) : null);
				}
			});
			form = m.el.querySelector('form');
			form.addEventListener('submit', function (e) { e.preventDefault(); if (form.reportValidity()) m.close('ok'); });
			const ok = m.el.querySelector('[data-modal-close="ok"]');
			ok.addEventListener('click', function (e) { if (!form.reportValidity()) { e.stopImmediatePropagation(); e.preventDefault(); } }, true);
		});
	};

	/* ------------------------------------------------- Integrations "test" buttons */
	document.addEventListener('click', async function (e) {
		const btn = e.target.closest('[data-sp-test]');
		if (!btn) return;
		e.preventDefault();
		const service = btn.getAttribute('data-sp-test');
		const out = document.querySelector('[data-sp-test-result="' + service + '"]');
		btn.disabled = true;
		if (out) { out.textContent = t('testing', 'Testing…'); out.className = 'sp-help'; }
		try {
			const res = await SP.api('admin/integrations/test', { method: 'POST', body: { service: service } });
			if (out) { out.textContent = res.message; out.className = 'sp-help sp-help--ok'; }
			SP.toast.success(res.message);
		} catch (err) {
			if (out) { out.textContent = err.message; out.className = 'sp-help sp-help--error'; }
			SP.toast.error(err.message);
		}
		btn.disabled = false;
	});

	/* -------------------------------------------------------- Alpine components */
	document.addEventListener('alpine:init', function () {
		const Alpine = window.Alpine;

		Alpine.data('spAdminTable', function (config) {
			config = config || {};
			const defaults = Object.assign({}, config.filters || {});
			const filterKeys = Object.keys(defaults);
			const fromUrl = readUrl(filterKeys.concat(['q', 'sort', 'dir', 'pg', 'per_page']));
			const perPageDefault = parseInt(config.perPage || 25, 10);
			return {
				endpoint: config.endpoint,
				drawerEndpoint: config.drawer || null,
				bulkEndpoint: config.bulk || null,
				deleteEndpoint: config.deleteEndpoint || null,
				defaults: defaults,
				filters: Object.assign({}, defaults, pick(fromUrl, filterKeys)),
				q: fromUrl.q || '',
				sort: fromUrl.sort || config.sort || '',
				dir: fromUrl.dir || config.dir || 'asc',
				page: Math.max(1, parseInt(fromUrl.pg || '1', 10) || 1),
				perPage: parseInt(fromUrl.per_page || perPageDefault, 10) || perPageDefault,
				perPageOptions: config.perPageOptions || [25, 50, 100],
				rows: [], total: 0, meta: {}, loading: false, loaded: false, busy: false,
				selected: [],
				drawer: { open: false, loading: false, id: 0, html: '', title: config.title || '' },
				init: function () {
					const self = this;
					this.$watch('q', SP.debounce(function () { self.page = 1; self.load(); }, 300));
					this.$watch('filters', function () { self.page = 1; self.load(); });
					this.$watch('perPage', function () { self.page = 1; self.load(); });
					this.load();
					if (config.openParam && this.drawerEndpoint) {
						const open = readUrl([config.openParam])[config.openParam];
						if (open) this.open(parseInt(open, 10));
					}
				},
				params: function () {
					return Object.assign({ q: this.q, sort: this.sort, dir: this.dir, page: this.page, per_page: this.perPage }, this.filters);
				},
				load: async function () {
					this.loading = true;
					try {
						const data = await SP.api(this.endpoint, { query: this.params() });
						this.rows = data.items || [];
						this.total = data.total || 0;
						this.meta = data.meta || {};
						this.loaded = true;
						const ids = this.rows.map(function (r) { return r.id; });
						this.selected = this.selected.filter(function (id) { return ids.indexOf(id) !== -1; });
						writeUrl(Object.assign({ q: this.q, sort: this.sort, dir: this.dir, pg: this.page, per_page: this.perPage }, this.filters), Object.assign({ q: '', sort: config.sort || '', dir: config.dir || 'asc', pg: 1, per_page: perPageDefault }, defaults));
					} catch (e) { SP.toast.error(errorMessage(e)); }
					this.loading = false;
				},
				reload: function () { return this.load(); },
				setSort: function (key) {
					if (this.sort === key) this.dir = this.dir === 'asc' ? 'desc' : 'asc'; else { this.sort = key; this.dir = 'asc'; }
					this.load();
				},
				sortArrow: function (key) { return this.sort === key ? (this.dir === 'asc' ? '▲' : '▼') : ''; },
				sortClass: function (key) { return this.sort === key ? 'is-sorted is-' + this.dir : ''; },
				get pageCount() { return Math.max(1, Math.ceil(this.total / this.perPage)); },
				get pageNumbers() {
					const n = this.pageCount, p = this.page, out = [];
					let from = Math.max(1, p - 2), to = Math.min(n, from + 4);
					from = Math.max(1, to - 4);
					for (let i = from; i <= to; i++) out.push(i);
					return out;
				},
				get summary() {
					if (!this.loaded) return '';
					if (!this.total) return t('noResults', 'No results.');
					const start = (this.page - 1) * this.perPage + 1;
					const end = Math.min(this.total, this.page * this.perPage);
					return SP.sprintf(t('showing', 'Showing %1$s–%2$s of %3$s'), start, end, SP.formatNumber(this.total));
				},
				goTo: function (p) { this.page = Math.min(this.pageCount, Math.max(1, p)); this.load(); },
				resetFilters: function () { this.q = ''; this.filters = Object.assign({}, defaults); },
				get isFiltered() { return this.q !== '' || JSON.stringify(this.filters) !== JSON.stringify(defaults); },
				exportUrl: function () {
					if (!config.exportUrl) return '#';
					const u = new URL(config.exportUrl, location.href);
					const p = this.params();
					Object.keys(p).forEach(function (k) {
						if (k === 'page' || k === 'per_page') return;
						if (p[k] !== '' && p[k] != null) u.searchParams.set(k, p[k]);
					});
					return u.toString();
				},
				/* selection */
				isSelected: function (id) { return this.selected.indexOf(id) !== -1; },
				toggle: function (id) { if (this.isSelected(id)) this.selected = this.selected.filter(function (x) { return x !== id; }); else this.selected.push(id); },
				get allSelected() { const self = this; return this.rows.length > 0 && this.rows.every(function (r) { return self.isSelected(r.id); }); },
				toggleAll: function () { this.selected = this.allSelected ? [] : this.rows.map(function (r) { return r.id; }); },
				clearSelection: function () { this.selected = []; },
				bulk: async function (action, extra, confirmMsg) {
					if (!this.bulkEndpoint || !this.selected.length) return;
					if (confirmMsg && !(await SP.confirm({ message: confirmMsg, danger: action === 'delete' || action === 'deactivate' }))) return;
					this.busy = true;
					try {
						const res = await SP.api(this.bulkEndpoint, { method: 'POST', body: Object.assign({ ids: this.selected, action: action }, extra || {}) });
						SP.toast[res.errors && res.errors.length ? 'warning' : 'success'](res.message || t('saved', 'Done.'));
						this.selected = [];
						await this.load();
					} catch (e) { SP.toast.error(errorMessage(e)); }
					this.busy = false;
				},
				bulkPrompt: async function (action, opts) {
					const vals = await SP.promptForm(opts || {});
					if (vals) return this.bulk(action, vals);
				},
				/* drawer */
				open: async function (id, title) {
					if (!this.drawerEndpoint) return;
					this.drawer.open = true; this.drawer.loading = true; this.drawer.id = id; this.drawer.title = title || config.title || '';
					document.body.classList.add('sp-drawer-open');
					try {
						const d = await SP.api(this.drawerEndpoint + '/' + id);
						this.drawer.html = d.html || '';
						this.drawer.title = title || d.name || (d.farm && d.farm.name) || this.drawer.title;
						writeUrl(config.openParam ? Object.fromEntries([[config.openParam, id]]) : {}, {});
					} catch (e) { SP.toast.error(errorMessage(e)); this.close(); return; }
					this.drawer.loading = false;
					const self = this;
					this.$nextTick(function () { const f = self.$refs.drawer && self.$refs.drawer.querySelector('.sp-drawer__close'); if (f) f.focus(); });
				},
				close: function () {
					this.drawer.open = false; this.drawer.html = ''; this.drawer.id = 0; this.drawer.loading = false;
					document.body.classList.remove('sp-drawer-open');
					if (config.openParam) writeUrl(Object.fromEntries([[config.openParam, '']]), {});
				},
				refreshDrawer: async function () {
					if (!this.drawer.id) return;
					const d = await SP.api(this.drawerEndpoint + '/' + this.drawer.id);
					this.drawer.html = d.html || '';
				},
				drawerSubmit: function (e) {
					const form = e.target;
					if (form && form.matches && form.matches('[data-sp-action],[data-sp-patch]')) this.runAction(form, formFields(form));
				},
				drawerClick: function (e) {
					const tab = e.target.closest('[data-sp-tab]');
					if (tab) {
						e.preventDefault();
						const root = tab.closest('.sp-drawer__body') || this.$refs.drawer;
						const key = tab.getAttribute('data-sp-tab');
						root.querySelectorAll('[data-sp-tab]').forEach(function (x) { x.classList.toggle('is-active', x === tab); x.setAttribute('aria-selected', x === tab ? 'true' : 'false'); });
						root.querySelectorAll('[data-sp-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-sp-panel') !== key; });
						return;
					}
					const btn = e.target.closest('button[data-sp-action]');
					if (btn && !btn.form) { e.preventDefault(); this.runAction(btn, {}); }
				},
				runAction: async function (el, fields) {
					const action = el.getAttribute('data-sp-action');
					const confirmMsg = el.getAttribute('data-sp-confirm');
					if (confirmMsg && !(await SP.confirm({ message: confirmMsg, danger: el.hasAttribute('data-sp-danger') }))) return;
					const isPatch = el.hasAttribute('data-sp-patch');
					const custom = el.getAttribute('data-sp-endpoint');
					const method = isPatch ? 'PATCH' : (el.getAttribute('data-sp-method') || 'POST');
					const path = custom || (this.drawerEndpoint + '/' + this.drawer.id + (isPatch ? '' : '/action'));
					const body = isPatch ? fields : Object.assign({ action: action }, fields);
					this.busy = true; el.classList.add('is-busy');
					try {
						const res = await SP.api(path, { method: method, body: body });
						SP.toast.success(res.message || t('saved', 'Saved.'));
						if (res.deleted || el.getAttribute('data-sp-after') === 'close') this.close();
						else if (res.html) this.drawer.html = res.html;
						else await this.refreshDrawer();
						await this.load();
					} catch (e) { SP.toast.error(errorMessage(e)); }
					el.classList.remove('is-busy'); this.busy = false;
				},
				/* row-level actions (payments, listing delete) */
				rowAction: async function (opts) {
					let vals = {};
					if (opts.fields && opts.fields.length) { vals = await SP.promptForm(opts); if (!vals) return; }
					else if (opts.confirm && !(await SP.confirm({ message: opts.confirm, danger: !!opts.danger }))) return;
					this.busy = true;
					try {
						const res = await SP.api(opts.path, { method: opts.method || 'POST', body: Object.assign(opts.action ? { action: opts.action } : {}, vals) });
						SP.toast.success(res.message || t('saved', 'Saved.'));
						await this.load();
					} catch (e) { SP.toast.error(errorMessage(e)); }
					this.busy = false;
				},
				remove: function (id, label) {
					if (!this.deleteEndpoint) return;
					return this.rowAction({ path: this.deleteEndpoint + '/' + id, method: 'DELETE', confirm: SP.sprintf(t('confirmDelete', 'Delete "%s"? This cannot be undone.'), label || '#' + id), danger: true });
				},
				badge: SP.badge, label: SP.label, money: SP.formatMoney
			};
		});

		Alpine.data('spAdminTiers', function () {
			return {
				items: [], loading: true, editing: null, adding: false, form: {}, busy: false,
				init: function () { this.load(); },
				load: async function () {
					try { const d = await SP.api('admin/tiers'); this.items = d.items || []; } catch (e) { SP.toast.error(errorMessage(e)); }
					this.loading = false;
				},
				edit: function (tier) { this.editing = tier.id; this.adding = false; this.form = Object.assign({}, tier); },
				add: function () { this.adding = true; this.editing = null; this.form = { name: '', description: '', price: '100.00', duration_days: 365, listing_limit: '', is_default: false, active: true, sort_order: this.items.length }; },
				cancel: function () { this.editing = null; this.adding = false; },
				save: async function () {
					this.busy = true;
					try {
						const res = await SP.api(this.adding ? 'admin/tiers' : 'admin/tiers/' + this.editing, { method: this.adding ? 'POST' : 'PATCH', body: this.form });
						if (res.items) this.items = res.items;
						SP.toast.success(res.message || t('saved', 'Saved.'));
						this.cancel();
					} catch (e) { SP.toast.error(errorMessage(e)); }
					this.busy = false;
				}
			};
		});

		Alpine.data('spAdminTaxonomies', function (config) {
			config = config || {};
			return {
				tax: config.tax, seo: !!config.seo, items: [], loading: true, editing: null, adding: false, form: {}, busy: false,
				init: function () { this.load(); },
				base: function () { return 'admin/taxonomies/' + this.tax; },
				load: async function () {
					try { const d = await SP.api(this.base()); this.items = d.items || []; } catch (e) { SP.toast.error(errorMessage(e)); }
					this.loading = false;
				},
				edit: function (term) { this.editing = term.id; this.adding = false; this.form = Object.assign({}, term); },
				add: function () { this.adding = true; this.editing = null; this.form = { name: '', slug: '', description: '', order: this.items.length, seo_title: '', seo_description: '' }; },
				cancel: function () { this.editing = null; this.adding = false; },
				save: async function () {
					this.busy = true;
					try {
						const res = await SP.api(this.adding ? this.base() : this.base() + '/' + this.editing, { method: this.adding ? 'POST' : 'PATCH', body: this.form });
						SP.toast.success(res.message || t('saved', 'Saved.'));
						if (res.items) this.items = res.items; else await this.load();
						this.cancel();
					} catch (e) { SP.toast.error(errorMessage(e)); }
					this.busy = false;
				},
				remove: async function (term) {
					if (!(await SP.confirm({ message: SP.sprintf(t('confirmDelete', 'Delete "%s"? This cannot be undone.'), term.name), danger: true }))) return;
					this.busy = true;
					try {
						const res = await SP.api(this.base() + '/' + term.id, { method: 'DELETE' });
						SP.toast.success(res.message || t('deleted', 'Deleted.'));
						if (res.items) this.items = res.items; else await this.load();
						if (this.editing === term.id) this.cancel();
					} catch (e) { SP.toast.error(errorMessage(e)); }
					this.busy = false;
				},
				move: async function (term, delta) {
					const i = this.items.indexOf(term), j = i + delta;
					if (i < 0 || j < 0 || j >= this.items.length) return;
					const arr = this.items.slice();
					arr.splice(i, 1); arr.splice(j, 0, term);
					this.items = arr;
					this.busy = true;
					try {
						const res = await SP.api(this.base() + '/reorder', { method: 'POST', body: { ids: arr.map(function (x) { return x.id; }) } });
						if (res.items) this.items = res.items;
					} catch (e) { SP.toast.error(errorMessage(e)); await this.load(); }
					this.busy = false;
				}
			};
		});

		Alpine.data('spAdminImport', function (config) {
			config = config || {};
			return {
				resource: config.resource, file: null, report: null, busy: false, mode: '',
				pick: function (e) { this.file = (e.target.files && e.target.files[0]) || null; this.report = null; },
				run: async function (dry) {
					if (!this.file) { SP.toast.warning(t('chooseFile', 'Choose a CSV file first.')); return; }
					if (!dry && !(await SP.confirm({ message: t('confirmImport', 'Import the valid rows now? Rows with problems are skipped. This cannot be undone.'), confirmLabel: t('import', 'Import') }))) return;
					this.busy = true; this.mode = dry ? 'check' : 'import';
					const fd = new FormData();
					fd.append('file', this.file);
					fd.append('dry_run', dry ? '1' : '0');
					try {
						this.report = await SP.api('admin/import/' + this.resource, { method: 'POST', body: fd });
						SP.toast[this.report.invalid || this.report.failed ? 'warning' : 'success'](this.report.message);
						if (!dry) { this.file = null; if (this.$refs.file) this.$refs.file.value = ''; }
					} catch (e) { SP.toast.error(errorMessage(e)); }
					this.busy = false; this.mode = '';
				}
			};
		});
	});

	document.addEventListener('DOMContentLoaded', function () {
		if (SP.initCountUps) SP.initCountUps();
	});
})();
