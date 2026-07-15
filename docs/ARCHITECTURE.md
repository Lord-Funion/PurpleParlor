# Architecture

The Purple Parlor is a dependency-light PHP 8.2+ application designed for Apache and MySQL/MariaDB on ordinary cPanel shared hosting. The only public entry point is `public/index.php`; all account, payment, game outcome, entitlement, and balance decisions happen on the server.

## Request flow

```text
Browser → Apache/.htaccess → public/index.php → Router → middleware
        → controller/service → PDO transaction → response/view or signed JSON
```

Middleware establishes a request ID, security headers, secure session, age policy, rate limits, CSRF, authentication, granular authorization, and private-page cache policy. Views escape by default. Static JavaScript renders server outcomes and stores only device preferences and guest presentation state.

## Data invariants

- MySQL/MariaDB is authoritative in production; SQLite is supported only for automated local tests.
- Money uses integer cents. Fictional currency uses integer units.
- Virtual balances change only through an append-only ledger within a transaction and locked wallet row.
- Each balance mutation, claim, round, purchase, and webhook uses an idempotency key plus a unique database constraint. Checkout keys are generated server-side and bound in `checkout_intents` to the user/item/period/provider/environment tuple so ambiguous browser or network retries reuse the provider request identity without crossing sandbox and live.
- Game outcomes are created on the PHP server. The browser never submits a result or balance.
- Entitlements activate only from verified provider state or the demo provider—not a return URL.
- Audit entries are append-only. Secrets and raw network identifiers are redacted/minimized.

## Main modules

- `app/Core`: configuration, routing, HTTP, views, logging, container/bootstrap.
- `app/Auth`, `Security`, `Middleware`: authentication, sessions, CSRF, rate limits, 2FA, RBAC.
- `app/Database`, `Repositories`, `Models`: PDO and persistence boundaries.
- `app/Services`: ledger, entitlements, rewards, missions, analytics, audit, support.
- `app/Games`: registry, game contracts, engines, fairness verification, simulations.
- `app/Payments`: provider-neutral demo, PayPal, and Square adapters plus webhooks.
- `app/Mail`: queued HTML/plain-text SMTP messages with retries.
- `resources/views`, `public/assets`: semantic server-rendered UI and small ES modules.
- `bin`: migrations, seed, cron, tests, simulations, reconciliation, health, backups.

## Multi-action rounds

The server records the immutable round and every legal state transition. Each action carries the round ID, current version, CSRF token, and new idempotency key. A transaction locks the round, verifies ownership/status/version/allowed action, appends the action, calculates state, and closes/pays at most once.

## Production layout

Use `/home/USER/purple-parlor/public` as the subdomain document root. Private code, configuration, migrations, logs, backups, documentation, and tests remain outside the web root. See [GODADDY_DEPLOYMENT.md](GODADDY_DEPLOYMENT.md).
