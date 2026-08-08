<?php
/**
 * Plugin Name: TS Game Info Box
 * Description: Two-column game information card (cover + info table), styled light/white.
 *              Genre = category (base: genre), Collection = tag (base: collection),
 *              Type = taxonomy (base: type), Publisher = taxonomy (base: publisher).
 *              The cover column stacks a thumbs up/down engagement bar above the
 *              cover art, then the Official Site link, then the Download button.
 *              Includes Schema.org JSON-LD (with like/dislike interaction counts).
 *              "Required Firmware" links to the [firmwares] page (pre-filtered to that version).
 * Version: 3.2
 */

if ( ! defined('ABSPATH') ) exit;

define('TS_GI_POST_TYPE', 'post'); // change if you're using a CPT for ROM posts

/* ==========================================================
 * 1. TYPE TAXONOMY — /type/nsp-roms/ etc.
 * ========================================================== */
function ts_gi_register_type_taxonomy() {
    register_taxonomy('game_type', TS_GI_POST_TYPE, [
        'label'             => 'Type',
        'hierarchical'      => false,
        'public'            => true,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => ['slug' => 'type', 'with_front' => false],
    ]);
}
add_action('init', 'ts_gi_register_type_taxonomy');

/**
 * One-time helper: creates standard Type terms so they exist as real archive pages.
 * Uncomment the add_action line once, load the site, then comment it back out.
 */
function ts_gi_seed_type_terms() {
    $terms = ['NSP' => 'nsp-roms', 'XCI' => 'xci-roms', 'NSZ' => 'nsz-roms'];
    foreach ($terms as $name => $slug) {
        if (!term_exists($name, 'game_type')) {
            wp_insert_term($name, 'game_type', ['slug' => $slug]);
        }
    }
}
// add_action('init', 'ts_gi_seed_type_terms', 20);

/* ==========================================================
 * 1b. PUBLISHER TAXONOMY — /publisher/nintendo/ etc.
 * ========================================================== */
function ts_gi_register_publisher_taxonomy() {
    register_taxonomy('game_publisher', TS_GI_POST_TYPE, [
        'label'             => 'Publisher',
        'hierarchical'      => false,
        'public'            => true,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => ['slug' => 'publisher', 'with_front' => false],
    ]);
}
add_action('init', 'ts_gi_register_publisher_taxonomy');

/* ==========================================================
 * 2. NATIVE POST META
 * ========================================================== */
function ts_gi_register_meta() {
    $fields = [
        'game_size'      => 'string',
        'game_version'   => 'string',
        'game_title_id'  => 'string',
        'game_languages' => 'string',
        'game_firmware'  => 'string',
        'game_release'   => 'string', // YYYY-MM-DD
        'game_badge'     => 'string', // optional cover badge, e.g. EXCLUSIVES
        'game_official'  => 'string', // official site URL
    ];
    foreach ($fields as $key => $type) {
        register_post_meta(TS_GI_POST_TYPE, $key, [
            'type' => $type, 'single' => true, 'show_in_rest' => true,
        ]);
    }
}
add_action('init', 'ts_gi_register_meta');

/* ==========================================================
 * 3. ADMIN META BOX
 * ========================================================== */
function ts_gi_add_meta_box() {
    add_meta_box('ts_gi_box', 'Game Information', 'ts_gi_meta_box_html', TS_GI_POST_TYPE, 'normal', 'high');
}
add_action('add_meta_boxes', 'ts_gi_add_meta_box');

function ts_gi_meta_box_html($post) {
    wp_nonce_field('ts_gi_save', 'ts_gi_nonce');
    $fields = [
        'game_size'      => 'Game Size (e.g. 4.2 GB)',
        'game_version'   => 'Version (e.g. v1.0.2)',
        'game_title_id'  => 'Title ID',
        'game_languages' => 'Languages (e.g. En, Fr, De)',
        'game_firmware'  => 'Required Firmware (e.g. 5.1.0)',
        'game_release'   => 'Release Date',
        'game_badge'     => 'Cover Badge (optional, e.g. EXCLUSIVES)',
        'game_official'  => 'Official Site URL (optional, e.g. https://...)',
    ];
    echo '<table class="form-table">';
    foreach ($fields as $key => $label) {
        $val  = get_post_meta($post->ID, $key, true);
        $type = ($key === 'game_release') ? 'date' : (($key === 'game_official') ? 'url' : 'text');
        printf(
            '<tr><th><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" style="width:100%%;" /></td></tr>',
            esc_attr($key), esc_html($label), esc_attr($type), esc_attr($val)
        );
    }
    echo '</table>';
    echo '<p class="description">Genre (Category), Collection (Tag), Type and Publisher are set in their boxes in the sidebar. The cover image comes from the post\'s Featured Image. Download links are managed in the separate "Download Links" box.</p>';
}

