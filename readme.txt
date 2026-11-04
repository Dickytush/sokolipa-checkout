=== Sokolipa Checkout ===
Contributors: snap1
Tags: mobile money, mpesa, mtn momo, airtel money, woocommerce
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free mobile-money checkout for African stores: M-Pesa, MTN MoMo and Airtel Money with your own keys. Buyers approve a prompt on their phone.

== Description ==

Sokolipa Checkout lets a WooCommerce store take mobile-money payments directly from the networks, with no processor in between. The buyer enters their full name, phone number, network and where to deliver, gets a payment prompt on their phone, approves it with their PIN, and the order is marked paid once the network confirms.

**Networks in this version**

* **M-Pesa** (Safaricom Daraja, Kenya): Lipa na M-Pesa Online STK push with your paybill or till.
* **MTN MoMo** (MoMo API Collections): request-to-pay in Uganda, Ghana, Rwanda, Zambia, Cameroon, Côte d'Ivoire, Benin, Congo-Brazzaville, Liberia, South Sudan, Eswatini and Guinea.
* **Airtel Money** (Airtel Africa Open API): USSD push in Uganda, Kenya, Tanzania, Rwanda, Zambia, Malawi, Nigeria, DR Congo, Congo-Brazzaville, Gabon, Chad, Niger, Madagascar and Seychelles.

**How it works**

1. Paste the keys from the network's developer portal into WooCommerce > Settings > Payments > Sokolipa Checkout.
2. Switch on the simple checkout if you want buyers to see only four fields: full name, phone number, network and delivery place.
3. The buyer places the order and gets a prompt on their phone. The order-received page waits for the approval and updates itself.
4. The plugin confirms the result with the network's own status API (it never trusts a callback by itself), records the receipt number on the order, and marks the order paid.

**Why it exists**

Sokolipa Checkout is free and open source, developed by Snap Pay Solutions (Nairobi) as a contribution to African e-commerce. It does not connect to the Snap Pay platform, charges no fees and keeps your keys on your own site. Code and issues: https://github.com/Dickytush/sokolipa-checkout

= External services =

This plugin sends payment requests to the mobile-money network(s) you configure, using the credentials you paste in. Nothing is sent anywhere else.

* **Safaricom Daraja (M-Pesa)**, `https://api.safaricom.co.ke` (sandbox `https://sandbox.safaricom.co.ke`): the order amount, the buyer's phone number, your shortcode and an order reference are sent when a buyer pays and when the plugin checks the result. Terms and privacy: https://developer.safaricom.co.ke/
* **MTN MoMo API**, `https://proxy.momoapi.mtn.com` (sandbox `https://sandbox.momodeveloper.mtn.com`): the order amount, currency, the buyer's phone number and an order reference. Terms and privacy: https://momodeveloper.mtn.com/
* **Airtel Africa Open API (Airtel Money)**, `https://openapi.airtel.africa` (test `https://openapiuat.airtel.africa`): the order amount, currency, country, the buyer's phone number and an order reference. Terms and privacy: https://developers.airtel.africa/

Each network can call back to `/wp-json/sokolipa/v1/callback/{network}` on your site to report a result; the route is public by design because the operators have no WordPress login, and every callback is verified against the network's status API before an order changes.

= Trademarks =

M-Pesa, Safaricom, MTN, MoMo, Airtel and Airtel Money are trademarks of their respective owners. Their names are used here only to identify the payment networks supported; this plugin is not affiliated with or endorsed by them. WooCommerce is a trademark of Automattic Inc.

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. Go to WooCommerce > Settings > Payments > Sokolipa Checkout and enable it.
3. For each network you use: enable it, choose Sandbox, paste the keys from the developer portal, save, and place a test order with your own phone.
4. Register the callback URL shown in each network's section with that network (where the portal asks for one).
5. Switch the network to Live and paste the live keys.

== Frequently Asked Questions ==

= Do I need a Snap Pay account? =

No. The plugin talks only to the networks you configure. Snap Pay Solutions developed it and maintains it, nothing more.

= Which keys do I need? =

M-Pesa: consumer key, consumer secret, your paybill or till number and the Lipa na M-Pesa passkey. MTN MoMo: the Collections subscription key, an API user ID and API key. Airtel Money: client ID and client secret (plus Airtel's public key in markets that require signed requests).

= The prompt never arrives. =

Check that the phone number is registered on that network, that the phone is on and has signal, and that the plugin is in the right environment (sandbox keys only reach sandbox test numbers). The buyer can resend the prompt from the order page; the merchant can run "Check payment with the network" from the order actions box.

= What if the callback does not reach my site? =

The order-received page polls the network every few seconds, and a background task keeps checking for 20 minutes. Callbacks make confirmation faster but are not required.

= Anything to set up in WordPress first? =

Use pretty permalinks (Settings > Permalinks, anything but "Plain") so the callback URL has no query string, and allow guest checkout (WooCommerce > Settings > Accounts & Privacy) if you use the simple checkout without an email field.

= Can customers see a network that is still in sandbox? =

No. A network in sandbox is offered only to logged-in store managers, so you can place test orders on the live store while real buyers keep seeing your other payment methods. Switch the network to Live to offer it to everyone.

= Does it work with the block checkout? =

Yes. The network and phone fields appear inside the payment method, and the delivery place is added as an additional checkout field. The full four-field reduction applies to the classic (shortcode) checkout.

= Can I refund from WooCommerce? =

Not in this version. Refunds are made from the network's merchant portal; the receipt number on the order is what you need.

= How do I add another network? =

Implement the `Sokolipa\Networks\Network` interface (extend `AbstractNetwork`) and register the class with the `sokolipa_networks` filter, or open a pull request on GitHub.

== Screenshots ==

1. The four-field checkout: full name, phone, network and delivery place.
2. The waiting screen while the buyer approves the prompt on their phone.
3. The order screen with the network, receipt number and delivery place.
4. Settings: one block of keys per network, sandbox or live.

== Changelog ==

= 0.1.0 =
* First release: M-Pesa (Daraja STK push), MTN MoMo (request to pay) and Airtel Money (USSD push); simple four-field checkout; callback plus polling confirmation; block checkout support.
