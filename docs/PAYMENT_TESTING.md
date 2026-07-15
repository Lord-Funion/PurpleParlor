# Payment Testing

Run payment tests in this order: demo provider, PayPal sandbox, Square sandbox, then—only after Adult Owner approval—a low-value legitimate live test.

For each provider record the environment, test account, local request ID, idempotency key, provider object/event ID, expected state, observed subscription/payment/entitlement state, audit record, and cleanup/refund result. Redact every token.

For every subscription plan, also verify the configured daily Cozy Coin amount,
that two cron runs on the same UTC date create exactly one
`membership.daily_bonus` ledger entry, that the next UTC date creates one new
entry, and that expired/refunded/disputed access stops future grants. An
overlapping plan-change test must award only the highest eligible amount once.

Checkout idempotency keys are created and retained by the server in `checkout_intents`; browsers do not create them. Repeating the same user/item/period/provider/environment checkout reuses the stored key through pending or ambiguous outcomes, and completed outcomes replay the stored provider result during the 24-hour safety window. Sandbox and live intents can never share a key. An unresolved intent is never auto-rotated: after the provider retention window it is blocked for Adult Owner/provider reconciliation instead of risking a second charge. Test this by submitting twice and confirming there is one provider object, one local payment attempt, and one entitlement. Never work around an ambiguous result by deleting an intent or inventing a new key; reconcile with the provider first.

Required scenarios include disabled mode, Adult Owner lock, success, failure, pending, renewal, failed renewal, grace, cancel-now, cancel-at-period-end, expiration, refund, dispute, duplicate request, duplicate webhook, invalid signature, unknown event, out-of-order event, missed-event reconciliation, cache invalidation, and entitlement removal. Confirm no return URL can grant access and no sensitive payment data is stored.

Production may run the explicitly selected `demo` provider only with `PAYMENT_MODE` not `live` and both live activation locks still on. This is a no-charge simulator: confirm no PayPal/Square request occurs and no charged `payments` record is created. For reconciliation, specifically test an `active` subscription renewing to another `active` period and confirm both `current_period_end` and every subscription entitlement end date move forward.