function ts_gi_save_meta($post_id) {
    if (!isset($_POST['ts_gi_nonce']) || !wp_verify_nonce($_POST['ts_gi_nonce'], 'ts_gi_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $keys = ['game_size','game_version','game_title_id','game_languages','game_firmware','game_release','game_badge'];
    foreach ($keys as $key) {
        if (isset($_POST[$key])) {
            update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
        }
    }
    if (isset($_POST['game_official'])) {
        update_post_meta($post_id, 'game_official', esc_url_raw(trim(wp_unslash($_POST['game_official']))));
    }
}
add_action('save_post', 'ts_gi_save_meta');

/**
 * Find the URL of the page that hosts the [firmwares] list, so the
 * "Required Firmware" value can link to it. Auto-detected (the published page
 * whose content contains the shortcode) and cached; override with the
 * 'ts_gi_firmware_page_url' filter if you prefer a fixed URL.
 *
 * @return string Page URL, or '' if none found.
 */
function ts_gi_firmware_page_url() {
    $override = apply_filters('ts_gi_firmware_page_url', null);
    if (is_string($override)) {
        return $override;
    }

    $cached = get_transient('ts_gi_fw_page_url');
    if (false !== $cached) {
        return $cached;
    }

    global $wpdb;
    $id = (int) $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type = 'page'
           AND post_content LIKE '%[firmwares%'
         ORDER BY ID ASC LIMIT 1"
    );

    $url = $id ? get_permalink($id) : '';
    set_transient('ts_gi_fw_page_url', $url, 12 * HOUR_IN_SECONDS);
    return $url;
}

// Clear the cached firmware-page URL whenever a page is saved (it may have
// gained or lost the shortcode).
add_action('save_post_page', function () {
    delete_transient('ts_gi_fw_page_url');
});

/* ==========================================================
 * 4. FRONTEND RENDER — two-column card
 * ========================================================== */
function ts_gi_render($atts = []) {
    $post_id = get_the_ID();
    if (!$post_id) return '';

    $genres      = get_the_category_list(', ', '', $post_id);
    $collections = get_the_tag_list('', ', ', '', $post_id);
    $types       = get_the_term_list($post_id, 'game_type', '', ', ');
    $publishers  = get_the_term_list($post_id, 'game_publisher', '', ', ');

    $size      = get_post_meta($post_id, 'game_size', true);
    $version   = get_post_meta($post_id, 'game_version', true);
    $title_id  = get_post_meta($post_id, 'game_title_id', true);
    $languages = get_post_meta($post_id, 'game_languages', true);
    $firmware  = get_post_meta($post_id, 'game_firmware', true);
    $release   = get_post_meta($post_id, 'game_release', true);
    $badge     = get_post_meta($post_id, 'game_badge', true);
    $official  = get_post_meta($post_id, 'game_official', true);
    $cover     = get_the_post_thumbnail_url($post_id, 'large');

    $thumbs_up   = (int) get_post_meta($post_id, 'game_thumbs_up', true);
    $thumbs_down = (int) get_post_meta($post_id, 'game_thumbs_down', true);
    $vote_url    = esc_url_raw(rest_url('ts-gi/v1/vote/' . $post_id));

    ob_start();
    ?>
    <div class="ts-gi-card">

        <div class="ts-gi-cover-col">

            <?php // Engagement bar (thumbs up / down) sits ABOVE the cover art. ?>
            <div class="ts-gi-vote" data-vote-url="<?php echo esc_attr($vote_url); ?>" data-post="<?php echo esc_attr($post_id); ?>">
                <button type="button" class="ts-gi-vote-btn ts-gi-vote-up" data-dir="up"
                        aria-label="Thumbs up" aria-pressed="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3H14zM7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3"/>
                    </svg>
                    <span class="ts-gi-vote-count" data-up><?php echo esc_html(number_format_i18n($thumbs_up)); ?></span>
                </button>
                <button type="button" class="ts-gi-vote-btn ts-gi-vote-down" data-dir="down"
                        aria-label="Thumbs down" aria-pressed="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 15v4a3 3 0 003 3l4-9V2H5.72a2 2 0 00-2 1.7l-1.38 9a2 2 0 002 2.3H10zm7-13h2.67A2.31 2.31 0 0122 4v7a2.31 2.31 0 01-2.33 2H17"/>
                    </svg>
                    <span class="ts-gi-vote-count" data-down><?php echo esc_html(number_format_i18n($thumbs_down)); ?></span>
                </button>
            </div>

            <?php if ($cover) : ?>
            <div class="ts-gi-cover">
                <img src="<?php echo esc_url($cover); ?>"
                     alt="<?php echo esc_attr(get_the_title($post_id)); ?> cover art"
                     loading="eager" />
                <?php if ($badge) : ?>
                    <span class="ts-gi-badge"><span class="ts-gi-dot"></span><?php echo esc_html($badge); ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php // Official Site link sits BELOW the cover art. ?>
            <?php if ($official && filter_var($official, FILTER_VALIDATE_URL)) : ?>
            <a class="ts-gi-official" href="<?php echo esc_url($official); ?>" target="_blank" rel="nofollow noopener">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6M15 3h6v6M10 14L21 3"/>
                </svg>
                Official Site
            </a>
            <?php endif; ?>

            <?php // Download button sits BELOW the Official Site link. ?>
            <a href="#ts-downloads" class="ts-gi-btn ts-gi-btn-cover">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download Now
            </a>

        </div>

        <div class="ts-gi-info-col">

            <h2 class="ts-gi-heading">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Game Information
            </h2>

            <div class="ts-gi-rows">
                <?php if ($genres) : ?>
                <div class="ts-gi-row">
                    <span class="ts-gi-label">Genre</span>
                    <span class="ts-gi-value ts-gi-link"><?php echo $genres; ?></span>
                </div>
                <?php endif; ?>

                <?php if ($collections) : ?>
                <div class="ts-gi-row">
                    <span class="ts-gi-label">Collection</span>
                    <span class="ts-gi-value ts-gi-link"><?php echo $collections; ?></span>
                </div>
                <?php endif; ?>

                <?php if ($types && !is_wp_error($types)) : ?>
                <div class="ts-gi-row">
                    <span class="ts-gi-label">Type</span>
                    <span class="ts-gi-value ts-gi-link"><?php echo $types; ?></span>
                </div>
                <?php endif; ?>

                <?php if ($publishers && !is_wp_error($publishers)) : ?>
                <div class="ts-gi-row">
                    <span class="ts-gi-label">Publisher</span>
                    <span class="ts-gi-value ts-gi-link"><?php echo $publishers; ?></span>
                </div>
                <?php endif; ?>

                <?php if ($size) : ?>
                <div class="ts-gi-row">
                    <span class="ts-gi-label">Game Size</span>
                    <span class="ts-gi-value ts-gi-size"><?php echo esc_html($size); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($version) : ?>
                <div class="ts-gi-row">
                    <span class="ts-gi-label">Version</span>
                    <span class="ts-gi-value ts-gi-version"><?php echo esc_html($version); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($title_id) : ?>
                <div class="ts-gi-row">
                    <span class="ts-gi-label">Title ID</span>
                    <span class="ts-gi-value ts-gi-mono"><?php echo esc_html($title_id); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($languages) : ?>
                <div class="ts-gi-row">
                    <span class="ts-gi-label">Language</span>
                    <span class="ts-gi-value"><?php echo esc_html($languages); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($firmware) : ?>
                <div class="ts-gi-row">
                    <span class="ts-gi-label">Required Firmware</span>
                    <span class="ts-gi-value ts-gi-link">
                        <?php
                        $fw_page = ts_gi_firmware_page_url();
                        if ($fw_page) {
                            printf(
                                '<a href="%s">%s</a>',
                                esc_url(add_query_arg('fw', rawurlencode($firmware), $fw_page)),
                                esc_html($firmware)
                            );
                        } else {
                            echo esc_html($firmware);
                        }
                        ?>
                    </span>
                </div>
                <?php endif; ?>

                <?php if ($release) : ?>
                <div class="ts-gi-row">
                    <span class="ts-gi-label">Release Date</span>
                    <span class="ts-gi-value"><?php echo esc_html(date_i18n('M j, Y', strtotime($release))); ?></span>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('game_info', 'ts_gi_render');

add_filter('the_content', function($content) {
    if (is_singular(TS_GI_POST_TYPE) && get_post_meta(get_the_ID(), 'game_title_id', true)) {
        $content = ts_gi_render() . $content;
    }
    return $content;
});

/* ==========================================================
 * 4b. THUMBS UP / DOWN — REST endpoint + counter
 * ========================================================== */
add_action('rest_api_init', function () {
    register_rest_route('ts-gi/v1', '/vote/(?P<id>\d+)', [
        [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => 'ts_gi_rest_vote',
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => 'ts_gi_rest_vote',
        ],
    ]);
});

function ts_gi_client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '';
    return sanitize_text_field($ip);
}

/**
 * Read (GET) or cast (POST) a thumbs up/down vote. One vote per visitor IP per
 * post is kept for 30 days via a transient, so a refresh can't inflate the count.
 */
function ts_gi_rest_vote($request) {
    $id   = (int) $request['id'];
    $post = get_post($id);
    if (!$post || 'publish' !== $post->post_status) {
        return new WP_Error('not_found', 'Not found.', ['status' => 404]);
    }

    $up   = (int) get_post_meta($id, 'game_thumbs_up', true);
    $down = (int) get_post_meta($id, 'game_thumbs_down', true);

    if ('POST' === $request->get_method()) {
        $dir = $request->get_param('dir');
        $dir = ('down' === $dir) ? 'down' : 'up';
        $key = 'ts_gi_vote_' . md5(ts_gi_client_ip() . '|' . $id);
        if (!get_transient($key)) {
            if ('down' === $dir) {
                $down++;
                update_post_meta($id, 'game_thumbs_down', $down);
            } else {
                $up++;
                update_post_meta($id, 'game_thumbs_up', $up);
            }
            set_transient($key, $dir, 30 * DAY_IN_SECONDS);
        }
    }

    return ['id' => $id, 'up' => $up, 'down' => $down];
}

add_action('wp_footer', function () {
    if (!is_singular(TS_GI_POST_TYPE)) return;
    ?>
    <script id="ts-gi-vote-js">
    (function(){
        var box = document.querySelector('.ts-gi-vote');
        if (!box) return;
        var url    = box.getAttribute('data-vote-url');
        var postId = box.getAttribute('data-post');
        var upEl   = box.querySelector('[data-up]');
        var downEl = box.querySelector('[data-down]');
        var btnUp  = box.querySelector('.ts-gi-vote-up');
        var btnDn  = box.querySelector('.ts-gi-vote-down');
        var storeKey = 'ts_gi_voted_' + postId;

        function fmt(n){ try { return Number(n).toLocaleString(); } catch(e){ return String(n); } }
        function paint(d){
            if (d && typeof d.up   !== 'undefined' && upEl)   { upEl.textContent   = fmt(d.up); }
            if (d && typeof d.down !== 'undefined' && downEl) { downEl.textContent = fmt(d.down); }
        }
        function markVoted(dir){
            box.classList.add('ts-gi-voted');
            var mine = (dir === 'down') ? btnDn : btnUp;
            if (mine) { mine.classList.add('is-active'); mine.setAttribute('aria-pressed','true'); }
        }

        // Reflect a vote this browser already cast.
        var prior = null;
        try { prior = localStorage.getItem(storeKey); } catch(e){}
        if (prior) { markVoted(prior); }

        // Load the current tally.
        fetch(url, { headers:{ 'Accept':'application/json' } })
            .then(function(r){ return r.json(); })
            .then(paint).catch(function(){});

        function vote(dir){
            if (box.classList.contains('ts-gi-voted')) return; // one vote per visitor
            markVoted(dir);
            try { localStorage.setItem(storeKey, dir); } catch(e){}
            // Optimistic bump.
            var el = (dir === 'down') ? downEl : upEl;
            if (el) { el.textContent = fmt((parseInt(el.textContent.replace(/[^0-9]/g,''),10)||0) + 1); }
            fetch(url, {
                method:'POST',
                headers:{ 'Content-Type':'application/json', 'Accept':'application/json' },
                body: JSON.stringify({ dir: dir }),
                keepalive:true
            }).then(function(r){ return r.json(); }).then(paint).catch(function(){});
        }

        if (btnUp) btnUp.addEventListener('click', function(){ vote('up'); });
        if (btnDn) btnDn.addEventListener('click', function(){ vote('down'); });
    })();
    </script>
    <?php
});

/* ==========================================================
 * 5. SCHEMA.ORG JSON-LD
 * ========================================================== */
function ts_gi_schema_output() {
    if (!is_singular(TS_GI_POST_TYPE)) return;
    $post_id  = get_the_ID();
    $title_id = get_post_meta($post_id, 'game_title_id', true);
    if (!$title_id) return;

    $cats       = wp_get_post_categories($post_id, ['fields' => 'names']);
    $types      = wp_get_post_terms($post_id, 'game_type', ['fields' => 'names']);
    $publishers = wp_get_post_terms($post_id, 'game_publisher', ['fields' => 'names']);
    $version    = get_post_meta($post_id, 'game_version', true);
    $release    = get_post_meta($post_id, 'game_release', true);
    $size       = get_post_meta($post_id, 'game_size', true);
    $langs      = get_post_meta($post_id, 'game_languages', true);
    $firmware   = get_post_meta($post_id, 'game_firmware', true);

    $schema = [
        '@context'            => 'https://schema.org',
        '@type'               => 'VideoGame',
        'name'                => get_the_title($post_id),
        'url'                 => get_permalink($post_id),
        'applicationCategory' => 'GameApplication',
        'operatingSystem'     => $firmware ? $firmware : 'Nintendo Switch',
        'offers'              => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
    ];
    if (!empty($cats))       $schema['genre'] = $cats;
    if (!empty($types))      $schema['fileFormat'] = implode(', ', $types);
    if (!empty($publishers)) $schema['publisher'] = ['@type' => 'Organization', 'name' => $publishers[0]];
    if ($version) $schema['softwareVersion'] = $version;
    if ($release) $schema['datePublished'] = date('Y-m-d', strtotime($release));
    if ($size)    $schema['fileSize'] = $size;
    if ($langs)   $schema['inLanguage'] = $langs;
    if (has_post_thumbnail($post_id)) $schema['image'] = get_the_post_thumbnail_url($post_id, 'large');

    // Thumbs up / down expressed as Schema.org interaction counts (SEO-friendly:
    // a valid representation of likes/dislikes that avoids self-serving star ratings).
    $up   = (int) get_post_meta($post_id, 'game_thumbs_up', true);
    $down = (int) get_post_meta($post_id, 'game_thumbs_down', true);
    if ($up > 0 || $down > 0) {
        $schema['interactionStatistic'] = [
            [
                '@type'                => 'InteractionCounter',
                'interactionType'      => ['@type' => 'LikeAction'],
                'userInteractionCount' => $up,
            ],
            [
                '@type'                => 'InteractionCounter',
                'interactionType'      => ['@type' => 'DislikeAction'],
                'userInteractionCount' => $down,
            ],
        ];
    }

    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
add_action('wp_head', 'ts_gi_schema_output');

/* ==========================================================
 * 6. STYLES
 * ========================================================== */
function ts_gi_styles() {
    ?>
    <style id="ts-gi-styles">
    html{scroll-behavior:smooth;}

    /* Card shell */
    .ts-gi-card{
        display:flex;flex-direction:column;
        background:#fff;border:1px solid #e8e8ea;border-radius:16px;
        overflow:hidden;margin:0 0 28px;
        box-shadow:0 1px 3px rgba(16,24,40,.06);
    }

    /* Cover column — stacks: thumbs, cover, official site, download */
    .ts-gi-cover-col{
        display:flex;flex-direction:column;align-items:center;
        gap:14px;flex-shrink:0;padding:20px;
    }
    /* Every stacked item shares the cover width so nothing overflows/overlaps. */
    .ts-gi-cover-col > *{width:200px;max-width:100%;}

    /* Thumbs up / down engagement bar (above the cover) */
    .ts-gi-vote{display:flex;gap:10px;justify-content:center;}
    .ts-gi-vote-btn{
        flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;
        background:#fff;border:1px solid #e5e7eb;border-radius:10px;
        padding:8px 10px;font-size:14px;font-weight:700;color:#475467;
        cursor:pointer;transition:border-color .15s ease,color .15s ease,background .15s ease;
    }
    .ts-gi-vote-btn svg{width:17px;height:17px;flex-shrink:0;}
    .ts-gi-vote-btn:hover{background:#f9fafb;}
    .ts-gi-vote-up:hover,.ts-gi-vote-up.is-active{border-color:#16a34a;color:#15803d;}
    .ts-gi-vote-down:hover,.ts-gi-vote-down.is-active{border-color:#e8394c;color:#c92e3f;}
    .ts-gi-voted .ts-gi-vote-btn{cursor:default;}
    .ts-gi-voted .ts-gi-vote-btn:not(.is-active){opacity:.6;}
    .ts-gi-vote-count{font-variant-numeric:tabular-nums;}

    .ts-gi-cover{
        position:relative;
        border-radius:8px;overflow:hidden;
        box-shadow:0 6px 18px rgba(16,24,40,.18);
    }
    .ts-gi-cover img{display:block;width:100%;height:auto;}
    .ts-gi-badge{
        position:absolute;top:12px;right:8px;
        display:inline-flex;align-items:center;gap:4px;
        background:rgba(0,0,0,.78);color:#fb7185;
        font-size:11px;font-weight:700;letter-spacing:.3px;
        padding:3px 7px;border-radius:4px;text-transform:uppercase;
        backdrop-filter:blur(4px);
    }
    .ts-gi-dot{width:4px;height:4px;border-radius:50%;background:#fb7185;flex-shrink:0;}

    /* Info column */
    .ts-gi-info-col{flex:1;min-width:0;padding:20px;}
    .ts-gi-heading{
        display:flex;align-items:center;gap:8px;
        font-size:17px;font-weight:700;color:#101828;
        margin:0 0 6px;padding:0;border:none;
    }
    .ts-gi-heading svg{width:17px;height:17px;color:#98a2b3;flex-shrink:0;}

    /* Rows */
    .ts-gi-rows{display:block;}
    .ts-gi-row{
        display:flex;align-items:center;justify-content:space-between;gap:16px;
        padding:12px 0;border-bottom:1px solid #f0f0f2;font-size:15px;line-height:1.4;
    }
    .ts-gi-row:last-child{border-bottom:none;}
    .ts-gi-label{color:#667085;flex-shrink:0;}
    .ts-gi-value{color:#101828;font-weight:500;text-align:right;min-width:0;}
    .ts-gi-link a{color:#e8394c;text-decoration:none;font-weight:600;}
    .ts-gi-link a:hover{color:#c92e3f;text-decoration:underline;}
    .ts-gi-size{color:#e8394c;font-weight:700;}
    .ts-gi-version{color:#15803d;font-weight:600;}
    .ts-gi-mono{
        font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;
        font-size:13px;letter-spacing:.02em;
    }

    /* Official Site link (below the cover) */
    .ts-gi-official{
        display:inline-flex;align-items:center;justify-content:center;gap:8px;
        background:#111827;color:#fff !important;
        font-size:14px;font-weight:700;text-decoration:none;
        padding:10px 16px;border-radius:10px;
        transition:background .18s ease;
    }
    .ts-gi-official:hover{background:#1f2937;}
    .ts-gi-official svg{width:15px;height:15px;flex-shrink:0;}

    /* Download button */
    .ts-gi-btn{
        display:inline-flex;align-items:center;justify-content:center;gap:8px;
        background:#e8394c;color:#fff !important;
        font-size:14px;font-weight:700;text-decoration:none;
        padding:10px 20px;border-radius:999px;
        transition:background .18s ease,box-shadow .18s ease;
        box-shadow:0 2px 8px rgba(232,57,76,.28);
    }
    .ts-gi-btn:hover{background:#c92e3f;box-shadow:0 4px 12px rgba(232,57,76,.36);}
    .ts-gi-btn svg{width:16px;height:16px;flex-shrink:0;}
    /* Full-width download in the cover column */
    .ts-gi-btn-cover{width:100%;}

    /* Side-by-side from 640px up */
    @media (min-width:640px){
        .ts-gi-card{flex-direction:row;align-items:stretch;}
        .ts-gi-info-col{padding:24px 24px 24px 4px;}
    }

    #ts-downloads{scroll-margin-top:80px;} /* raise if you have a taller sticky header */
    </style>
    <?php
}
add_action('wp_head', 'ts_gi_styles');
