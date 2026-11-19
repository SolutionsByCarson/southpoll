<?php
/**
 * Admin → SouthPoll → Taxonomies: offerings (directory filter) and listing categories with display order and SEO
 * fields. Data: /sp/v1/admin/taxonomies/{tax}.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Farms\Offerings;
use SouthPoll\Membership\Listings\ListingTypes;

final class TaxonomiesPage extends AdminPage {

	public const SLUG = 'southpoll-taxonomies';

	public function render(): void {
		$this->guard();
		$tax    = self::get( 'tax' );
		$tax    = Offerings::TAXONOMY === $tax ? Offerings::TAXONOMY : ListingTypes::TAXONOMY;
		$is_cat = ListingTypes::TAXONOMY === $tax;
		$this->open( __( 'Taxonomies', 'southpoll-membership' ), 'spAdminTaxonomies', [ 'tax' => $tax, 'seo' => $is_cat ] );
		$this->tabs( [ ListingTypes::TAXONOMY => __( 'Listing categories', 'southpoll-membership' ), Offerings::TAXONOMY => __( 'Farm offerings', 'southpoll-membership' ) ], $tax, 'tax' );
		?>
		<p class="description sp-admin__hint"><?php echo $is_cat ? esc_html__( 'Categories group classified listings (/classifieds/{slug}/). The order here is the order shown in filters and the editor. Deleting a category keeps its listings; they just lose that category.', 'southpoll-membership' ) : esc_html__( 'Offerings are the "what we sell" checkboxes on a farm page and the matching directory filter. Deleting one removes it from every farm.', 'southpoll-membership' ); ?></p>
		<div class="sp-table-wrap"><table class="sp-table sp-admin__table">
			<thead><tr><th style="width:70px"><?php esc_html_e( 'Order', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Name', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Slug', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Description', 'southpoll-membership' ); ?></th><?php if ( $is_cat ) : ?><th><?php esc_html_e( 'SEO title', 'southpoll-membership' ); ?></th><?php endif; ?><th><?php esc_html_e( 'In use', 'southpoll-membership' ); ?></th><th><span class="sp-sr-only"><?php esc_html_e( 'Actions', 'southpoll-membership' ); ?></span></th></tr></thead>
			<tbody>
				<template x-if="loading"><tr><td colspan="7" class="sp-admin__state sp-loading"><?php esc_html_e( 'Loading…', 'southpoll-membership' ); ?></td></tr></template>
				<template x-if="!loading && !items.length"><tr><td colspan="7" class="sp-admin__state sp-empty"><?php esc_html_e( 'Nothing here yet.', 'southpoll-membership' ); ?></td></tr></template>
				<template x-for="(t, i) in items" :key="t.id">
					<tr :class="{ 'is-open': editing === t.id }">
						<td class="sp-admin__order"><button type="button" class="sp-icon-btn" :disabled="i === 0 || busy" @click="move(t, -1)" aria-label="<?php esc_attr_e( 'Move up', 'southpoll-membership' ); ?>">▲</button><button type="button" class="sp-icon-btn" :disabled="i === items.length - 1 || busy" @click="move(t, 1)" aria-label="<?php esc_attr_e( 'Move down', 'southpoll-membership' ); ?>">▼</button></td>
						<td><a :href="t.url" target="_blank" rel="noopener" class="sp-admin__primary" x-text="t.name"></a></td>
						<td><code x-text="t.slug"></code></td>
						<td class="sp-admin__notes" x-text="t.description"></td>
						<?php if ( $is_cat ) : ?><td class="sp-admin__notes"><span x-text="t.seo_title"></span><div class="sp-text-small sp-text-muted" x-text="t.seo_description"></div></td><?php endif; ?>
						<td x-text="t.count"></td>
						<td class="sp-admin__rowactions"><button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="edit(t)"><?php esc_html_e( 'Edit', 'southpoll-membership' ); ?></button> <button type="button" class="sp-btn sp-btn--ghost sp-btn--sm sp-admin__danger" @click="remove(t)"><?php esc_html_e( 'Delete', 'southpoll-membership' ); ?></button></td>
					</tr>
				</template>
			</tbody>
		</table></div>
		<p><button type="button" class="sp-btn sp-btn--outline sp-btn--sm" @click="add()" x-show="!adding"><?php echo $is_cat ? esc_html__( 'Add a category', 'southpoll-membership' ) : esc_html__( 'Add an offering', 'southpoll-membership' ); ?></button></p>
		<form class="sp-card sp-admin__form" x-show="editing || adding" x-cloak @submit.prevent="save()">
			<h2 class="sp-card__title" x-text="adding ? '<?php echo esc_js( __( 'New term', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Edit term', 'southpoll-membership' ) ); ?>'"></h2>
			<div class="sp-form__row sp-form__row--2">
				<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></span><input class="sp-input" x-model="form.name" required></label>
				<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Slug', 'southpoll-membership' ); ?></span><input class="sp-input" x-model="form.slug" placeholder="<?php esc_attr_e( 'auto from name', 'southpoll-membership' ); ?>"><?php if ( $is_cat ) : ?><span class="sp-help"><?php esc_html_e( 'Renaming a slug changes the category URL; "listing" and "page" are reserved.', 'southpoll-membership' ); ?></span><?php endif; ?></label>
			</div>
			<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'Description', 'southpoll-membership' ); ?></span><textarea class="sp-textarea" rows="2" x-model="form.description"></textarea></label>
			<?php if ( $is_cat ) : ?>
				<div class="sp-form__row sp-form__row--2">
					<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'SEO title', 'southpoll-membership' ); ?></span><input class="sp-input" x-model="form.seo_title" maxlength="70"></label>
					<label class="sp-field"><span class="sp-label"><?php esc_html_e( 'SEO description', 'southpoll-membership' ); ?></span><input class="sp-input" x-model="form.seo_description" maxlength="160"></label>
				</div>
			<?php endif; ?>
			<div class="sp-form__actions"><button class="sp-btn sp-btn--primary" :disabled="busy"><?php esc_html_e( 'Save', 'southpoll-membership' ); ?></button> <button type="button" class="sp-btn sp-btn--ghost" @click="cancel()"><?php esc_html_e( 'Cancel', 'southpoll-membership' ); ?></button></div>
		</form>
		<?php
		$this->close();
	}
}
