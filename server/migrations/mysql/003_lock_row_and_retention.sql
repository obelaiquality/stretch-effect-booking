-- 003: seed the global booking lock row so BookingService only needs SELECT ... FOR UPDATE on it.
-- (INSERT IGNORE inside the transaction raced with SELECT ... FOR UPDATE and could deadlock under load.)
INSERT IGNORE INTO settings (k, v, updated_at) VALUES ('lock', '{}', UTC_TIMESTAMP());
