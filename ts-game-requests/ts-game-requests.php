<?php
/**
 * Plugin Name: TS Game Requests
 * Description: A front-end "Request a Game" form ([request_game] shortcode) where visitors ask for games
 *              (Name, Version, Format, details, optional email). Requests are stored and managed under a
 *              "Game Requests" admin screen with a new-request notification count, status (New/Done),
 *              filters and delete. Optional email notification to the admin on each new request.
 * Version: 1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TS_REQ_DB_VERSION', '1.0' );

/* ==========================================================
 * 0. DATABASE
 * ========================================================== */
function ts_req_table() {
	global $wpdb;
	return $wpdb->prefix . 'ts_game_requests';
}

function ts_req_install() {
	global $wpdb;
	$table           = ts_req_table();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(200) NOT NULL,
		version VARCHAR(100) NOT NULL DEFAULT '',
		platform VARCHAR(100) NOT NULL DEFAULT '',
		notes TEXT NOT NULL,
		email VARCHAR(200) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'new',
		ip VARCHAR(100) NOT NULL DEFAULT '',
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY status (status)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'ts_req_db_version', TS_REQ_DB_VERSION );
}
register_activation_hook( __FILE__, 'ts_req_install' );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'ts_req_db_version' ) !== TS_REQ_DB_VERSION ) {
		ts_req_install();
	}
} );

/* ==========================================================
 * 1. SETTINGS
 * ========================================================== */
function ts_req_defaults() {
	return array(
		'intro'         => 'Can\'t find a game? Request it below and we\'ll try to add it.',
		'success'       => 'Thanks! Your request has been submitted. We\'ll do our best to add it soon.',
		'format_label'  => 'Format',
		'format_ph'     => 'NSP, XCI, NSZ…',
		'notify'        => 0,
		'notify_email'  => get_option( 'admin_email' ),
	);
}

function ts_req_get_settings() {
	$saved = get_option( 'ts_req_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( ts_req_defaults(), $saved );
}

/**
 * Count of new (unhandled) requests, for the menu bubble.
 *
 * @return int
 */
function ts_req_new_count() {
	global $wpdb;
	$table = ts_req_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" );
}

/* ==========================================================
 * 2. FRONT-END — [request_game] form
 * ========================================================== */
function ts_req_render_form() {
	$s          = ts_req_get_settings();
	$submit_url = esc_url( rest_url( 'ts-req/v1/submit' ) );
	$nonce      = wp_create_nonce( 'wp_rest' );

	ts_req_styles();

	ob_start();
	?>
	<div class="ts-req" data-url="<?php echo $submit_url; ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>"
		data-success="<?php echo esc_attr( $s['success'] ); ?>">
		<?php if ( '' !== trim( $s['intro'] ) ) : ?>
			<p class="ts-req-intro"><?php echo esc_html( $s['intro'] ); ?></p>
		<?php endif; ?>
		<form class="ts-req-form" novalidate>
			<div class="ts-req-grid">
				<label class="ts-req-field ts-req-col2">
					<span>Game Name <b>*</b></span>
					<input type="text" name="name" maxlength="200" required placeholder="e.g. Super Mario Odyssey">
				</label>
				<label class="ts-req-field">
					<span>Version <span class="ts-req-opt">(optional)</span></span>
					<input type="text" name="version" maxlength="100" placeholder="e.g. v1.3.0">
				</label>
				<label class="ts-req-field">
					<span><?php echo esc_html( $s['format_label'] ?: 'Format' ); ?> <span class="ts-req-opt">(optional)</span></span>
					<input type="text" name="platform" maxlength="100" placeholder="<?php echo esc_attr( $s['format_ph'] ); ?>">
				</label>
				<label class="ts-req-field ts-req-col2">
					<span>Your Email <span class="ts-req-opt">(optional, for updates)</span></span>
					<input type="email" name="email" maxlength="200" placeholder="you@example.com">
				</label>
				<label class="ts-req-field ts-req-col2">
					<span>Additional details <span class="ts-req-opt">(optional)</span></span>
					<textarea name="notes" rows="4" maxlength="2000" placeholder="Region, update/DLC, anything else that helps."></textarea>
				</label>
			</div>
			<?php // Honeypot: hidden from humans; bots tend to fill it. ?>
			<input type="text" name="ts_req_website" class="ts-req-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
			<div class="ts-req-actions">
				<button type="submit" class="ts-req-btn">Submit Request</button>
			</div>
			<div class="ts-req-msg" role="status" aria-live="polite"></div>
		</form>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'request_game', 'ts_req_render_form' );

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?>
	<script id="ts-req-js">
	(function(){
		var wrap = document.querySelector('.ts-req');
		if (!wrap) return;
		var form = wrap.querySelector('.ts-req-form');
		var msg  = wrap.querySelector('.ts-req-msg');
		var url   = wrap.getAttribute('data-url');
		var nonce = wrap.getAttribute('data-nonce');
		var okMsg = wrap.getAttribute('data-success');

		form.addEventListener('submit', function(e){
			e.preventDefault();
			msg.className = 'ts-req-msg';
			var name = (form.name.value || '').trim();
			if (!name){ msg.classList.add('is-error'); msg.textContent = 'Please enter a game name.'; form.name.focus(); return; }

			var btn = form.querySelector('.ts-req-btn');
			btn.disabled = true; btn.classList.add('is-loading');

			var data = {
				name: name,
				version: form.version.value,
				platform: form.platform.value,
				email: form.email.value,
				notes: form.notes.value,
				ts_req_website: form.ts_req_website.value
			};

			fetch(url, {
				method:'POST',
				headers:{ 'Content-Type':'application/json', 'Accept':'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify(data)
			})
			.then(function(r){ return r.json().then(function(j){ return { ok:r.ok, j:j }; }); })
			.then(function(res){
				if (res.ok && res.j && res.j.ok){
					form.reset();
					msg.classList.add('is-success');
					msg.textContent = okMsg;
				} else {
					msg.classList.add('is-error');
					msg.textContent = (res.j && res.j.message) ? res.j.message : 'Sorry, something went wrong. Please try again.';
				}
			})
			.catch(function(){ msg.classList.add('is-error'); msg.textContent = 'Network error. Please try again.'; })
			.finally(function(){ btn.disabled = false; btn.classList.remove('is-loading'); });
		});
	})();
	</script>
	<?php
} );

