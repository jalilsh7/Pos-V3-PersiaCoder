-- Keyset pagination support for the business-scoped customer directory.
ALTER TABLE pos_customers
  ADD KEY idx_customers_business_updated_id (business_id, updated_at, id);
