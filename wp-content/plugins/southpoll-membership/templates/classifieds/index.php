<?php
/**
 * Classifieds archive / category archive / shared search: SSR first page + Alpine (spClassifieds).
 *
 * @var string $heading
 * @var string $intro
 * @var object|null $category
 * @var array  $categories
 * @var array  $items
 * @var array  $result
 * @var array  $options
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var array|null $notice   default|saved|shared search notice
 * @var bool   $maps_enabled
 * @var array  $config
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$v = $config['values'];
?>
<div class="sp-classifieds" x-data="spClassifieds(<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>)">
	<header class="sp-page__header sp-directory__header">
		<div>
			<?php if ( $category ) : ?><p class="sp-eyebrow"><a href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Classifieds', 'southpoll-membership' ); ?></a></p><?php endif; ?>
			<h1 class="sp-page__title"><?php echo esc_html( $heading ); ?></h1>
			<p class="sp-text-muted" style="margin:6px 0 0">
				<?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d active listing', '%d active listings', (int) $options['total'], 'southpoll-membership' ), (int) $options['total'] ) ); ?>
				<?php if ( '' !== $intro ) : ?> &middot; <?php echo esc_html( $intro ); ?><?php endif; ?>
			</p>
		</div>
		<div class="sp-cluster">
			<button type="button" class="sp-btn sp-btn--secondary sp-btn--sm" @click="saveSearch()" :class="{ 'is-loading': saving }"><?php echo Icons::svg( 'bell', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Save this search', 'southpoll-membership' ); ?></button>
			<div class="sp-view-toggle" role="group" aria-label="<?php esc_attr_e( 'View', 'southpoll-membership' ); ?>">
				<button type="button" :class="{ 'is-active': view === 'list' }" @click="view = 'list'" :aria-pressed="view === 'list'"><?php echo Icons::svg( 'list', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'List', 'southpoll-membership' ); ?></button>
				<button type="button" :class="{ 'is-active': view === 'map' }" @click="view = 'map'" :aria-pressed="view === 'map'"><?php echo Icons::svg( 'map', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Map', 'southpoll-membership' ); ?></button>
			</div>
		</div>
	</header>


	<?php if ( ! empty( $saved_searches ) ) : ?>
		<div class="sp-saved-bar">
			<label class="sp-saved-bar__label" for="sp-cl-saved"><?php esc_html_e( 'Your saved searches', 'southpoll-membership' ); ?></label>
			<select id="sp-cl-saved" class="sp-select sp-saved-bar__select" x-model="savedId" @change="applySaved($event.target.value)">
				<option value=""><?php esc_html_e( 'Quick-apply a saved search…', 'southpoll-membership' ); ?></option>
				<?php foreach ( $saved_searches as $ss ) : ?>
					<option value="<?php echo (int) $ss['id']; ?>"<?php selected( $notice && 'saved' === $notice['type'] && (int) ( $notice['id'] ?? 0 ) === (int) $ss['id'] ); ?>><?php echo esc_html( $ss['name'] . ( $ss['summary'] && $ss['summary'] !== $ss['name'] ? ' (' . $ss['summary'] . ')' : '' ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<a class="sp-saved-bar__manage" href="<?php echo esc_url( $urls->saved_searches() ); ?>"><?php esc_html_e( 'View / manage saved searches', 'southpoll-membership' ); ?> &rarr;</a>
		</div>
	<?php endif; ?>

	<form class="sp-filters sp-classifieds__filters" role="search" @submit.prevent>
		<div class="sp-field sp-filters__search">
			<label class="sp-label" for="sp-cl-q"><?php esc_html_e( 'Search', 'southpoll-membership' ); ?></label>
			<input id="sp-cl-q" class="sp-input" type="search" x-model="values.q" placeholder="<?php esc_attr_e( 'Title, description, farm, seller…', 'southpoll-membership' ); ?>" autocomplete="off" value="<?php echo esc_attr( (string) $v['q'] ); ?>">
		</div>
		<?php if ( ! $category ) : ?>
		<div class="sp-field" x-data="{ open: false }" @click.outside="open = false">
			<span class="sp-label" id="sp-cl-cats-label"><?php esc_html_e( 'Categories', 'southpoll-membership' ); ?></span>
			<button type="button" class="sp-select sp-multiselect__btn" @click="open = !open" :aria-expanded="open" aria-labelledby="sp-cl-cats-label" aria-controls="sp-cl-cats"><span x-text="values.cats.length ? values.cats.length + ' <?php echo esc_js( __( 'selected', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'All categories', 'southpoll-membership' ) ); ?>'"></span></button>
			<div id="sp-cl-cats" class="sp-multiselect__menu" x-show="open" x-transition.opacity style="display:none">
				<?php foreach ( $facets['categories'] as $o ) : ?>
					<label class="sp-check" :class="{ 'is-zero': !facetCount('categories', '<?php echo esc_js( $o['value'] ); ?>') && !values.cats.includes('<?php echo esc_js( $o['value'] ); ?>') }"><input type="checkbox" value="<?php echo esc_attr( $o['value'] ); ?>" x-model="values.cats"<?php checked( in_array( $o['value'], (array) $v['cats'], true ) ); ?>><span x-text="facetLabel('categories', '<?php echo esc_js( $o['value'] ); ?>', '<?php echo esc_js( $o['label'] ); ?>')"><?php echo esc_html( $o['label'] . ' (' . $o['count'] . ')' ); ?></span></label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>
		<div class="sp-field">
			<label class="sp-label" for="sp-cl-state"><?php esc_html_e( 'State', 'southpoll-membership' ); ?></label>
			<select id="sp-cl-state" class="sp-select" x-model="values.state">
				<option value=""><?php esc_html_e( 'All states', 'southpoll-membership' ); ?></option>
				<?php foreach ( $options['states'] as $s ) : ?><option value="<?php echo esc_attr( $s['value'] ); ?>"<?php selected( $v['state'], $s['value'] ); ?> x-text="facetLabel('states', '<?php echo esc_js( $s['value'] ); ?>', '<?php echo esc_js( $s['label'] ); ?>')" :disabled="!facetCount('states', '<?php echo esc_js( $s['value'] ); ?>') && values.state !== '<?php echo esc_js( $s['value'] ); ?>'"><?php echo esc_html( $s['label'] . ' (' . ( $sp_fc = array_values( array_filter( $facets['states'], static fn( $x ) => $x['value'] === $s['value'] ) ) ) . ( $sp_fc[0]['count'] ?? 0 ) . ')' ); ?></option><?php endforeach; ?>
			</select>
		</div>
		<div class="sp-field">
			<label class="sp-label" for="sp-cl-region"><?php esc_html_e( 'Region', 'southpoll-membership' ); ?></label>
			<select id="sp-cl-region" class="sp-select" x-model="values.region">
				<option value=""><?php esc_html_e( 'All regions', 'southpoll-membership' ); ?></option>
				<?php foreach ( $facets['regions'] as $r ) : ?><option value="<?php echo esc_attr( $r['value'] ); ?>"<?php selected( $v['region'], $r['value'] ); ?> x-text="facetLabel('regions', '<?php echo esc_js( $r['value'] ); ?>', '<?php echo esc_js( $r['label'] ); ?>')" :disabled="!facetCount('regions', '<?php echo esc_js( $r['value'] ); ?>') && values.region !== '<?php echo esc_js( $r['value'] ); ?>'"><?php echo esc_html( $r['label'] . ' (' . $r['count'] . ')' ); ?></option><?php endforeach; ?>
			</select>
		</div>
		<div class="sp-field" x-data="{ open: false }" @click.outside="open = false">
			<span class="sp-label" id="sp-cl-farms-label"><?php esc_html_e( 'Farm', 'southpoll-membership' ); ?></span>
			<button type="button" class="sp-select sp-multiselect__btn" @click="open = !open; $nextTick(() => open && $refs.farmQ.focus())" :aria-expanded="open" aria-labelledby="sp-cl-farms-label" aria-controls="sp-cl-farms"><span x-text="values.farms.length ? values.farms.length + ' <?php echo esc_js( __( 'selected', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Any farm', 'southpoll-membership' ) ); ?>'"></span></button>
			<div id="sp-cl-farms" class="sp-multiselect__menu sp-multiselect__menu--wide" x-show="open" x-transition.opacity style="display:none">
				<input type="search" class="sp-input sp-input--sm" x-ref="farmQ" x-model="farmQuery" placeholder="<?php esc_attr_e( 'Type to filter farms…', 'southpoll-membership' ); ?>" aria-label="<?php esc_attr_e( 'Filter farms', 'southpoll-membership' ); ?>">
				<template x-for="f in filteredFarms" :key="f.value">
					<label class="sp-check"><input type="checkbox" :value="String(f.value)" x-model="values.farms"><span x-text="f.label + ' (' + f.count + ')'"></span></label>
				</template>
				<p class="sp-help" x-show="!filteredFarms.length"><?php esc_html_e( 'No farms match.', 'southpoll-membership' ); ?></p>
			</div>
		</div>
		<div class="sp-field">
			<label class="sp-label" for="sp-cl-since"><?php esc_html_e( 'Published', 'southpoll-membership' ); ?></label>
			<select id="sp-cl-since" class="sp-select" x-model="values.since">
				<option value=""><?php esc_html_e( 'Any time', 'southpoll-membership' ); ?></option>
				<?php foreach ( $options['since'] as $s ) : ?><option value="<?php echo esc_attr( $s['value'] ); ?>"<?php selected( $v['since'], $s['value'] ); ?>><?php echo esc_html( $s['label'] ); ?></option><?php endforeach; ?>
			</select>
		</div>
		<div class="sp-field">
			<label class="sp-label" for="sp-cl-sort"><?php esc_html_e( 'Sort', 'southpoll-membership' ); ?></label>
			<select id="sp-cl-sort" class="sp-select" x-model="values.sort">
				<?php foreach ( $options['sorts'] as $s ) : ?><option value="<?php echo esc_attr( $s['value'] ); ?>"<?php selected( $v['sort'], $s['value'] ); ?>><?php echo esc_html( $s['label'] ); ?></option><?php endforeach; ?>
			</select>
		</div>
	</form>

	<div class="sp-results-bar" aria-live="polite">
		<span class="sp-results-bar__summary"><span class="sp-text-muted sp-text-small" x-text="summary"><?php echo esc_html( $result['total'] ? sprintf( /* translators: 1: from, 2: to, 3: total */ __( 'Showing %1$s–%2$s of %3$s', 'southpoll-membership' ), ( $result['page'] - 1 ) * $result['per_page'] + 1, min( $result['total'], $result['page'] * $result['per_page'] ), $result['total'] ) : __( 'No results found.', 'southpoll-membership' ) ); ?></span>
		<span class="sp-text-muted sp-text-small" x-show="loading" style="display:none"><?php esc_html_e( 'Updating…', 'southpoll-membership' ); ?></span></span>
		<button type="button" class="sp-btn sp-btn--outline sp-btn--sm sp-results-bar__reset" x-show="isDirty" x-cloak @click="reset()"><?php esc_html_e( 'Reset filters', 'southpoll-membership' ); ?> &times;</button>
	</div>

	<div x-show="view === 'list'">
		<div class="sp-listing-list" :class="{ 'sp-loading': loading }" x-show="!hydrated">
			<?php foreach ( $items as $item ) : ?>
				<?php echo $item['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>
		</div>
		<div class="sp-listing-list" :class="{ 'sp-loading': loading }" x-show="hydrated" style="display:none">
			<template x-for="item in items" :key="item.id"><div class="sp-farm-grid__cell" x-html="item.html"></div></template>
		</div>
		<div class="sp-empty" x-show="hydrated && !loading && !items.length" style="display:none">
			<?php esc_html_e( 'No listings match those filters.', 'southpoll-membership' ); ?><br>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" @click="reset()"><?php esc_html_e( 'Clear filters', 'southpoll-membership' ); ?></button>
		</div>
		<?php if ( empty( $items ) ) : ?><div class="sp-empty" x-show="!hydrated"><?php esc_html_e( 'No listings match those filters.', 'southpoll-membership' ); ?></div><?php endif; ?>
		<nav class="sp-pagination" x-show="pages > 1" aria-label="<?php esc_attr_e( 'Pagination', 'southpoll-membership' ); ?>"<?php echo $result['pages'] > 1 ? '' : ' style="display:none"'; ?>>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="page <= 1" @click="goTo(page - 1)"><?php esc_html_e( 'Previous', 'southpoll-membership' ); ?></button>
			<div class="sp-pagination__pages"><template x-for="p in pageNumbers" :key="p"><button type="button" class="sp-btn sp-btn--sm" :class="p === page ? 'sp-btn--primary' : 'sp-btn--ghost'" @click="goTo(p)" x-text="p" :aria-current="p === page ? 'page' : null"></button></template></div>
			<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" :disabled="page >= pages" @click="goTo(page + 1)"><?php esc_html_e( 'Next', 'southpoll-membership' ); ?></button>
		</nav>
	</div>

	<div x-show="view === 'map'" style="display:none">
		<?php if ( $maps_enabled ) : ?>
			<div class="sp-map sp-directory__map" x-ref="map" role="region" aria-label="<?php esc_attr_e( 'Map of listings by farm', 'southpoll-membership' ); ?>"></div>
			<p class="sp-text-muted sp-text-small" style="margin-top:8px" x-show="pinsLoaded" x-text="pins.length + ' <?php echo esc_js( __( 'farms with matching listings. Click a pin to see their listings.', 'southpoll-membership' ) ); ?>'"></p>
		<?php else : ?>
			<div class="sp-empty"><?php esc_html_e( 'The map view will be available once the office connects Google Maps.', 'southpoll-membership' ); ?></div>
		<?php endif; ?>
	</div>
</div>
