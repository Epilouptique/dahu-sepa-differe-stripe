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
  date from a metabox on the order page, or charge immediately with the "Charge
  now" button.
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

### Charge delay

The delay between "Completed" and the actual charge is resolved in this order:

1. **Per-customer delay** — *Users → (a customer) → "Deferred SEPA Direct Debit"
   section*. Leave empty to fall back to the global setting. Useful when a given
   B2B customer has negotiated different payment terms (e.g. net 30).
2. **Global gateway setting** — *WooCommerce → Settings → Payments → Deferred
   SEPA Direct Debit → "Default charge delay (days)"*.
3. **`ANNAD_SEPA_DELAY_DAYS`** constant (`8`) — last-resort fallback only.

### Constants

`ANNAD_SEPA_NOTIFY_EMAIL`, `ANNAD_SEPA_CONTACT_EMAIL`, `ANNAD_SEPA_DEBUG` and
`ANNAD_SEPA_WEBHOOK_SECRET` are only defined by the plugin if they are not
already defined, so they can (and should) be set in `wp-config.php` instead of
being edited into the plugin file.

| Constant | Default | Purpose |
|---|---|---|
| `ANNAD_SEPA_DELAY_DAYS` | `8` | Fallback delay, used only when neither the per-customer nor the global setting is set |
| `ANNAD_SEPA_PARTICULIER_ROLES` | `customer` | WordPress role(s) that should never see the deferred gateway |
| `ANNAD_SEPA_NOTIFY_EMAIL` | *(empty → site admin email)* | Notified when a customer changes their default SEPA mandate |
| `ANNAD_SEPA_CONTACT_EMAIL` | *(empty → site admin email)* | Shown to customers as the contact address for mandate changes |
| `ANNAD_SEPA_WEBHOOK_SECRET` | *(empty)* | Signing secret for this plugin's dedicated Stripe webhook. **Define it in `wp-config.php`, never in the plugin file** |
| `ANNAD_SEPA_DEBUG` | `false` | Shows diagnostic panels (raw Stripe metadata, gateway availability checks) on the order screen, user profile and checkout for admins. Keep `false` in production. The "Charge now" button does not depend on it |

**Authorizing a customer:** open the customer's user profile in
**Users**, and in the "Deferred SEPA Direct Debit" section, select which saved
IBAN is the active mandate for that customer (or `<none>` to disable it for
them). The customer must also set that same IBAN as their default payment
method under "My account → Payment methods" for the gateway to appear at
checkout.

**Webhook setup:** create a webhook endpoint in your Stripe Dashboard pointing
to `https://yoursite.com/wp-json/annad-sepa/v1/webhook`, listening for
`payment_intent.succeeded`, `payment_intent.payment_failed`, and
`charge.dispute.created`, then add its signing secret to `wp-config.php`:

```php
define( 'ANNAD_SEPA_WEBHOOK_SECRET', 'whsec_...' );
```

Test mode and live mode use **separate endpoints with different signing
secrets** — when you switch Stripe to live mode, create a live endpoint and
update the constant, otherwise every notification is rejected and orders stay
stuck in "processing".

## Changelog

### 1.7.2
- Removed a store-specific migration rule that hid the "Cash on delivery"
  gateway from authorized customers at checkout. Cash on delivery is no longer
  affected by this plugin.
- Restored the plugin version ("Version x.y.z") at the start of the plugin row
  meta on the Plugins screen; the custom row meta was dropping it.

### 1.7.1
- The "Charge now" button on the order screen is always available, no longer
  only in debug mode. The action itself stays protected by a capability check
  and a nonce.

### 1.7.0
- The charge delay is now configurable: a global gateway setting, overridable
  per customer from the user profile. `ANNAD_SEPA_DELAY_DAYS` is now only a
  fallback.
- Fixed Stripe customer id resolution when scheduling the charge.
- `ANNAD_SEPA_WEBHOOK_SECRET`, `ANNAD_SEPA_DEBUG`, `ANNAD_SEPA_NOTIFY_EMAIL` and
  `ANNAD_SEPA_CONTACT_EMAIL` can now be defined in `wp-config.php`; the webhook
  secret is no longer stored in the plugin file.
- `ANNAD_SEPA_DEBUG` now defaults to `false`.
- Removed the pinned `Stripe-Version` request header — the account's own API
  version is used instead.

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
