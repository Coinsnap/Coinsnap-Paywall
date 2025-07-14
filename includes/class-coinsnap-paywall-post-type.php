<?php
if (!defined('ABSPATH')) {
	exit;
}

class Coinsnap_Paywall_Shortcode_Metabox {
	public function __construct() {
		// Register custom post type
		add_action('init', [$this, 'register_paywall_shortcode_post_type']);

		// Add meta boxes
		add_action('add_meta_boxes', [$this, 'add_paywall_shortcode_metaboxes']);

		// Save meta box data
		add_action('save_post', [$this, 'save_paywall_shortcode_meta'], 10, 2);

		// Add custom columns to admin list
		add_filter('manage_paywall-shortcode_posts_columns', [$this, 'add_custom_columns']);
		add_action('manage_paywall-shortcode_posts_custom_column', [$this, 'populate_custom_columns'], 10, 2);
	}

	public function register_paywall_shortcode_post_type() {
		register_post_type('paywall-shortcode', [
			'labels' => [
				'name'               => 'Paywall Shortcodes',
				'singular_name'      => 'Paywall Shortcode',
				'menu_name'          => 'Paywall Shortcodes',
				'add_new'            => 'Add New',
				'add_new_item'       => 'Add New Paywall Shortcode',
				'edit_item'          => 'Edit Paywall Shortcode',
				'new_item'           => 'New Paywall Shortcode',
				'view_item'          => 'View Paywall Shortcode',
				'search_items'       => 'Search Paywall Shortcodes',
				'not_found'          => 'No paywall shortcodes found',
				'not_found_in_trash' => 'No paywall shortcodes found in Trash',
			],
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'query_var'          => true,
			'rewrite'            => ['slug' => 'paywall-shortcode'],
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => ['title'],
		]);
	}

	public function add_paywall_shortcode_metaboxes() {
		add_meta_box(
			'coinsnap_paywall_shortcode_details',
			'Paywall Shortcode Details',
			[$this, 'render_paywall_shortcode_metabox'],
			'paywall-shortcode',
			'normal',
			'high'
		);
	}

	public function render_paywall_shortcode_metabox($post) {
		// Add nonce for security
		wp_nonce_field('coinsnap_paywall_shortcode_nonce', 'coinsnap_paywall_shortcode_nonce');

		// Retrieve existing meta values
		$description = get_post_meta($post->ID, '_coinsnap_paywall_description', true);
		$button_text = get_post_meta($post->ID, '_coinsnap_paywall_button_text', true);
		$price = get_post_meta($post->ID, '_coinsnap_paywall_price', true);
		$currency = get_post_meta($post->ID, '_coinsnap_paywall_currency', true);
		$duration = get_post_meta($post->ID, '_coinsnap_paywall_duration', true);
		$theme = get_post_meta($post->ID, '_coinsnap_paywall_theme', true);
                ?>
      <table class="form-table">
        <tr>
          <th scope="row">
            <label for="coinsnap_paywall_description"><?php echo esc_html_e('Description','coinsnap-paywall')?></label>
          </th>
          <td>
                    <textarea
                        id="coinsnap_paywall_description"
                        name="coinsnap_paywall_description"
                        class="large-text"
                        rows="4"
                    ><?php echo esc_textarea($description); ?></textarea>
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="coinsnap_paywall_button_text"><?php echo esc_html_e('Button Text','coinsnap-paywall')?></label>
          </th>
          <td>
            <input
                type="text"
                id="coinsnap_paywall_button_text"
                name="coinsnap_paywall_button_text"
                class="regular-text"
                value="<?php echo esc_attr($button_text ?: 'Pay Now'); ?>"
            >
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="coinsnap_paywall_price"><?php echo esc_html_e('Price','coinsnap-paywall')?></label>
          </th>
          <td>
            <input
                type="number"
                id="coinsnap_paywall_price"
                name="coinsnap_paywall_price"
                class="regular-text"
                step="0.01"
                min="0"
                value="<?php echo esc_attr($price ?: '0'); ?>"
            >
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="coinsnap_paywall_currency"><?php echo esc_html_e('Currency','coinsnap-paywall')?></label>
          </th>
          <td>
            <select id="coinsnap_paywall_currency" name="coinsnap_paywall_currency"><?php
            foreach(COINSNAP_CURRENCIES as $coinsnap_currency){?>
                <option value="<?php echo esc_html($coinsnap_currency);?>" <?php selected($currency, $coinsnap_currency); ?>><?php echo esc_html($coinsnap_currency);?></option>
                <?php
                }?>
            </select>
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="coinsnap_paywall_duration"><?php echo esc_html_e('Duration (hours)','coinsnap-paywall')?></label>
          </th>
          <td>
            <input
                type="number"
                id="coinsnap_paywall_duration"
                name="coinsnap_paywall_duration"
                class="regular-text"
                min="1"
                value="<?php echo esc_attr($duration ?: '24'); ?>"
            >
          </td>
        </tr>
        <tr>
          <th scope="row">
            <label for="coinsnap_paywall_theme"><?php echo esc_html_e('Theme','coinsnap-paywall')?></label>
          </th>
          <td>
            <select id="coinsnap_paywall_theme" name="coinsnap_paywall_theme">
              <option value="light" <?php selected($theme, 'light'); ?>><?php echo esc_html_e('Light','coinsnap-paywall')?></option>
              <option value="dark" <?php selected($theme, 'dark'); ?>><?php echo esc_html_e('Dark','coinsnap-paywall')?></option>
            </select>
          </td>
        </tr>
      </table>

      <!-- Shortcode Display -->
      <div class="coinsnap-shortcode-display">
        <h3><?php echo esc_html_e('Shortcode','coinsnap-paywall')?></h3>
        <input type="text" class="large-text" readonly value='[paywall_payment id="<?php echo esc_html($post->ID); ?>"]'>
        <p class="description"><?php echo esc_html_e('Use this shortcode to display the paywall on any page or post.','coinsnap-paywall')?></p>
      </div>
		<?php
	}

