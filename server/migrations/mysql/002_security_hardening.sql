-- 002: permanent once-only payment references (shared by the ChatGPT tool and the owner's "mark pack paid").
-- Survives the 7-day idempotency_keys cleanup. ref is stored normalised (trimmed, inner whitespace collapsed, upper case).
CREATE TABLE payment_references (
  ref        VARCHAR(80) NOT NULL PRIMARY KEY,
  user_id    CHAR(16)    NOT NULL,
  source     VARCHAR(20) NOT NULL,   -- chatgpt|admin
  created_at DATETIME    NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The idempotency scope_key embeds a caller-chosen key: it must compare case-sensitively ("Key-1" and "key-1" are different keys).
ALTER TABLE idempotency_keys MODIFY scope_key VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
