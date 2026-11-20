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


- SmartSMS requires the documented literal suffix لغو11 at the end of every text. The adapter and outbox reject messages without it; the text is not silently rewritten.

## Encrypted transactional outbox and workers

- Migration 013 creates pos_sms_outbox and pos_sms_delivery_attempts.
- Recipient mobile and message text are stored only as AES-256-GCM ciphertext with independent nonce/tag pairs; HMAC integrity hashes are verified before send.
- SmsOutboxService queues transactional messages idempotently and requires pos.sell. If a customer is linked, the recipient must match that active customer's stored normalized mobile.
- Marketing messages are not accepted by this service. The SmartSMS literal suffix لغو11 is required and is never appended automatically.
- SmsOutboxWorker claims up to 100 rows with SKIP LOCKED, uses SendMultiple, stores provider IDs and attempts, and defers duplicate recipients to a later pass.
- HTTP 429 is retried with bounded exponential backoff (maximum 3 attempts); transport/5xx unknown outcomes are not retried. Stale processing claims older than 10 minutes become unknown.
- SmsOutboxDeliveryPoller checks SmartSMS delivery statuses and stores each report attempt. Unknown provider statuses do not regress the current state.
- CLI:
  - php scripts/run-v3-sms-outbox-worker.php
  - php scripts/poll-v3-sms-deliveries.php
- Server environment must include database settings, POS_V3_APP_KEY and SmartSMS variables documented above. No credentials are stored in frontend or source.
- DB integration on MySQL 8.4 and MariaDB 10.6 passed (run 44811930818); backend tests on PHP 8.2/8.3/8.4 passed (run 44812010216). Tests use fake providers; no real SMS was sent.
- The outbox is not yet triggered automatically by order events and has no management UI. SharedNumber template sending is not wired into the queue.
- Marketing consent/opt-out registry, provider settings UI, scheduled sending and SharedNumber delivery reconciliation remain pending.


## Transactional outbox API entry point

- Added authenticated API endpoint: POST /api/v1/communications/sms/transactional.
- The endpoint uses the existing SmsOutboxService; it requires an authenticated business context, pos.sell permission, CSRF/origin protection and an Idempotency-Key.
- JSON payload: recipient, message, optional customer_public_id. The service normalizes Iranian mobile numbers, checks the mandatory SmartSMS لغو11 suffix, encrypts recipient/message, enforces customer-phone match when linked, and stores an idempotent transactional outbox record.
- The endpoint only queues the message. It does not contact National Payamak; the separate CLI worker remains responsible for provider delivery. No automatic order-event trigger or real SMS send is enabled.
- UI, queue/report browsing and provider configuration remain pending. No tests were run for this API wiring in this batch; include it in the consolidated end-of-phase validation.
