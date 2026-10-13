-- Migration 018: rate-limit / brute-force protection.
--
-- The login, registration and forgot-password endpoints need a way to
-- count recent attempts and lock out abusive clients. A SQL table is
-- simpler and more correct than session-based counters because it
-- survives logout, works across many requests, and is shared across
-- all PHP processes.
--
-- `bucket` is the limiter key, e.g.:
--   "email:alice@example.com"  -> 5 failures in 15 minutes
--   "ip:1.2.3.4"               -> 20 failures in 10 minutes
-- `success` is 1 when the call to record_attempt() represents a
-- successful authentication (so we can clear the bucket on success).
-- Rows are retained for diagnostic purposes; a nightly cleanup can
-- delete `WHERE created_at < NOW() - INTERVAL 30 DAY` if desired.

CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    bucket      VARCHAR(80) NOT NULL,
    user_id     INT NULL,
    username    VARCHAR(150) NULL,
    ip          VARCHAR(45) NULL,
    success     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lat_bucket_time (bucket, created_at),
    INDEX idx_lat_time (created_at),
    CONSTRAINT fk_lat_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
