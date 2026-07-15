# GoDaddy Web Hosting Deluxe Deployment

This project is specifically packaged for Finn's GoDaddy Web Hosting Deluxe cPanel plan.

## Preferred layout

Upload the ZIP to `/home/CPANEL_USERNAME` and extract it there once; its single top-level folder becomes `/home/CPANEL_USERNAME/purple-parlor`. Do not create or enter a `purple-parlor` folder before extraction, or cPanel will produce a broken double-nested path. Point `purpleparlor.lordfunion.dev` to `/home/CPANEL_USERNAME/purple-parlor/public`. Set the GoDaddy hosting account to PHP 8.2+ through **Settings → Server Settings → PHP Version**, or use a per-domain selector only if this account exposes one. When the setting is account-wide, test every other site on the account before continuing. Create a dedicated MySQL database/user, enable AutoSSL, and schedule the single `bin/cron.php` dispatcher every five minutes.

## Split-root fallback

If cPanel forces a document root inside `public_html`, keep the application at `/home/CPANEL_USERNAME/purple-parlor-private` and copy only `public/` contents to the assigned web root. In the copied `index.php`, replace only `define('BASE_PATH', dirname(__DIR__));` with the exact private absolute path:

```php
define('BASE_PATH', '/home/ACTUAL_CPANEL_USERNAME/purple-parlor-private');
```

Leave the following `$application = require BASE_PATH . '/app/bootstrap.php';` line and request-handling line unchanged. Do not guess the username or home path. Confirm with File Manager or `pwd`. Preserve the public `.htaccess`.

## Last-resort same-root layout

Use only if GoDaddy cannot place private files above the document root. Keep the supplied root and directory denial rules. Verify `.env`, `app`, `bin`, `config`, `database`, `docs`, `resources`, `storage`, `tests`, `vendor`, logs, backups, and SQL return 403/404. If any private item downloads, stop and contact GoDaddy support.

## GoDaddy-injected analytics and the CSP

Some GoDaddy shared-hosting responses are modified after PHP finishes: the host appends an inline `_trfq`/`_trfd` bootstrap and `https://img1.wsimg.com/traffic-assets/js/tccl.min.js` after the application's closing HTML. Purple Parlor's nonce-based Content Security Policy intentionally blocks both tags. They are not application dependencies, and the CSP must not be weakened with `unsafe-inline`, a copied hash, or an `img1.wsimg.com` script allowlist merely to silence the console.

The injected comment itself directs customers to hosting support to opt out. Ask GoDaddy Web Hosting support to **disable GoDaddy traffic/analytics script injection for the hosting account**, provide the affected hostname, and include `tccl.min.js` plus the `_trfd` snippet so the request is unambiguous. After support confirms the change, fetch the public page and verify that its response body contains neither `traffic-assets` nor `tccl.min.js`; the existing CSP should remain unchanged.

Detailed click-by-click instructions, permissions, SSL, DNS, upload, cron, and rollback checks are in [SETUP_EVERYTHING.md](SETUP_EVERYTHING.md).

GoDaddy's current cPanel documentation confirms PHP, MySQL, cURL, `mod_rewrite`, cron jobs, raw access logs, and server error logs are supported on Web Hosting (cPanel). Use the account's actual feature availability as authoritative: [changing the hosting account's PHP version](https://www.godaddy.com/help/view-or-change-the-php-version-for-my-web-hosting-cpanel-16090), [supported hosting components](https://www.godaddy.com/help/which-components-does-my-hosting-support-5614), [finding a subdomain document root](https://www.godaddy.com/help/what-is-my-websites-root-directory-in-my-web-hosting-cpanel-account-16187), and [creating a MySQL database](https://www.godaddy.com/help/create-a-mysql-database-in-my-web-hosting-cpanel-16016).
