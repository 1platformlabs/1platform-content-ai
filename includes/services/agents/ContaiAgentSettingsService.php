<?php
/**
 * Manages local agent preferences stored in wp_options.
 *
 * All settings are prefixed with 'contai_agents_' to avoid collisions.
 * Provides typed getters/setters for each preference and a bulk
 * getAllSettings / updateSettings pair used by the admin UI and sync service.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ContaiAgentSettingsService {

	const OPTION_PREFIX = 'contai_agents_';

	// ── Publish Status ───────────────────────────────────────────

	/**
	 * Returns the default post status applied when an agent publishes content.
	 * Defaults to 'publish'. Change to 'draft' in settings to require review.
	 *
	 * @return string 'draft'|'publish'|'pending'
	 */
	public static function getPublishStatus() {
		return get_option( self::OPTION_PREFIX . 'publish_status', 'publish' );
	}

	/**
	 * @param string $status Accepted values: 'draft', 'publish', 'pending'.
	 */
	public static function setPublishStatus( $status ) {
		$allowed = array( 'draft', 'publish', 'pending' );
		$status  = sanitize_text_field( $status );
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'draft';
		}
		update_option( self::OPTION_PREFIX . 'publish_status', $status );
	}

	// ── Auto-Consume ─────────────────────────────────────────────

	/**
	 * Returns whether pending agent actions should be consumed automatically
	 * by the background cron job without manual approval.
	 *
	 * @return bool
	 */
	public static function isAutoConsumeEnabled() {
		return (bool) get_option( self::OPTION_PREFIX . 'auto_consume', true );
	}

	/**
	 * @param bool $enabled
	 */
	public static function setAutoConsume( $enabled ) {
		update_option( self::OPTION_PREFIX . 'auto_consume', (bool) $enabled );
	}

	// ── Polling Interval ─────────────────────────────────────────

	/**
	 * Returns the number of seconds between action-polling cycles.
	 * Defaults to 60 s. The sync service enforces a hard minimum of 30 s.
	 *
	 * @return int
	 */
	public static function getPollingInterval() {
		return (int) get_option( self::OPTION_PREFIX . 'polling_interval', 60 );
	}

	/**
	 * @param int $seconds Must be a positive integer.
	 */
	public static function setPollingInterval( $seconds ) {
		update_option( self::OPTION_PREFIX . 'polling_interval', absint( $seconds ) );
	}

	// ── Last Poll Time ───────────────────────────────────────────

	/**
	 * Returns the Unix timestamp of the last completed poll cycle.
	 * Defaults to 0 (never polled).
	 *
	 * @return int
	 */
	public static function getLastPollTime() {
		return (int) get_option( self::OPTION_PREFIX . 'last_poll_time', 0 );
	}

	/**
	 * @param int $time Unix timestamp.
	 */
	public static function setLastPollTime( $time ) {
		update_option( self::OPTION_PREFIX . 'last_poll_time', absint( $time ) );
	}

	// ── Bulk Access ──────────────────────────────────────────────

	/**
	 * Returns all agent settings as a flat associative array.
	 * Suitable for passing to action handlers as a $settings context.
	 *
	 * @return array{
	 *   publish_status: string,
	 *   auto_consume: bool,
	 *   polling_interval: int,
	 *   last_poll_time: int
	 * }
	 */
	public static function getAllSettings() {
		return array(
			'publish_status'   => self::getPublishStatus(),
			'auto_consume'     => self::isAutoConsumeEnabled(),
			'polling_interval' => self::getPollingInterval(),
			'last_poll_time'   => self::getLastPollTime(),
		);
	}

	/**
	 * Bulk-updates any recognised settings keys present in $settings.
	 * Unknown keys are silently ignored.
	 *
	 * @param array $settings Associative array of setting key => value pairs.
	 */
	public static function updateSettings( array $settings ) {
		if ( isset( $settings['publish_status'] ) ) {
			self::setPublishStatus( $settings['publish_status'] );
		}

		if ( isset( $settings['auto_consume'] ) ) {
			self::setAutoConsume( $settings['auto_consume'] );
		}

		if ( isset( $settings['polling_interval'] ) ) {
			self::setPollingInterval( $settings['polling_interval'] );
		}

		if ( isset( $settings['last_poll_time'] ) ) {
			self::setLastPollTime( $settings['last_poll_time'] );
		}
	}
}