/* ==========================================================
 * 3. REST — submit
 * ========================================================== */
add_action( 'rest_api_init', function () {
	register_rest_route( 'ts-req/v1', '/submit', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'ts_req_rest_submit',
	) );
} );

function ts_req_rest_submit( $request ) {
	global $wpdb;

	$body = json_decode( $request->get_body(), true );
	if ( ! is_array( $body ) ) {
		$body = $request->get_params();
	}

	// Honeypot: real users never fill this.
	if ( ! empty( $body['ts_req_website'] ) ) {
		return array( 'ok' => true ); // silently accept, store nothing
	}

	$name = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
	$name = trim( preg_replace( '/\s+/', ' ', $name ) );
	if ( '' === $name ) {
		return new WP_Error( 'no_name', 'Please enter a game name.', array( 'status' => 400 ) );
	}

	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	// Light throttle: one submission per visitor every 20 seconds.
	$rl = 'ts_req_rl_' . md5( $ip );
	if ( get_transient( $rl ) ) {
		return new WP_Error( 'too_fast', 'You just sent a request — please wait a moment before sending another.', array( 'status' => 429 ) );
	}
	set_transient( $rl, 1, 20 );

	$email = isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '';

	$data = array(
		'name'       => mb_substr( $name, 0, 200 ),
		'version'    => isset( $body['version'] ) ? mb_substr( sanitize_text_field( $body['version'] ), 0, 100 ) : '',
		'platform'   => isset( $body['platform'] ) ? mb_substr( sanitize_text_field( $body['platform'] ), 0, 100 ) : '',
		'notes'      => isset( $body['notes'] ) ? mb_substr( sanitize_textarea_field( $body['notes'] ), 0, 2000 ) : '',
		'email'      => ( $email && is_email( $email ) ) ? $email : '',
		'status'     => 'new',
		'ip'         => $ip,
		'created_at' => current_time( 'mysql' ),
	);

	$ok = $wpdb->insert(
		ts_req_table(),
		$data,
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( ! $ok ) {
		return new WP_Error( 'db_error', 'Could not save your request. Please try again.', array( 'status' => 500 ) );
	}

	ts_req_maybe_notify( $data );

	return array( 'ok' => true );
}

/**
 * Optionally email the admin about a new request.
 *
 * @param array $data Request data.
 */
function ts_req_maybe_notify( $data ) {
	$s = ts_req_get_settings();
	if ( empty( $s['notify'] ) ) {
		return;
	}
	$to = is_email( $s['notify_email'] ) ? $s['notify_email'] : get_option( 'admin_email' );

	$lines = array(
		'A new game request was submitted on ' . get_bloginfo( 'name' ) . ':',
		'',
		'Game:    ' . $data['name'],
		'Version: ' . ( $data['version'] ?: '—' ),
		'Format:  ' . ( $data['platform'] ?: '—' ),
		'Email:   ' . ( $data['email'] ?: '—' ),
		'Details: ' . ( $data['notes'] ?: '—' ),
		'',
		'Manage: ' . admin_url( 'admin.php?page=ts-game-requests' ),
	);
	wp_mail( $to, 'New game request: ' . $data['name'], implode( "\n", $lines ) );
}

/* ==========================================================
 * 4. ADMIN
 * ========================================================== */
add_action( 'admin_menu', function () {
	$new    = ts_req_new_count();
	$bubble = $new
		? ' <span class="update-plugins count-' . (int) $new . '"><span class="plugin-count">' . number_format_i18n( $new ) . '</span></span>'
		: '';

	add_menu_page(
		'Game Requests',
		'Game Requests' . $bubble,
		'manage_options',
		'ts-game-requests',
		'ts_req_admin_page',
		'dashicons-games',
		57
	);
	add_submenu_page( 'ts-game-requests', 'Request Settings', 'Settings', 'manage_options', 'ts-req-settings', 'ts_req_settings_page' );
} );

/**
 * Handle admin actions (mark done/new, delete).
 */
add_action( 'admin_init', function () {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table = ts_req_table();

	if ( isset( $_POST['ts_req_save'] ) && check_admin_referer( 'ts_req_save' ) ) {
		$page_ids = isset( $_POST['page_ids'] ) ? array_map( 'absint', (array) $_POST['page_ids'] ) : array();
		$done     = isset( $_POST['done_ids'] ) ? array_map( 'absint', (array) $_POST['done_ids'] ) : array();
		foreach ( $page_ids as $id ) {
			$status = in_array( $id, $done, true ) ? 'done' : 'new';
			$wpdb->update( $table, array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		}
		set_transient( 'ts_req_notice', 'Saved.', 30 );
		wp_safe_redirect( ts_req_admin_redirect() );
		exit;
	}

	if ( isset( $_GET['ts_req_delete'] ) && check_admin_referer( 'ts_req_delete_' . (int) $_GET['ts_req_delete'] ) ) {
		$wpdb->delete( $table, array( 'id' => (int) $_GET['ts_req_delete'] ), array( '%d' ) );
		set_transient( 'ts_req_notice', 'Request deleted.', 30 );
		wp_safe_redirect( ts_req_admin_redirect() );
		exit;
	}
} );

function ts_req_admin_redirect() {
	$args = array( 'page' => 'ts-game-requests' );
	foreach ( array( 'status', 's', 'paged' ) as $k ) {
		if ( isset( $_REQUEST[ $k ] ) && '' !== $_REQUEST[ $k ] ) {
			$args[ $k ] = sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) );
		}
	}
	return admin_url( 'admin.php?' . http_build_query( $args ) );
}

