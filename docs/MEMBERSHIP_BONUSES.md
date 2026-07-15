# Membership Daily Cozy Coin Bonuses

The application grants a fixed play-money bonus once per eligible UTC date:

- Cozy Club: `COZY_CLUB_DAILY_COINS` (default `1000`)
- Cozy Club Plus: `COZY_CLUB_PLUS_DAILY_COINS` (default `2500`)

The cPanel cron dispatcher performs the grant. Each ledger entry uses
`membership:daily:{user_id}:{YYYY-MM-DD}` as its idempotency key, so repeated
cron executions cannot duplicate a user's bonus. If provider records overlap
during a plan change, the user receives only the highest eligible configured
amount for that date.

Eligible states are active or trialing subscriptions inside their current
period, a nonexpired grace period, and a cancellation scheduled for the end of
an unexpired paid-through period. Expired, refunded, disputed, suspended, and
ordinary past-due records receive nothing.

Keep the existing cPanel cron running at least every five minutes:

```text
*/5 * * * * /usr/local/bin/php /home/CPANEL_USER/purple-parlor/bin/cron.php >/dev/null 2>&1
```

To change future amounts, edit the private `.env` and keep each value between
`0` and `100000`:

```text
COZY_CLUB_DAILY_COINS=1000
COZY_CLUB_PLUS_DAILY_COINS=2500
```

Setting a value to `0` disables that plan's future daily grant. Changes never
rewrite prior append-only ledger entries. Update and publish the plan page,
checkout disclosure, Subscription Terms, Virtual Currency Policy, Terms of
Service, and Refund Policy before a changed amount is offered to customers.

Before live payment activation, test activation, same-day cron retry, next-day
grant, cancellation through paid-through, grace expiry, refund, dispute, and an
overlapping plan-change record in sandbox. Reconcile the virtual ledger after
the test and verify that no bonus changes game probabilities or payout rules.
