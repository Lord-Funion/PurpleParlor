# The Purple Parlor - Build Status

Last updated: 2026-07-14

## Release status

The production-quality PHP/MySQL project is complete and ready to be packaged for the confirmed target: **GoDaddy Web Hosting Deluxe with cPanel**, Apache, PHP 8.2+, and MySQL/MariaDB. The site requires no persistent Node.js process, Docker, Redis, root access, or separate game server. An earlier demo build is present at `purpleparlor.lordfunion.dev` and remains in its protected installer; the visual update documented here has not yet been uploaded.

The operating model is play-money entertainment only. Cozy Coins and Parlor Stars have no cash value, are not sold separately, and cannot be transferred, redeemed, withdrawn, or exchanged for anything of value. Payments are limited to disclosed memberships and fixed non-wager cosmetics or convenience entitlements. Eligible memberships include a fixed daily Cozy Coin grant but never change odds, paytables, payout multipliers, or return-to-player values.

## Completed systems

- Responsive branded landing page, lobby, categories, search, favorites, recent games, profiles, statistics, settings, help, business inquiries, legal pages, maintenance, and branded 400/401/403/404/419/429/500 responses.
- Server-authoritative game API with cryptographic server RNG, precommitted seed queue, signed outcomes, replay protection, hidden state, idempotency, and transactional fictional-balance changes.
- Polished responsive SVG/CSS game tables for all 40 games, including an exact server-path Plinko drop, animated reels/wheels/cards/dice/races/boards, safe mid-round interactions, skip controls, and application-level reduced-motion final states. Cosmetic rendering never generates a second outcome.
- Append-only Cozy Coin and Parlor Star ledger with database trigger guards, row locking, reconciliation, daily rewards, missions, achievements, leaderboards, cooldown reset, and audited administrator adjustments.
- Guest demonstration mode with local fictional balance and idempotent server conversion that preserves history/statistics but never merges balances.
- Registration, versioned legal acceptance, email verification, login/logout, password reset, remember-me, throttling/lockout, session listing/revocation, administrator TOTP, recovery codes, privileged reauthentication, and owner/developer role separation.
- Granular RBAC for Adult Owner, Developer Administrator, support, content, moderation, member, and guest duties. Live merchant secrets and complete payment audit details remain Adult Owner-only.
- PayPal subscription and Square one-time-payment adapters with signed webhook verification, server-owned checkout intents, stable idempotency keys, lifecycle reconciliation, cancellation, renewal, failure, refund, dispute, out-of-order/duplicate event handling, and entitlement activation/removal.
- Provider refund execution with reviewed requests, typed confirmation, Adult Owner reauthentication and 2FA, cumulative refund bounds, ambiguous-result retry safety, and permanent dual audit trails.
- Memberships, fixed cosmetics, themes, profiles, badges, layout/statistics entitlements, purchase history, billing support, and subscription management. Eligible memberships add one fixed, idempotent daily Cozy Coin grant while never altering game odds, paytables, or payout multipliers.
- Adult Owner payment activation gate requiring all four independent live safeguards, plus a persistent audited lock. Demo provider actions are explicitly no-charge.
- Privacy-conscious first-party analytics with opt-out; canonical essential/analytics consent; fixed safe advertising placements; ads disabled by default and suppressed without consent or for ad-free members.
- Versioned database-backed legal and email content: immutable drafts, private previews, allow-listed plain-text placeholders, escaped output, Adult Owner legal approval, separate publication, rollback clones, source fallback, and complete audit snapshots.
- Account export/deletion, verified email changes, profile privacy, notification/play controls, synced authenticated appearance/audio preferences, and guest device preferences.
- Admin controls for users, roles/permissions, game availability/wager limits, virtual rewards, catalog/entitlements, missions/content, ads/sponsors, support workflows, payment/refund history, settings, audit logs, and system health.
- Six installed themes (four free and two entitlement-gated), dark/light/system appearance, audio controls, reduced motion, particles, compact/large-text/high-contrast modes, colorblind card suits, fullscreen play, visible focus, semantic controls, and keyboard/touch navigation.
- SMTP queue, cron dispatcher, overlapping-run lock, idempotent membership daily bonuses, daily/weekly mission rotation, subscription/webhook retries, retention cleanup, database backups/checksums, and bounded redacted persisted health snapshots.
- cPanel installer, migrations, safe seeds, generated MySQL schema, Apache/private-file protections, production-safe error handling, deployment packager, and complete Parts A-X setup/troubleshooting documentation.

## Completed games - exactly 40

1. Plinko
2. Classic 3-Reel Slots
3. Five-Reel Video Slots
4. Blackjack
5. European Roulette
6. American Roulette
7. Baccarat
8. Craps
9. Video Poker
10. Texas Hold'em vs Bots
11. Three Card Poker
12. Caribbean Stud
13. Casino War
14. Red Dog
15. Let It Ride
16. Pai Gow Poker
17. Hi-Lo
18. Five Card Draw vs Bots
19. Teen Patti Practice
20. Parlor Switch (original blackjack-switch style)
21. Sic Bo
22. Keno
23. Bingo
24. Mines
25. Over/Under Dice
26. Coin Flip
27. Prize Wheel
28. Number Draw
29. Pachinko
30. Horse Racing Simulation
31. Free Scratch Cards
32. Memory Match
33. Klondike Solitaire
34. Pyramid Solitaire
35. TriPeaks Solitaire
36. FreeCell
37. Higher-or-Lower Streak
38. Gem Drop
39. Lucky Cups
40. Treasure Tiles

