<?php
/**
 * Default dashboard home cards (favorites / saved searches / listings / farm). Each phase overrides its key
 * through the `sp/dashboard/home_cards` filter; this partial renders one card by $card key.
 *
 * @var string $card
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var \SouthPoll\Membership\Routing\Urls $urls
 */

defined( 'ABSPATH' ) || exit;

switch ( $card ) :
	case 'favorites':
		?>
		<div class="sp-card">
			<div class="sp-card__header"><h3 class="sp-h3" style="margin:0"><?php esc_html_e( 'Favorites', 'southpoll-membership' ); ?></h3><a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->my_favorites() ); ?>"><?php esc_html_e( 'View all', 'southpoll-membership' ); ?></a></div>
			<div class="sp-empty"><?php esc_html_e( 'No favorites yet. Browse the member directory or classifieds and tap the star on anything you like.', 'southpoll-membership' ); ?><br><a class="sp-btn sp-btn--primary sp-btn--sm" href="<?php echo esc_url( $urls->directory() ); ?>"><?php esc_html_e( 'Member directory', 'southpoll-membership' ); ?></a> <a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $urls->classifieds() ); ?>"><?php esc_html_e( 'Classifieds', 'southpoll-membership' ); ?></a></div>
		</div>
		<?php
		break;
	case 'saved_searches':
		?>
		<div class="sp-card">
			<div class="sp-card__header"><h3 class="sp-h3" style="margin:0"><?php esc_html_e( 'Saved searches', 'southpoll-membership' ); ?></h3><a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->dashboard( 'saved-searches' ) ); ?>"><?php esc_html_e( 'Manage', 'southpoll-membership' ); ?></a></div>
			<div class="sp-empty"><?php esc_html_e( 'Save a search from the classifieds page and get an email when new listings match.', 'southpoll-membership' ); ?></div>
		</div>
		<?php
		break;
	case 'listings':
		?>
		<div class="sp-card">
			<div class="sp-card__header"><h3 class="sp-h3" style="margin:0"><?php esc_html_e( 'My listings', 'southpoll-membership' ); ?></h3><a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->dashboard( 'my-listings' ) ); ?>"><?php esc_html_e( 'Manage', 'southpoll-membership' ); ?></a></div>
			<div class="sp-empty"><?php esc_html_e( 'Classified listings arrive in build phase 3.', 'southpoll-membership' ); ?></div>
		</div>
		<?php
		break;
	case 'farm':
		$s  = $farm_summary ?? [];
		$fc = $s['card'] ?? [];
		$ms = $s['membership'] ?? [];
		$vis_label = match ( $s['visibility'] ?? '' ) {
			'public'    => __( 'Public', 'southpoll-membership' ),
			'inactive'  => __( 'Hidden by the office', 'southpoll-membership' ),
			'expired'   => __( 'Hidden: membership expired', 'southpoll-membership' ),
			'pending'   => __( 'Hidden: payment pending', 'southpoll-membership' ),
			'cancelled' => __( 'Hidden: membership cancelled', 'southpoll-membership' ),
			default     => __( 'Hidden: no active membership', 'southpoll-membership' ),
		};
		?>
		<div class="sp-card sp-farm-card">
			<div class="sp-card__header"><h3 class="sp-h3" style="margin:0"><?php esc_html_e( 'My farm', 'southpoll-membership' ); ?></h3><a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->my_farm() ); ?>"><?php esc_html_e( 'Edit', 'southpoll-membership' ); ?></a></div>
			<div class="sp-farm-card__head">
				<div class="sp-farm-card__logo"><?php if ( ! empty( $fc['logo'] ) ) : ?><img src="<?php echo esc_url( $fc['logo'] ); ?>" alt="" width="400" height="300"><?php else : ?><span><?php echo esc_html( $fc['initials'] ?? '' ); ?></span><?php endif; ?></div>
				<div class="sp-farm-card__text">
					<p class="sp-farm-card__name"><?php echo esc_html( (string) $context->farm->name ); ?></p>
					<?php if ( ! empty( $fc['slogan'] ) ) : ?><p class="sp-farm-card__slogan"><?php echo esc_html( $fc['slogan'] ); ?></p><?php endif; ?>
					<p class="sp-text-muted sp-text-small"><?php echo esc_html( implode( ' · ', array_filter( [ $fc['location'] ?? '', $fc['region'] ?? '', $s['breeder'] ? sprintf( /* translators: %s: number */ __( 'Breeder #%s', 'southpoll-membership' ), $s['breeder'] ) : '' ] ) ) ); ?></p>
				</div>
			</div>
			<div class="sp-farm-card__badges">
				<span class="sp-badge <?php echo ! empty( $s['visible'] ) ? 'sp-badge--success' : 'sp-badge--warning'; ?>"><?php echo esc_html( $vis_label ); ?></span>
				<span class="sp-badge sp-badge--muted"><span class="sp-light sp-light--<?php echo esc_attr( $fc['light'] ?? 'off' ); ?>" aria-hidden="true"></span> <?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d active listing', '%d active listings', (int) ( $fc['active_listings'] ?? 0 ), 'southpoll-membership' ), (int) ( $fc['active_listings'] ?? 0 ) ) ); ?></span>
				<?php if ( ! empty( $ms['state'] ) ) : ?><span class="sp-badge <?php echo ! empty( $ms['active'] ) ? ( ! empty( $ms['expiring'] ) ? 'sp-badge--warning' : 'sp-badge--success' ) : 'sp-badge--danger'; ?>"><?php echo esc_html( sprintf( /* translators: %s: status */ __( 'Membership: %s', 'southpoll-membership' ), (string) $ms['state'] ) ); ?><?php echo ! empty( $ms['ends_display'] ) ? esc_html( ' · ' . ( ! empty( $ms['active'] ) ? sprintf( /* translators: %s: date */ __( 'ends %s', 'southpoll-membership' ), $ms['ends_display'] ) : $ms['ends_display'] ) ) : ''; ?></span><?php endif; ?>
			</div>
			<dl class="sp-dl sp-farm-card__dl">
				<?php if ( ! empty( $fc['owner_name'] ) ) : ?><dt><?php esc_html_e( 'Contact', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( $fc['owner_name'] ); ?></dd><?php endif; ?>
				<?php if ( $s['phone'] || $s['email'] ) : ?><dt><?php esc_html_e( 'Reach us', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( implode( ' · ', array_filter( [ $s['phone'], $s['email'] ] ) ) ); ?></dd><?php endif; ?>
				<?php if ( $s['website'] ) : ?><dt><?php esc_html_e( 'Website', 'southpoll-membership' ); ?></dt><dd><a href="<?php echo esc_url( $s['website'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( preg_replace( '#^https?://#', '', $s['website'] ) ); ?></a></dd><?php endif; ?>
				<dt><?php esc_html_e( 'Offerings', 'southpoll-membership' ); ?></dt><dd><?php echo ! empty( $fc['offerings'] ) ? esc_html( implode( ', ', array_map( static fn( $o ) => (string) $o['name'], $fc['offerings'] ) ) ) : '<span class="sp-text-muted">' . esc_html__( 'none selected yet', 'southpoll-membership' ) . '</span>'; ?></dd>
				<dt><?php esc_html_e( 'Page', 'southpoll-membership' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d gallery photo', '%d gallery photos', (int) ( $s['photos'] ?? 0 ), 'southpoll-membership' ), (int) ( $s['photos'] ?? 0 ) ) ); ?> · <?php echo ! empty( $s['contact_on'] ) ? esc_html__( 'contact form on', 'southpoll-membership' ) : esc_html__( 'contact form off', 'southpoll-membership' ); ?></dd>
			</dl>
			<?php if ( ! empty( $s['missing'] ) ) : ?>
				<p class="sp-text-muted sp-text-small"><?php echo esc_html( sprintf( /* translators: %s: list */ __( 'Make your page stand out: add %s.', 'southpoll-membership' ), implode( ', ', $s['missing'] ) ) ); ?></p>
			<?php else : ?>
				<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Your farm page is complete. Keep it fresh with new photos.', 'southpoll-membership' ); ?></p>
			<?php endif; ?>
			<div class="sp-cluster">
				<a class="sp-btn sp-btn--outline sp-btn--sm" href="<?php echo esc_url( $s['url'] ?? $urls->farm( (string) $context->farm->slug ) ); ?>"><?php esc_html_e( 'View farm page', 'southpoll-membership' ); ?></a>
				<a class="sp-btn sp-btn--ghost sp-btn--sm" href="<?php echo esc_url( $urls->dashboard( 'manage-membership' ) ); ?>"><?php esc_html_e( 'Membership', 'southpoll-membership' ); ?></a>
			</div>
		</div>
		<style>
			.sp-farm-card__head { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 10px; }
			.sp-farm-card__logo { width: 84px; height: 63px; flex: none; display: flex; align-items: center; justify-content: center; background: var(--sp-bg-alt); border: 1px solid var(--sp-border-light); border-radius: var(--sp-radius-sm); overflow: hidden; font-family: var(--sp-font-heading); font-weight: 600; color: var(--sp-maroon); }
			.sp-farm-card__logo img { width: 100%; height: 100%; object-fit: contain; padding: 4px; }
			.sp-farm-card__text { min-width: 0; }
			.sp-farm-card__name { font-family: var(--sp-font-heading); font-weight: 700; font-size: 1.1rem; margin: 0 0 2px; }
			.sp-farm-card__slogan { font-style: italic; color: var(--sp-body); margin: 0 0 4px; font-size: .9rem; }
			.sp-farm-card__badges { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
			.sp-farm-card__badges .sp-light { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 2px; vertical-align: middle; }
			.sp-farm-card__dl { font-size: .9rem; margin: 0 0 10px; }
		</style>
		<?php
		break;
endswitch;
