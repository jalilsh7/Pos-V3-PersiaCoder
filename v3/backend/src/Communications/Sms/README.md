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
