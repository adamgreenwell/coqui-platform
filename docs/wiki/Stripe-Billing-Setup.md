# Stripe Billing Setup

Coqui bills each **workspace** as a Stripe customer. `StripeBillingService` talks to the Stripe REST API directly; Cashier isn't used. Checkout and the customer portal open real Stripe-hosted pages. Signed webhooks then sync the plan, subscription status and invoices back onto the workspace.

## Billing modes

`StripeBillingService::mode()` decides how billing actions behave. The billing page shows a banner for anything other than `stripe`.

| Mode | When | Checkout / portal |
| --- | --- | --- |
| `stripe` | `STRIPE_SECRET` is set (and doesn't start with `mock_`) | Real Stripe sessions, in test or live mode depending on the key |
| `mock` | No key (or a `mock_` key) and `APP_ENV` is `local` or `testing` | Returns `?session_id=cs_mock_…` / `?portal_mock=1` URLs for UI work. Nothing is charged and the plan doesn't change. |
| `unconfigured` | No key in any other environment | Refuses with a clear error message. It never fakes success. |

Other refusals, also shown as a clear error:
- A plan has no `price_…` ID configured.
- A workspace with an active Pro/Agency subscription tries to buy another base plan. Plan changes go through the portal, so the customer isn't billed twice.
- The portal is opened before the workspace has a Stripe customer.
- Stripe returns an error. The detail is logged, never shown to the user.

## How webhooks keep the workspace in sync

Each workspace can have one base plan subscription (Pro or Agency) and one Runnr add-on subscription. Both are stored in `workspace_subscriptions` with type `default` or `runnr_addon`.

- **`checkout.session.completed`** applies the purchased plan or turns on the add-on. Buying the add-on never replaces the base plan's subscription ID or status. It only applies a plan the first time the app sees that subscription. A late or resent checkout event for a subscription the app already knows only fills in a missing Stripe customer ID. Its metadata still names the plan bought at checkout, so applying it again would undo a later portal switch or cancellation.
- **`customer.subscription.updated`** works out the plan from the subscription's price, by matching it against the `STRIPE_PRICE_*` IDs. That's how a Pro ↔ Agency switch in the customer portal reaches the app. The subscription's `plan_tier` metadata is only a fallback, because it keeps naming the plan bought at checkout. A scheduled cancellation is recorded as the subscription's `ends_at`, and the plan stays until it actually ends. Plan changes only apply while the status is `active`, `trialing` or `past_due`.
- **`customer.subscription.deleted`** ends only the subscription that was cancelled. Cancelling the add-on removes Runnr access and leaves the base plan alone. Cancelling the base plan drops the workspace to Free but keeps an active add-on.
- **`invoice.payment_succeeded`** adds the invoice to the workspace's billing history, and **`invoice.payment_failed`** marks it `past_due`. Stripe usually sends the first invoice *before* `checkout.session.completed`, when the workspace doesn't know its Stripe customer yet. So the workspace is found from the invoice's subscription first, then the customer, then the `workspace_id` in the subscription metadata. Metadata never attaches an invoice to a workspace that belongs to a different Stripe customer.

Changing a `STRIPE_PRICE_*` value doesn't move existing subscribers. Subscriptions on an old price fall back to their metadata until they're switched to a new price.

## Environment variables

| Variable | Value |
| --- | --- |
| `STRIPE_KEY` | Publishable key (`pk_test_…` / `pk_live_…`) |
| `STRIPE_SECRET` | Secret key (`sk_test_…` / `sk_live_…`). A restricted key needs write access to Checkout Sessions and Customer portal. |
| `STRIPE_WEBHOOK_SECRET` | Signing secret (`whsec_…`) of the webhook endpoint |
| `STRIPE_PRICE_PRO` | Recurring monthly Price ID for Pro ($49) |
| `STRIPE_PRICE_AGENCY` | Recurring monthly Price ID for Agency ($199) |
| `STRIPE_PRICE_RUNNR_ADDON` | Recurring monthly Price ID for the Runnr add-on ($29) |

Test-mode keys must be paired with test-mode prices, and live keys with live prices. Stripe rejects a price from the other mode.

## One-time Stripe dashboard setup

Do this once in test mode, then again in live mode before launch.

1. **Products → Add product**: create *Pro Cloud*, *Agency & Enterprise* and *Runnr Hosting Add-on*, each with a recurring monthly USD price. Copy each `price_…` ID into the matching `STRIPE_PRICE_*` variable.
2. **Settings → Billing → Customer portal**: activate the portal and allow cancelling and switching between the Pro and Agency prices. Under subscription updates, set proration to **invoice the prorated amount immediately** (`always_invoice`), so an upgrade charges the difference when the customer switches instead of at the next renewal.
3. **Developers → Webhooks → Add endpoint**:
   - URL: `https://<app domain>/api/webhooks/stripe`
   - Events: `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.payment_succeeded`, `invoice.payment_failed`
   - Copy the signing secret into `STRIPE_WEBHOOK_SECRET`.
4. **Developers → API keys**: copy the publishable and secret keys into `STRIPE_KEY` and `STRIPE_SECRET`.

On Coolify, set the variables on the application and redeploy (see [Coolify Deployment and Operations](Coolify-Deployment-And-Operations.md)).

## Local Docker with Stripe test mode

1. Put the test-mode values in `.env`: `STRIPE_KEY`, `STRIPE_SECRET` and the three `STRIPE_PRICE_*` IDs.
2. Forward webhooks to the local app with the [Stripe CLI](https://docs.stripe.com/stripe-cli):

   ```bash
   stripe listen --forward-to http://localhost/api/webhooks/stripe
   ```

   It prints a `whsec_…` secret. Set it as `STRIPE_WEBHOOK_SECRET` and restart the containers (`docker compose up -d`).
3. Open **Billing & Plans** for a workspace and choose a plan. Pay with the test card `4242 4242 4242 4242`, any future expiry and any CVC. After the redirect, the `checkout.session.completed` webhook upgrades the workspace.

Leave `STRIPE_SECRET` empty locally to work on the billing UI in `mock` mode without a Stripe account.
