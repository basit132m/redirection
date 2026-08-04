<?php
/**
 * External download page.
 *
 * Upload this file to the OTHER domain (e.g. dlsitex.online) as download.php.
 * It reads ?p=POST_ID (and optional &s=SOURCE), fetches the game's links from
 * your WordPress site's REST endpoint, shows a 10-second timer bar, then reveals
 * all the links as buttons. Background is always white.
 *
 * ---------------------------------------------------------------------------
 * CONFIG: list every WordPress site allowed to feed this page.
 *   key   = the "Source ID" you set in WP Settings -> TS Download Box
 *   value = that site's base URL (no trailing slash)
 * If you leave Source ID blank in WordPress, the DEFAULT_SOURCE below is used.
 * ---------------------------------------------------------------------------
 */
// Keep this page out of search indexes. Sent as an HTTP header (authoritative,
// applies even before the HTML <meta name="robots"> renders below).
if ( ! headers_sent() ) {
	header( 'X-Robots-Tag: noindex, nofollow', true );
}

$ALLOWED_SOURCES = array(
	'nspvault' => 'https://www.nspvault.com',
	// Add more sites here if this same download.php serves them, e.g.
	// 'repacklabs' => 'https://repacklabs.net',
);
$DEFAULT_SOURCE = 'nspvault';

// Seconds the timer bar runs before links are revealed.
$TIMER_SECONDS = 10;

/* ------------------------------------------------------------------ */

$post_id   = isset( $_GET['p'] ) ? (int) $_GET['p'] : 0;
$source_id = isset( $_GET['s'] ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $_GET['s'] ) ) : '';

if ( '' === $source_id ) {
	$source_id = $DEFAULT_SOURCE;
}

$data  = null;
$error = '';

if ( $post_id <= 0 ) {
	$error = 'No game specified.';
} elseif ( ! isset( $ALLOWED_SOURCES[ $source_id ] ) ) {
	$error = 'Unknown source.';
} else {
	$endpoint = rtrim( $ALLOWED_SOURCES[ $source_id ], '/' ) . '/wp-json/tsdl/v1/links/' . $post_id;
	$body     = dl_fetch( $endpoint );

	if ( false === $body ) {
		$error = 'Could not load download links right now. Please try again shortly.';
	} else {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['links'] ) ) {
			$error = 'No downloads are available for this item.';
		} else {
			$data = $decoded;
		}
	}
}

/**
 * Fetch a URL server-side (cURL, with a file_get_contents fallback).
 *
 * @param string $url URL.
 * @return string|false Response body or false on failure.
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
				CURLOPT_USERAGENT      => 'TS-Download-Page/1.0',
				CURLOPT_HTTPHEADER     => array( 'Accept: application/json' ),
			)
		);
		$res  = curl_exec( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		if ( false !== $res && $code >= 200 && $code < 300 ) {
			return $res;
		}
		return false;
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
 * Group flat links by their "section" field, preserving order.
 *
 * @param array $links Links.
 * @return array [ section_name => [links...] ]
 */
function dl_group( $links ) {
	$groups = array();
	foreach ( $links as $link ) {
		$section = isset( $link['section'] ) && '' !== trim( $link['section'] ) ? $link['section'] : 'Downloads';
		$groups[ $section ][] = $link;
	}
	return $groups;
}

