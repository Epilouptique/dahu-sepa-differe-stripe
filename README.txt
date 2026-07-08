=== Dahu - Sepa Differe Stripe ===
Contributors: epilouptique
Tags: woocommerce, stripe, sepa, direct debit, payment gateway
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Defer Stripe SEPA Direct Debit charges until N days after an order is completed, reusing the customer's already-saved mandate.

== Description ==

Many B2B and subscription-style stores collect payment via SEPA Direct Debit
through Stripe, using a mandate the customer has already signed electronically
(IBAN + consent checkbox). By default, Stripe charges the customer as soon as
the order is placed. This plugin lets you delay that charge — for example,
until after the order has shipped — while giving an administrator explicit
control over which customers, and which saved IBAN, are authorized to use the
deferred payment method.

At checkout, the dedicated "Deferred SEPA Direct Debit" payment gateway does
not charge anything: it reuses the customer's saved mandate, stores it on the
order, and puts the order on hold. Once the order is marked "Completed", the
plugin automatically schedules the real charge N days later (8 by default),
using WooCommerce's built-in Action Scheduler. The scheduled date can be viewed
and rescheduled from the order screen at any time before it fires.

= Features =

* Deferred charging — no debit at checkout; the real SEPA charge is scheduled automatically N days after the order is completed.
* Reschedule from the order screen — view and change the scheduled charge date from a metabox on the order page.
* Dedicated payment gateway that only appears for authorized customers with a reusable Stripe mandate.
* Mandate authorization control, per customer and per saved IBAN.
* Duplicate-charge protection via a persistent idempotency key per order.
* Dedicated Stripe webhook for succeeded/failed charges and disputes.
* Optional checkout cleanup to hide Stripe's native SEPA option from checkout while keeping it on the account page.
* HPOS compatible.

= Requirements =

* WooCommerce
* WooCommerce Stripe Payment Gateway (official plugin), with SEPA Direct Debit enabled

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/`, or install the `.zip` file via Plugins → Add New → Upload Plugin.
2. Activate "Dahu - Sepa Differe Stripe" from the Plugins screen.
3. Make sure WooCommerce and the official WooCommerce Stripe Payment Gateway plugin are installed, active, and configured.
4. Go to WooCommerce → Settings → Payments → Deferred SEPA Direct Debit and enable the gateway.

== Frequently Asked Questions ==

= Does this store credit card details or Stripe API keys? =

No. Stripe secret keys are read from the official WooCommerce Stripe Payment
Gateway plugin's own settings; this plugin never stores payment credentials.

= Will this charge the customer immediately? =

No. The dedicated gateway never charges at checkout. The charge is scheduled
automatically once the order is marked "Completed", after the configured delay.

= Can a customer choose their own mandate? =

A customer can save as many IBANs as they like with Stripe, but the plugin only
uses the one an administrator has explicitly marked as the active mandate for
that customer — and only if the customer has also set that same IBAN as their
default payment method.

== Screenshots ==

1. Order screen metabox showing the scheduled charge date and a manual reschedule field.
2. User profile screen showing the active-mandate selector.

== Changelog ==

= 1.1.0 =
* Added standard plugin action links ("Settings") and plugin row meta.
* Removed store-specific strings from generated Stripe descriptions and customer-facing contact text; both are now configurable/generic.

= 1.0.0 =
* Initial public release: deferred SEPA charging, dedicated payment gateway, per-customer mandate authorization, idempotency protection, dedicated webhook, optional native-SEPA checkout hiding, HPOS compatibility.

== Upgrade Notice ==

= 1.1.0 =
No breaking changes. Recommended for the settings link and generic contact/description strings.
