# Set Up Everything

This guide takes The Purple Parlor from a private ZIP file to `https://purpleparlor.lordfunion.dev` on a GoDaddy Web Hosting Deluxe cPanel account. Work through it in order. Menu names can change; when a label differs, look for the feature described and use GoDaddy's current help search.

The Purple Parlor is play-money entertainment, not real-money gambling. Do not enable live payments until the Adult Owner has completed every provider, legal, and security step.

## Part A — What Finn needs before beginning

- [ ] The private finished deployment ZIP and its SHA-256 checksum.
- [ ] Access to the `lordfunion.dev` DNS and Web Hosting Deluxe cPanel account, either through GoDaddy delegated access or with the account owner present.
- [ ] A parent or guardian willing to act as Adult Owner for merchant, advertising, payout, bank, tax, and legal matters.
- [ ] A support mailbox, preferably `support@lordfunion.dev`.
- [ ] A computer with enough space to keep an offline copy of the package and backups.
- [ ] A password manager or encrypted vault. Do not use a source file, browser note, or chat as a credential store.
- [ ] Working HTTPS before testing provider callbacks or enabling secure production cookies.

Finn may manage game content, appearance, users, missions, tests, and system health. The Adult Owner must create the PayPal, Square, advertising, and payout accounts; supply truthful identity, bank, and tax information directly to those providers; accept agreements; configure live secrets; review disputes/refunds/taxes; and approve launch. Finn should use delegated GoDaddy access when possible and should never ask for the Adult Owner's password.

## Part B — Local setup on Windows

1. Open PowerShell in the extracted project directory.
2. Install a supported PHP 8.x release. One option is Windows Package Manager:

   ```powershell
   winget search --id PHP.PHP
   winget install --id PHP.PHP.8.3 --exact --scope user
   ```

   Restart PowerShell, then check it:

   ```powershell
   php --version
   php -m
   ```

3. Confirm PHP is 8.2 or newer. Confirm `PDO`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `json`, `fileinfo`, `filter`, and `session`; also enable `zip` on the machine that creates the deployment archive. `intl` and `sodium` are strongly recommended. Enable missing extensions in the active `php.ini`; locate it with `php --ini`.
4. Composer is optional because the deployment includes the small project autoloader. If you use Composer, download it only from `getcomposer.org`, verify its installer as their current instructions require, then run:

   ```powershell
   composer install --no-dev --optimize-autoloader
   ```

5. Create the private environment file and application key:

   ```powershell
   Copy-Item .env.example .env
   php bin/generate-key.php
   ```

6. Install MySQL/MariaDB locally, or use an existing server. In a MySQL client run, replacing the example password:

   ```sql
   CREATE DATABASE purple_parlor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'purple_local'@'localhost' IDENTIFIED BY 'GENERATE_A_UNIQUE_PASSWORD';
   GRANT ALL PRIVILEGES ON purple_parlor.* TO 'purple_local'@'localhost';
   FLUSH PRIVILEGES;
   ```

7. Edit `.env`: set the local database host, port, name, user, and password. Keep `PAYMENT_PROVIDER=demo`, `PAYMENTS_ENABLED=false`, and the activation lock on.
8. Build the database and safe demonstration data:

   ```powershell
   php bin/migrate.php
   php bin/seed.php
   ```

9. Start PHP's local server:

   ```powershell
   php -S 127.0.0.1:8080 -t public bin/dev-router.php
   ```

10. Open `http://127.0.0.1:8080`, accept the 18+ play-money notice, and use guest demonstration mode or a development-only seeded account if one was explicitly created locally.
11. In another PowerShell window run:

   ```powershell
   php bin/system-check.php
   php bin/check-routes.php
   php bin/test.php
   php bin/simulate-games.php --mode=fast --seed=20260713
   php bin/simulate-games.php --mode=standard --seed=20260713 --csv=storage/temporary/game-report.csv
   php bin/package-deployment.php
   ```

12. Test demo payment success, failure, pending, cancellation, refund, dispute, duplicate delivery, and invalid signature from the Adult Owner's demo-payment screen. Demo events must never contact a real provider.

## Part C — Local setup on Ubuntu

