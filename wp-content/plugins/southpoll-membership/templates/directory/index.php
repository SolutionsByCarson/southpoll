<?php
/**
 * Member directory: SSR first page + Alpine (spDirectory) live filters, paging and map view.
 *
 * @var string $heading
 * @var string $intro
 * @var array  $items     cards (with html)
 * @var array  $result    DirectoryQuery::run()
 * @var array  $options   FarmsController::filter_options()
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var array  $config    spDirectory config
 * @var bool   $maps_enabled
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$sp_values = $config['values'];
?>
<div class="sp-directory" x-data="spDirectory(<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>)">
	<header class="sp-page__header sp-directory__header">
		<div>
			<h1 class="sp-page__title"><?php echo esc_html( $heading ); ?></h1>
			<p class="sp-text-muted" style="margin:6px 0 0">
				<?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d member farm', '%d member farms', (int) $options['total'], 'southpoll-membership' ), (int) $options['total'] ) ); ?>
				<?php if ( '' !== $intro ) : ?> &middot; <?php echo esc_html( $intro ); ?><?php endif; ?>
			</p>
		</div>
		<div class="sp-view-toggle" role="group" aria-label="<?php esc_attr_e( 'View', 'southpoll-membership' ); ?>">
			<button type="button" :class="{ 'is-active': view === 'list' }" @click="view = 'list'" :aria-pressed="view === 'list'"><?php echo Icons::svg( 'list', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'List', 'southpoll-membership' ); ?></button>
			<button type="button" :class="{ 'is-active': view === 'map' }" @click="view = 'map'" :aria-pressed="view === 'map'"><?php echo Icons::svg( 'map', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Map', 'southpoll-membership' ); ?></button>
		</div>
	</header>

	<form class="sp-filters sp-directory__filters" role="search" @submit.prevent>
		<div class="sp-field sp-filters__search">
			<label class="sp-label" for="sp-dir-q"><?php esc_html_e( 'Search', 'southpoll-membership' ); ?></label>
			<input id="sp-dir-q" class="sp-input" type="search" x-model="values.q" placeholder="<?php esc_attr_e( 'Farm, owner, city, state…', 'southpoll-membership' ); ?>" autocomplete="off" value="<?php echo esc_attr( (string) $sp_values['q'] ); ?>">
		</div>
		<div class="sp-field">
			<label class="sp-label" for="sp-dir-state"><?php esc_html_e( 'State', 'southpoll-membership' ); ?></label>
			<select id="sp-dir-state" class="sp-select" x-model="values.state">
				<option value=""><?php esc_html_e( 'All states', 'southpoll-membership' ); ?></option>
				<?php foreach ( $options['states'] as $s ) : ?><option value="<?php echo esc_attr( $s['value'] ); ?>"<?php selected( $sp_values['state'], $s['value'] ); ?>><?php echo esc_html( $s['label'] . ' (' . $s['count'] . ')' ); ?></option><?php endforeach; ?>
			</select>
		</div>
		<div class="sp-field">
			<label class="sp-label" for="sp-dir-region"><?php esc_html_e( 'Region', 'southpoll-membership' ); ?></label>
			<select id="sp-dir-region" class="sp-select" x-model="values.region">
				<option value=""><?php esc_html_e( 'All regions', 'southpoll-membership' ); ?></option>
				<?php foreach ( $options['regions'] as $r ) : ?><option value="<?php echo esc_attr( $r['value'] ); ?>"<?php selected( $sp_values['region'], $r['value'] ); ?>><?php echo esc_html( $r['label'] . ' (' . $r['count'] . ')' ); ?></option><?php endforeach; ?>
			</select>
		</div>
		<?php if ( ! empty( $options['offerings'] ) ) : ?>
		<div class="sp-field" x-data="{ open: false }" @click.outside="open = false">
			<span class="sp-label" id="sp-dir-offerings-label"><?php esc_html_e( 'Offers', 'southpoll-membership' ); ?></span>
			<button type="button" class="sp-select sp-multiselect__btn" @click="open = !open" :aria-expanded="open" aria-labelledby="sp-dir-offerings-label" aria-controls="sp-dir-offerings"><span x-text="values.offerings.length ? values.offerings.length + ' <?php echo esc_js( __( 'selected', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Any', 'southpoll-membership' ) ); ?>'"></span></button>
			<div id="sp-dir-offerings" class="sp-multiselect__menu" x-show="open" x-transition.opacity style="display:none">
				<?php foreach ( $options['offerings'] as $o ) : ?>
					<label class="sp-check"><input type="checkbox" value="<?php echo esc_attr( $o['value'] ); ?>" x-model="values.offerings"<?php checked( in_array( $o['value'], (array) $sp_values['offerings'], true ) ); ?>><span><?php echo esc_html( $o['label'] ); ?></span></label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>
		<?php if ( ! empty( $options['stock'] ) ) : ?>
		<div class="sp-field" x-data="{ open: false }" @click.outside="open = false">
			<span class="sp-label" id="sp-dir-stock-label"><?php esc_html_e( 'Stock available', 'southpoll-membership' ); ?></span>
			<button type="button" class="sp-select sp-multiselect__btn" @click="open = !open" :aria-expanded="open" aria-labelledby="sp-dir-stock-label" aria-controls="sp-dir-stock"><span x-text="values.stock.length ? values.stock.length + ' <?php echo esc_js( __( 'selected', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Any', 'southpoll-membership' ) ); ?>'"></span></button>
			<div id="sp-dir-stock" class="sp-multiselect__menu" x-show="open" x-transition.opacity style="display:none">
				<?php foreach ( $options['stock'] as $o ) : ?>
					<label class="sp-check"><input type="checkbox" value="<?php echo esc_attr( $o['value'] ); ?>" x-model="values.stock"><span><?php echo esc_html( $o['label'] ); ?></span></label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>
		<div class="sp-field">
			<label class="sp-label" for="sp-dir-since"><?php esc_html_e( 'Published in', 'southpoll-membership' ); ?></label>
			<select id="sp-dir-since" class="sp-select" x-model="values.since">
				<option value=""><?php esc_html_e( 'Any time', 'southpoll-membership' ); ?></option>
				<?php foreach ( $options['since'] as $s ) : ?><option value="<?php echo esc_attr( $s['value'] ); ?>"<?php selected( $sp_values['since'], $s['value'] ); ?>><?php echo esc_html( $s['label'] ); ?></option><?php endforeach; ?>
			</select>
		</div>
		<div class="sp-field">
			<label class="sp-label" for="sp-dir-sort"><?php esc_html_e( 'Sort', 'southpoll-membership' ); ?></label>
			<select id="sp-dir-sort" class="sp-select" x-model="values.sort">
				<?php foreach ( $options['sorts'] as $s ) : ?><option value="<?php echo esc_attr( $s['value'] ); ?>"<?php selected( $sp_values['sort'], $s['value'] ); ?>><?php echo esc_html( $s['label'] ); ?></option><?php endforeach; ?>
			</select>
		</div>
		<div class="sp-field sp-filters__toggle">
			<label class="sp-toggle"><input type="checkbox" :checked="values.has_listings === '1'" @change="values.has_listings = $event.target.checked ? '1' : ''"<?php checked( '1' === (string) $sp_values['has_listings'] ); ?>><span class="sp-toggle__track"></span><span><?php esc_html_e( 'Has active listings', 'southpoll-membership' ); ?></span></label>
		</div>
	</form>

	<div class="sp-results-bar" aria-live="polite">
		<span class="sp-text-muted sp-text-small" x-text="summary"><?php echo esc_html( $result['total'] ? sprintf( /* translators: 1: from, 2: to, 3: total */ __( 'Showing %1$s–%2$s of %3$s', 'southpoll-membership' ), ( $result['page'] - 1 ) * $result['per_page'] + 1, min( $result['total'], $result['page'] * $result['per_page'] ), $result['total'] ) : __( 'No results found.', 'southpoll-membership' ) ); ?></span>
		<span class="sp-text-muted sp-text-small" x-show="loading" style="display:none"><?php esc_html_e( 'Updating…', 'southpoll-membership' ); ?></span>
		<button type="button" class="sp-btn sp-btn--outline sp-btn--sm sp-results-bar__reset" x-show="isDirty" x-cloak @click="reset()"><?php esc_html_e( 'Reset filters', 'southpoll-membership' ); ?> &times;</button>
	</div>

	<div x-show="view === 'list'">
		<div class="sp-farm-grid" :class="{ 'sp-loading': loading }" x-show="!hydrated">
			<?php foreach ( $items as $item ) : ?>
				<?php echo $item['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card template escapes ?>
			<?php endforeach; ?>
		</div>
		<div class="sp-farm-grid" :class="{ 'sp-loading': loading }" x-show="hydrated" style="display:none">
			<template x-for="item in items" :key="item.id"><div class="sp-farm-grid__cell" x-html="item.html"></div></template>
		</div>
		<div class="sp-empty" x-show="hydrated && !loading && !items.length" style="display:none">
			<?php esc_html_e( 'No member farms match those filters.', 'southpoll-membership' ); ?><br>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" @click="reset()"><?php esc_html_e( 'Clear filters', 'southpoll-membership' ); ?></button>
		</div>
		<?php if ( empty( $items ) ) : ?>
			<div class="sp-empty" x-show="!hydrated"><?php esc_html_e( 'No member farms match those filters.', 'southpoll-membership' ); ?></div>
		<?php endif; ?>
		<nav class="sp-pagination" x-show="pages > 1" aria-label="<?php esc_attr_e( 'Pagination', 'southpoll-membership' ); ?>"<?php echo $result['pages'] > 1 ? '' : ' style="display:none"'; ?>>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="page <= 1" @click="goTo(page - 1)"><?php esc_html_e( 'Previous', 'southpoll-membership' ); ?></button>
			<div class="sp-pagination__pages">
				<template x-for="p in pageNumbers" :key="p"><button type="button" class="sp-btn sp-btn--sm" :class="p === page ? 'sp-btn--primary' : 'sp-btn--ghost'" @click="goTo(p)" x-text="p" :aria-current="p === page ? 'page' : null"></button></template>
			</div>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="page >= pages" @click="goTo(page + 1)"><?php esc_html_e( 'Next', 'southpoll-membership' ); ?></button>
		</nav>
		<?php if ( $result['pages'] > 1 ) : ?>
			<noscript><p><a href="<?php echo esc_url( $urls->home( $urls->base( 'members' ) . '/page/' . ( $result['page'] + 1 ) . '/' ) ); ?>"><?php esc_html_e( 'Next page', 'southpoll-membership' ); ?></a></p></noscript>
		<?php endif; ?>
	</div>

	<div x-show="view === 'map'" style="display:none">
		<?php if ( $maps_enabled ) : ?>
			<div class="sp-map sp-directory__map" x-ref="map" role="region" aria-label="<?php esc_attr_e( 'Map of member farms', 'southpoll-membership' ); ?>"></div>
			<p class="sp-text-muted sp-text-small" style="margin-top:8px" x-show="pinsLoaded" x-text="pins.length + ' <?php echo esc_js( __( 'farms on the map. Farms without a mappable address are listed in the list view.', 'southpoll-membership' ) ); ?>'"></p>
			<?php if ( ! $context->can( 'view_contact_info' ) ) : ?><p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Pins show approximate locations. Log in to see exact locations and contact details.', 'southpoll-membership' ); ?></p><?php endif; ?>
		<?php else : ?>
			<div class="sp-empty"><?php esc_html_e( 'The map view will be available once the office connects Google Maps.', 'southpoll-membership' ); ?></div>
		<?php endif; ?>
	</div>
</div>
