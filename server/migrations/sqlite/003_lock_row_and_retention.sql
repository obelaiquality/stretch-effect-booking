-- 003: seed the global booking lock row (kept in step with the MySQL schema; SQLite serialises with BEGIN IMMEDIATE).
INSERT OR IGNORE INTO settings (k, v, updated_at) VALUES ('lock', '{}', datetime('now'));