function ts_req_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$table = ts_req_table();
	$s     = ts_req_get_settings();

	if ( $notice = get_transient( 'ts_req_notice' ) ) {
		delete_transient( 'ts_req_notice' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
	}

	$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'new';
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
	$per    = 30;
	$offset = ( $paged - 1 ) * $per;

	$where  = '1=1';
	$params = array();
	if ( 'new' === $status ) {
		$where .= " AND status = 'new'";
	} elseif ( 'done' === $status ) {
		$where .= " AND status = 'done'";
	}
	if ( '' !== $search ) {
		$where   .= ' AND (name LIKE %s OR notes LIKE %s)';
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$params[] = $like;
		$params[] = $like;
	}

	$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
	$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

	$list_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
	$rows     = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per, $offset ) ) ) );

	$n_all  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$n_new  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" );
	$n_done = $n_all - $n_new;

	$base = admin_url( 'admin.php?page=ts-game-requests' );
	?>
	<div class="wrap">
		<h1>Game Requests</h1>
		<p>Requests submitted through the <code>[request_game]</code> form. Tick <strong>Done</strong> once a request is fulfilled, then Save.</p>

		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'new', $base ) ); ?>" class="<?php echo 'new' === $status ? 'current' : ''; ?>">New <span class="count">(<?php echo esc_html( $n_new ); ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'done', $base ) ); ?>" class="<?php echo 'done' === $status ? 'current' : ''; ?>">Done <span class="count">(<?php echo esc_html( $n_done ); ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', 'all', $base ) ); ?>" class="<?php echo 'all' === $status ? 'current' : ''; ?>">All <span class="count">(<?php echo esc_html( $n_all ); ?>)</span></a></li>
		</ul>

		<form method="get" style="margin:8px 0 14px;">
			<input type="hidden" name="page" value="ts-game-requests">
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search requests…" style="min-width:240px;">
			<button class="button">Filter</button>
		</form>

		<form method="post" action="<?php echo esc_url( ts_req_admin_redirect() ); ?>">
			<?php wp_nonce_field( 'ts_req_save' ); ?>
			<input type="hidden" name="ts_req_save" value="1">

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:70px;">Done</th>
						<th>Game</th>
						<th style="width:110px;">Version</th>
						<th style="width:110px;"><?php echo esc_html( $s['format_label'] ?: 'Format' ); ?></th>
						<th>Details</th>
						<th style="width:170px;">Contact</th>
						<th style="width:150px;">Received</th>
						<th style="width:90px;">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8">No requests yet.</td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td>
									<input type="hidden" name="page_ids[]" value="<?php echo (int) $r->id; ?>">
									<input type="checkbox" name="done_ids[]" value="<?php echo (int) $r->id; ?>" <?php checked( 'done', $r->status ); ?>>
								</td>
								<td><strong><?php echo esc_html( $r->name ); ?></strong></td>
								<td><?php echo $r->version ? esc_html( $r->version ) : '<span style="color:#a7aaad;">—</span>'; ?></td>
								<td><?php echo $r->platform ? esc_html( $r->platform ) : '<span style="color:#a7aaad;">—</span>'; ?></td>
								<td><?php echo $r->notes ? esc_html( $r->notes ) : '<span style="color:#a7aaad;">—</span>'; ?></td>
								<td>
									<?php if ( $r->email ) : ?>
										<a href="mailto:<?php echo esc_attr( $r->email ); ?>"><?php echo esc_html( $r->email ); ?></a>
									<?php else : ?>
										<span style="color:#a7aaad;">—</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( mysql2date( 'M j, Y g:i a', $r->created_at ) ); ?></td>
								<td>
									<a class="submitdelete" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'ts_req_delete', (int) $r->id, ts_req_admin_redirect() ), 'ts_req_delete_' . (int) $r->id ) ); ?>" onclick="return confirm('Delete this request?');">Delete</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<p style="margin-top:12px;"><button class="button button-primary">Save changes</button></p>
		</form>

		<?php
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			) ) ) . '</div></div>';
		}
		?>
	</div>
	<?php
}