These commands use Ubuntu's packages. Replace the PHP version suffix if your supported Ubuntu release provides a newer supported PHP 8.x version.

```bash
sudo apt update
sudo apt install php-cli php-mysql php-curl php-mbstring php-xml php-intl php-sodium php-zip php-fileinfo mariadb-server unzip
php --version
php -m
sudo mysql
```

At the MariaDB prompt:

```sql
CREATE DATABASE purple_parlor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'purple_local'@'localhost' IDENTIFIED BY 'GENERATE_A_UNIQUE_PASSWORD';
GRANT ALL PRIVILEGES ON purple_parlor.* TO 'purple_local'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Then:

```bash
cp .env.example .env
php bin/generate-key.php
# Edit .env with a private editor and the local database values.
php bin/migrate.php
php bin/seed.php
php bin/system-check.php
php bin/check-routes.php
php bin/test.php
php bin/simulate-games.php --mode=standard --seed=20260713
php bin/package-deployment.php
php -S 127.0.0.1:8080 -t public bin/dev-router.php
```

Composer, when desired, must be installed using the current signed installer instructions at `getcomposer.org`; then run `composer install --no-dev --optimize-autoloader`. Do not use Docker. To make a private upload package, use the project's verified packaging command rather than zipping `.env`, logs, backups, or test data.

## Part D — Get access to GoDaddy and cPanel

1. The GoDaddy account owner signs in directly at GoDaddy. Finn must not request, record, or share that password.
2. Prefer GoDaddy's account delegation/access-sharing feature if it is available. The owner grants the smallest access needed and can revoke it later.
3. In **My Products**, find the Web Hosting Deluxe product attached to `lordfunion.dev`. Open its management page.
4. Look for **cPanel Admin**, **cPanel**, or the feature that opens the hosting control panel. Opening it through GoDaddy is safer than sharing a separate cPanel password.
5. In cPanel, the right-side account summary or File Manager home path normally shows the cPanel username and home directory, such as `/home/CPANEL_USERNAME`. Record the actual values; do not assume examples are correct.
6. Open **File Manager** and identify `public_html`, the main web root. Do not delete or overwrite unrelated site files.
7. Optional FTP/SFTP: open **FTP Accounts**, create a dedicated user restricted to the Purple Parlor directory, use a generated password, and prefer SFTP when the plan supports SSH. Revoke it after setup if no longer needed.

## Part E — Create `purpleparlor.lordfunion.dev`

1. In cPanel, find **Domains**, **Subdomains**, or the area that adds a domain and document root.
2. Add `purpleparlor.lordfunion.dev`. Prefer a document root that points to the uploaded project's `public` directory, for example `/home/CPANEL_USERNAME/purple-parlor/public`.
3. In GoDaddy DNS for `lordfunion.dev`, check whether cPanel created the record. If not, add only the required `purpleparlor` record:
   - usually an `A` record to the hosting account's IPv4 address; or
   - a `CNAME` to the hostname GoDaddy tells you to use.
4. Do not change the root domain, nameservers, MX records, Microsoft 365 records, or unrelated subdomains.
5. DNS may take time to propagate. Verify from two networks or with a public DNS lookup and confirm it resolves to the hosting account.
6. If the requested subdomain is unavailable, choose another with the owner, then update `APP_URL`, canonical metadata, webhook URLs, and email links consistently.

## Part F — Select PHP and extensions

1. GoDaddy currently exposes the account PHP version from the hosting **Manage** page under **Settings → Server Settings → PHP Version → Change**. If the interface instead delegates versions per domain to cPanel, use **MultiPHP Manager** or the equivalent PHP-version selector. Follow GoDaddy's current [Web Hosting (cPanel) PHP-version guide](https://www.godaddy.com/help/update-php-to-the-latest-version-20198).
2. Select a currently supported PHP 8.x version that is at least 8.2. When the setting applies to the entire account, test every site on that account; GoDaddy documents changing back to the prior version as the rollback.
3. In cPanel's **Select PHP Version** extension page or the available module selector, confirm: `pdo`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `json`, `fileinfo`, `intl`, `sodium`, `filter`, and `session`. Some core extensions do not have checkboxes.
4. Run `php bin/system-check.php` over SSH or the protected installer check. If cPanel lacks a needed extension, contact GoDaddy support before launch.
5. If you temporarily create a `phpinfo()` page to diagnose the effective configuration, protect it, inspect it, then delete it immediately. Never leave it public.

## Part G — Create the production database

1. From the GoDaddy hosting **Manage** page select **cPanel Admin**, then under **Databases** open **MySQL Database Wizard**, matching GoDaddy's current [database creation guide](https://www.godaddy.com/help/create-a-mysql-database-in-my-web-hosting-cpanel-16016).
2. Create a database with a recognizable private suffix, such as `purpleparlor`. cPanel may prefix it: `CPANELUSER_purpleparlor`. Record the exact final name.
3. Create a dedicated database user with a generated unique password. Record the exact prefixed username.
4. Assign that user only to this database. The database-scoped grant must include `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `DROP`, `INDEX`, `ALTER`, `REFERENCES`, and `TRIGGER`; cPanel's **All Privileges** checkbox is acceptable only when it applies to this dedicated database, never globally. `TRIGGER` is mandatory because the ledger and audit tables use database-enforced append-only guards. After migration, `php bin/system-check.php` must report all eight append-only trigger guards verified.
5. Find the actual database hostname in cPanel's database information. It is often `localhost`, but must not be assumed.
6. Put the exact host, port, database, username, password, and `utf8mb4` charset in the private `.env`.
7. With SSH, run `php bin/migrate.php` and `php bin/seed.php`. Without SSH, use the protected installer. As a fallback, open **phpMyAdmin**, select the exact database, import `database/schema.sql`, then run only the safe seed step.
8. Run the installer database test or `php bin/system-check.php`. A prefix mismatch is the most common error.
9. Export the fresh database from phpMyAdmin and download the SQL file to encrypted offline storage before adding users.

