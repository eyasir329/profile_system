-- Migration 008: profile view analytics.
--
-- New table `profile_views` records every public profile render. Used
-- by the owner's dashboard to show "profile views this week" and a
-- sparkline of views over the last 30 days. `viewer_user_id` is NULL
-- for anonymous views; no FK is set so anonymous views can be logged
-- even if the viewer later creates / deletes an account.
-- `viewed_user_id` has FK CASCADE so profile deletion cleans up.


CREATE TABLE IF NOT EXISTS profile_views (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  viewer_user_id INT NULL,
  viewed_user_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pv_viewed (viewed_user_id, created_at),
  CONSTRAINT fk_pv_viewed FOREIGN KEY (viewed_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

