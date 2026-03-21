<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles "send_email" actions created by agents.
 *
 * Delegates to WordPress wp_mail() — no external email service required.
 * The email is sent using whatever SMTP/transport WordPress has configured.
 */
class ContaiSendEmailActionHandler implements ContaiAgentActionHandlerInterface {

	const RATE_LIMIT_OPTION = 'contai_agent_email_hourly_count';
	const RATE_LIMIT_MAX    = 20;

	/**
	 * @param string $type Action type identifier.
	 * @return bool
	 */
	public function canHandle( $type ) {
		return 'send_email' === $type;
	}

	/**
	 * Send an email using wp_mail().
	 *
	 * Expected payload keys in $action['payload']['metadata']:
	 *   - to      (string, required) — recipient email address
	 *   - subject (string, required) — email subject line
	 *   - body    (string, required) — email body (plain text or HTML)
	 *   - cc      (string, optional) — CC address
	 *   - headers (array, optional)  — additional email headers
	 *
	 * @param array $action   The full action document from the API.
	 * @param array $settings Local plugin settings (unused for email).
	 * @return array Result with 'success' key.
	 */
	public function handle( array $action, array $settings ) {
		$payload  = $action['payload'] ?? array();
		$metadata = $payload['metadata'] ?? $payload;

		$to      = sanitize_email( $metadata['to'] ?? '' );
		$subject = sanitize_text_field( $metadata['subject'] ?? '' );
		$body    = $metadata['body'] ?? '';

		if ( empty( $to ) || ! is_email( $to ) ) {
			return array( 'success' => false, 'error' => 'Invalid or missing recipient email address' );
		}
		if ( empty( $subject ) ) {
			return array( 'success' => false, 'error' => 'Missing email subject' );
		}
		if ( empty( $body ) ) {
			return array( 'success' => false, 'error' => 'Missing email body' );
		}

		// Rate limit: max N emails per hour
		if ( $this->isRateLimited() ) {
			contai_log( 'Agent email rate limit reached (' . self::RATE_LIMIT_MAX . '/hour)', 'warning' );
			return array( 'success' => false, 'error' => 'Hourly email rate limit reached (' . self::RATE_LIMIT_MAX . ')' );
		}

		// Build headers
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $metadata['cc'] ) ) {
			$cc = sanitize_email( $metadata['cc'] );
			if ( is_email( $cc ) ) {
				$headers[] = 'Cc: ' . $cc;
			}
		}

		// Sanitize body (allow basic HTML)
		$body = wp_kses_post( $body );

		// Add agent context to subject
		if ( strpos( $subject, '[' ) !== 0 ) {
			$subject = '[1Platform] ' . $subject;
		}

		$sent = wp_mail( $to, $subject, $body, $headers );

		if ( $sent ) {
			$this->incrementRateCounter();
			contai_log( 'Agent email sent to ' . $to . ' subject="' . $subject . '"' );
			return array(
				'success' => true,
				'to'      => $to,
				'subject' => $subject,
			);
		}

		contai_log( 'Agent email failed to ' . $to, 'warning' );
		return array( 'success' => false, 'error' => 'wp_mail() returned false' );
	}

	/**
	 * Checks if the hourly email rate limit has been reached.
	 *
	 * @return bool
	 */
	private function isRateLimited() {
		$count = (int) get_transient( self::RATE_LIMIT_OPTION );
		return $count >= self::RATE_LIMIT_MAX;
	}

	/**
	 * Increments the hourly email counter.
	 */
	private function incrementRateCounter() {
		$count = (int) get_transient( self::RATE_LIMIT_OPTION );
		set_transient( self::RATE_LIMIT_OPTION, $count + 1, HOUR_IN_SECONDS );
	}
}
