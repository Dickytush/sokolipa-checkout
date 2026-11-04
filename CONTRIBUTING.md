# Contributing to Sokolipa Checkout

Thank you for helping African merchants take mobile-money payments without a middleman. Contributions of every size are welcome: bug reports, testing in your market, new networks, translations, documentation and code review.

By taking part you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Before you start

- **Never post secrets or personal data.** Keep real consumer keys, passkeys, API keys, tokens and customers' phone numbers out of issues, pull requests, screenshots and logs. Redact them, even sandbox ones.
- **Security problems go to [SECURITY.md](SECURITY.md)**, not to public issues.
- **For bigger changes, open an issue first** so we can agree on the approach before you invest the time. Small fixes can go straight to a pull request.

## Setting up

You need PHP 7.4 or later and Git. For testing in a real store you also need a local WordPress site with WooCommerce; [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/), LocalWP, DDEV or any LAMP stack works.

```bash
# fork the repository on GitHub, then:
cd wp-content/plugins
git clone https://github.com/<your-username>/sokolipa-checkout.git
cd sokolipa-checkout
php tests/run.php        # unit tests, no WordPress needed
```

Activate the plugin, open **WooCommerce → Settings → Payments → Sokolipa Checkout**, switch a network on in **Sandbox** mode and paste sandbox keys from the operator's developer portal. Sandbox networks are only offered to logged-in store managers, so test while logged in as an administrator.

Optional tools for code style (needs [Composer](https://getcomposer.org/)):

```bash
composer install
composer lint            # PHP syntax check
composer phpcs           # WordPress Coding Standards and PHP 7.4+ compatibility
composer phpcbf          # fix what can be fixed automatically
composer build           # dist/sokolipa-checkout.zip, exactly what WordPress receives
```

## How the code fits together

- `sokolipa-checkout.php` defines the constants, the autoloader for the `Sokolipa\` namespace (`Sokolipa\Networks\Mpesa` lives in `includes/Networks/Mpesa.php`) and boots `Plugin`.
- `Gateway` is the WooCommerce payment gateway: settings screen, the network and phone fields on the classic checkout, validation and `process_payment()`.
- `Orders` is the only place that changes payment state. `start_push()` sends a prompt and records the attempt; `confirm()` asks the network for the truth and is the only code that marks an order paid or failed. It trusts `query_status()` alone, never a callback.
- `Callbacks` registers the REST routes under `/wp-json/sokolipa/v1/`: operator callbacks, the waiting screen's status poll and "send the prompt again". The last two require the order key.
- `Poller` is a one-minute WP-Cron task that settles pending prompts when nobody is watching; `ThankYou` renders the waiting panel; `SimpleCheckout` trims the classic checkout and adds the delivery place field; `Blocks/` integrates with the block checkout; `Admin/OrderScreen` shows payment details on the order screen.
- `Networks/` holds one adapter per operator API, the `Network` interface they implement, the `AbstractNetwork` base class with shared HTTP, token caching, logging and settings helpers, and the `Registry` that lists them.

## Adding a network

New networks are the most valuable contribution. A network adapter is one PHP class.

1. **Create** `includes/Networks/YourNetwork.php` with a class that extends `AbstractNetwork` and implements:
   - `id()`: a short, permanent slug such as `orange_ci`. It prefixes the settings keys and is stored on orders, so it must never change after a release.
   - `label()`, `country()` and `currency()`: what the buyer sees, and the ISO country and currency the configured account works in.
   - `is_configured()`: true only when the network is switched on and every required key is filled in.
   - `form_fields()`: start from `$this->section( $title, $description )` and prefix every field key with the network id. Show the callback URL in the description if the operator needs it registered.
   - `request_push( $order, $msisdn )`: sends the prompt and returns a `PushResult` holding the operator's reference. Throw a `NetworkException` when the operator refuses. If a timeout means the prompt may still have gone out, throw it with `$ambiguous = true` and set `provider_ref`, so the order can still be settled later.
   - `query_status( $order )`: the authoritative answer, as a `StatusResult` that is `PAID`, `FAILED` or `PENDING`. Anything that is not a final outcome, including rate-limit and "still processing" responses, must be `PENDING`.
   - `parse_callback( $request )`: returns a `CallbackHint`, or `null` for a body you do not recognise. It is only a hint and does not change the order by itself.
   - `callback_ack()`: the response body the operator expects back.
2. **Register** the class in `Registry::classes()`. Another plugin can add networks through the `sokolipa_networks` filter instead.
3. **Teach `Phone`** the country's dial code and national number lengths in `Phone::countries()`, and add an example number in `Phone::example()`.
4. **Use the helpers**: `$this->http()` for every request (it logs and redacts), `$this->cached_token()` for OAuth tokens and `$this->amount_for()` for amounts in whole units or two decimals.
5. **Document the external service** in the "External services" section of `readme.txt`: what is sent, when, and links to the operator's terms and privacy policy. WordPress.org requires this.
6. **Add tests** to `tests/run.php` for callback parsing and for how status answers map to paid, failed and pending.
7. **Test in the operator's sandbox**: a successful payment, a cancelled prompt, insufficient funds or a wrong PIN, a prompt that times out, and a callback that never reaches your site (a local site is perfect for this); the background poller must still settle the order.

In the pull request, link the operator's API documentation and say which of those cases you tested.

## Coding standards

- Follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/); the rules are in `phpcs.xml.dist`. The PHPCS job in CI is advisory while the remaining notices are cleaned up, and fixing them is a good first contribution. New code should not add notices.
- Code must pass WordPress.org's [Plugin Check](https://wordpress.org/plugins/plugin-check/) with no errors.
- Stay compatible with PHP 7.4 and add no runtime dependencies: the plugin must work from a plain zip.
- Sanitise input as early as possible and escape output as late as possible. Every user-facing string is translatable with the `sokolipa-checkout` text domain.
- Talk only to the operators the merchant configured. No tracking, telemetry or calls to any other service.
- Never log keys, tokens, PINs or full phone numbers; use `Logger` and `AbstractNetwork::redact()`.
- Write messages for buyers in plain language: tell them what happened and what to do next.

## Pull requests

- Branch from `main` and keep each pull request to one topic.
- Explain what changed and why, and how you tested it: network, sandbox or live, block or classic checkout, and the PHP, WordPress and WooCommerce versions. Add screenshots for anything a buyer or merchant sees.
- Add a line to the changelog at the bottom of `readme.txt`.
- CI must pass. It checks PHP syntax on PHP 7.4 to 8.4, runs the unit tests and checks the JavaScript.
- Contributions are accepted under the project's licence, GPL-2.0-or-later.

## Releasing (maintainers)

1. Update the version in the plugin header and `SOKOLIPA_VERSION` in `sokolipa-checkout.php`, and the `Stable tag` and changelog in `readme.txt`.
2. Publish a GitHub release with a `vX.Y.Z` tag. The release workflow builds `sokolipa-checkout.zip` with `bin/build-zip.sh` and attaches it.
3. Once the plugin is live on WordPress.org, copy the build to SVN `trunk` and `tags/X.Y.Z` (this can later be automated with an SVN deploy action and repository secrets).

Thank you for contributing!
