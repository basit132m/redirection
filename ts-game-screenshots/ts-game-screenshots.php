<?php
/**
 * Plugin Name: TS Game Screenshots
 * Description: "Game Screenshots" box with a Nintendo-style lightbox — large viewer, prev/next arrows,
 *              and a thumbnail filmstrip at the bottom with the active shot highlighted.
 *              Critical grid layout is set via inline styles so theme CSS and CSS optimizers
 *              (LiteSpeed / Autoptimize) cannot override or strip it.
 * Version: 4.1
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Post types that get the screenshots box. Both 'post' and the 'game' CPT are
 * supported; only types that actually exist on this site are used.
 */
function ts_ss_post_types() {
    $types = apply_filters('ts_ss_post_types', ['post', 'game']);
    $types = array_values(array_filter((array) $types, 'post_type_exists'));
    return $types ? $types : ['post'];
}

/** Maximum number of screenshots per post. */
if ( ! defined('TS_SS_MAX_IMAGES') ) define('TS_SS_MAX_IMAGES', 12);

/* ==========================================================
 * 1. IMAGE SIZES — grid thumbnail + filmstrip thumbnail (16:9)
 * ========================================================== */
add_action('after_setup_theme', function() {
    add_image_size('game_screenshot', 480, 270, true);      // hard crop 16:9, sized for 4-across
    add_image_size('game_screenshot_thumb', 320, 180, true); // lightbox filmstrip
});

/* ==========================================================
 * 2. NATIVE META — comma-separated attachment IDs
 * ========================================================== */
add_action('init', function() {
    foreach (ts_ss_post_types() as $pt) {
        register_post_meta($pt, 'game_screenshots', [
            'type' => 'string', 'single' => true, 'show_in_rest' => true,
        ]);
    }
});

/* ==========================================================
 * 3. ADMIN META BOX — media uploader
 * ========================================================== */
add_action('add_meta_boxes', function() {
    foreach (ts_ss_post_types() as $pt) {
        add_meta_box('ts_ss_box', 'Game Screenshots (max ' . TS_SS_MAX_IMAGES . ')', 'ts_ss_meta_box_html', $pt, 'normal', 'high');
    }
});

function ts_ss_meta_box_html($post) {
    wp_nonce_field('ts_ss_save', 'ts_ss_nonce');
    $ids = get_post_meta($post->ID, 'game_screenshots', true);
    $max = (int) TS_SS_MAX_IMAGES;
    ?>
    <div id="ts-ss-wrap">
        <input type="hidden" id="ts_ss_ids" name="ts_ss_ids" value="<?php echo esc_attr($ids); ?>" />
        <div id="ts-ss-preview" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
            <?php
            if ($ids) {
                foreach (explode(',', $ids) as $id) {
                    $id = trim($id);
                    if (!$id) continue;
                    echo '<div class="ts-ss-thumb" data-id="' . esc_attr($id) . '" style="position:relative;">'
                       . wp_get_attachment_image($id, [110, 110], true, ['style' => 'display:block;width:110px;height:110px;object-fit:cover;border-radius:4px;'])
                       . '<span class="ts-ss-remove" style="position:absolute;top:-6px;right:-6px;background:#e8394c;color:#fff;border-radius:50%;width:18px;height:18px;text-align:center;line-height:18px;cursor:pointer;font-size:12px;">&times;</span>'
                       . '</div>';
                }
            }
            ?>
        </div>
        <button type="button" class="button button-secondary" id="ts_ss_select">Select Screenshots (max <?php echo $max; ?>)</button>
        <p class="description">Shown 4 per row under the Game Information card. Clicking one opens the full gallery with a thumbnail strip. Upload at 1280&times;720. Drag the thumbnails above to reorder.</p>
    </div>
    <script>
    jQuery(function($){
        var frame, MAX = <?php echo $max; ?>;
        function syncIds(){
            var ids = $('#ts-ss-preview .ts-ss-thumb').map(function(){ return String($(this).data('id')); }).get();
            $('#ts_ss_ids').val(ids.join(','));
        }
        $('#ts_ss_select').on('click', function(e){
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({ title: 'Select up to ' + MAX + ' screenshots', multiple: true, library: { type: 'image' } });
            frame.on('select', function(){
                var sel = frame.state().get('selection').toJSON().slice(0, MAX);
                var wrap = $('#ts-ss-preview').empty();
                sel.forEach(function(img){
                    var t = (img.sizes && img.sizes.thumbnail) ? img.sizes.thumbnail.url : img.url;
                    wrap.append('<div class="ts-ss-thumb" data-id="'+img.id+'" style="position:relative;">' +
                        '<img src="'+t+'" style="width:110px;height:110px;object-fit:cover;display:block;border-radius:4px;">' +
                        '<span class="ts-ss-remove" style="position:absolute;top:-6px;right:-6px;background:#e8394c;color:#fff;border-radius:50%;width:18px;height:18px;text-align:center;line-height:18px;cursor:pointer;font-size:12px;">&times;</span>' +
                        '</div>');
                });
                syncIds();
            });
            frame.open();
        });
        $(document).on('click', '.ts-ss-remove', function(){
            $(this).parent('.ts-ss-thumb').remove();
            syncIds();
        });
        // Reorder by dragging thumbnails.
        if ($.fn.sortable) {
            $('#ts-ss-preview').sortable({ items: '.ts-ss-thumb', cursor: 'move', update: syncIds });
        }
    });
    </script>
    <?php
}

