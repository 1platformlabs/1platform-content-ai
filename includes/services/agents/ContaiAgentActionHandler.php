<?php
/**
 * Agent action handler interface and built-in implementations.
 *
 * ContaiAgentActionHandlerInterface defines the contract every handler must
 * fulfil.  ContaiPublishContentActionHandler is the default implementation
 * for the 'publish_content' action type emitted by the 1Platform Agent API.
 *
 * Handler contract:
 *   canHandle( $type ) — return true when this handler owns the given type.
 *   handle( $action, $settings ) — execute the action; return an array with
 *     at minimum ['success' => bool].  On success include 'post_id' and
 *     'permalink'.  On failure include 'error' with a human-readable message.
 *
 * Security model:
 *   All data received from the API payload is treated as untrusted input and
 *   sanitised before being written to the database (defense in depth), even
 *   though the source is our own API.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Interface ────────────────────────────────────────────────────────────────

interface ContaiAgentActionHandlerInterface {

	/**
	 * Returns true when this handler can process actions of the given type.
	 *
	 * @param string $type Action type identifier (e.g. 'publish_content').
	 * @return bool
	 */
	public function canHandle( $type );

	/**
	 * Processes the action and returns a result array.
	 *
	 * @param array $action   Full action object from the API (id, type, payload, …).
	 * @param array $settings Plugin settings context from ContaiAgentSettingsService::getAllSettings()
	 *                        plus any runtime flags (e.g. 'is_auto_consume').
	 * @return array{success: bool, error?: string, post_id?: int, permalink?: string, post_status?: string}
	 */
	public function handle( array $action, array $settings );
}

// ── publish_content handler ──────────────────────────────────────────────────

/**
 * Handles the 'publish_content' agent action type.
 *
 * Expected action payload keys:
 *   title             (string, required) — post title
 *   body              (string, required) — post HTML content
 *   slug              (string, optional) — desired URL slug
 *   excerpt           (string, optional) — post excerpt
 *   publish_date      (string, optional) — ISO 8601 / strtotime-compatible date
 *   metatitle         (string, optional) — SEO meta title
 *   categories        (string[], optional) — category names (created if missing)
 *   featured_image_url (string, optional) — remote image URL to sideload
 *   metadata          (array, optional)  — arbitrary post_meta key/value pairs
 */
class ContaiPublishContentActionHandler implements ContaiAgentActionHandlerInterface {

	/** Maximum allowed date drift from "now" (used to reject nonsensical dates). */
	const MAX_DATE_DRIFT_SECONDS = 365 * DAY_IN_SECONDS;

	private $post_creator;

	public function __construct( ContaiWordPressPostCreator $post_creator ) {
		$this->post_creator = $post_creator;
	}

	public function canHandle( $type ) {
		return 'publish_content' === $type;
	}

