# Email Setup

Use authenticated SMTP and a role mailbox such as `support@lordfunion.dev`. The Adult Owner obtains the actual host, port, encryption, username, and app password from GoDaddy Professional Email, Microsoft 365, or the selected provider and enters them only in private `.env`.

The application queues both HTML and plain-text messages in MySQL. Cron retries temporary failures to a bounded attempt count; user requests do not fail merely because SMTP is down. Test verification, password reset, security alerts, subscription events, refunds, contact acknowledgments, and support updates.

Publish only provider-issued SPF/DKIM/DMARC records. Never create two SPF records and never overwrite existing Microsoft 365 MX records blindly. See Part M of [SETUP_EVERYTHING.md](SETUP_EVERYTHING.md).

