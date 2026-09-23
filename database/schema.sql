CREATE DATABASE IF NOT EXISTS chama_ai_management_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE chama_ai_management_system;

CREATE TABLE IF NOT EXISTS members (
  member_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  email VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'Member',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  joined_date DATE NOT NULL,
  profile_picture VARCHAR(255) NULL,
  reset_token_hash VARCHAR(255) NULL,
  reset_expires DATETIME NULL,
  PRIMARY KEY (member_id),
  UNIQUE KEY uq_members_email (email),
  UNIQUE KEY uq_members_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  admin_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'Admin',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  reset_token_hash VARCHAR(255) NULL,
  reset_expires DATETIME NULL,
  PRIMARY KEY (admin_id),
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
  token_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL,
  selector VARCHAR(64) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (token_id),
  UNIQUE KEY uq_remember_selector (selector),
  KEY idx_remember_user (user_id, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_methods (
  payment_method_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  code VARCHAR(30) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (payment_method_id),
  UNIQUE KEY uq_payment_methods_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contributions (
  contribution_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  payment_method_id INT UNSIGNED NULL,
  contribution_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'recorded',
  reference VARCHAR(80) NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (contribution_id),
  KEY idx_contributions_member (member_id),
  KEY idx_contributions_payment_method (payment_method_id),
  KEY idx_contributions_date (contribution_date),
  CONSTRAINT fk_contributions_payment_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (payment_method_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS penalties (
  penalty_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id INT UNSIGNED NOT NULL,
  contribution_id INT UNSIGNED NULL,
  amount DECIMAL(12, 2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  penalty_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'outstanding',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (penalty_id),
  KEY idx_penalties_member (member_id),
  KEY idx_penalties_contribution (contribution_id),
  KEY idx_penalties_status (status),
  CONSTRAINT fk_penalties_member FOREIGN KEY (member_id) REFERENCES members (member_id),
  CONSTRAINT fk_penalties_contribution FOREIGN KEY (contribution_id) REFERENCES contributions (contribution_id),
  CONSTRAINT fk_penalties_admin FOREIGN KEY (created_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loans (
  loan_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id INT UNSIGNED NOT NULL,
  requested_amount DECIMAL(12, 2) NOT NULL,
  approved_amount DECIMAL(12, 2) NULL,
  interest_rate DECIMAL(5, 2) NULL,
  outstanding_balance DECIMAL(12, 2) NULL,
  purpose VARCHAR(255) NULL,
  notes VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  application_date DATE NOT NULL,
  approval_date DATE NULL,
  approved_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (loan_id),
  KEY idx_loans_member (member_id),
  KEY idx_loans_status (status),
  CONSTRAINT fk_loans_member FOREIGN KEY (member_id) REFERENCES members (member_id),
  CONSTRAINT fk_loans_admin FOREIGN KEY (approved_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_repayments (
  repayment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  payment_date DATE NOT NULL,
  payment_method_id INT UNSIGNED NULL,
  reference VARCHAR(80) NULL,
  recorded_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (repayment_id),
  KEY idx_repayments_loan (loan_id),
  KEY idx_repayments_date (payment_date),
  CONSTRAINT fk_repayments_loan FOREIGN KEY (loan_id) REFERENCES loans (loan_id),
  CONSTRAINT fk_repayments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (payment_method_id),
  CONSTRAINT fk_repayments_admin FOREIGN KEY (recorded_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meetings (
  meeting_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(160) NOT NULL,
  meeting_date DATE NOT NULL,
  minutes TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (meeting_id),
  KEY idx_meetings_date (meeting_date),
  CONSTRAINT fk_meetings_admin FOREIGN KEY (created_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_advice_sessions (
  session_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (session_id),
  KEY idx_ai_sessions_member (member_id),
  CONSTRAINT fk_ai_sessions_member FOREIGN KEY (member_id) REFERENCES members (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_chat_messages (
  message_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED NOT NULL,
  sender VARCHAR(20) NOT NULL,
  message TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id),
  KEY idx_ai_messages_session (session_id),
  CONSTRAINT fk_ai_messages_session FOREIGN KEY (session_id) REFERENCES ai_advice_sessions (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
