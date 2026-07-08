# Dahu - Sepa Differe Stripe

A WooCommerce extension that **defers Stripe SEPA Direct Debit charges** until a
configurable number of days after an order is marked "Completed" — reusing the
customer's already-saved mandate instead of charging them immediately at
checkout.

## Description

Many B2B and subscription-style stores collect payment via **SEPA Direct Debit
through Stripe**, using a mandate the customer has already signed electronically
(IBAN + consent checkbox). By default, Stripe charges the customer as soon as
the order is placed. This plugin lets you delay that charge — for example, until
after the order has shipped — while giving an administrator explicit control
over which customers, and which saved IBAN, are authorized to use the deferred
payment method.

At checkout, the dedicated "Deferred SEPA Direct Debit" payment gateway does
**not** charge anything: it reuses the customer's saved mandate, stores it on
the order, and puts the order on hold. Once the order is marked "Completed",
the plugin automatically schedules the real charge N days later (8 by default),
using WooCommerce's built-in Action Scheduler. The scheduled date can be viewed
and rescheduled from the order screen at any time before it fires.

## Features

- **Deferred charging** — no debit at checkout; the real SEPA charge is
  scheduled automatically N days after the order is completed.
- **Reschedule from the order screen** — view and change the scheduled charge
  date from a metabox on the order page.
- **Dedicated payment gateway** — a standalone "Deferred SEPA Direct Debit"
  gateway that only appears for customers who already have a reusable Stripe
  mandate, and only once explicitly authorized (see Configuration).
- **Mandate authorization control** — an administrator picks, per customer,
  which saved IBAN is the active mandate for deferred payments. The customer
  must also have that same IBAN set as their default payment method for the
  gateway to be offered — preventing a customer from silently switching to an
  unapproved IBAN.
- **Duplicate-charge protection** — a persistent idempotency key per order
  ensures a retried scheduled task never triggers a second charge.
- **Dedicated Stripe webhook** — listens for `payment_intent.succeeded`,
  `payment_intent.payment_failed`, and `charge.dispute.created` to finalize or
  fail the order, independently of the official Stripe plugin's own webhook.
- **Optional checkout cleanup** — hide Stripe's native SEPA Direct Debit option
  from the checkout page (to avoid a duplicate, immediate-charge alternative)
  while keeping it available on the "Add payment method" account page.
- **HPOS compatible.**

## Installation

1. Upload the plugin folder to `wp-content/plugins/`, or install the `.zip`
   file via **Plugins → Add New → Upload Plugin**.
2. Activate **Dahu - Sepa Differe Stripe** from the Plugins screen.
3. Make sure **WooCommerce** and the official **WooCommerce Stripe Payment
   Gateway** plugin are installed, active, and configured (SEPA Direct Debit
   enabled, in test mode while you validate the setup).
4. Go to **WooCommerce → Settings → Payments → Deferred SEPA Direct Debit** and
   enable the gateway.

## Configuration

Key constants near the top of the main plugin file:

| Constant | Default | Purpose |
|---|---|---|
| `ANNAD_SEPA_DELAY_DAYS` | `8` | Number of days between "Completed" and the actual charge |
| `ANNAD_SEPA_PARTICULIER_ROLES` | `customer` | WordPress role(s) that should never see the deferred gateway |
| `ANNAD_SEPA_NOTIFY_EMAIL` | *(empty → site admin email)* | Notified when a customer changes their default SEPA mandate |
| `ANNAD_SEPA_CONTACT_EMAIL` | *(empty → site admin email)* | Shown to customers as the contact address for mandate changes |
| `ANNAD_SEPA_WEBHOOK_SECRET` | *(empty)* | Signing secret for this plugin's dedicated Stripe webhook |
| `ANNAD_SEPA_DEBUG` | `false` | Shows diagnostic panels on the order screen and checkout for admins |

**Authorizing a customer:** open the customer's user profile in
**Users**, and in the "Deferred SEPA Direct Debit" section, select which saved
IBAN is the active mandate for that customer (or `<none>` to disable it for
them). The customer must also set that same IBAN as their default payment
method under "My account → Payment methods" for the gateway to appear at
checkout.

**Webhook setup:** create a webhook endpoint in your Stripe Dashboard pointing
to `https://yoursite.com/wp-json/annad-sepa/v1/webhook`, listening for
`payment_intent.succeeded`, `payment_intent.payment_failed`, and
`charge.dispute.created`, then paste the signing secret into
`ANNAD_SEPA_WEBHOOK_SECRET`.

## Changelog

### 1.1.0
- Added standard plugin action links ("Settings") and plugin row meta.
- Removed store-specific strings from generated Stripe descriptions and
  customer-facing contact text; both are now configurable/generic.

### 1.0.0
- Initial public release: deferred SEPA charging, dedicated payment gateway,
  per-customer mandate authorization, idempotency protection, dedicated
  webhook, optional native-SEPA checkout hiding, HPOS compatibility.

## License

GPL v2 or later. See [LICENSE](LICENSE).
