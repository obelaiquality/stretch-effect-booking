-- 002: permanent once-only payment references (shared by the ChatGPT tool and the owner's "mark pack paid").
-- Survives the 7-day idempotency_keys cleanup. ref is stored normalised (trimmed, inner whitespace collapsed, upper case).
CREATE TABLE payment_references (
  ref        VARCHAR(80) PRIMARY KEY,
  user_id    CHAR(16)    NOT NULL,
  source     VARCHAR(20) NOT NULL,   -- chatgpt|admin
  created_at DATETIME    NOT NULL
);
