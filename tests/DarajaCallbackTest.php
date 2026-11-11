<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DarajaCallbackTest extends TestCase
{
    private PDO $pdo;
    private int $memberId;
    private string $reference;
    private string $checkoutId;
    private string $merchantId;
    private string $phone = '254712345678';
    private array $investmentPaymentIds = [];
    private array $memberPaymentIds = [];
    private array $loanIds = [];
    private array $receiptNumbers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = db();
        $suffix = strtoupper(bin2hex(random_bytes(6)));
        $this->reference = 'T' . $suffix;
        $this->checkoutId = 'TEST_CHECKOUT_' . $suffix;
        $this->merchantId = 'TEST_MERCHANT_' . $suffix;

        $member = $this->pdo->prepare(
            "INSERT INTO members (name, phone, email, password_hash, status, joined_date)
             VALUES ('Daraja Test Member', :phone, :email, 'TEST DATA', 'active', CURDATE())"
        );
        $member->execute([
            'phone' => $this->phone,
            'email' => strtolower($suffix) . '@example.test',
        ]);
        $this->memberId = (int) $this->pdo->lastInsertId();

        daraja_set_test_query_result_provider(function (string $checkoutId): array {
            if ($checkoutId !== $this->checkoutId && $checkoutId !== $this->investmentCheckoutId()) {
                throw new RuntimeException('Unexpected TEST DATA checkout request.');
            }

            return ['result_code' => '0', 'result_description' => 'TEST DATA payment confirmed'];
        });
    }

    protected function tearDown(): void
    {
        daraja_set_test_query_result_provider(null);

        if ($this->investmentPaymentIds) {
            $ids = implode(',', array_map('intval', $this->investmentPaymentIds));
            $this->pdo->exec("DELETE FROM investment_payment_audit WHERE payment_id IN ($ids)");
            $this->pdo->exec("DELETE FROM investment_payment_allocations WHERE payment_id IN ($ids)");
            $this->pdo->exec("DELETE FROM investment_payments WHERE payment_id IN ($ids)");
        }
        if ($this->memberPaymentIds) {
            $ids = implode(',', array_map('intval', $this->memberPaymentIds));
            $this->pdo->exec("DELETE FROM member_daraja_payments WHERE member_payment_id IN ($ids)");
        }
        if ($this->receiptNumbers) {
            $stmt = $this->pdo->prepare('DELETE FROM contributions WHERE reference = :reference');
            foreach ($this->receiptNumbers as $receipt) {
                $stmt->execute(['reference' => $receipt]);
            }
        }
        if ($this->loanIds) {
            $ids = implode(',', array_map('intval', $this->loanIds));
            $this->pdo->exec("DELETE FROM loan_repayments WHERE loan_id IN ($ids)");
            $this->pdo->exec("DELETE FROM loans WHERE loan_id IN ($ids)");
        }
        $this->pdo->prepare('DELETE FROM investment_goals WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);
        $this->pdo->prepare('DELETE FROM members WHERE member_id = :member_id')
            ->execute(['member_id' => $this->memberId]);

        parent::tearDown();
    }

    public function testSuccessfulContributionIsRecordedOnceWhenCallbackIsRepeated(): void
    {
        $paymentId = $this->createMemberPayment('contribution', 125);
        $payload = $this->memberCallback('0', 125, 'TESTRCPT' . substr($this->reference, -6));
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        self::assertTrue(handle_daraja_member_callback($payload, $rawBody));
        self::assertTrue(handle_daraja_member_callback($payload, $rawBody));

        $payment = $this->fetchOne(
            'SELECT status FROM member_daraja_payments WHERE member_payment_id = :id',
            ['id' => $paymentId]
        );
        self::assertSame('successful', $payment['status']);
        self::assertSame(1, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM contributions WHERE reference = :reference',
            ['reference' => $payload['Body']['stkCallback']['CallbackMetadata']['Item'][1]['Value']]
        ));
    }

    public function testCancelledPaymentDoesNotCreateContribution(): void
    {
        $paymentId = $this->createMemberPayment('contribution', 125);
        $payload = $this->memberCallback('1032');
        daraja_set_test_query_result_provider(static fn (string $checkoutId): array => [
            'result_code' => '1',
            'result_description' => 'TEST DATA cancelled',
        ]);

        handle_daraja_member_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame('cancelled', $this->memberPaymentStatus($paymentId));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM contributions WHERE member_id = :member_id AND reference = :reference',
            ['member_id' => $this->memberId, 'reference' => $this->reference]
        ));
    }

    public function testSuccessfulQueryWithMismatchedAmountStaysPending(): void
    {
        $paymentId = $this->createMemberPayment('contribution', 125);
        $payload = $this->memberCallback('0', 124, 'TESTRCPT' . substr($this->reference, -6));

        handle_daraja_member_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame('pending', $this->memberPaymentStatus($paymentId));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM contributions WHERE member_id = :member_id',
            ['member_id' => $this->memberId]
        ));
    }

    public function testSuccessfulQueryWithMismatchedPhoneStaysPending(): void
    {
        $paymentId = $this->createMemberPayment('contribution', 125);
        $payload = $this->memberCallback('0', 125, 'TESTRCPT' . substr($this->reference, -6));
        $payload['Body']['stkCallback']['CallbackMetadata']['Item'][2]['Value'] = '254700000000';

        handle_daraja_member_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame('pending', $this->memberPaymentStatus($paymentId));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM contributions WHERE member_id = :member_id',
            ['member_id' => $this->memberId]
        ));
    }

    public function testSuccessfulQueryWithoutCallbackMetadataStaysPending(): void
    {
        $paymentId = $this->createMemberPayment('contribution', 125);
        $payload = $this->memberCallback('0');

        handle_daraja_member_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame('pending', $this->memberPaymentStatus($paymentId));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM contributions WHERE member_id = :member_id',
            ['member_id' => $this->memberId]
        ));
    }

    public function testSuccessfulCallbackWithMismatchedMerchantRequestIsNotReconciled(): void
    {
        $paymentId = $this->createMemberPayment('contribution', 125);
        $payload = $this->memberCallback('0', 125, 'TESTRCPT' . substr($this->reference, -6));
        $payload['Body']['stkCallback']['MerchantRequestID'] = 'UNEXPECTED_MERCHANT';

        handle_daraja_member_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame('pending', $this->memberPaymentStatus($paymentId));
        self::assertNull($this->fetchColumn(
            'SELECT callback_amount FROM member_daraja_payments WHERE member_payment_id = :id',
            ['id' => $paymentId]
        ));
    }

    public function testUnknownCheckoutRequestIsIgnored(): void
    {
        $payload = $this->memberCallback('0', 125, 'TESTRCPT' . substr($this->reference, -6));
        $payload['Body']['stkCallback']['CheckoutRequestID'] = 'UNKNOWN_TEST_CHECKOUT';

        self::assertFalse(handle_daraja_member_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR)));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM member_daraja_payments WHERE member_id = :member_id',
            ['member_id' => $this->memberId]
        ));
    }

    public function testVerifiedRepaymentAboveBalanceRequiresReviewWithoutLoanCredit(): void
    {
        $loanId = $this->createLoan(50);
        $paymentId = $this->createMemberPayment('loan_repayment', 75, $loanId);
        $payload = $this->memberCallback('0', 75, 'TESTRCPT' . substr($this->reference, -6));

        handle_daraja_member_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame('review_required', $this->memberPaymentStatus($paymentId));
        self::assertSame('50.00', (string) $this->fetchColumn(
            'SELECT outstanding_balance FROM loans WHERE loan_id = :id',
            ['id' => $loanId]
        ));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM loan_repayments WHERE loan_id = :id',
            ['id' => $loanId]
        ));
    }

    public function testVerifiedRepaymentUpdatesOnlyItsLoan(): void
    {
        $loanId = $this->createLoan(100);
        $paymentId = $this->createMemberPayment('loan_repayment', 40, $loanId);
        $payload = $this->memberCallback('0', 40, 'TESTRCPT' . substr($this->reference, -6));

        handle_daraja_member_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame('successful', $this->memberPaymentStatus($paymentId));
        self::assertSame('60.00', (string) $this->fetchColumn(
            'SELECT outstanding_balance FROM loans WHERE loan_id = :id',
            ['id' => $loanId]
        ));
        self::assertSame(1, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM loan_repayments WHERE loan_id = :id',
            ['id' => $loanId]
        ));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM contributions WHERE member_id = :member_id',
            ['member_id' => $this->memberId]
        ));
    }

    public function testVerifiedInvestmentCreatesOneAllocation(): void
    {
        $goalId = $this->createInvestmentGoal();
        $paymentId = $this->createInvestmentPayment($goalId);
        $payload = $this->investmentCallback('0', 125, 'TESTRCPT' . substr($this->reference, -6));

        self::assertTrue(handle_daraja_investment_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR)));
        self::assertTrue(handle_daraja_investment_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR)));

        self::assertSame('successful', $this->investmentPaymentStatus($paymentId));
        self::assertSame(1, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM investment_payment_allocations WHERE payment_id = :id',
            ['id' => $paymentId]
        ));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM contributions WHERE member_id = :member_id',
            ['member_id' => $this->memberId]
        ));
    }

    public function testInvestmentCallbackWithMismatchedMerchantRequestIsIgnored(): void
    {
        $goalId = $this->createInvestmentGoal();
        $paymentId = $this->createInvestmentPayment($goalId);
        $payload = $this->investmentCallback('0', 125, 'TESTRCPT' . substr($this->reference, -6));
        $payload['Body']['stkCallback']['MerchantRequestID'] = 'UNEXPECTED_MERCHANT';

        self::assertTrue(handle_daraja_investment_callback($payload, json_encode($payload, JSON_THROW_ON_ERROR)));
        self::assertSame('pending', $this->investmentPaymentStatus($paymentId));
        self::assertSame(0, (int) $this->fetchColumn(
            'SELECT COUNT(*) FROM investment_payment_allocations WHERE payment_id = :id',
            ['id' => $paymentId]
        ));
    }

    private function createMemberPayment(string $type, int $amount, ?int $loanId = null): int
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO member_daraja_payments
                (member_id, payment_type, related_id, transaction_reference, amount, phone_number, status,
                 submission_key, merchant_request_id, checkout_request_id)
             VALUES (:member_id, :type, :related_id, :reference, :amount, :phone, 'pending',
                     :submission_key, :merchant_id, :checkout_id)"
        );
        $insert->execute([
            'member_id' => $this->memberId,
            'type' => $type,
            'related_id' => $loanId,
            'reference' => $this->reference,
            'amount' => $amount,
            'phone' => $this->phone,
            'submission_key' => hash('sha256', $this->reference),
            'merchant_id' => $this->merchantId,
            'checkout_id' => $this->checkoutId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->memberPaymentIds[] = $id;

        return $id;
    }

    private function createLoan(float $balance): int
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO loans
                (member_id, requested_amount, approved_amount, outstanding_balance, purpose, status, application_date, due_date)
             VALUES (:member_id, :requested_amount, :approved_amount, :balance, 'TEST DATA loan', 'approved', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))"
        );
        $insert->execute([
            'member_id' => $this->memberId,
            'requested_amount' => $balance,
            'approved_amount' => $balance,
            'balance' => $balance,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->loanIds[] = $id;

        return $id;
    }

    private function createInvestmentGoal(): int
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO investment_goals
                (member_id, title, target_amount, duration_months, status, submission_key)
             VALUES (:member_id, 'TEST DATA goal', 1000, 12, 'active', :submission_key)"
        );
        $insert->execute([
            'member_id' => $this->memberId,
            'submission_key' => hash('sha256', 'goal-' . $this->reference),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createInvestmentPayment(int $goalId): int
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO investment_payments
                (member_id, goal_id, transaction_reference, amount, phone_number, status, submission_key,
                 merchant_request_id, checkout_request_id)
             VALUES (:member_id, :goal_id, :reference, 125, :phone, 'pending', :submission_key,
                     :merchant_id, :checkout_id)"
        );
        $insert->execute([
            'member_id' => $this->memberId,
            'goal_id' => $goalId,
            'reference' => $this->reference,
            'phone' => $this->phone,
            'submission_key' => hash('sha256', 'investment-' . $this->reference),
            'merchant_id' => $this->merchantId,
            'checkout_id' => $this->investmentCheckoutId(),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->investmentPaymentIds[] = $id;

        return $id;
    }

    private function memberCallback(string $resultCode, ?int $amount = null, ?string $receipt = null): array
    {
        return $this->callbackPayload($this->checkoutId, $resultCode, $amount, $receipt);
    }

    private function investmentCallback(string $resultCode, ?int $amount = null, ?string $receipt = null): array
    {
        return $this->callbackPayload($this->investmentCheckoutId(), $resultCode, $amount, $receipt);
    }

    private function callbackPayload(string $checkoutId, string $resultCode, ?int $amount, ?string $receipt): array
    {
        $callback = [
            'MerchantRequestID' => $this->merchantId,
            'CheckoutRequestID' => $checkoutId,
            'ResultCode' => $resultCode,
            'ResultDesc' => 'TEST DATA callback',
        ];
        if ($amount !== null && $receipt !== null) {
            $this->receiptNumbers[] = $receipt;
            $callback['CallbackMetadata'] = [
                'Item' => [
                    ['Name' => 'Amount', 'Value' => $amount],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                    ['Name' => 'PhoneNumber', 'Value' => $this->phone],
                ],
            ];
        }

        return ['Body' => ['stkCallback' => $callback]];
    }

    private function investmentCheckoutId(): string
    {
        return $this->checkoutId . '_INV';
    }

    private function memberPaymentStatus(int $id): string
    {
        return (string) $this->fetchColumn(
            'SELECT status FROM member_daraja_payments WHERE member_payment_id = :id',
            ['id' => $id]
        );
    }

    private function investmentPaymentStatus(int $id): string
    {
        return (string) $this->fetchColumn(
            'SELECT status FROM investment_payments WHERE payment_id = :id',
            ['id' => $id]
        );
    }

    private function fetchOne(string $sql, array $parameters): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetch();
    }

    private function fetchColumn(string $sql, array $parameters)
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchColumn();
    }
}
