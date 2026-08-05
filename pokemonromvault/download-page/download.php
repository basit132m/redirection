<?php
/**
 * External download page for Pokemon ROM Vault.
 *
 * Upload this to your download/redirect domain as download.php. It reads
 * ?p=POST_ID (and optional &s=SOURCE), fetches the ROM's links + info from
 * WordPress (/wp-json/prv/v1/links/<id>) server-side, shows a 10-second timer
 * bar, then reveals the download links. Background is always white; brand
 * colour is Pokemon yellow (#FFCD0A).
 *
 * ---------------------------------------------------------------------------
 * CONFIG: every WordPress site allowed to feed this page.
 *   key   = the "Source ID" set in WP -> Settings -> PRV Game Box
 *   value = that site's base URL (no trailing slash)
 * ---------------------------------------------------------------------------
 */
$ALLOWED_SOURCES = array(
	'pokemonromvault' => 'https://www.pokemonromvault.com',
	// Add more sites here if this same download.php serves them.
);
$DEFAULT_SOURCE = 'pokemonromvault';

// Seconds the timer bar runs before links are revealed.
$TIMER_SECONDS = 10;

/* ------------------------------------------------------------------ */

// Keep this page out of search indexes (authoritative HTTP header).
if ( ! headers_sent() ) {
	header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
}

$post_id   = isset( $_GET['p'] ) ? (int) $_GET['p'] : 0;
$source_id = isset( $_GET['s'] ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $_GET['s'] ) ) : '';
if ( '' === $source_id ) {
	$source_id = $DEFAULT_SOURCE;
}

$data  = null;
$error = '';

if ( $post_id <= 0 ) {
	$error = 'No ROM specified.';
} elseif ( ! isset( $ALLOWED_SOURCES[ $source_id ] ) ) {
	$error = 'Unknown source.';
} else {
	$endpoint = rtrim( $ALLOWED_SOURCES[ $source_id ], '/' ) . '/wp-json/prv/v1/links/' . $post_id;
	$body     = dl_fetch( $endpoint );

	if ( false === $body ) {
		$error = 'Could not load download links right now. Please try again shortly.';
	} else {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['links'] ) ) {
			$error = 'No downloads are available for this ROM.';
		} else {
			$data = $decoded;
		}
	}
}

/**
 * Fetch a URL server-side (cURL, with a file_get_contents fallback).
 *
 * @param string $url URL.
 * @return string|false
 */
function dl_fetch( $url ) {
	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT        => 12,
				CURLOPT_CONNECTTIMEOUT => 6,
				CURLOPT_USERAGENT      => 'PRV-Download-Page/1.0',
				CURLOPT_HTTPHEADER     => array( 'Accept: application/json' ),
			)
		);
		$res  = curl_exec( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		return ( false !== $res && $code >= 200 && $code < 300 ) ? $res : false;
	}

	$ctx = stream_context_create(
		array(
			'http' => array( 'timeout' => 12, 'header' => "Accept: application/json\r\n" ),
			'ssl'  => array( 'verify_peer' => true, 'verify_peer_name' => true ),
		)
	);
	$res = @file_get_contents( $url, false, $ctx );
	return ( false === $res ) ? false : $res;
}

/**
 * Group links by section, preserving order.
 *
 * @param array $links Links.
 * @return array
 */
function dl_group( $links ) {
	$groups = array();
	foreach ( $links as $link ) {
		$section = isset( $link['section'] ) && '' !== trim( $link['section'] ) ? $link['section'] : 'Download';
		$groups[ $section ][] = $link;
	}
	return $groups;
}

/**
 * Escape helper.
 *
 * @param string $s Value.
 * @return string
 */