function ts_req_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['ts_req_save_settings'] ) && check_admin_referer( 'ts_req_settings' ) ) {
		$s                 = ts_req_get_settings();
		$s['intro']        = isset( $_POST['intro'] ) ? sanitize_text_field( wp_unslash( $_POST['intro'] ) ) : '';
		$s['success']      = isset( $_POST['success'] ) ? sanitize_text_field( wp_unslash( $_POST['success'] ) ) : '';
		$s['format_label'] = isset( $_POST['format_label'] ) && '' !== trim( wp_unslash( $_POST['format_label'] ) ) ? sanitize_text_field( wp_unslash( $_POST['format_label'] ) ) : 'Format';
		$s['format_ph']    = isset( $_POST['format_ph'] ) ? sanitize_text_field( wp_unslash( $_POST['format_ph'] ) ) : '';
		$s['notify']       = isset( $_POST['notify'] ) ? 1 : 0;
		$s['notify_email'] = isset( $_POST['notify_email'] ) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : get_option( 'admin_email' );
		update_option( 'ts_req_settings', $s );
		echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
	}
	$s = ts_req_get_settings();
	?>
	<div class="wrap">
		<h1>Request Settings</h1>
		<p>Place the form on any page with the shortcode <code>[request_game]</code>.</p>
		<form method="post">
			<?php wp_nonce_field( 'ts_req_settings' ); ?>
			<input type="hidden" name="ts_req_save_settings" value="1">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="intro">Intro text</label></th>
					<td><input name="intro" id="intro" type="text" class="large-text" value="<?php echo esc_attr( $s['intro'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="success">Success message</label></th>
					<td><input name="success" id="success" type="text" class="large-text" value="<?php echo esc_attr( $s['success'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="format_label">Format / Platform field</label></th>
					<td>
						<input name="format_label" id="format_label" type="text" class="regular-text" value="<?php echo esc_attr( $s['format_label'] ); ?>" placeholder="Format">
						<input name="format_ph" type="text" class="regular-text" value="<?php echo esc_attr( $s['format_ph'] ); ?>" placeholder="NSP, XCI, NSZ…">
						<p class="description">The label and example text for the third field. e.g. label <code>Platform</code>, examples <code>Windows, Android, macOS</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Email notification</th>
					<td>
						<label><input type="checkbox" name="notify" value="1" <?php checked( 1, (int) $s['notify'] ); ?>> Email me when a new request comes in</label>
						<p><input name="notify_email" type="email" class="regular-text" value="<?php echo esc_attr( $s['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></p>
						<p class="description">Where to send the notification. Leave as your admin email if unsure.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>
	</div>
	<?php
}

