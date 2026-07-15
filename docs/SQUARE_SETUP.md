# Complete Square setup for The Purple Parlor

Last verified against Square's documentation: July 14, 2026.

This is the one Square checklist for both recurring memberships and single-purchase store items on `https://purpleparlor.lordfunion.dev`. Complete the sandbox sections first. Do not unlock live payments until every sandbox test passes and the Adult Owner has completed the legal, tax, merchant, and bank review.

## 1. Cost and account choice

Use **Square Free**. You need a normal Square seller account and a free Square Developer account/application, but you do **not** need Square Plus, Square Premium, Invoices Plus, or another paid Square software subscription for this implementation.

Square says Square Subscriptions has no monthly fees or fixed costs. Real transactions still have processing fees. Current United States rates depend on how Square classifies the transaction; Square's published Free-plan table currently distinguishes online API and card-on-file charges. Confirm the rate shown in your own Square account before launch because pricing can change. Sandbox transactions are unlimited and free.

Official references:

- [Square Subscriptions pricing and setup](https://squareup.com/help/us/en/article/7627-get-started-with-subscriptions-in-dashboard)
- [Current Square processing fees](https://squareup.com/help/us/en/article/5068-what-are-square-s-fees)
- [Square Sandbox overview](https://developer.squareup.com/docs/devtools/sandbox/overview)

Do not describe the business to Square as gambling. The site sells digital memberships and permanent cosmetic/interface features for a play-money entertainment site. Cozy Coins have no cash value, cannot be bought separately, cannot be transferred, and cannot be redeemed or cashed out.

## 2. What this version supports

The site now uses one Square integration for:

- One-time card payments through the Web Payments SDK and Payments API.
- Recurring memberships through Customers, Cards, and Subscriptions APIs.
- A separate Square customer mapping for sandbox and live mode.
- Explicit card-on-file and recurring-charge consent.
- Signed Square webhooks for payment, refund, dispute, subscription, and invoice lifecycle changes.
- Server idempotency so a retry does not intentionally create a second charge or subscription.
- A five-minute server reconciliation job as a backup for missed subscription events.
- Cancellation at the end of the paid billing cycle.
- Automatic membership access and fixed daily Cozy Coin bonuses after verified provider state.

The browser sends card details directly to Square. The Purple Parlor receives a short-lived Square payment token and stores only Square customer, payment, and subscription IDs. It does not store card numbers, CVVs, or raw webhook payloads.

Google Pay, Cash App Pay, Apple Pay, ACH, gift cards, installments, and buy-now-pay-later are not enabled by this implementation.

## 3. Exact catalog to configure

### Recurring Square plan variations

Create these four Square subscription plan variations. Names may be placed under two plans or four plans, but the final variation price and cadence must match exactly.

| Website membership | Square variation name | Cadence | Price | Website bonus |
|---|---|---:|---:|---:|
| Cozy Club | `Purple Parlor - Cozy Club Monthly` | Every month | USD $2.99 | 1,000 Cozy Coins per eligible UTC day |
| Cozy Club | `Purple Parlor - Cozy Club Annual` | Every year | USD $29.99 | 1,000 Cozy Coins per eligible UTC day |
| Cozy Club Plus | `Purple Parlor - Cozy Club Plus Monthly` | Every month | USD $5.99 | 2,500 Cozy Coins per eligible UTC day |
| Cozy Club Plus | `Purple Parlor - Cozy Club Plus Annual` | Every year | USD $59.99 | 2,500 Cozy Coins per eligible UTC day |

Use fixed/static pricing, bill in advance, begin immediately, and do not add a free trial, setup fee, discount phase, relative item price, minimum commitment, installment schedule, or custom billing anchor. The website's current request is designed for a simple fixed-price variation.

Square treats the `plan_variation_id` as the purchasable recurring object. Do not paste the parent plan ID, item ID, or item variation ID into `.env`.

Official references:

- [Subscriptions API overview](https://developer.squareup.com/docs/subscriptions/overview)
- [Create and manage subscriptions](https://developer.squareup.com/docs/subscriptions-api/manage-subscriptions)
- [Subscription billing behavior](https://developer.squareup.com/docs/subscriptions-api/subscription-billing)

### Single-purchase website items

These six items are already seeded in the website database and have Square checkout prices. They do not require Square Catalog IDs because the server sends the trusted local name, amount, currency, and product reference directly to `CreatePayment`.

| Website key | Customer-facing item | One-time price | Permanent entitlement |
|---|---|---:|---|
| `purple_theme_pack` | Royal Plum Theme | USD $3.99 | Royal Plum premium theme |
| `royal_onion_profile_pack` | Royal Onion Profile Frame | USD $2.99 | Royal profile frame |
| `cozy_fireplace_soundtrack` | Cozy Fireplace Theme | USD $3.99 | Fireplace visual theme |
| `animated_crown_decoration` | Animated Crown Decoration | USD $2.49 | Animated profile crown |
| `lifetime_ad_free` | Lifetime Ad-Free Upgrade | USD $19.99 | Ads disabled permanently for that account |
| `founder_supporter_badge` | Founder Supporter Badge | USD $4.99 | Founder profile badge |

Do not create separate checkout links for these in Square Dashboard. The website card form is their checkout. Creating matching Square Catalog items for reporting is optional, but those objects will not control website price or entitlement state.

## 4. Create the Square accounts

The Adult Owner should do this with truthful legal information.

1. Create or sign in to a Square seller account.
2. Select the country where the actual owner/business operates. Square country and currency settings cannot be treated as test values in a live account.
3. Complete the identity, taxpayer, business-description, customer-facing name, and bank/payout verification Square requests.
4. Turn on two-factor authentication and store recovery information safely.
5. Do not give the Square password or access token to a developer. Use delegated access where Square offers it; otherwise the Adult Owner should paste secrets directly into private cPanel `.env`.
6. Sign in to the [Square Developer Console](https://developer.squareup.com/apps).
7. Create an application named `The Purple Parlor`.

The Developer Console provides separate **Sandbox** and **Production** credentials. Never mix them.

## 5. Build the four plans in sandbox

Square's Sandbox Dashboard has limited subscription management. Square recommends API Explorer for reliable Sandbox API testing.

1. Open Square's official [Create a subscription plan and manage subscriptions walkthrough](https://developer.squareup.com/docs/subscriptions-api/walkthrough) while signed in.
2. Select the Purple Parlor application and **Sandbox** environment in API Explorer.
3. Create simple static-price subscription plans/variations matching the four rows above. Do not use relative item pricing or order-template phases.
4. Use Catalog API **List Catalog** in Sandbox with types `SUBSCRIPTION_PLAN,SUBSCRIPTION_PLAN_VARIATION`.
5. Match each variation by its exact name, cadence, currency, and amount.
6. Copy each variation object's `id`. The value must belong to the `SUBSCRIPTION_PLAN_VARIATION` object, not its parent.
7. Record the four Sandbox IDs in a password manager with these labels:

   - `SQUARE_COZY_MONTHLY_PLAN_VARIATION_ID`
   - `SQUARE_COZY_ANNUAL_PLAN_VARIATION_ID`
   - `SQUARE_PLUS_MONTHLY_PLAN_VARIATION_ID`
   - `SQUARE_PLUS_ANNUAL_PLAN_VARIATION_ID`

### Exact API Explorer shape

For a non-itemized digital membership, use Catalog API **Upsert Catalog Object**. First create the Cozy Club parent plan with a fresh UUID in `idempotency_key`:

```json
{
  "idempotency_key": "REPLACE_WITH_A_NEW_UUID",
  "object": {
    "type": "SUBSCRIPTION_PLAN",
    "id": "#cozy-plan",
    "present_at_all_locations": true,
    "subscription_plan_data": {
      "name": "Purple Parlor - Cozy Club",
      "all_items": true
    }
  }
}
```

Copy the Square-assigned parent `catalog_object.id` from the response. Then create its monthly variation with another fresh UUID and the real parent ID:

```json
{
  "idempotency_key": "REPLACE_WITH_A_DIFFERENT_NEW_UUID",
  "object": {
    "type": "SUBSCRIPTION_PLAN_VARIATION",
    "id": "#cozy-monthly",
    "present_at_all_locations": true,
    "subscription_plan_variation_data": {
      "name": "Purple Parlor - Cozy Club Monthly",
      "subscription_plan_id": "REPLACE_WITH_REAL_COZY_PARENT_ID",
      "phases": [
        {
          "cadence": "MONTHLY",
          "ordinal": 0,
          "pricing": {
            "type": "STATIC",
            "price": { "amount": 299, "currency": "USD" }
          }
        }
      ]
    }
  }
}
```

Create the annual variation the same way, changing only these values:

```json
{
  "idempotency_key": "REPLACE_WITH_ANOTHER_NEW_UUID",
  "object": {
    "type": "SUBSCRIPTION_PLAN_VARIATION",
    "id": "#cozy-annual",
    "present_at_all_locations": true,
    "subscription_plan_variation_data": {
      "name": "Purple Parlor - Cozy Club Annual",
      "subscription_plan_id": "REPLACE_WITH_REAL_COZY_PARENT_ID",
      "phases": [
        {
          "cadence": "ANNUAL",
          "ordinal": 0,
          "pricing": {
            "type": "STATIC",
            "price": { "amount": 2999, "currency": "USD" }
          }
        }
      ]
    }
  }
}
```

Repeat the parent-plan request for `Purple Parlor - Cozy Club Plus`, then create its two variations using the new Plus parent ID:

- Monthly: name `Purple Parlor - Cozy Club Plus Monthly`, cadence `MONTHLY`, amount `599`.
- Annual: name `Purple Parlor - Cozy Club Plus Annual`, cadence `ANNUAL`, amount `5999`.

Each amount is in cents. Each API call needs a different idempotency UUID. The four IDs copied into `.env` are the Square-assigned IDs returned by the four variation calls—not the temporary `#...` client IDs and not either parent-plan ID. Square's current official schema and examples are in [Subscription Plans and Variations](https://developer.squareup.com/docs/subscriptions-api/plans-and-variations).

If the production Square Dashboard exposes Item Library subscription-plan controls, you may use those controls later for production. Always retrieve and verify the final variation IDs through the Catalog API before copying them into the site.

## 6. Collect Sandbox credentials

In Developer Console, open the Purple Parlor application and select **Sandbox**.

Copy these values:

- Application ID → `SQUARE_APPLICATION_ID`
- Sandbox access token → `SQUARE_ACCESS_TOKEN`
- Sandbox test-account location ID → `SQUARE_LOCATION_ID`

The application ID and location ID are safe to expose to the Square browser SDK. The access token is secret and must exist only in private server configuration.

Keep `SQUARE_API_VERSION=2026-05-20` for this tested release. Review Square release notes and rerun all payment tests before changing it.

## 7. Create the Sandbox webhook

In the Developer Console application:

1. Select **Sandbox**.
2. Open **Webhooks** or **Webhook subscriptions**.
3. Add this exact notification URL:

   ```text
   https://purpleparlor.lordfunion.dev/api/webhooks/square
   ```

4. Select these events:

   - `payment.created`
   - `payment.updated`
   - `refund.created`
   - `refund.updated`
   - `dispute.created`
   - `dispute.state_changed`
   - `subscription.created`
   - `subscription.updated`
   - `invoice.payment_made`
   - `invoice.scheduled_charge_failed`
   - `invoice.refunded`

5. Save the subscription.
6. Copy that webhook subscription's **Signature key** → `SQUARE_WEBHOOK_SIGNATURE_KEY`.
7. Do not copy a signature key from a different URL or environment. Square calculates the signature using the exact notification URL, raw body, and matching key.

Official references:

- [Subscribe to Square webhook notifications](https://developer.squareup.com/docs/webhooks/step2subscribe)
- [Square webhook overview and retry behavior](https://developer.squareup.com/docs/webhooks/overview)
- [Validate Square webhook signatures](https://developer.squareup.com/docs/webhooks/step3validate)
- [Failed scheduled-charge event](https://developer.squareup.com/reference/square/webhooks/invoice.scheduled_charge_failed)

## 8. Configure cPanel `.env` for Sandbox

In cPanel File Manager, edit:

```text
/home/r5xegw92uu6o/purple-parlor/.env
```

Use the following block. Replace every `PASTE_...` value. Do not add quotes unless the copied value actually contains spaces, and do not expose this file under `public_html`.

```dotenv
PAYMENTS_ENABLED=true
PAYMENT_MODE=sandbox
PAYMENT_PROVIDER=square
ADULT_OWNER_CONFIRMED=false
LIVE_PAYMENT_ACTIVATION_LOCK=true
PAYMENT_CURRENCY=USD

PAYPAL_ENABLED=false

SQUARE_ENABLED=true
SQUARE_ENVIRONMENT=sandbox
SQUARE_APPLICATION_ID=PASTE_SANDBOX_APPLICATION_ID
SQUARE_ACCESS_TOKEN=PASTE_SANDBOX_ACCESS_TOKEN
SQUARE_LOCATION_ID=PASTE_SANDBOX_LOCATION_ID
SQUARE_WEBHOOK_SIGNATURE_KEY=PASTE_SANDBOX_WEBHOOK_SIGNATURE_KEY
SQUARE_API_VERSION=2026-05-20
SQUARE_WEBHOOK_URL=https://purpleparlor.lordfunion.dev/api/webhooks/square
SQUARE_COZY_MONTHLY_PLAN_VARIATION_ID=PASTE_SANDBOX_VARIATION_ID
SQUARE_COZY_ANNUAL_PLAN_VARIATION_ID=PASTE_SANDBOX_VARIATION_ID
SQUARE_PLUS_MONTHLY_PLAN_VARIATION_ID=PASTE_SANDBOX_VARIATION_ID
SQUARE_PLUS_ANNUAL_PLAN_VARIATION_ID=PASTE_SANDBOX_VARIATION_ID
```

This intentionally keeps the production activation locks on. Sandbox checkout is allowed, but live checkout is not.

After saving, request these paths in a private browser window and confirm each returns 403 or 404 without file contents:

- `https://purpleparlor.lordfunion.dev/.env`
- `https://purpleparlor.lordfunion.dev/config/payments.php`
- `https://purpleparlor.lordfunion.dev/storage/logs/`

Stop if a secret is visible. Remove public access and rotate the exposed Square token and webhook key.

## 9. Run setup commands on cPanel

Open cPanel Terminal and run:

```bash
cd /home/r5xegw92uu6o/purple-parlor
php bin/migrate.php
php bin/seed.php
php bin/system-check.php
php bin/test.php
```

The seed command ensures all six Square product prices exist and records configured membership mappings. The application also reads the four current Square variation IDs directly from `.env`, preventing a stale database row from silently selecting the wrong environment's plan.

Keep exactly one five-minute cron entry:

```cron
*/5 * * * * /usr/local/bin/php -q /home/r5xegw92uu6o/purple-parlor/bin/cron.php >/dev/null 2>&1
```

If `/usr/local/bin/php` is not the same PHP 8.2+ CLI executable that passed `system-check.php`, use the actual path reported by `command -v php`.

## 10. Test every product in Sandbox

Square Sandbox rejects real cards. Use Square's official [Sandbox payment values](https://developer.squareup.com/docs/devtools/sandbox/payments).

A current successful Visa test is:

```text
Card: 4111 1111 1111 1111
CVV: 111
Expiration: any future month/year
Postal code: 94103
```

Use only test values from Square's current page; never type a real card into Sandbox.

### Test all six single purchases

For a fresh test account, buy each of these and confirm the feature appears only after the signed payment webhook is processed:

1. Royal Plum Theme — $3.99
2. Royal Onion Profile Frame — $2.99
3. Cozy Fireplace Theme — $3.99
4. Animated Crown Decoration — $2.49
5. Lifetime Ad-Free Upgrade — $19.99
6. Founder Supporter Badge — $4.99

For each item verify:

- The checkout page shows Square Sandbox and the exact amount.
- The network request goes to Square's Sandbox SDK, not the live SDK.
- Refreshing or double-clicking does not create two local entitlements.
- The Billing history shows the payment after webhook completion.
- Buying the already-owned permanent item is rejected.
- A completed full refund revokes that item's entitlement; a partial refund is recorded without automatically revoking the whole fixed item.
- A dispute safely revokes the affected entitlement pending Adult Owner review.

### Test all four memberships

Use a separate clean test account for each variation:

1. Cozy Club monthly — $2.99/month
2. Cozy Club annual — $29.99/year
3. Cozy Club Plus monthly — $5.99/month
4. Cozy Club Plus annual — $59.99/year

For each membership verify:

- The exact price and renewal cadence are shown before card entry.
- The separate recurring-charge/card-storage authorization must be checked.
- Square creates one Customer, one Card, and one Subscription.
- The subscription uses the expected plan variation ID.
- A verified `subscription.created` or `subscription.updated` event activates the correct entitlements.
- Cozy Club grants 1,000 extra Cozy Coins once per eligible UTC day.
- Cozy Club Plus grants 2,500 extra Cozy Coins once per eligible UTC day.
- Re-running cron on the same UTC day does not duplicate the bonus.
- Cancellation from `/billing/subscriptions` schedules the end of the current paid cycle and stops future renewals.
- A failed scheduled charge changes local status to `past_due` and follows the configured grace-period rules.
- Reconciliation repairs a missed renewal/status event.
- Expired, refunded, disputed, or otherwise ineligible membership access stops future daily grants.

Square explains that subscriptions created with a card on file are automatically charged; if the charge fails, Square can email the customer an invoice payment link. The customer profile therefore must have a valid email address.

## 11. Verify webhook delivery and local operation

In Square Developer Console webhook logs:

1. Confirm test deliveries receive HTTP 2xx.
2. Confirm the notification URL is exact.
3. Confirm the delivery uses the Sandbox webhook subscription.
4. Confirm duplicate delivery does not duplicate a payment, subscription event, entitlement, or bonus.
5. Deliberately test an invalid signature in a controlled test and confirm the endpoint rejects it.

In the Purple Parlor admin area:

- Run the Square connection test as a recently reauthenticated Adult Owner.
- Check Commerce status and the redacted payment audit.
- Check cron health and subscription reconciliation.
- Check private application/payment logs for safe error codes—not tokens or card data.

The same Square account may have unrelated Dashboard or POS payments. The webhook adapter ignores payment IDs that are neither known local payments nor website product references, so unrelated seller activity is not granted website entitlements.

## 12. Move from Sandbox to live

Sandbox and production are separate. Do not reuse any Sandbox credential, location, webhook key, customer ID, subscription ID, or plan variation ID.

1. Finish all sandbox tests above.
2. Have the Adult Owner review and publish final Terms, Subscription Terms, Privacy Policy, Refund Policy, customer support contact, cancellation process, prices, bonuses, and tax treatment.
3. Complete Square's live identity, merchant, taxpayer, bank, and payout verification.
4. In the production Square seller catalog, create the same four fixed subscription variations.
5. Retrieve the four **production** `SUBSCRIPTION_PLAN_VARIATION` IDs and verify their cadence/currency/price.
6. In Developer Console, select **Production** and copy the production Application ID, access token, and location ID.
7. Create a separate **Production** webhook subscription with the exact same URL and event list; copy its own signature key.
8. Back up the database and current `.env` securely.
9. Replace all Sandbox Square credentials and all four plan variation IDs with Production values.
10. Set:

   ```dotenv
   PAYMENTS_ENABLED=true
   PAYMENT_MODE=live
   PAYMENT_PROVIDER=square
   ADULT_OWNER_CONFIRMED=true
   SQUARE_ENABLED=true
   SQUARE_ENVIRONMENT=live
   ```

11. Keep `LIVE_PAYMENT_ACTIVATION_LOCK=true` while running the production connection test and final checklist.
12. Run `php bin/seed.php`, `php bin/system-check.php`, and `php bin/test.php` again.
13. Recently reauthenticate as the Adult Owner, open the site's **Live payment activation** admin page, verify every guard, and release both the file and persistent database activation locks through the protected workflow. Do not bypass the database lock with SQL.
14. Make one authorized low-value real purchase. Confirm the charge, signed webhook, receipt, entitlement, refund path, audit record, and Square deposit record.
15. Make one authorized membership test only when you are prepared for a real recurring agreement. Confirm the subscription, invoice, receipt, cancellation, paid-through date, and no second renewal after cancellation.

If any check fails, immediately return to:

```dotenv
PAYMENTS_ENABLED=false
PAYMENT_MODE=sandbox
PAYMENT_PROVIDER=demo
ADULT_OWNER_CONFIRMED=false
LIVE_PAYMENT_ACTIVATION_LOCK=true
SQUARE_ENABLED=false
```

Then re-lock production in the Adult Owner admin control, preserve logs, and reconcile Square before retrying.

## 13. Taxes and records

Yes, real Square sales can create tax obligations. Square being free does not make sales tax-free or income tax-free.

- The owner must report taxable business income even if Square does not issue a Form 1099-K. The IRS explicitly says income from goods or services must be reported regardless of whether it appears on Form 1099-K.
- Form 1099-K reports gross processed payments and is not reduced by refunds, Square fees, credits, or other expenses. Keep records so the return can reconcile gross receipts, fees, refunds, chargebacks, and deductible expenses.
- State and local sales-tax rules for digital memberships, themes, cosmetics, and ad-free features depend on where the owner and customers are located. Do not guess or enable a random Square tax rate.
- Before live launch, have a qualified tax professional determine registration/nexus, taxable products, customer-location sourcing, rates, filing frequency, and whether prices are tax-inclusive or tax-added.
- Export and retain Square transaction, fee, refund, dispute, payout, invoice, and 1099-K reports. Reconcile them to the site's payment records and bank deposits at least monthly.

Official references:

- [IRS: Understanding Form 1099-K](https://www.irs.gov/businesses/understanding-your-form-1099-k)
- [IRS: What to do with Form 1099-K](https://www.irs.gov/businesses/what-to-do-with-form-1099-k)
- [Square: Manage Form 1099-K](https://squareup.com/help/us/en/article/5048-1099-k-overview)

This section is operational guidance, not legal or tax advice.

## 14. Common failures

| Symptom | Likely cause | Fix |
|---|---|---|
| Square option is hidden | Payments disabled, provider environment mismatch, required credential missing, or membership variation ID missing | Compare every `.env` line; run system check; never expose secrets in the browser |
| `UNAUTHORIZED` | Sandbox token used against production or the reverse; token revoked | Select the correct Developer Console environment and replace the server token |
| `NOT_FOUND` for plan/location | Wrong environment, parent plan ID pasted instead of variation ID, or location mismatch | Retrieve the correct environment's `SUBSCRIPTION_PLAN_VARIATION` and location IDs |
| Card form fails to load | Application/location mismatch, CSP/domain issue, blocked third-party JavaScript, or wrong SDK environment | Confirm Sandbox uses `sandbox.web.squarecdn.com`; inspect safe browser/server errors |
| CreateCard rejects postal code | Sandbox card-on-file test value mismatch | Use Square's current Sandbox values, including postal code `94103` where required |
| Customer email error | Account email invalid or unavailable | Correct and verify the website account email before subscribing |
| Webhook signature invalid | Wrong environment/key, URL mismatch, proxy changed body, or key copied from another webhook | Use the exact HTTPS URL and that subscription's signature key; never modify the raw body before verification |
| Membership stays pending | Missing/failed subscription webhook or mismatched external ID | Check Square webhook logs, local redacted logs, then run reconciliation; do not manually grant access |
| Renewal becomes past due | Scheduled card charge failed | Let Square notify the customer, follow grace policy, and reconcile after payment; do not create a replacement subscription blindly |
| Duplicate-looking checkout | Ambiguous timeout or repeated click | Keep the existing checkout intent/idempotency record and reconcile with Square; never delete the intent to force another charge |
| Cancel appears immediate | Paid-through date was not received before cancellation | Check Square subscription state and reconciliation; verify the plan and `charged_through_date` before adjusting access |

## 15. Secrets and emergency response

Never put `SQUARE_ACCESS_TOKEN` or `SQUARE_WEBHOOK_SIGNATURE_KEY` in JavaScript, HTML, screenshots, tickets, chat, source control, or the public document root.

If a secret is exposed:

1. Disable site payments and restore both production locks.
2. Revoke/rotate the Square access token or webhook signature key in the correct environment.
3. Update private `.env`.
4. Test authentication and signature verification.
5. Review Square activity, local audit records, webhook logs, refunds, and disputes.
6. Document the incident and notify affected parties when legally required.

For the underlying card-storage design, see Square's [Cards API overview](https://developer.squareup.com/docs/cards-api/overview) and [charge/store card workflow](https://developer.squareup.com/docs/web-payments/charge-and-store-cards).