Every future schema change must be a new numbered migration containing explicit, driver-aware incremental DDL such as `ALTER TABLE`, `CREATE INDEX`, or `CREATE TRIGGER`. Calling `Schema::install()` again can create missing objects but cannot alter an existing table; it is not a substitute for an incremental migration. Never edit a migration already recorded in `schema_migrations`.

## Part H — Upload the files securely

### Recommended layout

```text
/home/CPANEL_USERNAME/purple-parlor/       private application root
  app/ config/ database/ resources/ storage/ vendor/ bin/ .env
  public/                                  subdomain document root
```

Set the subdomain document root to `/home/CPANEL_USERNAME/purple-parlor/public`. This makes only `public/index.php` and versioned public assets web-accessible.

### File Manager ZIP method

1. In File Manager, open the account home directory (`/home/CPANEL_USERNAME`), not an unrelated site's folder. Do **not** create or enter a `purple-parlor` folder first; the verified ZIP already contains that one top-level folder.
2. Upload the private deployment ZIP to the account home, verify its SHA-256 checksum if cPanel provides a terminal or checksum feature, and extract it once. Extraction creates `/home/CPANEL_USERNAME/purple-parlor`.
3. Avoid `purple-parlor/purple-parlor/...`: after extraction, `app`, `bin`, `public`, and `resources` must be immediate children of `/home/CPANEL_USERNAME/purple-parlor`. If a second nested folder appears, stop and correct the layout before configuring the subdomain.
4. Confirm hidden files are visible and `.htaccess` files survived extraction.
5. Delete the uploaded ZIP from the account home after verification; retain the offline original.

### FTP/SFTP method

Upload the extracted tree in binary/automatic transfer mode. Do not upload `.env` from a developer machine; create a fresh production `.env`. If Composer is unavailable on the server, upload the package's production `vendor` directory. Compare file counts and spot-check hidden files after transfer.

### Fallback when the subdomain cannot point to `/public`

Best fallback: keep the private application above `public_html`, copy only the contents of `public/` into the subdomain document root, and edit its bootstrap path to the absolute private application location as documented in `GODADDY_DEPLOYMENT.md`.

Last-resort shared-root fallback: upload the whole package under the document root only if its root and directory `.htaccess` protections are supported and verified. Requests for `.env`, `app/`, `config/`, `database/`, `docs/`, `storage/`, `tests/`, `vendor/`, `bin/`, backups, and logs must return 403 or 404. Never continue launch if they can be downloaded.

