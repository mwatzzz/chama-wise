<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/sms_centre.php';

final class SmsCentreTest extends TestCase
{
    private PDO $pdo;
    private int $adminId;
    private array $memberIds = [];
    private array $environment = [];
    private array $campaignIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = db();
        foreach ([
            'SMS_PROVIDER',
            'MOBILESASA_API_TOKEN',
            'MOBILESASA_SENDER_ID',
            'MOBILESASA_TRIAL_MODE',
            'MOBILESASA_TRIAL_PHONE',
        ] as $key) {
            $this->environment[$key] = [
                'isset' => array_key_exists($key, $_ENV),
                'value' => $_ENV[$key] ?? null,
                'process' => getenv($key),
            ];
        }
        $_ENV['SMS_PROVIDER'] = 'mobilesasa';
        $_ENV['MOBILESASA_API_TOKEN'] = 'sms-centre-test-token';
        $_ENV['MOBILESASA_SENDER_ID'] = 'TEST-SENDER';
        $_ENV['MOBILESASA_TRIAL_MODE'] = 'false';
        unset($_ENV['MOBILESASA_TRIAL_PHONE']);

        $admin = $this->pdo->prepare(
            "INSERT INTO admins (name, email, password_hash) VALUES ('SMS Centre Test Admin', :email, 'test-hash')"
        );
        $admin->execute(['email' => bin2hex(random_bytes(12)) . '@example.invalid']);
        $this->adminId = (int) $this->pdo->lastInsertId();
        $this->memberIds[] = $this->insertMember('SMS Test Member One');
        $this->memberIds[] = $this->insertMember('SMS Test Member Two');
    }

    protected function tearDown(): void
    {
        if ($this->campaignIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->campaignIds), '?'));
            $delete = $this->pdo->prepare("DELETE FROM sms_campaigns WHERE campaign_id IN ($placeholders)");
            $delete->execute($this->campaignIds);
        }
        if ($this->memberIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->memberIds), '?'));
            $delete = $this->pdo->prepare("DELETE FROM members WHERE member_id IN ($placeholders)");
            $delete->execute($this->memberIds);
        }
        $this->pdo->prepare('DELETE FROM admins WHERE admin_id = :admin_id')->execute(['admin_id' => $this->adminId]);

        foreach ($this->environment as $key => $environment) {
            if ($environment['isset']) {
                $_ENV[$key] = $environment['value'];
            } else {
                unset($_ENV[$key]);
            }
            if ($environment['process'] === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $environment['process']);
            }
        }
        parent::tearDown();
    }

    public function testCampaignBatchStoresTrackingAndBulkIdsAndMatchesEachRecipientIndependently(): void
    {
        $campaignId = $this->createCampaign();
        $this->campaignIds[] = $campaignId;
        $batch = $this->batchFor($campaignId);
        self::assertNotEmpty($batch['tracking_id']);

        $result = sms_centre_process_batch(
            (int) $batch['batch_id'],
            $this->adminId,
            static fn (string $url, array $headers, string $body): array => [
                'response' => '{"status":true,"responseCode":"0200","bulkId":"test-bulk-1"}',
                'http_code' => 200,
            ],
            $this->pdo
        );
        self::assertSame('accepted', $result['status']);
        self::assertSame('test-bulk-1', $this->batchFor($campaignId)['bulk_id']);

        foreach ($this->phonesFor($campaignId) as $phone) {
            $report = [
                'reference' => 'test-bulk-1',
                'trackingId' => $batch['tracking_id'],
                'msisdn' => substr($phone, 1),
                'status' => 'Delivered',
                'deliveryTime' => '2025-01-01 10:00:00',
            ];
            self::assertTrue(sms_centre_apply_delivery_report($report, $this->pdo)['updated']);
            $duplicate = sms_centre_apply_delivery_report($report, $this->pdo);
            self::assertTrue($duplicate['matched']);
            self::assertFalse($duplicate['updated']);
        }

        $statusStmt = $this->pdo->prepare(
            'SELECT status FROM sms_campaign_recipients WHERE campaign_id = :campaign_id ORDER BY recipient_id'
        );
        $statusStmt->execute(['campaign_id' => $campaignId]);
        self::assertSame(['delivered', 'delivered'], array_column($statusStmt->fetchAll(PDO::FETCH_ASSOC), 'status'));
    }

    public function testRecipientSelectedBeforeErasureCannotBeLockedIntoANewCampaign(): void
    {
        $memberId = $this->memberIds[0];
        $prepared = sms_centre_prepare_campaign_recipients([
            'audience' => 'individual',
            'member_ids' => [$memberId],
            'template' => 'general_announcement',
            'message' => 'TEST DATA scheduled notice',
        ], $this->pdo);
        self::assertCount(1, $prepared['recipients']);

        // Model the interleaving where erasure commits after recipient selection
        // but before campaign insertion acquires the member row lock.
        $this->pdo->prepare("UPDATE members SET status = 'erased' WHERE member_id = :member_id")
            ->execute(['member_id' => $memberId]);
        $this->pdo->beginTransaction();
        try {
            sms_centre_lock_active_recipients($this->pdo, $prepared['recipients']);
            self::fail('A recipient erased after selection must not be added to a campaign.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('no longer active', $exception->getMessage());
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }

        $recipientCount = $this->pdo->prepare(
            'SELECT COUNT(*) FROM sms_campaign_recipients WHERE member_id = :member_id'
        );
        $recipientCount->execute(['member_id' => $memberId]);
        self::assertSame(0, (int) $recipientCount->fetchColumn());
    }

    public function testHashedPhoneDeliveryReportRemainsUnmatchedForManualReconciliation(): void
    {
        $campaignId = $this->createCampaign();
        $this->campaignIds[] = $campaignId;
        $batch = $this->batchFor($campaignId);
        sms_centre_process_batch(
            (int) $batch['batch_id'],
            $this->adminId,
            static fn (string $url, array $headers, string $body): array => [
                'response' => '{"status":true,"responseCode":"0200","bulkId":"test-bulk-hash"}',
                'http_code' => 200,
            ],
            $this->pdo
        );

        $result = sms_centre_apply_delivery_report([
            'reference' => 'test-bulk-hash',
            'trackingId' => $batch['tracking_id'],
            'msisdn' => str_repeat('a', 64),
            'status' => 'Delivered',
        ], $this->pdo);

        self::assertFalse($result['matched']);
        self::assertSame('hashed_phone_manual_reconciliation', $result['reason']);
        $statusStmt = $this->pdo->prepare('SELECT status FROM sms_campaign_recipients WHERE campaign_id = :campaign_id LIMIT 1');
        $statusStmt->execute(['campaign_id' => $campaignId]);
        self::assertSame('accepted', $statusStmt->fetchColumn());
    }

    public function testPartialDeliveryFailureIsRecordedPerRecipient(): void
    {
        $campaignId = $this->createCampaign();
        $this->campaignIds[] = $campaignId;
        $batch = $this->batchFor($campaignId);
        sms_centre_process_batch(
            (int) $batch['batch_id'],
            $this->adminId,
            static fn (string $url, array $headers, string $body): array => [
                'response' => '{"status":true,"responseCode":"0200","bulkId":"test-bulk-partial"}',
                'http_code' => 200,
            ],
            $this->pdo
        );
        $phones = $this->phonesFor($campaignId);

        $delivered = sms_centre_apply_delivery_report([
            'reference' => 'test-bulk-partial',
            'trackingId' => $batch['tracking_id'],
            'msisdn' => substr($phones[0], 1),
            'status' => 'Delivered',
        ], $this->pdo);
        $failed = sms_centre_apply_delivery_report([
            'reference' => 'test-bulk-partial',
            'trackingId' => $batch['tracking_id'],
            'msisdn' => substr($phones[1], 1),
            'status' => 'Failed',
            'deliveryStatus' => 'Rejected by network',
        ], $this->pdo);

        self::assertTrue($delivered['updated']);
        self::assertTrue($failed['updated']);
        $statusStmt = $this->pdo->prepare(
            'SELECT status FROM sms_campaign_recipients WHERE campaign_id = :campaign_id ORDER BY recipient_id'
        );
        $statusStmt->execute(['campaign_id' => $campaignId]);
        self::assertSame(['delivered', 'failed'], array_column($statusStmt->fetchAll(PDO::FETCH_ASSOC), 'status'));
    }

    public function testAmbiguousNetworkTimeoutIsMarkedUncertainAndCannotBeRetried(): void
    {
        $campaignId = $this->createCampaign();
        $this->campaignIds[] = $campaignId;
        $batch = $this->batchFor($campaignId);
        $result = sms_centre_process_batch(
            (int) $batch['batch_id'],
            $this->adminId,
            static fn (string $url, array $headers, string $body): array => ['response' => false, 'http_code' => 0, 'network_error' => true],
            $this->pdo
        );

        self::assertSame('uncertain', $result['status']);
        $this->expectException(DomainException::class);
        sms_centre_process_batch(
            (int) $batch['batch_id'],
            $this->adminId,
            static fn (string $url, array $headers, string $body): array => self::fail('An uncertain batch must not be sent again.'),
            $this->pdo
        );
    }

    public function testUnresolvablePlaceholdersAreRejectedBeforeCampaignCreation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        sms_centre_prepare_campaign_recipients([
            'audience' => 'selected',
            'member_ids' => $this->memberIds,
            'template' => 'custom',
            'message' => 'Hello {meeting_venue}',
        ], $this->pdo);
    }

    public function testMemberPlaceholderIsRenderedIndividuallyBeforeCampaignCreation(): void
    {
        $prepared = sms_centre_prepare_campaign_recipients([
            'audience' => 'selected',
            'member_ids' => $this->memberIds,
            'template' => 'custom',
            'message' => 'Hello {member_name}.',
        ], $this->pdo);

        self::assertCount(2, $prepared['recipients']);
        self::assertSame(
            ['Hello SMS Test Member One.', 'Hello SMS Test Member Two.'],
            array_column($prepared['recipients'], 'message')
        );
    }

    private function createCampaign(): int
    {
        $id = sms_centre_create_campaign([
            'audience' => 'selected',
            'member_ids' => $this->memberIds,
            'template' => 'general_announcement',
            'message' => 'Chama meeting moved to Friday.',
            'confirmed' => true,
            'submission_key' => bin2hex(random_bytes(32)),
        ], $this->adminId, $this->pdo);
        return $id;
    }

    private function insertMember(string $name): int
    {
        $phone = '+2547' . (string) random_int(10000000, 99999999);
        $stmt = $this->pdo->prepare(
            "INSERT INTO members (name, phone, email, password_hash, status, joined_date)
             VALUES (:name, :phone, :email, 'test-hash', 'active', CURDATE())"
        );
        $stmt->execute([
            'name' => $name,
            'phone' => $phone,
            'email' => bin2hex(random_bytes(12)) . '@example.invalid',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function batchFor(int $campaignId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sms_campaign_batches WHERE campaign_id = :campaign_id LIMIT 1');
        $stmt->execute(['campaign_id' => $campaignId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function phonesFor(int $campaignId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT normalized_phone FROM sms_campaign_recipients WHERE campaign_id = :campaign_id ORDER BY recipient_id'
        );
        $stmt->execute(['campaign_id' => $campaignId]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'normalized_phone');
    }
}
