<?php
/**
 * UI kit showcase (staff only) — exercises every shared component so visual QA is one page.
 *
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;

$sp_rows = [
	[ 'id' => 1, 'farm' => 'Bulmanski Farms', 'owner' => 'Brady Bulmanski', 'state' => 'MO', 'listings' => 3, 'status' => 'active' ],
	[ 'id' => 2, 'farm' => 'Shawn Barber Farms', 'owner' => 'Shawn Barber', 'state' => 'AL', 'listings' => 0, 'status' => 'active' ],
	[ 'id' => 3, 'farm' => 'Sweetwater Ranch', 'owner' => 'Kathy Smith', 'state' => 'FL', 'listings' => 1, 'status' => 'expired' ],
	[ 'id' => 4, 'farm' => 'Whitehead Road Cattle', 'owner' => 'Robert Davis', 'state' => 'VA', 'listings' => 5, 'status' => 'active' ],
	[ 'id' => 5, 'farm' => 'Lemons Road Farm', 'owner' => 'Josie Davis', 'state' => 'IN', 'listings' => 2, 'status' => 'pending' ],
	[ 'id' => 6, 'farm' => 'Prescott Grass Cattle', 'owner' => 'Marty Davis', 'state' => 'AR', 'listings' => 0, 'status' => 'active' ],
	[ 'id' => 7, 'farm' => 'Corsica Hill Farm', 'owner' => 'Ann Lee', 'state' => 'PA', 'listings' => 4, 'status' => 'active' ],
	[ 'id' => 8, 'farm' => 'Goodman Pastures', 'owner' => 'Lee Goodman', 'state' => 'MS', 'listings' => 1, 'status' => 'expired' ],
	[ 'id' => 9, 'farm' => 'Salina Stock Farm', 'owner' => 'Dana Hall', 'state' => 'KS', 'listings' => 2, 'status' => 'active' ],
	[ 'id' => 10, 'farm' => 'West Plains Cattle Co.', 'owner' => 'Tom Porter', 'state' => 'MO', 'listings' => 6, 'status' => 'active' ],
	[ 'id' => 11, 'farm' => 'Alpena Ridge', 'owner' => 'Sue Park', 'state' => 'AR', 'listings' => 0, 'status' => 'active' ],
	[ 'id' => 12, 'farm' => 'Eubank Family Farm', 'owner' => 'Jim Eubank', 'state' => 'KY', 'listings' => 1, 'status' => 'active' ],
];
?>
<section class="sp-section">
	<h2 class="sp-h2"><?php esc_html_e( 'Typography', 'southpoll-membership' ); ?></h2>
	<h1 class="sp-h1">Heading one — Oswald 600</h1>
	<h2 class="sp-h2">Heading two</h2>
	<h3 class="sp-h3">Heading three</h3>
	<p>Body copy in Montserrat 400. <a href="#">A link</a>, <strong>bold</strong>, <em>italic</em>, and <code>code</code>. <span class="sp-text-muted">Muted text.</span></p>
	<p class="sp-eyebrow">Eyebrow label</p>
</section>

<section class="sp-section">
	<h2 class="sp-h2"><?php esc_html_e( 'Buttons', 'southpoll-membership' ); ?></h2>
	<div class="sp-cluster">
		<button class="sp-btn sp-btn--primary">Primary</button>
		<button class="sp-btn sp-btn--secondary">Secondary</button>
		<button class="sp-btn sp-btn--outline">Outline</button>
		<button class="sp-btn sp-btn--ghost">Ghost</button>
		<button class="sp-btn sp-btn--danger">Danger</button>
		<button class="sp-btn sp-btn--primary sp-btn--sm">Small</button>
		<button class="sp-btn sp-btn--primary sp-btn--lg">Large</button>
		<button class="sp-btn sp-btn--primary" disabled>Disabled</button>
		<button class="sp-btn sp-btn--primary is-loading">Loading</button>
	</div>
</section>

<section class="sp-section">
	<h2 class="sp-h2"><?php esc_html_e( 'Badges, lights, pills', 'southpoll-membership' ); ?></h2>
	<div class="sp-cluster">
		<span class="sp-badge">Default</span>
		<span class="sp-badge sp-badge--new">New listing</span>
		<span class="sp-badge sp-badge--success">Active</span>
		<span class="sp-badge sp-badge--warning">Expiring</span>
		<span class="sp-badge sp-badge--danger">Expired</span>
		<span class="sp-badge sp-badge--muted">Pending</span>
		<span class="sp-light sp-light--on" title="Has active listings"></span> <span class="sp-light sp-light--off" title="No active listings"></span>
		<span class="sp-pill">Bulls</span><span class="sp-pill">Females</span><span class="sp-pill sp-pill--active">Semen</span>
	</div>
	<div class="sp-media-card" style="max-width:260px;margin-top:16px">
		<div class="sp-media-card__image sp-ratio-4x3" style="background:#e3f0fc"></div>
		<span class="sp-badge sp-badge--new sp-badge--float">New listing</span>
		<div class="sp-media-card__body"><strong>Card with floating badge</strong><div class="sp-text-muted">Top-right badge over the image.</div></div>
	</div>
</section>

<section class="sp-section">
	<h2 class="sp-h2"><?php esc_html_e( 'Forms', 'southpoll-membership' ); ?></h2>
	<form class="sp-form" onsubmit="return false">
		<div class="sp-form__row sp-form__row--2">
			<div class="sp-field"><label class="sp-label" for="k-text">Text <span class="sp-req">*</span></label><input id="k-text" class="sp-input" type="text" placeholder="Farm name"><div class="sp-help">Helper text under the field.</div></div>
			<div class="sp-field sp-field--error"><label class="sp-label" for="k-email">Email</label><input id="k-email" class="sp-input" type="email" value="not-an-email"><div class="sp-error">Please enter a valid email address.</div></div>
		</div>
		<div class="sp-form__row sp-form__row--3">
			<div class="sp-field"><label class="sp-label" for="k-select">Select</label><select id="k-select" class="sp-select"><option>Missouri</option><option>Virginia</option></select></div>
			<div class="sp-field"><label class="sp-label">Checkbox</label><label class="sp-check"><input type="checkbox" checked> <span>Cool season</span></label></div>
			<div class="sp-field"><label class="sp-label">Toggle</label><label class="sp-toggle"><input type="checkbox" checked><span class="sp-toggle__track"></span><span>Email me new listings</span></label></div>
		</div>
		<div class="sp-field"><label class="sp-label" for="k-area">Textarea</label><textarea id="k-area" class="sp-textarea" rows="3" placeholder="About the farm"></textarea></div>
		<div class="sp-form__actions"><button class="sp-btn sp-btn--primary">Save changes</button><button class="sp-btn sp-btn--ghost">Cancel</button></div>
	</form>
</section>

<section class="sp-section">
	<h2 class="sp-h2"><?php esc_html_e( 'Toasts, modal, share', 'southpoll-membership' ); ?></h2>
	<div class="sp-cluster">
		<button class="sp-btn sp-btn--outline" onclick="SP.toast.success('Added to your favorites.', { title: 'Saved', actions: [{ label: 'View favorites', href: SP_CONFIG.urls.favorites }] })">Success toast</button>
		<button class="sp-btn sp-btn--outline" onclick="SP.toast.info('You can get an email whenever this farm posts a new listing.')">Info toast</button>
		<button class="sp-btn sp-btn--outline" onclick="SP.toast.error('Something went wrong. Please try again.')">Error toast</button>
		<button class="sp-btn sp-btn--outline" onclick="SP.modal({ title: 'A modal', html: '<p>Modal body content. Press Escape or click outside to close.</p>', footer: '<button class=&quot;sp-btn sp-btn--primary&quot; data-modal-close>Done</button>' })">Modal</button>
		<button class="sp-btn sp-btn--outline" onclick="SP.confirm({ title: 'Remove favorite?', message: 'This farm will be removed from your favorites.' }).then(ok => SP.toast.info(ok ? 'Confirmed' : 'Cancelled'))">Confirm</button>
		<button class="sp-btn sp-btn--secondary" onclick="SP.share({ title: 'Bulmanski Farms on SouthPoll.com', text: 'Check out this South Poll farm:', url: location.href })">Share</button>
	</div>
</section>

<section class="sp-section">
	<h2 class="sp-h2"><?php esc_html_e( 'Count-up stats', 'southpoll-membership' ); ?></h2>
	<div class="sp-grid sp-grid--stats">
		<div class="sp-stat"><span class="sp-stat__value" data-countup="482">0</span><span class="sp-stat__label">Active members</span></div>
		<div class="sp-stat"><span class="sp-stat__value" data-countup="30301" data-countup-prefix="$">0</span><span class="sp-stat__label">Revenue</span></div>
		<div class="sp-stat sp-stat--accent"><span class="sp-stat__value" data-countup="91">0</span><span class="sp-stat__label">Listings</span></div>
		<div class="sp-stat"><span class="sp-stat__value" data-countup="12.5" data-countup-decimals="1" data-countup-suffix="%">0</span><span class="sp-stat__label">Growth</span></div>
	</div>
</section>

<section class="sp-section" x-data="spTable({ rows: <?php echo esc_attr( (string) wp_json_encode( $sp_rows ) ); ?>, columns: [ { key: 'farm', label: 'Farm' }, { key: 'owner', label: 'Owner' }, { key: 'state', label: 'State' }, { key: 'listings', label: 'Listings', type: 'number' }, { key: 'status', label: 'Status' } ], perPage: 5 })">
	<h2 class="sp-h2"><?php esc_html_e( 'Live table (client mode)', 'southpoll-membership' ); ?></h2>
	<div class="sp-table-tools">
		<label class="sp-table-search"><span class="sp-sr-only">Search</span><input class="sp-input" type="search" x-model.debounce.200ms="query" placeholder="Search all fields…"></label>
		<label class="sp-table-perpage"><select class="sp-select" x-model.number="perPage" @change="page = 1"><template x-for="n in perPageOptions" :key="n"><option :value="n === 'all' ? 100000 : n" x-text="n === 'all' ? 'All' : n"></option></template></select> <span class="sp-text-muted">per page</span></label>
	</div>
	<div class="sp-table-wrap">
		<table class="sp-table">
			<thead><tr><template x-for="col in columns" :key="col.key"><th :class="{ 'is-sorted': sortKey === col.key }" @click="setSort(col.key)" scope="col"><button type="button" class="sp-table__sort" x-text="col.label"></button><span class="sp-table__dir" x-text="sortKey === col.key ? (sortDir === 'asc' ? '▲' : '▼') : ''"></span></th></template></tr></thead>
			<tbody>
				<template x-for="row in paged" :key="row.id">
					<tr><td x-text="row.farm"></td><td x-text="row.owner"></td><td x-text="row.state"></td><td x-text="row.listings"></td><td><span class="sp-badge" :class="{ 'sp-badge--success': row.status === 'active', 'sp-badge--danger': row.status === 'expired', 'sp-badge--muted': row.status === 'pending' }" x-text="row.status"></span></td></tr>
				</template>
				<tr x-show="!paged.length"><td :colspan="columns.length" class="sp-text-muted">No results found.</td></tr>
			</tbody>
		</table>
	</div>
	<div class="sp-pagination">
		<span class="sp-text-muted" x-text="summary"></span>
		<div class="sp-pagination__pages">
			<button class="sp-btn sp-btn--ghost sp-btn--sm" @click="goTo(page - 1)" :disabled="page <= 1">‹</button>
			<template x-for="p in pageNumbers" :key="p"><button class="sp-btn sp-btn--sm" :class="p === page ? 'sp-btn--primary' : 'sp-btn--ghost'" @click="goTo(p)" x-text="p"></button></template>
			<button class="sp-btn sp-btn--ghost sp-btn--sm" @click="goTo(page + 1)" :disabled="page >= pageCount">›</button>
		</div>
	</div>
</section>

<section class="sp-section">
	<h2 class="sp-h2"><?php esc_html_e( 'Alerts & banners', 'southpoll-membership' ); ?></h2>
	<div class="sp-banner sp-banner--membership"><div><strong>Your membership expires in 7 days.</strong><div>Renew now to keep your farm page and listings live.</div></div><a class="sp-btn sp-btn--secondary" href="#">Renew membership</a></div>
	<div class="sp-alert sp-alert--info">Informational alert.</div>
	<div class="sp-alert sp-alert--success">Success alert.</div>
	<div class="sp-alert sp-alert--warning">Warning alert.</div>
	<div class="sp-alert sp-alert--error">Error alert.</div>
</section>
