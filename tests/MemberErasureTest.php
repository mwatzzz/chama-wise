<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MemberErasureTest extends TestCase
{
    private PDO $pdo;
    private int $memberId;
    private int $adminId;
    private string $name = 'TEST DATA Qxandria Member';
    private string $phone = '254712345678';
    private string $email;
    private array $campaignIds = [];
    private array $loanIds = [];
    private array $goalIds = [];
    private array $paymentIds = [];
    private array $testPicturePaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = db();
        $suffix = strtolower(bin2hex(random_bytes(6)));
        $this->email = $suffix . '@example.test';

        $admin = $this->pdo->prepare(
            "INSERT INTO admins (name, email, password_hash, status)
             VALUES ('TEST DATA Erasure Admin', :email, 'TEST DATA', 'active')"
        );
        $admin->execute(['email' => 'admin-' . $this->email]);
        $this->adminId = (int) $this->pdo->lastInsertId();

        $member = $this->pdo->prepare(
            "INSERT INTO members
             (name, phone, email, password_hash, status, joined_date, reset_token_hash, reset_expires)
             VALUES (:name, :phone, :email, 'TEST DATA', 'active', CURDATE(), 'TEST RESET HASH', DATE_ADD(NOW(), INTERVAL 1 DAY))"
        );
        $member->execute([
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
        ]);
        $this->memberId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach ($this->paymentIds as $paymentId) {
            $this->pdo->prepare('DELETE FROM investment_payment_audit WHERE payment_id = :id')->execute(['id' => $paymentId]);
            $this->pdo->prepare('DELETE FROM investment_payment_allocations WHERE payment_id = :id')->execute(['id' => $paymentId]);
            $this->pdo->prepare('DELETE FROM investment_payments WHERE payment_id = :id')->execute(['id' => $paymentId]);
        }
        $this->pdo->prepare('DELETE FROM member_erasure_file_cleanup WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);
        foreach ($this->campaignIds as $campaignId) {
            $this->pdo->prepare('DELETE FROM sms_campaign_recipients WHERE campaign_id = :id')->execute(['id' => $campaignId]);
            $this->pdo->prepare('DELETE FROM sms_campaign_batches WHERE campaign_id = :id')->execute(['id' => $campaignId]);
            $this->pdo->prepare('DELETE FROM sms_campaigns WHERE campaign_id = :id')->execute(['id' => $campaignId]);
        }
        foreach ($this->goalIds as $goalId) {
            $this->pdo->prepare('DELETE FROM investment_transactions WHERE goal_id = :id')->execute(['id' => $goalId]);
            $this->pdo->prepare('DELETE FROM investment_goals WHERE goal_id = :id')->execute(['id' => $goalId]);
        }
        foreach ($this->loanIds as $loanId) {
            $this->pdo->prepare(
                'DELETE a FROM loan_repayment_audit a
                 INNER JOIN loan_repayments r ON r.repayment_id = a.repayment_id
                 WHERE r.loan_id = :loan_id'
            )->execute(['loan_id' => $loanId]);
            $this->pdo->prepare('DELETE FROM loan_repayments WHERE loan_id = :loan_id')->execute(['loan_id' => $loanId]);
            $this->pdo->prepare('DELETE FROM penalties WHERE loan_id = :loan_id')->execute(['loan_id' => $loanId]);
            $this->pdo->prepare('DELETE FROM loans WHERE loan_id = :loan_id')->execute(['loan_id' => $loanId]);
        }

        $this->pdo->prepare('DELETE FROM member_erasure_audit WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM ai_chat_messages WHERE session_id IN (SELECT session_id FROM ai_advice_sessions WHERE member_id = :member_id)')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM ai_advice_sessions WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM ai_member_daily_usage WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :member_id AND role = \'Member\'')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM auth_login_attempts WHERE email_hash = :email_hash')
            ->execute(['email_hash' => hash('sha256', $this->email)]);
        $this->pdo->prepare('DELETE FROM contributions WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM penalties WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM investment_payments WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM member_daraja_payments WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM group_investment_proposals WHERE submitted_by = :member_id')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM members WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM admins WHERE admin_id = :admin_id')
            ->execute(['admin_id' => $this->adminId]);

        foreach ($this->testPicturePaths as $path) {
            if (is_dir($path)) {
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        $testPictureDirectory = member_profile_picture_directory();
        if (is_dir($testPictureDirectory)) {
            rmdir($testPictureDirectory);
        }

        parent::tearDown();
    }

    public function testErasureRetainsFinancialHistoryAndRedactsDirectIdentifiers(): void
    {
        $contribution = $this->pdo->prepare(
            'INSERT INTO contributions (member_id, amount, contribution_date, reference, notes)
             VALUES (:member_id, 1250, CURDATE(), :reference, :notes)'
        );
        $contribution->execute([
            'member_id' => $this->memberId,
            'reference' => 'Receipt for ' . $this->name,
            'notes' => $this->email . ' paid from +' . $this->phone,
        ]);
        $contributionId = (int) $this->pdo->lastInsertId();

        $penalty = $this->pdo->prepare(
            "INSERT INTO penalties (member_id, amount, reason, penalty_date, status)
             VALUES (:member_id, 25, :reason, CURDATE(), 'paid')"
        );
        $penalty->execute(['member_id' => $this->memberId, 'reason' => 'Paid by ' . $this->name]);
        $penaltyId = (int) $this->pdo->lastInsertId();

        $loan = $this->pdo->prepare(
            "INSERT INTO loans (member_id, requested_amount, status, outstanding_balance, purpose, notes, application_date)
             VALUES (:member_id, 5000, 'repaid', 0, :purpose, :notes, CURDATE())"
        );
        $loan->execute([
            'member_id' => $this->memberId,
            'purpose' => 'For ' . $this->name,
            'notes' => $this->email . ' ' . $this->phone,
        ]);
        $loanId = (int) $this->pdo->lastInsertId();
        $this->loanIds[] = $loanId;

        $repayment = $this->pdo->prepare(
            'INSERT INTO loan_repayments (loan_id, amount, payment_date, reference)
             VALUES (:loan_id, 5000, CURDATE(), :reference)'
        );
        $repayment->execute([
            'loan_id' => $loanId,
            'reference' => 'Paid by ' . $this->name,
        ]);
        $repaymentId = (int) $this->pdo->lastInsertId();
        $audit = $this->pdo->prepare(
            "INSERT INTO loan_repayment_audit
             (repayment_id, previous_amount, new_amount, previous_payment_date, new_payment_date, reason, changed_by)
             VALUES (:repayment_id, 5000, 5000, CURDATE(), CURDATE(), :reason, :admin_id)"
        );
        $audit->execute([
            'repayment_id' => $repaymentId,
            'reason' => 'Correction for ' . $this->email,
            'admin_id' => $this->adminId,
        ]);

        $goal = $this->pdo->prepare(
            "INSERT INTO investment_goals
             (member_id, title, target_amount, duration_months, status)
             VALUES (:member_id, :title, 10000, 12, 'completed')"
        );
        $goal->execute(['member_id' => $this->memberId, 'title' => 'Goal for ' . $this->name]);
        $goalId = (int) $this->pdo->lastInsertId();
        $this->goalIds[] = $goalId;
        $transaction = $this->pdo->prepare(
            'INSERT INTO investment_transactions (goal_id, amount, transaction_type, note)
             VALUES (:goal_id, 1000, \'contribution\', :note)'
        );
        $transaction->execute(['goal_id' => $goalId, 'note' => $this->phone]);

        $payment = $this->pdo->prepare(
            "INSERT INTO investment_payments
             (member_id, transaction_reference, amount, phone_number, submission_key, status)
             VALUES (:member_id, :reference, 500, :phone, :submission_key, 'successful')"
        );
        $payment->execute([
            'member_id' => $this->memberId,
            'reference' => 'TEST' . strtoupper(bin2hex(random_bytes(6))),
            'phone' => $this->phone,
            'submission_key' => hash('sha256', random_bytes(16)),
        ]);
        $paymentId = (int) $this->pdo->lastInsertId();
        $this->paymentIds[] = $paymentId;
        $allocation = $this->pdo->prepare(
            'INSERT INTO investment_payment_allocations
             (payment_id, amount, allocated_by_admin, reason)
             VALUES (:payment_id, 500, :admin_id, :reason)'
        );
        $allocation->execute([
            'payment_id' => $paymentId,
            'admin_id' => $this->adminId,
            'reason' => 'Reconciled by Qxandria',
        ]);

        $campaign = $this->pdo->prepare(
            "INSERT INTO sms_campaigns
             (created_by, category, template_key, audience_type, provider, submission_key, message_body)
             VALUES (:admin_id, 'general', 'custom', 'members', 'TEST', :submission_key, :body)"
        );
        $campaign->execute([
            'admin_id' => $this->adminId,
            'submission_key' => hash('sha256', random_bytes(16)),
            'body' => 'Hello Qxandria, member payment reminder.',
        ]);
        $campaignId = (int) $this->pdo->lastInsertId();
        $this->campaignIds[] = $campaignId;
        $recipient = $this->pdo->prepare(
            "INSERT INTO sms_campaign_recipients
             (campaign_id, member_id, recipient_name, normalized_phone, rendered_message, status, delivered_at)
             VALUES (:campaign_id, :member_id, :name, :phone, :message, 'delivered', NOW())"
        );
        $recipient->execute([
            'campaign_id' => $campaignId,
            'member_id' => $this->memberId,
            'name' => $this->name,
            'phone' => '+' . $this->phone,
            'message' => 'Hello ' . $this->name . ', your email is ' . $this->email,
        ]);

        $aiSession = $this->pdo->prepare(
            'INSERT INTO ai_advice_sessions (member_id, title) VALUES (:member_id, :title)'
        );
        $aiSession->execute(['member_id' => $this->memberId, 'title' => 'Question from ' . $this->name]);
        $sessionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO ai_chat_messages (session_id, sender, message) VALUES (:session_id, 'member', :message)"
        )->execute(['session_id' => $sessionId, 'message' => 'My phone is ' . $this->phone]);

        $remember = $this->pdo->prepare(
            "INSERT INTO remember_tokens (user_id, role, selector, token_hash, expires_at)
             VALUES (:member_id, 'Member', :selector, 'TEST DATA', DATE_ADD(NOW(), INTERVAL 1 DAY))"
        );
        $remember->execute(['member_id' => $this->memberId, 'selector' => bin2hex(random_bytes(16))]);
        $this->pdo->prepare(
            'INSERT INTO ai_member_daily_usage (member_id, usage_date, request_count) VALUES (:member_id, CURDATE(), 1)'
        )->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare(
            'INSERT INTO auth_login_attempts (email_hash, ip_hash, window_started_at, failed_attempts)
             VALUES (:email_hash, :ip_hash, NOW(), 1)'
        )->execute([
            'email_hash' => hash('sha256', $this->email),
            'ip_hash' => hash('sha256', 'TEST DATA IP'),
        ]);

        $result = erase_member_account($this->pdo, $this->memberId, $this->adminId);

        $member = $this->fetchOne('SELECT * FROM members WHERE member_id = :member_id', ['member_id' => $this->memberId]);
        self::assertSame('erased', $member['status']);
        self::assertSame('Erased Member', $member['name']);
        self::assertStringContainsString('erased-member-', $member['email']);
        self::assertSame('1970-01-01', $member['joined_date']);
        self::assertNull($member['reset_token_hash']);
        self::assertNull($member['reset_expires']);
        self::assertNull($member['profile_picture']);
        self::assertFalse(member_account_is_active($this->pdo, $this->memberId));
        self::assertNull($result['profile_picture']);

        $contributionRow = $this->fetchOne(
            'SELECT amount, reference, notes FROM contributions WHERE contribution_id = :id',
            ['id' => $contributionId]
        );
        self::assertSame('1250.00', $contributionRow['amount']);
        self::assertStringNotContainsString($this->name, $contributionRow['reference']);
        self::assertStringNotContainsString($this->email, $contributionRow['notes']);
        self::assertStringNotContainsString($this->phone, $contributionRow['notes']);
        self::assertSame(1, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM loan_repayments WHERE loan_id = :loan_id',
            ['loan_id' => $loanId]
        ));
        $loanRow = $this->fetchOne(
            'SELECT purpose, notes FROM loans WHERE loan_id = :loan_id',
            ['loan_id' => $loanId]
        );
        self::assertStringNotContainsString($this->name, $loanRow['purpose']);
        self::assertStringNotContainsString($this->email, $loanRow['notes']);
        self::assertStringNotContainsString($this->phone, $loanRow['notes']);
        self::assertStringNotContainsString($this->name, $this->fetchColumn(
            'SELECT reference FROM loan_repayments WHERE loan_id = :loan_id',
            ['loan_id' => $loanId]
        ));
        self::assertSame(1, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM loan_repayment_audit WHERE repayment_id = :repayment_id',
            ['repayment_id' => $repaymentId]
        ));
        $penaltyRow = $this->fetchOne(
            'SELECT amount, status, reason FROM penalties WHERE penalty_id = :penalty_id',
            ['penalty_id' => $penaltyId]
        );
        self::assertSame('25.00', $penaltyRow['amount']);
        self::assertSame('paid', $penaltyRow['status']);
        self::assertStringNotContainsString($this->name, $penaltyRow['reason']);
        self::assertSame(1, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM investment_transactions WHERE goal_id = :goal_id',
            ['goal_id' => $goalId]
        ));
        self::assertSame('[redacted]', $this->fetchColumn(
            'SELECT note FROM investment_transactions WHERE goal_id = :goal_id',
            ['goal_id' => $goalId]
        ));
        self::assertStringNotContainsString($this->name, $this->fetchColumn(
            'SELECT title FROM investment_goals WHERE goal_id = :goal_id',
            ['goal_id' => $goalId]
        ));
        $paymentRow = $this->fetchOne(
            'SELECT amount, status, phone_number FROM investment_payments WHERE payment_id = :payment_id',
            ['payment_id' => $paymentId]
        );
        self::assertSame('500.00', $paymentRow['amount']);
        self::assertSame('successful', $paymentRow['status']);
        self::assertSame('', $paymentRow['phone_number']);
        self::assertSame(
            'Reconciled by [redacted]',
            $this->fetchColumn(
                'SELECT reason FROM investment_payment_allocations WHERE payment_id = :payment_id',
                ['payment_id' => $paymentId]
            )
        );
        self::assertSame(
            'Hello [redacted], member payment reminder.',
            $this->fetchColumn(
                'SELECT message_body FROM sms_campaigns WHERE campaign_id = :campaign_id',
                ['campaign_id' => $campaignId]
            )
        );

        $recipientRow = $this->fetchOne(
            'SELECT member_id, recipient_name, normalized_phone, rendered_message, status, delivered_at
             FROM sms_campaign_recipients WHERE campaign_id = :campaign_id',
            ['campaign_id' => $campaignId]
        );
        self::assertNull($recipientRow['member_id']);
        self::assertSame('Erased member', $recipientRow['recipient_name']);
        self::assertStringNotContainsString('254712345678', $recipientRow['normalized_phone']);
        self::assertSame('[redacted due to account erasure]', $recipientRow['rendered_message']);
        self::assertSame('delivered', $recipientRow['status']);
        self::assertNotNull($recipientRow['delivered_at']);

        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM ai_advice_sessions WHERE member_id = :member_id',
            ['member_id' => $this->memberId]
        ));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM remember_tokens WHERE user_id = :member_id AND role = \'Member\'',
            ['member_id' => $this->memberId]
        ));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM auth_login_attempts WHERE email_hash = :email_hash',
            ['email_hash' => hash('sha256', $this->email)]
        ));
        self::assertSame(1, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM member_erasure_audit WHERE member_id = :member_id AND admin_id = :admin_id',
            ['member_id' => $this->memberId, 'admin_id' => $this->adminId]
        ));
    }

    public function testPendingLoanApplicationBlocksErasureWithoutChanges(): void
    {
        $this->createLoan('pending', 0);

        try {
            erase_member_account($this->pdo, $this->memberId, $this->adminId);
            self::fail('Pending loan application should block account erasure.');
        } catch (MemberErasureBlocked $exception) {
            self::assertStringContainsString('pending loan applications', $exception->getMessage());
        }

        self::assertSame('active', $this->memberStatus());
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM member_erasure_audit WHERE member_id = :member_id',
            ['member_id' => $this->memberId]
        ));
    }

    public function testOutstandingLoanBlocksErasure(): void
    {
        $this->createLoan('overdue', 100);

        $this->expectException(MemberErasureBlocked::class);
        erase_member_account($this->pdo, $this->memberId, $this->adminId);
    }

    public function testUnpaidPenaltyBlocksErasure(): void
    {
        $this->pdo->prepare(
            "INSERT INTO penalties (member_id, amount, reason, penalty_date, status)
             VALUES (:member_id, 100, 'TEST DATA unpaid penalty', CURDATE(), 'outstanding')"
        )->execute(['member_id' => $this->memberId]);

        $this->expectException(MemberErasureBlocked::class);
        erase_member_account($this->pdo, $this->memberId, $this->adminId);
    }

    public function testPendingPaymentBlocksErasure(): void
    {
        $payment = $this->pdo->prepare(
            "INSERT INTO investment_payments
             (member_id, transaction_reference, amount, phone_number, submission_key, status)
             VALUES (:member_id, :reference, 100, :phone, :submission_key, 'pending')"
        );
        $payment->execute([
            'member_id' => $this->memberId,
            'reference' => 'TEST' . strtoupper(bin2hex(random_bytes(6))),
            'phone' => $this->phone,
            'submission_key' => hash('sha256', random_bytes(16)),
        ]);
        $this->paymentIds[] = (int) $this->pdo->lastInsertId();

        $this->expectException(MemberErasureBlocked::class);
        erase_member_account($this->pdo, $this->memberId, $this->adminId);
    }

    public function testUnreconciledSmsDeliveryBlocksErasure(): void
    {
        $campaign = $this->pdo->prepare(
            "INSERT INTO sms_campaigns
             (created_by, category, template_key, audience_type, provider, submission_key, message_body)
             VALUES (:admin_id, 'general', 'custom', 'members', 'TEST', :submission_key, 'TEST DATA')"
        );
        $campaign->execute([
            'admin_id' => $this->adminId,
            'submission_key' => hash('sha256', random_bytes(16)),
        ]);
        $campaignId = (int) $this->pdo->lastInsertId();
        $this->campaignIds[] = $campaignId;
        $this->pdo->prepare(
            "INSERT INTO sms_campaign_recipients
             (campaign_id, member_id, recipient_name, normalized_phone, rendered_message, status)
             VALUES (:campaign_id, :member_id, :name, :phone, 'TEST DATA', 'accepted')"
        )->execute([
            'campaign_id' => $campaignId,
            'member_id' => $this->memberId,
            'name' => $this->name,
            'phone' => '+' . $this->phone,
        ]);

        try {
            erase_member_account($this->pdo, $this->memberId, $this->adminId);
            self::fail('Unreconciled SMS delivery should block account erasure.');
        } catch (MemberErasureBlocked $exception) {
            self::assertStringContainsString('unreconciled SMS deliveries', $exception->getMessage());
        }

        self::assertSame('active', $this->memberStatus());
        self::assertSame(1, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM sms_campaign_recipients WHERE member_id = :member_id',
            ['member_id' => $this->memberId]
        ));
    }

    public function testProfilePictureIsDeletedAndCleanupQueueIsCleared(): void
    {
        $directory = member_profile_picture_directory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            self::fail('Could not create isolated test profile-picture storage.');
        }
        $relativePath = 'uploads/profile-pictures/member-' . $this->memberId . '-' . bin2hex(random_bytes(8)) . '.jpg';
        $filePath = $directory . DIRECTORY_SEPARATOR . basename($relativePath);
        $this->testPicturePaths[] = $filePath;
        self::assertNotFalse(file_put_contents($filePath, 'TEST DATA image bytes'));
        $this->pdo->prepare('UPDATE members SET profile_picture = :path WHERE member_id = :member_id')
            ->execute(['path' => $relativePath, 'member_id' => $this->memberId]);

        erase_member_account($this->pdo, $this->memberId, $this->adminId);
        self::assertSame(1, member_erasure_retry_profile_picture_cleanup($this->pdo, 10, $this->memberId));

        self::assertFileDoesNotExist($filePath);
        self::assertFalse(member_erasure_has_pending_profile_picture_cleanup($this->pdo, $this->memberId));
    }

    public function testFailedProfilePictureCleanupRemainsQueuedAndCanBeRetried(): void
    {
        $directory = member_profile_picture_directory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            self::fail('Could not create isolated test profile-picture storage.');
        }
        $relativePath = 'uploads/profile-pictures/member-' . $this->memberId . '-' . bin2hex(random_bytes(8)) . '.jpg';
        $filePath = $directory . DIRECTORY_SEPARATOR . basename($relativePath);
        $this->testPicturePaths[] = $filePath;
        self::assertTrue(mkdir($filePath));
        self::assertFalse(member_erasure_delete_profile_picture(
            '../uploads/profile-pictures/' . basename($filePath),
            $this->memberId
        ));
        $this->pdo->prepare('UPDATE members SET profile_picture = :path WHERE member_id = :member_id')
            ->execute(['path' => $relativePath, 'member_id' => $this->memberId]);

        erase_member_account($this->pdo, $this->memberId, $this->adminId);
        self::assertSame(0, member_erasure_retry_profile_picture_cleanup($this->pdo, 10, $this->memberId));
        self::assertTrue(member_erasure_has_pending_profile_picture_cleanup($this->pdo, $this->memberId));
        self::assertSame(
            1,
            (int) $this->fetchColumn(
                'SELECT attempts FROM member_erasure_file_cleanup WHERE member_id = :member_id',
                ['member_id' => $this->memberId]
            )
        );

        self::assertTrue(rmdir($filePath));
        self::assertNotFalse(file_put_contents($filePath, 'TEST DATA image bytes'));
        self::assertSame(1, member_erasure_retry_profile_picture_cleanup($this->pdo, 10, $this->memberId));
        self::assertFileDoesNotExist($filePath);
        self::assertFalse(member_erasure_has_pending_profile_picture_cleanup($this->pdo, $this->memberId));
    }

    private function createLoan(string $status, float $balance): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO loans (member_id, requested_amount, status, outstanding_balance, application_date)
             VALUES (:member_id, 1000, :status, :balance, CURDATE())'
        );
        $stmt->execute([
            'member_id' => $this->memberId,
            'status' => $status,
            'balance' => $balance,
        ]);
        $loanId = (int) $this->pdo->lastInsertId();
        $this->loanIds[] = $loanId;
        return $loanId;
    }

    private function memberStatus(): string
    {
        return (string) $this->fetchColumn(
            'SELECT status FROM members WHERE member_id = :member_id',
            ['member_id' => $this->memberId]
        );
    }

    private function fetchOne(string $sql, array $parameters): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function fetchColumn(string $sql, array $parameters): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);
        return $stmt->fetchColumn();
    }
}
