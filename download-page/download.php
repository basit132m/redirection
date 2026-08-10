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
	header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
}

$ALLOWED_SOURCES = array(
	// key = the "Source ID" set in WP -> Settings -> TS Download Box
	// value = that WordPress site's base URL (no trailing slash).
	'nspvault' => 'https://www.nspvault.com',
	// Add each site this download.php serves, e.g. for the LG site:
	// 'lg' => 'https://your-lg-wordpress-site.com',
);
// The source used when the button link has no &s= parameter. Point this at the
// WordPress site that owns the games shown on THIS download domain.
$DEFAULT_SOURCE = 'nspvault';

// Seconds the timer bar runs before links are revealed.
$TIMER_SECONDS = 10;

// Set to true temporarily to see the exact reason on the error screen
// (which endpoint was called and the HTTP status). Turn OFF in production.
$DEBUG = false;

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
	$fetch_info = array();
	$body       = dl_fetch( $endpoint, $fetch_info );

	if ( false === $body ) {
		$error = 'Could not load download links right now. Please try again shortly.';
		if ( ! empty( $DEBUG ) ) {
			$error .= ' [debug: GET ' . $endpoint . ' → HTTP ' . ( isset( $fetch_info['code'] ) ? (int) $fetch_info['code'] : 0 )
				. ( ! empty( $fetch_info['error'] ) ? ' — ' . $fetch_info['error'] : '' ) . ']';
		}
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
function dl_fetch( $url, &$info = array() ) {
	$info = array( 'code' => 0, 'error' => '' );
	$ua   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	if ( function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT        => 12,
				CURLOPT_CONNECTTIMEOUT => 6,
				CURLOPT_USERAGENT      => $ua,
				CURLOPT_HTTPHEADER     => array( 'Accept: application/json' ),
			)
		);
		$res  = curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$cerr = curl_error( $ch );
		curl_close( $ch );

		$info['code'] = $code;
		if ( false !== $res && $code >= 200 && $code < 300 ) {
			return $res;
		}
		if ( false === $res ) {
			$info['error'] = '' !== $cerr ? $cerr : 'request failed (no response)';
		} else {
			$info['error'] = 'unexpected HTTP status';
		}
		return false;
	}

	$ctx = stream_context_create(
		array(
			'http' => array(
				'timeout'       => 12,
				'user_agent'    => $ua,
				'header'        => "Accept: application/json\r\n",
				'ignore_errors' => true,
			),
			'ssl'  => array( 'verify_peer' => true, 'verify_peer_name' => true ),
		)
	);
	$res = @file_get_contents( $url, false, $ctx );

	// Parse the HTTP status line from $http_response_header when available.
	if ( isset( $http_response_header ) && is_array( $http_response_header ) ) {
		foreach ( $http_response_header as $h ) {
			if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $h, $m ) ) {
				$info['code'] = (int) $m[1];
			}
		}
	}

	if ( false === $res ) {
		$err            = error_get_last();
		$info['error']  = ( $err && ! empty( $err['message'] ) ) ? $err['message'] : 'request failed';
		return false;
	}
	if ( $info['code'] && ( $info['code'] < 200 || $info['code'] >= 300 ) ) {
		$info['error'] = 'unexpected HTTP status';
		return false;
	}
	return $res;
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
<meta name="robots" content="noindex, nofollow, noarchive">
<title><?php echo htmlspecialchars( $title, ENT_QUOTES ); ?> — Download</title>
<style>
	:root{
		--accent:#e8394c; --accent2:#ff6a5a; --accent-soft:#fff1f2;
		--ink:#0f172a; --muted:#64748b; --line:#eceef3; --card:#ffffff;
		--ok:#15803d; --ok-soft:#ecfdf3; --ok-line:#d1fadf;
		--radius:20px; --shadow:0 12px 34px rgba(15,23,42,.08);
	}
	*{ box-sizing:border-box; }
	html,body{ margin:0; padding:0; }
	body{
		color:var(--ink); line-height:1.55;
		font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
		background:#f5f7fc;
		background:
			radial-gradient(900px 500px at 12% -8%, #eef2ff 0%, rgba(238,242,255,0) 60%),
			radial-gradient(900px 500px at 100% 0%, #fff1f2 0%, rgba(255,241,242,0) 55%),
			linear-gradient(180deg, #f7f9fe 0%, #eef1f8 100%);
		min-height:100vh;
	}
	.wrap{ max-width:720px; margin:0 auto; padding:34px 18px 64px; }

	.brandbar{ display:flex; align-items:center; justify-content:center; gap:8px; color:var(--muted); font-size:13px; font-weight:600; margin:0 0 22px; }
	.brandbar svg{ width:16px; height:16px; color:var(--accent); }

	.card{ background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); }

	/* Game info */
	.ginfo{ display:flex; gap:22px; align-items:flex-start; padding:22px; margin-bottom:22px; position:relative; overflow:hidden; }
	.ginfo::before{ content:""; position:absolute; inset:0 0 auto 0; height:5px; background:linear-gradient(90deg,var(--accent),var(--accent2)); }
	.ginfo-img{ flex:0 0 auto; width:220px; max-width:42%; }
	.ginfo-img img{ width:100%; height:auto; border-radius:14px; display:block; box-shadow:0 10px 26px rgba(15,23,42,.16); }
	.ginfo-meta{ flex:1; min-width:0; }
	.ginfo-meta h1{ font-size:23px; line-height:1.25; margin:2px 0 14px; font-weight:800; letter-spacing:-.01em; }
	.ginfo-meta ul{ list-style:none; margin:0; padding:0; }
	.ginfo-meta li{ display:flex; align-items:center; justify-content:space-between; gap:14px; padding:10px 0; border-bottom:1px solid var(--line); }
	.ginfo-meta li:last-child{ border-bottom:0; }
	.ginfo-meta li > span{ color:var(--muted); font-size:14px; }
	.ginfo-meta li > strong{ font-size:14px; text-align:right; word-break:break-word; font-weight:700; }
	.pill{ padding:3px 10px; border-radius:999px; font-size:12.5px; font-weight:800; }
	.pill-size{ color:var(--accent); background:var(--accent-soft); }
	.pill-ver{ color:var(--ok); background:var(--ok-soft); border:1px solid var(--ok-line); }
	.mono{ font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:12.5px; letter-spacing:.02em; color:#334155; }
	@media (max-width:600px){ .ginfo{ flex-direction:column; } .ginfo-img{ width:100%; max-width:100%; } }

	/* Countdown */
	.timer{ text-align:center; padding:34px 20px; margin-bottom:22px; }
	.ring{ position:relative; width:132px; height:132px; margin:0 auto 16px; }
	.ring svg{ width:132px; height:132px; transform:rotate(-90deg); }
	.ring .track{ fill:none; stroke:#eef1f6; stroke-width:10; }
	.ring .prog{ fill:none; stroke:url(#g); stroke-width:10; stroke-linecap:round; stroke-dasharray:326.726; stroke-dashoffset:0; }
	.ring .num{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:40px; font-weight:800; color:var(--ink); }
	.timer .lead{ font-weight:800; font-size:19px; margin:2px 0 4px; }
	.timer .note{ color:var(--muted); font-size:13.5px; }

	/* Links */
	#links{ display:none; }
	#links.show{ display:block; animation:fade .35s ease both; }
	@keyframes fade{ from{ opacity:0; transform:translateY(8px);} to{ opacity:1; transform:none; } }
	.section{ margin-bottom:18px; overflow:hidden; }
	.section > h2{ margin:0; padding:15px 20px; font-size:15px; font-weight:800; display:flex; align-items:center; gap:9px; color:#0f172a; border-bottom:1px solid var(--line); }
	.section > h2 svg{ width:17px; height:17px; color:var(--accent); }
	.rows{ display:flex; flex-direction:column; }
	.row{ display:flex; align-items:center; justify-content:space-between; gap:12px; padding:15px 20px; text-decoration:none; color:var(--ink); border-bottom:1px solid var(--line); transition:background .16s ease; }
	.row:last-child{ border-bottom:0; }
	.row:hover{ background:var(--accent-soft); }
	.row .left{ display:flex; align-items:center; gap:13px; min-width:0; }
	.row .dot{ width:40px; height:40px; border-radius:12px; background:linear-gradient(135deg,var(--accent),var(--accent2)); color:#fff; display:flex; align-items:center; justify-content:center; flex:0 0 auto; box-shadow:0 6px 14px rgba(232,57,76,.30); }
	.row .dot svg{ width:19px; height:19px; }
	.row .label{ font-weight:800; font-size:15px; }
	.row .sub{ font-size:12.5px; color:var(--muted); }
	.row .right{ display:flex; align-items:center; gap:9px; }
	.badge{ font-size:11.5px; font-weight:800; color:#475569; background:#f1f5f9; border:1px solid #e6eaf1; border-radius:999px; padding:3px 10px; white-space:nowrap; }
	.badge-type{ color:var(--accent); background:var(--accent-soft); border-color:#fbdfe3; text-transform:uppercase; letter-spacing:.3px; }
	.row .chev{ color:#cbd5e1; flex:0 0 auto; }
	.row:hover .chev{ color:var(--accent); }

	.trust{ display:flex; align-items:center; justify-content:center; gap:8px; color:var(--muted); font-size:12.5px; font-weight:600; margin:20px 0 0; }
	.trust svg{ width:15px; height:15px; color:var(--ok); }

	.err{ text-align:center; padding:44px 22px; color:var(--muted); font-size:15px; }
	.footer{ text-align:center; color:#94a3b8; font-size:12.5px; margin-top:26px; }
	@media (max-width:480px){ .row .right .badge-type{ display:none; } }
</style>
</head>
<body>
<div class="wrap">

	<div class="brandbar">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z"/></svg>
		Secure download portal
	</div>

	<?php if ( $error ) : ?>
		<div class="card err"><?php echo htmlspecialchars( $error, ENT_QUOTES ); ?></div>
	<?php else : ?>

		<div class="card ginfo">
			<?php if ( $image ) : ?>
				<div class="ginfo-img"><img src="<?php echo htmlspecialchars( $image, ENT_QUOTES ); ?>" alt="<?php echo htmlspecialchars( $title, ENT_QUOTES ); ?>"></div>
			<?php endif; ?>
			<div class="ginfo-meta">
				<h1><?php echo htmlspecialchars( $title, ENT_QUOTES ); ?></h1>
				<ul>
					<?php if ( $genre ) : ?><li><span>Genre</span><strong><?php echo htmlspecialchars( $genre, ENT_QUOTES ); ?></strong></li><?php endif; ?>
					<?php if ( $tsize ) : ?><li><span>Game Size</span><strong class="pill pill-size"><?php echo htmlspecialchars( $tsize, ENT_QUOTES ); ?></strong></li><?php endif; ?>
					<?php if ( $version ) : ?><li><span>Version</span><strong class="pill pill-ver"><?php echo htmlspecialchars( $version, ENT_QUOTES ); ?></strong></li><?php endif; ?>
					<?php if ( $title_id ) : ?><li><span>Title ID</span><strong class="mono"><?php echo htmlspecialchars( $title_id, ENT_QUOTES ); ?></strong></li><?php endif; ?>
					<li><span>Files</span><strong><?php echo (int) $files; ?></strong></li>
				</ul>
			</div>
		</div>

		<!-- Countdown -->
		<div class="card timer" id="timer">
			<div class="ring">
				<svg viewBox="0 0 120 120">
					<defs><linearGradient id="g" x1="0" y1="0" x2="120" y2="120" gradientUnits="userSpaceOnUse">
						<stop offset="0" stop-color="#e8394c"/><stop offset="1" stop-color="#ff6a5a"/>
					</linearGradient></defs>
					<circle class="track" cx="60" cy="60" r="52"/>
					<circle class="prog" id="ring" cx="60" cy="60" r="52"/>
				</svg>
				<span class="num" id="count"><?php echo (int) $TIMER_SECONDS; ?></span>
			</div>
			<div class="lead">Preparing your download…</div>
			<div class="note">Your links will appear automatically.</div>
		</div>

		<!-- Links (revealed after timer) -->
		<div id="links">
			<?php foreach ( dl_group( $data['links'] ) as $section => $rows ) : ?>
				<div class="card section">
					<h2>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>
						<?php echo htmlspecialchars( $section, ENT_QUOTES ); ?>
					</h2>
					<div class="rows">
						<?php foreach ( $rows as $link ) : ?>
							<a class="row" href="<?php echo htmlspecialchars( $link['url'], ENT_QUOTES ); ?>" target="_blank" rel="nofollow noopener">
								<span class="left">
									<span class="dot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg></span>
									<span>
										<span class="label"><?php echo htmlspecialchars( $link['title'] ?: 'Download', ENT_QUOTES ); ?></span>
										<span class="sub">Click to download</span>
									</span>
								</span>
								<span class="right">
									<?php if ( ! empty( $link['type'] ) ) : ?><span class="badge badge-type"><?php echo htmlspecialchars( $link['type'], ENT_QUOTES ); ?></span><?php endif; ?>
									<?php if ( ! empty( $link['size'] ) ) : ?><span class="badge"><?php echo htmlspecialchars( $link['size'], ENT_QUOTES ); ?></span><?php endif; ?>
									<span class="chev"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><polyline points="9 6 15 12 9 18"></polyline></svg></span>
								</span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<div class="trust">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
				Links verified &amp; safe to download
			</div>
		</div>

		<div class="footer">If a download doesn’t start, disable your ad-blocker and try again.</div>

		<script>
		(function(){
			var total = <?php echo (int) $TIMER_SECONDS; ?>;
			var left  = total;
			var C = 326.726; // 2·π·r (r=52)
			var countEl = document.getElementById('count');
			var ringEl  = document.getElementById('ring');
			var timerEl = document.getElementById('timer');
			var linksEl = document.getElementById('links');

			// Deplete the ring smoothly over the full duration.
			requestAnimationFrame(function(){
				ringEl.style.transition = 'stroke-dashoffset ' + total + 's linear';
				ringEl.style.strokeDashoffset = C;
			});

			var iv = setInterval(function(){
				left--;
				if (left <= 0){
					clearInterval(iv);
					countEl.textContent = '0';
					timerEl.style.display = 'none';
					linksEl.classList.add('show');
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
