CREATE TABLE IF NOT EXISTS member_erasure_audit (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id INT UNSIGNED NOT NULL,
  admin_id INT UNSIGNED NOT NULL,
  erased_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_member_erasure_audit_member (member_id, erased_at),
  KEY idx_member_erasure_audit_admin (admin_id, erased_at),
  CONSTRAINT fk_member_erasure_audit_member FOREIGN KEY (member_id) REFERENCES members (member_id),
  CONSTRAINT fk_member_erasure_audit_admin FOREIGN KEY (admin_id) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