## Part I — File permissions

Start with directories `755` and ordinary files `644`. Keep `.env` at `600` or the tightest readable setting supported by the hosting account. Make only `storage/cache`, `storage/logs`, `storage/sessions`, `storage/temporary`, and `storage/backups` writable by the PHP account. On typical cPanel hosting, `755` directories are already writable by their owner; use `775` only if the account's actual PHP ownership requires it. Never use `777` as a default.

Run the system check, exercise logging/session/cache writes, then tighten any widened permission. Confirm another hosting user cannot read private configuration. Uploaded user files, if enabled later, must be non-executable and validated by type and size.

## Part J — Configure production environment values

Create `.env` from `.env.example` in the private application root. Do not upload a local development `.env`.

- `APP_NAME`, `APP_BRAND`: public configurable names.
- `APP_ENV=production`, `APP_DEBUG=false`: hide diagnostics and stack traces.
- `APP_URL`: exact HTTPS subdomain without a trailing path.
- `APP_TIMEZONE`: display timezone; database timestamps remain UTC.
- `APP_KEY`: unique 32-byte base64 key created by `php bin/generate-key.php`; changing it invalidates protected data.
- `APP_MINIMUM_AGE`: default `18`; lowering it requires an explicit Adult Owner policy decision and audit.
- `APP_INDEXING_ENABLED`: keep false until the Adult Owner approves indexing.
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_CHARSET`: exact cPanel database values.
- `SESSION_COOKIE`: unique cookie name; `SESSION_SECURE=true`; `SESSION_SAMESITE=Lax` unless a documented provider flow requires a narrowly scoped exception.
- `MAIL_DRIVER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`: values supplied by the actual mailbox provider.
- `PAYMENTS_ENABLED=false`, `PAYMENT_MODE=sandbox`, `PAYMENT_PROVIDER=demo`, `ADULT_OWNER_CONFIRMED=false`, `LIVE_PAYMENT_ACTIVATION_LOCK=true`: required safe launch defaults.
- `PAYPAL_*`: Adult Owner's sandbox/live API and plan mappings. Secrets stay server-side.
- `SQUARE_*`: Adult Owner's sandbox/live application, token, location, signature, and API-version values. Access tokens stay server-side.
- `ADS_ENABLED=false`, `ADS_PROVIDER`, `ADSENSE_CLIENT_ID`: advertising remains off until provider approval and Adult Owner consent review.
- `LOG_LEVEL`: `warning` or `info` in production; private logs only.
- `BACKUP_PATH`, `BACKUP_RETENTION_DAYS`: private backup destination and retention policy.
- `CRON_SECRET`: generated secret for any explicitly enabled protected cron dispatch; CLI cron is preferred.
- `INSTALL_TOKEN`: temporary high-entropy installer token; remove or rotate it after the installer locks.

After upload, request `https://purpleparlor.lordfunion.dev/.env` in a private browser window. It must return 403 or 404 and show none of its content. Repeat for `/config/`, `/storage/logs/`, `/database/schema.sql`, `/docs/`, and `/vendor/`. Stop launch if any private file is visible.

## Part K — Run the installer

1. Generate an installation token locally: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` and place it in the private `.env`.
2. Visit `https://purpleparlor.lordfunion.dev/install` over HTTPS and paste the token into the protected form. Do not put it in a URL, chat, or screenshot.
3. Confirm the PHP, extension, file-permission, HTTPS, database, and writable-directory checks.
4. Run migrations and seeds from the installer.
5. With the Adult Owner present, create the first Adult Owner account. Use a unique password, immediately enroll 2FA, and print/store recovery codes offline.
6. Create Finn's Developer Administrator account with a different password; enroll its 2FA and store its recovery codes separately.
7. Confirm demo-payment mode, optionally test SMTP, and copy the displayed cron command using the actual home path.
8. Finish installation. Confirm `storage/installed.lock` exists, remove `INSTALL_TOKEN` from `.env`, and delete any temporary installer export.
9. Revisit `/install`; it must refuse access. Test login, logout, email verification, forgot-password non-enumerating response, reset link, session listing, and remote session revocation.

The installer cannot activate live payments.

