<?php
/**
 * Logger class for Coinsnap Core.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logger class for handling plugin logging.
 *
 * Instance-based logger — each consuming plugin creates its own Logger
 * with its own log directory and file name.
 */
class Logger {
	/**
	 * Log file path.
	 *
	 * @var string
	 */
	private string $log_file = '';

	/**
	 * Current log level.
	 *
	 * @var string
	 */
	private string $log_level = '';

	/**
	 * Maximum log file size in bytes (5MB).
	 *
	 * @var int
	 */
	private const MAX_LOG_SIZE = 5 * 1024 * 1024;

	/**
	 * Maximum number of log files to keep.
	 *
	 * @var int
	 */
	private const MAX_LOG_FILES = 5;

	/**
	 * Write counter to throttle rotation checks.
	 *
	 * @var int
	 */
	private int $write_count = 0;

	/**
	 * How often (in writes) to check rotation.
	 *
	 * @var int
	 */
	private const ROTATION_CHECK_INTERVAL = 50;

	/**
	 * Constructor.
	 *
	 * @param string $log_dir_name  Directory name inside wp-content/uploads for log files.
	 * @param string $log_file_name Log file name.
	 * @param string $log_level     Minimum log level to record.
	 */
	public function __construct( string $log_dir_name = 'coinsnap-logs', string $log_file_name = 'coinsnap.log', string $log_level = 'error' ) {
		$this->log_level = LogLevels::is_valid_level( $log_level ) ? $log_level : 'error';
		$this->init( $log_dir_name, $log_file_name );
	}

	/**
	 * Initialize the logger.
	 *
	 * @param string $log_dir_name  Directory name inside wp-content/uploads.
	 * @param string $log_file_name Log file name.
	 */
	private function init( string $log_dir_name, string $log_file_name ): void {
		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/' . $log_dir_name;

		// Create log directory if it doesn't exist.
		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$this->log_file = $log_dir . '/' . $log_file_name;
	}

	/**
	 * Log a message.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return bool True if logged, false otherwise.
	 */
	public function log( string $level, string $message, array $context = array() ): bool {
		if ( ! $this->should_log( $level ) ) {
			return false;
		}

		$log_entry = $this->format_log_entry( $level, $message, $context );

		// Write to file.
		return $this->write_to_file( $log_entry );
	}

	/**
	 * Check if a message should be logged based on current log level.
	 *
	 * @param string $level Log level to check.
	 * @return bool True if should log, false otherwise.
	 */
	private function should_log( string $level ): bool {
		$current_level_value = LogLevels::get_level_value( $this->log_level );
		$message_level_value = LogLevels::get_level_value( $level );

		return $message_level_value <= $current_level_value;
	}

	/**
	 * Format a log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return string Formatted log entry.
	 */
	private function format_log_entry( string $level, string $message, array $context ): string {
		$timestamp   = current_time( 'Y-m-d H:i:s' );
		$context_str = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';

		return sprintf(
			'[%s] %s: %s%s' . PHP_EOL,
			$timestamp,
			strtoupper( $level ),
			$message,
			$context_str
		);
	}

	/**
	 * Write log entry to file.
	 *
	 * @param string $log_entry Formatted log entry.
	 * @return bool True if written successfully, false otherwise.
	 */
	private function write_to_file( string $log_entry ): bool {
		// Rotate log file if it's too large (throttled to avoid checking on every write).
		++$this->write_count;
		if ( $this->write_count % self::ROTATION_CHECK_INTERVAL === 0 ) {
			$this->maybe_rotate_log();
		}

		// Use direct file append with locking for performance — avoids reading entire log file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Append mode with LOCK_EX is the correct approach for log writing.
		$result = file_put_contents( $this->log_file, $log_entry, FILE_APPEND | LOCK_EX );
		return false !== $result;
	}

	/**
	 * Rotate log file if it exceeds maximum size.
	 */
	private function maybe_rotate_log(): void {
		// Use WordPress filesystem API.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			// Fallback to direct file operations if filesystem is not available.
			$this->maybe_rotate_log_fallback();
			return;
		}

		if ( ! $wp_filesystem->exists( $this->log_file ) ) {
			return;
		}

		$file_size = $wp_filesystem->size( $this->log_file );
		if ( $file_size < self::MAX_LOG_SIZE ) {
			return;
		}

		// Rotate existing log files.
		for ( $i = self::MAX_LOG_FILES - 1; $i > 0; $i-- ) {
			$old_file = $this->log_file . '.' . $i;
			$new_file = $this->log_file . '.' . ( $i + 1 );

			if ( $wp_filesystem->exists( $old_file ) ) {
				if ( self::MAX_LOG_FILES - 1 === $i ) {
					// Delete the oldest log file.
					$wp_filesystem->delete( $old_file );
				} else {
					// Move to next number.
					$wp_filesystem->move( $old_file, $new_file );
				}
			}
		}

