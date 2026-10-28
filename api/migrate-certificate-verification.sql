-- Adds one unguessable public verification URL for each attendee covered by a paid registration.
-- This creates a new table and preserves all existing registration and payment records.
CREATE TABLE IF NOT EXISTS paid_certificate_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  paid_registration_id BIGINT UNSIGNED NOT NULL,
  attendee_index SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  attendee_name VARCHAR(180) NOT NULL,
  institution VARCHAR(200) NULL,
  token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  email_sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_emailed_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_certificate_token (token),
  UNIQUE KEY uniq_certificate_attendee (paid_registration_id, attendee_index),
  CONSTRAINT fk_certificate_paid_registration
    FOREIGN KEY (paid_registration_id) REFERENCES paid_registrations (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE paid_certificate_verifications
  ADD COLUMN IF NOT EXISTS email_sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS last_emailed_at DATETIME DEFAULT NULL;