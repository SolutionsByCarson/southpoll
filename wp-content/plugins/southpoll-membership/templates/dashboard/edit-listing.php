<?php
/**
 * Listing editor.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Listings\Listing $listing
 * @var array    $values
 * @var WP_Error $errors
 * @var array    $categories
 * @var array    $groups      [{id,title,categories[],fields[]}]
 * @var array    $statuses
 * @var string   $featured_url
 * @var array    $gallery
 * @var array    $limits
 * @var bool     $can_publish   Publish button enabled (membership active and a listing slot free)
 * @var string   $publish_block ''|membership|limit — why Publish is disabled
 * @var bool     $can_renew     Membership allows renewing
 * @var bool     $is_live
 * @var bool     $is_expired
 * @var bool     $renew_mode
 * @var string   $view_url
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var int      $max_images
 * @var float    $max_mb
 * @var int      $duration
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$sp_err = static fn( string $code ): string => $errors->get_error_message( $code ) ? '<div class="sp-error" role="alert">' . esc_html( (string) $errors->get_error_message( $code ) ) . '</div>' : '';
$sp_cls = static fn( string $code ): string => $errors->get_error_message( $code ) ? ' sp-field--error' : '';
$sp_cfg = [
	'id'         => $listing->id,
	'featured'   => $featured_url,
	'gallery'    => $gallery,
	'categories' => array_map( 'intval', $values['categories'] ),
	'groups'     => array_map( static fn( $g ) => [ 'id' => $g['id'], 'categories' => $g['categories'] ], $groups ),
	'maxItems'   => $max_images,
	'maxMb'      => $max_mb,
	'status'     => $values['status'],
];
?>
<div class="sp-farm-editor sp-listing-editor" x-data="spListingEditor(<?php echo esc_attr( (string) wp_json_encode( $sp_cfg ) ); ?>)">

	<?php if ( $errors->has_errors() ) : ?>
		<?php $sp_field_codes = array_diff( $errors->get_error_codes(), [ 'sp_limit', 'sp_membership_inactive', 'sp_forbidden' ] ); ?>
		<div class="sp-alert sp-alert--error" role="alert">
			<?php if ( $sp_field_codes ) : ?>
				<span class="sp-alert__title"><?php esc_html_e( 'Your listing was not saved yet.', 'southpoll-membership' ); ?></span><?php echo esc_html( sprintf( /* translators: %d: number of problems */ _n( 'Please fix the %d highlighted field below — the problem is explained under it.', 'Please fix the %d highlighted fields below — each problem is explained under its field.', count( $sp_field_codes ), 'southpoll-membership' ), count( $sp_field_codes ) ) ); ?>
			<?php else : ?>
				<span class="sp-alert__title"><?php esc_html_e( 'Not published.', 'southpoll-membership' ); ?></span><?php echo esc_html( (string) $errors->get_error_message() ); ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>
	<?php if ( $renew_mode ) : ?>
		<div class="sp-alert sp-alert--warning"><strong><?php echo $is_expired ? esc_html__( 'This listing has expired.', 'southpoll-membership' ) : esc_html__( 'This listing expires soon.', 'southpoll-membership' ); ?></strong> <?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Check the price, photos and details are still right, then renew it for another %d days.', 'southpoll-membership' ), $duration ) ); ?></div>
	<?php endif; ?>

	<div class="sp-card sp-card--flat sp-farm-editor__head">
		<div>
			<p class="sp-eyebrow"><?php echo $listing->published_at ? esc_html__( 'Edit listing', 'southpoll-membership' ) : esc_html__( 'New listing', 'southpoll-membership' ); ?></p>
			<h2 class="sp-h2" style="margin-bottom:4px"><?php echo esc_html( $values['title'] ?: __( 'Untitled listing', 'southpoll-membership' ) ); ?></h2>
			<p class="sp-text-muted sp-text-small" style="margin:0">
				<span class="sp-badge <?php echo esc_attr( \SouthPoll\Membership\Listings\ListingPresenter::status_class( $listing->effective_status() ) ); ?>"><?php echo esc_html( $is_live ? sprintf( /* translators: %s: status */ __( 'Live · %s', 'southpoll-membership' ), $listing->status_label() ) : $listing->status_label() ); ?></span>
				<?php if ( $listing->expires_at && $is_live ) : ?> <?php echo esc_html( sprintf( /* translators: %s: date */ __( 'runs until %s', 'southpoll-membership' ), \SouthPoll\Membership\Support\Dates::display( $listing->expires_at ) ) ); ?><?php endif; ?>
				&middot; <?php echo esc_html( sprintf( /* translators: 1: active, 2: limit */ __( '%1$d of %2$d active listings used', 'southpoll-membership' ), (int) $limits['active'], (int) $limits['limit'] ) ); ?>
			</p>
		</div>
		<div class="sp-cluster">
			<a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->my_listings() ); ?>">&larr; <?php esc_html_e( 'My Listings', 'southpoll-membership' ); ?></a>
			<?php if ( $is_live ) : ?><a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener"><?php echo Icons::svg( 'external', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'View', 'southpoll-membership' ); ?></a><?php endif; ?>
		</div>
	</div>

	<form method="post" class="sp-form sp-farm-form" id="listing-form" @submit="saving = true">
		<?php wp_nonce_field( 'sp_listing_save_' . $listing->id, 'sp_nonce' ); ?>
		<input type="hidden" name="sp_action" value="save_listing">
		<input type="hidden" name="listing_id" value="<?php echo esc_attr( (string) $listing->id ); ?>">
		<input type="hidden" name="sp_submit" :value="submitAs" value="save">

		<div class="sp-card">
			<h3 class="sp-card__title"><?php esc_html_e( 'What are you selling?', 'southpoll-membership' ); ?></h3>
			<div class="sp-field<?php echo $sp_cls( 'title' ); ?>"><label class="sp-label" for="le-title"><?php esc_html_e( 'Ad title', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="le-title" class="sp-input" name="title" value="<?php echo esc_attr( $values['title'] ); ?>" maxlength="190" required placeholder="<?php esc_attr_e( 'e.g. Three registered South Poll bred heifers', 'southpoll-membership' ); ?>"><?php echo $sp_err( 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="sp-field<?php echo $sp_cls( 'categories' ); ?>">
				<span class="sp-label"><?php esc_html_e( 'Categories', 'southpoll-membership' ); ?> <span class="sp-req">*</span></span>
				<div class="sp-choice-grid">
					<?php foreach ( $categories as $c ) : ?>
						<label class="sp-choice"><input type="checkbox" name="categories[]" value="<?php echo esc_attr( (string) $c->id ); ?>" x-model.number="categories"<?php checked( in_array( (int) $c->id, $values['categories'], true ) ); ?>><span><?php echo esc_html( (string) $c->name ); ?></span></label>
					<?php endforeach; ?>
				</div>
				<?php echo $sp_err( 'categories' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<div class="sp-form__row sp-form__row--3">
				<div class="sp-field<?php echo $sp_cls( 'price' ); ?>"><label class="sp-label" for="le-price"><?php esc_html_e( 'Price (USD)', 'southpoll-membership' ); ?></label><input id="le-price" class="sp-input" name="price" value="<?php echo esc_attr( $values['price'] ); ?>" inputmode="decimal" placeholder="2500"><div class="sp-help"><?php esc_html_e( 'Leave blank for "Call for price".', 'southpoll-membership' ); ?></div><?php echo $sp_err( 'price' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="sp-field"><label class="sp-label" for="le-price-label"><?php esc_html_e( 'Price note', 'southpoll-membership' ); ?></label><input id="le-price-label" class="sp-input" name="price_label" value="<?php echo esc_attr( $values['price_label'] ); ?>" maxlength="60" placeholder="<?php esc_attr_e( 'per head · OBO · negotiable', 'southpoll-membership' ); ?>"></div>
				<?php if ( $is_live ) : ?>
					<div class="sp-field<?php echo $sp_cls( 'status' ); ?>"><label class="sp-label" for="le-status"><?php esc_html_e( 'Status', 'southpoll-membership' ); ?></label><select id="le-status" class="sp-select" name="status" x-model="status"><?php foreach ( $statuses as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>"<?php selected( $values['status'], $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select><div class="sp-help" x-show="status === 'sold'"><?php esc_html_e( 'Sold listings stay visible for 14 days, then hide automatically.', 'southpoll-membership' ); ?></div><div class="sp-help" x-show="status === 'inactive'"><?php esc_html_e( 'Inactive listings are hidden from buyers but kept here.', 'southpoll-membership' ); ?></div></div>
				<?php else : ?>
					<?php
					// Not live: the status select is replaced by a read-only state line (the listing goes live as "Available"
					// when Publish is pressed), so a draft can no longer be "saved as Active" and stay hidden.
					if ( $is_expired ) {
						$sp_state = __( 'Expired — not visible to buyers until you renew it.', 'southpoll-membership' );
					} elseif ( $listing->is_unpublished_draft() ) {
						$sp_state = __( 'Draft — not visible to buyers until you press Publish.', 'southpoll-membership' );
					} elseif ( 'membership' === $listing->hidden_reason ) {
						$sp_state = __( 'Hidden while the farm membership is inactive — it returns automatically when the membership is renewed.', 'southpoll-membership' );
					} else {
						$sp_state = __( 'Paused — hidden from buyers until you press Publish.', 'southpoll-membership' );
					}
					?>
					<div class="sp-field sp-field--state"><span class="sp-label"><?php esc_html_e( 'Status', 'southpoll-membership' ); ?></span><input type="hidden" name="status" value="<?php echo esc_attr( $values['status'] ); ?>"><p class="sp-listing-state" id="le-state"><?php echo Icons::svg( 'info', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <strong><?php echo esc_html( $sp_state ); ?></strong></p><div class="sp-help"><?php esc_html_e( '"Save draft" keeps your changes without showing them. You can mark the listing sold or pause it after it is published.', 'southpoll-membership' ); ?></div></div>
				<?php endif; ?>
			</div>
			<div class="sp-field<?php echo $sp_cls( 'excerpt' ); ?>"><label class="sp-label" for="le-excerpt"><?php esc_html_e( 'Short description (optional)', 'southpoll-membership' ); ?></label><textarea id="le-excerpt" class="sp-textarea" name="excerpt" rows="2" maxlength="400" placeholder="<?php esc_attr_e( 'One or two lines shown on the listing card. Blank = the start of your details.', 'southpoll-membership' ); ?>"><?php echo esc_textarea( $values['excerpt'] ); ?></textarea><?php echo $sp_err( 'excerpt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<div class="sp-field sp-field--editor<?php echo $sp_cls( 'content' ); ?>">
				<label class="sp-label" for="sp-listing-content"><?php esc_html_e( 'Details', 'southpoll-membership' ); ?></label>
				<?php
				wp_editor( $values['content'], 'sp-listing-content', [
					'textarea_name' => 'content',
					'textarea_rows' => 12,
					'media_buttons' => false,
					'teeny'         => true,
					'quicktags'     => [ 'buttons' => 'strong,em,link,ul,ol,li' ],
					'tinymce'       => [ 'toolbar1' => 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo', 'toolbar2' => '', 'block_formats' => 'Paragraph=p;Heading=h3', 'statusbar' => false ],
					'editor_height' => 260,
				] );
				?>
				<?php echo $sp_err( 'content' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>

		<?php foreach ( $groups as $g ) : ?>
			<?php if ( empty( $g['fields'] ) ) { continue; } ?>
			<div class="sp-card" x-show="groupApplies(<?php echo esc_attr( (string) $g['id'] ); ?>)" x-transition.opacity>
				<h3 class="sp-card__title"><?php echo esc_html( $g['title'] ); ?></h3>
				<?php if ( ! empty( $g['categories'] ) ) : ?><p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Shown because of the categories you chose.', 'southpoll-membership' ); ?></p><?php endif; ?>
				<div class="sp-form__row sp-form__row--2">
					<?php foreach ( $g['fields'] as $f ) : ?>
						<?php $fk = (string) $f->field_key; $fv = (string) ( $values['meta'][ $fk ] ?? $f->default_value ?? '' ); $fid = 'le-meta-' . sanitize_html_class( $fk ); $req = (int) $f->required ? ' <span class="sp-req">*</span>' : ''; ?>
						<div class="sp-field<?php echo 'textarea' === $f->type ? ' sp-field--wide' : ''; ?><?php echo $sp_cls( 'meta_' . $fk ); ?>">
							<?php if ( 'bool' === $f->type ) : ?>
								<span class="sp-label"><?php echo esc_html( (string) $f->label ) . $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<label class="sp-toggle"><input type="checkbox" name="meta[<?php echo esc_attr( $fk ); ?>]" value="1"<?php checked( '1' === $fv ); ?>><span class="sp-toggle__track"></span><span><?php esc_html_e( 'Yes', 'southpoll-membership' ); ?></span></label>
							<?php elseif ( 'select' === $f->type ) : ?>
								<label class="sp-label" for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( (string) $f->label ) . $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
								<select id="<?php echo esc_attr( $fid ); ?>" class="sp-select" name="meta[<?php echo esc_attr( $fk ); ?>]"><option value=""><?php esc_html_e( 'Select…', 'southpoll-membership' ); ?></option><?php foreach ( (array) $f->options as $opt ) : ?><option value="<?php echo esc_attr( (string) $opt ); ?>"<?php selected( $fv, (string) $opt ); ?>><?php echo esc_html( (string) $opt ); ?></option><?php endforeach; ?></select>
							<?php elseif ( 'textarea' === $f->type ) : ?>
								<label class="sp-label" for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( (string) $f->label ) . $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
								<textarea id="<?php echo esc_attr( $fid ); ?>" class="sp-textarea" name="meta[<?php echo esc_attr( $fk ); ?>]" rows="3"><?php echo esc_textarea( $fv ); ?></textarea>
							<?php else : ?>
								<label class="sp-label" for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( (string) $f->label ) . $req; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
								<input id="<?php echo esc_attr( $fid ); ?>" class="sp-input" name="meta[<?php echo esc_attr( $fk ); ?>]" value="<?php echo esc_attr( $fv ); ?>">
							<?php endif; ?>
							<?php echo $sp_err( 'meta_' . $fk ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>

		<?php if ( ! $is_live && '' !== $publish_block ) : ?>
			<div class="sp-alert sp-alert--warning" id="le-publish-block" role="status">
				<strong><?php esc_html_e( 'Publish is not available yet.', 'southpoll-membership' ); ?></strong>
				<?php if ( 'membership' === $publish_block ) : ?>
					<?php esc_html_e( 'Listings can only be published while the farm membership is active. You can keep editing and save this draft in the meantime.', 'southpoll-membership' ); ?>
					<?php if ( $context->is_owner() ) : ?>
						<a href="<?php echo esc_url( $urls->dashboard( 'manage-membership' ) ); ?>"><?php esc_html_e( 'Manage membership', 'southpoll-membership' ); ?></a>
					<?php else : ?>
						<?php esc_html_e( 'Ask the farm owner to renew the membership.', 'southpoll-membership' ); ?>
					<?php endif; ?>
				<?php else : ?>
					<?php echo esc_html( sprintf( /* translators: %d: limit */ __( 'You have reached your limit of %d active listings. Mark one sold or pause it on My Listings, then publish this one. Drafts are always allowed.', 'southpoll-membership' ), (int) $limits['limit'] ) ); ?>
					<a href="<?php echo esc_url( $urls->my_listings() ); ?>"><?php esc_html_e( 'Go to My Listings', 'southpoll-membership' ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="sp-farm-form__actions">
			<?php if ( $renew_mode && $can_renew ) : ?>
				<button class="sp-btn sp-btn--secondary" type="submit" @click="setSubmit('renew', $el.form)" :class="{ 'is-loading': saving }"><?php echo esc_html( sprintf( /* translators: %d: days */ __( 'Renew for %d days', 'southpoll-membership' ), $duration ) ); ?></button>
			<?php endif; ?>
			<?php if ( $is_live ) : ?>
				<button class="sp-btn sp-btn--primary" type="submit" @click="setSubmit('publish', $el.form)" :class="{ 'is-loading': saving }"><?php esc_html_e( 'Update listing', 'southpoll-membership' ); ?></button>
				<button class="sp-btn sp-btn--outline" type="submit" @click="setSubmit('draft', $el.form)"><?php esc_html_e( 'Pause (hide)', 'southpoll-membership' ); ?></button>
			<?php else : ?>
				<button class="sp-btn sp-btn--primary" type="submit" @click="setSubmit('publish', $el.form)" :class="{ 'is-loading': saving }"<?php disabled( ! $can_publish ); ?><?php echo $can_publish ? '' : ' aria-describedby="le-publish-block"'; ?>><?php esc_html_e( 'Publish', 'southpoll-membership' ); ?></button>
				<button class="sp-btn sp-btn--outline" type="submit" @click="setSubmit('save', $el.form)"><?php esc_html_e( 'Save draft', 'southpoll-membership' ); ?></button>
				<?php if ( ! $can_publish ) : ?><span class="sp-text-small sp-text-muted sp-farm-form__note"><?php echo 'membership' === $publish_block ? esc_html__( 'Publish needs an active membership (see above).', 'southpoll-membership' ) : esc_html__( 'Publish needs a free listing slot (see above).', 'southpoll-membership' ); ?></span><?php endif; ?>
			<?php endif; ?>
			<button class="sp-btn sp-btn--ghost" type="submit" @click.prevent="confirmDelete($el.form)"><?php esc_html_e( 'Delete', 'southpoll-membership' ); ?></button>
		</div>
	</form>

	<!-- Images (REST; independent of the form) -->
	<div class="sp-card" id="images">
		<h3 class="sp-card__title"><?php esc_html_e( 'Featured image', 'southpoll-membership' ); ?></h3>
		<p class="sp-text-muted sp-text-small"><?php echo esc_html( sprintf( /* translators: %s: MB */ __( 'Shown on the listing card and at the top of the listing. JPG, PNG or WebP up to %s MB.', 'southpoll-membership' ), (string) $max_mb ) ); ?></p>
		<div class="sp-dropzone sp-dropzone--logo" :class="{ 'is-busy': busy.featured, 'is-over': over === 'featured' }" @dragover.prevent="over = 'featured'" @dragleave="over = null" @drop.prevent="over = null; uploadFeatured($event.dataTransfer.files[0])">
			<template x-if="featured"><img :src="featured" alt="<?php esc_attr_e( 'Featured image', 'southpoll-membership' ); ?>" style="object-fit:cover"></template>
			<template x-if="!featured"><div class="sp-dropzone__empty"><?php echo Icons::svg( 'image', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Drop a photo here or choose a file', 'southpoll-membership' ); ?></span></div></template>
			<div class="sp-dropzone__bar" x-show="busy.featured"><div class="sp-dropzone__progress" :style="'width:' + progress + '%'"></div></div>
		</div>
		<div class="sp-cluster" style="margin-top:8px">
			<label class="sp-btn sp-btn--outline sp-btn--sm"><?php echo Icons::svg( 'upload', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Choose photo', 'southpoll-membership' ); ?><input type="file" accept="image/*" class="sp-sr-only" @change="uploadFeatured($event.target.files[0]); $event.target.value = ''"></label>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="featured" @click="removeFeatured()"><?php esc_html_e( 'Remove', 'southpoll-membership' ); ?></button>
		</div>
	</div>

	<div class="sp-card" id="gallery">
		<div class="sp-card__header"><h3 class="sp-card__title" style="margin:0"><?php esc_html_e( 'Photo & video gallery', 'southpoll-membership' ); ?></h3><span class="sp-badge" x-text="media.length + ' / ' + maxItems"></span></div>
		<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Drag to reorder. Buyers can open every photo full screen; videos play in a pop-up.', 'southpoll-membership' ); ?></p>
		<div class="sp-gallery-manager" @dragover.prevent>
			<template x-for="(item, i) in media" :key="item.key">
				<div class="sp-gallery-manager__item" :class="{ 'is-dragging': dragKey === item.key }" draggable="true" @dragstart="dragStart(item)" @dragover.prevent="dragOver(item)" @drop.prevent="dragEnd()" @dragend="dragEnd()">
					<div class="sp-gallery-manager__thumb">
						<template x-if="item.type === 'image'"><img :src="item.thumb" :alt="item.caption || ''"></template>
						<template x-if="item.type === 'video'"><div class="sp-gallery-manager__video" :style="item.thumb ? 'background-image:url(' + item.thumb + ')' : ''"><?php echo Icons::svg( 'play', 32 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span x-text="item.provider === 'vimeo' ? 'Vimeo' : 'YouTube'"></span></div></template>
						<span class="sp-gallery-manager__grip"><?php echo Icons::svg( 'grip', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</div>
					<input class="sp-input sp-input--sm" type="text" :value="item.caption" placeholder="<?php esc_attr_e( 'Caption (optional)', 'southpoll-membership' ); ?>" maxlength="255" @change="saveCaption(item, $event.target.value)" :aria-label="'<?php echo esc_js( __( 'Caption for item', 'southpoll-membership' ) ); ?> ' + (i + 1)">
					<div class="sp-gallery-manager__tools">
						<button type="button" class="sp-icon-btn" @click="move(item, -1)" :disabled="i === 0" aria-label="<?php esc_attr_e( 'Move earlier', 'southpoll-membership' ); ?>"><?php echo Icons::svg( 'left', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
						<button type="button" class="sp-icon-btn" @click="move(item, 1)" :disabled="i === media.length - 1" aria-label="<?php esc_attr_e( 'Move later', 'southpoll-membership' ); ?>"><?php echo Icons::svg( 'right', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
						<button type="button" class="sp-icon-btn sp-icon-btn--danger" @click="remove(item)" aria-label="<?php esc_attr_e( 'Remove', 'southpoll-membership' ); ?>"><?php echo Icons::svg( 'trash', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
					</div>
				</div>
			</template>
			<label class="sp-gallery-manager__add" :class="{ 'is-busy': busy.gallery, 'is-over': over === 'gallery' }" @dragover.prevent="over = 'gallery'" @dragleave="over = null" @drop.prevent="over = null; addFiles($event.dataTransfer.files)">
				<?php echo Icons::svg( 'upload', 26 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span x-text="busy.gallery ? '<?php echo esc_js( __( 'Uploading…', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Add photos', 'southpoll-membership' ) ); ?>'"></span>
				<input type="file" accept="image/*" multiple class="sp-sr-only" @change="addFiles($event.target.files); $event.target.value = ''">
			</label>
		</div>
		<form class="sp-gallery-manager__video-form" @submit.prevent="addVideo()">
			<div class="sp-field" style="flex:1;margin:0"><label class="sp-label" for="le-video"><?php esc_html_e( 'Add a YouTube or Vimeo video', 'southpoll-membership' ); ?></label><input id="le-video" class="sp-input" type="url" x-model="videoUrl" placeholder="https://youtu.be/…"></div>
			<button type="submit" class="sp-btn sp-btn--outline" :class="{ 'is-loading': busy.video }" :disabled="!videoUrl"><?php esc_html_e( 'Add video', 'southpoll-membership' ); ?></button>
		</form>
	</div>
</div>