$title   = $data ? $data['title'] : 'Download';
$version = $data && ! empty( $data['version'] ) ? $data['version'] : '';
$tsize   = $data && ! empty( $data['size'] ) ? $data['size'] : '';
$files   = $data ? (int) $data['files'] : 0;
$image    = $data && ! empty( $data['image'] ) ? $data['image'] : '';
$genre    = $data && ! empty( $data['genre'] ) ? $data['genre'] : '';
$title_id = $data && ! empty( $data['title_id'] ) ? $data['title_id'] : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo htmlspecialchars( $title, ENT_QUOTES ); ?> — Download</title>
<style>
	:root{ --red:#e8394c; --red-dark:#cf2a3c; --ink:#1a1a1a; --muted:#6b7280; --line:#ececec; --card:#fafafa; }
	*{ box-sizing:border-box; }
	html,body{ margin:0; padding:0; }
	/* Background is always white, per requirement. */
	body{ background:#ffffff; color:var(--ink); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; line-height:1.5; }
	.wrap{ max-width:760px; margin:0 auto; padding:28px 18px 60px; }

	/* Game info card: featured image + details */
	.ginfo{ display:flex; gap:22px; align-items:flex-start; border:1px solid var(--line); border-radius:16px; padding:20px 22px; margin-bottom:26px; background:var(--card); }
	.ginfo-img{ flex:0 0 auto; width:210px; max-width:42%; }
	.ginfo-img img{ width:100%; height:auto; border-radius:12px; display:block; }
	.ginfo-meta{ flex:1; min-width:0; }
	.ginfo-meta h1{ font-size:22px; margin:0 0 14px; font-weight:800; }
	.ginfo-meta ul{ list-style:none; margin:0; padding:0; }
	.ginfo-meta li{ display:flex; justify-content:space-between; gap:14px; padding:9px 0; border-bottom:1px solid var(--line); }
	.ginfo-meta li:last-child{ border-bottom:0; }
	.ginfo-meta li > span{ color:var(--muted); font-size:14px; }
	.ginfo-meta li > strong{ font-size:14px; text-align:right; word-break:break-word; }
	@media (max-width:600px){ .ginfo{ flex-direction:column; } .ginfo-img{ width:100%; max-width:100%; } }

	/* Timer card */
	.timer{ text-align:center; border:1px solid var(--line); border-radius:16px; padding:34px 20px; margin-bottom:26px; background:var(--card); }
	.timer .lead{ font-weight:700; font-size:18px; margin-bottom:16px; }
	.timer .count{ font-size:34px; font-weight:800; color:var(--red); margin-bottom:16px; }
	.bar{ height:12px; background:#eee; border-radius:999px; overflow:hidden; max-width:420px; margin:0 auto; }
	.bar > span{ display:block; height:100%; width:0%; background:var(--red); border-radius:999px; transition:width 1s linear; }
	.timer .note{ color:var(--muted); font-size:13px; margin-top:14px; }

	/* Links */
	#links{ display:none; }
	.section{ border:1px solid var(--line); border-radius:14px; overflow:hidden; margin-bottom:18px; }
	.section > h2{ margin:0; padding:14px 18px; font-size:16px; background:#f4f4f4; border-bottom:1px solid var(--line); }
	.rows{ display:flex; flex-direction:column; }
	.row{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 18px; text-decoration:none; color:var(--ink); border-bottom:1px solid var(--line); transition:background .15s; }
	.row:last-child{ border-bottom:0; }
	.row:hover{ background:#fdf2f4; }
	.row .left{ display:flex; align-items:center; gap:12px; min-width:0; }
	.row .dot{ width:34px; height:34px; border-radius:50%; background:var(--red); color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; flex:0 0 auto; }
	.row .label{ font-weight:700; font-size:15px; }
	.row .right{ display:flex; align-items:center; gap:10px; color:var(--muted); font-size:13px; }
	.row .chev{ color:var(--red); font-size:18px; }

	.err{ text-align:center; border:1px solid var(--line); border-radius:14px; padding:40px 20px; color:var(--muted); }
	.footer{ text-align:center; color:var(--muted); font-size:12px; margin-top:30px; }
	@media (max-width:480px){ .row{ flex-direction:row; } .row .right .type{ display:none; } }
</style>
</head>
<body>
<div class="wrap">

	<?php if ( $error ) : ?>
		<div class="err"><?php echo htmlspecialchars( $error, ENT_QUOTES ); ?></div>
	<?php else : ?>

		<div class="ginfo">
			<?php if ( $image ) : ?>
				<div class="ginfo-img"><img src="<?php echo htmlspecialchars( $image, ENT_QUOTES ); ?>" alt="<?php echo htmlspecialchars( $title, ENT_QUOTES ); ?>"></div>
			<?php endif; ?>
			<div class="ginfo-meta">
				<h1><?php echo htmlspecialchars( $title, ENT_QUOTES ); ?></h1>
				<ul>
					<?php if ( $genre ) : ?><li><span>Genre</span><strong><?php echo htmlspecialchars( $genre, ENT_QUOTES ); ?></strong></li><?php endif; ?>
					<?php if ( $tsize ) : ?><li><span>Game Size</span><strong><?php echo htmlspecialchars( $tsize, ENT_QUOTES ); ?></strong></li><?php endif; ?>
					<?php if ( $version ) : ?><li><span>Version</span><strong><?php echo htmlspecialchars( $version, ENT_QUOTES ); ?></strong></li><?php endif; ?>
					<?php if ( $title_id ) : ?><li><span>Title ID</span><strong><?php echo htmlspecialchars( $title_id, ENT_QUOTES ); ?></strong></li><?php endif; ?>
					<li><span>Files</span><strong><?php echo (int) $files; ?></strong></li>
				</ul>
			</div>
		</div>

		<!-- Timer -->
		<div class="timer" id="timer">
			<div class="lead">Preparing your download…</div>
			<div class="count"><span id="count"><?php echo (int) $TIMER_SECONDS; ?></span></div>
			<div class="bar"><span id="barfill"></span></div>
			<div class="note">Your links will appear automatically.</div>
		</div>

		<!-- Links (revealed after timer) -->
		<div id="links">
			<?php foreach ( dl_group( $data['links'] ) as $section => $rows ) : ?>
				<div class="section">
					<h2><?php echo htmlspecialchars( $section, ENT_QUOTES ); ?></h2>
					<div class="rows">
						<?php foreach ( $rows as $link ) : ?>
							<a class="row" href="<?php echo htmlspecialchars( $link['url'], ENT_QUOTES ); ?>" target="_blank" rel="nofollow noopener">
								<span class="left">
									<span class="dot">&#8681;</span>
									<span class="label"><?php echo htmlspecialchars( $link['title'] ?: 'Download', ENT_QUOTES ); ?></span>
								</span>
								<span class="right">
									<?php if ( ! empty( $link['type'] ) ) : ?><span class="type"><?php echo htmlspecialchars( $link['type'], ENT_QUOTES ); ?></span><?php endif; ?>
									<?php if ( ! empty( $link['size'] ) ) : ?><span><?php echo htmlspecialchars( $link['size'], ENT_QUOTES ); ?></span><?php endif; ?>
									<span class="chev">&rsaquo;</span>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="footer">If a download doesn’t start, disable your ad-blocker and try again.</div>

		<script>
		(function(){
			var total = <?php echo (int) $TIMER_SECONDS; ?>;
			var left  = total;
			var countEl = document.getElementById('count');
			var barEl   = document.getElementById('barfill');
			var timerEl = document.getElementById('timer');
			var linksEl = document.getElementById('links');

			// Kick the bar to 100% over the full duration.
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
