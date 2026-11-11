<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/daraja.php';

use PHPUnit\Framework\TestCase;

final class DarajaStatusTest extends TestCase
{
    public function testFailureCodesMapToMemberFacingStatuses(): void
    {
        self::assertSame('cancelled', daraja_failure_status('1032'));
        self::assertSame('timed_out', daraja_failure_status('1037'));
        self::assertSame('failed', daraja_failure_status('1'));
    }

    public function testStatusLabelsDistinguishInitiatingFromCustomerApproval(): void
    {
        self::assertSame(
            'Initiating / awaiting Daraja response',
            daraja_payment_status_label('pending', false)
        );
        self::assertSame(
            'Pending customer approval / verification',
            daraja_payment_status_label('pending', true)
        );
        self::assertSame('Review required', daraja_payment_status_label('review_required', true));
    }

    public function testStatusClassesKeepUnconfirmedPaymentsWarningColored(): void
    {
        self::assertSame('success', daraja_payment_status_class('successful'));
        self::assertSame('danger', daraja_payment_status_class('cancelled'));
        self::assertSame('warning', daraja_payment_status_class('pending'));
        self::assertSame('warning', daraja_payment_status_class('review_required'));
    }
}
