<?php
/**
 * Branded HTML email layout (table-based for mail clients).
 * Override: {child-theme}/southpoll-membership/emails/layout.php
 *
 * @var \SouthPoll\Membership\Email\Message $message
 * @var string $subject
 * @var string $heading
 * @var string $preheader
 * @var string $body
 * @var array|null $cta
 * @var string $footer_note
 * @var string $logo_url
 * @var string $site_name
 * @var string $home_url
 * @var array  $admin_contact
 * @var string $prefs_url
 * @var string $year
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f7f7f7;font-family:Montserrat,Arial,Helvetica,sans-serif;">
<?php if ( '' !== $preheader ) : ?>
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#f7f7f7;"><?php echo esc_html( $preheader ); ?></div>
<?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f7f7;padding:24px 12px;">
	<tr><td align="center">
		<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;">
			<tr><td style="padding:26px 32px 18px;text-align:center;">
				<a href="<?php echo esc_url( $home_url ); ?>"><img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site_name ); ?>" width="260" style="display:inline-block;max-width:260px;height:auto;border:0;"></a>
			</td></tr>
			<tr><td style="height:6px;background:#8d183c;font-size:0;line-height:0;">&nbsp;</td></tr>
			<tr><td style="padding:32px 32px 8px;">
				<h1 style="margin:0 0 18px;font-family:Oswald,'Arial Narrow',Arial,sans-serif;font-size:26px;line-height:1.2;font-weight:600;letter-spacing:-.02em;text-transform:uppercase;color:#272727;"><?php echo esc_html( $heading ); ?></h1>
				<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from kses-filtered pieces ?>
				<?php if ( $cta && ! empty( $cta['url'] ) ) : ?>
				<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;">
					<tr><td style="background:#8d183c;">
						<a href="<?php echo esc_url( $cta['url'] ); ?>" style="display:inline-block;padding:14px 26px;font-family:Montserrat,Arial,sans-serif;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#ffffff;text-decoration:none;"><?php echo esc_html( $cta['label'] ); ?></a>
					</td></tr>
				</table>
				<?php endif; ?>
			</td></tr>
			<?php if ( '' !== $footer_note ) : ?>
			<tr><td style="padding:0 32px 20px;font-size:12px;line-height:1.5;color:#6f6f6f;"><?php echo esc_html( $footer_note ); ?></td></tr>
			<?php endif; ?>
			<tr><td style="padding:20px 32px 28px;border-top:1px solid #eeeeee;font-size:12px;line-height:1.6;color:#6f6f6f;">
				<strong style="color:#272727;"><?php echo esc_html( $site_name ); ?></strong><br>
				<?php if ( ! empty( $admin_contact['name'] ) ) : ?><?php echo esc_html( (string) $admin_contact['name'] ); ?> &middot; <?php endif; ?>
				<?php if ( ! empty( $admin_contact['phone'] ) ) : ?><?php echo esc_html( (string) $admin_contact['phone'] ); ?> &middot; <?php endif; ?>
				<?php if ( ! empty( $admin_contact['email'] ) ) : ?><a href="mailto:<?php echo esc_attr( (string) $admin_contact['email'] ); ?>" style="color:#8d183c;text-decoration:none;"><?php echo esc_html( (string) $admin_contact['email'] ); ?></a><?php endif; ?>
				<br><a href="<?php echo esc_url( $home_url ); ?>" style="color:#8d183c;text-decoration:none;"><?php echo esc_html( preg_replace( '#^https?://#', '', $home_url ) ); ?></a>
				<?php if ( '' !== $prefs_url ) : ?>
				<br><br><a href="<?php echo esc_url( $prefs_url ); ?>" style="color:#6f6f6f;text-decoration:underline;"><?php esc_html_e( 'Manage email preferences', 'southpoll-membership' ); ?></a>
				<?php endif; ?>
				<br>&copy; <?php echo esc_html( $year ); ?> <?php echo esc_html( $site_name ); ?>
			</td></tr>
		</table>
	</td></tr>
</table>
</body>
</html>
