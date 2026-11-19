<?php
/**
 * Farm page editor (My Farm). Images and the gallery save instantly through REST; everything else posts with the form.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var object   $farm
 * @var array    $values
 * @var WP_Error $errors
 * @var array    $states
 * @var array    $offerings
 * @var array    $meta_fields
 * @var array    $media
 * @var string   $logo_url
 * @var string   $banner_url
 * @var string   $visibility
 * @var string   $farm_url
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var array    $limits
 * @var bool     $maps_enabled
 * @var bool     $geocoding
 * @var array    $days
 * @var array    $social_keys
 */

use SouthPoll\Membership\Frontend\Icons;

defined( 'ABSPATH' ) || exit;

$sp_err = static fn( string $code ): string => $errors->get_error_message( $code ) ? '<div class="sp-error" role="alert">' . esc_html( (string) $errors->get_error_message( $code ) ) . '</div>' : '';
$sp_cls = static fn( string $code ): string => $errors->get_error_message( $code ) ? ' sp-field--error' : '';
$sp_owner = $context->is_owner() || $context->is_staff();
$sp_cfg   = [
	'logo'      => $logo_url,
	'banner'    => $banner_url,
	'media'     => $media,
	'hours'     => $values['hours'],
	'maxMb'     => $limits['image_mb'],
	'maxItems'  => $limits['gallery'],
	'aboutMax'  => 5000,
];
?>
<div class="sp-farm-editor" x-data="spFarmEditor(<?php echo esc_attr( (string) wp_json_encode( $sp_cfg ) ); ?>)">

	<?php if ( $errors->has_errors() ) : ?>
		<div class="sp-alert sp-alert--error" role="alert"><span class="sp-alert__title"><?php esc_html_e( 'Your farm page was not saved yet.', 'southpoll-membership' ); ?></span><?php esc_html_e( 'Please fix the highlighted fields below and save again — each problem is explained under its field.', 'southpoll-membership' ); ?></div>
	<?php endif; ?>

	<div class="sp-card sp-card--flat sp-farm-editor__head">
		<div>
			<p class="sp-eyebrow"><?php esc_html_e( 'Farm page', 'southpoll-membership' ); ?></p>
			<h2 class="sp-h2" style="margin-bottom:4px"><?php echo esc_html( (string) $farm->name ); ?></h2>
			<p class="sp-text-muted sp-text-small" style="margin:0">
				<?php if ( 'public' === $visibility ) : ?>
					<span class="sp-badge sp-badge--success"><?php esc_html_e( 'Live in the directory', 'southpoll-membership' ); ?></span>
				<?php else : ?>
					<span class="sp-badge sp-badge--warning"><?php esc_html_e( 'Not shown in the directory until the membership is active', 'southpoll-membership' ); ?></span>
				<?php endif; ?>
				<code><?php echo esc_html( $farm_url ); ?></code>
			</p>
		</div>
		<div class="sp-cluster">
			<a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $farm_url ); ?>" target="_blank" rel="noopener"><?php echo Icons::svg( 'external', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'View page', 'southpoll-membership' ); ?></a>
			<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" data-sp-share="<?php echo esc_url( $farm_url ); ?>" data-sp-share-title="<?php echo esc_attr( (string) $farm->name ); ?>"><?php echo Icons::svg( 'share', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Share', 'southpoll-membership' ); ?></button>
		</div>
	</div>

	<!-- Images (REST; independent of the form) -->
	<div class="sp-card" id="images">
		<h3 class="sp-card__title"><?php esc_html_e( 'Logo & banner', 'southpoll-membership' ); ?></h3>
		<p class="sp-text-muted sp-text-small"><?php echo esc_html( sprintf( /* translators: %s: MB */ __( 'JPG, PNG or WebP up to %s MB. Images are resized automatically; the banner is cropped to a wide 16:5 strip, so keep the subject near the middle.', 'southpoll-membership' ), (string) $limits['image_mb'] ) ); ?></p>
		<div class="sp-grid sp-grid--2">
			<div>
				<span class="sp-label"><?php esc_html_e( 'Logo (shown on cards, 4:3)', 'southpoll-membership' ); ?></span>
				<div class="sp-dropzone sp-dropzone--logo" :class="{ 'is-busy': busy.logo, 'is-over': over === 'logo' }" @dragover.prevent="over = 'logo'" @dragleave="over = null" @drop.prevent="over = null; upload('logo', $event.dataTransfer.files[0])">
					<template x-if="logo"><img :src="logo" alt="<?php esc_attr_e( 'Current logo', 'southpoll-membership' ); ?>"></template>
					<template x-if="!logo"><div class="sp-dropzone__empty"><?php echo Icons::svg( 'image', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Drop a logo here or choose a file', 'southpoll-membership' ); ?></span></div></template>
					<div class="sp-dropzone__bar" x-show="busy.logo"><div class="sp-dropzone__progress" :style="'width:' + progress.logo + '%'"></div></div>
				</div>
				<div class="sp-cluster" style="margin-top:8px">
					<label class="sp-btn sp-btn--outline sp-btn--sm"><?php echo Icons::svg( 'upload', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Choose logo', 'southpoll-membership' ); ?><input type="file" accept="image/*" class="sp-sr-only" @change="upload('logo', $event.target.files[0]); $event.target.value = ''"></label>
					<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="logo" @click="removeImage('logo')"><?php esc_html_e( 'Remove', 'southpoll-membership' ); ?></button>
				</div>
			</div>
			<div>
				<span class="sp-label"><?php esc_html_e( 'Banner (top of your page, 16:5)', 'southpoll-membership' ); ?></span>
				<div class="sp-dropzone sp-dropzone--banner" :class="{ 'is-busy': busy.banner, 'is-over': over === 'banner' }" @dragover.prevent="over = 'banner'" @dragleave="over = null" @drop.prevent="over = null; upload('banner', $event.dataTransfer.files[0])">
					<template x-if="banner"><img :src="banner" alt="<?php esc_attr_e( 'Current banner', 'southpoll-membership' ); ?>"></template>
					<template x-if="!banner"><div class="sp-dropzone__empty"><?php echo Icons::svg( 'image', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Drop a wide photo here or choose a file', 'southpoll-membership' ); ?></span></div></template>
					<div class="sp-dropzone__bar" x-show="busy.banner"><div class="sp-dropzone__progress" :style="'width:' + progress.banner + '%'"></div></div>
				</div>
				<div class="sp-cluster" style="margin-top:8px">
					<label class="sp-btn sp-btn--outline sp-btn--sm"><?php echo Icons::svg( 'upload', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Choose banner', 'southpoll-membership' ); ?><input type="file" accept="image/*" class="sp-sr-only" @change="upload('banner', $event.target.files[0]); $event.target.value = ''"></label>
					<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="banner" @click="removeImage('banner')"><?php esc_html_e( 'Remove', 'southpoll-membership' ); ?></button>
				</div>
			</div>
		</div>
	</div>

	<form method="post" class="sp-form sp-farm-form" id="farm-form" @submit="saving = true">
		<?php wp_nonce_field( 'sp_farm_save', 'sp_nonce' ); ?>
		<input type="hidden" name="sp_action" value="save_farm">
		<input type="hidden" name="_offerings_submitted" value="1">
		<input type="hidden" name="_contact_form_submitted" value="1">

		<div class="sp-card" id="basics">
			<h3 class="sp-card__title"><?php esc_html_e( 'The basics', 'southpoll-membership' ); ?></h3>
			<div class="sp-form__row sp-form__row--2">
				<div class="sp-field<?php echo $sp_cls( 'name' ); ?>"><label class="sp-label" for="fe-name"><?php esc_html_e( 'Farm / ranch name', 'southpoll-membership' ); ?> <span class="sp-req">*</span></label><input id="fe-name" class="sp-input" name="name" value="<?php echo esc_attr( $values['name'] ); ?>" required maxlength="190"><div class="sp-help"><?php esc_html_e( 'Renaming updates your page address; old links are redirected.', 'southpoll-membership' ); ?></div><?php echo $sp_err( 'name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="sp-field<?php echo $sp_cls( 'slogan' ); ?>"><label class="sp-label" for="fe-slogan"><?php esc_html_e( 'Slogan / tagline', 'southpoll-membership' ); ?></label><input id="fe-slogan" class="sp-input" name="slogan" value="<?php echo esc_attr( $values['slogan'] ); ?>" maxlength="255"><?php echo $sp_err( 'slogan' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
			<div class="sp-form__row sp-form__row--3">
				<div class="sp-field"><label class="sp-label" for="fe-owner"><?php esc_html_e( 'Owner name (shown publicly)', 'southpoll-membership' ); ?></label><input id="fe-owner" class="sp-input" name="owner_name" value="<?php echo esc_attr( $values['owner_name'] ); ?>" maxlength="190"></div>
				<div class="sp-field<?php echo $sp_cls( 'breeder_number' ); ?>"><label class="sp-label" for="fe-breeder"><?php esc_html_e( 'Breeder number', 'southpoll-membership' ); ?></label><input id="fe-breeder" class="sp-input" name="breeder_number" value="<?php echo esc_attr( $values['breeder_number'] ); ?>" maxlength="50"<?php echo $sp_owner ? '' : ' readonly'; ?>><?php if ( ! $sp_owner ) : ?><div class="sp-help"><?php esc_html_e( 'Only the owner can change this.', 'southpoll-membership' ); ?></div><?php endif; ?></div>
				<div class="sp-field<?php echo $sp_cls( 'website' ); ?>"><label class="sp-label" for="fe-web"><?php esc_html_e( 'Website', 'southpoll-membership' ); ?></label><input id="fe-web" class="sp-input" name="website" value="<?php echo esc_attr( $values['website'] ); ?>" placeholder="https://" inputmode="url"><?php echo $sp_err( 'website' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
			<div class="sp-form__row sp-form__row--2">
				<div class="sp-field<?php echo $sp_cls( 'email' ); ?>"><label class="sp-label" for="fe-email"><?php esc_html_e( 'Contact email', 'southpoll-membership' ); ?></label><input id="fe-email" class="sp-input" type="email" name="email" value="<?php echo esc_attr( $values['email'] ); ?>"><div class="sp-help"><?php esc_html_e( 'Shown to logged-in members and used for contact-form messages.', 'southpoll-membership' ); ?></div><?php echo $sp_err( 'email' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="sp-field"><label class="sp-label" for="fe-phone"><?php esc_html_e( 'Contact phone', 'southpoll-membership' ); ?></label><input id="fe-phone" class="sp-input" type="tel" name="phone" value="<?php echo esc_attr( $values['phone'] ); ?>"></div>
			</div>
		</div>

		<div class="sp-card" id="location">
			<h3 class="sp-card__title"><?php esc_html_e( 'Location', 'southpoll-membership' ); ?></h3>
			<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Your street address is only shown to logged-in members. City and state appear on your card and set your region for directory filters.', 'southpoll-membership' ); ?><?php if ( ! $geocoding ) : ?> <?php esc_html_e( '(Map pins switch on once the office adds a Google Maps key.)', 'southpoll-membership' ); ?><?php endif; ?></p>
			<div class="sp-field"><label class="sp-label" for="fe-addr"><?php esc_html_e( 'Street address', 'southpoll-membership' ); ?></label><input id="fe-addr" class="sp-input" name="address1" value="<?php echo esc_attr( $values['address1'] ); ?>" maxlength="190"></div>
			<div class="sp-form__row sp-form__row--3">
				<div class="sp-field"><label class="sp-label" for="fe-city"><?php esc_html_e( 'City', 'southpoll-membership' ); ?></label><input id="fe-city" class="sp-input" name="city" value="<?php echo esc_attr( $values['city'] ); ?>" maxlength="100"></div>
				<div class="sp-field<?php echo $sp_cls( 'state' ); ?>"><label class="sp-label" for="fe-state"><?php esc_html_e( 'State', 'southpoll-membership' ); ?></label><select id="fe-state" class="sp-select" name="state"><option value=""><?php esc_html_e( 'Select…', 'southpoll-membership' ); ?></option><?php foreach ( $states as $abbr => $sname ) : ?><option value="<?php echo esc_attr( $abbr ); ?>"<?php selected( $values['state'], $abbr ); ?>><?php echo esc_html( $sname ); ?></option><?php endforeach; ?></select><?php echo $sp_err( 'state' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="sp-field<?php echo $sp_cls( 'zip' ); ?>"><label class="sp-label" for="fe-zip"><?php esc_html_e( 'ZIP', 'southpoll-membership' ); ?></label><input id="fe-zip" class="sp-input" name="zip" value="<?php echo esc_attr( $values['zip'] ); ?>" inputmode="numeric" maxlength="10"><?php echo $sp_err( 'zip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			</div>
		</div>

		<div class="sp-card" id="about">
			<h3 class="sp-card__title"><?php esc_html_e( 'About us', 'southpoll-membership' ); ?></h3>
			<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Tell buyers about your operation, your herd, and what makes your cattle a good fit.', 'southpoll-membership' ); ?></p>
			<div class="sp-field sp-field--editor">
				<?php
				wp_editor( $values['about'], 'sp-about', [
					'textarea_name' => 'about',
					'textarea_rows' => 12,
					'media_buttons' => false,
					'teeny'         => true,
					'quicktags'     => [ 'buttons' => 'strong,em,link,ul,ol,li' ],
					'tinymce'       => [ 'toolbar1' => 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo', 'toolbar2' => '', 'block_formats' => 'Paragraph=p;Heading=h3', 'statusbar' => false ],
					'editor_height' => 280,
				] );
				?>
			</div>
		</div>

		<div class="sp-card" id="offerings">
			<h3 class="sp-card__title"><?php esc_html_e( 'What you offer', 'southpoll-membership' ); ?></h3>
			<?php if ( empty( $offerings ) ) : ?>
				<p class="sp-text-muted"><?php esc_html_e( 'No offerings have been set up yet.', 'southpoll-membership' ); ?></p>
			<?php else : ?>
				<div class="sp-choice-grid">
					<?php foreach ( $offerings as $o ) : ?>
						<label class="sp-choice"><input type="checkbox" name="offerings[]" value="<?php echo esc_attr( (string) $o->id ); ?>"<?php checked( in_array( (int) $o->id, $values['offerings'], true ) ); ?>><span><?php echo esc_html( (string) $o->name ); ?></span></label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $meta_fields ) ) : ?>
		<div class="sp-card" id="details">
			<h3 class="sp-card__title"><?php esc_html_e( 'Farm details', 'southpoll-membership' ); ?></h3>
			<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'These appear in a table on your farm page. Leave anything blank to hide it.', 'southpoll-membership' ); ?></p>
			<div class="sp-form__row sp-form__row--2">
				<?php foreach ( $meta_fields as $f ) : ?>
					<?php $fk = (string) $f->field_key; $fv = (string) ( $values['meta'][ $fk ] ?? $f->default_value ?? '' ); $fid = 'fe-meta-' . sanitize_html_class( $fk ); ?>
					<div class="sp-field<?php echo 'textarea' === $f->type ? ' sp-field--wide' : ''; ?>">
						<?php if ( 'bool' === $f->type ) : ?>
							<span class="sp-label"><?php echo esc_html( (string) $f->label ); ?></span>
							<label class="sp-toggle"><input type="checkbox" name="meta[<?php echo esc_attr( $fk ); ?>]" value="1"<?php checked( '1' === $fv ); ?>><span class="sp-toggle__track"></span><span><?php esc_html_e( 'Yes', 'southpoll-membership' ); ?></span></label>
						<?php elseif ( 'select' === $f->type ) : ?>
							<label class="sp-label" for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( (string) $f->label ); ?></label>
							<select id="<?php echo esc_attr( $fid ); ?>" class="sp-select" name="meta[<?php echo esc_attr( $fk ); ?>]"><option value=""><?php esc_html_e( 'Select…', 'southpoll-membership' ); ?></option><?php foreach ( (array) $f->options as $opt ) : ?><option value="<?php echo esc_attr( (string) $opt ); ?>"<?php selected( $fv, (string) $opt ); ?>><?php echo esc_html( (string) $opt ); ?></option><?php endforeach; ?></select>
						<?php elseif ( 'textarea' === $f->type ) : ?>
							<label class="sp-label" for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( (string) $f->label ); ?></label>
							<textarea id="<?php echo esc_attr( $fid ); ?>" class="sp-textarea" name="meta[<?php echo esc_attr( $fk ); ?>]" rows="3"><?php echo esc_textarea( $fv ); ?></textarea>
						<?php else : ?>
							<label class="sp-label" for="<?php echo esc_attr( $fid ); ?>"><?php echo esc_html( (string) $f->label ); ?></label>
							<input id="<?php echo esc_attr( $fid ); ?>" class="sp-input" name="meta[<?php echo esc_attr( $fk ); ?>]" value="<?php echo esc_attr( $fv ); ?>">
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endif; ?>

		<div class="sp-card" id="hours" x-data="{ open: <?php echo \SouthPoll\Membership\Farms\FarmRepository::has_hours( $farm ) || $errors->get_error_message( 'hours_mon' ) ? 'true' : 'false'; ?> }">
			<div class="sp-card__header" style="margin-bottom:0">
				<h3 class="sp-card__title" style="margin:0"><?php esc_html_e( 'Visiting hours', 'southpoll-membership' ); ?> <span class="sp-badge sp-badge--muted"><?php esc_html_e( 'Optional', 'southpoll-membership' ); ?></span></h3>
				<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="open = !open" :aria-expanded="open" aria-controls="sp-hours-grid" x-text="open ? '<?php echo esc_js( __( 'Hide', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Set hours', 'southpoll-membership' ) ); ?>'"></button>
			</div>
			<div id="sp-hours-grid" x-show="open" x-collapse>
				<p class="sp-text-muted sp-text-small" style="margin-top:12px"><?php esc_html_e( 'Tick the days visitors are welcome and set the times. Leave everything unticked to hide hours from your page.', 'southpoll-membership' ); ?></p>
				<div class="sp-hours">
					<?php foreach ( $days as $dk => $dlabel ) : ?>
						<?php $h = $values['hours'][ $dk ]; ?>
						<div class="sp-hours__row<?php echo $errors->get_error_message( 'hours_' . $dk ) ? ' sp-field--error' : ''; ?>" x-data="{ on: <?php echo $h['open'] ? 'true' : 'false'; ?> }">
							<label class="sp-check"><input type="checkbox" name="hours[<?php echo esc_attr( $dk ); ?>][open]" value="1" x-model="on"><span><?php echo esc_html( $dlabel ); ?></span></label>
							<div class="sp-hours__times" :class="{ 'is-off': !on }">
								<input class="sp-input" type="time" name="hours[<?php echo esc_attr( $dk ); ?>][from]" value="<?php echo esc_attr( $h['from'] ?: '08:00' ); ?>" :disabled="!on" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: day */ __( '%s opening time', 'southpoll-membership' ), $dlabel ) ); ?>">
								<span>–</span>
								<input class="sp-input" type="time" name="hours[<?php echo esc_attr( $dk ); ?>][to]" value="<?php echo esc_attr( $h['to'] ?: '17:00' ); ?>" :disabled="!on" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: day */ __( '%s closing time', 'southpoll-membership' ), $dlabel ) ); ?>">
							</div>
							<?php echo $sp_err( 'hours_' . $dk ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<div class="sp-card" id="social">
			<h3 class="sp-card__title"><?php esc_html_e( 'Social media', 'southpoll-membership' ); ?></h3>
			<div class="sp-form__row sp-form__row--2">
				<?php foreach ( $social_keys as $sk => $slabel ) : ?>
					<div class="sp-field<?php echo $sp_cls( 'social_' . $sk ); ?>"><label class="sp-label" for="fe-social-<?php echo esc_attr( $sk ); ?>"><?php echo esc_html( $slabel ); ?></label><input id="fe-social-<?php echo esc_attr( $sk ); ?>" class="sp-input" name="social[<?php echo esc_attr( $sk ); ?>]" value="<?php echo esc_attr( $values['social'][ $sk ] ?? '' ); ?>" placeholder="https://" inputmode="url"><?php echo $sp_err( 'social_' . $sk ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<?php endforeach; ?>
			</div>
		</div>

		<?php if ( $sp_owner ) : ?>
		<div class="sp-card" id="contact-form">
			<h3 class="sp-card__title"><?php esc_html_e( 'Contact form for visitors', 'southpoll-membership' ); ?></h3>
			<label class="sp-toggle"><input type="checkbox" name="contact_form_enabled" value="1"<?php checked( $values['contact_form_enabled'] ); ?>><span class="sp-toggle__track"></span><span><?php esc_html_e( 'Let visitors who are not logged in send me a message from my farm page', 'southpoll-membership' ); ?></span></label>
			<p class="sp-help" style="margin-top:8px"><?php esc_html_e( 'Messages go to your contact email (and account managers who receive listing emails). Logged-in members always see your contact details instead.', 'southpoll-membership' ); ?></p>
		</div>
		<?php endif; ?>

		<div class="sp-farm-form__actions">
			<button class="sp-btn sp-btn--primary sp-btn--lg" type="submit" :class="{ 'is-loading': saving }"><?php esc_html_e( 'Save farm page', 'southpoll-membership' ); ?></button>
			<a class="sp-btn sp-btn--ghost" href="<?php echo esc_url( $farm_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'southpoll-membership' ); ?></a>
		</div>
	</form>

	<!-- Gallery (REST; independent of the form) -->
	<div class="sp-card" id="gallery">
		<div class="sp-card__header">
			<h3 class="sp-card__title" style="margin:0"><?php esc_html_e( 'Photo & video gallery', 'southpoll-membership' ); ?></h3>
			<span class="sp-badge" x-text="media.length + ' / ' + maxItems"></span>
		</div>
		<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Drag items to reorder. The first item leads the gallery on your page.', 'southpoll-membership' ); ?></p>
		<div class="sp-gallery-manager" @dragover.prevent>
			<template x-for="(item, i) in media" :key="item.id">
				<div class="sp-gallery-manager__item" :class="{ 'is-dragging': dragId === item.id }" draggable="true" @dragstart="dragStart(item)" @dragover.prevent="dragOver(item)" @drop.prevent="dragEnd()" @dragend="dragEnd()">
					<div class="sp-gallery-manager__thumb">
						<template x-if="item.type === 'image'"><img :src="item.thumb" :alt="item.caption || ''"></template>
						<template x-if="item.type === 'video'"><div class="sp-gallery-manager__video" :style="item.thumb ? 'background-image:url(' + item.thumb + ')' : ''"><?php echo Icons::svg( 'play', 32 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span x-text="item.provider === 'vimeo' ? 'Vimeo' : 'YouTube'"></span></div></template>
						<span class="sp-gallery-manager__grip" title="<?php esc_attr_e( 'Drag to reorder', 'southpoll-membership' ); ?>"><?php echo Icons::svg( 'grip', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
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
			<div class="sp-field" style="flex:1;margin:0"><label class="sp-label" for="fe-video"><?php esc_html_e( 'Add a YouTube or Vimeo video', 'southpoll-membership' ); ?></label><input id="fe-video" class="sp-input" type="url" x-model="videoUrl" placeholder="https://youtu.be/…"></div>
			<button type="submit" class="sp-btn sp-btn--outline" :class="{ 'is-loading': busy.video }" :disabled="!videoUrl"><?php esc_html_e( 'Add video', 'southpoll-membership' ); ?></button>
		</form>
	</div>
</div>
