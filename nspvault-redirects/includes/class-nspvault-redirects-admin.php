<?php
/**
 * Admin UI for NSPVault Redirects.
 *
 * @package NSPVault_Redirects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the management screen (Tools -> NSPVault Redirects) and processes
 * form submissions for manual redirects, deletions, and settings.
 */
class NSPVault_Redirects_Admin {

	const PAGE_SLUG = 'nspvault-redirects';

	/**
	 * Data-access layer.
	 *
	 * @var NSPVault_Redirects_DB
	 */
	private $db;

	/**
	 * Transient-backed admin notices.
	 *
	 * @var array
	 */
	private $notices = array();

	/**
	 * Constructor.
	 *
	 * @param NSPVault_Redirects_DB $db Data-access layer.
	 */
	public function __construct( NSPVault_Redirects_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Register admin hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NSPVAULT_REDIRECTS_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add the settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url  = admin_url( 'tools.php?page=' . self::PAGE_SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage', 'nspvault-redirects' ) . '</a>';
		array_unshift( $links, $link );

		return $links;
	}

	/**
	 * Register the Tools submenu page.
	 */
	public function register_page() {
		add_management_page(
			__( 'NSPVault Redirects', 'nspvault-redirects' ),
			__( 'NSPVault Redirects', 'nspvault-redirects' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Current admin page URL, optionally with extra query args.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	private function page_url( $args = array() ) {
		$args = wp_parse_args( $args, array( 'page' => self::PAGE_SLUG ) );

		return add_query_arg( $args, admin_url( 'tools.php' ) );
	}

	/**
	 * Process add/delete/settings form submissions.
	 */
	public function handle_actions() {
		if ( ! isset( $_REQUEST['page'] ) || self::PAGE_SLUG !== $_REQUEST['page'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Add a manual redirect.
		if ( isset( $_POST['nspvault_action'] ) && 'add' === $_POST['nspvault_action'] ) {
			check_admin_referer( 'nspvault_add_redirect' );

			$source = isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ) ) : '';
			$target = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : '';
			$code   = isset( $_POST['status_code'] ) ? absint( $_POST['status_code'] ) : 301;

			$result = $this->db->add_manual( $source, $target, $code );
			if ( is_wp_error( $result ) ) {
				$this->add_notice( $result->get_error_message(), 'error' );
			} else {
				$this->add_notice( __( 'Redirect saved.', 'nspvault-redirects' ), 'success' );
			}

			$this->redirect_back();
		}

		// Delete a redirect.
		if ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] && isset( $_GET['id'] ) ) {
			$id = absint( $_GET['id'] );
			check_admin_referer( 'nspvault_delete_' . $id );

			$this->db->delete( $id );
			$this->add_notice( __( 'Redirect deleted.', 'nspvault-redirects' ), 'success' );

			$this->redirect_back();
		}

		// Quick Pause / Resume toggle.
		if ( isset( $_POST['nspvault_action'] ) && 'toggle_pause' === $_POST['nspvault_action'] ) {
			check_admin_referer( 'nspvault_toggle_pause' );

			$settings           = NSPVault_Redirects::get_settings();
			$settings['paused'] = empty( $settings['paused'] ) ? 1 : 0;
			update_option( NSPVAULT_REDIRECTS_OPTION, $settings );

			$this->add_notice(
				$settings['paused']
					? __( 'Redirects paused. Retired URLs are no longer being redirected.', 'nspvault-redirects' )
					: __( 'Redirects resumed. Retired URLs are redirecting again.', 'nspvault-redirects' ),
				$settings['paused'] ? 'warning' : 'success'
			);

			$this->redirect_back();
		}

		// Save settings.
		if ( isset( $_POST['nspvault_action'] ) && 'settings' === $_POST['nspvault_action'] ) {
			check_admin_referer( 'nspvault_settings' );

			$settings                 = NSPVault_Redirects::get_settings();
			$settings['auto_capture'] = isset( $_POST['auto_capture'] ) ? 1 : 0;
			$settings['paused']       = isset( $_POST['paused'] ) ? 1 : 0;
			$settings['status_code']  = isset( $_POST['default_status_code'] ) && in_array( absint( $_POST['default_status_code'] ), array( 301, 302 ), true )
				? absint( $_POST['default_status_code'] )
				: 301;

			$chosen = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();
			$valid  = array_keys( $this->public_post_types() );
			$settings['post_types'] = array_values( array_intersect( $chosen, $valid ) );

			update_option( NSPVAULT_REDIRECTS_OPTION, $settings );
			$this->add_notice( __( 'Settings saved.', 'nspvault-redirects' ), 'success' );

			$this->redirect_back();
		}
	}

	/**
	 * Public post types keyed by name => label.
	 *
	 * @return array
	 */
	private function public_post_types() {
		$types  = get_post_types( array( 'public' => true ), 'objects' );
		$result = array();
		foreach ( $types as $name => $object ) {
			if ( 'attachment' === $name ) {
				continue;
			}
			$result[ $name ] = $object->labels->singular_name;
		}

		return $result;
	}

	/**
	 * Queue an admin notice (stored in a transient across the redirect).
	 *
	 * @param string $message Notice text.
	 * @param string $type    success|error|warning|info.
	 */
	private function add_notice( $message, $type = 'info' ) {
		$this->notices[] = array(
			'message' => $message,
			'type'    => $type,
		);
		set_transient( 'nspvault_redirects_notices_' . get_current_user_id(), $this->notices, 60 );
	}

	/**
	 * Redirect back to the admin page after handling an action.
	 */
	private function redirect_back() {
		$args = array( 'page' => self::PAGE_SLUG );
		foreach ( array( 's', 'paged' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && '' !== $_REQUEST[ $key ] ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
			}
		}

		wp_safe_redirect( $this->page_url( $args ) );
		exit;
	}

	/**
	 * Output any queued notices.
	 */
	private function print_notices() {
		$key     = 'nspvault_redirects_notices_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );

		foreach ( $notices as $notice ) {
			$class = 'notice notice-' . sanitize_html_class( $notice['type'] ) . ' is-dismissible';
			printf(
				'<div class="%1$s"><p>%2$s</p></div>',
				esc_attr( $class ),
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Render the management page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nspvault-redirects' ) );
		}

		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$per_page = 20;

		$total = $this->db->count_rows( $search );
		$rows  = $this->db->get_rows(
			array(
				'search'   => $search,
				'per_page' => $per_page,
				'paged'    => $paged,
			)
		);

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$settings    = NSPVault_Redirects::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NSPVault Redirects', 'nspvault-redirects' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Old URLs are recorded automatically whenever you change a post\'s slug. Every historical URL always redirects in a single 301 hop to the current URL — no chains.', 'nspvault-redirects' ); ?>
			</p>

			<?php $this->print_notices(); ?>

			<?php $paused = ! empty( $settings['paused'] ); ?>
			<div style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin:16px 0; padding:14px 18px; border-radius:8px; border:1px solid <?php echo $paused ? '#f0c8c8' : '#c6e6c6'; ?>; border-left:4px solid <?php echo $paused ? '#d63638' : '#2ea043'; ?>; background:<?php echo $paused ? '#fcf2f2' : '#f2fbf3'; ?>;">
				<div>
					<strong style="font-size:15px; color:<?php echo $paused ? '#a30d0d' : '#1a7f37'; ?>;">
						<?php echo $paused ? esc_html__( 'Redirects are PAUSED', 'nspvault-redirects' ) : esc_html__( 'Redirects are Active', 'nspvault-redirects' ); ?>
					</strong>
					<div style="color:#50575e; font-size:13px; margin-top:2px;">
						<?php echo $paused
							? esc_html__( 'Retired URLs are not being redirected right now. All redirects are still saved and will work again when you resume.', 'nspvault-redirects' )
							: esc_html__( 'Retired URLs are redirecting in a single 301 hop to their current URL.', 'nspvault-redirects' ); ?>
					</div>
				</div>
				<form method="post" action="<?php echo esc_url( $this->page_url() ); ?>" style="margin:0; flex:0 0 auto;">
					<?php wp_nonce_field( 'nspvault_toggle_pause' ); ?>
					<input type="hidden" name="nspvault_action" value="toggle_pause">
					<button type="submit" class="button <?php echo $paused ? 'button-primary' : ''; ?>">
						<?php echo $paused ? esc_html__( '▶ Resume redirects', 'nspvault-redirects' ) : esc_html__( '⏸ Pause redirects', 'nspvault-redirects' ); ?>
					</button>
				</form>
			</div>

			<div style="display:flex; gap:16px; flex-wrap:wrap; margin:16px 0;">
				<div class="card" style="padding:12px 16px;">
					<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong><br>
					<?php esc_html_e( 'Total redirects', 'nspvault-redirects' ); ?>
				</div>
				<div class="card" style="padding:12px 16px;">
					<strong><?php echo esc_html( number_format_i18n( $this->db->total_hits() ) ); ?></strong><br>
					<?php esc_html_e( 'Redirects served', 'nspvault-redirects' ); ?>
				</div>
			</div>

			<h2><?php esc_html_e( 'Add a redirect', 'nspvault-redirects' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $this->page_url() ); ?>">
				<?php wp_nonce_field( 'nspvault_add_redirect' ); ?>
				<input type="hidden" name="nspvault_action" value="add">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="nspvault-source"><?php esc_html_e( 'Old URL (source)', 'nspvault-redirects' ); ?></label></th>
						<td>
							<input name="source" id="nspvault-source" type="text" class="regular-text code" placeholder="/roms/pokemon-lets-go-eevee-rom/" required>
							<p class="description"><?php esc_html_e( 'Full URL or a path beginning with /.', 'nspvault-redirects' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nspvault-target"><?php esc_html_e( 'New URL (target)', 'nspvault-redirects' ); ?></label></th>
						<td>
							<input name="target" id="nspvault-target" type="text" class="regular-text code" placeholder="/roms/pokemon-lets-go-eevee-rom-1/" required>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nspvault-code"><?php esc_html_e( 'Type', 'nspvault-redirects' ); ?></label></th>
						<td>
							<select name="status_code" id="nspvault-code">
								<option value="301"><?php esc_html_e( '301 — Permanent (recommended for SEO)', 'nspvault-redirects' ); ?></option>
								<option value="302"><?php esc_html_e( '302 — Temporary', 'nspvault-redirects' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Add redirect', 'nspvault-redirects' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Existing redirects', 'nspvault-redirects' ); ?></h2>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<p class="search-box">
					<label class="screen-reader-text" for="nspvault-search"><?php esc_html_e( 'Search redirects', 'nspvault-redirects' ); ?></label>
					<input type="search" id="nspvault-search" name="s" value="<?php echo esc_attr( $search ); ?>">
					<?php submit_button( __( 'Search', 'nspvault-redirects' ), '', '', false ); ?>
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Old URL', 'nspvault-redirects' ); ?></th>
						<th><?php esc_html_e( 'Redirects to', 'nspvault-redirects' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Code', 'nspvault-redirects' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Source', 'nspvault-redirects' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Hits', 'nspvault-redirects' ); ?></th>
						<th style="width:90px;"><?php esc_html_e( 'Actions', 'nspvault-redirects' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No redirects yet.', 'nspvault-redirects' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$delete_url = wp_nonce_url(
								$this->page_url(
									array(
										'action' => 'delete',
										'id'     => $row->id,
										's'      => $search,
										'paged'  => $paged,
									)
								),
								'nspvault_delete_' . $row->id
							);
							?>
							<tr>
								<td><code><?php echo esc_html( $row->source_path ); ?></code></td>
								<td><code><?php echo esc_html( $row->target_path ); ?></code></td>
								<td><?php echo esc_html( $row->status_code ); ?></td>
								<td><?php echo esc_html( 'manual' === $row->type ? __( 'Manual', 'nspvault-redirects' ) : __( 'Auto', 'nspvault-redirects' ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row->hits ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( $delete_url ); ?>" class="submitdelete" onclick="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'nspvault-redirects' ) ); ?>');"><?php esc_html_e( 'Delete', 'nspvault-redirects' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => $this->page_url( array( 's' => $search ) ) . '%_%',
									'format'    => '&paged=%#%',
									'current'   => $paged,
									'total'     => $total_pages,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Settings', 'nspvault-redirects' ); ?></h2>
			<form method="post" action="<?php echo esc_url( $this->page_url() ); ?>">
				<?php wp_nonce_field( 'nspvault_settings' ); ?>
				<input type="hidden" name="nspvault_action" value="settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Pause redirects', 'nspvault-redirects' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="paused" value="1" <?php checked( ! empty( $settings['paused'] ) ); ?>>
									<?php esc_html_e( 'Temporarily stop serving redirects (stored redirects are kept and resume when unchecked).', 'nspvault-redirects' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Automatic capture', 'nspvault-redirects' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_capture" value="1" <?php checked( ! empty( $settings['auto_capture'] ) ); ?>>
								<?php esc_html_e( 'Record a redirect automatically when a published post\'s slug changes.', 'nspvault-redirects' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default type', 'nspvault-redirects' ); ?></th>
						<td>
							<select name="default_status_code">
								<option value="301" <?php selected( 301, (int) $settings['status_code'] ); ?>><?php esc_html_e( '301 — Permanent', 'nspvault-redirects' ); ?></option>
								<option value="302" <?php selected( 302, (int) $settings['status_code'] ); ?>><?php esc_html_e( '302 — Temporary', 'nspvault-redirects' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Track post types', 'nspvault-redirects' ); ?></th>
						<td>
							<?php
							$selected_types = (array) $settings['post_types'];
							foreach ( $this->public_post_types() as $name => $label ) :
								$checked = empty( $selected_types ) || in_array( $name, $selected_types, true );
								?>
								<label style="display:inline-block; margin-right:14px;">
									<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( $checked ); ?>>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Leave all checked to track every public post type.', 'nspvault-redirects' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'nspvault-redirects' ) ); ?>
			</form>
		</div>
		<?php
	}
}
