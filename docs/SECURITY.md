# Security

## Launch invariants

- Production debug and directory listings are off; private paths are outside the web root or denied.
- HTTPS and secure session cookies are active before live callbacks.
- All state-changing browser requests require CSRF; webhooks use provider signatures instead.
- PDO prepared statements, contextual escaping, allow-listed routes/redirects/uploads, CSP nonces, and strict headers are enforced.
- Passwords use Argon2id when available and a secure `password_hash` fallback. Reset/verification tokens are random and stored hashed.
- Login/reset/contact/payment/game/admin limits are server-side. Sessions regenerate on privilege change; sensitive actions require recent reauthentication.
- Administrators use TOTP 2FA and one-time hashed recovery codes. Granular permissions separate Adult Owner and Developer Administrator authority.
- Ledger, payment, webhook, reward, and round writes are transactional and idempotent.
- Logs redact passwords, cookies, access/client secrets, tokens, card/bank data, and identity documents; IPs are keyed hashes with retention limits.

Report a suspected incident by disabling affected features, preserving private logs and backups, rotating exposed secrets at the provider, revoking sessions, reconciling ledgers/entitlements, and documenting audited corrective entries. Do not erase evidence or silently edit balances.

