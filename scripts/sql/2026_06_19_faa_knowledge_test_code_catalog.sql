-- FAA written-test deficiency code catalog for pasted PSI/CATS reports.
-- The parser can still show fallback rows for unknown codes; this table lets
-- IPCA maintain exact titles/sections as codes are encountered.

CREATE TABLE IF NOT EXISTS ipca_faa_knowledge_test_code_catalog (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code              VARCHAR(64) NOT NULL,
  title             VARCHAR(255) NOT NULL,
  relevant_section  VARCHAR(255) NULL,
  acs_area_code     VARCHAR(32) NULL,
  acs_task_code     VARCHAR(64) NULL,
  status            VARCHAR(32) NOT NULL DEFAULT 'active',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ipca_faa_knowledge_code (code),
  KEY idx_ipca_faa_knowledge_status (status, code),
  KEY idx_ipca_faa_knowledge_acs_area (acs_area_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='FAA knowledge test learning statement code titles and ACS sections.';

INSERT INTO ipca_faa_knowledge_test_code_catalog
  (code, title, relevant_section, acs_area_code, acs_task_code, status)
VALUES
  ('PLT012', 'Certificates and documents', 'ACS Area I - Pilot Qualifications', 'I', NULL, 'active'),
  ('PLT064', 'Weather information and services', 'ACS Area III - Weather Information', 'III', NULL, 'active'),
  ('PLT083', 'Airspace and ATC procedures', 'ACS Area V - National Airspace System', 'V', NULL, 'active'),
  ('PLT124', 'Aircraft performance and limitations', 'ACS Area VI - Performance and Limitations', 'VI', NULL, 'active'),
  ('PLT141', 'Weight and balance', 'ACS Area VI - Performance and Limitations', 'VI', NULL, 'active'),
  ('PLT161', 'Navigation systems and cross-country planning', 'ACS Area IV - Cross-Country Flight Planning', 'IV', NULL, 'active'),
  ('PLT172', 'Aircraft systems', 'ACS Area VII - Operation of Systems', 'VII', NULL, 'active'),
  ('PLT310', 'Aeromedical factors and human factors', 'ACS Area VIII - Human Factors', 'VIII', NULL, 'active')
ON DUPLICATE KEY UPDATE
  title = VALUES(title),
  relevant_section = VALUES(relevant_section),
  acs_area_code = VALUES(acs_area_code),
  acs_task_code = VALUES(acs_task_code),
  status = VALUES(status),
  updated_at = CURRENT_TIMESTAMP;