## Verification completed

- Application suite: **93 passed, 0 failed**. One test method runs the separate **28 passed, 0 failed** focused game-rule suite.
- HTTP/security acceptance covers the real local request kernel for CSRF, authentication/RBAC, encoded path attacks, production error redaction, XSS escaping, forged Square webhooks, preference/wager-confirmation contracts, and Apache denial rules.
- PHP syntax: **322 current project PHP files checked, 0 failures** (Composer vendor and deployment output excluded from source lint).
- JavaScript: **7 production ES modules imported, 0 failures or console errors**.
- Routes: **163 routes, 163 unique names, 11 view references**, with handlers, CSRF policy, and dynamic/static ordering checked.
- Fresh SQLite release database: migrations **001-007** applied; seed produced 40 games, 7 roles, 28 permissions, 3 plans, 6 products, 3 achievements, 2 initial missions, 1 leaderboard, 2 disabled ad slots, and **0 users/development accounts**.
- Generated MySQL schema parity check passed. Local health verified 28 critical tables/131 selected columns, all 8 append-only triggers, all 4 Apache protection files, private config placement, writable storage, and the non-live payment lock.
- Composer optimized autoload generated for 154 classes; `composer.json` strict validation passed; locked dependency audit found no known security advisories.
- Standard isolated math validation was run at 100,000 rounds per wagered game with configured threshold checks. The current 1,000-round-per-game smoke run launched all 40 games with **0 warnings/errors**. These simulations do not prove perfect randomness.
- Browser smoke checks covered the age gate and policy page plus 16 representative game scenes spanning every visual archetype. Every scene reached its server-selected final state without console errors or document overflow. Dedicated checks passed for reduced-motion wheel alignment, scratch reveal, Plinko landing, scatter highlights, Baccarat labels, Number Draw bands, and seven-module import. Keyboard tab controls include arrows, Home, and End.
- Deployment dry-run confirmed one `purple-parlor/` archive root, bundled Composer vendor files, `.env.example`, and all Apache protections while excluding `.env`, installer lock, temporary databases, sessions, logs, backups, `.git`, and prior deployment output.

## Safe packaged defaults

The release contains no `.env` and no provider credential. `.env.example` intentionally starts with:

```env
PAYMENTS_ENABLED=false
PAYMENT_MODE=sandbox
PAYMENT_PROVIDER=demo
ADULT_OWNER_CONFIRMED=false
LIVE_PAYMENT_ACTIVATION_LOCK=true
PAYPAL_ENABLED=false
SQUARE_ENABLED=false
ADS_ENABLED=false
```

Google Pay and Cash App Pay remain unavailable until the Adult Owner obtains provider approval and a separate implementation/test cycle is completed. They are not silently presented as working payment methods.

## External validation not performed locally

The following require the real accounts and are launch blockers until completed by the appropriate owner. Local tests use SQLite/local cryptography and test doubles; they do not claim to exercise the real GoDaddy Apache/MySQL instance or provider network.

- Actual GoDaddy cPanel document root, `AllowOverride`, account web/CLI PHP versions and extensions, MySQL `TRIGGER` privilege, file ownership/modes, AutoSSL, DNS, cron execution, disk quota, and backup restore.
- GoDaddy Web Hosting support must disable the host-injected `_trfd`/`tccl.min.js` traffic analytics tags; the application CSP intentionally blocks them and will not be weakened to authorize third-party tracking.
- Authenticated SMTP delivery, mailbox ownership, spam placement, SPF, DKIM, and DMARC.
- Real PayPal and Square sandbox/live account eligibility, merchant verification, products/plans, webhook delivery, refunds, cancellation, disputes, statements, and low-value authorized checkout tests.
- Advertising-provider approval, publisher identifiers, `ads.txt`, category controls, consent requirements, and payout configuration.
- Adult Owner review of merchant identity, prices, taxes, privacy/cookie duties, refund/cancellation language, all legal templates, asset licenses, and any qualified professional advice.

## Remaining manual account and production steps

1. The Adult Owner gives Finn delegated GoDaddy/cPanel access where available; neither person shares the owner's password or merchant secrets.
2. In GoDaddy cPanel, create/verify the subdomain and secure document root, select PHP 8.2+, create the dedicated MySQL database/user with database-scoped required privileges including `TRIGGER`, and enable HTTPS.
3. Ask GoDaddy Web Hosting support to disable traffic/analytics script injection for the account, then verify the public HTML contains neither `traffic-assets` nor `tccl.min.js`.
4. Upload the verified private ZIP to the cPanel account home, extract it exactly once, and confirm `app`, `bin`, `public`, and `resources` are immediate children of `/home/CPANEL_USERNAME/purple-parlor`.
5. Use the protected installer to write the private production `.env`, migrate/seed, and create unique Adult Owner and Developer Administrator accounts; save recovery codes and lock the installer.
6. Configure SMTP, the five-minute cPanel cron command using the verified CLI PHP binary, off-host encrypted backups, and a tested staging restore.
7. Adult Owner configures PayPal/Square sandbox credentials and webhooks directly, enables administrator 2FA, completes the documented lifecycle/refund/duplicate tests, reviews/publishes legal content, and obtains any advertising approval.
8. Keep live payments, ads, and indexing disabled until every launch checklist item passes and the Adult Owner explicitly approves production activation.

Begin with [README_FIRST.md](README_FIRST.md), then follow [SETUP_EVERYTHING.md](SETUP_EVERYTHING.md) in order.