With those exact safe defaults, the installed production site may expose the clearly labeled no-charge demo checkout. It contacts no merchant provider and cannot create a real charge. Demo checkout immediately fails closed if `PAYMENT_MODE=live`, another provider is selected, or either production activation lock is opened.

## Part L — HTTPS and SSL

1. In cPanel, open **SSL/TLS Status**, **AutoSSL**, or the certificate management feature. Confirm the exact subdomain has a valid certificate and intermediate chain.
2. If not, request/run AutoSSL or install the certificate GoDaddy provides. Wait until `https://` loads without a warning.
3. Set `APP_URL` to the HTTPS URL and `SESSION_SECURE=true`.
4. Enable the supplied HTTP-to-HTTPS rule after HTTPS works. If a proxy is involved, use only the trusted forwarded-protocol configuration to avoid loops.
5. Test both `http://` and `https://`; HTTP should redirect once to the same path on HTTPS.
6. Enable HSTS only after every subdomain dependency is HTTPS-ready. Start with a short max-age and do not add `includeSubDomains` or preload casually.
7. Confirm PayPal/Square return, cancel, and webhook URLs use the final HTTPS host.

## Part M — Configure email

1. The Adult Owner chooses `support@lordfunion.dev` or another role mailbox. In GoDaddy/cPanel/Microsoft 365, create it or confirm it exists.
2. Obtain the SMTP host, port, encryption, username, and app password directly from that provider. Do not guess and do not paste them into Codex.
3. Enter them in `.env`; set a truthful sender name and an authorized From address.
4. Run the protected SMTP test as Adult Owner. Check inbox, spam, `storage/logs/mail-*.log`, and the admin mail queue.
5. Add SPF, DKIM, and DMARC only using records issued by the chosen provider. Merge authorized senders into one SPF policy; never create a second SPF record. Do not replace Microsoft 365 MX records when only adding an application sender.
6. Test verification, reset, security alert, purchase, cancellation, refund, contact acknowledgment, and retry behavior. Authentication failures usually indicate wrong host/port/encryption, SMTP AUTH disabled, or an app password requirement.

## Part N — PayPal sandbox (Adult Owner)

- [ ] Sign in to or create an eligible PayPal account with truthful Adult Owner information.
- [ ] Open PayPal's current developer dashboard and create a sandbox REST application.
- [ ] Copy its sandbox client ID and secret directly into private `.env`; never into JavaScript or chat.
- [ ] Create a product and four plans: Cozy monthly/annual and Cozy Plus monthly/annual. Put their exact sandbox IDs in the four private `PAYPAL_*_PLAN_ID` values. These current environment values are authoritative at checkout; they are never accepted from a browser and do not require reseeding.
- [ ] Register `https://purpleparlor.lordfunion.dev/api/webhooks/paypal` and store the webhook ID.
- [ ] Select the currently documented subscription activation/change/cancellation/expiration, payment completion/denial/refund, and dispute lifecycle events used by the application.
- [ ] Run the Adult Owner-only connection test.
- [ ] Create a sandbox buyer and test monthly approval, annual approval, cancel, renewal simulation where PayPal supports it, failed payment, refund, dispute, and grace/expiration.
- [ ] Confirm entitlements change only after server confirmation, duplicate submissions reuse one server checkout intent, duplicate webhooks do not duplicate access, active-to-active renewal advances paid-through and entitlement dates, invalid signatures fail, and billing history contains no credentials.

Provider event names and verification requirements change; verify the current PayPal documentation before live activation.

## Part O — PayPal live mode (Adult Owner only)

The Adult Owner must complete account verification and truthful business description, link the approved payout account, review acceptable-use restrictions, taxes, prices, renewal/cancellation/refund terms, and create separate live application/product/plan/webhook resources. Approval is not automatic.

Keep sandbox values separately. Place live values only in private `.env`, never JavaScript. Test the live connection, then perform one low-value legitimate membership purchase with clear consent. Confirm receipt, webhook, entitlement, renewal date, cancellation, and refund. The Adult Owner must reauthenticate and deliberately satisfy all activation safeguards; no developer role can bypass them.

## Part P — Square sandbox (Adult Owner)