		// Move current log to .1.
		$wp_filesystem->move( $this->log_file, $this->log_file . '.1' );
	}

	/**
	 * Fallback log rotation using direct file operations.
	 */
	private function maybe_rotate_log_fallback(): void {
		// Ensure WordPress file helpers are available for wp_delete_file.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! file_exists( $this->log_file ) ) {
			return;
		}

		$file_size = filesize( $this->log_file );
		if ( $file_size < self::MAX_LOG_SIZE ) {
			return;
		}

		// Rotate existing log files.
		for ( $i = self::MAX_LOG_FILES - 1; $i > 0; $i-- ) {
			$old_file = $this->log_file . '.' . $i;
			$new_file = $this->log_file . '.' . ( $i + 1 );

			if ( file_exists( $old_file ) ) {
				if ( self::MAX_LOG_FILES - 1 === $i ) {
					// Delete the oldest log file using WordPress helper.
					wp_delete_file( $old_file );
				} else {
					// Fallback move: copy then delete to avoid rename().
					if ( file_exists( $old_file ) && copy( $old_file, $new_file ) ) {
						wp_delete_file( $old_file );
					}
				}
			}
		}

		// Move current log to .1 using copy + delete.
		if ( @copy( $this->log_file, $this->log_file . '.1' ) ) {
			wp_delete_file( $this->log_file );
		}
	}

	/**
	 * Log an emergency message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return bool True if logged, false otherwise.
	 */
	public function emergency( string $message, array $context = array() ): bool {
		return $this->log( LogLevels::EMERGENCY, $message, $context );
	}

	/**
	 * Log an alert message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return bool True if logged, false otherwise.
	 */
	public function alert( string $message, array $context = array() ): bool {
		return $this->log( LogLevels::ALERT, $message, $context );
	}

	/**
	 * Log a critical message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return bool True if logged, false otherwise.
	 */
	public function critical( string $message, array $context = array() ): bool {
		return $this->log( LogLevels::CRITICAL, $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return bool True if logged, false otherwise.
	 */
	public function error( string $message, array $context = array() ): bool {
		return $this->log( LogLevels::ERROR, $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return bool True if logged, false otherwise.
	 */
	public function warning( string $message, array $context = array() ): bool {
		return $this->log( LogLevels::WARNING, $message, $context );
	}

	/**
	 * Log a notice message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return bool True if logged, false otherwise.
	 */
	public function notice( string $message, array $context = array() ): bool {
		return $this->log( LogLevels::NOTICE, $message, $context );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return bool True if logged, false otherwise.
	 */
	public function info( string $message, array $context = array() ): bool {
		return $this->log( LogLevels::INFO, $message, $context );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return bool True if logged, false otherwise.
	 */
	public function debug( string $message, array $context = array() ): bool {
		return $this->log( LogLevels::DEBUG, $message, $context );
	}

	/**
	 * Get log file path.
	 *
	 * @return string Log file path.
	 */
	public function get_log_file_path(): string {
		return $this->log_file;
	}

	/**
	 * Get log file size in bytes.
	 *
	 * @return int Log file size in bytes.
	 */
	public function get_log_file_size(): int {
		return file_exists( $this->log_file ) ? filesize( $this->log_file ) : 0;
	}

	/**
	 * Clear log file.
	 *
	 * @return bool True if cleared successfully, false otherwise.
	 */
	public function clear_log(): bool {
		// Use WordPress filesystem API.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			// Fallback to direct file operation if filesystem is not available.
			if ( ! file_exists( $this->log_file ) ) {
				return true;
			}
			wp_delete_file( $this->log_file );
			return ! file_exists( $this->log_file );
		}

		if ( ! $wp_filesystem->exists( $this->log_file ) ) {
			return true;
		}

		return $wp_filesystem->delete( $this->log_file );
	}

	/**
	 * Get recent log entries.
	 *
	 * @param int    $lines        Number of lines to retrieve.
	 * @param string $level_filter Optional level filter.
	 * @return array Array of log entries.
	 */
	public function get_recent_entries( int $lines = 100, string $level_filter = '' ): array {
		if ( ! file_exists( $this->log_file ) ) {
			return array();
		}

		// Read last N lines efficiently using fseek instead of reading entire file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct file access for efficient tail reading.
		$handle = fopen( $this->log_file, 'r' );
		if ( ! $handle ) {
			return array();
		}

		// Seek from end; read chunks until we have enough lines.
		$buffer     = '';
		$chunk_size = 4096;
		$file_size  = filesize( $this->log_file );

		if ( $file_size <= $chunk_size ) {
			// Small file — just read the whole thing.
			$buffer = fread( $handle, $file_size );
		} else {
			$pos        = $file_size;
			$line_count = 0;
			// Read enough lines (over-read to ensure we have $lines entries).
			while ( $pos > 0 && $line_count < $lines + 1 ) {
				$read_size = min( $chunk_size, $pos );
				$pos      -= $read_size;
				fseek( $handle, $pos );
				$chunk      = fread( $handle, $read_size );
				$buffer     = $chunk . $buffer;
				$line_count = substr_count( $buffer, PHP_EOL );
			}
		}
		fclose( $handle );

		$log_lines = explode( PHP_EOL, $buffer );
		$log_lines = array_filter( $log_lines );
		$log_lines = array_slice( $log_lines, -$lines );

		// Filter by log level if specified.
		if ( '' !== $level_filter ) {
			$level_upper = strtoupper( $level_filter );
			$log_lines   = array_filter( $log_lines, function ( $line ) use ( $level_upper ) {
				return strpos( $line, '] ' . $level_upper . ':' ) !== false;
			} );
			$log_lines = array_values( $log_lines );
		}

		return $log_lines;
	}
}
