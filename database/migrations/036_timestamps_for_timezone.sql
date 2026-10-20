-- 036: every moment in time is a TIMESTAMP, so the site timezone can change.
--
-- Admin › Site settings sets one timezone for the site; db.php applies it to
-- PHP and to each database connection (SET time_zone). TIMESTAMP columns are
-- stored in UTC and converted on the way in and out, so they follow that
-- setting. DATETIME columns are not converted: they hold a bare clock time,
-- so after a timezone change expiry checks such as `expires_at < NOW()` would
-- be off by the UTC offset. These were the DATETIME columns that hold moments
-- (DATE columns such as award dates are calendar days and stay as they are).
--
-- Until now everything ran in UTC, so the existing values are UTC: the
-- session is set to UTC first, so the conversion reads them as such and no
-- stored moment moves.
--
-- Explicit DEFAULTs stop MariaDB from giving a NOT NULL TIMESTAMP the
-- automatic "ON UPDATE CURRENT_TIMESTAMP", which would rewrite an expiry
-- whenever the row changes.
--
-- Idempotent (MODIFY to the same type is a no-op).

SET time_zone = '+00:00';

ALTER TABLE password_resets
    MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY used_at    TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE email_verifications
    MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY used_at    TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE invites
    MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY revoked_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE account_restorations
    MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY used_at    TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE users
    MODIFY deleted_at         TIMESTAMP NULL DEFAULT NULL,
    MODIFY scheduled_purge_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE two_factor
    MODIFY last_used_at TIMESTAMP NULL DEFAULT NULL;
