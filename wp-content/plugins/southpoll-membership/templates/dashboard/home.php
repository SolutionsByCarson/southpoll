<?php
/**
 * Dashboard home.
 *
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \WP_User $user
 * @var string   $name
 * @var array    $membership
 * @var \SouthPoll\Membership\Routing\Urls $urls
 * @var array    $cards  key => HTML (favorites, saved_searches, listings, farm, …)
 * @var array|null $popup  S7 launch popup {title, text, version, feedback} or null (disabled / already dismissed)
 */

defined( 'ABSPATH' ) || exit;
?>
<?php if ( ! empty( $popup ) ) : ?>
	<script type="application/json" id="sp-launch-popup"><?php echo wp_json_encode( $popup, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?></script>
<?php endif; ?>
<div class="sp-card sp-card--flat sp-home__profile">
	<div class="sp-home__profile-main">
		<p class="sp-eyebrow"><?php echo esc_html( $context->is_staff() ? __( 'Staff account', 'southpoll-membership' ) : ( $context->is_owner() ? __( 'Farm owner', 'southpoll-membership' ) : ( $context->is_manager() ? __( 'Account manager', 'southpoll-membership' ) : __( 'Buyer account', 'southpoll-membership' ) ) ) ); ?></p>
		<h2 class="sp-h2" style="margin-bottom:4px"><?php echo esc_html( $name ); ?></h2>
		<div class="sp-text-muted"><?php echo esc_html( $user->user_email ); ?><?php if ( $context->has_farm() ) : ?> &middot; <?php echo esc_html( (string) $context->farm->name ); ?><?php endif; ?></div>
	</div>
	<div class="sp-home__profile-actions">
		<?php if ( ! $context->is_verified() ) : ?>
			<span class="sp-badge sp-badge--warning"><?php esc_html_e( 'Email not verified', 'southpoll-membership' ); ?></span>
		<?php endif; ?>
		<a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->dashboard( 'account-details' ) ); ?>"><?php esc_html_e( 'Edit details', 'southpoll-membership' ); ?></a>
	</div>
</div>

<?php if ( ! $context->is_verified() ) : ?>
	<div class="sp-alert sp-alert--warning" x-data="{ busy: false }">
		<?php esc_html_e( 'Please verify your email address to see farm contact information and use favorites and saved searches.', 'southpoll-membership' ); ?>
		<button type="button" class="sp-btn sp-btn--outline sp-btn--sm" style="margin-left:10px" :class="{ 'is-loading': busy }" @click="busy = true; SP.api('account/resend-verification', { method: 'POST' }).then(r => SP.toast.success(r.message)).catch(e => SP.toast.error(e.message)).finally(() => busy = false)"><?php esc_html_e( 'Resend verification email', 'southpoll-membership' ); ?></button>
	</div>
<?php endif; ?>

<?php $view->display( 'dashboard/partials/membership-banner', compact( 'membership', 'context', 'urls' ) ); ?>

<div class="sp-grid sp-grid--2 sp-home__cards">
	<?php foreach ( $cards as $key => $card ) : ?>
		<div class="sp-home__card sp-home__card--<?php echo esc_attr( (string) $key ); ?>"><?php echo $card; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card templates escape their own output ?></div>
	<?php endforeach; ?>
</div>
<style>
	.sp-home__profile { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; }
	.sp-home__profile-actions { display: flex; align-items: center; gap: 10px; }
	.sp-home__cards .sp-card { margin-bottom: 0; height: 100%; }
</style>
