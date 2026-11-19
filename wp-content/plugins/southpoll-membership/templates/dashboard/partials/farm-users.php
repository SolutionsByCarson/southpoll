<?php
/**
 * Owner-only users manager (Alpine + REST).
 *
 * @var array  $users
 * @var \SouthPoll\Membership\Members\MemberContext $context
 * @var object $farm
 */

defined( 'ABSPATH' ) || exit;

$sp_rows = array_map( fn( $r ) => [
	'id'                => (int) $r->id,
	'role'              => $r->role,
	'name'              => $r->display_name,
	'email'             => $r->email,
	'pending'           => $r->is_pending,
	'notify_listings'   => (bool) $r->notify_listings,
	'notify_membership' => (bool) $r->notify_membership,
	'is_me'             => (int) ( $r->user_id ?? 0 ) === $context->user_id,
], $users );
$sp_can = $context->can( 'manage_farm_users' );
?>
<div class="sp-card" x-data="spFarmUsers(<?php echo esc_attr( (string) wp_json_encode( [ 'items' => $sp_rows, 'canManage' => $sp_can ] ) ); ?>)">
	<div class="sp-card__header">
		<h2 class="sp-card__title" style="margin:0"><?php esc_html_e( 'Farm users', 'southpoll-membership' ); ?></h2>
		<span class="sp-badge" x-text="items.length + ' <?php echo esc_js( __( 'people', 'southpoll-membership' ) ); ?>'"></span>
	</div>
	<p class="sp-text-muted sp-text-small"><?php esc_html_e( 'Account managers can edit this farm page and manage listings. Only you can change membership, billing, and users. Choose who gets listing and membership emails.', 'southpoll-membership' ); ?></p>

	<?php if ( ! $sp_can ) : ?>
		<div class="sp-alert sp-alert--warning"><?php esc_html_e( 'Inviting and removing users requires an active membership.', 'southpoll-membership' ); ?></div>
	<?php endif; ?>

	<div class="sp-table-wrap">
		<table class="sp-table">
			<thead><tr><th><?php esc_html_e( 'Person', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Role', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Listing emails', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Membership emails', 'southpoll-membership' ); ?></th><th class="sp-table__actions"></th></tr></thead>
			<tbody>
				<template x-for="u in items" :key="u.id">
					<tr :class="{ 'is-pending': u.pending }">
						<td><strong x-text="u.name || u.email"></strong><div class="sp-text-muted sp-text-small" x-show="u.name" x-text="u.email"></div><span class="sp-badge sp-badge--muted" x-show="u.pending"><?php esc_html_e( 'Invitation pending', 'southpoll-membership' ); ?></span></td>
						<td><span class="sp-badge" :class="u.role === 'owner' ? 'sp-badge--role' : ''" x-text="u.role === 'owner' ? '<?php echo esc_js( __( 'Owner', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Account manager', 'southpoll-membership' ) ); ?>'"></span></td>
						<td><label class="sp-toggle"><input type="checkbox" :checked="u.notify_listings" :disabled="!canManage || u.pending" @change="toggle(u, 'notify_listings', $event.target.checked)"><span class="sp-toggle__track"></span><span class="sp-sr-only"><?php esc_html_e( 'Listing emails', 'southpoll-membership' ); ?></span></label></td>
						<td><label class="sp-toggle"><input type="checkbox" :checked="u.notify_membership" :disabled="!canManage || u.pending" @change="toggle(u, 'notify_membership', $event.target.checked)"><span class="sp-toggle__track"></span><span class="sp-sr-only"><?php esc_html_e( 'Membership emails', 'southpoll-membership' ); ?></span></label></td>
						<td class="sp-table__actions">
							<template x-if="u.role !== 'owner' && canManage">
								<span>
									<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" x-show="u.pending" @click="resend(u)"><?php esc_html_e( 'Resend', 'southpoll-membership' ); ?></button>
									<button type="button" class="sp-btn sp-btn--ghost sp-btn--sm" @click="remove(u)" x-text="u.pending ? '<?php echo esc_js( __( 'Cancel', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Remove', 'southpoll-membership' ) ); ?>'"></button>
								</span>
							</template>
						</td>
					</tr>
				</template>
			</tbody>
		</table>
	</div>

	<form class="sp-form sp-farm-users__invite" @submit.prevent="invite()" x-show="canManage">
		<div class="sp-field" style="flex:1;margin:0"><label class="sp-label" for="fu-email"><?php esc_html_e( 'Invite an account manager by email', 'southpoll-membership' ); ?></label><input id="fu-email" class="sp-input" type="email" x-model="email" placeholder="name@example.com" required></div>
		<button class="sp-btn sp-btn--primary" type="submit" :class="{ 'is-loading': busy }" :disabled="busy"><?php esc_html_e( 'Send invitation', 'southpoll-membership' ); ?></button>
	</form>
</div>
<style>
	.sp-farm-users__invite { display: flex; gap: 12px; align-items: flex-end; margin-top: 16px; flex-wrap: wrap; }
	.sp-table tr.is-pending td { color: var(--sp-muted); }
</style>
<script>
document.addEventListener('alpine:init', function () {
	Alpine.data('spFarmUsers', function (init) {
		return {
			items: init.items || [], canManage: !!init.canManage, email: '', busy: false,
			invite: async function () {
				if (!this.email) return; this.busy = true;
				try { const r = await SP.api('farm-users/invite', { method: 'POST', body: { email: this.email } }); this.items = r.items; this.email = ''; SP.toast.success(r.message); }
				catch (e) { SP.toast.error(e.message); }
				this.busy = false;
			},
			resend: async function (u) {
				try { const r = await SP.api('farm-users/' + u.id + '/resend', { method: 'POST' }); this.items = r.items; SP.toast.success(r.message); } catch (e) { SP.toast.error(e.message); }
			},
			remove: async function (u) {
				const ok = await SP.confirm({ title: u.pending ? '<?php echo esc_js( __( 'Cancel invitation?', 'southpoll-membership' ) ); ?>' : '<?php echo esc_js( __( 'Remove this account manager?', 'southpoll-membership' ) ); ?>', message: (u.name || u.email) + ' <?php echo esc_js( __( 'will lose access to this farm. Their account stays active as a buyer.', 'southpoll-membership' ) ); ?>', confirmLabel: '<?php echo esc_js( __( 'Remove', 'southpoll-membership' ) ); ?>', danger: true });
				if (!ok) return;
				try { const r = await SP.api('farm-users/' + u.id, { method: 'DELETE' }); this.items = r.items; SP.toast.success(r.message); } catch (e) { SP.toast.error(e.message); }
			},
			toggle: async function (u, key, value) {
				const body = {}; body[key] = value;
				try { const r = await SP.api('farm-users/' + u.id, { method: 'PATCH', body: body }); this.items = r.items; } catch (e) { SP.toast.error(e.message); }
			}
		};
	});
});
</script>
