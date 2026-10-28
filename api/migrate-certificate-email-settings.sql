CREATE TABLE IF NOT EXISTS certificate_email_settings (
  id TINYINT UNSIGNED NOT NULL,
  email_subject VARCHAR(255) NOT NULL DEFAULT 'Certificate of Participation - NACOS South East Convention 2026',
  email_body TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO certificate_email_settings (id, email_subject, email_body)
VALUES (1,
  'Certificate of Participation - NACOS South East Convention 2026',
  'Dear [Name],\n\nWe extend our heartfelt appreciation to you for being a part of the NACOS South East Convention 2026, held from 20th - 24th October 2026 at Ogbonnaya Onu Polytechnic Aba, Abia State. Your active participation contributed immensely to the success of this landmark event.\n\nPlease find attached your Certificate of Participation, issued in recognition of your participation in the Convention. We encourage you to proudly display and share this certificate as a testament to your commitment to advancing the prospects of computing around the student community in Nigeria and world at large.\n\nThank you once again for your presence, enthusiasm, and support. We look forward to seeing you at future NACOS programs and initiatives.\n\nAccept the assurances of our regards.'
);
