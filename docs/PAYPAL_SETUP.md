# PayPal Setup

PayPal is the preferred recurring-membership provider. The Adult Owner creates and verifies the account, developer application, products, plans, payout account, webhook, tax information, and live credentials using truthful information directly in PayPal.

Start in sandbox. Map separate monthly and annual IDs for Cozy Club and Cozy Club Plus. Register `/api/webhooks/paypal` on the final HTTPS host and select the lifecycle events currently required by the application. Webhook verification uses the exact raw body and provider headers; event IDs are unique and processing is transactional/idempotent.

The four `PAYPAL_*_PLAN_ID` values in the private `.env` are the authoritative mapping for the currently selected `PAYPAL_ENVIRONMENT`. They are read from trusted server configuration at checkout, not copied from browser input and not frozen at database-seed time. When switching between sandbox and live, replace the environment, credentials, webhook ID, and all four plan IDs as one reviewed change; do not reuse sandbox IDs in live. A seed rerun is not required, but the Adult Owner must run the read-only connection check and all sandbox checkout tests after any mapping change.

The return URL never grants membership. Test approval, activation, active-to-active renewal with a later paid-through date, failure, grace, suspension, cancellation, expiration, refund, dispute, duplicate, invalid signature, reconciliation, and out-of-order delivery. Live activation requires Adult Owner reauthentication plus all four safety gates. See Parts N and O of [SETUP_EVERYTHING.md](SETUP_EVERYTHING.md).

Before configuring an account, compare the integration with PayPal's current official [Subscriptions webhooks](https://developer.paypal.com/docs/subscriptions/reference/webhooks/), [webhook verification guide](https://developer.paypal.com/api/rest/webhooks/rest/), and [OAuth authentication guide](https://developer.paypal.com/api/rest/authentication). Provider documentation is authoritative when event availability changes.
