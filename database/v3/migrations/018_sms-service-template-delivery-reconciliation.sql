-- Delivery reconciliation for National Payamak Shared Number template messages.
ALTER TABLE pos_sms_service_outbox
  ADD COLUMN delivery_status_code SMALLINT NULL AFTER provider_message_id,
  ADD COLUMN delivery_checked_at DATETIME(3) NULL AFTER delivery_status_code,
  ADD COLUMN next_delivery_check_at DATETIME(3) NULL AFTER delivery_checked_at,
  ADD KEY idx_sms_service_outbox_delivery (status, next_delivery_check_at, id);
