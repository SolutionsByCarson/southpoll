/* SouthPoll Membership — public components: Google Maps (with clustering), gallery/lightbox, uploader,
 * favorites, and the Alpine components for the directory, farm page and farm editor.
 * Loaded (deferred) after sp-core and before Alpine. */
(function () {
	'use strict';

	const SP = window.SP = window.SP || {};
	const CONFIG = window.SP_CONFIG || {};
	const I18N = CONFIG.i18n || {};
	const esc = SP.escape || function (s) { return String(s == null ? '' : s); };
	const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ------------------------------------------------------------ Google Maps */
	const svgPin = function (color) {
		return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="34" height="44" viewBox="0 0 34 44"><path d="M17 1C8.2 1 1 8.2 1 17c0 11 16 26 16 26s16-15 16-26C33 8.2 25.8 1 17 1z" fill="' + color + '" stroke="#fff" stroke-width="2"/><circle cx="17" cy="17" r="6" fill="#fff"/></svg>');
	};
	const svgCluster = function (n) {
		const size = n >= 100 ? 56 : n >= 10 ? 48 : 42;
		return { url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '"><circle cx="' + size / 2 + '" cy="' + size / 2 + '" r="' + (size / 2 - 2) + '" fill="#8d183c" stroke="#fff" stroke-width="3"/><circle cx="' + size / 2 + '" cy="' + size / 2 + '" r="' + (size / 2 - 7) + '" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="2"/></svg>'), size: size };
	};

	SP.map = {
		_promise: null,
		load: function () {
			if (window.google && window.google.maps && window.google.maps.Map) return Promise.resolve(window.google.maps);
			if (this._promise) return this._promise;
			const key = CONFIG.maps && CONFIG.maps.key;
			if (!key) return Promise.reject(new Error(I18N.mapsMissing || 'Map unavailable'));
			this._promise = new Promise(function (resolve, reject) {
				const cb = '__spMapsReady' + Date.now();
				window[cb] = function () { try { delete window[cb]; } catch (e) { window[cb] = undefined; } resolve(window.google.maps); };
				const s = document.createElement('script');
				s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&callback=' + cb + '&loading=async&v=weekly';
				s.async = true;
				s.onerror = function () { reject(new Error(I18N.mapsMissing || 'Map unavailable')); };
				document.head.appendChild(s);
			});
			return this._promise;
		},
		create: function (el, opts) {
			return SP.map.load().then(function () { return new SPMap(el, opts || {}); });
		},
		/* Info window: the same details as a directory card (logo, name, slogan, location/region, listings light, offerings, owner, favorite). */
		infoHtml: function (p) {
			if (p.listing_items && p.listing_items.length) return SP.map.listingsInfoHtml(p);
			const logo = p.logo ? '<img src="' + esc(p.logo) + '" alt="">' : '<span>' + esc(p.initials || '') + '</span>';
			const light = '<span class="sp-light sp-light--' + (p.light === 'on' ? 'on' : 'off') + '" aria-hidden="true"></span>';
			const n = Number(p.listings != null ? p.listings : p.active_listings) || 0;
			const listings = p.listings != null ? esc(n) + ' ' + esc(n === 1 ? (I18N.matchingListing || 'matching listing') : (I18N.matchingListings || 'matching listings')) : esc(n) + ' ' + esc(n === 1 ? (I18N.activeListing || 'active listing') : (I18N.activeListings || 'active listings'));
			const where = [p.location, p.region].filter(Boolean).map(esc).join(' · ');
			const chips = (p.offerings || []).slice(0, 5).map(function (o) { return '<span class="sp-map-info__chip">' + esc(o) + '</span>'; }).join('');
			const fav = CONFIG.isLoggedIn ? '<button type="button" class="sp-btn sp-btn--outline sp-btn--sm sp-fav sp-fav--btn' + (p.favorited ? ' is-active' : '') + '" data-sp-favorite="farm" data-id="' + esc(p.id) + '" aria-pressed="' + (p.favorited ? 'true' : 'false') + '"><span data-fav-label>' + esc(p.favorited ? (I18N.favorited || 'Favorited') : (I18N.favorite || 'Favorite')) + '</span></button>' : '';
			return '<div class="sp-map-info sp-scope">' +
				'<div class="sp-map-info__head"><div class="sp-map-info__logo">' + logo + '</div><div class="sp-map-info__text">' +
				'<p class="sp-map-info__name">' + esc(p.name) + (p.new_listing ? ' <span class="sp-badge sp-badge--new">' + esc(I18N.newListing || 'New listing') + '</span>' : '') + '</p>' +
				(p.slogan ? '<p class="sp-map-info__slogan">' + esc(p.slogan) + '</p>' : '') +
				(where ? '<p class="sp-map-info__meta">' + where + '</p>' : '') +
				'</div></div>' +
				'<p class="sp-map-info__meta sp-map-info__listings">' + light + ' ' + listings + (p.owner_name ? ' · ' + esc(p.owner_name) : '') + '</p>' +
				(chips ? '<div class="sp-map-info__chips">' + chips + '</div>' : '') +
				'<div class="sp-map-info__actions"><a class="sp-btn sp-btn--primary sp-btn--sm" href="' + esc(p.url) + '">' + esc(I18N.viewFarm || 'View farm page') + ' &rarr;</a>' + fav +
				(p.listings_url && n ? '<a class="sp-btn sp-btn--ghost sp-btn--sm" href="' + esc(p.listings_url) + '">' + esc(I18N.seeListings || 'See the listings') + '</a>' : '') + '</div>' +
				'</div>';
		},
		/* Classifieds map (T6.11): one marker per farm (its listings share an address), but the window is about the listings. */
		listingsInfoHtml: function (p) {
			const items = p.listing_items || [];
			const n = Number(p.listings != null ? p.listings : items.length) || items.length;
			const where = [p.location, p.region].filter(Boolean).map(esc).join(' · ');
			const rows = items.map(function (l) {
				const img = l.image ? '<img src="' + esc(l.image) + '" alt="" loading="lazy">' : '<span class="sp-map-listing__noimg" aria-hidden="true"></span>';
				const cat = (l.categories || []).map(esc).join(', ');
				return '<a class="sp-map-listing" href="' + esc(l.url) + '">' +
					'<span class="sp-map-listing__img">' + img + '</span>' +
					'<span class="sp-map-listing__text">' +
					'<span class="sp-map-listing__title">' + esc(l.title) + (l.is_new ? ' <span class="sp-badge sp-badge--new">' + esc(I18N.newBadge || 'New') + '</span>' : '') + '</span>' +
					'<span class="sp-map-listing__meta">' + [l.price ? '<strong>' + esc(l.price) + '</strong>' : '', cat].filter(Boolean).join(' · ') + '</span>' +
					'</span></a>';
			}).join('');
			const more = n > items.length ? '<a class="sp-btn sp-btn--ghost sp-btn--sm" href="' + esc(p.listings_url || p.url) + '">+' + esc(n - items.length) + ' ' + esc(I18N.more || 'more') + '</a>' : '';
			return '<div class="sp-map-info sp-map-info--listings sp-scope">' +
				'<p class="sp-map-info__name">' + esc(n) + ' ' + esc(n === 1 ? (I18N.matchingListing || 'matching listing') : (I18N.matchingListings || 'matching listings')) + '</p>' +
				'<p class="sp-map-info__meta">' + esc(p.name) + (where ? ' · ' + where : '') + '</p>' +
				'<div class="sp-map-info__list">' + rows + '</div>' +
				'<div class="sp-map-info__actions">' + more + '<a class="sp-btn sp-btn--primary sp-btn--sm" href="' + esc(p.listings_url || p.url) + '">' + esc(I18N.seeListings || 'See the listings') + ' &rarr;</a><a class="sp-btn sp-btn--ghost sp-btn--sm" href="' + esc(p.url) + '">' + esc(I18N.viewFarm || 'View farm page') + '</a></div>' +
				'</div>';
		}
	};

	function SPMap(el, opts) {
		const g = window.google.maps;
		const center = opts.center || (CONFIG.maps && CONFIG.maps.center) || { lat: 37.8, lng: -92.5 };
		this.map = new g.Map(el, { center: { lat: +center.lat, lng: +center.lng }, zoom: opts.zoom || (CONFIG.maps && CONFIG.maps.zoom) || 5, mapTypeControl: false, streetViewControl: false, fullscreenControl: true, gestureHandling: 'cooperative', clickableIcons: false });
		this.cluster = opts.cluster !== false;
		this.pins = [];
		this.markers = [];
		this.info = new g.InfoWindow({ maxWidth: 360 });
		this.renderedZoom = null;
		const self = this;
		// Clustering depends on the zoom level, so markers are rebuilt when the zoom changes — not on every pan: opening an
		// info window auto-pans the map, and rebuilding then would destroy the window's anchor marker and close it (T6.10).
		this.map.addListener('idle', function () { if (self.map.getZoom() !== self.renderedZoom) self.render(); });
	}
	SPMap.prototype.setPins = function (pins, fit) {
		const g = window.google.maps;
		this.pins = (pins || []).filter(function (p) { return p && p.lat != null && p.lng != null; });
		if (fit !== false && this.pins.length) {
			if (this.pins.length === 1) {
				this.map.setCenter({ lat: +this.pins[0].lat, lng: +this.pins[0].lng });
				this.map.setZoom(this.pins[0].exact === false ? 8 : 11);
			} else {
				const b = new g.LatLngBounds();
				this.pins.forEach(function (p) { b.extend({ lat: +p.lat, lng: +p.lng }); });
				this.map.fitBounds(b, 48);
			}
		}
		this.render();
	};
	SPMap.prototype.clear = function () {
		this.markers.forEach(function (m) { m.setMap(null); });
		this.markers = [];
	};
	SPMap.prototype.render = function () {
		const g = window.google.maps;
		this.clear();
		this.info.close();
		const proj = this.map.getProjection();
		this.renderedZoom = this.map.getZoom();
		if (!proj || !this.pins.length) return;
		if (!this.cluster || this.pins.length < 2) { this.pins.forEach(this.addPin, this); return; }
		const scale = Math.pow(2, this.map.getZoom());
		const cell = 58;
		const groups = new Map();
		this.pins.forEach(function (p) {
			const pt = proj.fromLatLngToPoint(new g.LatLng(+p.lat, +p.lng));
			const k = Math.floor(pt.x * scale / cell) + ':' + Math.floor(pt.y * scale / cell);
			if (!groups.has(k)) groups.set(k, []);
			groups.get(k).push(p);
		});
		const self = this;
		groups.forEach(function (list) { if (list.length === 1) self.addPin(list[0]); else self.addCluster(list); });
	};
	SPMap.prototype.addPin = function (p) {
		const g = window.google.maps;
		const self = this;
		const m = new g.Marker({ position: { lat: +p.lat, lng: +p.lng }, map: this.map, title: p.name || '', icon: { url: svgPin(p.light === 'on' ? '#1e8e3e' : '#8d183c'), scaledSize: new g.Size(34, 44), anchor: new g.Point(17, 43) }, optimized: true });
		m.addListener('click', function () { self.info.setContent(SP.map.infoHtml(p)); self.info.open({ map: self.map, anchor: m, shouldFocus: false }); });
		this.markers.push(m);
	};
	SPMap.prototype.addCluster = function (list) {
		const g = window.google.maps;
		const self = this;
		let lat = 0, lng = 0;
		list.forEach(function (p) { lat += +p.lat; lng += +p.lng; });
		lat /= list.length; lng /= list.length;
		const icon = svgCluster(list.length);
		const m = new g.Marker({ position: { lat: lat, lng: lng }, map: this.map, icon: { url: icon.url, scaledSize: new g.Size(icon.size, icon.size), anchor: new g.Point(icon.size / 2, icon.size / 2) }, label: { text: String(list.length), color: '#ffffff', fontWeight: '700', fontSize: '13px', fontFamily: 'Montserrat, Arial, sans-serif' }, title: list.length + ' ' + (I18N.farmsOnMap || 'farms'), optimized: true });
		m.addListener('click', function () {
			const b = new g.LatLngBounds();
			list.forEach(function (p) { b.extend({ lat: +p.lat, lng: +p.lng }); });
			if (b.getNorthEast().equals(b.getSouthWest())) {
				self.map.panTo(b.getCenter()); // animated instead of an instant jump
				self.map.setZoom(Math.min(self.map.getZoom() + 3, 14));
			} else {
				self.map.fitBounds(b, 60);
				g.event.addListenerOnce(self.map, 'idle', function () { if (self.map.getZoom() > 14) self.map.setZoom(14); });
			}
		});
		this.markers.push(m);
	};

	/* --------------------------------------------------------------- gallery */
	SP.gallery = function (root) {
		if (!root) return null;
		const items = Array.prototype.map.call(root.querySelectorAll('[data-gallery-item]'), function (el) {
			return { type: el.getAttribute('data-type') || 'image', full: el.getAttribute('data-full') || '', embed: el.getAttribute('data-embed') || '', thumb: el.getAttribute('data-thumb') || '', caption: el.getAttribute('data-caption') || '', el: el };
		});
		if (!items.length) return null;
		const stage = root.querySelector('[data-gallery-featured]');
		const caption = root.querySelector('[data-gallery-caption]');
		let current = 0;
		const setFeatured = function (i) {
			current = i;
			const it = items[i];
			items.forEach(function (x, j) { x.el.classList.toggle('is-active', j === i); });
			if (stage) {
				if (it.type === 'video') stage.innerHTML = '<div class="sp-gallery__video-cover"' + (it.thumb ? ' style="background-image:url(' + esc(it.thumb) + ')"' : '') + '><svg viewBox="0 0 24 24" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4z" fill="currentColor"/></svg></div>';
				else stage.innerHTML = '<img src="' + esc(it.full) + '" alt="' + esc(it.caption) + '">';
				stage.setAttribute('data-index', String(i));
			}
			if (caption) caption.textContent = it.caption;
		};
		const open = function (i) {
			let idx = i;
			const node = document.createElement('div');
			node.className = 'sp-lightbox';
			node.innerHTML = '<div class="sp-lightbox__stage"></div>' + (items.length > 1 ? '<button type="button" class="sp-lightbox__nav sp-lightbox__nav--prev" aria-label="' + esc(I18N.prev || 'Previous') + '">&#10094;</button><button type="button" class="sp-lightbox__nav sp-lightbox__nav--next" aria-label="' + esc(I18N.next || 'Next') + '">&#10095;</button>' : '') + '<div class="sp-lightbox__foot"><span class="sp-lightbox__caption"></span><span class="sp-lightbox__count"></span></div>';
			const stageEl = node.querySelector('.sp-lightbox__stage');
			const cap = node.querySelector('.sp-lightbox__caption');
			const count = node.querySelector('.sp-lightbox__count');
			const show = function (j) {
				idx = (j + items.length) % items.length;
				const it = items[idx];
				stageEl.innerHTML = it.type === 'video' ? '<iframe src="' + esc(it.embed) + '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen title="' + esc(it.caption || 'Video') + '"></iframe>' : '<img src="' + esc(it.full) + '" alt="' + esc(it.caption) + '">';
				cap.textContent = it.caption;
				count.textContent = (idx + 1) + ' ' + (I18N.of || 'of') + ' ' + items.length;
			};
			const modal = SP.modal({ title: false, node: node, size: 'lg', onClose: function () { document.removeEventListener('keydown', onKey); } });
			modal.el.querySelector('.sp-modal').classList.add('sp-modal--lightbox');
			const closeBtn = document.createElement('button');
			closeBtn.type = 'button'; closeBtn.className = 'sp-modal__close'; closeBtn.style.cssText = 'position:absolute;top:8px;right:8px;z-index:2;background:rgba(255,255,255,.9);color:#272727'; closeBtn.setAttribute('aria-label', I18N.close || 'Close'); closeBtn.innerHTML = '&times;';
			closeBtn.addEventListener('click', function () { modal.close(); });
			modal.el.querySelector('.sp-modal').appendChild(closeBtn);
			const prev = node.querySelector('.sp-lightbox__nav--prev'), next = node.querySelector('.sp-lightbox__nav--next');
			if (prev) prev.addEventListener('click', function () { show(idx - 1); });
			if (next) next.addEventListener('click', function () { show(idx + 1); });
			const onKey = function (e) { if (e.key === 'ArrowLeft') show(idx - 1); else if (e.key === 'ArrowRight') show(idx + 1); };
			document.addEventListener('keydown', onKey);
			show(idx);
		};
		items.forEach(function (it, i) {
			it.el.addEventListener('click', function () { if (it.type === 'video') { setFeatured(i); open(i); } else if (current === i) open(i); else setFeatured(i); });
		});
		if (stage) stage.addEventListener('click', function () { open(current); });
		return { open: open, setFeatured: setFeatured };
	};

	/* -------------------------------------------------------------- uploader */
	SP.uploader = {
		/** Downscale big images in the browser before upload (keeps uploads fast on rural connections). */
		shrink: function (file, maxPx) {
			maxPx = maxPx || 2000;
			if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) return Promise.resolve(file);
			return createImageBitmap(file).then(function (bmp) {
				const scale = Math.min(1, maxPx / Math.max(bmp.width, bmp.height));
				if (scale >= 1) { bmp.close && bmp.close(); return file; }
				const canvas = document.createElement('canvas');
				canvas.width = Math.round(bmp.width * scale); canvas.height = Math.round(bmp.height * scale);
				canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
				bmp.close && bmp.close();
				const type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
				return new Promise(function (resolve) { canvas.toBlob(function (blob) { resolve(blob ? new File([blob], file.name.replace(/\.[^.]+$/, type === 'image/png' ? '.png' : '.jpg'), { type: type }) : file); }, type, 0.88); });
			}).catch(function () { return file; });
		},
		send: function (path, file, fields, onProgress) {
			return new Promise(function (resolve, reject) {
				const fd = new FormData();
				Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
				fd.append('file', file, file.name);
				const xhr = new XMLHttpRequest();
				xhr.open('POST', (CONFIG.restUrl || '/wp-json/sp/v1/') + path.replace(/^\//, ''));
				if (CONFIG.restNonce) xhr.setRequestHeader('X-WP-Nonce', CONFIG.restNonce);
				xhr.setRequestHeader('Accept', 'application/json');
				xhr.upload.addEventListener('progress', function (e) { if (onProgress && e.lengthComputable) onProgress(Math.round(e.loaded / e.total * 100)); });
				xhr.addEventListener('load', function () {
					let data = null;
					try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
					if (xhr.status >= 200 && xhr.status < 300) resolve(data);
					else reject(new SP.Error((data && data.message) || I18N.error || 'Upload failed', xhr.status, data));
				});
				xhr.addEventListener('error', function () { reject(new SP.Error(I18N.error || 'Upload failed', 0, null)); });
				xhr.send(fd);
			});
		},
		check: function (file, maxMb) {
			if (!file) return I18N.notImage || 'Please choose an image.';
			if (!/^image\//.test(file.type)) return I18N.notImage || 'Please choose an image file.';
			if (maxMb && file.size > maxMb * 1024 * 1024) return I18N.tooLarge || 'That image is too large.';
			return '';
		}
	};

	/* ------------------------------------------------------------- favorites */
	SP.favorites = {
		busy: {},
		buttons: function (type, id) { return document.querySelectorAll('[data-sp-favorite="' + type + '"][data-id="' + id + '"]'); },
		paint: function (type, id, active) {
			SP.favorites.buttons(type, id).forEach(function (b) {
				b.classList.toggle('is-active', active);
				b.setAttribute('aria-pressed', active ? 'true' : 'false');
				b.setAttribute('aria-label', active ? (I18N.removeFavorite || 'Remove from favorites') : (I18N.addFavorite || 'Add to favorites'));
				b.setAttribute('title', b.getAttribute('aria-label'));
				const label = b.querySelector('[data-fav-label]');
				if (label) label.textContent = active ? (I18N.favorited || 'Favorited') : (I18N.favorite || 'Favorite');
			});
		},
		toggle: async function (type, id, btn) {
			const key = type + ':' + id;
			if (SP.favorites.busy[key]) return;
			if (!CONFIG.isLoggedIn) {
				SP.toast({ type: 'info', title: I18N.favoritesTitle || 'Favorites', message: I18N.favoritesGuest || I18N.loginRequired || 'This feature requires an account.', actions: [{ label: I18N.createAccount || 'Create an account', href: CONFIG.urls.register }, { label: I18N.logIn || 'Log in', href: CONFIG.urls.login }] });
				return;
			}
			SP.favorites.busy[key] = true;
			SP.favorites.buttons(type, id).forEach(function (b) { b.classList.add('is-busy'); });
			try {
				const r = await SP.api('favorites/toggle', { method: 'POST', body: { type: type, id: id } });
				SP.favorites.paint(type, id, r.added);
				const actions = [{ label: I18N.myFavorites || 'My Favorites', href: r.favorites_url || CONFIG.urls.favorites }];
				SP.toast({ type: 'success', message: r.message + (r.note ? ' ' + r.note : ''), actions: actions });
				if (!r.added && btn && btn.getAttribute('data-remove-row')) {
					const row = btn.closest('[data-fav-row]');
					if (row) row.remove();
				}
				document.dispatchEvent(new CustomEvent('sp:favorite', { bubbles: true, detail: { type: type, id: id, added: r.added, favorite_id: r.favorite_id, notify: r.notify } }));
			} catch (e) {
				if (e.status === 401) SP.toast({ type: 'info', title: I18N.favoritesTitle || 'Favorites', message: I18N.favoritesGuest || 'This feature requires an account.', actions: [{ label: I18N.createAccount || 'Create an account', href: CONFIG.urls.register }, { label: I18N.logIn || 'Log in', href: CONFIG.urls.login }] });
				else if (e.status === 403 && e.data && e.data.data && e.data.data.verify) SP.toast({ type: 'warning', message: e.message, actions: [{ label: I18N.verifyNow || 'Resend verification', href: CONFIG.urls.account }] });
				else SP.toast.error(e.message);
			}
			SP.favorites.busy[key] = false;
			SP.favorites.buttons(type, id).forEach(function (b) { b.classList.remove('is-busy'); });
		}
	};
	document.addEventListener('click', function (e) {
		const btn = e.target.closest('[data-sp-favorite]');
		if (!btn) return;
		e.preventDefault();
		SP.favorites.toggle(btn.getAttribute('data-sp-favorite'), parseInt(btn.getAttribute('data-id'), 10), btn);
	});

	/* ------------------------------------------------------ Alpine components */
	document.addEventListener('alpine:init', function () {
		const Alpine = window.Alpine;

		/* Directory: filters + list/map views + paging, all synced to the URL. */
		Alpine.data('spDirectory', function (cfg) {
			cfg = cfg || {};
			const defaults = Object.assign({}, cfg.defaults || {});
			const fromUrl = SP.url.read(Object.keys(defaults).concat(['view', 'page']));
			const start = Object.assign({}, defaults, cfg.values || {});
			Object.keys(defaults).forEach(function (k) { if (fromUrl[k] !== undefined) start[k] = Array.isArray(defaults[k]) ? [].concat(fromUrl[k]) : fromUrl[k]; });
			return {
				values: start,
				defaults: defaults,
				view: fromUrl.view === 'map' ? 'map' : (cfg.view === 'map' ? 'map' : 'list'),
				page: parseInt(fromUrl.page, 10) || cfg.page || 1,
				pages: cfg.pages || 1,
				total: cfg.total || 0,
				perPage: cfg.perPage || 24,
				items: cfg.initial || [],
				hydrated: false,
				loading: false,
				pins: [],
				pinsLoaded: false,
				mapObj: null,
				mapsEnabled: !!cfg.mapsEnabled,
				init: function () {
					const self = this;
					this.hydrated = true;
					this.$watch('values', SP.debounce(function () { self.page = 1; self.pinsLoaded = false; self.load(); if (self.view === 'map') self.loadPins(); self.sync(); }, 300));
					this.$watch('view', function (v) { self.sync(); if (v === 'map') self.$nextTick(function () { self.showMap(); }); });
					if (this.view === 'map') this.$nextTick(function () { self.showMap(); });
					// The SSR page may have been reached with ?page=N; nothing to fetch until something changes.
				},
				sync: function () {
					SP.url.write(Object.assign({}, this.values, { view: this.view === 'map' ? 'map' : '', page: this.page > 1 ? String(this.page) : '' }), Object.assign({}, this.defaults, { view: '', page: '' }));
				},
				load: async function () {
					this.loading = true;
					try {
						const d = await SP.api(cfg.endpoint || 'farms', { query: Object.assign({}, this.values, { page: this.page, per_page: this.perPage }) });
						this.items = d.items || [];
						this.total = d.total || 0;
						this.pages = d.pages || 1;
						this.page = d.page || 1;
					} catch (e) { SP.toast.error(e.message); }
					this.loading = false;
				},
				goTo: function (p) {
					this.page = Math.min(this.pages, Math.max(1, p));
					this.load();
					this.sync();
					const top = this.$root.getBoundingClientRect().top + window.scrollY - 90;
					window.scrollTo({ top: Math.max(0, top), behavior: reducedMotion ? 'auto' : 'smooth' });
				},
				reset: function () { this.values = Object.assign({}, this.defaults, { offerings: [], stock: [] }); },
				get isDirty() { return JSON.stringify(this.values) !== JSON.stringify(this.defaults); },
				get summary() {
					if (!this.total) return I18N.noResults || 'No results found.';
					const from = (this.page - 1) * this.perPage + 1;
					const to = Math.min(this.total, this.page * this.perPage);
					return SP.sprintf(I18N.showing || 'Showing %1$s–%2$s of %3$s', from, to, this.total);
				},
				get pageNumbers() {
					const n = this.pages, p = this.page, out = [];
					let from = Math.max(1, p - 2), to = Math.min(n, from + 4);
					from = Math.max(1, to - 4);
					for (let i = from; i <= to; i++) out.push(i);
					return out;
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
						const d = await SP.api(cfg.pinsEndpoint || 'farms/pins', { query: this.values });
						this.pins = d.items || [];
						this.pinsLoaded = true;
						if (this.mapObj) this.mapObj.setPins(this.pins);
					} catch (e) { SP.toast.error(e.message); }
				}
			};
		});

		/* Farm page: single-pin map, gallery, inquiry form. */
		Alpine.data('spFarmPage', function (cfg) {
			cfg = cfg || {};
			return {
				fields: { name: cfg.user ? cfg.user.name : '', email: cfg.user ? cfg.user.email : '', phone: '', message: '' },
				errors: {},
				hp: '',
				busy: false,
				sent: false,
				started: Date.now(),
				maxChars: cfg.maxChars || 2000,
				init: function () {
					const self = this;
					if (cfg.map && this.$refs.map) {
						SP.map.create(this.$refs.map, { cluster: false, zoom: cfg.map.exact ? 11 : 8, center: cfg.map }).then(function (m) {
							m.setPins([{ lat: cfg.map.lat, lng: cfg.map.lng, name: cfg.name, light: cfg.light, exact: cfg.map.exact, url: location.href, active_listings: 0 }], false);
							m.map.setZoom(cfg.map.exact ? 11 : 8);
						}).catch(function () {});
					}
					if (this.$refs.gallery) SP.gallery(this.$refs.gallery);
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
					this.errors = {};
					this.busy = true;
					try {
						const token = await this.captcha();
						const body = Object.assign({}, this.fields, { farm_id: cfg.farmId, elapsed: Math.round((Date.now() - this.started) / 1000), 'g-recaptcha-response': token, sp_website_url: this.hp });
						const r = await SP.api('inquiries/farm', { method: 'POST', body: body });
						this.sent = true;
						SP.toast.success(r.message);
					} catch (e) {
						const errs = e.data && e.data.data && e.data.data.errors;
						if (e.status === 422 && errs && typeof errs === 'object') this.errors = errs; // keyed by field (name, email, message)
						SP.toast.error(e.message);
					}
					this.busy = false;
				}
			};
		});

		/* Farm editor: logo/banner uploads, gallery manager with drag reorder, video add. */
		Alpine.data('spFarmEditor', function (cfg) {
			cfg = cfg || {};
			return {
				logo: cfg.logo || '',
				banner: cfg.banner || '',
				media: cfg.media || [],
				maxItems: cfg.maxItems || 20,
				maxMb: cfg.maxMb || 10,
				busy: { logo: false, banner: false, gallery: false, video: false },
				progress: { logo: 0, banner: 0 },
				over: null,
				saving: false,
				dragId: null,
				videoUrl: '',
				upload: async function (purpose, file) {
					const err = SP.uploader.check(file, this.maxMb);
					if (err) { SP.toast.error(err); return; }
					const self = this;
					this.busy[purpose] = true; this.progress[purpose] = 0;
					try {
						const small = await SP.uploader.shrink(file, purpose === 'banner' ? 2200 : 1600);
						const r = await SP.uploader.send('my-farm/image', small, { purpose: purpose }, function (p) { self.progress[purpose] = p; });
						this[purpose] = r.url + (r.url.indexOf('?') === -1 ? '?' : '&') + 'v=' + Date.now();
						SP.toast.success(r.message);
					} catch (e) { SP.toast.error(e.message); }
					this.busy[purpose] = false;
				},
				removeImage: async function (purpose) {
					const ok = await SP.confirm({ message: I18N.removeItem || 'Remove this item?', confirmLabel: I18N.remove || 'Remove', danger: true });
					if (!ok) return;
					try { const r = await SP.api('my-farm/image/' + purpose, { method: 'DELETE' }); this[purpose] = ''; SP.toast.success(r.message); }
					catch (e) { SP.toast.error(e.message); }
				},
				addFiles: async function (files) {
					const list = Array.prototype.slice.call(files || []);
					if (!list.length) return;
					this.busy.gallery = true;
					for (const file of list) {
						if (this.media.length >= this.maxItems) { SP.toast.warning(I18N.error || 'Gallery is full.'); break; }
						const err = SP.uploader.check(file, this.maxMb);
						if (err) { SP.toast.error(file.name + ': ' + err); continue; }
						try {
							const small = await SP.uploader.shrink(file, 2000);
							const r = await SP.uploader.send('my-farm/media', small, {});
							this.media = r.items;
						} catch (e) { SP.toast.error(file.name + ': ' + e.message); }
					}
					this.busy.gallery = false;
				},
				addVideo: async function () {
					if (!this.videoUrl) return;
					this.busy.video = true;
					try { const r = await SP.api('my-farm/media', { method: 'POST', body: { video_url: this.videoUrl } }); this.media = r.items; this.videoUrl = ''; SP.toast.success(r.message); }
					catch (e) { SP.toast.error(e.message); }
					this.busy.video = false;
				},
				saveCaption: async function (item, caption) {
					try { const r = await SP.api('my-farm/media/' + item.id, { method: 'PATCH', body: { caption: caption } }); this.media = r.items; }
					catch (e) { SP.toast.error(e.message); }
				},
				remove: async function (item) {
					const ok = await SP.confirm({ message: I18N.removeItem || 'Remove this item?', confirmLabel: I18N.remove || 'Remove', danger: true });
					if (!ok) return;
					try { const r = await SP.api('my-farm/media/' + item.id, { method: 'DELETE' }); this.media = r.items; SP.toast.success(r.message); }
					catch (e) { SP.toast.error(e.message); }
				},
				move: function (item, dir) {
					const i = this.media.indexOf(item), j = i + dir;
					if (i < 0 || j < 0 || j >= this.media.length) return;
					const copy = this.media.slice();
					copy.splice(i, 1); copy.splice(j, 0, item);
					this.media = copy;
					this.persistOrder();
				},
				dragStart: function (item) { this.dragId = item.id; },
				dragOver: function (target) {
					if (this.dragId === null || target.id === this.dragId) return;
					const from = this.media.findIndex(function (m) { return m.id === this.dragId; }, this);
					const to = this.media.indexOf(target);
					if (from < 0 || to < 0) return;
					const copy = this.media.slice();
					const moved = copy.splice(from, 1)[0];
					copy.splice(to, 0, moved);
					this.media = copy;
				},
				dragEnd: function () { if (this.dragId === null) return; this.dragId = null; this.persistOrder(); },
				persistOrder: SP.debounce(async function () {
					try { await SP.api('my-farm/media/reorder', { method: 'POST', body: { ids: this.media.map(function (m) { return m.id; }) } }); }
					catch (e) { SP.toast.error(e.message); }
				}, 400)
			};
		});
	});
})();
