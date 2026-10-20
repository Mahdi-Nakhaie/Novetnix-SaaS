CREATE TABLE IF NOT EXISTS darycheh_product_requests (
 user_id INTEGER NOT NULL,
 request_key TEXT NOT NULL,
 request_fingerprint TEXT NOT NULL,
 attempt_id INTEGER NOT NULL,
 project_id INTEGER NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,request_key),
 FOREIGN KEY(user_id) REFERENCES users(id),
 FOREIGN KEY(attempt_id) REFERENCES darycheh_attempts(id),
 FOREIGN KEY(project_id) REFERENCES projects(id)
);