add_action('save_post', function($post_id) {
    if (!isset($_POST['ts_ss_nonce']) || !wp_verify_nonce($_POST['ts_ss_nonce'], 'ts_ss_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    if (isset($_POST['ts_ss_ids'])) {
        $ids = array_filter(array_map('intval', explode(',', sanitize_text_field($_POST['ts_ss_ids']))));
        $ids = array_slice($ids, 0, (int) TS_SS_MAX_IMAGES);
        update_post_meta($post_id, 'game_screenshots', implode(',', $ids));
    }
});

add_action('admin_enqueue_scripts', function($hook) {
    if (in_array($hook, ['post.php', 'post-new.php'], true)) {
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
    }
});

/* ==========================================================
 * 4. FRONTEND RENDER — the on-page grid
 *
 * NOTE ON INLINE STYLES: the grid and image sizing are written as inline
 * style attributes on purpose. Inline styles have higher specificity than any
 * theme stylesheet and are not touched by CSS minify/combine plugins, so the
 * 4-across layout cannot be overridden or stripped. The <style> block below
 * only adds non-critical polish (hover) and the lightbox chrome.
 * ========================================================== */
function ts_ss_get_ids($post_id) {
    $raw = get_post_meta($post_id, 'game_screenshots', true);
    if (!$raw) return [];
    return array_filter(array_map('trim', explode(',', $raw)));
}

function ts_ss_render($atts = []) {
    $post_id = get_the_ID();
    if (!$post_id) return '';

    $ids = ts_ss_get_ids($post_id);
    if (empty($ids)) return '';

    // Critical inline styles
    $card_css = 'background:#fff;border:1px solid #e8e8ea;border-radius:16px;padding:20px 24px 24px;margin:0 0 28px;box-shadow:0 1px 3px rgba(16,24,40,.06);';
    $head_css = 'display:flex;align-items:center;gap:10px;font-size:19px;font-weight:700;color:#101828;margin:0 0 16px;padding:0;border:none;';
    $bar_css  = 'display:inline-block;width:4px;height:20px;background:#e8394c;border-radius:2px;flex:0 0 4px;';
    $grid_css = 'display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;width:100%;margin:0;padding:0;list-style:none;';
    $item_css = 'position:relative;display:block;width:100%;margin:0;padding:0;border:1px solid #ececee;border-radius:10px;overflow:hidden;cursor:zoom-in;background:#f4f4f6;aspect-ratio:16/9;box-sizing:border-box;';
    $img_css  = 'display:block;width:100%;height:100%;max-width:none;min-width:0;margin:0;padding:0;object-fit:cover;border-radius:0;';

    ob_start();
    ?>
    <div class="ts-ss-card" style="<?php echo esc_attr($card_css); ?>">
        <h2 class="ts-ss-heading" style="<?php echo esc_attr($head_css); ?>">
            <span class="ts-ss-bar" style="<?php echo esc_attr($bar_css); ?>"></span>Game Screenshots
        </h2>
        <div class="ts-ss-grid" style="<?php echo esc_attr($grid_css); ?>">
            <?php $i = 0; foreach ($ids as $id) :
                $thumb = wp_get_attachment_image_url($id, 'game_screenshot');
                if (!$thumb) $thumb = wp_get_attachment_image_url($id, 'medium_large'); // fallback if size not generated yet
                if (!$thumb) continue;
                $full  = wp_get_attachment_image_url($id, 'full');
                $strip = wp_get_attachment_image_url($id, 'game_screenshot_thumb');
                if (!$strip) $strip = $thumb;
                $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
                if (!$alt) $alt = get_the_title($post_id) . ' screenshot ' . ($i + 1);
            ?>
                <div class="ts-ss-item" style="<?php echo esc_attr($item_css); ?>"
                     data-full="<?php echo esc_url($full); ?>"
                     data-thumb="<?php echo esc_url($strip); ?>"
                     data-alt="<?php echo esc_attr($alt); ?>"
                     data-index="<?php echo (int) $i; ?>"
                     role="button" tabindex="0"
                     aria-label="<?php echo esc_attr('View screenshot: ' . $alt); ?>">
                    <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr($alt); ?>"
                         width="480" height="270" loading="lazy" decoding="async"
                         style="<?php echo esc_attr($img_css); ?>" />
                </div>
            <?php $i++; endforeach; ?>
        </div>
    </div>
    <?php
    $html = ob_get_clean();

    /**
     * Collapse whitespace between tags.
     * This filter runs before wpautop (priority 10). Given multi-line markup,
     * wpautop inserts stray </p> tags around the closing divs. Emitting a single
     * line leaves it nothing to interpret as a paragraph break. Only whitespace
     * BETWEEN tags is removed, so text content is untouched.
     */
    $html = preg_replace('/>\s+</', '><', $html);   // whitespace between tags
    $html = preg_replace('/\s*\R\s*/', ' ', $html); // remaining newlines (inside tags) -> single space

    return trim($html);
}
add_shortcode('game_screenshots', 'ts_ss_render');

/**
 * ORDERING
 * TS Game Info Box prepends its card at the default priority (10).
 * Prepending screenshots at priority 5 means the info box lands in front of it:
 *     [Game Information] [Game Screenshots] [post content]
 * No matching against the info box markup, so this can't break if that markup changes.
 */
add_filter('the_content', function($content) {
    if (is_singular(ts_ss_post_types()) && get_post_meta(get_the_ID(), 'game_screenshots', true)) {
        $content = ts_ss_render() . $content;
    }
    return $content;
}, 5);

/**
 * Should the lightbox markup/JS be printed on this request?
 */
function ts_ss_needs_lightbox() {
    return is_singular(ts_ss_post_types()) && get_post_meta(get_the_ID(), 'game_screenshots', true);
}

/* ==========================================================
 * 5. LIGHTBOX — markup printed in the footer (outside the_content,
 *    so wpautop can never touch it and it is never duplicated).
 * ========================================================== */
add_action('wp_footer', function() {
    if (!ts_ss_needs_lightbox()) return;
    ?>
    <div id="ts-ss-lb" role="dialog" aria-modal="true" aria-label="Screenshot viewer" aria-hidden="true">
        <button type="button" id="ts-ss-lb-close" aria-label="Close screenshot viewer">&times;</button>

        <div class="ts-ss-lb-stage">
            <button type="button" class="ts-ss-lb-nav ts-ss-lb-prev" aria-label="Previous screenshot">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
            </button>

            <figure class="ts-ss-lb-figure">
                <img id="ts-ss-lb-img" src="" alt="" />
            </figure>

            <button type="button" class="ts-ss-lb-nav ts-ss-lb-next" aria-label="Next screenshot">
                <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
            </button>
        </div>

        <div class="ts-ss-lb-bottom">
            <div class="ts-ss-lb-counter"><span id="ts-ss-lb-cur">1</span> / <span id="ts-ss-lb-tot">1</span></div>
            <div class="ts-ss-lb-striprow">
                <button type="button" class="ts-ss-strip-arrow ts-ss-strip-prev" aria-label="Scroll thumbnails left">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
                </button>
                <div class="ts-ss-lb-strip" id="ts-ss-lb-strip"></div>
                <button type="button" class="ts-ss-strip-arrow ts-ss-strip-next" aria-label="Scroll thumbnails right">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </button>
            </div>
        </div>
    </div>

    <script id="ts-ss-lightbox-js">
    (function(){
        var lb = document.getElementById('ts-ss-lb');
        if (!lb) return;

        var items = Array.prototype.slice.call(document.querySelectorAll('.ts-ss-item'));
        if (!items.length) return;

        var shots = items.map(function(el){
            return {
                full:  el.getAttribute('data-full'),
                thumb: el.getAttribute('data-thumb'),
                alt:   el.getAttribute('data-alt') || ''
            };
        });

        var imgEl    = document.getElementById('ts-ss-lb-img');
        var stripEl  = document.getElementById('ts-ss-lb-strip');
        var curEl    = document.getElementById('ts-ss-lb-cur');
        var totEl    = document.getElementById('ts-ss-lb-tot');
        var closeEl  = document.getElementById('ts-ss-lb-close');
        var prevEl   = lb.querySelector('.ts-ss-lb-prev');
        var nextEl   = lb.querySelector('.ts-ss-lb-next');
        var sPrevEl  = lb.querySelector('.ts-ss-strip-prev');
        var sNextEl  = lb.querySelector('.ts-ss-strip-next');
        var index    = 0;
        var lastFocus = null;

        totEl.textContent = shots.length;
        // Single image: hide the navigation chrome entirely.
        if (shots.length < 2) {
            prevEl.style.display = nextEl.style.display = 'none';
            document.querySelector('.ts-ss-lb-bottom').style.display = 'none';
        }

        // Build the filmstrip once.
        shots.forEach(function(s, i){
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'ts-ss-lb-thumb';
            b.setAttribute('aria-label', 'Show screenshot ' + (i + 1));
            var im = document.createElement('img');
            im.src = s.thumb || s.full;
            im.alt = s.alt;
            im.loading = 'lazy';
            b.appendChild(im);
            b.addEventListener('click', function(){ show(i); });
            stripEl.appendChild(b);
        });
        var thumbs = Array.prototype.slice.call(stripEl.children);

        function preload(i){
            if (i < 0 || i >= shots.length) return;
            var p = new Image();
            p.src = shots[i].full;
        }

        function show(i){
            if (i < 0) i = shots.length - 1;              // wrap around
            if (i >= shots.length) i = 0;
            index = i;
            imgEl.src = shots[i].full;
            imgEl.alt = shots[i].alt;
            curEl.textContent = i + 1;

            thumbs.forEach(function(t, n){
                var on = (n === i);
                t.classList.toggle('is-active', on);
                if (on) t.setAttribute('aria-current', 'true');
                else t.removeAttribute('aria-current');
            });

            // Keep the active thumbnail visible in the strip.
            var active = thumbs[i];
            if (active && stripEl.scrollWidth > stripEl.clientWidth) {
                var left = active.offsetLeft - (stripEl.clientWidth / 2) + (active.offsetWidth / 2);
                stripEl.scrollTo({ left: left, behavior: 'smooth' });
            }

            preload(i + 1);
            preload(i - 1);
        }

        function open(i){
            lastFocus = document.activeElement;
            show(i);
            lb.classList.add('is-open');
            lb.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            closeEl.focus();
        }

        function close(){
            lb.classList.remove('is-open');
            lb.setAttribute('aria-hidden', 'true');
            imgEl.src = '';
            document.body.style.overflow = '';
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }

        // Open from the on-page grid.
        items.forEach(function(el, i){
            el.addEventListener('click', function(){ open(i); });
            el.addEventListener('keydown', function(e){
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(i); }
            });
        });

        prevEl.addEventListener('click', function(e){ e.stopPropagation(); show(index - 1); });
        nextEl.addEventListener('click', function(e){ e.stopPropagation(); show(index + 1); });
        closeEl.addEventListener('click', close);

        // Filmstrip scroll buttons.
        function scrollStrip(dir){
            stripEl.scrollBy({ left: dir * Math.max(240, stripEl.clientWidth * 0.7), behavior: 'smooth' });
        }
        sPrevEl.addEventListener('click', function(){ scrollStrip(-1); });
        sNextEl.addEventListener('click', function(){ scrollStrip(1); });

        // Click the dark backdrop (not the image or controls) to close.
        lb.addEventListener('click', function(e){
            if (e.target === lb || e.target.classList.contains('ts-ss-lb-stage') || e.target.classList.contains('ts-ss-lb-figure')) close();
        });

        // Keyboard: Esc closes, arrows navigate.
        document.addEventListener('keydown', function(e){
            if (!lb.classList.contains('is-open')) return;
            if (e.key === 'Escape')     { close(); }
            else if (e.key === 'ArrowLeft')  { show(index - 1); }
            else if (e.key === 'ArrowRight') { show(index + 1); }
        });

        // Swipe on touch devices.
        var tx = 0, ty = 0;
        lb.addEventListener('touchstart', function(e){
            tx = e.changedTouches[0].clientX; ty = e.changedTouches[0].clientY;
        }, { passive: true });
        lb.addEventListener('touchend', function(e){
            var dx = e.changedTouches[0].clientX - tx;
            var dy = e.changedTouches[0].clientY - ty;
            if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) show(index + (dx < 0 ? 1 : -1));
        }, { passive: true });
    })();
    </script>
    <?php
});

