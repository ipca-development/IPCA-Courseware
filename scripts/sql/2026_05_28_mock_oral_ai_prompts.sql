-- Default AI prompts for mock oral exam engine
--
-- Ensures canonical ai_prompts schema, then seeds mock oral prompt keys.
-- Safe to re-run (idempotent).
--
-- Canonical columns expected by PHP:
--   prompt_key, prompt_text, created_at, updated_at

CREATE TABLE IF NOT EXISTS ai_prompts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prompt_key VARCHAR(128) NOT NULL,
  prompt_text LONGTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_prompts_key (prompt_key),
  KEY idx_ai_prompts_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ai_prompts'
     AND COLUMN_NAME = 'prompt_text'
);
SET @sql_add_prompt_text := IF(@col_exists = 0,
  'ALTER TABLE ai_prompts ADD COLUMN prompt_text LONGTEXT NULL AFTER prompt_key',
  'SELECT 1');
PREPARE stmt_add_prompt_text FROM @sql_add_prompt_text;
EXECUTE stmt_add_prompt_text;
DEALLOCATE PREPARE stmt_add_prompt_text;

SET @legacy_prompt_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ai_prompts'
     AND COLUMN_NAME = 'prompt'
);
SET @sql_backfill_prompt_text := IF(@legacy_prompt_exists > 0,
  'UPDATE ai_prompts SET prompt_text = prompt WHERE (prompt_text IS NULL OR prompt_text = '''') AND prompt IS NOT NULL AND prompt <> ''''',
  'SELECT 1');
PREPARE stmt_backfill_prompt_text FROM @sql_backfill_prompt_text;
EXECUTE stmt_backfill_prompt_text;
DEALLOCATE PREPARE stmt_backfill_prompt_text;

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ai_prompts'
     AND COLUMN_NAME = 'created_at'
);
SET @sql_add_created_at := IF(@col_exists = 0,
  'ALTER TABLE ai_prompts ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
  'SELECT 1');
PREPARE stmt_add_created_at FROM @sql_add_created_at;
EXECUTE stmt_add_created_at;
DEALLOCATE PREPARE stmt_add_created_at;

SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'ai_prompts'
     AND COLUMN_NAME = 'updated_at'
);
SET @sql_add_updated_at := IF(@col_exists = 0,
  'ALTER TABLE ai_prompts ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
  'SELECT 1');
PREPARE stmt_add_updated_at FROM @sql_add_updated_at;
EXECUTE stmt_add_updated_at;
DEALLOCATE PREPARE stmt_add_updated_at;

INSERT INTO ai_prompts (prompt_key, prompt_text, created_at, updated_at)
SELECT 'mock_oral_blueprint_system',
'You are an FAA DPE oral exam planner for IPCA aviation training. Return ONLY valid JSON matching the schema. Design scenario-driven conversational exams—not random disconnected questions. Weight weak areas heavily. Include natural follow-ups. Stay within Private Pilot ACS scope.',
UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM ai_prompts WHERE prompt_key = 'mock_oral_blueprint_system');

INSERT INTO ai_prompts (prompt_key, prompt_text, created_at, updated_at)
SELECT 'mock_oral_turn_evaluator_system',
'You are an FAA DPE evaluating a Private Pilot oral exam answer. Use the session blueprint as authoritative scope. Return JSON only. Score 0-100. Identify missing concepts. Suggest natural follow-up questions when answers are partial or weak.',
UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM ai_prompts WHERE prompt_key = 'mock_oral_turn_evaluator_system');

INSERT INTO ai_prompts (prompt_key, prompt_text, created_at, updated_at)
SELECT 'mock_oral_debrief_system',
'You are an IPCA Head of Training writing a mock oral debrief for a student preparing for a real DPE exam. Return JSON only. Use ONLY references present in remediation_context (slide_references, mapped_lessons, acs_tasks). Do NOT invent FAR/AIM, PHAK, AFH, ACS codes, lesson IDs, or slide IDs.',
UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM ai_prompts WHERE prompt_key = 'mock_oral_debrief_system');
