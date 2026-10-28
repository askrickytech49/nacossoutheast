CREATE TABLE IF NOT EXISTS hackathon_registrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(160) NOT NULL,
  email VARCHAR(254) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  institution VARCHAR(200) NOT NULL,
  department VARCHAR(160) NOT NULL,
  study_level VARCHAR(40) NOT NULL,
  state VARCHAR(120) NOT NULL,
  participation ENUM('Individual', 'Team') NOT NULL,
  team_name VARCHAR(160) NULL,
  team_size TINYINT UNSIGNED NULL,
  review_status ENUM('new', 'reviewing', 'shortlisted', 'accepted', 'rejected') NOT NULL DEFAULT 'new',
  admin_notes TEXT NULL,
  project_idea TEXT NOT NULL,
  portfolio_url VARCHAR(2048) NULL,
  other_information TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hackathon_email (email),
  KEY idx_hackathon_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS paid_registrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_type ENUM('convention', 'sponsorship', 'pageant') NOT NULL,
  applicant_name VARCHAR(180) NOT NULL,
  applicant_email VARCHAR(254) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  category VARCHAR(120) NULL,
  package_name VARCHAR(160) NOT NULL,
  amount_kobo BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  payment_status ENUM('paid') NOT NULL DEFAULT 'paid',
  payment_reference VARCHAR(100) NOT NULL,
  payment_channel VARCHAR(40) NULL,
  payment_paid_at DATETIME NULL,
  submission_data LONGTEXT NOT NULL,
  photo_path VARCHAR(255) NULL,
  admin_notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_paid_payment_reference (payment_reference),
  KEY idx_paid_form_created (form_type, created_at),
  KEY idx_paid_email (applicant_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
CREATE TABLE IF NOT EXISTS manual_payment_submissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_type ENUM('convention', 'sponsorship', 'pageant') NOT NULL DEFAULT 'convention',
  applicant_name VARCHAR(180) NOT NULL,
  applicant_email VARCHAR(254) NOT NULL,
  phone VARCHAR(50) NOT NULL,
  category VARCHAR(120) NULL,
  package_name VARCHAR(160) NOT NULL,
  amount_kobo BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'NGN',
  submission_data LONGTEXT NOT NULL,
  proof_path VARCHAR(255) NOT NULL,
  application_photo_path VARCHAR(255) NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  admin_email_status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  payer_email_status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
  paid_registration_id BIGINT UNSIGNED NULL,
  admin_notes TEXT NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_manual_paid_registration (paid_registration_id),
  KEY idx_manual_status_submitted (status, submitted_at),
  KEY idx_manual_applicant_email (applicant_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS payment_method_settings (
  id TINYINT UNSIGNED NOT NULL,
  automatic_enabled TINYINT(1) NOT NULL DEFAULT 1,
  manual_enabled TINYINT(1) NOT NULL DEFAULT 1,
  default_method ENUM('automatic', 'manual') NOT NULL DEFAULT 'automatic',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO payment_method_settings (id, automatic_enabled, manual_enabled, default_method)
VALUES (1, 1, 1, 'automatic');
