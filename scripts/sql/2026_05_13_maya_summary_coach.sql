-- Maya Summary Coach (TEST/v3 feature) coaching state.
--
-- Stores ephemeral coaching state (stage, scores, readiness, history, flags)
-- for the AI-guided summary writing experience served by:
--   - /public/player/slide_v3.php
--   - /public/student/lesson_summaries_v3.php
-- and the API at:
--   - /public/student/api/summary_coach.php
--
-- This table is NOT authoritative for lesson completion or summary acceptance.
-- The canonical store remains `lesson_summaries` and the canonical acceptance
-- path remains LessonSummaryService::checkSummary(). Maya may unlock the final
-- review UI on the v3 surfaces, but the production progression engine stays
-- the source of truth. This row is purely coaching support state.
--
-- JSON-shaped columns are stored as LONGTEXT for environment compatibility.
-- All JSON validation happens in PHP (see public/student/api/summary_coach.php).
--
-- Idempotent: re-runs are safe.

CREATE TABLE IF NOT EXISTS student_summary_coach_sessions (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                  INT UNSIGNED    NOT NULL,
  lesson_id                INT UNSIGNED    NOT NULL,
  cohort_id                INT UNSIGNED    NULL,
  summary_id               INT UNSIGNED    NULL,
  context                  VARCHAR(32)     NOT NULL,
  stage                    VARCHAR(64)     NOT NULL DEFAULT 'structure',
  scores_json              LONGTEXT        NULL,
  readiness_json           LONGTEXT        NULL,
  flags_json               LONGTEXT        NULL,
  history_json             LONGTEXT        NULL,
  last_question            TEXT            NULL,
  interaction_count        INT             NOT NULL DEFAULT 0,
  major_paste_flag         TINYINT(1)      NOT NULL DEFAULT 0,
  ready_for_final_review   TINYINT(1)      NOT NULL DEFAULT 0,
  approved_by_maya         TINYINT(1)      NOT NULL DEFAULT 0,
  created_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_user_lesson_cohort_ctx (user_id, lesson_id, cohort_id, context),
  KEY idx_user_lesson (user_id, lesson_id),
  KEY idx_summary (summary_id),
  KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
