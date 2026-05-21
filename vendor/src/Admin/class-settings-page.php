<?php
/**
 * Settings page registration and rendering.
 *
 * @package coinsnap-core
 */

declare(strict_types=1);

namespace CoinsnapCore\Admin;

use CoinsnapCore\PluginInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides settings registration, rendering, and sanitization
 * parameterized by a PluginInstance.
 */
class SettingsPage {

	/**
	 * Get merged settings with defaults for a plugin instance.
	 *
	 * @param PluginInstance $inst Plugin instance.
	 * @return array Settings array.
	 */
	public static function get_settings_for( PluginInstance $inst ): array {
		$defaults = array(
			'payment_provider'             => 'coinsnap',
			'coinsnap_store_id'            => '',
			'coinsnap_api_key'             => '',
			'coinsnap_api_base'            => 'https://app.coinsnap.io',
			'coinsnap_webhook_secret'      => '',
			'btcpay_host'                  => '',
			'btcpay_api_key'               => '',
			'btcpay_store_id'              => '',
			'btcpay_webhook_secret'        => '',
			'theme'                        => 'light',
			'log_level'                    => 'error',
			'disable_webhook_verification' => false,
			'bitcoin_discount_enabled'     => false,
			'bitcoin_discount_type'        => 'percentage',
			'bitcoin_discount_value'       => 0.0,
		);

		$opts = get_option( $inst->option_key(), array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}

		return array_merge( $defaults, $opts );
	}

	/**
	 * Check if payment provider credentials are configured.
	 *
	 * @param PluginInstance $inst Plugin instance.
	 * @return bool True if credentials are set.
	 */
	public static function is_configured( PluginInstance $inst ): bool {
		$s = self::get_settings_for( $inst );
		$provider = $s['payment_provider'] ?? 'coinsnap';

		if ( 'btcpay' === $provider ) {
			return ! empty( $s['btcpay_api_key'] ) && ! empty( $s['btcpay_store_id'] ) && ! empty( $s['btcpay_host'] );
		}

		return ! empty( $s['coinsnap_api_key'] ) && ! empty( $s['coinsnap_store_id'] );
	}

