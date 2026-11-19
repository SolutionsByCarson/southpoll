/* SouthPoll Membership — classifieds screens: archive (spClassifieds), listing page (spListingPage), listing editor
 * (spListingEditor), My Listings (spMyListings), My Favorites (spMyFavorites), Saved Searches (spSavedSearches),
 * and the save-search helper. Loaded (deferred) after sp-public and before Alpine. */
(function () {
	'use strict';

	const SP = window.SP = window.SP || {};
	const CONFIG = window.SP_CONFIG || {};
	const I18N = CONFIG.i18n || {};
	const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function guestToast(message) {
		SP.toast({ type: 'info', title: I18N.savedSearchesTitle || 'Saved searches', message: message || I18N.savedSearchGuest || I18N.loginRequired || 'This feature requires an account.', actions: [{ label: I18N.createAccount || 'Create an account', href: CONFIG.urls.register }, { label: I18N.logIn || 'Log in', href: CONFIG.urls.login }] });
	}
	function apiError(e) {
		if (e.status === 401) { guestToast(); return; }
		if (e.status === 403 && e.data && e.data.data && e.data.data.verify) { SP.toast({ type: 'warning', message: e.message, actions: [{ label: I18N.verifyNow || 'Resend verification', href: CONFIG.urls.account }] }); return; }
		SP.toast.error(e.message);
	}

	/* --------------------------------------------------------- saved search */
	SP.savedSearch = {
		save: async function (params, name) {
			if (!CONFIG.isLoggedIn) { guestToast(); return null; }
			try {
				const r = await SP.api('saved-searches', { method: 'POST', body: { params: params, name: name || '' } });
				SP.toast({ type: r.already_saved ? 'info' : 'success', message: r.message + (r.note ? ' ' + r.note : ''), actions: [{ label: I18N.savedSearches || 'Saved Searches', href: r.searches_url || CONFIG.urls.savedSearches }] });
				return r;
			} catch (e) { apiError(e); return null; }
		}
	};

	/* ------------------------------------------------------ Alpine components */
	document.addEventListener('alpine:init', function () {
		const Alpine = window.Alpine;

		/* Classifieds archive: filters + list/map + paging + save search. */
		Alpine.data('spClassifieds', function (cfg) {
			cfg = cfg || {};
			const defaults = Object.assign({}, cfg.defaults || {});
			const fromUrl = SP.url.read(Object.keys(defaults).concat(['view', 'page']));
			const start = Object.assign({}, defaults, cfg.values || {});
			Object.keys(defaults).forEach(function (k) {
				if (fromUrl[k] === undefined) return;
				start[k] = Array.isArray(defaults[k]) ? String(fromUrl[k]).split(',').filter(Boolean) : fromUrl[k];
			});
			if (cfg.lockedCategory) start.cats = [cfg.lockedCategory];
			return {
				values: start,
				defaults: defaults,
				view: fromUrl.view === 'map' ? 'map' : (cfg.view === 'map' ? 'map' : 'list'),
				page: parseInt(fromUrl.page, 10) || cfg.page || 1,
				pages: cfg.pages || 1,
				total: cfg.total || 0,
				perPage: cfg.perPage || 20,
				items: cfg.initial || [],
				hydrated: false,
				loading: false,
				saving: false,
				pins: [],
				pinsLoaded: false,
				mapObj: null,
				mapsEnabled: !!cfg.mapsEnabled,
				farmQuery: '',
				facets: cfg.facets || {},
				savedId: cfg.savedId ? String(cfg.savedId) : '',
				/* Quick-apply a saved search in place: the filter inputs update through x-model, results reload, the URL follows. */
				applySaved: function (id) {
					const s = (cfg.savedSearches || []).filter(function (x) { return String(x.id) === String(id); })[0];
					if (!s) { this.savedId = ''; return; }
					const params = s.params || {};
					const next = Object.assign({}, this.defaults, { cats: [], farms: [] });
					Object.keys(this.defaults).forEach(function (k) {
						if (params[k] === undefined || params[k] === null || params[k] === '') return;
						next[k] = Array.isArray(next[k]) ? [].concat(params[k]).map(String) : String(params[k]);
					});
					if (cfg.lockedCategory) next.cats = [cfg.lockedCategory];
					this.values = next;
					SP.toast({ type: 'info', title: (I18N.savedSearchApplied || 'Saved search applied') + ': ' + s.name, message: s.summary || '', timeout: 6000 });
					SP.api('saved-searches/' + s.id + '/viewed', { method: 'POST' }).catch(function () {});
				},
				/* Option counts for the current filter set (each dimension ignores its own selection); refreshed with every load. */
				facetCount: function (dim, value) {
					const list = (this.facets && this.facets[dim]) || [];
					for (let i = 0; i < list.length; i++) { if (String(list[i].value) === String(value)) return Number(list[i].count) || 0; }
					return 0;
				},
				facetLabel: function (dim, value, label) { return label + ' (' + this.facetCount(dim, value) + ')'; },
				init: function () {
					const self = this;
					this.hydrated = true;
					if (cfg.notice && cfg.notice.title) { // applied saved / shared search: slide-in notification instead of an inline banner
						SP.toast({ type: 'info', title: cfg.notice.title, message: cfg.notice.message || '', timeout: 8000, actions: cfg.notice.href ? [{ label: cfg.notice.label, href: cfg.notice.href }] : [] });
					}
					this.$watch('values', SP.debounce(function () { if (cfg.lockedCategory) self.values.cats = [cfg.lockedCategory]; self.page = 1; self.pinsLoaded = false; self.load(); if (self.view === 'map') self.loadPins(); self.sync(); }, 300));
					this.$watch('view', function (v) { self.sync(); if (v === 'map') self.$nextTick(function () { self.showMap(); }); });
					if (this.view === 'map') this.$nextTick(function () { self.showMap(); });
				},
				get filteredFarms() {
					const q = this.farmQuery.trim().toLowerCase();
					const selected = (this.values.farms || []).map(String);
					const list = ((this.facets && this.facets.farms) || cfg.farmsOptions || []).filter(function (f) { return Number(f.count) > 0 || selected.indexOf(String(f.value)) !== -1; });
					return q ? list.filter(function (f) { return f.label.toLowerCase().indexOf(q) !== -1; }) : list;
				},
				query: function () {
					const q = Object.assign({}, this.values);
					if (cfg.lockedCategory) q.cats = [cfg.lockedCategory];
					return q;
				},
				sync: function () {
					const vals = Object.assign({}, this.values, { view: this.view === 'map' ? 'map' : '', page: this.page > 1 ? String(this.page) : '' });
					if (cfg.lockedCategory) delete vals.cats;
					const defs = Object.assign({}, this.defaults, { view: '', page: '' });
					// Arrays are written comma-joined so the SSR page and saved searches read the same shape.
					['cats', 'farms'].forEach(function (k) { if (Array.isArray(vals[k])) vals[k] = vals[k].join(','); if (Array.isArray(defs[k])) defs[k] = ''; });
					SP.url.write(vals, defs);
				},
				load: async function () {
					this.loading = true;
					try {
						const d = await SP.api(cfg.endpoint || 'listings', { query: Object.assign({}, this.query(), { page: this.page, per_page: this.perPage }) });
						this.items = d.items || []; this.total = d.total || 0; this.pages = d.pages || 1; this.page = d.page || 1;
						if (d.meta && d.meta.facets) this.facets = d.meta.facets;
					} catch (e) { SP.toast.error(e.message); }
					this.loading = false;
				},
				goTo: function (p) {
					this.page = Math.min(this.pages, Math.max(1, p));
					this.load(); this.sync();
					const top = this.$root.getBoundingClientRect().top + window.scrollY - 90;
					window.scrollTo({ top: Math.max(0, top), behavior: reducedMotion ? 'auto' : 'smooth' });
				},
				reset: function () { this.values = Object.assign({}, this.defaults, { cats: cfg.lockedCategory ? [cfg.lockedCategory] : [], farms: [] }); this.farmQuery = ''; },
				get isDirty() {
					const a = Object.assign({}, this.values); const b = Object.assign({}, this.defaults, cfg.lockedCategory ? { cats: [cfg.lockedCategory] } : {});
					return JSON.stringify(a) !== JSON.stringify(b);
				},
				get summary() {
					if (!this.total) return I18N.noResults || 'No results found.';
					const from = (this.page - 1) * this.perPage + 1, to = Math.min(this.total, this.page * this.perPage);
					return SP.sprintf(I18N.showing || 'Showing %1$s–%2$s of %3$s', from, to, this.total);
				},
				get pageNumbers() {
					const n = this.pages, p = this.page, out = [];
					let from = Math.max(1, p - 2), to = Math.min(n, from + 4);
					from = Math.max(1, to - 4);
					for (let i = from; i <= to; i++) out.push(i);
					return out;
				},
				get savedParams() {
					const q = this.query(), out = {};
					['q', 'cats', 'state', 'region', 'farms', 'since', 'sort'].forEach(function (k) {
						const v = q[k];
						if (v === undefined || v === '' || (Array.isArray(v) && !v.length) || (k === 'sort' && v === 'newest')) return;
						out[k] = Array.isArray(v) ? v.map(String) : v;
					});
					return out;
				},
				saveSearch: async function () {
					if (!Object.keys(this.savedParams).length) { SP.toast.warning(I18N.saveSearchEmpty || 'Add a search term or filter first.'); return; }
					this.saving = true;
					await SP.savedSearch.save(this.savedParams, '');
					this.saving = false;
				},
				showMap: async function () {
					if (!this.mapsEnabled || !this.$refs.map) return;
					if (!this.mapObj) {
						try { this.mapObj = await SP.map.create(this.$refs.map, { cluster: true }); }
						catch (e) { SP.toast.error(e.message); return; }
					}
					if (!this.pinsLoaded) await this.loadPins(); else this.mapObj.setPins(this.pins);
				},
				loadPins: async function () {
					try {
						const d = await SP.api(cfg.pinsEndpoint || 'listings/pins', { query: this.query() });
						this.pins = (d.items || []).map(function (p) { p.listings_url = (cfg.classifiedsUrl || '') + '?farms=' + p.id; return p; }); // p.url stays the farm page
						this.pinsLoaded = true;
						if (this.mapObj) this.mapObj.setPins(this.pins);
					} catch (e) { SP.toast.error(e.message); }
				}
			};
		});

		/* Single listing: gallery, map, view beacon, seller inquiry form. */
		Alpine.data('spListingPage', function (cfg) {
			cfg = cfg || {};
			return {
				fields: { name: cfg.user ? cfg.user.name : '', email: cfg.user ? cfg.user.email : '', phone: '', message: '' },
				errors: {}, hp: '', busy: false, sent: false, started: Date.now(), maxChars: cfg.maxChars || 2000,
				init: function () {
					if (cfg.map && this.$refs.map) {
						SP.map.create(this.$refs.map, { cluster: false, zoom: cfg.map.exact ? 11 : 8, center: cfg.map }).then(function (m) {
							m.setPins([{ lat: cfg.map.lat, lng: cfg.map.lng, name: cfg.name, light: 'on', exact: cfg.map.exact, url: '#', active_listings: 0 }], false);
							m.map.setZoom(cfg.map.exact ? 11 : 8);
						}).catch(function () {});
					}
					if (this.$refs.gallery) SP.gallery(this.$refs.gallery);
					if (cfg.countView && cfg.listingId) {
						const key = 'sp_viewed_' + cfg.listingId;
						let seen = false;
						try { seen = !!sessionStorage.getItem(key); } catch (e) { seen = false; }
						if (!seen) SP.api('listings/' + cfg.listingId + '/view', { method: 'POST' }).then(function () { try { sessionStorage.setItem(key, '1'); } catch (e) {} }).catch(function () {});
					}
				},
				captcha: function () {
					if (!cfg.recaptcha || !CONFIG.recaptchaSiteKey || !window.grecaptcha) return Promise.resolve('');
					return new Promise(function (resolve) {
						try { window.grecaptcha.ready(function () { window.grecaptcha.execute(CONFIG.recaptchaSiteKey, { action: cfg.action || 'farm_inquiry' }).then(resolve).catch(function () { resolve(''); }); }); }
						catch (e) { resolve(''); }
					});
				},
				send: async function () {
					if (this.busy) return;
					this.errors = {}; this.busy = true;
					try {
						const token = await this.captcha();
						const body = Object.assign({}, this.fields, { listing_id: cfg.listingId, elapsed: Math.round((Date.now() - this.started) / 1000), 'g-recaptcha-response': token, sp_website_url: this.hp });
						const r = await SP.api('inquiries/listing', { method: 'POST', body: body });
						this.sent = true; SP.toast.success(r.message);
					} catch (e) {
						const errs = e.data && e.data.data && e.data.data.errors;
						if (e.status === 422 && errs && typeof errs === 'object') this.errors = errs;
						SP.toast.error(e.message);
					}
					this.busy = false;
				}
			};
		});

		/* Listing editor: featured image, gallery manager, category → field-group visibility, submit routing. */
		Alpine.data('spListingEditor', function (cfg) {
			cfg = cfg || {};
			const base = 'my-listings/' + cfg.id;
			return {
				featured: cfg.featured || '', media: cfg.gallery || [], categories: (cfg.categories || []).map(Number), status: cfg.status || 'available',
				maxItems: cfg.maxItems || 10, maxMb: cfg.maxMb || 10,
				busy: { featured: false, gallery: false, video: false }, progress: 0, over: null, saving: false, dragKey: null, videoUrl: '', submitAs: 'save',
				groupApplies: function (id) {
					const g = (cfg.groups || []).find(function (x) { return x.id === id; });
					if (!g) return true;
					if (!g.categories || !g.categories.length) return true;
					const mine = this.categories;
					return g.categories.some(function (c) { return mine.indexOf(Number(c)) !== -1; });
				},
				setSubmit: function (kind, form) {
					this.submitAs = kind;
					const input = form && form.querySelector('[name=sp_submit]');
					if (input) input.value = kind;
				},
				confirmDelete: async function (form) {
					const ok = await SP.confirm({ title: I18N.deleteListing || 'Delete this listing?', message: I18N.deleteListingNote || 'This removes the listing and its photos for good.', confirmLabel: I18N.remove || 'Delete', danger: true });
					if (!ok) return;
					this.setSubmit('delete', form);
					form.submit();
				},
				uploadFeatured: async function (file) {
					const err = SP.uploader.check(file, this.maxMb);
					if (err) { SP.toast.error(err); return; }
					const self = this; this.busy.featured = true; this.progress = 0;
					try {
						const small = await SP.uploader.shrink(file, 2000);
						const r = await SP.uploader.send(base + '/image', small, {}, function (p) { self.progress = p; });
						this.featured = r.url + (r.url.indexOf('?') === -1 ? '?' : '&') + 'v=' + Date.now();
						SP.toast.success(r.message);
					} catch (e) { SP.toast.error(e.message); }
					this.busy.featured = false;
				},
				removeFeatured: async function () {
					const ok = await SP.confirm({ message: I18N.removeItem || 'Remove this item?', confirmLabel: I18N.remove || 'Remove', danger: true });
					if (!ok) return;
					try { const r = await SP.api(base + '/image', { method: 'DELETE' }); this.featured = ''; SP.toast.success(r.message); } catch (e) { SP.toast.error(e.message); }
				},
				addFiles: async function (files) {
					const list = Array.prototype.slice.call(files || []);
					if (!list.length) return;
					this.busy.gallery = true;
					for (const file of list) {
						if (this.media.length >= this.maxItems) { SP.toast.warning(I18N.galleryFull || 'The gallery is full.'); break; }
						const err = SP.uploader.check(file, this.maxMb);
						if (err) { SP.toast.error(file.name + ': ' + err); continue; }
						try { const small = await SP.uploader.shrink(file, 2000); const r = await SP.uploader.send(base + '/media', small, {}); this.media = r.items; }
						catch (e) { SP.toast.error(file.name + ': ' + e.message); }
					}
					this.busy.gallery = false;
				},
				addVideo: async function () {
					if (!this.videoUrl) return;
					this.busy.video = true;
					try { const r = await SP.api(base + '/media', { method: 'POST', body: { video_url: this.videoUrl } }); this.media = r.items; this.videoUrl = ''; SP.toast.success(r.message); }
					catch (e) { SP.toast.error(e.message); }
					this.busy.video = false;
				},
				saveCaption: async function (item, caption) {
					try { const r = await SP.api(base + '/media/' + item.key, { method: 'PATCH', body: { caption: caption } }); this.media = r.items; } catch (e) { SP.toast.error(e.message); }
				},
				remove: async function (item) {
					const ok = await SP.confirm({ message: I18N.removeItem || 'Remove this item?', confirmLabel: I18N.remove || 'Remove', danger: true });
					if (!ok) return;
					try { const r = await SP.api(base + '/media/' + item.key, { method: 'DELETE' }); this.media = r.items; SP.toast.success(r.message); } catch (e) { SP.toast.error(e.message); }
				},
				move: function (item, dir) {
					const i = this.media.indexOf(item), j = i + dir;
					if (i < 0 || j < 0 || j >= this.media.length) return;
					const copy = this.media.slice(); copy.splice(i, 1); copy.splice(j, 0, item); this.media = copy; this.persistOrder();
				},
				dragStart: function (item) { this.dragKey = item.key; },
				dragOver: function (target) {
					if (this.dragKey === null || target.key === this.dragKey) return;
					const key = this.dragKey;
					const from = this.media.findIndex(function (m) { return m.key === key; }), to = this.media.indexOf(target);
					if (from < 0 || to < 0) return;
					const copy = this.media.slice(); const moved = copy.splice(from, 1)[0]; copy.splice(to, 0, moved); this.media = copy;
				},
				dragEnd: function () { if (this.dragKey === null) return; this.dragKey = null; this.persistOrder(); },
				persistOrder: SP.debounce(async function () {
					try { await SP.api(base + '/media/reorder', { method: 'POST', body: { keys: this.media.map(function (m) { return m.key; }) } }); } catch (e) { SP.toast.error(e.message); }
				}, 400)
			};
		});

		/* My Listings table + quick actions. */
		Alpine.data('spMyListings', function (cfg) {
			cfg = cfg || {};
			return {
				items: cfg.items || [], summary: cfg.summary || { limit: 0, active: 0, remaining: 0 }, drafts: cfg.drafts || 0, expired: cfg.expired || 0, statuses: cfg.statuses || {},
				query: '', filter: 'all', sortKey: 'expires_at', sortDir: 'desc', page: 1, perPage: (CONFIG.pagination && CONFIG.pagination.default) || 25, perPageOptions: (CONFIG.pagination && CONFIG.pagination.options) || [10, 25, 50, 100, 'all'],
				init: function () { const self = this; this.$watch('query', function () { self.page = 1; }); this.$watch('filter', function () { self.page = 1; }); },
				setSort: function (k) { if (this.sortKey === k) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'; else { this.sortKey = k; this.sortDir = 'asc'; } },
				get filtered() {
					const q = this.query.trim().toLowerCase(), f = this.filter;
					return this.items.filter(function (l) {
						if (f === 'live' && l.post_status !== 'publish') return false;
						if (f === 'draft' && l.post_status !== 'draft') return false;
						if (f === 'expired' && l.post_status !== 'sp_expired') return false;
						if (!q) return true;
						const hay = [l.title, l.status_label, l.price, l.summary].concat((l.categories || []).map(function (c) { return c.name; })).join(' ').toLowerCase();
						return hay.indexOf(q) !== -1;
					});
				},
				get sorted() {
					const k = this.sortKey, d = this.sortDir === 'desc' ? -1 : 1;
					return this.filtered.slice().sort(function (a, b) {
						const x = a[k], y = b[k];
						if (x == null && y == null) return 0; if (x == null) return 1; if (y == null) return -1;
						if (typeof x === 'number' && typeof y === 'number') return (x - y) * d;
						return String(x).localeCompare(String(y), undefined, { numeric: true, sensitivity: 'base' }) * d;
					});
				},
				get pageCount() { return Math.max(1, Math.ceil(this.sorted.length / this.perPage)); },
				get paged() { const s = (this.page - 1) * this.perPage; return this.sorted.slice(s, s + this.perPage); },
				get pageNumbers() { const n = this.pageCount, p = this.page, out = []; let from = Math.max(1, p - 2), to = Math.min(n, from + 4); from = Math.max(1, to - 4); for (let i = from; i <= to; i++) out.push(i); return out; },
				get summaryText() { if (!this.sorted.length) return ''; const s = (this.page - 1) * this.perPage + 1; return SP.sprintf(I18N.showing || 'Showing %1$s–%2$s of %3$s', s, Math.min(this.sorted.length, this.page * this.perPage), this.sorted.length); },
				goTo: function (p) { this.page = Math.min(this.pageCount, Math.max(1, p)); },
				apply: function (r) { if (r.items) this.items = r.items; if (r.summary) this.summary = r.summary; if (r.drafts !== undefined) this.drafts = r.drafts; if (r.expired !== undefined) this.expired = r.expired; },
				act: async function (l, path, body, confirmOpts) {
					if (confirmOpts) { const ok = await SP.confirm(confirmOpts); if (!ok) return null; }
					try { const r = await SP.api('my-listings/' + l.id + (path ? '/' + path : ''), { method: path === '' ? 'DELETE' : 'POST', body: body }); this.apply(r); if (r.message) SP.toast.success(r.message); return r; }
					catch (e) { if (e.data && e.data.data) this.apply(e.data.data); SP.toast.error(e.message); return null; }
				},
				toggle: function (l) { return this.act(l, 'toggle'); },
				setStatus: function (l, status) { return this.act(l, 'status', { status: status }); },
				renew: function (l) { return this.act(l, 'renew', undefined, { title: I18N.renewListing || 'Renew this listing?', message: I18N.renewListingNote || 'It will run for another 60 days from today. Review the details afterwards if anything changed.', confirmLabel: I18N.renew || 'Renew' }); },
				duplicate: async function (l) {
					const r = await this.act(l, 'duplicate');
					if (r && r.edit_url) SP.toast({ type: 'success', message: r.message, actions: [{ label: I18N.editCopy || 'Edit the copy', href: r.edit_url }] });
				},
				remove: function (l) { return this.act(l, '', undefined, { title: I18N.deleteListing || 'Delete this listing?', message: (l.title || '') + ' — ' + (I18N.deleteListingNote || 'This removes the listing and its photos for good.'), confirmLabel: I18N.remove || 'Delete', danger: true }); }
			};
		});

		/* My Favorites. */
		Alpine.data('spMyFavorites', function (cfg) {
			cfg = cfg || {};
			return {
				tab: 'farms', show: 'active', query: '', farms: cfg.farms || [], listings: cfg.listings || [], prefs: cfg.prefs || {}, shareUrl: cfg.shareUrl || '',
				directoryUrl: cfg.directoryUrl, classifiedsUrl: cfg.classifiedsUrl,
				get list() { return this.tab === 'farms' ? this.farms : this.listings; },
				matches: function (item) {
					const q = this.query.trim().toLowerCase();
					if (!q) return true;
					const hay = [item.name, item.title, item.location, item.owner_name, item.price, item.farm && item.farm.name].concat((item.offerings || []).map(function (o) { return o.name; })).concat((item.categories || []).map(function (c) { return c.name; })).join(' ').toLowerCase();
					return hay.indexOf(q) !== -1;
				},
				get counts() {
					const self = this, m = this.list.filter(function (i) { return self.matches(i); });
					return { active: m.filter(function (i) { return i.active; }).length, inactive: m.filter(function (i) { return !i.active; }).length };
				},
				get visible() {
					const self = this;
					return this.list.filter(function (i) { return self.matches(i) && (self.show === 'active' ? i.active : !i.active); });
				},
				init: function () {
					const self = this;
					['farms', 'listings'].forEach(function (k) { if (self[k].length && !self[k].some(function (i) { return i.active; }) && self.list === self[k]) self.show = 'inactive'; });
				},
				setNotify: async function (item, on) {
					try { const r = await SP.api('favorites/' + item.favorite_id, { method: 'PATCH', body: { notify: on } }); item.notify = r.notify; SP.toast.success(r.message); } catch (e) { apiError(e); }
				},
				notifyAll: async function (on) {
					const type = this.tab === 'farms' ? 'farm' : 'listing';
					try { const r = await SP.api('favorites/notify-all', { method: 'POST', body: { type: type, notify: on } }); this.list.forEach(function (i) { i.notify = on; }); SP.toast.success(r.message); } catch (e) { apiError(e); }
				},
				removeFav: async function (item) {
					try { const r = await SP.api('favorites/' + item.favorite_id, { method: 'DELETE' }); this.drop(item); SP.toast.success(r.message); } catch (e) { apiError(e); }
				},
				drop: function (item) { this.farms = this.farms.filter(function (i) { return i !== item; }); this.listings = this.listings.filter(function (i) { return i !== item; }); },
				onFavorite: function (d) {
					if (!d || d.added) return;
					const key = d.type === 'farm' ? 'farms' : 'listings';
					this[key] = this[key].filter(function (i) { return i.id !== d.id; });
				},
				setPref: async function (key, val) {
					const body = {}; body[key] = val;
					try { const r = await SP.api('account/preferences', { method: 'PATCH', body: body }); this.prefs[key] = r[key]; SP.toast.success(r.message); } catch (e) { apiError(e); }
				},
				shareList: async function () {
					const self = this;
					try {
						if (!this.shareUrl) { const r = await SP.api('favorites/share-token', { method: 'POST' }); this.shareUrl = r.share_url; }
						const m = SP.share({ url: this.shareUrl, title: I18N.myFavoritesShareTitle || 'My favorites on SouthPoll.com', modalTitle: I18N.shareFavorites || 'Share my favorites list' });
						const btn = document.createElement('button');
						btn.type = 'button'; btn.className = 'sp-btn sp-btn--ghost sp-btn--sm'; btn.style.marginTop = '12px'; btn.textContent = I18N.newLink || 'Create a new link (old one stops working)';
						btn.addEventListener('click', async function () {
							try { const r = await SP.api('favorites/share-token', { method: 'POST', body: { regenerate: true } }); self.shareUrl = r.share_url; m.close(); SP.toast.success(r.message); self.shareList(); } catch (e) { apiError(e); }
						});
						m.body.appendChild(btn);
					} catch (e) { apiError(e); }
				}
			};
		});

		/* Saved Searches. */
		Alpine.data('spSavedSearches', function (cfg) {
			cfg = cfg || {};
			return {
				items: cfg.items || [], prefs: cfg.prefs || {}, query: '',
				get filtered() {
					const q = this.query.trim().toLowerCase();
					return q ? this.items.filter(function (s) { return (s.name + ' ' + s.summary).toLowerCase().indexOf(q) !== -1; }) : this.items;
				},
				setNotify: async function (s, on) {
					try { const r = await SP.api('saved-searches/' + s.id, { method: 'PATCH', body: { notify: on } }); this.items = r.items; SP.toast.success(on ? (I18N.alertsOn || 'Email alerts on.') : (I18N.alertsOff || 'Email alerts off.')); } catch (e) { apiError(e); }
				},
				notifyAll: async function (on) {
					try { const r = await SP.api('saved-searches/notify-all', { method: 'POST', body: { notify: on } }); this.items = r.items; SP.toast.success(r.message); } catch (e) { apiError(e); }
				},
				remove: async function (s) {
					const ok = await SP.confirm({ title: I18N.removeSearch || 'Remove this saved search?', message: s.name, confirmLabel: I18N.remove || 'Remove', danger: true });
					if (!ok) return;
					try { const r = await SP.api('saved-searches/' + s.id, { method: 'DELETE' }); this.items = r.items; SP.toast.success(r.message); } catch (e) { apiError(e); }
				},
				rename: function (s) {
					const self = this;
					const node = document.createElement('div');
					node.innerHTML = '<div class="sp-field"><label class="sp-label" for="sp-ss-name">' + SP.escape(I18N.searchName || 'Name') + '</label><input id="sp-ss-name" class="sp-input" type="text" maxlength="190" value="' + SP.escape(s.name) + '"></div>';
					const m = SP.modal({ title: I18N.rename || 'Rename search', size: 'sm', node: node, footer: '<button type="button" class="sp-btn sp-btn--ghost" data-modal-close="cancel">' + SP.escape(I18N.cancel || 'Cancel') + '</button><button type="button" class="sp-btn sp-btn--primary" data-modal-close="ok">' + SP.escape(I18N.save || 'Save') + '</button>', onClose: async function (result) {
						if (result !== 'ok') return;
						const name = node.querySelector('input').value.trim();
						if (!name) return;
						try { const r = await SP.api('saved-searches/' + s.id, { method: 'PATCH', body: { name: name } }); self.items = r.items; SP.toast.success(r.message); } catch (e) { apiError(e); }
					} });
					node.querySelector('input').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); m.close('ok'); } });
				},
				share: async function (s) {
					try { const r = await SP.api('saved-searches/' + s.id + '/share', { method: 'POST' }); SP.share({ url: r.share_url, title: r.title + ' – SouthPoll.com classifieds', text: (I18N.shareSearchText || 'Take a look at this South Poll classifieds search:') }); } catch (e) { apiError(e); }
				},
				setPref: async function (key, val) {
					const body = {}; body[key] = val;
					try { const r = await SP.api('account/preferences', { method: 'PATCH', body: body }); this.prefs[key] = r[key]; SP.toast.success(r.message); } catch (e) { apiError(e); }
				}
			};
		});
	});
})();
