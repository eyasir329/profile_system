-- 035: where and as whom each activity happened, for Admin › Activity.
--
-- ip           client address (client_ip(), proxy-aware)
-- user_agent   the browser's User-Agent, cut to 255 characters
-- acting_role  the role the person was acting as (admin / teacher / student)
--
-- Filled by log_activity() from now on; older rows stay NULL. The IP and
-- browser are cleared after 90 days by housekeeping (the event is kept).
--
-- Idempotent.

ALTER TABLE activity
    ADD COLUMN IF NOT EXISTS ip          VARCHAR(45)  NULL AFTER detail,
    ADD COLUMN IF NOT EXISTS user_agent  VARCHAR(255) NULL AFTER ip,
    ADD COLUMN IF NOT EXISTS acting_role VARCHAR(20)  NULL AFTER user_agent;

CREATE INDEX IF NOT EXISTS idx_act_time ON activity (created_at);
