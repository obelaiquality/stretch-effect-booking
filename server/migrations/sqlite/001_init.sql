-- SQLite version for local development and tests. Generated from mysql/001_init.sql; keep in step.
-- All timestamps are UTC, stored as DATETIME. IDs are random 16-char strings (bin2hex(random_bytes(8))).

CREATE TABLE users (
  id            CHAR(16)     NOT NULL PRIMARY KEY,
  role          VARCHAR(10)  NOT NULL DEFAULT 'client',      -- client | owner
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(40)  NULL,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret_enc TEXT       NULL,                            -- sodium secretbox, base64
  totp_enabled  TINYINT(1)   NOT NULL DEFAULT 0,
  totp_last_step BIGINT      NULL,                            -- reject TOTP code reuse
  created_at    DATETIME     NOT NULL
);

CREATE TABLE credit_ledger (
  id         CHAR(16)     NOT NULL PRIMARY KEY,
  user_id    CHAR(16)     NOT NULL,
  delta      INT          NOT NULL,
  reason     VARCHAR(20)  NOT NULL,   -- purchase|booking|cancel_refund|owner_adjust|chatgpt_adjust|welcome_offer
  note       VARCHAR(255) NULL,
  actor      VARCHAR(60)  NOT NULL,   -- user id or chatgpt:<grant id>
  booking_id CHAR(16)     NULL,
  created_at DATETIME     NOT NULL,
  CONSTRAINT fk_ledger_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE packages (
  id         VARCHAR(20)  NOT NULL PRIMARY KEY,
  name       VARCHAR(80)  NOT NULL,
  sessions   INT          NOT NULL,
  price_zar  INT          NOT NULL,
  note       VARCHAR(160) NULL,
  active     TINYINT(1)   NOT NULL DEFAULT 1,
  sort       INT          NOT NULL DEFAULT 0
);

CREATE TABLE pack_requests (
  id                CHAR(16)    NOT NULL PRIMARY KEY,
  user_id           CHAR(16)    NOT NULL,
  package_id        VARCHAR(20) NOT NULL,
  sessions          INT         NOT NULL,
  price_zar         INT         NOT NULL,
  status            VARCHAR(10) NOT NULL DEFAULT 'pending',  -- pending|paid|cancelled
  payment_reference VARCHAR(80) NULL,
  created_at        DATETIME    NOT NULL,
  paid_at           DATETIME    NULL,
  CONSTRAINT fk_pack_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE bookings (
  id              CHAR(16)     NOT NULL PRIMARY KEY,
  user_id         CHAR(16)     NOT NULL,
  start_at        DATETIME     NOT NULL,
  end_at          DATETIME     NOT NULL,
  status          VARCHAR(12)  NOT NULL DEFAULT 'confirmed', -- confirmed|cancelled|completed|no_show
  created_by      VARCHAR(10)  NOT NULL,                     -- client|owner|chatgpt
  google_event_id VARCHAR(120) NULL,
  cancelled_at    DATETIME     NULL,
  cancel_reason   VARCHAR(255) NULL,
  refunded        TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL,
  CONSTRAINT fk_booking_user FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Key/value settings (JSON values). Keys: booking (Settings JSON), google (connection, refresh token encrypted).
CREATE TABLE settings (
  k          VARCHAR(40) NOT NULL PRIMARY KEY,
  v          TEXT  NOT NULL,
  updated_at DATETIME    NOT NULL
);

-- Busy blocks synced from Google Calendar by cron (cache; freeBusy is re-checked at booking time).
CREATE TABLE calendar_busy (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  start_at  DATETIME NOT NULL,
  end_at    DATETIME NOT NULL,
  synced_at DATETIME NOT NULL
);

-- ---------- ChatGPT connector: OAuth 2.1 authorization server ----------
CREATE TABLE oauth_clients (
  client_id      VARCHAR(255) NOT NULL PRIMARY KEY,  -- random id (DCR) or https URL (CIMD)
  client_name    VARCHAR(120) NOT NULL,
  redirect_uris  TEXT         NOT NULL,              -- JSON array
  registration   VARCHAR(10)  NOT NULL,              -- dcr|cimd
  created_at     DATETIME     NOT NULL
);

CREATE TABLE oauth_codes (
  code_hash      CHAR(64)     NOT NULL PRIMARY KEY,  -- sha256 hex
  client_id      VARCHAR(255) NOT NULL,
  user_id        CHAR(16)     NOT NULL,
  redirect_uri   VARCHAR(500) NOT NULL,
  scopes         VARCHAR(255) NOT NULL,
  resource       VARCHAR(255) NOT NULL,
  code_challenge VARCHAR(128) NOT NULL,
  expires_at     DATETIME     NOT NULL,              -- 60 seconds
  used_at        DATETIME     NULL
);

-- A grant = one approved ChatGPT connection. Revoking it kills all its tokens.
CREATE TABLE oauth_grants (
  id           CHAR(16)     NOT NULL PRIMARY KEY,
  client_id    VARCHAR(255) NOT NULL,
  user_id      CHAR(16)     NOT NULL,
  scopes       VARCHAR(255) NOT NULL,
  resource     VARCHAR(255) NOT NULL,
  created_at   DATETIME     NOT NULL,
  last_used_at DATETIME     NULL,
  revoked_at   DATETIME     NULL,
  revoke_reason VARCHAR(80) NULL
);

CREATE TABLE oauth_tokens (
  token_hash  CHAR(64)    NOT NULL PRIMARY KEY,      -- sha256 hex of the opaque token
  grant_id    CHAR(16)    NOT NULL,
  kind        VARCHAR(8)  NOT NULL,                  -- access|refresh
  expires_at  DATETIME    NOT NULL,
  used_at     DATETIME    NULL,                      -- refresh: set when rotated (reuse => revoke grant)
  revoked_at  DATETIME    NULL,
  created_at  DATETIME    NOT NULL
);

CREATE TABLE audit_log (
  id         CHAR(16)     NOT NULL PRIMARY KEY,
  at         DATETIME     NOT NULL,
  grant_id   CHAR(16)     NULL,
  actor      VARCHAR(60)  NOT NULL,
  action     VARCHAR(60)  NOT NULL,                  -- tool name or auth event
  args_json  TEXT         NULL,                      -- redacted
  status     VARCHAR(14)  NOT NULL,                  -- ok|denied|error|rate_limited|replayed
  summary    VARCHAR(255) NOT NULL,
  ip         VARCHAR(45)  NULL
);

CREATE TABLE idempotency_keys (
  scope_key   VARCHAR(200) NOT NULL PRIMARY KEY,     -- "<grant id>:<tool>:<key>"
  result_json TEXT   NOT NULL,
  created_at  DATETIME     NOT NULL
);

-- Fixed-window rate limiter: bucket = "<name>:<subject>:<window start>"
CREATE TABLE rate_limits (
  bucket     VARCHAR(200) NOT NULL PRIMARY KEY,
  hits       INT          NOT NULL,
  expires_at DATETIME     NOT NULL
);

INSERT INTO packages (id, name, sessions, price_zar, note, sort) VALUES
  ('single', 'Single session', 1, 550, '60-minute assisted stretch', 1),
  ('five',   '5-session pack', 5, 2500, 'Save R250', 2),
  ('ten',    '10-session pack', 10, 4500, 'Save R1,000', 3);

CREATE UNIQUE INDEX uq_users_email ON users (email);
CREATE INDEX ix_ledger_user ON credit_ledger (user_id, created_at);
CREATE INDEX ix_pack_user ON pack_requests (user_id);
CREATE UNIQUE INDEX uq_pack_payref ON pack_requests (payment_reference);
CREATE INDEX ix_bookings_start ON bookings (start_at, status);
CREATE INDEX ix_bookings_user ON bookings (user_id, start_at);
CREATE INDEX ix_busy_start ON calendar_busy (start_at);
CREATE INDEX ix_tokens_grant ON oauth_tokens (grant_id);
CREATE INDEX ix_audit_at ON audit_log (at);
