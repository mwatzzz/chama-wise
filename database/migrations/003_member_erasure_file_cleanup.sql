CREATE TABLE IF NOT EXISTS member_erasure_file_cleanup (
  cleanup_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id INT UNSIGNED NOT NULL,
  relative_path VARCHAR(255) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cleanup_id),
  UNIQUE KEY uq_member_erasure_file_path (relative_path),
  KEY idx_member_erasure_file_member (member_id, cleanup_id),
  CONSTRAINT fk_member_erasure_file_member FOREIGN KEY (member_id) REFERENCES members (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
