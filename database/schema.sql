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
  currency_code VARCHAR(10) NOT NULL DEFAULT 'KES',
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
  loan_id INT UNSIGNED NULL,
  amount DECIMAL(12, 2) NOT NULL,
  currency_code VARCHAR(10) NOT NULL DEFAULT 'KES',
  reason VARCHAR(255) NOT NULL,
  penalty_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'outstanding',
  contribution_period CHAR(7) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (penalty_id),
  KEY idx_penalties_member (member_id),
  KEY idx_penalties_contribution (contribution_id),
  UNIQUE KEY uq_penalties_loan (loan_id),
  UNIQUE KEY uq_penalties_member_period (member_id, contribution_period),
  KEY idx_penalties_status (status),
  CONSTRAINT fk_penalties_member FOREIGN KEY (member_id) REFERENCES members (member_id),
  CONSTRAINT fk_penalties_contribution FOREIGN KEY (contribution_id) REFERENCES contributions (contribution_id),
  CONSTRAINT fk_penalties_admin FOREIGN KEY (created_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loans (
  loan_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id INT UNSIGNED NOT NULL,
  requested_amount DECIMAL(12, 2) NOT NULL,
  currency_code VARCHAR(10) NOT NULL DEFAULT 'KES',
  approved_amount DECIMAL(12, 2) NULL,
  interest_rate DECIMAL(5, 2) NULL,
  interest_method VARCHAR(20) NULL,
  outstanding_balance DECIMAL(12, 2) NULL,
  purpose VARCHAR(255) NULL,
  notes VARCHAR(255) NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'pending',
  rejection_reason VARCHAR(255) NULL,
  eligibility_decided_at DATETIME NULL,
  eligibility_outcome TEXT NULL,
  submission_key CHAR(64) NULL,
  application_date DATE NOT NULL,
  approval_date DATE NULL,
  due_date DATE NULL,
  interest_accrued_until DATE NULL,
  approved_by INT UNSIGNED NULL,
  first_approved_by INT UNSIGNED NULL,
  first_approval_date DATE NULL,
  second_approved_by INT UNSIGNED NULL,
  second_approval_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (loan_id),
  KEY idx_loans_member (member_id),
  KEY idx_loans_status (status),
  UNIQUE KEY uq_loans_submission_key (submission_key),
  CONSTRAINT fk_loans_member FOREIGN KEY (member_id) REFERENCES members (member_id),
  CONSTRAINT fk_loans_admin FOREIGN KEY (approved_by) REFERENCES admins (admin_id),
  CONSTRAINT fk_loans_first_admin FOREIGN KEY (first_approved_by) REFERENCES admins (admin_id),
  CONSTRAINT fk_loans_second_admin FOREIGN KEY (second_approved_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_repayments (
  repayment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  currency_code VARCHAR(10) NOT NULL DEFAULT 'KES',
  payment_date DATE NOT NULL,
  payment_method_id INT UNSIGNED NULL,
  reference VARCHAR(80) NULL,
  recorded_by INT UNSIGNED NULL,
  idempotency_key CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (repayment_id),
  UNIQUE KEY uq_repayments_idempotency_key (idempotency_key),
  KEY idx_repayments_loan (loan_id),
  KEY idx_repayments_date (payment_date),
  CONSTRAINT fk_repayments_loan FOREIGN KEY (loan_id) REFERENCES loans (loan_id),
  CONSTRAINT fk_repayments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (payment_method_id),
  CONSTRAINT fk_repayments_admin FOREIGN KEY (recorded_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_settings (
  setting_key VARCHAR(80) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_business_settings_admin FOREIGN KEY (updated_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS business_setting_audit (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  setting_key VARCHAR(80) NOT NULL,
  previous_value VARCHAR(255) NOT NULL,
  new_value VARCHAR(255) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_setting_audit_key (setting_key, changed_at),
  CONSTRAINT fk_setting_audit_admin FOREIGN KEY (changed_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_repayment_audit (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repayment_id INT UNSIGNED NOT NULL,
  previous_amount DECIMAL(12, 2) NOT NULL,
  new_amount DECIMAL(12, 2) NOT NULL,
  previous_payment_date DATE NOT NULL,
  new_payment_date DATE NOT NULL,
  reason VARCHAR(255) NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_repayment_audit_repayment (repayment_id, changed_at),
  CONSTRAINT fk_repayment_audit_repayment FOREIGN KEY (repayment_id) REFERENCES loan_repayments (repayment_id),
  CONSTRAINT fk_repayment_audit_admin FOREIGN KEY (changed_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS penalty_audit (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  penalty_id INT UNSIGNED NOT NULL,
  previous_amount DECIMAL(12, 2) NOT NULL,
  new_amount DECIMAL(12, 2) NOT NULL,
  previous_status VARCHAR(20) NOT NULL,
  new_status VARCHAR(20) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_penalty_audit_penalty (penalty_id, changed_at),
  CONSTRAINT fk_penalty_audit_penalty FOREIGN KEY (penalty_id) REFERENCES penalties (penalty_id),
  CONSTRAINT fk_penalty_audit_admin FOREIGN KEY (changed_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_opportunities (
  opportunity_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(160) NOT NULL,
  category VARCHAR(80) NOT NULL,
  description VARCHAR(1000) NOT NULL,
  risk_level VARCHAR(20) NOT NULL DEFAULT 'unknown',
  investment_period VARCHAR(120) NULL,
  liquidity VARCHAR(120) NULL,
  minimum_amount DECIMAL(12, 2) NULL,
  risks VARCHAR(1000) NULL,
  source_url VARCHAR(500) NULL,
  verified_at DATE NULL,
  annual_return_assumption DECIMAL(6, 3) NULL,
  verification_status VARCHAR(20) NOT NULL DEFAULT 'unverified',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (opportunity_id),
  KEY idx_investment_opportunities_active (is_active, category),
  CONSTRAINT fk_investment_opportunity_admin FOREIGN KEY (created_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_goals (
  goal_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id INT UNSIGNED NOT NULL,
  opportunity_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  target_amount DECIMAL(12, 2) NOT NULL,
  initial_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
  monthly_contribution DECIMAL(12, 2) NOT NULL DEFAULT 0,
  annual_return_assumption DECIMAL(6, 3) NOT NULL DEFAULT 0,
  duration_months SMALLINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  target_date DATE NULL,
  submission_key CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (goal_id),
  UNIQUE KEY uq_investment_goal_submission_key (submission_key),
  KEY idx_investment_goals_member (member_id, status),
  CONSTRAINT fk_investment_goal_member FOREIGN KEY (member_id) REFERENCES members (member_id),
  CONSTRAINT fk_investment_goal_opportunity FOREIGN KEY (opportunity_id) REFERENCES investment_opportunities (opportunity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_transactions (
  transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  goal_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  transaction_type VARCHAR(20) NOT NULL DEFAULT 'contribution',
  note VARCHAR(255) NULL,
  recorded_by INT UNSIGNED NULL,
  submission_key CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (transaction_id),
  UNIQUE KEY uq_investment_submission_key (submission_key),
  KEY idx_investment_transactions_goal (goal_id, created_at),
  CONSTRAINT fk_investment_transaction_goal FOREIGN KEY (goal_id) REFERENCES investment_goals (goal_id),
  CONSTRAINT fk_investment_transaction_admin FOREIGN KEY (recorded_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_group_plans (
  plan_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(160) NOT NULL,
  description VARCHAR(1000) NOT NULL,
  target_amount DECIMAL(12, 2) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (plan_id),
  KEY idx_investment_group_plan_status (status, created_at),
  CONSTRAINT fk_investment_group_plan_admin FOREIGN KEY (created_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_group_plan_audit (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  plan_id INT UNSIGNED NOT NULL,
  previous_title VARCHAR(160) NULL,
  new_title VARCHAR(160) NOT NULL,
  previous_target_amount DECIMAL(12, 2) NULL,
  new_target_amount DECIMAL(12, 2) NOT NULL,
  previous_status VARCHAR(20) NULL,
  new_status VARCHAR(20) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  admin_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_investment_group_plan_audit (plan_id, created_at),
  CONSTRAINT fk_investment_group_plan_audit_plan FOREIGN KEY (plan_id) REFERENCES investment_group_plans (plan_id),
  CONSTRAINT fk_investment_group_plan_audit_admin FOREIGN KEY (admin_id) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_payments (
  payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id INT UNSIGNED NOT NULL,
  goal_id INT UNSIGNED NULL,
  opportunity_id INT UNSIGNED NULL,
  group_plan_id INT UNSIGNED NULL,
  transaction_reference VARCHAR(32) NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  phone_number VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  submission_key CHAR(64) NOT NULL,
  merchant_request_id VARCHAR(100) NULL,
  checkout_request_id VARCHAR(100) NULL,
  mpesa_receipt_number VARCHAR(32) NULL,
  request_result_code VARCHAR(20) NULL,
  request_result_description VARCHAR(255) NULL,
  callback_result_code VARCHAR(20) NULL,
  callback_result_description VARCHAR(255) NULL,
  callback_amount DECIMAL(12, 2) NULL,
  callback_phone_number VARCHAR(20) NULL,
  callback_receipt_number VARCHAR(32) NULL,
  callback_received_at DATETIME NULL,
  callback_payload_hash CHAR(64) NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (payment_id),
  UNIQUE KEY uq_investment_payment_reference (transaction_reference),
  UNIQUE KEY uq_investment_payment_submission (submission_key),
  UNIQUE KEY uq_investment_payment_checkout (checkout_request_id),
  UNIQUE KEY uq_investment_payment_receipt (mpesa_receipt_number),
  KEY idx_investment_payment_member (member_id, created_at),
  KEY idx_investment_payment_status (status, created_at),
  CONSTRAINT fk_investment_payment_member FOREIGN KEY (member_id) REFERENCES members (member_id),
  CONSTRAINT fk_investment_payment_goal FOREIGN KEY (goal_id) REFERENCES investment_goals (goal_id),
  CONSTRAINT fk_investment_payment_opportunity FOREIGN KEY (opportunity_id) REFERENCES investment_opportunities (opportunity_id),
  CONSTRAINT fk_investment_payment_group_plan FOREIGN KEY (group_plan_id) REFERENCES investment_group_plans (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_payment_allocations (
  allocation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id BIGINT UNSIGNED NOT NULL,
  goal_id INT UNSIGNED NULL,
  opportunity_id INT UNSIGNED NULL,
  group_plan_id INT UNSIGNED NULL,
  amount DECIMAL(12, 2) NOT NULL,
  allocated_by_admin INT UNSIGNED NULL,
  reason VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (allocation_id),
  UNIQUE KEY uq_investment_allocation_payment (payment_id),
  KEY idx_investment_allocation_goal (goal_id),
  KEY idx_investment_allocation_opportunity (opportunity_id),
  CONSTRAINT fk_investment_allocation_payment FOREIGN KEY (payment_id) REFERENCES investment_payments (payment_id),
  CONSTRAINT fk_investment_allocation_goal FOREIGN KEY (goal_id) REFERENCES investment_goals (goal_id),
  CONSTRAINT fk_investment_allocation_opportunity FOREIGN KEY (opportunity_id) REFERENCES investment_opportunities (opportunity_id),
  CONSTRAINT fk_investment_allocation_group_plan FOREIGN KEY (group_plan_id) REFERENCES investment_group_plans (plan_id),
  CONSTRAINT fk_investment_allocation_admin FOREIGN KEY (allocated_by_admin) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS investment_payment_audit (
  audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(32) NOT NULL,
  previous_goal_id INT UNSIGNED NULL,
  previous_opportunity_id INT UNSIGNED NULL,
  previous_group_plan_id INT UNSIGNED NULL,
  previous_amount DECIMAL(12, 2) NULL,
  new_goal_id INT UNSIGNED NULL,
  new_opportunity_id INT UNSIGNED NULL,
  new_group_plan_id INT UNSIGNED NULL,
  new_amount DECIMAL(12, 2) NULL,
  result_code VARCHAR(20) NULL,
  result_description VARCHAR(255) NULL,
  reason VARCHAR(255) NULL,
  admin_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (audit_id),
  KEY idx_investment_payment_audit (payment_id, created_at),
  CONSTRAINT fk_investment_payment_audit_payment FOREIGN KEY (payment_id) REFERENCES investment_payments (payment_id),
  CONSTRAINT fk_investment_payment_audit_admin FOREIGN KEY (admin_id) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_investment_proposals (
  proposal_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  submitted_by INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  category VARCHAR(80) NOT NULL,
  description VARCHAR(1000) NOT NULL,
  simulated_amount DECIMAL(12, 2) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  decision_reason VARCHAR(255) NULL,
  decided_by INT UNSIGNED NULL,
  decided_at DATETIME NULL,
  submission_key CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (proposal_id),
  UNIQUE KEY uq_group_investment_submission_key (submission_key),
  KEY idx_group_investment_status (status, created_at),
  CONSTRAINT fk_group_investment_member FOREIGN KEY (submitted_by) REFERENCES members (member_id),
  CONSTRAINT fk_group_investment_admin FOREIGN KEY (decided_by) REFERENCES admins (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW loan_member_view AS
SELECT
  l.loan_id,
  l.member_id,
  m.name AS member_name,
  m.email AS member_email,
  m.phone AS member_phone,
  l.requested_amount,
  l.approved_amount,
  l.interest_rate,
  l.outstanding_balance,
  l.purpose,
  l.notes,
  l.status,
  l.rejection_reason,
  l.application_date,
  l.approval_date,
  l.approved_by,
  l.first_approved_by,
  l.first_approval_date,
  l.second_approved_by,
  l.second_approval_date,
  l.created_at
FROM loans l
INNER JOIN members m ON m.member_id = l.member_id;

CREATE TABLE IF NOT EXISTS meetings (
  meeting_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(160) NOT NULL,
  meeting_date DATE NOT NULL,
  minutes TEXT NULL,
  document_path VARCHAR(255) NULL,
  document_name VARCHAR(255) NULL,
  document_mime VARCHAR(100) NULL,
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

CREATE TABLE IF NOT EXISTS ai_member_daily_usage (
  member_id INT UNSIGNED NOT NULL,
  usage_date DATE NOT NULL,
  request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (member_id, usage_date),
  CONSTRAINT fk_ai_usage_member FOREIGN KEY (member_id) REFERENCES members (member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_login_attempts (
  email_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  window_started_at DATETIME NOT NULL,
  failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  PRIMARY KEY (email_hash, ip_hash),
  KEY idx_auth_attempts_locked_until (locked_until),
  KEY idx_auth_attempts_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_campaigns (
  campaign_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_by INT UNSIGNED NULL,
  category VARCHAR(60) NOT NULL,
  template_key VARCHAR(60) NOT NULL,
  audience_type VARCHAR(40) NOT NULL,
  provider VARCHAR(30) NOT NULL,
  submission_key CHAR(64) NOT NULL,
  message_body TEXT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (campaign_id),
  UNIQUE KEY uq_sms_campaign_submission (submission_key),
  KEY idx_sms_campaign_created (created_at),
  KEY idx_sms_campaign_status (status),
  CONSTRAINT fk_sms_campaign_admin FOREIGN KEY (created_by) REFERENCES admins (admin_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_campaign_batches (
  batch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  batch_number SMALLINT UNSIGNED NOT NULL,
  provider VARCHAR(30) NOT NULL,
  tracking_id VARCHAR(80) NULL,
  bulk_id VARCHAR(120) NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  response_code VARCHAR(40) NULL,
  safe_response_message VARCHAR(255) NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (batch_id),
  UNIQUE KEY uq_sms_campaign_batch_number (campaign_id, batch_number),
  UNIQUE KEY uq_sms_batch_tracking (tracking_id),
  KEY idx_sms_batch_bulk (provider, bulk_id),
  KEY idx_sms_batch_status (status),
  CONSTRAINT fk_sms_batch_campaign FOREIGN KEY (campaign_id) REFERENCES sms_campaigns (campaign_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_campaign_recipients (
  recipient_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  member_id INT UNSIGNED NULL,
  recipient_name VARCHAR(120) NOT NULL,
  normalized_phone VARCHAR(24) NOT NULL,
  rendered_message TEXT NOT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'pending',
  provider_reference VARCHAR(120) NULL,
  response_code VARCHAR(40) NULL,
  safe_response_message VARCHAR(255) NULL,
  failure_reason VARCHAR(255) NULL,
  last_dlr_fingerprint CHAR(64) NULL,
  last_dlr_status VARCHAR(24) NULL,
  sent_at DATETIME NULL,
  delivered_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (recipient_id),
  UNIQUE KEY uq_sms_campaign_recipient_phone (campaign_id, normalized_phone),
  KEY idx_sms_recipient_batch (batch_id, status),
  KEY idx_sms_recipient_member (member_id),
  KEY idx_sms_recipient_phone (normalized_phone),
  KEY idx_sms_recipient_status (status),
  CONSTRAINT fk_sms_recipient_campaign FOREIGN KEY (campaign_id) REFERENCES sms_campaigns (campaign_id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_recipient_batch FOREIGN KEY (batch_id) REFERENCES sms_campaign_batches (batch_id) ON DELETE RESTRICT,
  CONSTRAINT fk_sms_recipient_member FOREIGN KEY (member_id) REFERENCES members (member_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
