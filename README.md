# Coinsnap Bitcoin Paywall for WordPress

![Coinsnap Bitcoin Paywall Plugin for WordPress](https://resources.coinsnap.org/products/paywall/images/cover.png)

**Bitcoin paywall plugin for WordPress — lock articles, videos, files, downloads or content sections and unlock them with a one-time Bitcoin or Lightning payment.**

Coinsnap Bitcoin Paywall turns WordPress into a simple **pay-per-access** platform. Publishers and creators can protect premium content with a shortcode, define a price and access duration, and let visitors unlock the content after a successful Bitcoin payment.

Visitors do not need a subscription or user account to pay. Payments can be made with **Bitcoin via Lightning or on-chain** using **Coinsnap** or your own **BTCPay Server**.

Coinsnap is **Bitcoin payment software for merchants** and a brand of **Onlineshop24 d.o.o., Serbia**.

## Key features

- Monetize WordPress content with a **one-time Bitcoin payment**
- Support for **Lightning and Bitcoin on-chain**
- Protect articles, reports, videos, audio, files, downloads and content sections
- Create **multiple individual paywalls**
- Set a custom **price and currency**
- Define how many hours the content remains unlocked
- Custom paywall description and payment-button text
- Light and dark themes
- Shortcode-based placement anywhere in WordPress
- Instant access after successful payment
- No visitor registration or subscription required
- Support for **Coinsnap** and **BTCPay Server**
- Support for multiple Coinsnap currencies
- Visual Composer support
- No own Lightning node required when using Coinsnap
- **Non-custodial / self-custody** payment model
- Optional settlement to a bank account through supported Coinsnap partners

## Current version and requirements

Current plugin version: **1.3.1**

- WordPress **5.2 or newer**
- PHP **8.0 or newer**
- Tested with WordPress **6.9.x**
- A **Coinsnap merchant account**, or
- Your own **BTCPay Server**

For settlement with Coinsnap, you can use a compatible Bitcoin or Lightning wallet. One option is the [Coinsnap Wallet](https://coinsnap.io/wallet/).

## Typical use cases

Use Coinsnap Bitcoin Paywall to monetize:

- premium articles and reports
- research and market analysis
- pay-per-view videos
- bonus podcast or audio content
- tutorials and guides
- downloadable files
- templates and digital resources
- exclusive content sections
- independent creator content

## How it works

1. Install and activate Coinsnap Bitcoin Paywall.
2. Connect **Coinsnap** or your own **BTCPay Server**.
3. Create a new paywall in **Paywall Shortcodes**.
4. Give the paywall a name and description.
5. Set the price and display currency.
6. Define how long access remains unlocked.
7. Choose a light or dark theme.
8. Copy the generated shortcode into a WordPress post or page.
9. Place the premium content behind the paywall.
10. A visitor pays with Bitcoin and receives access after successful payment.

## Paywall configuration

Each paywall can be configured independently with:

- Description
- Payment button text
- Price
- Currency
- Access duration in hours
- Light or dark theme

This makes it possible to create different paywalls for different content types and price points.

## Shortcode

Each paywall receives a shortcode in this format:

`[paywall_payment id="123"]`

Replace `123` with the ID of the paywall generated in WordPress.

The shortcode can be inserted into posts, pages or other shortcode-compatible content areas.

## Coinsnap or BTCPay Server

The plugin supports two payment-provider modes:

- **Coinsnap** — for publishers and creators who want to accept Bitcoin without operating their own Bitcoin or Lightning payment infrastructure.
- **BTCPay Server** — for users who want to connect their own self-hosted BTCPay Server.

All paywalls use the payment provider selected in the plugin settings.

## Access duration

A paywall can define how long the purchased content remains unlocked.

For example, a publisher can give a visitor access for 1 hour, 24 hours or another configured period after payment.

## Settlement

With Coinsnap, payments can be settled to a compatible Bitcoin or Lightning wallet.

Coinsnap follows a **non-custodial / self-custody** approach and does not hold merchant Bitcoin.

Depending on the merchant setup and supported partner integrations, Bitcoin payments can also be converted and settled to a bank account.

## Installation

### Install from WordPress.org

1. In WordPress, go to **Plugins → Add New**.
2. Search for **Coinsnap Bitcoin Paywall**.
3. Click **Install Now** and activate the plugin.
4. Open **Coinsnap Bitcoin Paywall → Settings**.
5. Connect Coinsnap or BTCPay Server.
6. Open **Paywall Shortcodes** and create a paywall.
7. Configure price, currency, access duration and theme.
8. Add the generated shortcode to your content.
9. Make a test payment before publishing.

### Install from GitHub

1. Download this repository as a ZIP archive.
2. Upload it through **Plugins → Add New → Upload Plugin**.
3. Install and activate the plugin.
4. Connect the payment provider and create your paywalls.

## Resources

- [Coinsnap Bitcoin Paywall product page](https://coinsnap.io/wp-plugins/wp-bitcoin-paywall/)
- [WordPress Bitcoin Paywall User Guide](https://coinsnap.io/user-guides/wp-bitcoin-paywall-user-guide/)
- [WordPress Plugin Directory](https://wordpress.org/plugins/coinsnap-paywall/)
- [Demo](https://btcpaywall.com/)
- [Coinsnap Wallet](https://coinsnap.io/wallet/)
- [Coinsnap API documentation](https://docs.coinsnap.io/)
- [Coinsnap merchant account](https://app.coinsnap.io/register)
- [Telegram community and support](https://t.me/coinsnap)

> **Brand note:** Coinsnap is Bitcoin payment software for merchants and is not the provider of the similarly named coin identifier app "CoinSnap".

## Frequently Asked Questions

### What does the Coinsnap Bitcoin Paywall do?

It locks WordPress content and unlocks it after a successful one-time Bitcoin or Lightning payment.

### What types of content can I protect?

You can use the paywall for articles, pages, videos, audio, downloads, files or individual content sections that can be placed behind the shortcode.

### Can visitors pay with both Lightning and Bitcoin on-chain?

Yes. The plugin supports both Lightning and Bitcoin on-chain payments.

### Do visitors need to create an account?

No. The pay-per-access flow does not require a visitor account or subscription.

### Can I create more than one paywall?

Yes. You can create multiple paywall configurations with different prices, currencies, access durations and presentation settings.

### Can I use my own BTCPay Server?

Yes. BTCPay Server is supported as an alternative to Coinsnap.

### How long does the content remain unlocked?

You configure the access duration for each paywall in hours.

### Do I need my own Lightning node?

No. When using Coinsnap, no own Lightning node or Bitcoin payment server is required.

### Does Coinsnap hold my Bitcoin?

No. Coinsnap follows a non-custodial / self-custody approach and does not hold merchant Bitcoin.

### Can I receive settlement in euros?

Depending on the merchant setup and supported partner integrations, Bitcoin payments can be converted and settled to a bank account.

## Changelog

#### 1.0.0 :: 2024-07-08
* Initial release. 

#### 1.1.0 :: 2025-01-23
* Update: Gateway connection check is added

#### 1.2.0 :: 2025-07-14
* Update: BTCPay and Coinsnap connection check is added on gateway change
* Update: Added metadata field called type in the transaction
* Update: Added support for Visual composer
* Update: Added Coinsnap currencies support
* Compatibility with Wordpress 6.8.1 is tested

#### 1.3.0 :: 2025-12-02
* Update: Minimum order amount is added to connection status notice in backend.
* Update: Plugin is renamed to "Coinsnap Bitcoin Paywall"

#### 1.3.1 :: 2025-12-03
* Plugin menu update
* Compatibility with Wordpress 6.9 is tested.


