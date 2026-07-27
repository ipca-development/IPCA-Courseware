-- Seed SPC Scenario/Mission Catalogue for Program 1 FAA Private Pilot and Program 2 Experience Building VFR.
-- Source: mission_catalogue_SPC.csv
-- Re-runnable: updates mission identity rows and only creates a new version if this exact version is not already present.
-- Requires scripts/sql/2026_07_12_mission_catalog_foundation.sql to be applied first.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = utf8mb4_unicode_ci;

START TRANSACTION;

-- 1-1-1
SET @mission_code := '1-1-1';
SET @mission_name := 'Training Habits, About Your Airplane, Taxiing and Basic Attitude Flying - (1.0h LB)';
SET @mission_description := 'Training Habits, About Your Airplane, Taxiing and Basic Attitude Flying - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-2
SET @mission_code := '1-1-2';
SET @mission_name := 'Familiarization with the Airplane - I - (1.0h SE/FSTD)';
SET @mission_description := 'Familiarization with the Airplane - I - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-3
SET @mission_code := '1-1-3';
SET @mission_name := 'Familiarization with the Airplane - II - (1.0h SE/FSTD)';
SET @mission_description := 'Familiarization with the Airplane - II - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-4
SET @mission_code := '1-1-4';
SET @mission_name := 'Your First Flight - (1.5h DUAL)';
SET @mission_description := 'Your First Flight - (1.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-5
SET @mission_code := '1-1-5';
SET @mission_name := 'Airworthiness, Preflight Inspection, Use of Checklists and Trimming - (1.0h LB)';
SET @mission_description := 'Airworthiness, Preflight Inspection, Use of Checklists and Trimming - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-6
SET @mission_code := '1-1-6';
SET @mission_name := 'Engine Starting & Trimming - (1.0h SE/FSTD)';
SET @mission_description := 'Engine Starting & Trimming - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 6;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-7
SET @mission_code := '1-1-7';
SET @mission_name := 'Before Takeoff Actions and Turning - (1.0h SE/FSTD)';
SET @mission_description := 'Before Takeoff Actions and Turning - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 7;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-8
SET @mission_code := '1-1-8';
SET @mission_name := 'Trimming, Coordination and Turning - (1.5h DUAL)';
SET @mission_description := 'Trimming, Coordination and Turning - (1.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 8;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-9
SET @mission_code := '1-1-9';
SET @mission_name := 'Climbing, Descending, Climbing/Descending Turns - (1.0h LB)';
SET @mission_description := 'Climbing, Descending, Climbing/Descending Turns - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 9;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-10
SET @mission_code := '1-1-10';
SET @mission_name := 'Climbs/Descends, Climbing/Descending Turns - (1.0h SE/FSTD)';
SET @mission_description := 'Climbs/Descends, Climbing/Descending Turns - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 10;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-11
SET @mission_code := '1-1-11';
SET @mission_name := 'Climbs/Descends, Climbing/Descending Turns - (1.5h DUAL)';
SET @mission_description := 'Climbs/Descends, Climbing/Descending Turns - (1.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 11;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-1-12
SET @mission_code := '1-1-12';
SET @mission_name := 'Phase 1 - Progress Check - (1.5h DUAL)';
SET @mission_description := 'Phase 1 - Progress Check - (1.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 12;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-1
SET @mission_code := '1-2-1';
SET @mission_name := 'Taking Off, Maneuvering in Slow Flight - (1.0h LB)';
SET @mission_description := 'Taking Off, Maneuvering in Slow Flight - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-2
SET @mission_code := '1-2-2';
SET @mission_name := 'Taking Off - (1.0h SE/FSTD)';
SET @mission_description := 'Taking Off - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-3
SET @mission_code := '1-2-3';
SET @mission_name := 'Maneuvering in Slow Flight - (1.0h SE/FSTD)';
SET @mission_description := 'Maneuvering in Slow Flight - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-4
SET @mission_code := '1-2-4';
SET @mission_name := 'Your First Takeoff and Slow Flight - (1.5h DUAL)';
SET @mission_description := 'Your First Takeoff and Slow Flight - (1.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-5
SET @mission_code := '1-2-5';
SET @mission_name := 'Stalls and Steep Turns - (1.0h LB)';
SET @mission_description := 'Stalls and Steep Turns - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-6
SET @mission_code := '1-2-6';
SET @mission_name := 'Recovering from Stalls, practicing Steep Turns - (1.0h SE/FSTD)';
SET @mission_description := 'Recovering from Stalls, practicing Steep Turns - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 6;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-7
SET @mission_code := '1-2-7';
SET @mission_name := 'Correcting for wind in flight, recovering from stalls - (1.5h DUAL)';
SET @mission_description := 'Correcting for wind in flight, recovering from stalls - (1.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 7;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-8
SET @mission_code := '1-2-8';
SET @mission_name := 'Stall Recovery Practice and Steep Turns - (1.5h DUAL)';
SET @mission_description := 'Stall Recovery Practice and Steep Turns - (1.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 8;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-9
SET @mission_code := '1-2-9';
SET @mission_name := 'Ground Reference Maneuvers - (1.0h LB)';
SET @mission_description := 'Ground Reference Maneuvers - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 9;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-10
SET @mission_code := '1-2-10';
SET @mission_name := 'Ground Reference Maneuvers - (1.0h SE/FSTD)';
SET @mission_description := 'Ground Reference Maneuvers - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 10;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-11
SET @mission_code := '1-2-11';
SET @mission_name := 'Ground Reference Maneuvers - (1.5h DUAL)';
SET @mission_description := 'Ground Reference Maneuvers - (1.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 11;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-2-12
SET @mission_code := '1-2-12';
SET @mission_name := 'Phase 2 - Progress Check - (2.0h DUAL)';
SET @mission_description := 'Phase 2 - Progress Check - (2.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 2;
SET @mission_scenario := 12;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-3-1
SET @mission_code := '1-3-1';
SET @mission_name := 'Approaches and Landings - (1.0h LB)';
SET @mission_description := 'Approaches and Landings - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 3;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-3-2
SET @mission_code := '1-3-2';
SET @mission_name := 'Approaches and Landings - (1.0h SE/FSTD)';
SET @mission_description := 'Approaches and Landings - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 3;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-3-3
SET @mission_code := '1-3-3';
SET @mission_name := 'Crosswind Approaches and Landings - (1.0h SE/FSTD)';
SET @mission_description := 'Crosswind Approaches and Landings - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 3;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-3-4
SET @mission_code := '1-3-4';
SET @mission_name := 'Pattern Work (Non-Towered Airport) - (1.0h SE/FSTD)';
SET @mission_description := 'Pattern Work (Non-Towered Airport) - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 3;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-3-5
SET @mission_code := '1-3-5';
SET @mission_name := 'Your first Touch & Go session - (1.0h DUAL)';
SET @mission_description := 'Your first Touch & Go session - (1.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 3;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-3-6
SET @mission_code := '1-3-6';
SET @mission_name := 'Takeoff and landing consolidation - (4.0h DUAL)';
SET @mission_description := 'Takeoff and landing consolidation - (4.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 3;
SET @mission_scenario := 6;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-3-7
SET @mission_code := '1-3-7';
SET @mission_name := 'Meteorology, Performance, Emergency Equipment, Towered Airport Touch and Go Exercise - (1.0h LB)';
SET @mission_description := 'Meteorology, Performance, Emergency Equipment, Towered Airport Touch and Go Exercise - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 3;
SET @mission_scenario := 7;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-3-8
SET @mission_code := '1-3-8';
SET @mission_name := 'Pattern Work (Towered Airport) - (1.0h SE/FSTD)';
SET @mission_description := 'Pattern Work (Towered Airport) - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 3;
SET @mission_scenario := 8;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-3-9
SET @mission_code := '1-3-9';
SET @mission_name := 'Touch and go’s at a Towered Airport - (1.0h DUAL)';
SET @mission_description := 'Touch and go’s at a Towered Airport - (1.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 3;
SET @mission_scenario := 9;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-3-10
SET @mission_code := '1-3-10';
SET @mission_name := 'Phase 3 - Progress Check - (2.0h DUAL)';
SET @mission_description := 'Phase 3 - Progress Check - (2.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 3;
SET @mission_scenario := 10;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-4-1
SET @mission_code := '1-4-1';
SET @mission_name := 'Pilot Qualifications, Basic Instrument Flying - (1.0h LB)';
SET @mission_description := 'Pilot Qualifications, Basic Instrument Flying - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 4;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-4-2
SET @mission_code := '1-4-2';
SET @mission_name := 'Learning to use your Instruments - (1.0h SE/FSTD)';
SET @mission_description := 'Learning to use your Instruments - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 4;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-4-3
SET @mission_code := '1-4-3';
SET @mission_name := 'Using Your Flight Instruments to control the Airplane - (1.0h DUAL/0.7h B-IR)';
SET @mission_description := 'Using Your Flight Instruments to control the Airplane - (1.0h DUAL/0.7h B-IR)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 4;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-4-4
SET @mission_code := '1-4-4';
SET @mission_name := 'The Airspace System, Emergency Approach and Landings - (1.0h LB)';
SET @mission_description := 'The Airspace System, Emergency Approach and Landings - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 4;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-4-5
SET @mission_code := '1-4-5';
SET @mission_name := 'Handling Emergencies - I - (1.0h SE/FSTD)';
SET @mission_description := 'Handling Emergencies - I - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 4;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-4-6
SET @mission_code := '1-4-6';
SET @mission_name := 'Handling Emergencies - II - (1.0h SE/FSTD)';
SET @mission_description := 'Handling Emergencies - II - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 4;
SET @mission_scenario := 6;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-4-7
SET @mission_code := '1-4-7';
SET @mission_name := 'Handling The Unexpected (2.0h DUAL)';
SET @mission_description := 'Handling The Unexpected (2.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 4;
SET @mission_scenario := 7;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-4-8
SET @mission_code := '1-4-8';
SET @mission_name := 'Getting Ready For Solo Flight (2.0h DUAL/0.5h B-IR)';
SET @mission_description := 'Getting Ready For Solo Flight (2.0h DUAL/0.5h B-IR)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 4;
SET @mission_scenario := 8;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-4-9
SET @mission_code := '1-4-9';
SET @mission_name := 'Phase 4 - Progress Check (3.0h DUAL/1.3h B-IR)';
SET @mission_description := 'Phase 4 - Progress Check (3.0h DUAL/1.3h B-IR)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 4;
SET @mission_scenario := 9;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-5-1
SET @mission_code := '1-5-1';
SET @mission_name := 'Solo Check Out - (0.5h DUAL)';
SET @mission_description := 'Solo Check Out - (0.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 5;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-5-2
SET @mission_code := '1-5-2';
SET @mission_name := 'Your First Solo Flight - (0.5h PIC)';
SET @mission_description := 'Your First Solo Flight - (0.5h PIC)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 5;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 1-5-3
SET @mission_code := '1-5-3';
SET @mission_name := 'Your Second Solo Flight - (0.5h PIC)';
SET @mission_description := 'Your Second Solo Flight - (0.5h PIC)';
SET @mission_program := 1;
SET @mission_stage := 1;
SET @mission_phase := 5;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-1-1
SET @mission_code := '2-1-1';
SET @mission_name := 'Short/Soft Field Takeoffs & Landing, Weather Sources - (1.0h LB)';
SET @mission_description := 'Short/Soft Field Takeoffs & Landing, Weather Sources - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-1-2
SET @mission_code := '2-1-2';
SET @mission_name := 'Short Field Takeoff and Landings - (1.0h SE/FSTD)';
SET @mission_description := 'Short Field Takeoff and Landings - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-1-3
SET @mission_code := '2-1-3';
SET @mission_name := 'Using Short and Soft field Techniques - (1.5h DUAL)';
SET @mission_description := 'Using Short and Soft field Techniques - (1.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-1-4
SET @mission_code := '2-1-4';
SET @mission_name := 'Basic Instrument Flying Practice - (1.0h DUAL/0.5h B-IR)';
SET @mission_description := 'Basic Instrument Flying Practice - (1.0h DUAL/0.5h B-IR)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-1-5
SET @mission_code := '2-1-5';
SET @mission_name := 'Phase 1 Progress Check - (1.5h DUAL/1.0 B-IR)';
SET @mission_description := 'Phase 1 Progress Check - (1.5h DUAL/1.0 B-IR)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-2-1
SET @mission_code := '2-2-1';
SET @mission_name := 'Dead Reckoning Navigation - (1.0h LB)';
SET @mission_description := 'Dead Reckoning Navigation - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 2;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-2-2
SET @mission_code := '2-2-2';
SET @mission_name := 'Dead Reckoning Navigation - (1.0h SE/FSTD)';
SET @mission_description := 'Dead Reckoning Navigation - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 2;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-2-3
SET @mission_code := '2-2-3';
SET @mission_name := 'Practicing Dead Reckoning Techniques - (1.0h DUAL)';
SET @mission_description := 'Practicing Dead Reckoning Techniques - (1.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 2;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-2-4
SET @mission_code := '2-2-4';
SET @mission_name := 'Dead Reckoning to a Non-Towered Airport - (2.0h DUAL)';
SET @mission_description := 'Dead Reckoning to a Non-Towered Airport - (2.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 2;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-2-5
SET @mission_code := '2-2-5';
SET @mission_name := 'Radio Navigation VOR/ADF/DME/GPS and using your AFCS, Diverting to an Alternate - (1.0h LB)';
SET @mission_description := 'Radio Navigation VOR/ADF/DME/GPS and using your AFCS, Diverting to an Alternate - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 2;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-2-6
SET @mission_code := '2-2-6';
SET @mission_name := 'Radio Navigation VOR/DME/ADF - (1.0h SE/FSTD)';
SET @mission_description := 'Radio Navigation VOR/DME/ADF - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 2;
SET @mission_scenario := 6;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-2-7
SET @mission_code := '2-2-7';
SET @mission_name := 'Radio Navigation to a Non-Towered Airport - (2.0h DUAL)';
SET @mission_description := 'Radio Navigation to a Non-Towered Airport - (2.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 2;
SET @mission_scenario := 7;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-2-8
SET @mission_code := '2-2-8';
SET @mission_name := 'Radio Navigation GPS and using your AFCS - (1.0h SE/FSTD)';
SET @mission_description := 'Radio Navigation GPS and using your AFCS - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 2;
SET @mission_scenario := 8;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-2-9
SET @mission_code := '2-2-9';
SET @mission_name := 'Radio Navigation to a Towered Airport - (2.0h DUAL)';
SET @mission_description := 'Radio Navigation to a Towered Airport - (2.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 2;
SET @mission_scenario := 9;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-2-10
SET @mission_code := '2-2-10';
SET @mission_name := 'Stage 2 - Progress Check - (2.0h DUAL)';
SET @mission_description := 'Stage 2 - Progress Check - (2.0h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 2;
SET @mission_scenario := 10;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-3-1
SET @mission_code := '2-3-1';
SET @mission_name := 'Night Briefing - (1.0h LB)';
SET @mission_description := 'Night Briefing - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 3;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-3-2
SET @mission_code := '2-3-2';
SET @mission_name := 'Night Flying - (1.0h SE/FSTD)';
SET @mission_description := 'Night Flying - (1.0h SE/FSTD)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 3;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-3-3
SET @mission_code := '2-3-3';
SET @mission_name := 'Night Patterns and Emergencies - (1.0h DUAL/1.0h NIGHT)';
SET @mission_description := 'Night Patterns and Emergencies - (1.0h DUAL/1.0h NIGHT)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 3;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-3-4
SET @mission_code := '2-3-4';
SET @mission_name := 'Night Cross-country and Phase 3 - Progress Check - (2.0h DUAL/2.0h NIGHT/2.0h X-C)';
SET @mission_description := 'Night Cross-country and Phase 3 - Progress Check - (2.0h DUAL/2.0h NIGHT/2.0h X-C)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 3;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-4-1
SET @mission_code := '2-4-1';
SET @mission_name := 'Solo cross-country check-out - (0.5h DUAL)';
SET @mission_description := 'Solo cross-country check-out - (0.5h DUAL)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 4;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-4-2
SET @mission_code := '2-4-2';
SET @mission_name := 'Your first solo beyond the pattern - (1.5h PIC)';
SET @mission_description := 'Your first solo beyond the pattern - (1.5h PIC)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 4;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-4-3
SET @mission_code := '2-4-3';
SET @mission_name := 'Solo cross-country - (3.5h PIC)';
SET @mission_description := 'Solo cross-country - (3.5h PIC)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 4;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 2-4-4
SET @mission_code := '2-4-4';
SET @mission_name := 'Long Solo cross-country - (4.0h PIC)';
SET @mission_description := 'Long Solo cross-country - (4.0h PIC)';
SET @mission_program := 1;
SET @mission_stage := 2;
SET @mission_phase := 4;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 3-1-1
SET @mission_code := '3-1-1';
SET @mission_name := 'Practical Test Briefing - (1.0h LB)';
SET @mission_description := 'Practical Test Briefing - (1.0h LB)';
SET @mission_program := 1;
SET @mission_stage := 3;
SET @mission_phase := 1;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 3-1-2
SET @mission_code := '3-1-2';
SET @mission_name := 'Practical Test Preparation - (2.5h DUAL/0.5h B-IR)';
SET @mission_description := 'Practical Test Preparation - (2.5h DUAL/0.5h B-IR)';
SET @mission_program := 1;
SET @mission_stage := 3;
SET @mission_phase := 1;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 3-1-3
SET @mission_code := '3-1-3';
SET @mission_name := 'Final Progress Test - (2.5h DUAL/0.5h B-IR)';
SET @mission_description := 'Final Progress Test - (2.5h DUAL/0.5h B-IR)';
SET @mission_program := 1;
SET @mission_stage := 3;
SET @mission_phase := 1;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 3-1-4
SET @mission_code := '3-1-4';
SET @mission_name := 'FAA Practical Exam - (2.0h PIC)';
SET @mission_description := 'FAA Practical Exam - (2.0h PIC)';
SET @mission_program := 1;
SET @mission_stage := 3;
SET @mission_phase := 1;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 3-1-5
SET @mission_code := '3-1-5';
SET @mission_name := 'EASA Practical Exam - (2.0h PIC)';
SET @mission_description := 'EASA Practical Exam - (2.0h PIC)';
SET @mission_program := 1;
SET @mission_stage := 3;
SET @mission_phase := 1;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-1
SET @mission_code := '4-1-1';
SET @mission_name := 'Basic Multi-Crew Procedures - (1.0h LB)';
SET @mission_description := 'Basic Multi-Crew Procedures - (1.0h LB)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-2
SET @mission_code := '4-1-2';
SET @mission_name := 'Basic Multi-Crew Operations - (1.0h SE/FSTD)';
SET @mission_description := 'Basic Multi-Crew Operations - (1.0h SE/FSTD)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-3
SET @mission_code := '4-1-3';
SET @mission_name := 'Borrego Valley - L08 (DR VFR Navigation - MCC/Man) with SP - (1.5h PIC)';
SET @mission_description := 'Borrego Valley - L08 (DR VFR Navigation - MCC/Man) with SP - (1.5h PIC)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-4
SET @mission_code := '4-1-4';
SET @mission_name := 'Borrego Valley - L08 (DR VFR Navigation - MCC/Man) - (3.0h PIC)';
SET @mission_description := 'Borrego Valley - L08 (DR VFR Navigation - MCC/Man) - (3.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-5
SET @mission_code := '4-1-5';
SET @mission_name := 'Basic Multi-Crew Operations and AFCS use Part I - (1.5h SE/FSTD)';
SET @mission_description := 'Basic Multi-Crew Operations and AFCS use Part I - (1.5h SE/FSTD)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-6
SET @mission_code := '4-1-6';
SET @mission_name := 'Basic Multi-Crew Operations and AFCS use Part II - (1.5h SE/FSTD)';
SET @mission_description := 'Basic Multi-Crew Operations and AFCS use Part II - (1.5h SE/FSTD)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 6;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-7
SET @mission_code := '4-1-7';
SET @mission_name := 'Hemet Ryan - HMT (Radio Navigation - MCC) with SP - (1.5h PIC)';
SET @mission_description := 'Hemet Ryan - HMT (Radio Navigation - MCC) with SP - (1.5h PIC)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 7;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-8
SET @mission_code := '4-1-8';
SET @mission_name := 'Hemet Ryan - HMT (Radio Navigation - MCC) - (1.5h PIC)';
SET @mission_description := 'Hemet Ryan - HMT (Radio Navigation - MCC) - (1.5h PIC)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 8;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-9
SET @mission_code := '4-1-9';
SET @mission_name := 'Apple Valley - APV (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_description := 'Apple Valley - APV (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 9;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-10
SET @mission_code := '4-1-10';
SET @mission_name := 'Lake Havasu - HII (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_description := 'Lake Havasu - HII (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 10;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-11
SET @mission_code := '4-1-11';
SET @mission_name := 'TRM - TNP - BLH - L08 - TRM (Radio Navigation - MCC) - (4.0h PIC)';
SET @mission_description := 'TRM - TNP - BLH - L08 - TRM (Radio Navigation - MCC) - (4.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 11;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 4-1-12
SET @mission_code := '4-1-12';
SET @mission_name := 'Big Bear - L35 (Radio Navigation) with SP - (2.0h PIC)';
SET @mission_description := 'Big Bear - L35 (Radio Navigation) with SP - (2.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 1;
SET @mission_phase := 1;
SET @mission_scenario := 12;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-1
SET @mission_code := '5-1-1';
SET @mission_name := 'Towered Airports Review - (1.0h LB)';
SET @mission_description := 'Towered Airports Review - (1.0h LB)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-2
SET @mission_code := '5-1-2';
SET @mission_name := 'Basic Multi-Crew Operations AFCS use - (1.0h SE/FSTD)';
SET @mission_description := 'Basic Multi-Crew Operations AFCS use - (1.0h SE/FSTD)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-3
SET @mission_code := '5-1-3';
SET @mission_name := 'Ontario - ONT (Radio Navigation - MCC) with SP - (1.5h PIC)';
SET @mission_description := 'Ontario - ONT (Radio Navigation - MCC) with SP - (1.5h PIC)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-4
SET @mission_code := '5-1-4';
SET @mission_name := 'Victorville - VCV (Radio Navigation - MCC) - (4.0h PIC)';
SET @mission_description := 'Victorville - VCV (Radio Navigation - MCC) - (4.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-5
SET @mission_code := '5-1-5';
SET @mission_name := 'Yuma - NYL (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_description := 'Yuma - NYL (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-6
SET @mission_code := '5-1-6';
SET @mission_name := 'Mojave - MHV (Radio Navigation - MCC) - (4.0h PIC)';
SET @mission_description := 'Mojave - MHV (Radio Navigation - MCC) - (4.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 6;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-7
SET @mission_code := '5-1-7';
SET @mission_name := 'Carlsbad - CRQ (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_description := 'Carlsbad - CRQ (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 7;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-8
SET @mission_code := '5-1-8';
SET @mission_name := 'Ontario - ONT (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_description := 'Ontario - ONT (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 8;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-9
SET @mission_code := '5-1-9';
SET @mission_name := 'Catalina - AVX (Radio Navigation - MCC) with SP - (4.0h PIC)';
SET @mission_description := 'Catalina - AVX (Radio Navigation - MCC) with SP - (4.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 9;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-10
SET @mission_code := '5-1-10';
SET @mission_name := 'Torrance - TOA (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_description := 'Torrance - TOA (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 10;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 5-1-11
SET @mission_code := '5-1-11';
SET @mission_name := 'Long Beach - LGB (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_description := 'Long Beach - LGB (Radio Navigation - MCC) - (3.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 2;
SET @mission_phase := 1;
SET @mission_scenario := 11;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 6-1-1
SET @mission_code := '6-1-1';
SET @mission_name := 'Class B Airspace Review - (1.0h LB)';
SET @mission_description := 'Class B Airspace Review - (1.0h LB)';
SET @mission_program := 2;
SET @mission_stage := 3;
SET @mission_phase := 1;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 6-1-2
SET @mission_code := '6-1-2';
SET @mission_name := 'Burbank - BUR (Radio Navigation - MCC/AFCS) with SP - (2.0h PIC)';
SET @mission_description := 'Burbank - BUR (Radio Navigation - MCC/AFCS) with SP - (2.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 3;
SET @mission_phase := 1;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 6-1-3
SET @mission_code := '6-1-3';
SET @mission_name := 'San Diego Tour (Radio Navigation - MCC/AFCS) - (5.0h PIC)';
SET @mission_description := 'San Diego Tour (Radio Navigation - MCC/AFCS) - (5.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 3;
SET @mission_phase := 1;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 6-1-4
SET @mission_code := '6-1-4';
SET @mission_name := 'Burbank - BUR (Radio Navigation - MCC/AFCS) - (4.0h PIC)';
SET @mission_description := 'Burbank - BUR (Radio Navigation - MCC/AFCS) - (4.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 3;
SET @mission_phase := 1;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 6-1-5
SET @mission_code := '6-1-5';
SET @mission_name := 'Las Vegas - HND (Radio Navigation - MCC/AFCS) with SP - (2.0h PIC)';
SET @mission_description := 'Las Vegas - HND (Radio Navigation - MCC/AFCS) with SP - (2.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 3;
SET @mission_phase := 1;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 7-1-1
SET @mission_code := '7-1-1';
SET @mission_name := 'Long Distance Navigation - (1.0h LB)';
SET @mission_description := 'Long Distance Navigation - (1.0h LB)';
SET @mission_program := 2;
SET @mission_stage := 4;
SET @mission_phase := 1;
SET @mission_scenario := 1;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 7-1-2
SET @mission_code := '7-1-2';
SET @mission_name := 'Oxnard - OXR (Radio Navigation - MCC/AFCS) - (4.0h PIC)';
SET @mission_description := 'Oxnard - OXR (Radio Navigation - MCC/AFCS) - (4.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 4;
SET @mission_phase := 1;
SET @mission_scenario := 2;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 7-1-3
SET @mission_code := '7-1-3';
SET @mission_name := '300 NM - Needles & Henderson - EED HND - (Radio Navigation - MCC/AFCS) - (4.5h PIC)';
SET @mission_description := '300 NM - Needles & Henderson - EED HND - (Radio Navigation - MCC/AFCS) - (4.5h PIC)';
SET @mission_program := 2;
SET @mission_stage := 4;
SET @mission_phase := 1;
SET @mission_scenario := 3;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 7-1-4
SET @mission_code := '7-1-4';
SET @mission_name := 'Grand Canyon Tour (Radio Navigation - MCC/AFCS) with SP - (3.5h PIC)';
SET @mission_description := 'Grand Canyon Tour (Radio Navigation - MCC/AFCS) with SP - (3.5h PIC)';
SET @mission_program := 2;
SET @mission_stage := 4;
SET @mission_phase := 1;
SET @mission_scenario := 4;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 7-1-5
SET @mission_code := '7-1-5';
SET @mission_name := 'Final Progress Check John Wayne - SNA  (Radio Navigation - MCC/AFCS) with SP - (2.0h PIC)';
SET @mission_description := 'Final Progress Check John Wayne - SNA  (Radio Navigation - MCC/AFCS) with SP - (2.0h PIC)';
SET @mission_program := 2;
SET @mission_stage := 4;
SET @mission_phase := 1;
SET @mission_scenario := 5;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 7-1-6
SET @mission_code := '7-1-6';
SET @mission_name := 'EASA Night Patterns - (1.0h DUAL/1.0h NIGHT)';
SET @mission_description := 'EASA Night Patterns - (1.0h DUAL/1.0h NIGHT)';
SET @mission_program := 2;
SET @mission_stage := 4;
SET @mission_phase := 1;
SET @mission_scenario := 6;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 7-1-7
SET @mission_code := '7-1-7';
SET @mission_name := 'EASA Solo Night Patterns - (1.0h PIC/1.0h NIGHT)';
SET @mission_description := 'EASA Solo Night Patterns - (1.0h PIC/1.0h NIGHT)';
SET @mission_program := 2;
SET @mission_stage := 4;
SET @mission_phase := 1;
SET @mission_scenario := 7;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

-- 7-1-8
SET @mission_code := '7-1-8';
SET @mission_name := 'Extra Experience Building Mission(s)';
SET @mission_description := 'Extra Experience Building Mission(s)';
SET @mission_program := 2;
SET @mission_stage := 4;
SET @mission_phase := 1;
SET @mission_scenario := 8;
SET @mission_exercise := JSON_OBJECT('program', @mission_program, 'stage', @mission_stage, 'phase', @mission_phase, 'scenario', @mission_scenario, 'source', 'mission_catalogue_SPC.csv');
INSERT INTO ipca_missions (mission_uuid, organization_id, code, name, status)
VALUES (UUID(), 1, @mission_code, @mission_name, 'active')
ON DUPLICATE KEY UPDATE name = VALUES(name), status = 'active', updated_at = CURRENT_TIMESTAMP(3);
SET @mission_id := (SELECT id FROM ipca_missions WHERE organization_id = 1 AND code COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci LIMIT 1);
SET @matching_version_id := (
  SELECT id
  FROM ipca_mission_versions
  WHERE mission_id = @mission_id
    AND code_snapshot COLLATE utf8mb4_unicode_ci = @mission_code COLLATE utf8mb4_unicode_ci
    AND name_snapshot COLLATE utf8mb4_unicode_ci = @mission_name COLLATE utf8mb4_unicode_ci
    AND COALESCE(description, _utf8mb4'' COLLATE utf8mb4_unicode_ci) COLLATE utf8mb4_unicode_ci = @mission_description COLLATE utf8mb4_unicode_ci
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.program')) AS UNSIGNED) = @mission_program
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.stage')) AS UNSIGNED) = @mission_stage
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.phase')) AS UNSIGNED) = @mission_phase
    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(exercise_json, '$.scenario')) AS UNSIGNED) = @mission_scenario
  ORDER BY version_number DESC
  LIMIT 1
);
SET @next_version_number := (SELECT COALESCE(MAX(version_number), 0) + 1 FROM ipca_mission_versions WHERE mission_id = @mission_id);
INSERT INTO ipca_mission_versions (mission_id, mission_version_uuid, version_number, code_snapshot, name_snapshot, description, exercise_json, created_by)
SELECT @mission_id, UUID(), @next_version_number, @mission_code, @mission_name, @mission_description, @mission_exercise, NULL
WHERE @matching_version_id IS NULL;
SET @version_id := COALESCE(@matching_version_id, LAST_INSERT_ID());
UPDATE ipca_missions SET current_version_id = @version_id, updated_at = CURRENT_TIMESTAMP(3) WHERE id = @mission_id;
INSERT INTO ipca_mission_aliases (mission_id, alias, source)
VALUES (@mission_id, @mission_code, 'mission_catalogue_SPC')
ON DUPLICATE KEY UPDATE mission_id = VALUES(mission_id), source = VALUES(source);

INSERT INTO ipca_mission_import_batches (import_uuid, source, status, result_json)
VALUES (UUID(), 'mission_catalogue_SPC.sql', 'succeeded', JSON_OBJECT('row_count', 110, 'source', 'mission_catalogue_SPC.csv', 'generated_by', 'Cursor'));

COMMIT;
