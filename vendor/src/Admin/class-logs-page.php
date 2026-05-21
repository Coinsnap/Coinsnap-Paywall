<?php
/**
 * Admin logs page.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Admin;

use CoinsnapCore\PluginInstance;
use CoinsnapCore\Util\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin logs page for viewing plugin logs.
 */
class LogsPage {

	/**
	 * Render the logs page for a given plugin instance.
	 *
	 * @param PluginInstance $instance Plugin configuration.
	 * @param Logger         $logger   Logger instance.
	 */
	public static function render_page_for( PluginInstance $instance, Logger $logger ): void {
		$log_file_path = $logger->get_log_file_path();
		$log_file_size = $logger->get_log_file_size();

		// Handle log clearing.
		if ( isset( $_POST['clear_logs'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'coinsnap_core_clear_logs' ) ) {
			$logger->clear_log();
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Logs cleared successfully.', 'coinsnap-core' ) . '</p></div>';
			$log_file_size = 0;
		}

		// Level filter.
		$level_filter = '';
		if ( isset( $_GET['log_level'] ) ) {
			$level_filter = sanitize_text_field( wp_unslash( $_GET['log_level'] ) );
		}

		$recent_entries = $logger->get_recent_entries( 100, $level_filter );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Plugin Logs', 'coinsnap-core' ); ?></h1>

			<div class="csc-logs-info">
				<p>
					<strong><?php esc_html_e( 'Log File:', 'coinsnap-core' ); ?></strong>
					<code><?php echo esc_html( $log_file_path ); ?></code>
				</p>
				<p>
					<strong><?php esc_html_e( 'File Size:', 'coinsnap-core' ); ?></strong>
					<?php echo esc_html( size_format( $log_file_size ) ); ?>
				</p>
			</div>

			<div class="csc-logs-actions">
				<form method="get" style="display: inline;">
					<input type="hidden" name="page" value="<?php echo esc_attr( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ); ?>" />
					<select name="log_level">
						<option value=""><?php esc_html_e( 'All Levels', 'coinsnap-core' ); ?></option>
						<?php foreach ( array( 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' ) as $lvl ) : ?>
							<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $level_filter, $lvl ); ?>><?php echo esc_html( ucfirst( $lvl ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'coinsnap-core' ); ?>" />
				</form>
				<form method="post" style="display: inline;">
					<?php wp_nonce_field( 'coinsnap_core_clear_logs' ); ?>
					<input type="submit" name="clear_logs" class="button" value="<?php esc_attr_e( 'Clear Logs', 'coinsnap-core' ); ?>"
						   onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to clear all logs?', 'coinsnap-core' ); ?>');" />
				</form>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $instance->get( 'menu_slug' ) . '-settings' ) ); ?>" class="button">
					<?php esc_html_e( 'Log Settings', 'coinsnap-core' ); ?>
				</a>
			</div>

			<div class="csc-logs-content">
				<?php if ( empty( $recent_entries ) ) : ?>
					<p><?php esc_html_e( 'No log entries found.', 'coinsnap-core' ); ?></p>
				<?php else : ?>
					<div class="csc-log-entries">
						<?php foreach ( $recent_entries as $entry ) : ?>
							<?php
							// Parse log entry.
							$parts = explode( '] ', $entry, 3 );
							if ( count( $parts ) >= 3 ) {
								$timestamp = trim( $parts[0], '[' );
								$level     = trim( $parts[1], ':' );
								$message   = $parts[2];

								// Determine log level class.
								$level_class = 'csc-log-' . strtolower( $level );
							} else {
								$timestamp   = '';
								$level       = '';
								$message     = $entry;
								$level_class = 'csc-log-unknown';
							}
							?>
							<div class="csc-log-entry <?php echo esc_attr( $level_class ); ?>">
								<div class="csc-log-header">
									<span class="csc-log-timestamp"><?php echo esc_html( $timestamp ); ?></span>
									<span class="csc-log-level"><?php echo esc_html( $level ); ?></span>
								</div>
								<div class="csc-log-message"><?php echo esc_html( $message ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