	public function save_paywall_shortcode_meta($post_id, $post) {
		// Check if this is an autosave
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

		// Check nonce for security
		if (null === filter_input(INPUT_POST,'coinsnap_paywall_shortcode_nonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS) ||
		    !wp_verify_nonce(filter_input(INPUT_POST,'coinsnap_paywall_shortcode_nonce',FILTER_SANITIZE_FULL_SPECIAL_CHARS), 'coinsnap_paywall_shortcode_nonce')
                ){ return;}

		// Check user permissions
                if (!current_user_can('edit_post', $post_id)){ return; }

		// Check post type
                if ($post->post_type !== 'paywall-shortcode'){ return; }

		// Sanitize and save meta fields
		$meta_fields = [
			'description'   => 'coinsnap_paywall_description',
			'button_text'   => 'coinsnap_paywall_button_text',
			'price'         => 'coinsnap_paywall_price',
			'currency'      => 'coinsnap_paywall_currency',
			'duration'      => 'coinsnap_paywall_duration',
			'theme'         => 'coinsnap_paywall_theme'
		];
                
                $meta_fields_types = [
			$meta_fields['description']   => 'FILTER_SANITIZE_FULL_SPECIAL_CHARS',
			$meta_fields['button_text']   => 'FILTER_SANITIZE_FULL_SPECIAL_CHARS',
			$meta_fields['price']         => 'FILTER_VALIDATE_FLOAT',
			$meta_fields['currency']      => 'FILTER_SANITIZE_FULL_SPECIAL_CHARS',
			$meta_fields['duration']      => 'FILTER_VALIDATE_INT',
			$meta_fields['theme']         => 'FILTER_SANITIZE_FULL_SPECIAL_CHARS'
		];
                
                $post_array_filtered = filter_input_array(INPUT_POST, $meta_fields_types);

		foreach ($meta_fields as $key => $field) {
			if (isset($post_array_filtered[$field])) {
				$value = match($key) {
					'description' => sanitize_textarea_field($post_array_filtered[$field]),
					'button_text' => sanitize_text_field($post_array_filtered[$field]),
					'price'       => floatval($post_array_filtered[$field]),
					'currency'    => in_array($post_array_filtered[$field], COINSNAP_CURRENCIES) ? $post_array_filtered[$field] : 'SATS',
					'duration'    => intval($post_array_filtered[$field]),
					'theme'       => in_array($post_array_filtered[$field], ['light', 'dark']) ? $post_array_filtered[$field] : 'light',
					default      => sanitize_text_field($post_array_filtered[$field])
				};

				update_post_meta($post_id, '_' . $field, $value);
			}
		}
	}

	public function add_custom_columns($columns) {
		$new_columns = [];
		foreach ($columns as $key => $title) {
			$new_columns[$key] = $title;
			if ($key === 'title') {
				$new_columns['price'] = 'Price';
				$new_columns['currency'] = 'Currency';
				$new_columns['shortcode'] = 'Shortcode';
			}
		}
		return $new_columns;
	}

	public function populate_custom_columns($column, $post_id) {
		switch ($column) {
			case 'price':
				echo esc_html(get_post_meta($post_id, '_coinsnap_paywall_price', true) ?: '0');
				break;
			case 'currency':
				echo esc_html(get_post_meta($post_id, '_coinsnap_paywall_currency', true) ?: 'SATS');
				break;
			case 'shortcode':
				echo '<code>[paywall_payment id="' . esc_attr($post_id) . '"]</code>';
				break;
		}
	}
}

// Initialize the class
new Coinsnap_Paywall_Shortcode_Metabox();
