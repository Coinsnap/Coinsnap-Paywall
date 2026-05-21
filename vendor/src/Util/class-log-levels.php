<?php
/**
 * Log levels enumeration.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Util;

/**
 * Log levels for the plugin logging system.
 */
class LogLevels {
	/**
	 * Emergency: system is unusable.
	 */
	public const EMERGENCY = 'emergency';

	/**
	 * Alert: action must be taken immediately.
	 */
	public const ALERT = 'alert';

	/**
	 * Critical: critical conditions.
	 */
	public const CRITICAL = 'critical';

	/**
	 * Error: error conditions.
	 */
	public const ERROR = 'error';

	/**
	 * Warning: warning conditions.
	 */
	public const WARNING = 'warning';

	/**
	 * Notice: normal but significant condition.
	 */
	public const NOTICE = 'notice';

	/**
	 * Info: informational messages.
	 */
	public const INFO = 'info';

	/**
	 * Debug: debug-level messages.
	 */
	public const DEBUG = 'debug';

	/**
	 * Get all available log levels.
	 *
	 * @return array Array of log levels with their numeric values.
	 */
	public static function get_levels(): array {
		return array(
			self::EMERGENCY => 0,
			self::ALERT     => 1,
			self::CRITICAL  => 2,
			self::ERROR     => 3,
			self::WARNING   => 4,
			self::NOTICE    => 5,
			self::INFO      => 6,
			self::DEBUG     => 7,
		);
	}

	/**
	 * Get log level numeric value.
	 *
	 * @param string $level Log level name.
	 * @return int Numeric value of the log level.
	 */
	public static function get_level_value( string $level ): int {
		$levels = self::get_levels();
		return $levels[ $level ] ?? 7; // Default to DEBUG if not found.
	}

	/**
	 * Check if a log level is valid.
	 *
	 * @param string $level Log level to check.
	 * @return bool True if valid, false otherwise.
	 */
	public static function is_valid_level( string $level ): bool {
		return array_key_exists( $level, self::get_levels() );
	}

	/**
	 * Get human-readable log level names.
	 *
	 * @return array Array of log level names for display.
	 */
	public static function get_level_names(): array {
		return array(
			self::EMERGENCY => __( 'Emergency', 'coinsnap-core' ),
			self::ALERT     => __( 'Alert', 'coinsnap-core' ),
			self::CRITICAL  => __( 'Critical', 'coinsnap-core' ),
			self::ERROR     => __( 'Error', 'coinsnap-core' ),
			self::WARNING   => __( 'Warning', 'coinsnap-core' ),
			self::NOTICE    => __( 'Notice', 'coinsnap-core' ),
			self::INFO      => __( 'Info', 'coinsnap-core' ),
			self::DEBUG     => __( 'Debug', 'coinsnap-core' ),
		);
	}
}
