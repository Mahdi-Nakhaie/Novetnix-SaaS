CREATE TABLE IF NOT EXISTS darycheh_releases (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 scenario_key VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, content_version VARCHAR(32) COLLATE utf8mb4_bin NOT NULL,
 definition_schema_version INT NOT NULL, engine_version VARCHAR(32) NOT NULL, assessment_version VARCHAR(32) NOT NULL,
 definition_hash CHAR(64) COLLATE utf8mb4_bin NOT NULL UNIQUE, definition_json JSON NOT NULL,
 availability VARCHAR(16) NOT NULL CHECK(availability IN ('available','retired')),
 published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(scenario_key,content_version), UNIQUE(id,scenario_key), INDEX idx_dr_release(scenario_key,availability,published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS darycheh_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, release_id BIGINT UNSIGNED NOT NULL,
 scenario_key VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, attempt_no INT NOT NULL CHECK(attempt_no>0),
 start_request_key VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, start_fingerprint CHAR(64) NOT NULL,
 status VARCHAR(16) NOT NULL CHECK(status IN ('in_progress','completed','abandoned')),
 active_slot INT NULL, current_step_key VARCHAR(64) NOT NULL, revision INT NOT NULL CHECK(revision>0),
 last_action_seq INT NOT NULL DEFAULT 0, state_schema_version INT NOT NULL DEFAULT 1, state_json JSON NOT NULL,
 started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at DATETIME NULL, abandoned_at DATETIME NULL,
 UNIQUE(user_id,scenario_key,attempt_no), UNIQUE(user_id,start_request_key), UNIQUE(user_id,scenario_key,active_slot),
 CHECK((status='in_progress' AND active_slot IS NOT NULL AND active_slot=1 AND completed_at IS NULL AND abandoned_at IS NULL)
 OR (status='completed' AND active_slot IS NULL AND completed_at IS NOT NULL AND abandoned_at IS NULL)
 OR (status='abandoned' AND active_slot IS NULL AND abandoned_at IS NOT NULL AND completed_at IS NULL)),
 INDEX idx_dr_owner_status(user_id,status,updated_at,id), INDEX idx_dr_owner_history(user_id,scenario_key,id),
 INDEX idx_dr_attempt_release(release_id,status),
 FOREIGN KEY(user_id) REFERENCES users(id), FOREIGN KEY(release_id,scenario_key) REFERENCES darycheh_releases(id,scenario_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS darycheh_actions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attempt_id BIGINT UNSIGNED NOT NULL, seq INT NOT NULL CHECK(seq>0),
 request_key VARCHAR(64) COLLATE utf8mb4_bin NOT NULL, request_fingerprint CHAR(64) NOT NULL,
 action_type VARCHAR(32) NOT NULL, step_key VARCHAR(64) NOT NULL, base_revision INT NOT NULL,
 result_revision INT NOT NULL CHECK(result_revision=base_revision+1), payload_json JSON NOT NULL, outcome_json JSON NOT NULL,
 result_state_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(attempt_id,seq), UNIQUE(attempt_id,request_key), UNIQUE(attempt_id,result_revision),
 INDEX idx_dr_action_type(attempt_id,action_type,seq), FOREIGN KEY(attempt_id) REFERENCES darycheh_attempts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS darycheh_evaluations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, attempt_id BIGINT UNSIGNED NOT NULL, source_action_seq INT NOT NULL,
 scope VARCHAR(16) NOT NULL CHECK(scope IN ('main','transfer','summary')), round_no INT NOT NULL CHECK(round_no>0),
 assessment_hash CHAR(64) NOT NULL, input_hash CHAR(64) NOT NULL, evidence_json JSON NOT NULL, result_json JSON NOT NULL,
 score INT NOT NULL, max_score INT NOT NULL CHECK(max_score>0), passed INT NOT NULL CHECK(passed IN (0,1)),
 support_level VARCHAR(24) NOT NULL CHECK(support_level IN ('none','hinted','solution_revealed')),
 is_independent INT NOT NULL CHECK(is_independent IN (0,1)), created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(score>=0 AND score<=max_score), CHECK(NOT(is_independent=1 AND support_level='solution_revealed')),
 UNIQUE(attempt_id,source_action_seq,scope,assessment_hash),
 FOREIGN KEY(attempt_id,source_action_seq) REFERENCES darycheh_actions(attempt_id,seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
