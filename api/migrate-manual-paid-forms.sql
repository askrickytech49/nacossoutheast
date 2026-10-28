-- Run this once in phpMyAdmin on an existing database that already has
-- manual_payment_submissions from the Convention-only manual payment feature.
ALTER TABLE manual_payment_submissions
  MODIFY form_type ENUM('convention', 'sponsorship', 'pageant') NOT NULL DEFAULT 'convention',
  ADD COLUMN category VARCHAR(120) NULL AFTER phone,
  ADD COLUMN application_photo_path VARCHAR(255) NULL AFTER proof_path;
