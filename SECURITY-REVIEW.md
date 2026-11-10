# ChamaWise Member Account Erasure — Read-Only Safety Review

**Review date:** 2026-11-10
**Scope:** Member account erasure implementation and migration 002
**Review method:** Read-only source review. No database was accessed, no migration was run, no files were changed during the review, and no member account was processed.

## Assessment

Do not activate the erasure feature as a complete erasure control until the findings below are addressed and tested. The reviewed implementation includes administrator authentication, CSRF checks, obligation checks, transactional database updates, financial record retention, AI data deletion, and access checks for erased accounts. Three medium-severity risks remain.

## Findings

### 1. Incomplete redaction of retained free-text fields

- **Severity:** Medium
- **Confidence:** 8/10
- **Location:** `includes/member_erasure.php`, lines 393–413
- **Finding:** SMS campaign bodies are scrubbed using full identifiers only, so partial-name references may remain. `investment_payment_allocations.reason` is a member-payment-linked text field and is not scrubbed.
- **Risk:** If those fields contain identifying information, it may persist after erasure. No existing records were inspected, so actual presence is unknown.
- **Recommendation:** Redact appropriate member-associated identifiers from these fields and add tests for partial-name SMS content and payment-allocation reasons.

### 2. Concurrent SMS campaign can add a recipient after erasure checks

- **Severity:** Medium
- **Confidence:** 8/10
- **Location:** `includes/member_erasure.php`, lines 212–236; SMS recipient insertion path in `includes/sms_centre.php`, lines 319–349
- **Finding:** Erasure checks and locks existing SMS recipients, but a concurrent campaign can insert a recipient after that check. The campaign path selects active recipients before its insertion transaction and does not recheck that the member remains active while inserting.
- **Risk:** An erased member may be added to campaign history after the erasure transaction completes, potentially allowing subsequent use of their details.
- **Recommendation:** Recheck and lock member status inside the campaign insertion transaction, coordinate lock ordering with erasure, and add a concurrency test.

### 3. Profile image cleanup is not transactional or retryable

- **Severity:** Medium
- **Confidence:** 8/10
- **Location:** `includes/member_erasure.php`, lines 446–477; `admin/member.php`, lines 45–54
- **Finding:** The database transaction commits before the profile image is removed. If file deletion fails, the image may remain accessible at its public URL; there is no persisted cleanup retry mechanism.
- **Risk:** An erased member's profile image could remain publicly retrievable if cleanup fails.
- **Recommendation:** Store images outside the public web root or reliably quarantine/delete them, and persist failed cleanup for retry.

## Control review

- **Authorization and CSRF:** Administrator authentication and CSRF checks are present; the erasure function also checks for an active administrator.
- **Financial blockers:** The code checks for pending loan applications, outstanding loans and penalties, unresolved payment states, and existing pending/accepted/uncertain SMS recipients. The SMS concurrency issue means campaign protection is not race-proof.
- **Record retention:** Financial records, the member tombstone, and audit history are retained. The identified gaps concern redaction of free-text fields, not deletion of transaction records.
- **AI and credentials:** AI sessions/messages and associated AI usage are removed; remembered-login tokens are deleted.
- **Existing sessions:** Protected member requests check account status and destroy an inactive member session on the next authenticated request. Sessions are not proactively revoked at erasure time.
- **Reactivation:** Reviewed admin paths prevent editing or reactivating erased members.
- **Database transaction safety:** Database updates are transactional and roll back on failure. Profile-image cleanup is a separate post-commit operation and has the failure risk noted above.
- **Migration:** Migration 002 and the fresh-install schema appear structurally compatible with MySQL/MariaDB. The migration file itself is not automatically executed, but application startup invokes runtime schema setup. Confirm that deployment behavior and database DDL permissions are appropriate before activation.
- **Tests:** Existing tests cover basic blockers and some redaction, but not the identified SMS race, omitted retained text fields, failed image cleanup, or admin authorization/CSRF paths.

## Activation recommendation

Do not use the erasure feature on live member accounts until the three findings are corrected and covered by tests. This review did not inspect live records, execute migrations, modify a database, or erase any member.