/* ==========================================================
 * 6. STYLES — grid polish + lightbox chrome
 * ========================================================== */
add_action('wp_head', function() {
    ?>
    <style id="ts-ss-styles">
    .ts-ss-item{transition:border-color .18s ease,box-shadow .18s ease;}
    .ts-ss-item:hover{border-color:#e8394c !important;box-shadow:0 4px 12px rgba(16,24,40,.12);}
    .ts-ss-item img{transition:transform .25s ease;}
    .ts-ss-item:hover img{transform:scale(1.04);}
    /* Defensive: if any filter ever wraps tiles in <p>, keep them as grid children */
    .ts-ss-grid > p{display:contents !important;margin:0 !important;}
    /* Responsive: 4 across on desktop, 2 on tablet/phone */
    @media (max-width:782px){
        .ts-ss-grid{grid-template-columns:repeat(2,minmax(0,1fr)) !important;}
    }

    /* ---------- Lightbox ---------- */
    #ts-ss-lb{position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.97);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px);display:none;flex-direction:column;}
    #ts-ss-lb.is-open{display:flex;}
    #ts-ss-lb-close{position:absolute;top:18px;right:22px;z-index:3;width:46px;height:46px;border:none;border-radius:50%;background:#fff;color:#111;font-size:28px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;transition:transform .15s ease,background .15s ease;}
    #ts-ss-lb-close:hover{background:#f1f1f1;transform:scale(1.06);}

    /* overflow:hidden guarantees the image can never spill over the filmstrip */
    .ts-ss-lb-stage{flex:1 1 auto;display:flex;align-items:center;justify-content:center;position:relative;min-height:0;overflow:hidden;padding:70px 96px 14px;}
    /* White frame around the shot, like Nintendo's viewer */
    .ts-ss-lb-figure{margin:0;display:flex;align-items:center;justify-content:center;max-width:100%;max-height:100%;min-height:0;background:#fff;padding:8px;border-radius:12px;box-shadow:0 18px 50px rgba(0,0,0,.55);}
    #ts-ss-lb-img{display:block;max-width:min(1120px,84vw);max-height:calc(100vh - 285px);width:auto;height:auto;object-fit:contain;border-radius:5px;}

    .ts-ss-lb-nav{position:absolute;top:50%;transform:translateY(-50%);z-index:2;width:56px;height:56px;border:none;border-radius:50%;background:rgba(255,255,255,.14);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;transition:background .15s ease,transform .15s ease;}
    .ts-ss-lb-nav:hover{background:rgba(255,255,255,.3);}
    .ts-ss-lb-nav:active{transform:translateY(-50%) scale(.94);}
    .ts-ss-lb-prev{left:26px;}
    .ts-ss-lb-next{right:26px;}

    .ts-ss-lb-bottom{flex:0 0 auto;padding:0 18px 20px;}
    .ts-ss-lb-counter{text-align:center;color:#fff;opacity:.75;font-size:13px;font-weight:600;margin:0 0 10px;letter-spacing:.02em;}
    .ts-ss-lb-striprow{display:flex;align-items:center;gap:8px;justify-content:center;max-width:1280px;margin:0 auto;}
    .ts-ss-lb-strip{display:flex;gap:12px;overflow-x:auto;scroll-behavior:smooth;padding:4px;scrollbar-width:none;-ms-overflow-style:none;}
    .ts-ss-lb-strip::-webkit-scrollbar{display:none;}
    .ts-ss-lb-thumb{flex:0 0 auto;width:168px;height:94px;padding:0;border:3px solid transparent;border-radius:10px;overflow:hidden;background:#222;cursor:pointer;opacity:.5;transition:opacity .18s ease,border-color .18s ease,transform .18s ease;}
    .ts-ss-lb-thumb img{display:block;width:100%;height:100%;object-fit:cover;}
    .ts-ss-lb-thumb:hover{opacity:.85;transform:translateY(-2px);}
    .ts-ss-lb-thumb.is-active{opacity:1;border-color:#e8394c;}
    .ts-ss-strip-arrow{flex:0 0 auto;width:38px;height:38px;border:none;border-radius:50%;background:rgba(255,255,255,.14);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;transition:background .15s ease;}
    .ts-ss-strip-arrow:hover{background:rgba(255,255,255,.3);}

    @media (max-width:782px){
        .ts-ss-lb-stage{padding:58px 8px 10px;}
        .ts-ss-lb-figure{padding:5px;border-radius:9px;}
        #ts-ss-lb-img{max-width:94vw;max-height:calc(100vh - 235px);}
        .ts-ss-lb-nav{width:44px;height:44px;background:rgba(0,0,0,.45);}
        .ts-ss-lb-prev{left:8px;}
        .ts-ss-lb-next{right:8px;}
        #ts-ss-lb-close{top:12px;right:12px;width:40px;height:40px;font-size:24px;}
        .ts-ss-lb-thumb{width:116px;height:66px;border-width:2px;}
        .ts-ss-strip-arrow{display:none;}
        .ts-ss-lb-strip{padding:2px;}
    }
    </style>
    <?php
});