Follow the exact catalog, credential, webhook, `.env`, cPanel, and per-item checklist in [SQUARE_SETUP.md](SQUARE_SETUP.md). Square Free is sufficient; real transactions still have processing fees.

- [ ] Create/sign in to an eligible Square seller account using truthful Adult Owner information.
- [ ] In the developer dashboard, create an application and record the sandbox application ID, access token, and location ID privately.
- [ ] Register `https://purpleparlor.lordfunion.dev/api/webhooks/square` and record its sandbox signature key.
- [ ] Create the four fixed/static Square subscription plan variations and record their Sandbox variation IDs.
- [ ] Set sandbox values and API version in `.env`; run the Adult Owner-only connection test.
- [ ] Test all six fixed purchases plus all four monthly/annual memberships, including card storage consent, renewal, failed charge, paid-through cancellation, bonus idempotency, refund, dispute, duplicate request/event, and invalid signature.
- [ ] Confirm only the verified Square card form appears. Google Pay and Cash App Pay are intentionally disabled in this release; do not expose either by editing JavaScript or configuration. A later wallet launch requires a real-amount, method-specific implementation, merchant/domain approval, and its own sandbox regression tests.

## Part Q — Square live mode (Adult Owner only)

The Adult Owner completes Square identity verification, uses a truthful customer-facing business name, links the approved payout account, reviews prohibited-business policies, and configures production card credentials/webhook. Google Pay, Cash App Pay, and Apple Pay remain disabled even if the account dashboard offers them; provider approval and a separately reviewed implementation are both required before any wallet can be launched.

After all legal pages and prices are reviewed, create separate production subscription variations and a production webhook, then perform a low-value legitimate fixed-product purchase and refund. Confirm the statement/receipt merchant name, verified webhook, entitlement grant/revocation, and that card data stays with Square. Keep production and sandbox credentials, customer IDs, location IDs, webhook keys, and plan variation IDs separate.

## Part R — Subscriptions and entitlements

Local plans and prices use integer cents and map to external provider plan IDs. Monthly and annual provider plans are distinct. A local price edit does not alter existing provider subscriptions; normally create a new provider plan and decide how existing members are grandfathered.

Test `pending`, `active`, `past_due`, grace, suspended, canceled-at-period-end, immediately canceled, expired, refunded, and disputed states. Confirm renewal dates and paid-through dates. Failed renewals enter only the configured grace period; afterward premium entitlements are removed. Refunds and disputes revoke or adjust access according to the reviewed policy. Cancellation must stay easy. Reconciliation must repair missed events without replaying benefits.

## Part S — Advertisements

The Adult Owner owns the advertising account and applies with a truthful site description. Add the site for review, create only approved ad units, place the issued publisher IDs in private configuration, and publish `ads.txt` only as instructed. Approval and earnings are not guaranteed.

Keep placeholders during development and `ADS_ENABLED=false` until approval. Block inappropriate categories, especially real-money gambling, betting, crypto gambling, adult content, tobacco, illegal products, and get-rich-quick offers. Confirm consent/privacy requirements, frequency limits, and that members with `ads.disabled` see no ads. Never put ads over controls, near confirmations, inside unresolved rounds, or on auth/payment forms.

## Part T — Create the cPanel cron job

1. In cPanel open **Cron Jobs**.
2. Follow GoDaddy's current [cPanel cron-job guide](https://www.godaddy.com/help/create-cron-jobs-16086). Its usual example starts with `/usr/local/bin/php -q`, but do not assume that path or version: use `command -v php`, run `/ACTUAL/PHP/PATH --version`, then run `/ACTUAL/PHP/PATH bin/system-check.php` from the project root. The CLI PHP version and extensions can differ from the PHP selected for the website in cPanel; both must satisfy the release checks. Find the project path with `pwd` in its directory.
3. Run the dispatcher manually first:

   ```bash
   /ACTUAL/PHP/PATH -q /home/ACTUAL_CPANEL_USER/purple-parlor/bin/cron.php
   ```

4. Add one job every five minutes:

   ```cron
   */5 * * * * /ACTUAL/PHP/PATH -q /home/ACTUAL_CPANEL_USER/purple-parlor/bin/cron.php >/dev/null 2>&1
   ```

