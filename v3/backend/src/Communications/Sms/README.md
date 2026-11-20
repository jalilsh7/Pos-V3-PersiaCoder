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

- No public free-text enqueue endpoint is registered. Outbox enqueue is an internal application-service operation and must be called only by approved domain-event/template flows. Do not expose arbitrary message text under a generic transactional endpoint.

## Read-only queue and delivery history API

- `GET /api/v1/communications/sms/outbox` is branch-scoped and requires `communications.read`.
- Supports `limit` (1–100), an optional exact `status` filter, and keyset `cursor` pagination.
- Returns public message ID, type, provider code, status, attempt count, safe failure code, timestamps and safe delivery-attempt metadata only.
- Recipient numbers, message bodies, ciphertext, customer identifiers, provider message IDs and raw provider payloads are never returned.
- `unknown` is informational only. No retry/resend action is exposed.
- Permission is granted to the manager role; owner wildcard access follows existing RBAC.


## Source archive compatibility note — REST transport formats

- The supplied `Rest-Sample.zip` demonstrates `application/x-www-form-urlencoded` for legacy `SendSMS/SendSMS`, `GetDeliveries2`, `GetMessages`, and `BaseServiceNumber` requests.
- The supplied SmartSMS REST PDF explicitly specifies `application/json` for `SmartSMS/SendMultiple`; its REST examples also define the SmartSMS `Send` and `GetDeliveries` contracts and response IDs/statuses.
- Shared-number `BaseServiceNumber` therefore uses a dedicated HTTPS form-encoded transport, matching the supplied REST sample rather than sending JSON. TLS verification, no redirects and bounded timeouts remain enabled.
- The supplied `SamplePHP.rar` contains legacy SOAP examples (`SendSimpleSMS2`, `SendSms`, `SendByBaseNumber2`, schedules, inbox and contacts). These examples use HTTP WSDL URLs and placeholder/demo credentials. They are treated as compatibility references only; V3 does not copy insecure HTTP endpoints, SOAP credentials or legacy PECL/SoapClient runtime dependencies.
- No provider call or real SMS was made during this change.


## Shared Number approved-template registry

- `SharedNumberTemplateRegistry` is an installation-level allow-list for templates already approved in the provider account.
- Configure `POS_V3_SMS_SHARED_TEMPLATES` as JSON, for example:
  `{"order_ready":{"body_id":123456,"variable_count":2}}`
- Replace the example ID with the actual account-specific approved body ID. The example is illustrative only and is not a valid project template ID.
- Template keys are internal stable identifiers. Each definition enforces a positive provider body ID and an exact variable count (0–20). Unknown keys and mismatched variable counts fail closed.
- This registry does not send SMS and is not yet connected to the outbox worker. Do not expose arbitrary body IDs or template variables through a public endpoint.
- Test: `php v3/backend/tests/Communications/Sms/SharedNumberTemplateRegistryTest.php` (not run in this batch).


## Business-managed service templates (product decision SMS-TEMPLATE-2026-002)
- Each business configures the service message text and the actual National Payamak-issued template/body ID separately for each event trigger in the POS.
- Store configuration server-side with business/branch scope. A global environment registry is not the product source of truth; the current registry is validation scaffolding only.
- Each trigger has enabled state, editable text, provider template ID, ordered event-variable mapping, preview and controlled test-send support.
- Do not hard-code sample IDs or assume IDs are shared across customer accounts. The business must create/approve the corresponding template in its own National Payamak account.
- Marketing remains separately gated by verifiable consent and opt-out.


## Service template variable mapping — correction
- The service-message body text is authored and approved only in the National Payamak account. POS must not store or submit the full service-template text.
- POS stores the account-specific provider template/body ID per business trigger, plus the ordered mapping from provider template variables to approved POS event data.
- The UI provides a variable guide (variable name, meaning, source field, example value, ordinal position) and previews the outgoing variable values only. It does not preview or edit the final provider-side message body.
- A controlled test send submits the configured template ID and ordered variable values. Never invent template IDs or send arbitrary user text through the template endpoint.


## POS-managed National Payamak variable mapping
- Full service message text is authored and approved only in National Payamak; POS does not store the body.
- Migration 016 adds branch-scoped body IDs, allow-listed ordered variable keys, enabled/version fields and audit history.
- GET/PUT /api/v1/communications/sms/service-templates requires communications.manage and authenticated business/branch context; PUT also requires CSRF and same-origin.
- UI shows variable source/example and outgoing values only. Order lifecycle enqueue/worker integration remains pending.

## Shared Number service-template event outbox — implementation update (2026-11-21)

- Migration `database/v3/migrations/017_sms-service-template-outbox.sql` adds a dedicated queue and attempt ledger for approved National Payamak shared-service templates.
- `SmsServiceTemplateOutbox` reads the enabled business/branch trigger configuration and queues an immutable snapshot of the provider body ID, encrypted recipient and encrypted ordered variable values. It never stores the provider-side message body.
- Order lifecycle integration is in `Orders/OrderService.php`: `order.created`; `order.status_changed` for ordinary status transitions; and dedicated `order.ready` / `order.completed` events. Missing/invalid recipient or incomplete variable values fail closed. SMS enqueue errors are logged and must not fail the order transaction.
- `SmsServiceTemplateWorker` sends one recipient per request through `NationalPayamakSharedNumberProvider` and the documented HTTPS form-urlencoded `BaseServiceNumber` endpoint. Provider acceptance IDs are persisted. Explicit HTTP 429 may retry with a bounded attempt count; unknown outcomes are terminal for automatic sending and are not blindly retried. Stale processing rows become `unknown` after 10 minutes.
- CLI entry point: `scripts/run-v3-sms-service-template-worker.php`. Configure `POS_V3_SMS_NATIONAL_PAYAMAK_USERNAME`, `POS_V3_SMS_NATIONAL_PAYAMAK_API_KEY` and optional `POS_V3_SMS_HTTP_TIMEOUT` on the server only.
- Read-only branch-scoped report: `GET /api/v1/communications/sms/service-outbox` (`communications.read`). The SMS report UI shows trigger, body ID, status and attempt count only; recipient, variable values, ciphertext and provider IDs remain private.
- Shared Number delivery polling is still pending. `Rest-Sample.zip` includes a legacy `GetDeliveries2` form request, but the reviewed sources do not establish a complete response/status mapping for this adapter. Do not invent status meanings or claim delivery until that contract is confirmed.
- Migration 017 has not been applied; tests/build have not been run in this implementation batch; no real provider request or SMS send has been made.

## Shared Number delivery reconciliation
- GetDeliveries2 lookup follows the supplied REST sample (/api/SendSMS/GetDeliveries2, form-urlencoded username, password, recID) over HTTPS.
- Delivery codes are mapped conservatively: 1 delivered; 2, 3, 16, 35 and 300 failed; other codes remain pending and are checked again after a bounded cooldown.
- CLI: scripts/poll-v3-sms-service-template-deliveries.php; apply migration 018 first. Schedule the CLI on the customer server with the same protected Shared Number environment credentials as the send worker.
- Unknown lookup outcomes never trigger a new SMS send. Provider receipt IDs are treated as strings to preserve 16–19 digit values.
- Status mapping and actual account response must be verified in a controlled provider test before declaring production acceptance.
