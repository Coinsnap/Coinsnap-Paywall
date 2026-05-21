<?php
/**
 * Admin transactions page.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Admin;

use CoinsnapCore\PluginInstance;
use CoinsnapCore\Database\PaymentTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin transactions page for viewing and managing payment transactions.
 */
class TransactionsPage {

	/**
	 * Render the transactions page for a given plugin instance.
	 *
	 * @param PluginInstance $instance Plugin configuration.
	 */
	public static function render_page_for( PluginInstance $instance ): void {
		global $wpdb;

		$table_name = PaymentTable::table_name( $instance );
		$source_col = $instance->get( 'source_column', 'source_id' );
		$per_page   = 10;
		$_paged = filter_input( INPUT_GET, 'paged', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$_nonce = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$filters_enabled = ( $_nonce !== null && wp_verify_nonce( $_nonce, 'coinsnap_core_transactions_filter' ) ) ? true : false;

		$filter_source_id      = filter_input( INPUT_GET, 'source_id', FILTER_VALIDATE_INT );
		$filter_payment_status = filter_input( INPUT_GET, 'payment_status', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$filter_date_from      = filter_input( INPUT_GET, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$filter_date_to        = filter_input( INPUT_GET, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		// Handle pagination with nonce verification for filter state.
		$current_page = 1;
		if ( $_paged !== null ) {

			// If filters are active, require nonce. Otherwise allow pagination without nonce.
			$has_filters = ( $filter_source_id !== null || $filter_payment_status !== null || $filter_date_from !== null || $filter_date_to !== null ) ? true : false;

			if ( ! $has_filters || ( $has_filters && $filters_enabled ) ) {
				$current_page = max( 1, intval( $_paged ) );
			}
		}

		$offset = ( $current_page - 1 ) * $per_page;

		$where_conditions = array( '1 = %d' );
		$where_values     = array( 1 );

		if ( $filters_enabled ) {

			if ( $filter_source_id !== null && intval( $filter_source_id ) > 0 ) {
				$where_conditions[] = $source_col . ' = %d';
				$where_values[]     = intval( $filter_source_id );
			}

			if ( $filter_payment_status !== null && $filter_payment_status !== '' ) {
				$where_conditions[] = 'payment_status = %s';
				$where_values[]     = sanitize_text_field( wp_unslash( $filter_payment_status ) );
			}

			if ( $filter_date_from !== null && $filter_date_from !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_date_from ) ) {
				$where_conditions[] = 'created_at >= %s';
				$where_values[]     = sanitize_text_field( wp_unslash( $filter_date_from ) ) . ' 00:00:00';
			}

			if ( $filter_date_to !== null && $filter_date_to !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_date_to ) ) {
				$where_conditions[] = 'created_at <= %s';
				$where_values[]     = sanitize_text_field( wp_unslash( $filter_date_to ) ) . ' 23:59:59';
			}
		}

		$where_clause = implode( ' AND ', $where_conditions );

		// Get total count - query uses a dynamic table name and dynamic WHERE built with placeholders.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is from PaymentTable::table_name(); WHERE clause contains only placeholder fragments, values are passed to prepare; direct query is acceptable within admin listing.
		$total_items = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}", $where_values )
		);

		// Get transactions - prepared with LIMIT/OFFSET and dynamic WHERE with placeholders.
		$query_values = array_merge( $where_values, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is from PaymentTable::table_name(); WHERE clause contains only placeholder fragments; values are passed to prepare; direct query is acceptable within admin listing.
		$transactions = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY created_at DESC LIMIT %d OFFSET %d", $query_values )
		);

		// Get sources for filter dropdown via filter.
		$sources = apply_filters( 'coinsnap_core_transaction_sources', array(), $instance );

		?>

		<div class="wrap">
			<h1><?php esc_html_e( 'Transactions', 'coinsnap-core' ); ?></h1>

			<!-- Filters -->
			<div class="tablenav top">
				<form method="get" action="">
					<input type="hidden" name="page" value="<?php echo esc_attr( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ); ?>" />
					<?php wp_nonce_field( 'coinsnap_core_transactions_filter' ); ?>

					<div class="alignleft actions">
						<?php if ( ! empty( $sources ) ) : ?>
							<select name="source_id">
								<option value=""><?php esc_html_e( 'All Sources', 'coinsnap-core' ); ?></option>
								<?php foreach ( $sources as $source ) : ?>
									<option value="<?php echo esc_attr( $source['id'] ); ?>" <?php selected( $filter_source_id !== null ? intval( $filter_source_id ) : '', $source['id'] ); ?>>
										<?php echo esc_html( $source['title'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						<?php endif; ?>

						<select name="payment_status">
							<option value=""><?php esc_html_e( 'All Statuses', 'coinsnap-core' ); ?></option>
							<option value="unpaid" <?php selected( $filter_payment_status !== null ? sanitize_text_field( wp_unslash( $filter_payment_status ) ) : '', 'unpaid' ); ?>><?php esc_html_e( 'Unpaid', 'coinsnap-core' ); ?></option>
							<option value="paid" <?php selected( $filter_payment_status !== null ? sanitize_text_field( wp_unslash( $filter_payment_status ) ) : '', 'paid' ); ?>><?php esc_html_e( 'Paid', 'coinsnap-core' ); ?></option>
							<option value="failed" <?php selected( $filter_payment_status !== null ? sanitize_text_field( wp_unslash( $filter_payment_status ) ) : '', 'failed' ); ?>><?php esc_html_e( 'Failed', 'coinsnap-core' ); ?></option>
							<option value="refunded" <?php selected( $filter_payment_status !== null ? sanitize_text_field( wp_unslash( $filter_payment_status ) ) : '', 'refunded' ); ?>><?php esc_html_e( 'Refunded', 'coinsnap-core' ); ?></option>
						</select>

						<input type="date" name="date_from" value="<?php echo esc_attr( $filter_date_from !== null ? sanitize_text_field( wp_unslash( $filter_date_from ) ) : '' ); ?>" placeholder="<?php esc_attr_e( 'From Date', 'coinsnap-core' ); ?>" />
						<input type="date" name="date_to" value="<?php echo esc_attr( $filter_date_to !== null ? sanitize_text_field( wp_unslash( $filter_date_to ) ) : '' ); ?>" placeholder="<?php esc_attr_e( 'To Date', 'coinsnap-core' ); ?>" />

						<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'coinsnap-core' ); ?>" />
					</div>
				</form>
			</div>

			<!-- Transactions Table -->
			<table class="wp-list-table widefat fixed striped">
				<thead>
				<tr>
					<th><?php esc_html_e( 'Transaction ID', 'coinsnap-core' ); ?></th>
					<th><?php esc_html_e( 'Source', 'coinsnap-core' ); ?></th>
					<th><?php esc_html_e( 'Invoice Number', 'coinsnap-core' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'coinsnap-core' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'coinsnap-core' ); ?></th>
					<th><?php esc_html_e( 'Status', 'coinsnap-core' ); ?></th>
					<th><?php esc_html_e( 'Date', 'coinsnap-core' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'coinsnap-core' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php if ( empty( $transactions ) ) : ?>
					<tr>
						<td colspan="8" style="text-align: center; padding: 20px;">
							<?php esc_html_e( 'No transactions found.', 'coinsnap-core' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $transactions as $transaction ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $transaction->transaction_id ); ?></strong>
							</td>
							<td>
								<?php
								$source_val = isset( $transaction->{$source_col} ) ? $transaction->{$source_col} : '';
								$label = apply_filters( 'coinsnap_core_transaction_source_label', '', $source_val, $instance );
								if ( '' === $label ) {
									$label = '#' . $source_val;
								}
								echo esc_html( $label );
								?>
							</td>
							<td>
								<?php echo esc_html( $transaction->invoice_number ?: '-' ); ?>
							</td>
							<td>
								<strong><?php echo esc_html( $transaction->customer_name ); ?></strong><br>
								<small><?php echo esc_html( $transaction->customer_email ); ?></small>
								<?php if ( $transaction->customer_company ) : ?>
									<br><small><?php echo esc_html( $transaction->customer_company ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<?php echo esc_html( $transaction->amount ); ?> <?php echo esc_html( $transaction->currency ); ?>
							</td>
							<td>
								<span class="csc-status csc-status-<?php echo esc_attr( $transaction->payment_status ); ?>">
									<?php echo esc_html( ucfirst( $transaction->payment_status ) ); ?>
								</span>
							</td>
							<td>
								<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $transaction->created_at ) ) ); ?>
							</td>
							<td>
								<?php if ( $transaction->payment_url ) : ?>
									<a href="<?php echo esc_url( $transaction->payment_url ); ?>" target="_blank" class="button button-small">
										<?php esc_html_e( 'View Payment', 'coinsnap-core' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_items > $per_page ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						$total_pages = ceil( $total_items / $per_page );
						// Preserve filter query params in pagination links.
						$base_url        = remove_query_arg( 'paged' );
						$pagination_args = array(
							'base'    => add_query_arg( 'paged', '%#%', $base_url ),
							'format'  => '',
							'current' => $current_page,
							'total'   => $total_pages,
						);
						echo wp_kses_post( paginate_links( $pagination_args ) );
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