5. The dispatcher decides which individual tasks are due and uses a database lock to prevent overlap. Do not add duplicate per-task jobs.
6. Check the admin cron panel, `cron_runs` table, and private cron log. A stale run usually means a wrong PHP path, wrong project path, permissions, disabled CLI extension, or overlapping lock.

## Part U — Backups

Use both database and file backups. Export MySQL manually through phpMyAdmin before launch and major changes. Schedule `bin/backup-database.php` to a private directory; it creates timestamped files, checksums, audit records, and retention cleanup without printing the password. Download backups off the hosting account, encrypt them, and keep a practical rotation such as 7 daily, 4 weekly, and 6 monthly copies subject to storage and privacy requirements.

Back up application files except cache, temporary data, sessions, and replaceable logs; include private configuration only inside encrypted, access-controlled storage. Quarterly, restore a copy into a separate staging database and verify users/ledgers/settings. Never restore blindly over production. Take a new backup, document the incident, stop writes if required, and obtain Adult Owner reauthentication before any admin-assisted restoration.

## Part V — Legal and policy setup

Generated policies are editable templates, not legal advice. The Adult Owner—and a qualified professional where appropriate—must review and replace bracketed values for owner identity, contact email, prices, renewals, cancellation, refunds, privacy/cookies, virtual currency, advertising, age, taxes, assets, governing law, and effective dates.

Every public representation must say: no real-money wagering; no deposits; no cash prizes; no paid Cozy Coins or Parlor Stars; no cash-out, transfer, redemption, or exchange; membership and fixed cosmetics never affect odds or virtual payouts. Confirm asset licenses and the proprietary notice.

## Part W — Final launch checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, safe log level, and no public source maps.
- [ ] Final HTTPS works; one HTTP redirect; secure/HttpOnly/SameSite cookies; HSTS only after confirmation.
- [ ] Installer is locked, token removed, directory listing disabled, private files return 403/404.
- [ ] No default passwords; Adult Owner and Developer Administrator have unique passwords, 2FA, and separately stored recovery codes.
- [ ] Database and file backups complete; restoration procedure reviewed.
- [ ] Cron is fresh; email verification/reset/security alerts work.
- [ ] Payment mode is intentionally sandbox or deliberately approved live; webhooks/signatures verified.
- [ ] Purchase, failed purchase, renewal, cancellation, expiration, refund, dispute, out-of-order, and duplicate-webhook tests pass.
- [ ] Ledger reconciliation passes; balances never go negative; no card, CVV, bank password, provider password, or identity document is stored.
- [ ] All legal templates reviewed and published; exact no-cash-value language visible near every balance.
- [ ] All games launch and pass rules/payout/simulation checks; mobile, tablet, desktop, keyboard, screen reader, zoom, reduced motion, and touch tested.
- [ ] Ads remain disabled until approval; `robots.txt`, canonical metadata, and indexing toggle reviewed.
- [ ] Error/log/backup/database/docs directories are not public; friendly errors reveal no paths or SQL.
- [ ] Payment description is truthful and Adult Owner approves launch.

## Part X — Troubleshooting

Always reproduce safely, note the request/correlation ID, inspect private `storage/logs/application-*.log` plus the relevant security/payment/webhook/mail/cron log, make one reversible change, retest, and roll back if it worsens the issue. Never turn on public debug output in production.

