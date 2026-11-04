# Sokolipa Checkout

**Free, open-source mobile-money checkout for WooCommerce.** Buyers enter their name, phone number, network and where to deliver, approve a payment prompt on their phone, and the order is paid. M-Pesa, MTN MoMo and Airtel Money, using the merchant's own API keys.

[![CI](https://github.com/Dickytush/sokolipa-checkout/actions/workflows/ci.yml/badge.svg)](https://github.com/Dickytush/sokolipa-checkout/actions/workflows/ci.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](license.txt)
![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg)
![WooCommerce 8.2+](https://img.shields.io/badge/WooCommerce-8.2%2B-7f54b3.svg)
![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)

Most online shoppers in Africa pay with mobile money, yet many stores still need a payment aggregator in the middle that charges a fee and holds the money. Sokolipa talks to the operators' own APIs with the merchant's keys, so payments land directly in the merchant's paybill, till or wallet. The plugin takes nothing and sends data nowhere else.

It is developed and maintained by [Snap Pay Solutions](https://snapaysolutions.com) (Nairobi) as a contribution to African e-commerce. It does not connect to the Snap Pay platform.

## What the buyer sees

1. A short checkout: full name, phone number, network and delivery place.
2. After **Place order**, a payment prompt opens on the phone (STK push, USSD push or request-to-pay, depending on the network).
3. The buyer enters their PIN. The order page waits, updates by itself and shows the receipt once the network confirms.

If the prompt never arrives, the buyer can send it again from the same page, to a corrected number or another network.

## Networks

| Network | Operator API | Markets | Prompt |
| --- | --- | --- | --- |
| **M-Pesa** | Safaricom Daraja, Lipa na M-Pesa Online | Kenya (KES) | STK push |
| **MTN MoMo** | MoMo API, Collections (request to pay) | Uganda, Ghana, Rwanda, Zambia, Cameroon, Côte d'Ivoire, Benin, Congo-Brazzaville, Liberia, South Sudan, Eswatini, Guinea | Request-to-pay prompt |
| **Airtel Money** | Airtel Africa Open API, Collections | Uganda, Kenya, Tanzania, Rwanda, Zambia, Malawi, Nigeria, DR Congo, Congo-Brazzaville, Gabon, Chad, Niger, Madagascar, Seychelles | USSD push |

Each network appears at checkout only when it is switched on, has its keys and charges in the store's currency. Vodacom M-Pesa, Mixx by Yas (Tigo Pesa), Orange Money, Wave, Moov Money and EcoCash are on the wish list: see [Adding a network](CONTRIBUTING.md#adding-a-network).

## How payments are confirmed

A payment plugin is only as good as its "paid" decision, so that decision lives in one place (`Orders::confirm()`) and follows a few rules:

- **The operator's status API is the only source of truth.** Callbacks are public by necessity and are treated as hints: every callback triggers a status query with the merchant's credentials before an order changes.
- **Nothing is left hanging.** The order page polls the store, the store queries the operator at a gentle pace (first check 10 seconds after the prompt, then every 8 seconds), callbacks trigger an immediate check, and a one-minute background task keeps checking pending prompts for 20 minutes before marking them failed.
- **Orders are protected.** The status and resend routes require the order key, each order allows at most four prompts, and a per-order lock stops a callback, the poller and the browser from processing the same answer twice.
- **Keys stay private.** Credentials are stored in the WooCommerce gateway settings and are never logged; the optional debug log redacts tokens and phone numbers.
- **Testing never touches real customers.** While a network is in sandbox mode, only logged-in store managers see it at checkout.

## Requirements

- WordPress 6.5 or later and WooCommerce 8.2 or later (block or classic checkout; HPOS compatible)
- PHP 7.4 or later
- HTTPS and pretty permalinks, so operators can reach the callback URLs
- WP-Cron running (the default), or a server cron that calls `wp-cron.php`

## Installation

- **From a release:** download `sokolipa-checkout.zip` from the [latest release](https://github.com/Dickytush/sokolipa-checkout/releases/latest), then in WordPress go to **Plugins → Add New → Upload Plugin**.
- **From source:** clone this repository into `wp-content/plugins/sokolipa-checkout`. There is no build step.
- **From WordPress.org:** the plugin is on its way to the plugin directory; this section will link to it once it is approved.

## Configuration

Go to **WooCommerce → Settings → Payments → Sokolipa Checkout**.

1. Enable the gateway and decide whether to use the simple four-field checkout.
2. For each network you use: tick **Offer … at checkout**, choose **Sandbox**, paste the keys from the operator's developer portal and save.
3. While logged in as an administrator, place a test order with your own phone.
4. Register the callback URL shown in each network's section with the operator, where the portal asks for one. Daraja's ends in `/callback/stk` because Safaricom rejects URLs that contain "mpesa".
5. Switch the network to **Live** and paste the live keys.

**Sandbox keys.** M-Pesa: create an app on [Daraja](https://developer.safaricom.co.ke/) for the consumer key and secret; the test shortcode is `174379` and its passkey is on Daraja's test credentials page. MTN MoMo: subscribe to Collections on [momodeveloper.mtn.com](https://momodeveloper.mtn.com/) and create an API user and key (the sandbox charges in EUR). Airtel Money: create an app with the Collection product on [developers.airtel.africa](https://developers.airtel.africa/).

On the **block checkout**, the network and phone fields sit inside the payment method and the delivery place is added as an extra checkout field. The full four-field reduction (no address, company or email fields) applies to the **classic** (shortcode) checkout.

## Project layout

```
sokolipa-checkout.php        Plugin header, constants, autoloader, activation hooks
uninstall.php                Removes settings and transients (order history is kept)
readme.txt                   WordPress.org readme
includes/
  Plugin.php                 Wires everything together
  Gateway.php                The WooCommerce payment gateway and its settings screen
  Orders.php                 Starting prompts, confirming payments, order meta
  Callbacks.php              REST routes: operator callbacks, status poll, resend
  Poller.php                 One-minute WP-Cron task for pending prompts
  ThankYou.php               The waiting panel on the order-received page
  SimpleCheckout.php         The four-field classic checkout and the delivery place field
  Phone.php                  Phone number formats for each country
  Logger.php                 WooCommerce logging with redaction
  Admin/OrderScreen.php      Payment details and "check with the network" on the order screen
  Blocks/                    Block checkout integration
  Networks/                  The Network interface, shared base class, registry and adapters
assets/                      Checkout, waiting-screen and block-checkout scripts and styles
tests/                       Unit tests that run with plain PHP (no WordPress needed)
bin/build-zip.sh             Builds the WordPress-ready zip in dist/
```

## Development

```bash
git clone https://github.com/Dickytush/sokolipa-checkout.git
cd sokolipa-checkout
php tests/run.php          # unit tests, plain PHP
composer install           # optional: PHP_CodeSniffer and the WordPress Coding Standards
composer phpcs
bash bin/build-zip.sh      # dist/sokolipa-checkout.zip, ready to upload to WordPress
```

Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request. Issues labelled [good first issue](https://github.com/Dickytush/sokolipa-checkout/labels/good%20first%20issue) are a friendly place to start.

## Roadmap

- More networks: Vodacom M-Pesa (Tanzania, DR Congo, Mozambique, Lesotho), Mixx by Yas, Orange Money, Wave, Moov Money, EcoCash, Halopesa and more
- Refunds through the operators' reversal APIs
- Translations, starting with Swahili, French and Portuguese
- A hosted, open-source Shopify app built on the same network adapters
- Plugin Check and end-to-end tests in CI

## Security

Please report vulnerabilities privately as described in [SECURITY.md](SECURITY.md), not in public issues.

## Licence and trademarks

Sokolipa Checkout is free software released under the [GNU General Public License v2.0 or later](license.txt).

M-Pesa, Safaricom, MTN, MoMo, Airtel and Airtel Money are trademarks of their respective owners. They are named only to identify the networks the plugin works with; this project is not affiliated with or endorsed by them. WooCommerce is a trademark of Automattic Inc.
