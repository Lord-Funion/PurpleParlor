# Troubleshooting

Use the full diagnostic table in Part X of [SETUP_EVERYTHING.md](SETUP_EVERYTHING.md). Start with the branded request ID, cPanel Apache/PHP error log, and the private application/security/payment/webhook/mail/cron log. Keep `APP_DEBUG=false` in production.

Before a change, save the current private configuration and database backup. Change one thing, verify, and restore it if the symptom worsens. Never weaken webhook verification, CSRF, authorization, private-path rules, or ledger constraints to make an error disappear.

