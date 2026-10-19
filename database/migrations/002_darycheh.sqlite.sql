CREATE TABLE IF NOT EXISTS darycheh_releases (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 scenario_key TEXT NOT NULL, content_version TEXT NOT NULL,
 definition_schema_version INTEGER NOT NULL, engine_version TEXT NOT NULL, assessment_version TEXT NOT NULL,
 definition_hash TEXT NOT NULL UNIQUE, definition_json TEXT NOT NULL CHECK(json_valid(definition_json)),
 availability TEXT NOT NULL CHECK(availability IN ('available','retired')),
 published_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(scenario_key,content_version), UNIQUE(id,scenario_key)
);
CREATE INDEX IF NOT EXISTS idx_dr_release ON darycheh_releases(scenario_key,availability,published_at);
CREATE TABLE IF NOT EXISTS darycheh_attempts (
 id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, release_id INTEGER NOT NULL,
 scenario_key TEXT NOT NULL, attempt_no INTEGER NOT NULL CHECK(attempt_no>0),
 start_request_key TEXT NOT NULL, start_fingerprint TEXT NOT NULL,
 status TEXT NOT NULL CHECK(status IN ('in_progress','completed','abandoned')),
 active_slot INTEGER, current_step_key TEXT NOT NULL, revision INTEGER NOT NULL CHECK(revision>0),
 last_action_seq INTEGER NOT NULL DEFAULT 0, state_schema_version INTEGER NOT NULL DEFAULT 1,
 state_json TEXT NOT NULL CHECK(json_valid(state_json)),
 started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 completed_at TEXT, abandoned_at TEXT,
 UNIQUE(user_id,scenario_key,attempt_no), UNIQUE(user_id,start_request_key), UNIQUE(user_id,scenario_key,active_slot),
 CHECK((status='in_progress' AND active_slot IS NOT NULL AND active_slot=1 AND completed_at IS NULL AND abandoned_at IS NULL)
 OR (status='completed' AND active_slot IS NULL AND completed_at IS NOT NULL AND abandoned_at IS NULL)
 OR (status='abandoned' AND active_slot IS NULL AND abandoned_at IS NOT NULL AND completed_at IS NULL)),
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(release_id,scenario_key) REFERENCES darycheh_releases(id,scenario_key)
);
CREATE INDEX IF NOT EXISTS idx_dr_owner_status ON darycheh_attempts(user_id,status,updated_at,id);
CREATE INDEX IF NOT EXISTS idx_dr_owner_history ON darycheh_attempts(user_id,scenario_key,id);
CREATE INDEX IF NOT EXISTS idx_dr_attempt_release ON darycheh_attempts(release_id,status);
CREATE TABLE IF NOT EXISTS darycheh_actions (
 id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_id INTEGER NOT NULL, seq INTEGER NOT NULL CHECK(seq>0),
 request_key TEXT NOT NULL, request_fingerprint TEXT NOT NULL, action_type TEXT NOT NULL, step_key TEXT NOT NULL,
 base_revision INTEGER NOT NULL, result_revision INTEGER NOT NULL CHECK(result_revision=base_revision+1),
 payload_json TEXT NOT NULL CHECK(json_valid(payload_json)), outcome_json TEXT NOT NULL CHECK(json_valid(outcome_json)),
 result_state_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(attempt_id,seq), UNIQUE(attempt_id,request_key), UNIQUE(attempt_id,result_revision),
 FOREIGN KEY(attempt_id) REFERENCES darycheh_attempts(id)
);
CREATE INDEX IF NOT EXISTS idx_dr_action_type ON darycheh_actions(attempt_id,action_type,seq);
CREATE TABLE IF NOT EXISTS darycheh_evaluations (
 id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_id INTEGER NOT NULL, source_action_seq INTEGER NOT NULL,
 scope TEXT NOT NULL CHECK(scope IN ('main','transfer','summary')), round_no INTEGER NOT NULL CHECK(round_no>0),
 assessment_hash TEXT NOT NULL, input_hash TEXT NOT NULL,
 evidence_json TEXT NOT NULL CHECK(json_valid(evidence_json)), result_json TEXT NOT NULL CHECK(json_valid(result_json)),
 score INTEGER NOT NULL, max_score INTEGER NOT NULL CHECK(max_score>0), passed INTEGER NOT NULL CHECK(passed IN (0,1)),
 support_level TEXT NOT NULL CHECK(support_level IN ('none','hinted','solution_revealed')),
 is_independent INTEGER NOT NULL CHECK(is_independent IN (0,1)), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CHECK(score>=0 AND score<=max_score), CHECK(NOT(is_independent=1 AND support_level='solution_revealed')),
 UNIQUE(attempt_id,source_action_seq,scope,assessment_hash),
 FOREIGN KEY(attempt_id,source_action_seq) REFERENCES darycheh_actions(attempt_id,seq)
);
