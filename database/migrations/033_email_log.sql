-- 033: a record of every email the app sends or tries to send.
--
-- One row per message and recipient, written by mail_send() (src/includes/
-- mailer.php). Shown to admins at /admin/emails.php.
--
-- kind       'custom' for an admin-written message, otherwise the template
--            name (invitation, password_reset, ...).
-- status     'sent' (the SMTP server accepted it), 'failed' (it refused or
--            was unreachable) or 'skipped' (email is off or APP_URL is
--            missing, so nothing was attempted).
-- body_text  The text of the message. For system emails the link in the
--            button is replaced by "[link hidden]": it is a one-time
--            password-reset / invitation / restore token and must not be
--            readable from the database or the admin pages afterwards.
-- sent_by    The admin who sent a custom email; NULL for system emails.
-- resend_of  The log row this message repeats, if it was a resend.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS email_log (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    kind          VARCHAR(40)  NOT NULL,
    status        ENUM('sent','failed','skipped') NOT NULL,
    to_email      VARCHAR(150) NOT NULL,
    to_name       VARCHAR(150) NULL,
    to_user_id    INT NULL,
    from_email    VARCHAR(150) NULL,
    subject       VARCHAR(255) NOT NULL,
    body_text     MEDIUMTEXT NULL,
    error         TEXT NULL,
    smtp_host     VARCHAR(150) NULL,
    duration_ms   INT UNSIGNED NULL,
    sent_by       INT NULL,
    request_ip    VARCHAR(45) NULL,
    resend_of     BIGINT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_el_time (created_at),
    INDEX idx_el_status (status, created_at),
    INDEX idx_el_kind (kind, created_at),
    INDEX idx_el_to (to_email),
    CONSTRAINT fk_el_to_user FOREIGN KEY (to_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_el_sent_by FOREIGN KEY (sent_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
