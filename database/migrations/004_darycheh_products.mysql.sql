CREATE TABLE IF NOT EXISTS darycheh_product_requests (
 user_id BIGINT UNSIGNED NOT NULL,
 request_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 attempt_id BIGINT UNSIGNED NOT NULL,
 project_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,request_key),
 CONSTRAINT fk_dr_product_user FOREIGN KEY(user_id) REFERENCES users(id),
 CONSTRAINT fk_dr_product_attempt FOREIGN KEY(attempt_id) REFERENCES darycheh_attempts(id),
 CONSTRAINT fk_dr_product_project FOREIGN KEY(project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