/* ==========================================================
 * 5. STYLES (front-end form)
 * ========================================================== */
function ts_req_styles() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style id="ts-req-styles">
	.ts-req{max-width:760px;margin:24px auto;}
	.ts-req-intro{color:#475467;font-size:15px;margin:0 0 16px;}
	.ts-req-form{background:#fff;border:1px solid #e8e8ea;border-radius:16px;padding:24px;
		box-shadow:0 1px 3px rgba(16,24,40,.06);}
	.ts-req-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
	.ts-req-col2{grid-column:1 / -1;}
	.ts-req-field{display:flex;flex-direction:column;gap:6px;}
	.ts-req-field > span{font-size:13px;font-weight:600;color:#101828;}
	.ts-req-field b{color:#e8394c;}
	.ts-req-opt{font-weight:400;color:#98a2b3;}
	.ts-req-field input,.ts-req-field textarea{
		width:100%;box-sizing:border-box;border:1px solid #d0d5dd;border-radius:10px;
		padding:11px 14px;font-size:15px;color:#101828;background:#fff;outline:none;
		transition:border-color .15s ease,box-shadow .15s ease;font-family:inherit;}
	.ts-req-field input:focus,.ts-req-field textarea:focus{
		border-color:#e8394c;box-shadow:0 0 0 3px rgba(232,57,76,.12);}
	.ts-req-field textarea{resize:vertical;min-height:96px;}
	.ts-req-hp{position:absolute !important;left:-9999px !important;width:1px;height:1px;opacity:0;}
	.ts-req-actions{margin-top:18px;}
	.ts-req-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;
		background:#e8394c;color:#fff;border:0;font-weight:700;font-size:15px;cursor:pointer;
		padding:12px 26px;border-radius:10px;box-shadow:0 2px 8px rgba(232,57,76,.26);
		transition:background .18s ease;}
	.ts-req-btn:hover{background:#cf2a3c;}
	.ts-req-btn:disabled,.ts-req-btn.is-loading{opacity:.7;cursor:default;}
	.ts-req-msg{margin-top:14px;font-size:14px;font-weight:600;}
	.ts-req-msg.is-success{color:#15803d;}
	.ts-req-msg.is-error{color:#b42318;}
	@media (max-width:560px){
		.ts-req-grid{grid-template-columns:1fr;}
		.ts-req-form{padding:18px;}
	}
	</style>
	<?php
}
