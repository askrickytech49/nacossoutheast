-- Run once in phpMyAdmin on the live NACOS database.
-- Adds admin controls for automatic Paystack and manual bank transfer methods.
-- Existing registration/payment records are not changed.
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
