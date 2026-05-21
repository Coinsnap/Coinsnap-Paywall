# Coinsnap Core

**Shared payment infrastructure library for building Bitcoin-powered WordPress plugins.**

Coinsnap Core is a drop-in PHP library that gives your WordPress plugin everything it needs to accept Bitcoin and Lightning payments through either the hosted [Coinsnap](https://coinsnap.io) service or a self-hosted [BTCPay Server](https://btcpayserver.org/) — without reinventing the payment pipeline each time.

If you've ever shipped a WordPress plugin that talks to a payment processor, you know the boring parts: settings pages, API clients, webhook handshakes, transaction tables, log viewers, BTCPay OAuth flows. Coinsnap Core packages all of it behind a simple `PluginInstance` config object so you can focus on the part that actually makes your plugin unique.

---

## Table of Contents

- [Why Coinsnap Core](#why-coinsnap-core)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [Core Classes](#core-classes)
- [Full Integration Example](#full-integration-example)
- [Implementation Ideas](#implementation-ideas)
- [Hooks & Filters](#hooks--filters)
- [Database Schema](#database-schema)
- [Webhook Flow](#webhook-flow)
- [BTCPay Authorization Flow](#btcpay-authorization-flow)
- [FAQ](#faq)
- [License](#license)

---

## Why Coinsnap Core

Before Coinsnap Core, each of our plugins (Donation, Invoice Form, Paywall, Crowdfunding, Voting) re-implemented the same ~500 lines of payment glue. The result: five subtly different API clients, five BTCPay auth flows, five webhook verifiers, five settings pages that "almost" looked the same.

Coinsnap Core replaces all of that with **one shared library** that each plugin loads via a `vendor/coinsnap-core/` directory. A built-in double-load guard (`COINSNAP_CORE_VERSION`) ensures only one copy runs even when several Coinsnap plugins are active simultaneously.

The library is **not** a singleton. Every consuming plugin creates its own `PluginInstance` with plugin-specific settings (option key, DB table, REST namespace, referral code, etc.) and passes that instance into the core classes. One library, many plugins, zero collisions.

---

## Features

- **Dual payment provider support** — Coinsnap (hosted) and BTCPay Server (self-hosted) behind one interface
- **Drop-in settings page** with connection check, provider switching, webhook verification toggle, log level selector
- **BTCPay OAuth-style authorization flow** — one line to register, user clicks through BTCPay's `/api-keys/authorize`, the callback merges credentials into your plugin's options
- **Webhook helper** with HMAC-SHA256 signature verification for both providers
- **Transactions admin page** — searchable, sortable payment list with per-plugin filtering
- **Logs admin page** with log level filtering and rotation (5 files × 5MB)
- **Exchange rate utility** pulling live BTC rates from Kraken's public API (no auth, no rate limits)
- **Shared payment DB table schema** via `dbDelta` — one call on activation and you're done
- **WordPress coding standards throughout** — nonce verification, capability checks, output escaping, sanitized inputs

---

## Requirements

- PHP 7.4 or higher (uses `declare(strict_types=1)` and typed properties)
- WordPress 6.0 or higher
- A Coinsnap store ID + API key, **or** a BTCPay Server instance with invoice permissions

---

## Installation

Coinsnap Core is designed to be **bundled inside** your plugin's `vendor/` directory, not installed from the WordPress plugin directory. This keeps your plugin self-contained and lets the double-load guard handle the case where multiple Coinsnap-based plugins are active.

### Option 1: Git submodule (recommended for active development)

```bash
cd your-plugin/
git submodule add https://github.com/coinsnap/coinsnap-core.git vendor/coinsnap-core
```

### Option 2: Manual copy

Download the latest release and extract it to `your-plugin/vendor/coinsnap-core/`.

### Option 3: Symlink (for local development across multiple consumer plugins)

```bash
ln -s /absolute/path/to/coinsnap-core your-plugin/vendor/coinsnap-core
```

Then load it from your main plugin file:

```php
require_once plugin_dir_path( __FILE__ ) . 'vendor/coinsnap-core/coinsnap-core.php';
```

---

## Quick Start

Here's the minimum viable plugin that creates a Bitcoin invoice:

```php
<?php
/**
 * Plugin Name: My Bitcoin Plugin
 */

require_once plugin_dir_path( __FILE__ ) . 'vendor/coinsnap-core/coinsnap-core.php';

use CoinsnapCore\PluginInstance;
use CoinsnapCore\Util\ProviderFactory;

add_action( 'plugins_loaded', function () {
    $instance = new PluginInstance( array(
        'plugin_name'    => 'My Bitcoin Plugin',
        'option_key'     => 'my_plugin_settings',
        'webhook_key'    => 'my_plugin_webhook',
        'table_suffix'   => 'my_plugin_payments',
        'rest_namespace' => 'myplugin/v1',
        'referral_code'  => 'YOUR_REFERRAL',
        'menu_slug'      => 'my-plugin',
    ) );

    // Somewhere in your code when a user submits a payment form:
    $provider = ProviderFactory::create( $instance );
    $invoice  = $provider->create_invoice(
        $form_id  = 42,
        $amount   = 1500,   // in cents (15.00 EUR)
        $currency = 'EUR',
        $data     = array( 'email' => 'buyer@example.com' )
    );

    // $invoice['payment_url'] → redirect the user here
    // $invoice['invoice_id'] → save this to match with the webhook later
} );
```

That's it. No custom API client, no curl, no settings page to build.

---

## Architecture

```
your-plugin/
├── your-plugin.php                  ← requires vendor/coinsnap-core/coinsnap-core.php
├── src/                             ← your plugin-specific code
│   ├── Shortcodes/
│   ├── Webhooks/
│   └── ...
└── vendor/
    └── coinsnap-core/               ← this library
        ├── coinsnap-core.php        ← bootstrap + autoloader
        └── src/
            ├── class-plugin-instance.php
            ├── Providers/           ← Coinsnap + BTCPay implementations
            ├── Admin/               ← settings, transactions, logs, AJAX
            ├── Auth/                ← BTCPay authorization
            ├── Database/            ← payment table schema
            ├── Rest/                ← webhook helpers
            └── Util/                ← HTTP client, rates, logger, factory
```

**Key design principle:** Core classes never read from or write to globals. They always receive a `PluginInstance` and read the plugin's config from there. This is what makes multiple Coinsnap-based plugins co-exist safely.

---

## Core Classes

| Class | Purpose |
|-------|---------|
| `CoinsnapCore\PluginInstance` | Immutable config container. The one thing every consuming plugin creates. |
| `CoinsnapCore\CoinsnapConstants` | API endpoint templates for Coinsnap and BTCPay. |
| `CoinsnapCore\Interfaces\PaymentProviderInterface` | Contract for payment providers (`create_invoice`, `check_invoice_status`, `handle_webhook`, etc.). |
| `CoinsnapCore\Providers\CoinsnapProvider` | Coinsnap (hosted) implementation. |
| `CoinsnapCore\Providers\BTCPayProvider` | BTCPay Server (self-hosted) implementation. |
| `CoinsnapCore\Util\ProviderFactory` | Picks the right provider based on plugin settings. **This is the main entry point you'll use.** |
| `CoinsnapCore\Util\HttpClient` | Thin wrapper around `wp_remote_request` that returns `{status, body, headers}`. |
| `CoinsnapCore\Util\ExchangeRates` | Live BTC rates from Kraken + amount/minimum validation. |
| `CoinsnapCore\Util\Logger` | Per-plugin file logger with rotation. |
| `CoinsnapCore\Util\LogLevels` | Log level constants and helpers. |
| `CoinsnapCore\Admin\SettingsPage` | Drop-in settings page renderer and registrar. |
| `CoinsnapCore\Admin\TransactionsPage` | Drop-in transactions list table. |
| `CoinsnapCore\Admin\LogsPage` | Drop-in log viewer. |
| `CoinsnapCore\Admin\AjaxHandlers` | Shared AJAX handlers for connection checks and BTCPay URL wizard. |
| `CoinsnapCore\Auth\BTCPayAuthorizer` | Builds authorization URLs and registers the callback endpoint. |
| `CoinsnapCore\Database\PaymentTable` | Creates/updates the payment table via `dbDelta`. |
| `CoinsnapCore\Rest\WebhookHelper` | Signature verification + webhook parsing for your REST routes. |

---

## Full Integration Example

Here's a more complete example showing the canonical way to integrate coinsnap-core into a WordPress plugin.

```php
<?php
/**
 * Plugin Name: My Bitcoin Checkout
 * Version: 1.0.0
 * Requires PHP: 7.4
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MBC_PLUGIN_FILE', __FILE__ );
define( 'MBC_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'MBC_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// 1. Load the core library (double-load guard inside).
require_once MBC_PLUGIN_DIR . 'vendor/coinsnap-core/coinsnap-core.php';

use CoinsnapCore\PluginInstance;
use CoinsnapCore\Util\ProviderFactory;
use CoinsnapCore\Util\Logger;
use CoinsnapCore\Admin\SettingsPage;
use CoinsnapCore\Admin\TransactionsPage;
use CoinsnapCore\Admin\AjaxHandlers;
use CoinsnapCore\Auth\BTCPayAuthorizer;
use CoinsnapCore\Database\PaymentTable;
use CoinsnapCore\Rest\WebhookHelper;

class My_Bitcoin_Checkout {

    public PluginInstance $core;
    public Logger $logger;

    public function boot(): void {
        // 2. Create the plugin instance — plugin-specific config lives here.
        $this->core = new PluginInstance( array(
            'plugin_name'              => 'My Bitcoin Checkout',
            'option_key'               => 'mbc_settings',
            'webhook_key'              => 'mbc_webhook',
            'table_suffix'             => 'mbc_payments',
            'rest_namespace'           => 'mbc/v1',
            'referral_code'            => 'YOUR_REFERRAL_CODE',
            'text_domain'              => 'my-bitcoin-checkout',
            'plugin_url'               => MBC_PLUGIN_URL,
            'plugin_dir'               => MBC_PLUGIN_DIR,
            'menu_slug'                => 'mbc',
            'btcpay_callback_endpoint' => 'mbc-btcpay-callback',
            'btcpay_app_name'          => 'My Bitcoin Checkout',
            'source_column'            => 'form_id',
            'log_dir_name'             => 'mbc-logs',
            'log_file_name'            => 'mbc.log',
            'plugin_icon_url'          => MBC_PLUGIN_URL . 'assets/img/icon.svg',
            'help_links' => array(
                array( 'url' => 'https://example.com/docs',    'label' => 'Documentation' ),
                array( 'url' => 'https://example.com/support', 'label' => 'Support' ),
            ),
        ) );

        $settings = SettingsPage::get_settings_for( $this->core );
        $this->logger = new Logger( 'mbc-logs', 'mbc.log', $settings['log_level'] ?? 'error' );

        // 3. Register activation hook — creates the payments table.
        register_activation_hook( MBC_PLUGIN_FILE, array( $this, 'activate' ) );

        // 4. Register BTCPay OAuth callback (handles the redirect from BTCPay).
        BTCPayAuthorizer::register_callback( $this->core );

        // 5. Register admin pages.
        add_action( 'admin_menu',  array( $this, 'register_admin_menu' ) );
        add_action( 'admin_init',  function () {
            SettingsPage::register_for( $this->core );
        } );
        add_action( 'admin_notices', function () {
            SettingsPage::maybe_show_setup_notice( $this->core );
        } );

        // 6. Register AJAX handlers (connection check + BTCPay URL wizard).
        add_action( 'wp_ajax_mbc_connection_check', function () {
            AjaxHandlers::handle_connection_check( $this->core );
        } );
        add_action( 'wp_ajax_mbc_btcpay_url', function () {
            AjaxHandlers::handle_btcpay_url( $this->core );
        } );

        // 7. Register REST routes for webhooks.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // 8. Your plugin's own shortcodes, CPTs, forms, etc.
        add_shortcode( 'mbc_checkout', array( $this, 'render_checkout' ) );
    }

    public function activate(): void {
        PaymentTable::activate( $this->core );
    }

    public function register_admin_menu(): void {
        add_menu_page(
            'My Bitcoin Checkout',
            'Bitcoin Checkout',
            'manage_options',
            'mbc',
            function () { TransactionsPage::render_page_for( $this->core ); },
            'dashicons-money-alt',
            58
        );
        add_submenu_page(
            'mbc',
            __( 'Settings', 'my-bitcoin-checkout' ),
            __( 'Settings', 'my-bitcoin-checkout' ),
            'manage_options',
            'mbc-settings',
            function () { SettingsPage::render_page_for( $this->core ); }
        );
    }

    public function register_rest_routes(): void {
        register_rest_route( $this->core->rest_namespace(), '/webhook/coinsnap', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => array( $this, 'handle_coinsnap_webhook' ),
        ) );
    }

    public function handle_coinsnap_webhook( WP_REST_Request $request ) {
        // Verify signature before trusting anything.
        if ( ! WebhookHelper::verify_coinsnap_signature( $this->core ) ) {
            return new WP_Error( 'invalid_signature', 'Invalid webhook signature', array( 'status' => 401 ) );
        }

        $data   = json_decode( $request->get_body(), true ) ?: array();
        $parsed = WebhookHelper::parse_webhook( 'coinsnap', $data );

        if ( $parsed['paid'] ) {
            // Your business logic here — fulfill the order, grant paywall access,
            // email the customer, update a post meta, etc.
            do_action( 'mbc_payment_settled', $parsed['invoice_id'], $parsed['metadata'] );
            $this->logger->info( "Invoice {$parsed['invoice_id']} settled" );
        }

        return rest_ensure_response( array( 'received' => true ) );
    }

    public function render_checkout( $atts ): string {
        // Create an invoice when the form is submitted, render the payment QR, etc.
        $provider = ProviderFactory::create( $this->core );
        // ...
        return '<div>checkout form here</div>';
    }
}

add_action( 'plugins_loaded', function () {
    ( new My_Bitcoin_Checkout() )->boot();
} );
```

That's a complete plugin skeleton. Everything that's not specific to your product comes from `coinsnap-core`.

---

## Implementation Ideas

Coinsnap Core is the backbone for any plugin where "accept a Bitcoin payment and do something with the result" is part of the flow. Here are ideas to spark your imagination:

### Content & Community
- **Paywall** — gate posts, podcast episodes, or course lessons behind a satoshi payment.
- **Membership site** — recurring satoshi-priced tiers with auto-expiring access rows.
- **Tipping jar** — a "buy me a beer" widget that works with Lightning in <5s.
- **Premium comments** — pay a small fee to post a highlighted comment or AMA question.

### Commerce
- **Simple Bitcoin checkout** for digital downloads without WooCommerce overhead.
- **Invoice form** — freelancers send a link, client pays in BTC, you get notified.
- **Crowdfunding / goal tracker** — pool donations toward a target with a live progress bar.
- **Event ticket sales** with a unique QR code emailed on settlement.

### Engagement & Gamification
- **Bitcoin voting / polls** — pay-to-vote for fundraising or spam resistance.
- **Bounty board** — users post paid bounties, fulfillers claim them.
- **Pay-to-enter giveaways** with on-chain fairness proofs.
- **Shoutout / super-chat** — donors leave a message that appears on a public wall.

### Integrations
- **Gravity Forms / Formidable / WPForms add-on** — attach a Bitcoin payment step to any form.
- **Download Monitor add-on** — Bitcoin-gated downloads.
- **BuddyBoss / BuddyPress tie-in** — Bitcoin tipping between community members.

Each of these reuses the same four moving parts: `create_invoice()` → redirect → webhook → fulfillment. Coinsnap Core handles three of the four for you.

---

## Hooks & Filters

### Actions

| Hook | Args | Fires when |
|------|------|------------|
| `wpbn_coinsnap_response` | `$wp_remote_response`, `$form_id` | After any Coinsnap API call. Useful for debugging. |
| `wpbn_coinsnap_webhook_received` | `$request_body` | When a Coinsnap webhook arrives, before parsing. |
| `wpbn_btcpay_response` | `$wp_remote_response`, `$form_id` | After any BTCPay API call. |
| `wpbn_btcpay_webhook_received` | `$request_body` | When a BTCPay webhook arrives, before parsing. |

### Filters

| Hook | Args | Purpose |
|------|------|---------|
| `coinsnap_core_transaction_sources` | `array $sources` | Provide a list of `{id, title}` source objects for the Transactions page dropdown. |
| `coinsnap_core_transaction_source_label` | `string $label, int $source_id` | Resolve a source ID to a human-readable label. |

Example: if your plugin uses a custom post type as the "source" of each payment (an invoice form, a poll, a campaign), wire it up like this:

```php
add_filter( 'coinsnap_core_transaction_sources', function ( $sources ) {
    $posts = get_posts( array( 'post_type' => 'my_campaign', 'posts_per_page' => -1 ) );
    foreach ( $posts as $p ) {
        $sources[] = array( 'id' => $p->ID, 'title' => $p->post_title );
    }
    return $sources;
} );

add_filter( 'coinsnap_core_transaction_source_label', function ( $label, $source_id ) {
    $post = get_post( $source_id );
    return $post ? $post->post_title : $label;
}, 10, 2 );
```

---

## Database Schema

`PaymentTable::activate( $instance )` creates a table named `{wp_prefix}{table_suffix}` with this schema:

```sql
CREATE TABLE {prefix}{table_suffix} (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_id          BIGINT UNSIGNED NOT NULL,
    transaction_id     VARCHAR(190) NOT NULL,
    invoice_number     VARCHAR(190) NULL,
    customer_name      VARCHAR(190) NOT NULL,
    customer_email     VARCHAR(190) NOT NULL,
    customer_company   VARCHAR(190) NULL,
    amount             DOUBLE NOT NULL,
    currency           VARCHAR(10) NOT NULL,
    description        TEXT NULL,
    payment_provider   VARCHAR(50) NOT NULL,
    payment_invoice_id VARCHAR(190) NOT NULL,
    payment_status     VARCHAR(20) NOT NULL DEFAULT 'unpaid',
    payment_url        TEXT NULL,
    ip                 VARCHAR(64) NULL,
    user_agent         TEXT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY source_id (source_id),
    KEY transaction_id (transaction_id),
    KEY payment_status (payment_status),
    UNIQUE KEY payment_invoice_id (payment_invoice_id)
);
```

`source_id` is where you stash the ID of whatever domain object the payment belongs to (a form, a post, a campaign, a poll option). The `source_column` config key on `PluginInstance` tells the Transactions page how to label this column in the admin UI.

---

## Webhook Flow

1. User submits your form → plugin calls `ProviderFactory::create($instance)->create_invoice(...)`.
2. User gets redirected to the Coinsnap/BTCPay checkout and pays.
3. Coinsnap/BTCPay POSTs to `{site}/wp-json/{rest_namespace}/webhook/{provider}` with a JSON body and an HMAC signature header.
4. Your REST route calls `WebhookHelper::verify_{provider}_signature( $instance )` — returns `false` if the signature is invalid or missing.
5. `WebhookHelper::parse_webhook( 'coinsnap', $data )` returns `{ invoice_id, paid, type, metadata }`.
6. Your code does whatever it needs (update DB row, email user, grant access, fire an action hook) and returns a 200 response.

Coinsnap-side webhook **registration** happens automatically when the admin clicks "Connection check" on the settings page — `AjaxHandlers::handle_connection_check()` registers the webhook if it's missing and stores the secret in the `webhook_key` option.

---

## BTCPay Authorization Flow

1. Admin enters their BTCPay server URL on the settings page and clicks "Authorize".
2. JS calls the `mbc_btcpay_url` AJAX action → `AjaxHandlers::handle_btcpay_url()` builds the `/api-keys/authorize` URL with the required permissions and a redirect back to your site.
3. Admin clicks through on BTCPay's site, approves the requested permissions, and is redirected to `{site}/?{btcpay_callback_endpoint}=1` with the new API key in the POST body.
4. `BTCPayAuthorizer::register_callback( $instance )` handles the redirect, merges `btcpay_api_key` and `btcpay_store_id` into your plugin's options, and bounces back to the settings page.

Required BTCPay permissions (set in `BTCPayAuthorizer::REQUIRED_PERMISSIONS`):

- `btcpay.store.canviewinvoices`
- `btcpay.store.cancreateinvoice`
- `btcpay.store.canviewstoresettings`
- `btcpay.store.canmodifyinvoices`

Optional (webhook management):

- `btcpay.store.cancreatenonapprovedpullpayments`
- `btcpay.store.webhooks.canmodifywebhooks`

---

## FAQ

**Can I use just one piece of Coinsnap Core without adopting the whole thing?**
Yes. The classes are loosely coupled. You can use only `HttpClient` and `ExchangeRates` without ever touching `SettingsPage` or `TransactionsPage`. Start small, adopt more as you need it.

**What happens if two plugins bundle different versions of coinsnap-core?**
The first one loaded wins (the `COINSNAP_CORE_VERSION` guard). Keep your bundled version up to date, or add a `composer`-style version constraint check in your main plugin file if you need stricter behavior.

**Does this work with WooCommerce?**
Coinsnap publishes a separate WooCommerce gateway plugin — don't use `coinsnap-core` for that. Use `coinsnap-core` when you're building *non-WooCommerce* payment experiences (paywalls, donations, custom checkouts).

**Can I add a third payment provider (e.g. OpenNode, Strike)?**
Yes. Implement `CoinsnapCore\Interfaces\PaymentProviderInterface` in your own class, then override `ProviderFactory::create()` or fork it. The interface is deliberately thin.

**Why Kraken for exchange rates?**
Public, no auth, no rate limits, reliable uptime. If Kraken is down or rate-limited, `ExchangeRates::load_rates()` returns `{ result: false, error: 'ratesLoadingError' }` — your plugin should handle that gracefully.

**How do I test webhooks locally?**
Run your dev site through ngrok, then set the `ngrok_url` setting value temporarily. `CoinsnapProvider::register_webhook()` will use that instead of `get_home_url()` for the callback URL. (See `class-coinsnap-provider.php` line ~203.)

---

## Contributing

Issues and pull requests are welcome. Please:

- Follow WordPress coding standards.
- Add/update PHPDoc for public methods.
- Don't break the double-load guard — core must remain safe to bundle in many plugins.
- Don't introduce a singleton. `PluginInstance` is the only "identity" the core has.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.

---

## Credits

Built by the [Coinsnap](https://coinsnap.io) team. Kraken public API used for exchange rates. BTCPay Server is an independent project — thanks to its maintainers for an excellent open-source payment processor.