function e( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

$title = $data ? $data['title'] : 'Download';
$image = ( $data && ! empty( $data['image'] ) ) ? $data['image'] : '';
$files = $data ? (int) $data['files'] : 0;

// Game-information rows, in display order. Only non-empty ones are shown.
$info_rows = array();
if ( $data ) {
	$labels = array(
		'genre'         => 'Genre',
		'creator'       => 'Creator',
		'version'       => 'Version',
		'hack_of'       => 'Hack of',
		'updated'       => 'Updated',
		'language'      => 'Language',
		'status'        => 'Status',
		'official_site' => 'Official Site',
	);
	foreach ( $labels as $key => $label ) {
		if ( ! empty( $data[ $key ] ) ) {
			$info_rows[ $key ] = array( 'label' => $label, 'value' => $data[ $key ] );
		}
	}
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?php echo e( $title ); ?> &mdash; Download</title>
<style>
	:root{ --yellow:#FFCD0A; --yellow-dark:#EBBD00; --ink:#1a1a1a; --muted:#6b7280; --line:#ececec; --card:#fafafa; --link:#8a6800; }
	*{ box-sizing:border-box; }
	html,body{ margin:0; padding:0; }
	body{ background:#ffffff; color:var(--ink); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; line-height:1.5; }
	.wrap{ max-width:780px; margin:0 auto; padding:28px 18px 60px; }

	/* Game info card */
	.ginfo{ display:flex; gap:22px; align-items:flex-start; border:1px solid var(--line); border-radius:16px; padding:20px 22px; margin-bottom:24px; background:var(--card); }
	.ginfo-img{ flex:0 0 auto; width:210px; max-width:42%; }
	.ginfo-img img{ width:100%; height:auto; border-radius:12px; display:block; }
	.ginfo-meta{ flex:1; min-width:0; }
	.ginfo-meta h1{ font-size:22px; margin:0 0 14px; font-weight:800; display:flex; align-items:center; gap:10px; }
	.ginfo-meta h1::before{ content:""; display:inline-block; width:5px; height:22px; background:var(--yellow); border-radius:2px; flex:0 0 5px; }
	.ginfo-meta ul{ list-style:none; margin:0; padding:0; }
	.ginfo-meta li{ display:flex; justify-content:space-between; gap:14px; padding:9px 0; border-bottom:1px solid var(--line); }
	.ginfo-meta li:last-child{ border-bottom:0; }
	.ginfo-meta li > span{ color:var(--muted); font-size:14px; }
	.ginfo-meta li > strong{ font-size:14px; text-align:right; word-break:break-word; }
	.ginfo-meta a{ color:var(--link); text-decoration:none; }
	.ginfo-meta a:hover{ text-decoration:underline; }

	/* Timer */
	.timer{ text-align:center; border:1px solid var(--line); border-radius:16px; padding:34px 20px; margin-bottom:26px; background:var(--card); }
	.timer .lead{ font-weight:700; font-size:18px; margin-bottom:16px; }
	.timer .count{ font-size:34px; font-weight:800; color:var(--ink); margin-bottom:16px; }
	.bar{ height:12px; background:#eee; border-radius:999px; overflow:hidden; max-width:420px; margin:0 auto; }
	.bar > span{ display:block; height:100%; width:0%; background:var(--yellow); border-radius:999px; transition:width 1s linear; }
	.timer .note{ color:var(--muted); font-size:13px; margin-top:14px; }

	/* Links */
	#links{ display:none; }
	.section{ border:1px solid var(--line); border-radius:14px; overflow:hidden; margin-bottom:18px; }
	.section > h2{ margin:0; padding:14px 18px; font-size:16px; background:#faf3d6; border-bottom:1px solid var(--line); }
	.rows{ display:flex; flex-direction:column; }
	.row{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 18px; text-decoration:none; color:var(--ink); border-bottom:1px solid var(--line); transition:background .15s; }
	.row:last-child{ border-bottom:0; }
	.row:hover{ background:#fffbe8; }
	.row .left{ display:flex; align-items:center; gap:12px; min-width:0; }
	.row .dot{ width:34px; height:34px; border-radius:50%; background:var(--yellow); color:var(--ink); display:flex; align-items:center; justify-content:center; font-size:16px; font-weight:800; flex:0 0 auto; }
	.row .label{ font-weight:700; font-size:15px; }
	.row .right{ display:flex; align-items:center; gap:10px; color:var(--muted); font-size:13px; }
	.row .chev{ color:var(--link); font-size:18px; font-weight:800; }

	.err{ text-align:center; border:1px solid var(--line); border-radius:14px; padding:40px 20px; color:var(--muted); }
	.footer{ text-align:center; color:var(--muted); font-size:12px; margin-top:30px; }
	@media (max-width:600px){ .ginfo{ flex-direction:column; } .ginfo-img{ width:100%; max-width:100%; } .row .right .type{ display:none; } }
</style>
</head>
<body>
<div class="wrap">

	<?php if ( $error ) : ?>
		<div class="err"><?php echo e( $error ); ?></div>
	<?php else : ?>

		<div class="ginfo">
			<?php if ( $image ) : ?>
				<div class="ginfo-img"><img src="<?php echo e( $image ); ?>" alt="<?php echo e( $title ); ?>"></div>
			<?php endif; ?>
			<div class="ginfo-meta">
				<h1><?php echo e( $title ); ?></h1>
				<ul>
					<?php foreach ( $info_rows as $key => $row ) : ?>
						<li>
							<span><?php echo e( $row['label'] ); ?></span>
							<strong>
								<?php
								if ( 'official_site' === $key && filter_var( $row['value'], FILTER_VALIDATE_URL ) ) {
									$host = parse_url( $row['value'], PHP_URL_HOST );
									echo '<a href="' . e( $row['value'] ) . '" target="_blank" rel="nofollow noopener">' . e( $host ? $host : $row['value'] ) . '</a>';
								} else {
									echo e( $row['value'] );
								}
								?>
							</strong>
						</li>
					<?php endforeach; ?>
					<li><span>Files</span><strong><?php echo (int) $files; ?></strong></li>
				</ul>
			</div>
		</div>

		<!-- Timer -->
		<div class="timer" id="timer">
			<div class="lead">Preparing your download&hellip;</div>
			<div class="count"><span id="count"><?php echo (int) $TIMER_SECONDS; ?></span></div>
			<div class="bar"><span id="barfill"></span></div>
			<div class="note">Your links will appear automatically.</div>
		</div>

		<!-- Links -->
		<div id="links">
			<?php foreach ( dl_group( $data['links'] ) as $section => $rows ) : ?>
				<div class="section">
					<h2><?php echo e( $section ); ?></h2>
					<div class="rows">
						<?php foreach ( $rows as $link ) : ?>
							<a class="row" href="<?php echo e( $link['url'] ); ?>" target="_blank" rel="nofollow noopener">
								<span class="left">
									<span class="dot">&#8681;</span>
									<span class="label"><?php echo e( $link['title'] ? $link['title'] : 'Download' ); ?></span>
								</span>
								<span class="right">
									<?php if ( ! empty( $link['type'] ) ) : ?><span class="type"><?php echo e( $link['type'] ); ?></span><?php endif; ?>
									<?php if ( ! empty( $link['size'] ) ) : ?><span><?php echo e( $link['size'] ); ?></span><?php endif; ?>
									<span class="chev">&rsaquo;</span>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="footer">If a download doesn&rsquo;t start, disable your ad-blocker and try again.</div>

		<script>
		(function(){
			var total = <?php echo (int) $TIMER_SECONDS; ?>;
			var left  = total;
			var countEl = document.getElementById('count');
			var barEl   = document.getElementById('barfill');
			var timerEl = document.getElementById('timer');
			var linksEl = document.getElementById('links');

			requestAnimationFrame(function(){
				barEl.style.transition = 'width ' + total + 's linear';
				barEl.style.width = '100%';
			});

			var iv = setInterval(function(){
				left--;
				if (left <= 0){
					clearInterval(iv);
					countEl.textContent = '0';
					timerEl.style.display = 'none';
					linksEl.style.display = 'block';
					return;
				}
				countEl.textContent = left;
			}, 1000);
		})();
		</script>

	<?php endif; ?>

</div>
</body>
</html>
