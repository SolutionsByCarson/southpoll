<?php
/**
 * Admin → SouthPoll → Status: versions, tables, cron, integrations readiness, recent log lines,
 * and maintenance buttons (re-run migrations, run cron jobs now).
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\Cron;
use SouthPoll\Membership\Core\Integrations;
use SouthPoll\Membership\Core\Logger;
use SouthPoll\Membership\Core\Migrator;
use SouthPoll\Membership\Core\Schema;
use SouthPoll\Membership\Core\Settings;
use SouthPoll\Membership\Email\Mailer;
use SouthPoll\Membership\Email\Messages\TestEmail;
use SouthPoll\Membership\Farms\Geocoder;
use SouthPoll\Membership\Routing\Router;
use SouthPoll\Membership\Routing\Urls;

final class StatusPage extends AdminPage {

	public const SLUG   = 'southpoll-status';
	private const NONCE = 'sp_status_action';

	public function __construct(
		private Migrator $migrator,
		private Cron $cron,
		private Settings $settings,
		private Integrations $integrations,
		private Mailer $mailer,
		private Urls $urls,
		private Router $router,
		private Geocoder $geocoder
	) {}

	/**
	 * Handle the maintenance buttons (POST, nonce + capability checked).
	 */
	public function handle_actions(): void {
		if ( empty( $_POST['sp_status_action'] ) || ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		$action = sanitize_key( wp_unslash( $_POST['sp_status_action'] ) );
		switch ( $action ) {
			case 'migrate':
				$this->migrator->migrate();
				break;
			case 'cron_hourly':
			case 'cron_daily':
			case 'cron_queue':
				$this->cron->run_now( substr( $action, 5 ) );
				break;
			case 'digest_now':
				$r       = sp_membership()->get( \SouthPoll\Membership\Memberships\RegistryDigest::class )->run( true );
				$action .= '_add_' . $r['add'] . '_remove_' . $r['remove'] . ( $r['sent'] ? '_sent' : '_not_sent_' . sanitize_key( str_replace( ' ', '_', $r['reason'] ?: 'unknown' ) ) );
				break;
			case 'reschedule':
				Cron::unschedule();
				Cron::schedule();
				break;
			case 'test_email':
				$user = wp_get_current_user();
				$sent = $this->mailer->send( ( new TestEmail() )->to( $user->user_email )->with( [ 'sent_by' => $user->display_name, 'dashboard_url' => $this->urls->dashboard() ] ) );
				$action .= $sent ? '_sent' : '_failed';
				break;
			case 'flush_rewrite':
				$this->router->add_rewrite_rules();
				flush_rewrite_rules( false );
				break;
			case 'geocode':
				$r       = $this->geocoder->backfill( 100 );
				$action .= '_' . $r['geocoded'] . '_of_' . $r['attempted'] . ( ! empty( $r['error'] ) ? '_stopped_' . strtolower( (string) $r['error'] ) : '' ); // e.g. geocode_0_of_1_stopped_request_denied
				break;
		}
		wp_safe_redirect( add_query_arg( [ 'page' => 'southpoll-status', 'sp_done' => $action ], admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'southpoll-membership' ) );
		}
		global $wpdb;

		$done = isset( $_GET['sp_done'] ) ? sanitize_key( wp_unslash( $_GET['sp_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SouthPoll Membership — Status', 'southpoll-membership' ); ?></h1>
			<?php if ( $done ) : ?>
				<div class="notice <?php echo str_contains( $done, 'stopped' ) ? 'notice-warning' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %s: action key */ __( 'Done: %s', 'southpoll-membership' ), $done ) ); ?><?php if ( str_contains( $done, 'request_denied' ) ) : ?> <?php esc_html_e( '— Google refused the geocoding requests: enable the Geocoding API for the key’s Cloud project (Integrations → Test geocoding shows the detail). Nothing was marked as attempted.', 'southpoll-membership' ); ?><?php endif; ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Versions', 'southpoll-membership' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
					<tr><td><?php esc_html_e( 'Plugin', 'southpoll-membership' ); ?></td><td><?php echo esc_html( SP_VERSION ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Schema (code)', 'southpoll-membership' ); ?></td><td><?php echo esc_html( SP_DB_VERSION ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Schema (database)', 'southpoll-membership' ); ?></td><td><?php echo esc_html( $this->migrator->current_version() ); ?><?php echo $this->migrator->needs_migration() ? ' <span style="color:#a71000">— migration needed</span>' : ' <span style="color:#2e7d32">— up to date</span>'; ?></td></tr>
					<tr><td><?php esc_html_e( 'WordPress / PHP', 'southpoll-membership' ); ?></td><td><?php echo esc_html( get_bloginfo( 'version' ) . ' / ' . PHP_VERSION ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Activated at (UTC)', 'southpoll-membership' ); ?></td><td><?php echo esc_html( (string) get_option( 'sp_activated_at', '—' ) ); ?></td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Tables', 'southpoll-membership' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th><?php esc_html_e( 'Table', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Rows', 'southpoll-membership' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( Schema::table_names() as $table ) : ?>
					<?php $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; ?>
					<tr>
						<td><code><?php echo esc_html( $table ); ?></code></td>
						<td><?php echo $exists ? esc_html( number_format_i18n( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) ) ) : '<span style="color:#a71000">missing</span>'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Cron', 'southpoll-membership' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<thead><tr><th><?php esc_html_e( 'Job', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Next run (site time)', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Last run', 'southpoll-membership' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( Cron::next_runs() as $hook => $ts ) : ?>
					<?php $last = (int) get_option( 'sp_cron_last_' . ( Cron::DIGEST === $hook ? 'digest' : substr( $hook, 3 ) ), 0 ); ?>
					<tr>
						<td><code><?php echo esc_html( $hook ); ?></code></td>
						<td><?php echo $ts ? esc_html( wp_date( 'Y-m-d H:i', (int) $ts ) ) : '<span style="color:#a71000">not scheduled</span>'; ?></td>
						<td><?php echo $last ? esc_html( wp_date( 'Y-m-d H:i', $last ) ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Integrations', 'southpoll-membership' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<tbody>
					<tr><td><?php esc_html_e( 'Stripe', 'southpoll-membership' ); ?></td><td><?php echo esc_html( $this->integrations->stripe_mode() ); ?> mode — <?php echo $this->integrations->stripe_configured() ? 'keys present (' . esc_html( Integrations::masked( $this->integrations->stripe_publishable_key() ) ) . ')' : '<span style="color:#a71000">not configured</span>'; ?>; webhook secret <?php echo '' !== (string) $this->integrations->get( 'stripe.webhook_secret' ) ? 'set' : '<span style="color:#b2892e">missing</span>'; ?></td></tr>
					<tr><td><?php esc_html_e( 'reCAPTCHA', 'southpoll-membership' ); ?></td><td><?php echo $this->integrations->recaptcha_configured() ? 'configured' : '<span style="color:#a71000">not configured</span>'; ?></td></tr>
					<tr><td><?php esc_html_e( 'Google Maps', 'southpoll-membership' ); ?></td><td><?php echo $this->integrations->maps_configured() ? 'configured' : '<span style="color:#b2892e">not configured</span>'; ?></td></tr>
					<tr><td><?php esc_html_e( 'Email from', 'southpoll-membership' ); ?></td><td><?php echo esc_html( (string) $this->integrations->get( 'email.from_name' ) . ' <' . (string) $this->integrations->get( 'email.from_email' ) . '>' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Membership admin', 'southpoll-membership' ); ?></td><td><?php echo esc_html( (string) $this->settings->get( 'admin_contact.name' ) . ' · ' . (string) $this->settings->get( 'admin_contact.email' ) . ' · ' . (string) $this->settings->get( 'admin_contact.phone' ) ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Log directory', 'southpoll-membership' ); ?></td><td><code><?php echo esc_html( Logger::dir() ); ?></code></td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Maintenance', 'southpoll-membership' ); ?></h2>
			<form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
				<?php wp_nonce_field( self::NONCE ); ?>
				<button class="button" name="sp_status_action" value="migrate"><?php esc_html_e( 'Re-run migrations', 'southpoll-membership' ); ?></button>
				<button class="button" name="sp_status_action" value="cron_hourly"><?php esc_html_e( 'Run hourly job now', 'southpoll-membership' ); ?></button>
				<button class="button" name="sp_status_action" value="cron_daily"><?php esc_html_e( 'Run daily job now', 'southpoll-membership' ); ?></button>
				<button class="button" name="sp_status_action" value="cron_queue"><?php esc_html_e( 'Run queue now', 'southpoll-membership' ); ?></button>
				<?php $sp_digest = \SouthPoll\Membership\Memberships\RegistryDigest::status(); ?>
				<button class="button" name="sp_status_action" value="digest_now" title="<?php echo esc_attr( $sp_digest['last'] ? sprintf( /* translators: %s: datetime */ __( 'Last sent %s (UTC); sends everything since then', 'southpoll-membership' ), $sp_digest['last'] ) : __( 'Never sent yet; the first run covers the last 7 days', 'southpoll-membership' ) ); ?>"><?php esc_html_e( 'Send registry digest now', 'southpoll-membership' ); ?></button>
				<button class="button" name="sp_status_action" value="reschedule"><?php esc_html_e( 'Reschedule cron', 'southpoll-membership' ); ?></button>
				<button class="button" name="sp_status_action" value="flush_rewrite"><?php esc_html_e( 'Flush rewrite rules', 'southpoll-membership' ); ?></button>
				<button class="button" name="sp_status_action" value="geocode" <?php disabled( ! $this->geocoder->configured() ); ?> title="<?php esc_attr_e( 'Needs a Google geocoding key (Integrations)', 'southpoll-membership' ); ?>"><?php esc_html_e( 'Geocode farms missing coordinates', 'southpoll-membership' ); ?></button>
				<button class="button button-primary" name="sp_status_action" value="test_email"><?php esc_html_e( 'Send test email to me', 'southpoll-membership' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Routes', 'southpoll-membership' ); ?></h2>
			<table class="widefat striped" style="max-width:960px">
				<thead><tr><th><?php esc_html_e( 'Rewrite rule', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Query', 'southpoll-membership' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $this->router->compiled_rules() as $regex => $query ) : ?>
					<tr><td><code><?php echo esc_html( $regex ); ?></code></td><td><code><?php echo esc_html( $query ); ?></code></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php echo esc_html( sprintf( /* translators: %s: URL */ __( 'Stripe webhook URL: %s', 'southpoll-membership' ), $this->urls->stripe_webhook() ) ); ?></p>

			<h2><?php esc_html_e( 'Recent log', 'southpoll-membership' ); ?></h2>
			<pre style="max-width:960px;max-height:320px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px;font-size:12px"><?php echo esc_html( implode( "\n", Logger::tail( 40 ) ) ?: '(empty)' ); ?></pre>
		</div>
		<?php
	}
}