	/**
	 * Render an admin notice if credentials are missing.
	 * Call this from your plugin's admin_notices hook.
	 *
	 * @param PluginInstance $inst Plugin instance.
	 */
	public static function maybe_show_setup_notice( PluginInstance $inst ): void {
		if ( self::is_configured( $inst ) ) {
			return;
		}

		$plugin_name = $inst->get( 'plugin_name' );
		$settings_url = admin_url( 'admin.php?page=' . $inst->get( 'menu_slug' ) . '-settings' );

		echo '<div class="notice notice-warning is-dismissible"><p>';
		printf(
			/* translators: 1: plugin name, 2: opening link tag, 3: closing link tag */
			esc_html__( '%1$s: Payment gateway is not configured. Please %2$sconfigure your settings%3$s to start accepting Bitcoin payments.', 'coinsnap-core' ),
			'<strong>' . esc_html( $plugin_name ) . '</strong>',
			'<a href="' . esc_url( $settings_url ) . '">',
			'</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Update settings for a plugin instance.
	 *
	 * @param PluginInstance $inst Plugin instance.
	 * @param array          $data New data to merge.
	 */
	public static function update_settings_for( PluginInstance $inst, array $data ): void {
		$existing = get_option( $inst->option_key(), array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$merged = array_merge( $existing, $data );
		update_option( $inst->option_key(), $merged );
	}

	/**
	 * Register settings, sections, and fields for a plugin instance.
	 *
	 * @param PluginInstance $inst Plugin instance.
	 */
	public static function register_for( PluginInstance $inst ): void {
		$option_key      = $inst->option_key();
		$settings_group  = $inst->get( 'menu_slug' ) . '_settings_group';
		$settings_page   = $inst->get( 'menu_slug' ) . '-settings';

		register_setting(
			$settings_group,
			$option_key,
			array(
				'type'              => 'array',
				'sanitize_callback' => function ( $input ) use ( $inst ) {
					return self::sanitize_for( $inst, $input );
				},
			)
		);

		// General section.
		add_settings_section(
			$inst->get( 'menu_slug' ) . '_general',
			__( 'General', 'coinsnap-core' ),
			function () {
				echo '<p>' . esc_html__( 'Configure payment gateway settings.', 'coinsnap-core' ) . '</p>';
			},
			$settings_page
		);

		add_settings_field(
			'payment_provider',
			__( 'Bitcoin Payment Gateway', 'coinsnap-core' ),
			function () use ( $inst, $option_key ) {
				$s = self::get_settings_for( $inst );
				echo '<select name="' . esc_attr( $option_key ) . '[payment_provider]">';
				foreach ( array(
					'coinsnap' => 'Coinsnap',
					'btcpay'   => 'BTCPay',
				) as $k => $label ) {
					echo '<option value="' . esc_attr( $k ) . '" ' . selected( $k, $s['payment_provider'], false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
			},
			$settings_page,
			$inst->get( 'menu_slug' ) . '_general'
		);

		$group           = $settings_page;
		$section_general = $inst->get( 'menu_slug' ) . '_general';

		add_settings_field(
			'theme',
			__( 'Theme', $inst->get('text_domain') ),
			function () use ( $inst ) {
				$s = self::get_settings_for( $inst );
				$val = $s['theme'] ?? 'light';
				echo '<select name="' . esc_attr( $inst->option_key() ) . '[theme]" class="csc-field-select">';
				echo '<option value="light"' . selected( $val, 'light', false ) . '>' . esc_html__( 'Light', $inst->get('text_domain') ) . '</option>';
				echo '<option value="dark"' . selected( $val, 'dark', false ) . '>' . esc_html__( 'Dark', $inst->get('text_domain') ) . '</option>';
				echo '</select>';
			},
			$group,
			$section_general
		);

		// Coinsnap section.
		add_settings_section(
			$inst->get( 'menu_slug' ) . '_coinsnap',
			__( 'Coinsnap', 'coinsnap-core' ),
			function () {
				echo '<p class="description">' . esc_html__( 'Configure Coinsnap settings. These fields are only relevant when Coinsnap is selected as the payment provider.', 'coinsnap-core' ) . '</p>';
			},
			$settings_page
		);

		add_settings_field(
			'coinsnap_store_id',
			__( 'Coinsnap Store ID', 'coinsnap-core' ),
			function () use ( $inst, $option_key ) {
				$s = self::get_settings_for( $inst );
				echo '<input type="text" class="regular-text" name="' . esc_attr( $option_key ) . '[coinsnap_store_id]" value="' . esc_attr( $s['coinsnap_store_id'] ) . '" />';
			},
			$settings_page,
			$inst->get( 'menu_slug' ) . '_coinsnap'
		);

		add_settings_field(
			'coinsnap_api_key',
			__( 'Coinsnap API Key', 'coinsnap-core' ),
			function () use ( $inst, $option_key ) {
				$s = self::get_settings_for( $inst );
				echo '<input type="text" class="regular-text" name="' . esc_attr( $option_key ) . '[coinsnap_api_key]" value="' . esc_attr( $s['coinsnap_api_key'] ) . '" />';
			},
			$settings_page,
			$inst->get( 'menu_slug' ) . '_coinsnap'
		);

		// BTCPay section.
		add_settings_section(
			$inst->get( 'menu_slug' ) . '_btcpay',
			__( 'BTCPay Server', 'coinsnap-core' ),
			function () {
				echo '<p class="description">' . esc_html__( 'Configure BTCPay Server settings. These fields are only relevant when BTCPay Server is selected as the payment provider.', 'coinsnap-core' ) . '</p>';
			},
			$settings_page
		);

		$menu_slug = $inst->get( 'menu_slug' );

		add_settings_field(
			'btcpay_host',
			__( 'BTCPay Server Host', 'coinsnap-core' ),
			function () use ( $inst, $option_key, $menu_slug ) {
				$s = self::get_settings_for( $inst );
				echo '<input type="url" id="' . esc_attr( $menu_slug ) . '_btcpay_url" class="regular-text" name="' . esc_attr( $option_key ) . '[btcpay_host]" value="' . esc_attr( $s['btcpay_host'] ) . '" /><br/><button class="button btcpay-apikey-link" type="button" id="' . esc_attr( $menu_slug ) . '_btcpay_wizard_button" target="_blank">' . esc_html__( 'Generate API key', 'coinsnap-core' ) . '</button>';
			},
			$settings_page,
			$inst->get( 'menu_slug' ) . '_btcpay'
		);

		add_settings_field(
			'btcpay_api_key',
			__( 'BTCPay API Key', 'coinsnap-core' ),
			function () use ( $inst, $option_key ) {
				$s = self::get_settings_for( $inst );
				echo '<input type="text" class="regular-text" name="' . esc_attr( $option_key ) . '[btcpay_api_key]" value="' . esc_attr( $s['btcpay_api_key'] ) . '" />';
			},
			$settings_page,
			$inst->get( 'menu_slug' ) . '_btcpay'
		);

		add_settings_field(
			'btcpay_store_id',
			__( 'BTCPay Store ID', 'coinsnap-core' ),
			function () use ( $inst, $option_key ) {
				$s = self::get_settings_for( $inst );
				echo '<input type="text" class="regular-text" name="' . esc_attr( $option_key ) . '[btcpay_store_id]" value="' . esc_attr( $s['btcpay_store_id'] ) . '" />';
			},
			$settings_page,
			$inst->get( 'menu_slug' ) . '_btcpay'
		);

		// Advanced section.
		add_settings_section(
			$inst->get( 'menu_slug' ) . '_advanced',
			__( 'Advanced', 'coinsnap-core' ),
			function () {
				echo '<p class="description">' . esc_html__( 'Advanced configuration options.', 'coinsnap-core' ) . '</p>';
			},
			$settings_page
		);

		add_settings_field(
			'log_level',
			__( 'Log Level', 'coinsnap-core' ),
			function () use ( $inst, $option_key ) {
				$s = self::get_settings_for( $inst );
				echo '<select name="' . esc_attr( $option_key ) . '[log_level]">';
				foreach ( array(
					'error'   => __( 'Error', 'coinsnap-core' ),
					'warning' => __( 'Warning', 'coinsnap-core' ),
					'info'    => __( 'Info', 'coinsnap-core' ),
					'debug'   => __( 'Debug', 'coinsnap-core' ),
				) as $k => $label ) {
					echo '<option value="' . esc_attr( $k ) . '" ' . selected( $k, $s['log_level'], false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
			},
			$settings_page,
			$inst->get( 'menu_slug' ) . '_advanced'
		);
	}

	/**
	 * Return discount settings array for a plugin instance.
	 *
	 * @param PluginInstance $inst Plugin instance.
	 * @return array { enabled: bool, type: string, value: float }
	 */
	public static function get_discount_settings( PluginInstance $inst ): array {
		$s = self::get_settings_for( $inst );
		return array(
			'enabled' => ! empty( $s['bitcoin_discount_enabled'] ),
			'type'    => $s['bitcoin_discount_type'] ?? 'percentage',
			'value'   => (float) ( $s['bitcoin_discount_value'] ?? 0.0 ),
		);
	}

	/**
	 * Sanitize settings input for a plugin instance.
	 *
	 * @param PluginInstance $inst  Plugin instance.
	 * @param array          $input Raw input.
	 * @return array Sanitized input.
	 */
	public static function sanitize_for( PluginInstance $inst, array $input ): array {
		$sanitized = array();

		// Sanitize text fields.
		$text_fields = array(
			'payment_provider',
			'coinsnap_api_key',
			'coinsnap_store_id',
			'coinsnap_webhook_secret',
			'btcpay_api_key',
			'btcpay_store_id',
			'btcpay_webhook_secret',
			'log_level',
		);

		foreach ( $text_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_text_field( $input[ $field ] );
			}
		}

		// Sanitize URL fields.
		$url_fields = array( 'coinsnap_api_base', 'btcpay_host' );
		foreach ( $url_fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$sanitized[ $field ] = sanitize_url( $input[ $field ] );
			}
		}

		// Sanitize theme field.
		if ( isset( $input['theme'] ) ) {
			$sanitized['theme'] = in_array( $input['theme'], array( 'light', 'dark' ), true ) ? $input['theme'] : 'light';
		}

		// Sanitize ngrok URL.
		if ( isset( $input['ngrok_url'] ) ) {
			$sanitized['ngrok_url'] = esc_url_raw( $input['ngrok_url'] );
		}

		// Sanitize boolean fields.
		$sanitized['disable_webhook_verification'] = isset( $input['disable_webhook_verification'] ) && $input['disable_webhook_verification'];

		// Sanitize Bitcoin Discount fields.
		$sanitized['bitcoin_discount_enabled'] = ! empty( $input['bitcoin_discount_enabled'] );
		if ( isset( $input['bitcoin_discount_type'] ) ) {
			$sanitized['bitcoin_discount_type'] = in_array( $input['bitcoin_discount_type'], array( 'percentage', 'fixed' ), true )
				? $input['bitcoin_discount_type']
				: 'percentage';
		}
		if ( isset( $input['bitcoin_discount_value'] ) ) {
			$sanitized['bitcoin_discount_value'] = max( 0.0, (float) $input['bitcoin_discount_value'] );
		}

		// Clear connection transients so next admin page load re-checks.
		$old        = self::get_settings_for( $inst );
		$prefix     = $inst->get( 'menu_slug' );
		$api_url    = $sanitized['coinsnap_api_base'] ?? $old['coinsnap_api_base'];
		$store_id   = $sanitized['coinsnap_store_id'] ?? $old['coinsnap_store_id'];
		delete_transient( $prefix . '_conn_' . md5( $api_url . $store_id ) );

		$btcpay_url   = $sanitized['btcpay_host'] ?? $old['btcpay_host'];
		$btcpay_store = $sanitized['btcpay_store_id'] ?? $old['btcpay_store_id'];
		delete_transient( $prefix . '_conn_' . md5( $btcpay_url . $btcpay_store ) );

		return $sanitized;
	}

	/**
	 * Render the settings page for a plugin instance.
	 *
	 * @param PluginInstance $inst Plugin instance.
	 */
	public static function render_page_for( PluginInstance $inst ): void {
		$s               = self::get_settings_for( $inst );
		$option_key      = $inst->option_key();
		$settings_group  = $inst->get( 'menu_slug' ) . '_settings_group';
		$plugin_name     = $inst->get( 'plugin_name' );
		$plugin_icon_url = $inst->get( 'plugin_icon_url' );
		$menu_slug       = $inst->get( 'menu_slug' );
		$help_links      = $inst->get( 'help_links', array() );
		$saved           = isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'];
		?>
		<div class="wrap csc-admin csc-settings-page">
			<?php if ( $saved ) : ?>
			<div class="csc-toast" id="csc-save-toast">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
				<?php esc_html_e( 'Settings saved successfully', 'coinsnap-core' ); ?>
			</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( $settings_group ); ?>

				<!-- Page Header with Connection Badge -->
				<div class="csc-settings-header">
					<div class="csc-settings-header-left">
						<h1>
							<?php if ( ! empty( $plugin_icon_url ) ) : ?>
								<span class="csc-header-icon">
									<img src="<?php echo esc_url( $plugin_icon_url ); ?>" alt="" />
								</span>
							<?php endif; ?>
							<?php echo esc_html( $plugin_name ); ?>
						</h1>
						<p class="csc-settings-subtitle"><?php esc_html_e( 'Connect your payment provider to start accepting Bitcoin payments.', 'coinsnap-core' ); ?></p>
					</div>
					<div class="csc-connection-badge" id="csc-connection-badge">
						<span class="csc-status-dot"></span>
						<span class="csc-connection-text"><?php esc_html_e( 'Checking...', 'coinsnap-core' ); ?></span>
					</div>
				</div>

				<!-- Hidden div for legacy AJAX connection check -->
				<div class="coinsnapConnectionStatus" style="display:none;"></div>

				<!-- Payment Provider Card -->
				<div class="csc-card">
					<div class="csc-card-header">
						<h2><?php esc_html_e( 'Payment Gateway', 'coinsnap-core' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Choose your Bitcoin payment provider and enter your credentials.', 'coinsnap-core' ); ?></p>
					</div>
					<div class="csc-card-body">

						<!-- Provider Toggle -->
						<div class="csc-provider-toggle" role="radiogroup" aria-label="<?php esc_attr_e( 'Payment provider', 'coinsnap-core' ); ?>">
							<input type="radio"
								name="<?php echo esc_attr( $option_key ); ?>[payment_provider]"
								id="csc-provider-coinsnap"
								value="coinsnap"
								<?php checked( $s['payment_provider'], 'coinsnap' ); ?>
							/>
							<label for="csc-provider-coinsnap">
								<span class="csc-provider-icon">
									<img src="<?php echo esc_url( COINSNAP_CORE_PLUGIN_URL . 'assets/img/coinsnap-icon.svg' ); ?>" alt="" />
								</span>
								Coinsnap
							</label>
							<input type="radio"
								name="<?php echo esc_attr( $option_key ); ?>[payment_provider]"
								id="csc-provider-btcpay"
								value="btcpay"
								<?php checked( $s['payment_provider'], 'btcpay' ); ?>
							/>
							<label for="csc-provider-btcpay">
								<span class="csc-provider-icon">
									<img src="<?php echo esc_url( COINSNAP_CORE_PLUGIN_URL . 'assets/img/btcpay-icon.svg' ); ?>" alt="" />
								</span>
								BTCPay Server
							</label>
						</div>

						<!-- Coinsnap Fields -->
						<div class="csc-provider-panel" id="csc-panel-coinsnap" data-provider="coinsnap">
							<div class="csc-field-row">
								<label for="csc-coinsnap-store-id"><?php esc_html_e( 'Store ID', 'coinsnap-core' ); ?></label>
								<input type="text"
									id="csc-coinsnap-store-id"
									name="<?php echo esc_attr( $option_key ); ?>[coinsnap_store_id]"
									value="<?php echo esc_attr( $s['coinsnap_store_id'] ); ?>"
									placeholder="<?php esc_attr_e( 'Enter your Coinsnap Store ID', 'coinsnap-core' ); ?>"
								/>
								<p class="csc-field-description"><?php esc_html_e( 'Find this in your Coinsnap dashboard under Store Settings.', 'coinsnap-core' ); ?></p>
							</div>
							<div class="csc-field-row">
								<label for="csc-coinsnap-api-key"><?php esc_html_e( 'API Key', 'coinsnap-core' ); ?></label>
								<input type="text"
									id="csc-coinsnap-api-key"
									name="<?php echo esc_attr( $option_key ); ?>[coinsnap_api_key]"
									value="<?php echo esc_attr( $s['coinsnap_api_key'] ); ?>"
									placeholder="<?php esc_attr_e( 'Enter your Coinsnap API Key', 'coinsnap-core' ); ?>"
								/>
								<p class="csc-field-description"><?php esc_html_e( 'Your API key from the Coinsnap dashboard.', 'coinsnap-core' ); ?></p>
							</div>
						</div>

						<!-- BTCPay Fields -->
						<div class="csc-provider-panel" id="csc-panel-btcpay" data-provider="btcpay">
							<div class="csc-field-row">
								<label for="<?php echo esc_attr( $menu_slug ); ?>_btcpay_url"><?php esc_html_e( 'Server URL', 'coinsnap-core' ); ?></label>
								<div class="csc-generate-key-wrapper">
									<input type="url"
										id="<?php echo esc_attr( $menu_slug ); ?>_btcpay_url"
										name="<?php echo esc_attr( $option_key ); ?>[btcpay_host]"
										value="<?php echo esc_attr( $s['btcpay_host'] ); ?>"
										placeholder="https://your-btcpay-instance.com"
									/>
									<button type="button" class="csc-btn-generate" id="<?php echo esc_attr( $menu_slug ); ?>_btcpay_wizard_button">
										<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
										<?php esc_html_e( 'Generate API Key', 'coinsnap-core' ); ?>
									</button>
								</div>
								<p class="csc-field-description"><?php esc_html_e( 'Enter your BTCPay Server URL, then click "Generate API Key" to authorize automatically.', 'coinsnap-core' ); ?></p>
							</div>
							<div class="csc-field-row">
								<label for="csc-btcpay-api-key"><?php esc_html_e( 'API Key', 'coinsnap-core' ); ?></label>
								<input type="text"
									id="csc-btcpay-api-key"
									name="<?php echo esc_attr( $option_key ); ?>[btcpay_api_key]"
									value="<?php echo esc_attr( $s['btcpay_api_key'] ); ?>"
									placeholder="<?php esc_attr_e( 'Auto-filled after authorization', 'coinsnap-core' ); ?>"
								/>
								<p class="csc-field-description"><?php esc_html_e( 'This field is populated automatically when you use the Generate API Key flow above.', 'coinsnap-core' ); ?></p>
							</div>
							<div class="csc-field-row">
								<label for="csc-btcpay-store-id"><?php esc_html_e( 'Store ID', 'coinsnap-core' ); ?></label>
								<input type="text"
									id="csc-btcpay-store-id"
									name="<?php echo esc_attr( $option_key ); ?>[btcpay_store_id]"
									value="<?php echo esc_attr( $s['btcpay_store_id'] ); ?>"
									placeholder="<?php esc_attr_e( 'Auto-filled after authorization', 'coinsnap-core' ); ?>"
								/>
							</div>
						</div>

					</div>
				</div>

				<!-- Advanced Settings Card (compact variant) -->
				<div class="csc-card csc-card--compact">
					<div class="csc-card-header">
						<h2><?php esc_html_e( 'Advanced', 'coinsnap-core' ); ?></h2>
					</div>
					<div class="csc-card-body">
						<div class="csc-field-row">
							<label class="csc-field-label"><?php esc_html_e( 'Theme', $inst->get('text_domain') ); ?></label>
							<div class="csc-field-input">
								<?php $theme_val = $s['theme'] ?? 'light'; ?>
								<select name="<?php echo esc_attr( $option_key ); ?>[theme]" class="csc-field-select">
									<option value="light" <?php selected( $theme_val, 'light' ); ?>><?php esc_html_e( 'Light', $inst->get('text_domain') ); ?></option>
									<option value="dark" <?php selected( $theme_val, 'dark' ); ?>><?php esc_html_e( 'Dark', $inst->get('text_domain') ); ?></option>
								</select>
							</div>
						</div>
						<div class="csc-field-row">
							<label for="csc-log-level"><?php esc_html_e( 'Log Level', 'coinsnap-core' ); ?></label>
							<select id="csc-log-level" name="<?php echo esc_attr( $option_key ); ?>[log_level]">
								<?php
								$levels = array(
									'error'   => __( 'Error', 'coinsnap-core' ),
									'warning' => __( 'Warning', 'coinsnap-core' ),
									'info'    => __( 'Info', 'coinsnap-core' ),
									'debug'   => __( 'Debug', 'coinsnap-core' ),
								);
								foreach ( $levels as $k => $label ) {
									echo '<option value="' . esc_attr( $k ) . '" ' . selected( $k, $s['log_level'], false ) . '>' . esc_html( $label ) . '</option>';
								}
								?>
							</select>
							<p class="csc-field-description"><?php esc_html_e( 'Set the verbosity of plugin logging. Use "Debug" only for troubleshooting.', 'coinsnap-core' ); ?></p>
						</div>
					</div>
				</div>

				<?php if ( ( $s['log_level'] ?? 'error' ) === 'debug' ) : ?>
				<!-- Debug Tools Card -->
				<div class="csc-card csc-card--compact">
					<div class="csc-card-header" style="border-left:3px solid #f59e0b;">
						<h2><?php esc_html_e( 'Debug Tools', 'coinsnap-core' ); ?></h2>
					</div>
					<div class="csc-card-body">
						<div class="csc-field-row">
							<label><?php esc_html_e( 'Webhook', 'coinsnap-core' ); ?></label>
							<div class="csc-field-input">
								<button type="button" class="button" id="csc-reregister-webhook"><?php esc_html_e( 'Re-register Webhook', 'coinsnap-core' ); ?></button>
								<span id="csc-webhook-status" style="margin-left:10px;"></span>
								<p class="csc-field-description"><?php esc_html_e( 'Force re-registration of the webhook with the payment provider. Use if webhooks are not being received.', 'coinsnap-core' ); ?></p>
							</div>
						</div>
						<div class="csc-field-row">
							<label style="display:flex;align-items:center;gap:8px;cursor:pointer">
								<input type="checkbox"
									id="csc-disable-webhook-verification"
									name="<?php echo esc_attr( $option_key ); ?>[disable_webhook_verification]"
									value="1"
									<?php checked( ! empty( $s['disable_webhook_verification'] ) ); ?>
								/>
								<?php esc_html_e( 'Disable Webhook Verification', 'coinsnap-core' ); ?>
							</label>
							<p class="csc-field-description"><?php esc_html_e( 'Skip webhook signature verification. Use only for debugging — disable in production after testing.', 'coinsnap-core' ); ?></p>
						</div>
						<?php
							$is_local = strpos( get_site_url(), 'localhost' ) !== false || strpos( get_site_url(), '.ddev.site' ) !== false || strpos( get_site_url(), '.local' ) !== false;
							if ( $is_local ) :
						?>
						<div class="csc-field-row">
							<label for="csc-ngrok-url"><?php esc_html_e( 'Ngrok URL', 'coinsnap-core' ); ?></label>
							<input type="url"
								id="csc-ngrok-url"
								name="<?php echo esc_attr( $option_key ); ?>[ngrok_url]"
								value="<?php echo esc_attr( $s['ngrok_url'] ?? '' ); ?>"
								placeholder="https://your-tunnel.ngrok.io"
							/>
							<p class="csc-field-description"><?php esc_html_e( 'Enter your ngrok URL for webhook testing on localhost. Webhooks will be registered with this URL instead of your local domain.', 'coinsnap-core' ); ?></p>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>

				<!-- Bitcoin Discount Card -->
				<div class="csc-card">
					<div class="csc-card-header">
						<h2><?php esc_html_e( 'Bitcoin Discount', 'coinsnap-core' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Offer a discount when customers pay with Bitcoin.', 'coinsnap-core' ); ?></p>
					</div>
					<div class="csc-card-body">
						<div class="csc-field-row">
							<label class="csc-discount-toggle">
								<input type="checkbox"
									id="csc-bitcoin-discount-enabled"
									name="<?php echo esc_attr( $option_key ); ?>[bitcoin_discount_enabled]"
									value="1"
									<?php checked( ! empty( $s['bitcoin_discount_enabled'] ) ); ?>
								/>
								<?php esc_html_e( 'Enable Bitcoin Discount', 'coinsnap-core' ); ?>
							</label>
						</div>
						<div id="csc-discount-fields" class="<?php echo empty( $s['bitcoin_discount_enabled'] ) ? 'csc-discount-fields--hidden' : ''; ?>">
							<div class="csc-field-row">
								<div class="csc-discount-inline">
									<div class="csc-discount-inline__col">
										<label for="csc-bitcoin-discount-type"><?php esc_html_e( 'Discount Type', 'coinsnap-core' ); ?></label>
										<select id="csc-bitcoin-discount-type"
											name="<?php echo esc_attr( $option_key ); ?>[bitcoin_discount_type]"
											class="csc-discount-select">
											<option value="percentage" <?php selected( $s['bitcoin_discount_type'] ?? 'percentage', 'percentage' ); ?>><?php esc_html_e( 'Percentage (%)', 'coinsnap-core' ); ?></option>
											<option value="fixed" <?php selected( $s['bitcoin_discount_type'] ?? 'percentage', 'fixed' ); ?>><?php esc_html_e( 'Fixed Amount', 'coinsnap-core' ); ?></option>
										</select>
									</div>
									<div class="csc-discount-inline__col">
										<label for="csc-bitcoin-discount-value"><?php esc_html_e( 'Value', 'coinsnap-core' ); ?></label>
										<input type="number"
											id="csc-bitcoin-discount-value"
											name="<?php echo esc_attr( $option_key ); ?>[bitcoin_discount_value]"
											value="<?php echo esc_attr( (string) ( $s['bitcoin_discount_value'] ?? 0 ) ); ?>"
											min="0"
											step="0.01"
											class="csc-discount-value-input"
										/>
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>

				<script>
				( function() {
					var chk    = document.getElementById( 'csc-bitcoin-discount-enabled' );
					var fields = document.getElementById( 'csc-discount-fields' );
					if ( chk && fields ) {
						chk.addEventListener( 'change', function() {
							fields.classList.toggle( 'csc-discount-fields--hidden', ! chk.checked );
						} );
					}
				} )();
				</script>

				<!-- Sticky Save Bar -->
				<div class="csc-save-bar">
					<p class="csc-save-hint">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
						<?php esc_html_e( 'Changes are applied after saving', 'coinsnap-core' ); ?>
					</p>
					<?php submit_button( __( 'Save Changes', 'coinsnap-core' ), 'primary', 'submit', false ); ?>
				</div>

			</form>

			<!-- Footer Help Links -->
			<?php if ( ! empty( $help_links ) && is_array( $help_links ) ) : ?>
				<div class="csc-footer-links">
					<?php
					$link_count = count( $help_links );
					$i          = 0;
					foreach ( $help_links as $link ) :
						$i++;
						?>
						<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link['label'] ); ?></a>
						<?php if ( $i < $link_count ) : ?>
							<span class="csc-sep">&middot;</span>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