| Problem | Likely cause and verification | Safe correction and rollback |
|---|---|---|
| 500 or blank page | Wrong PHP version/extension, bad path, unreadable `.env`, migration gap. Check cPanel error log and application log. | Restore last package/config, select PHP 8.2+, correct bootstrap path/permissions, run system check. Never show stack traces publicly. |
| Missing PHP extension | `php -m`/system check omits it. Web and CLI may use different INI files. | Enable it for the exact domain/CLI or ask GoDaddy; roll back features requiring it. |
| Database connection | Wrong prefixed name/user/host/port/password, user not assigned. Check cPanel values and a protected connection test. | Correct one private value, reassign only required privileges; restore prior `.env` if needed. |
| Database prefix confusion | cPanel displays `account_name` while `.env` uses only `name`. | Copy the exact displayed names. Do not rename production tables casually. |
| Permission error | PHP owner cannot write storage, or files are overly restricted. Check system check and ownership. | Adjust only affected storage dir; never 777; restore previous mode if broader. |
| `.htaccess` error | Unsupported directive or wrong RewriteBase. Apache error log identifies the line. | Comment only that directive, retest protections, restore file if exposure occurs. |
| Redirect loop | HTTPS enforced both by proxy/cPanel/app with untrusted forwarded headers. Inspect redirect chain. | Keep one trusted redirect layer and correct trusted proxy setting; revert latest redirect rule. |
| Mixed content | HTML/CSS references `http://`. Check browser security panel and generated URL config. | Use HTTPS/config-relative assets and purge cache; restore prior template if broken. |
| Session not persisting | Cookie domain/path/secure/SameSite mismatch or unwritable sessions. Inspect response cookies privately. | Correct final host, HTTPS, and session path; revoke test sessions after correction. |
| Email spam | Authentication or SPF/DKIM/DMARC/reputation/content issue. Inspect provider result and headers. | Use authorized sender and provider-issued DNS; never replace MX or add a second SPF; roll back DNS from saved values. |
| SMTP authentication | Wrong host/port/TLS, SMTP AUTH disabled, app password needed. Check mail log code. | Obtain exact settings from provider and retest; restore previous secret. |
| Cron not running | Wrong PHP/project path, CLI extension, permissions, lock, or suppressed error. | Run manually, check cron/application logs, correct actual paths; remove duplicate job. |
| PayPal authentication | Wrong environment/client pair or account access. Check redacted provider status. | Adult Owner replaces sandbox/live pair directly and retests; return to demo/sandbox. |
| PayPal webhook | Wrong webhook ID/URL, signature headers, HTTPS, or selected event. Check redacted webhook viewer. | Re-register exact HTTPS endpoint, replay from provider sandbox only; do not bypass verification. |
| Square authentication | Wrong environment/token/location/API version. Check Adult Owner connection test. | Correct private values from Square dashboard; return to sandbox. |
| Square signature | Wrong signature key or notification URL/body altered. Check raw-body hash metadata, not secrets. | Restore exact URL/key and unmodified raw body verification; never accept unsigned events. |
| Cash App/Google Pay absent | Expected in this release; wallet flows are intentionally disabled. | Keep them hidden. Treat future enablement as an Adult Owner-approved code, provider-approval, and regression-test change. |
| Subscription not activating | Unverified/missing/out-of-order event or plan mapping. Check provider object and events. | Run reconciliation after fixing mapping/signature; never grant from success URL. |
| Entitlement remains | Cancellation is end-of-period, event missed, cache stale, or grace active. | Verify paid-through/policy, reconcile, invalidate cache; audit any manual adjustment. |
| Duplicate payment/event | Idempotency key mismatch or unique constraint missing. Check provider ID/event ID. | Stop checkout, preserve evidence, refund only under Adult Owner policy, repair constraint in migration, replay in staging. |
| Game balance mismatch | Interrupted transaction, manual DB edit, or defective migration. Run read-only reconciliation. | Pause rounds, back up DB, repair through an audited compensating ledger entry—not direct balance edit. |
| Assets 404 | Nested extraction, wrong public root/base URL, missing hidden files. Inspect Network tab and paths. | Fix document root/extraction, restore release asset tree, clear cache. |
| Old service worker/cache | Browser/CDN cached old version. | This project does not cache account/payment APIs; bump asset version and clear site data. Never cache private endpoints. |
| Debug output | `APP_DEBUG=true`, web-server display_errors, or stale cache. | Disable both, clear config cache, verify friendly 500 page; remove any leaked log/secret and rotate it. |
| Disk space | Backups/logs/cache exhausted quota. Check cPanel Disk Usage. | Download/verify backups, apply retention, rotate logs, clear only disposable cache/temp. Never delete the only backup. |
| GoDaddy path difference | Examples do not match account home/PHP binary/document root. | Use cPanel File Manager and `pwd`/`command -v php`; update only Purple Parlor paths. |

If rollback touches payments, entitlements, virtual ledger, legal settings, or backups, require Adult Owner reauthentication, preserve the audit trail, and reconcile after restoration.
