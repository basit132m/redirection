<?php
/**
 * Front-end capture + redirect serving for NSPVault Redirects.
 *
 * @package NSPVault_Redirects
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Watches for post URL changes (to record redirects) and issues the actual
 * 301 responses on the front end.
 */
class NSPVault_Redirects_Manager {

	/**
	 * Data-access layer.
	 *
	 * @var NSPVault_Redirects_DB
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param NSPVault_Redirects_DB $db Data-access layer.
	 */
	public function __construct( NSPVault_Redirects_DB $db ) {
		$this->db = $db;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register_hooks() {
		// Capture slug/permalink changes when a post is updated.
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );

		// Serve redirects. Priority 9 runs before core's wp_old_slug_redirect (10)
		// so our flattened, single-hop target always wins.
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 9 );
	}

	/**
	 * Detect a URL change on save and record the redirect.
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post object after the update.
	 * @param WP_Post $post_before Post object before the update.
	 */
	public function on_post_updated( $post_id, $post_after, $post_before ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$settings = NSPVault_Redirects::get_settings();
		if ( empty( $settings['auto_capture'] ) ) {
			return;
		}

		// Only track post types that produce public, indexable URLs.
		$post_type_object = get_post_type_object( $post_after->post_type );
		if ( ! $post_type_object || ! $post_type_object->public ) {
			return;
		}

		if ( ! empty( $settings['post_types'] ) && ! in_array( $post_after->post_type, (array) $settings['post_types'], true ) ) {
			return;
		}

		// A redirect only matters when the old URL was publicly live and the new
		// one still is. Renaming a draft never exposed a public URL to redirect.
		if ( 'publish' !== $post_before->post_status || 'publish' !== $post_after->post_status ) {
			return;
		}

		$new_url = get_permalink( $post_id );
		if ( ! $new_url ) {
			return;
		}

		$old_url = $this->reconstruct_previous_url( $post_before, $post_after, $new_url );
		if ( ! $old_url ) {
			return;
		}

		if ( $this->db->normalize_path( $old_url ) === $this->db->normalize_path( $new_url ) ) {
			return;
		}

		$this->db->record_move( $old_url, $new_url, $post_id, 'auto' );
	}

	/**
	 * Reconstruct the pre-update permalink from the slug change.
	 *
	 * At the point `post_updated` fires the post row already holds the new slug,
	 * so `get_permalink()` returns the new URL. We rebuild the old URL by swapping
	 * the new slug segment back to the old one within the new permalink's path.
	 *
	 * @param WP_Post $post_before Post before update.
	 * @param WP_Post $post_after  Post after update.
	 * @param string  $new_url     New permalink.
	 * @return string|false Old URL, or false when the slug did not change.
	 */
	private function reconstruct_previous_url( $post_before, $post_after, $new_url ) {
		$old_slug = $post_before->post_name;
		$new_slug = $post_after->post_name;

		if ( '' === $old_slug || $old_slug === $new_slug ) {
			return false;
		}

		$parts = wp_parse_url( $new_url );
		if ( empty( $parts['path'] ) ) {
			return false;
		}

		$path = $parts['path'];

		// Replace the last "/{new_slug}/" or "/{new_slug}" segment with the old slug.
		$patterns    = array(
			'#/' . preg_quote( $new_slug, '#' ) . '/$#',
			'#/' . preg_quote( $new_slug, '#' ) . '$#',
		);
		$replacement = array(
			'/' . $old_slug . '/',
			'/' . $old_slug,
		);

		$old_path = $path;
		foreach ( $patterns as $i => $pattern ) {
			$candidate = preg_replace( $pattern, $replacement[ $i ], $path, 1 );
			if ( null !== $candidate && $candidate !== $path ) {
				$old_path = $candidate;
				break;
			}
		}

		if ( $old_path === $path ) {
			// The new slug was not found in the path; cannot reconstruct safely.
			return false;
		}

		// Reassemble with the same scheme/host so normalization keeps only the path.
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';

		return $scheme . '://' . $host . $old_path;
	}

	/**
	 * On the front end, redirect a retired URL to its current canonical target.
	 */
	public function maybe_redirect() {
		if ( is_admin() || is_robots() || is_favicon() ) {
			return;
		}

		// Pause switch: keep every stored redirect but stop serving them.
		$settings = NSPVault_Redirects::get_settings();
		if ( ! empty( $settings['paused'] ) ) {
			return;
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$request_path = $this->db->normalize_path( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $request_path ) {
			return;
		}

		$row = $this->db->get_by_source( $request_path );
		if ( ! $row ) {
			return;
		}

		// Never redirect a path onto itself.
		if ( $this->db->normalize_path( $row->target_path ) === $request_path ) {
			return;
		}

		$this->db->increment_hit( $row->id );

		$location = $this->build_location( $row->target_path );
		$status   = (int) $row->status_code;
		if ( ! in_array( $status, array( 301, 302, 307, 308 ), true ) ) {
			$status = 301;
		}

		wp_safe_redirect( $location, $status );
		exit;
	}

	/**
	 * Build an absolute, same-host destination URL and carry over the query string.
	 *
	 * @param string $target_path Stored root-relative target path.
	 * @return string Absolute URL.
	 */
	private function build_location( $target_path ) {
		$home = wp_parse_url( home_url( '/' ) );
		$host = isset( $home['host'] ) ? $home['host'] : '';
		if ( isset( $home['port'] ) ) {
			$host .= ':' . $home['port'];
		}

		$scheme   = is_ssl() ? 'https' : ( isset( $home['scheme'] ) ? $home['scheme'] : 'http' );
		$location = $scheme . '://' . $host . '/' . ltrim( $target_path, '/' );

		// Preserve any incoming query string (e.g. tracking params) on the redirect.
		if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
			$query = wp_unslash( $_SERVER['QUERY_STRING'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( false === strpos( $location, '?' ) ) {
				$location .= '?' . $query;
			}
		}

		return $location;
	}
}
