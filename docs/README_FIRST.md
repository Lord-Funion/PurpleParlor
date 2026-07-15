# Read This First

Welcome to **The Purple Parlor**, a cozy play-money social casino and game arcade by Lord Funion.

**Confirmed deployment target:** GoDaddy **Web Hosting Deluxe with cPanel**. The release package and setup instructions are designed for cPanel File Manager, MySQL Database Wizard, the PHP selector, AutoSSL, and cPanel Cron Jobs; no VPS or command-line deployment service is required.

## The two rules that matter most

1. This project uses fictional balances only. Cozy Coins and Parlor Stars cannot be bought, transferred, redeemed, withdrawn, or exchanged for anything of value.
2. Because Finn is under 18, the parent or legal guardian acting as **Adult Owner** must own and configure every payment, advertising, merchant, payout, tax, and legal account. Finn should never request or handle the Adult Owner's passwords, identity documents, bank credentials, tax documents, or live payment secrets.

## Start safely

1. Keep the deployment ZIP private. Do not publish the source or upload it to a public coding or hosting service.
2. Read [SETUP_EVERYTHING.md](SETUP_EVERYTHING.md) from beginning to end.
3. Prepare the site locally in demo-payment mode.
4. In GoDaddy cPanel, create the subdomain, database, HTTPS certificate, support mailbox, and cron job using the guide.
5. Upload only the deployment package created for this project.
6. Run the protected installer and create unique Adult Owner and Developer Administrator accounts.
7. Leave advertisements and live payments disabled until the Adult Owner completes provider verification, reviews every legal template, enables administrator 2FA, and approves launch.

## Safe default state

```env
PAYMENTS_ENABLED=false
PAYMENT_MODE=sandbox
PAYMENT_PROVIDER=demo
ADULT_OWNER_CONFIRMED=false
LIVE_PAYMENT_ACTIVATION_LOCK=true
ADS_ENABLED=false
APP_DEBUG=false
```

These settings are intentional. The application will refuse live payment actions unless all four live-payment safeguards are explicitly satisfied by an authenticated Adult Owner, and changing the activation lock is audited.

## Who does what

Finn, as Developer Administrator, may configure games, themes, users, missions, content, tests, and system health. The Adult Owner alone supplies legal merchant details directly to PayPal, Square, Google, the advertising provider, the bank, or the tax authority; configures live credentials and payout destinations; handles refunds, disputes, chargebacks, and taxes; accepts provider agreements; and authorizes production monetization.

Never paste real secrets or identity information into source code, JavaScript, documentation, Codex, chat, or a public configuration file. Store runtime secrets only in the private production `.env` file or the provider's own dashboard.

## Next document

Open [SETUP_EVERYTHING.md](SETUP_EVERYTHING.md).
