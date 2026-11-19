<?php
/**
 * Generic message page (unsubscribe confirmations and similar).
 *
 * @var string $heading
 * @var string $message
 * @var string $type    success|info|warning|error
 * @var array  $links   [ [label, href, primary?], … ]
 */

defined( 'ABSPATH' ) || exit;
?>
<header class="sp-page__header"><h1 class="sp-page__title"><?php echo esc_html( $heading ); ?></h1></header>
<div class="sp-card">
	<div class="sp-alert sp-alert--<?php echo esc_attr( in_array( $type, [ 'success', 'info', 'warning', 'error' ], true ) ? $type : 'info' ); ?>"><?php echo esc_html( $message ); ?></div>
	<p>
		<?php foreach ( $links as $link ) : ?>
			<a class="sp-btn <?php echo ! empty( $link['primary'] ) ? 'sp-btn--primary' : 'sp-btn--outline'; ?>" href="<?php echo esc_url( $link['href'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
		<?php endforeach; ?>
	</p>
</div>
