<?php
/**
 * Admin → SouthPoll → Meta Fields.
 *   Farm fields tab:    flat list (label, type, options, default, required, active, order).
 *   Listing fields tab: field groups (title + categories they apply to) each holding fields (spec: "field groups by
 *                       category or all"; no defaults).
 * Phase 4 (T4.7) may add drag ordering; everything else admins need is here.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Admin\Pages;

use SouthPoll\Membership\Core\Capabilities;
use SouthPoll\Membership\Core\MetaFieldRepository;
use SouthPoll\Membership\Listings\ListingTypes;

final class MetaFieldsPage {

	public const SLUG   = 'southpoll-meta-fields';
	private const NONCE = 'sp_meta_fields_action';

	public function __construct( private MetaFieldRepository $fields, private ListingTypes $types ) {}

	public function handle_actions(): void {
		if ( empty( $_POST['sp_mf_action'] ) || ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			return;
		}
		check_admin_referer( self::NONCE );
		$action = sanitize_key( wp_unslash( $_POST['sp_mf_action'] ) );
		$scope  = isset( $_POST['scope'] ) && 'listing' === $_POST['scope'] ? 'listing' : 'farm';
		$id     = isset( $_POST['field_id'] ) ? (int) $_POST['field_id'] : 0;
		$gid    = isset( $_POST['group_id'] ) ? (int) $_POST['group_id'] : 0;
		$in     = [
			'label'         => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
			'type'          => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'text',
			'options'       => isset( $_POST['options'] ) ? sanitize_textarea_field( wp_unslash( $_POST['options'] ) ) : '',
			'default_value' => isset( $_POST['default_value'] ) ? sanitize_text_field( wp_unslash( $_POST['default_value'] ) ) : '',
			'required'      => ! empty( $_POST['required'] ),
			'active'        => ! empty( $_POST['active'] ),
			'group_id'      => $gid ?: null,
		];
		$cats   = isset( $_POST['categories'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['categories'] ) ) : [];
		$title  = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$msg    = $action;
		$res    = null;
		switch ( $action ) {
			case 'add':
				$res = $this->fields->create( $scope, $in );
				break;
			case 'update':
				$this->fields->update( $id, $in );
				break;
			case 'delete':
				$this->fields->delete( $id );
				break;
			case 'up':
				$this->fields->move( $id, -1 );
				break;
			case 'down':
				$this->fields->move( $id, 1 );
				break;
			case 'toggle':
				$f = $this->fields->find( $id );
				if ( $f ) {
					$this->fields->update( $id, [ 'active' => ! (int) $f->active ] );
				}
				break;
			case 'add_group':
				$res = $this->fields->create_group( 'listing', $title, $cats );
				break;
			case 'update_group':
				$this->fields->update_group( $gid, $title, $cats );
				break;
			case 'delete_group':
				$this->fields->delete_group( $gid );
				break;
			case 'group_up':
				$this->fields->move_group( $gid, -1 );
				break;
			case 'group_down':
				$this->fields->move_group( $gid, 1 );
				break;
		}
		if ( is_wp_error( $res ) ) {
			$msg = 'error&sp_msg=' . rawurlencode( $res->get_error_message() );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&scope=' . $scope . '&sp_done=' . $msg ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'southpoll-membership' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$scope = isset( $_GET['scope'] ) && 'listing' === $_GET['scope'] ? 'listing' : 'farm';
		$done  = isset( $_GET['sp_done'] ) ? sanitize_key( wp_unslash( $_GET['sp_done'] ) ) : '';
		$msg   = isset( $_GET['sp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_msg'] ) ) : '';
		$edit  = isset( $_GET['edit'] ) ? $this->fields->find( (int) $_GET['edit'] ) : null;
		$egrp  = isset( $_GET['edit_group'] ) ? $this->fields->find_group( (int) $_GET['edit_group'] ) : null;
		// phpcs:enable
		$types = [ 'text' => __( 'Text', 'southpoll-membership' ), 'textarea' => __( 'Paragraph', 'southpoll-membership' ), 'select' => __( 'Dropdown', 'southpoll-membership' ), 'bool' => __( 'Yes / No', 'southpoll-membership' ) ];
		$base  = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<div class="wrap sp-admin">
			<h1><?php esc_html_e( 'Meta Fields', 'southpoll-membership' ); ?></h1>
			<?php if ( 'error' === $done ) : ?><div class="notice notice-error"><p><?php echo esc_html( $msg ); ?></p></div><?php elseif ( $done ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'southpoll-membership' ); ?></p></div><?php endif; ?>
			<nav class="nav-tab-wrapper" style="margin-bottom:16px">
				<a class="nav-tab<?php echo 'farm' === $scope ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&scope=farm' ); ?>"><?php esc_html_e( 'Farm page fields', 'southpoll-membership' ); ?></a>
				<a class="nav-tab<?php echo 'listing' === $scope ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&scope=listing' ); ?>"><?php esc_html_e( 'Listing field groups', 'southpoll-membership' ); ?></a>
			</nav>
			<?php if ( 'farm' === $scope ) : ?>
				<?php $this->render_farm( $types, $edit, $base ); ?>
			<?php else : ?>
				<?php $this->render_listing( $types, $edit, $egrp, $base ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string, string> $types */
	private function render_farm( array $types, ?object $edit, string $base ): void {
		$fields = $this->fields->for_scope( 'farm', false );
		?>
		<p class="description" style="max-width:760px"><?php esc_html_e( 'These fields appear on the My Farm editor and, when filled in, in the "Farm details" table on each farm page. Order here is the display order. Deleting a field also deletes every farm’s value for it; switch it off instead to hide it without losing data.', 'southpoll-membership' ); ?></p>
		<?php $this->fields_table( $fields, $types, 'farm', $base ); ?>
		<h2 style="margin-top:28px"><?php echo $edit ? esc_html( sprintf( /* translators: %s: label */ __( 'Edit field: %s', 'southpoll-membership' ), (string) $edit->label ) ) : esc_html__( 'Add a farm field', 'southpoll-membership' ); ?></h2>
		<?php $this->field_form( $types, $edit, 'farm', 0, $base ); ?>
		<?php
	}

	/** @param array<string, string> $types */
	private function render_listing( array $types, ?object $edit, ?object $egrp, string $base ): void {
		$groups     = $this->fields->groups( 'listing' );
		$categories = $this->types->categories();
		$cat_names  = [];
		foreach ( $categories as $c ) {
			$cat_names[ $c->id ] = $c->name;
		}
		?>
		<p class="description" style="max-width:760px"><?php esc_html_e( 'Listing fields live in groups. A group applies to every listing, or only to listings in the categories you pick; each group appears as its own section on the listing editor and on the listing page. There are no default groups — add what the association needs (for example "Animal details": age, sex, registration number).', 'southpoll-membership' ); ?></p>
		<?php if ( empty( $groups ) ) : ?><p><em><?php esc_html_e( 'No field groups yet.', 'southpoll-membership' ); ?></em></p><?php endif; ?>
		<?php foreach ( $groups as $i => $g ) : ?>
			<div class="sp-admin__group" style="background:#fff;border:1px solid #ddd;padding:14px 16px;margin:12px 0;max-width:1100px">
				<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
					<div><strong style="font-size:15px"><?php echo esc_html( (string) $g->title ); ?></strong><br><small><?php echo empty( $g->category_term_ids ) ? esc_html__( 'Applies to all categories', 'southpoll-membership' ) : esc_html( __( 'Applies to: ', 'southpoll-membership' ) . implode( ', ', array_map( static fn( $id ) => $cat_names[ $id ] ?? '#' . $id, $g->category_term_ids ) ) ); ?></small></div>
					<form method="post" style="display:flex;gap:6px;align-items:center"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="scope" value="listing"><input type="hidden" name="group_id" value="<?php echo esc_attr( (string) $g->id ); ?>">
						<button class="button button-small" name="sp_mf_action" value="group_up" <?php disabled( 0 === $i ); ?>>&uarr;</button><button class="button button-small" name="sp_mf_action" value="group_down" <?php disabled( $i === count( $groups ) - 1 ); ?>>&darr;</button>
						<a class="button button-small" href="<?php echo esc_url( $base . '&scope=listing&edit_group=' . (int) $g->id ); ?>"><?php esc_html_e( 'Edit group', 'southpoll-membership' ); ?></a>
						<a class="button button-small" href="<?php echo esc_url( $base . '&scope=listing&add_to=' . (int) $g->id . '#add-field' ); ?>"><?php esc_html_e( 'Add field', 'southpoll-membership' ); ?></a>
						<button class="button button-small button-link-delete" name="sp_mf_action" value="delete_group" onclick="return confirm('<?php echo esc_js( __( 'Delete this group, its fields, and every listing value stored for them?', 'southpoll-membership' ) ); ?>')"><?php esc_html_e( 'Delete group', 'southpoll-membership' ); ?></button>
					</form>
				</div>
				<?php $this->fields_table( $this->fields->fields_for_group( (int) $g->id, false ), $types, 'listing', $base ); ?>
			</div>
		<?php endforeach; ?>

		<h2 style="margin-top:28px"><?php echo $egrp ? esc_html( sprintf( /* translators: %s: title */ __( 'Edit group: %s', 'southpoll-membership' ), (string) $egrp->title ) ) : esc_html__( 'Add a field group', 'southpoll-membership' ); ?></h2>
		<form method="post" style="max-width:760px">
			<?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="scope" value="listing">
			<?php if ( $egrp ) : ?><input type="hidden" name="group_id" value="<?php echo esc_attr( (string) $egrp->id ); ?>"><?php endif; ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="mg-title"><?php esc_html_e( 'Group title', 'southpoll-membership' ); ?></label></th><td><input id="mg-title" name="title" class="regular-text" required value="<?php echo esc_attr( $egrp ? (string) $egrp->title : '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Animal details', 'southpoll-membership' ); ?>"></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Applies to', 'southpoll-membership' ); ?></th><td>
					<?php foreach ( $categories as $c ) : ?><label style="display:inline-block;margin:0 14px 6px 0"><input type="checkbox" name="categories[]" value="<?php echo esc_attr( (string) $c->id ); ?>"<?php checked( $egrp && in_array( $c->id, $egrp->category_term_ids, true ) ); ?>> <?php echo esc_html( $c->name ); ?></label><?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Leave every box unticked to apply the group to all listings.', 'southpoll-membership' ); ?></p>
				</td></tr>
			</table>
			<p class="submit"><button class="button button-primary" name="sp_mf_action" value="<?php echo $egrp ? 'update_group' : 'add_group'; ?>"><?php echo $egrp ? esc_html__( 'Save group', 'southpoll-membership' ) : esc_html__( 'Add group', 'southpoll-membership' ); ?></button><?php if ( $egrp ) : ?> <a class="button" href="<?php echo esc_url( $base . '&scope=listing' ); ?>"><?php esc_html_e( 'Cancel', 'southpoll-membership' ); ?></a><?php endif; ?></p>
		</form>

		<?php if ( ! empty( $groups ) ) : ?>
			<?php $add_to = isset( $_GET['add_to'] ) ? (int) $_GET['add_to'] : ( $edit ? (int) $edit->group_id : (int) $groups[0]->id ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<h2 id="add-field" style="margin-top:28px"><?php echo $edit ? esc_html( sprintf( /* translators: %s: label */ __( 'Edit field: %s', 'southpoll-membership' ), (string) $edit->label ) ) : esc_html__( 'Add a listing field', 'southpoll-membership' ); ?></h2>
			<?php $this->field_form( $types, $edit, 'listing', $add_to, $base, $groups ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param object[] $fields
	 * @param array<string, string> $types
	 */
	private function fields_table( array $fields, array $types, string $scope, string $base ): void {
		?>
		<table class="widefat striped" style="max-width:1100px;margin-top:12px">
			<thead><tr><th style="width:60px"><?php esc_html_e( 'Order', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Label', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Key', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Type', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Options / default', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Required', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Active', 'southpoll-membership' ); ?></th><th><?php esc_html_e( 'Actions', 'southpoll-membership' ); ?></th></tr></thead>
			<tbody>
			<?php if ( empty( $fields ) ) : ?><tr><td colspan="8"><?php esc_html_e( 'No fields yet.', 'southpoll-membership' ); ?></td></tr><?php endif; ?>
			<?php foreach ( $fields as $i => $f ) : ?>
				<tr<?php echo (int) $f->active ? '' : ' style="opacity:.55"'; ?>>
					<td><form method="post" style="display:inline"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>"><input type="hidden" name="field_id" value="<?php echo esc_attr( (string) $f->id ); ?>"><button class="button button-small" name="sp_mf_action" value="up" <?php disabled( 0 === $i ); ?> aria-label="<?php esc_attr_e( 'Move up', 'southpoll-membership' ); ?>">&uarr;</button> <button class="button button-small" name="sp_mf_action" value="down" <?php disabled( $i === count( $fields ) - 1 ); ?> aria-label="<?php esc_attr_e( 'Move down', 'southpoll-membership' ); ?>">&darr;</button></form></td>
					<td><strong><?php echo esc_html( (string) $f->label ); ?></strong></td>
					<td><code><?php echo esc_html( (string) $f->field_key ); ?></code></td>
					<td><?php echo esc_html( $types[ $f->type ] ?? (string) $f->type ); ?></td>
					<td><small><?php echo esc_html( 'select' === $f->type ? implode( ', ', (array) $f->options ) : (string) ( $f->default_value ?? '' ) ); ?></small></td>
					<td><?php echo (int) $f->required ? '&#10003;' : '—'; ?></td>
					<td><?php echo (int) $f->active ? '&#10003;' : '—'; ?></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( $base . '&scope=' . $scope . '&edit=' . (int) $f->id . '#add-field' ); ?>"><?php esc_html_e( 'Edit', 'southpoll-membership' ); ?></a>
						<form method="post" style="display:inline"><?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>"><input type="hidden" name="field_id" value="<?php echo esc_attr( (string) $f->id ); ?>"><button class="button button-small" name="sp_mf_action" value="toggle"><?php echo (int) $f->active ? esc_html__( 'Switch off', 'southpoll-membership' ) : esc_html__( 'Switch on', 'southpoll-membership' ); ?></button> <button class="button button-small button-link-delete" name="sp_mf_action" value="delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this field and every value stored for it?', 'southpoll-membership' ) ); ?>')"><?php esc_html_e( 'Delete', 'southpoll-membership' ); ?></button></form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<string, string> $types
	 * @param object[] $groups
	 */
	private function field_form( array $types, ?object $edit, string $scope, int $group_id, string $base, array $groups = [] ): void {
		?>
		<form method="post" style="max-width:760px">
			<?php wp_nonce_field( self::NONCE ); ?><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
			<?php if ( $edit ) : ?><input type="hidden" name="field_id" value="<?php echo esc_attr( (string) $edit->id ); ?>"><?php endif; ?>
			<table class="form-table" role="presentation">
				<?php if ( 'listing' === $scope ) : ?>
					<tr><th scope="row"><label for="mf-group"><?php esc_html_e( 'Group', 'southpoll-membership' ); ?></label></th><td><select id="mf-group" name="group_id"><?php foreach ( $groups as $g ) : ?><option value="<?php echo esc_attr( (string) $g->id ); ?>"<?php selected( $edit ? (int) $edit->group_id : $group_id, (int) $g->id ); ?>><?php echo esc_html( (string) $g->title ); ?></option><?php endforeach; ?></select></td></tr>
				<?php endif; ?>
				<tr><th scope="row"><label for="mf-label"><?php esc_html_e( 'Label', 'southpoll-membership' ); ?></label></th><td><input id="mf-label" name="label" class="regular-text" required value="<?php echo esc_attr( $edit ? (string) $edit->label : '' ); ?>"><?php if ( $edit ) : ?><p class="description"><?php echo esc_html( sprintf( /* translators: %s: key */ __( 'Key: %s (fixed once created).', 'southpoll-membership' ), (string) $edit->field_key ) ); ?></p><?php endif; ?></td></tr>
				<tr><th scope="row"><label for="mf-type"><?php esc_html_e( 'Type', 'southpoll-membership' ); ?></label></th><td><select id="mf-type" name="type"><?php foreach ( $types as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>"<?php selected( $edit ? (string) $edit->type : 'text', $k ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th scope="row"><label for="mf-options"><?php esc_html_e( 'Dropdown options', 'southpoll-membership' ); ?></label></th><td><textarea id="mf-options" name="options" class="large-text" rows="4" placeholder="<?php esc_attr_e( 'One per line (dropdown type only)', 'southpoll-membership' ); ?>"><?php echo esc_textarea( $edit ? implode( "\n", (array) $edit->options ) : '' ); ?></textarea></td></tr>
				<tr><th scope="row"><label for="mf-default"><?php esc_html_e( 'Default value', 'southpoll-membership' ); ?></label></th><td><input id="mf-default" name="default_value" class="regular-text" value="<?php echo esc_attr( $edit ? (string) ( $edit->default_value ?? '' ) : '' ); ?>"></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Flags', 'southpoll-membership' ); ?></th><td><label><input type="checkbox" name="required" value="1"<?php checked( $edit && (int) $edit->required ); ?>> <?php esc_html_e( 'Required', 'southpoll-membership' ); ?></label><br><label><input type="checkbox" name="active" value="1"<?php checked( ! $edit || (int) $edit->active ); ?>> <?php esc_html_e( 'Active', 'southpoll-membership' ); ?></label></td></tr>
			</table>
			<p class="submit">
				<button class="button button-primary" name="sp_mf_action" value="<?php echo $edit ? 'update' : 'add'; ?>"><?php echo $edit ? esc_html__( 'Save field', 'southpoll-membership' ) : esc_html__( 'Add field', 'southpoll-membership' ); ?></button>
				<?php if ( $edit ) : ?> <a class="button" href="<?php echo esc_url( $base . '&scope=' . $scope ); ?>"><?php esc_html_e( 'Cancel', 'southpoll-membership' ); ?></a><?php endif; ?>
			</p>
		</form>
		<?php
	}
}