	public function handle( array $action, array $settings ) {
		$payload = isset( $action['payload'] ) && is_array( $action['payload'] )
			? $action['payload']
			: array();

		// ── Required field validation ────────────────────────────
		if ( empty( $payload['title'] ) || empty( $payload['body'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required fields: title, body',
			);
		}

		// ── Duplicate check ──────────────────────────────────────
		$existing = get_posts( array(
			'post_type'   => 'post',
			'post_status' => array( 'publish', 'draft', 'pending' ),
			'title'       => sanitize_text_field( $payload['title'] ),
			'numberposts' => 1,
		) );
		if ( ! empty( $existing ) ) {
			return array(
				'success' => false,
				'error'   => 'A post with this title already exists (ID: ' . $existing[0]->ID . ')',
			);
		}

		// ── Sanitise all inputs (defense in depth) ───────────────
		$title     = sanitize_text_field( $payload['title'] );
		$body      = wp_kses_post( $payload['body'] );
		$slug      = isset( $payload['slug'] ) ? sanitize_title( $payload['slug'] ) : null;
		$excerpt   = isset( $payload['excerpt'] ) ? wp_kses_post( $payload['excerpt'] ) : '';
		$date      = isset( $payload['publish_date'] ) ? $this->validateDate( $payload['publish_date'] ) : null;
		$metatitle = isset( $payload['metatitle'] ) ? sanitize_text_field( $payload['metatitle'] ) : null;

		// ── Determine post status ────────────────────────────────
		$post_status = isset( $settings['publish_status'] ) ? $settings['publish_status'] : 'publish';

		try {
			// ── Create post with correct status from the start ───
			// Build the post array directly so the post is never momentarily
			// published before being changed to draft (prevents RSS/ping/email
			// hooks from firing with an unintended status).
			$post_data = array(
				'post_title'   => $title,
				'post_content' => $body,
				'post_status'  => $post_status,
				'post_type'    => 'post',
			);
			if ( $slug ) {
				$post_data['post_name'] = $slug;
			}
			if ( $date ) {
				$post_data['post_date_gmt'] = $date;
				$post_data['post_date']     = get_date_from_gmt( $date );
			}
			if ( $excerpt ) {
				$post_data['post_excerpt'] = $excerpt;
			}

			$post_id = wp_insert_post( $post_data, true );
			if ( is_wp_error( $post_id ) ) {
				return array( 'success' => false, 'error' => $post_id->get_error_message() );
			}

			// Save metatitle
			if ( $metatitle ) {
				update_post_meta( $post_id, '_contai_metatitle', $metatitle );
			}

			// ── Categories ───────────────────────────────────────
			// Use existing post creator method for categories.
			if ( ! empty( $payload['categories'] ) && is_array( $payload['categories'] ) ) {
				$cat_names = array_map( 'sanitize_text_field', $payload['categories'] );
				foreach ( $cat_names as $cat_name ) {
					if ( empty( $cat_name ) ) continue;
					$term = term_exists( $cat_name, 'category' );
					if ( ! $term ) {
						$term = wp_insert_term( $cat_name, 'category' );
					}
					if ( ! is_wp_error( $term ) ) {
						$cat_id = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
						$this->post_creator->assignCategory( $post_id, $cat_id );
					}
				}
			}

			// ── Featured image (sideload from URL) ───────────────
			if ( ! empty( $payload['featured_image_url'] ) ) {
				$image_url = esc_url_raw( $payload['featured_image_url'] );
				if ( $image_url ) {
					require_once ABSPATH . 'wp-admin/includes/media.php';
					require_once ABSPATH . 'wp-admin/includes/file.php';
					require_once ABSPATH . 'wp-admin/includes/image.php';
					$attachment_id = media_sideload_image( $image_url, $post_id, null, 'id' );
					if ( ! is_wp_error( $attachment_id ) ) {
						$this->post_creator->setFeaturedImage( $post_id, $attachment_id );
					}
				}
			}

			// ── Arbitrary metadata ───────────────────────────────
			if ( ! empty( $payload['metadata'] ) && is_array( $payload['metadata'] ) ) {
				$this->post_creator->saveMetadata( $post_id, $this->sanitizeMetadata( $payload['metadata'] ) );
			}

			$permalink = $this->post_creator->getPermalink( $post_id );

			return array(
				'success'     => true,
				'post_id'     => $post_id,
				'permalink'   => $permalink,
				'post_status' => $post_status,
			);

		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'error'   => $e->getMessage(), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			);
		}
	}

	// ── Private helpers ──────────────────────────────────────────────────────

	/**
	 * Recursively sanitizes metadata values before writing to post_meta.
	 *
	 * Strings are passed through sanitize_text_field; array keys through
	 * sanitize_key.  Scalar types (int, float, bool) are returned as-is.
	 * Any other type is coerced to an empty string.
	 *
	 * @param mixed $data
	 * @return mixed
	 */
	private function sanitizeMetadata( $data ) {
		if ( is_string( $data ) ) {
			return sanitize_text_field( $data );
		}
		if ( is_array( $data ) ) {
			$clean = array();
			foreach ( $data as $key => $value ) {
				$clean_key           = sanitize_key( $key );
				$clean[ $clean_key ] = $this->sanitizeMetadata( $value );
			}
			return $clean;
		}
		if ( is_int( $data ) || is_float( $data ) || is_bool( $data ) ) {
			return $data;
		}
		return '';
	}

	/**
	 * Validates a date string and returns a MySQL-formatted datetime or null.
	 *
	 * Rejects dates that are more than one year in the past or future to guard
	 * against malformed or malicious values being written to post_date.
	 *
	 * @param string $date_str
	 * @return string|null 'Y-m-d H:i:s' on success, null on invalid/out-of-range input.
	 */
	private function validateDate( $date_str ) {
		$timestamp = strtotime( $date_str );
		if ( ! $timestamp ) {
			return null;
		}

		$now = time();
		if (
			$timestamp < ( $now - self::MAX_DATE_DRIFT_SECONDS ) ||
			$timestamp > ( $now + self::MAX_DATE_DRIFT_SECONDS )
		) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

}
