# SMS compatibility components

This folder currently contains a V3-namespaced adaptation of the reusable inbound parsing logic found in V2:

- Explicit Jalali birthday command parsing.
- National Payamak REST `GetMessages` response parsing.
- Persian/Arabic digit normalization.
- Iranian mobile number normalization.

Source references remain preserved under `legacy/v2-reference/php-backend/private/` and their parser checks under `legacy/v2-reference/scripts/`.

## Important scope boundary

These files are parsing/compatibility utilities, not a complete SMS integration. They do not send messages, poll the provider, persist inbound messages, manage delivery receipts, implement queues/retries, handle credentials, or manage marketing consent. No V2 credentials or hard-coded secrets are copied.

Provider examples and API contracts supplied separately by the project owner (National Payamak REST, SmartSMS and shared service-number documents) must be implemented through a server-side provider adapter. Keep transactional and marketing messages separate; marketing sends require recorded consent and an opt-out path.

Run the focused parser check with:

```sh
php v3/backend/tests/Communications/Sms/LegacySmsParserTest.php
```

## National Payamak SmartSMS REST adapter

- Implemented provider adapter: NationalPayamakSmartSmsProvider.
- REST endpoints:
  - POST https://rest.payamak-panel.com/api/SmartSMS/Send for identical text to up to 100 recipients.
  - POST https://rest.payamak-panel.com/api/SmartSMS/SendMultiple for personalized text arrays (up to 100, equal recipient/text ordering).
  - POST https://rest.payamak-panel.com/api/SmartSMS/GetDeliveries for batch delivery lookup by stored provider IDs.
- Credentials are injected only from server environment:
  - POS_V3_SMS_NATIONAL_PAYAMAK_USERNAME
  - POS_V3_SMS_NATIONAL_PAYAMAK_API_KEY
  - POS_V3_SMS_NATIONAL_PAYAMAK_SENDER
  - POS_V3_SMS_NATIONAL_PAYAMAK_BACKUP_SENDER_ONE (optional)
  - POS_V3_SMS_NATIONAL_PAYAMAK_BACKUP_SENDER_TWO (optional)
  - POS_V3_SMS_HTTP_TIMEOUT (optional; 5–60 seconds, default 20)
- API key is sent in the provider's documented password field but kept private in the config object. It is never exposed to frontend code.
- HTTPS, JSON, TLS certificate verification, no redirects, bounded timeouts and max-100 batches are enforced.
- The adapter returns provider IDs for persistence and maps documented delivery status codes.
- Network/5xx outcomes are marked deliveryUnknown and must not be blindly retried. A caller must persist returned provider IDs and reconcile delivery status.
- Unit tests use a fake HTTP transport. No real credentials or provider send have been used.
- Queue/outbox persistence, consent/opt-out enforcement, scheduled worker, retry policy, delivery polling and settings UI are still pending. Do not wire a user-facing send action until those controls are implemented.


## National Payamak Shared Service Number template adapter

- Implemented provider adapter: NationalPayamakSharedNumberProvider.
- REST endpoint: POST https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber.
- One recipient per request; bodyId must identify a template already submitted and approved in the Payamak panel.
- The text field is built from ordered template variables joined with semicolons; the provider replaces the approved template placeholders.
- Success requires HTTP 200, RetStatus=1, StrRetStatus=OK and a numeric Value with more than 15 digits; persist that provider ID for later delivery reconciliation.
- API credentials are read from the server environment; password carries the API key as documented.
- The scheduled BaseServiceNumberSchedule method is not implemented. Its allowed send window (07:00–22:00) and scheduleDate semantics need timezone-aware queue support first.
- No real provider credentials or sends have been used; tests use a fake HTTP transport.
- Delivery report polling for shared-service IDs, queue persistence, approved-template registry, template-variable validation against provider metadata and operational consent policy remain pending.

