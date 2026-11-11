<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/includes/sms.php';

final class SmsTest extends TestCase
{
    private array $originalMobilesasaEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['MOBILESASA_API_TOKEN', 'MOBILESASA_SENDER_ID', 'SMS_PROVIDER'] as $name) {
            $this->originalMobilesasaEnvironment[$name] = [
                'isset' => array_key_exists($name, $_ENV),
                'value' => $_ENV[$name] ?? null,
                'processValue' => getenv($name),
            ];
        }

        $_ENV['MOBILESASA_API_TOKEN'] = 'test-token-never-used-for-network';
        $_ENV['MOBILESASA_SENDER_ID'] = 'TEST-SENDER';
        $_ENV['SMS_PROVIDER'] = 'mobilesasa';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalMobilesasaEnvironment as $name => $environment) {
            if ($environment['isset']) {
                $_ENV[$name] = $environment['value'];
            } else {
                unset($_ENV[$name]);
            }

            if ($environment['processValue'] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $environment['processValue']);
            }
        }

        parent::tearDown();
    }

    public function testAcceptedResponseUsesMobileSasaRequestAndOnlyConfirmsProcessing(): void
    {
        $request = null;
        $result = send_mobilesasa_sms('+254712345678', 'Test SMS', static function (
            string $url,
            array $headers,
            string $body
        ) use (&$request): array {
            $request = ['url' => $url, 'headers' => $headers, 'body' => $body];

            return [
                'response' => '{"status":true,"responseCode":"0200","message":"Accepted"}',
                'http_code' => 200,
            ];
        });

        self::assertSame('https://api.mobilesasa.com/v1/send/message', $request['url']);
        self::assertContains('Authorization: Bearer test-token-never-used-for-network', $request['headers']);
        self::assertContains('Content-Type: application/json', $request['headers']);
        self::assertSame([
            'senderID' => 'TEST-SENDER',
            'phone' => '+254712345678',
            'message' => 'Test SMS',
        ], json_decode($request['body'], true));
        self::assertTrue($result['success']);
        self::assertSame('mobilesasa', $result['provider']);
        self::assertSame('SMS accepted by MobileSasa for processing.', $result['message']);
        self::assertStringNotContainsString('delivered', strtolower($result['message']));
    }

    public function testInsufficientBalanceIsReportedWithoutProviderText(): void
    {
        $result = $this->sendMockResponse('{"status":false,"responseCode":"0403","message":"Insufficient balance"}', 403);

        self::assertFalse($result['success']);
        self::assertSame('MobileSasa account has insufficient SMS balance.', $result['message']);
    }

    public function testInvalidPhoneNumberIsReportedSafely(): void
    {
        $result = $this->sendMockResponse('{"status":false,"responseCode":"0400","message":"Invalid phone number"}', 400);

        self::assertFalse($result['success']);
        self::assertSame('MobileSasa rejected the phone number as invalid.', $result['message']);
    }

    public function testAuthenticationAndNetworkErrorsDoNotExposeTokensOrTransportDetails(): void
    {
        $authenticationResult = $this->sendMockResponse('{"message":"Invalid API token"}', 401);
        $networkResult = $this->sendMockResponse(false, 0, true);

        self::assertFalse($authenticationResult['success']);
        self::assertStringNotContainsString('test-token-never-used-for-network', $authenticationResult['message']);
        self::assertSame('MobileSasa authentication failed or account access was denied.', $authenticationResult['message']);
        self::assertSame('MobileSasa network request failed.', $networkResult['message']);
    }

    public function testMalformedResponseAndServerFailureAreHandledSafely(): void
    {
        $malformedResult = $this->sendMockResponse('not-json', 200);
        $serverFailureResult = $this->sendMockResponse('{"message":"Internal error"}', 503);

        self::assertSame('MobileSasa returned a malformed response.', $malformedResult['message']);
        self::assertSame('MobileSasa service is temporarily unavailable.', $serverFailureResult['message']);
    }

    public function testHttpSuccessWithoutMobileSasaAcceptanceCodeIsNotReportedAsAccepted(): void
    {
        $result = $this->sendMockResponse('{"status":true,"responseCode":"0201","message":"Queued"}', 200);

        self::assertFalse($result['success']);
        self::assertSame('MobileSasa returned an unexpected response.', $result['message']);
    }

    public function testConfiguredProviderUsesMobileSasaWithoutMakingNetworkRequestsWhenUnconfigured(): void
    {
        unset($_ENV['MOBILESASA_API_TOKEN']);

        $result = send_sms('0712345678', 'Test SMS');

        self::assertFalse($result['success']);
        self::assertSame('mobilesasa', $result['provider']);
        self::assertSame('MobileSasa API token is not configured.', $result['message']);
        self::assertSame('+254712345678', normalize_sms_phone('0712345678'));
    }

    public function testIdenticalMobileSasaBatchUsesBulkEndpointAndKeepsAcceptedStatus(): void
    {
        $request = null;
        $result = send_sms_batch([
            ['phone' => '0712345678', 'message' => 'Meeting at 5pm'],
            ['phone' => '+254722222222', 'message' => 'Meeting at 5pm'],
        ], null, null, static function (string $url, array $headers, string $body) use (&$request): array {
            $request = ['url' => $url, 'headers' => $headers, 'body' => $body];
            return ['response' => '{"status":true,"responseCode":"0200","bulkId":"bulk-123"}', 'http_code' => 200];
        }, 'campaign-track-1');

        self::assertSame('https://api.mobilesasa.com/v2/send/bulk', $request['url']);
        self::assertSame([
            'senderID' => 'TEST-SENDER',
            'phones' => '+254712345678,+254722222222',
            'message' => 'Meeting at 5pm',
            'trackingId' => 'campaign-track-1',
        ], json_decode($request['body'], true));
        self::assertTrue($result['success']);
        self::assertSame('accepted', $result['status']);
        self::assertSame('bulk-123', $result['bulk_id']);
        self::assertSame('campaign-track-1', $result['tracking_id']);
        self::assertCount(2, $result['results']);
        self::assertSame('accepted', $result['results'][0]['status']);
    }

    public function testPersonalizedMobileSasaBatchUsesPersonalizedEndpoint(): void
    {
        $request = null;
        send_sms_batch([
            ['phone' => '0712345678', 'message' => 'Hello Amina'],
            ['phone' => '0722222222', 'message' => 'Hello Baraka'],
        ], null, 'mobilesasa', static function (string $url, array $headers, string $body) use (&$request): array {
            $request = ['url' => $url, 'body' => $body];
            return ['response' => '{"status":true,"responseCode":"0200","bulkId":"bulk-personalized"}', 'http_code' => 200];
        }, 'campaign-track-2');

        self::assertSame('https://api.mobilesasa.com/v2/send/bulk-personalized', $request['url']);
        self::assertSame([
            ['phone' => '+254712345678', 'message' => 'Hello Amina'],
            ['phone' => '+254722222222', 'message' => 'Hello Baraka'],
        ], json_decode($request['body'], true)['messageBody']);
        self::assertSame('campaign-track-2', json_decode($request['body'], true)['trackingId']);
    }

    public function testBatchProviderFailureAndAmbiguousTimeoutAreNotRetriedOrMarkedDelivered(): void
    {
        $failed = send_sms_batch(
            [['phone' => '0712345678', 'message' => 'Test']],
            null,
            'mobilesasa',
            static fn (): array => ['response' => '{"status":false,"responseCode":"0403","message":"Insufficient balance"}', 'http_code' => 403],
            'campaign-track-3'
        );
        $uncertain = send_sms_batch(
            [['phone' => '0712345678', 'message' => 'Test']],
            null,
            'mobilesasa',
            static fn (): array => ['response' => false, 'http_code' => 0, 'network_error' => true],
            'campaign-track-4'
        );

        self::assertFalse($failed['success']);
        self::assertSame('failed', $failed['status']);
        self::assertSame('MobileSasa account has insufficient SMS balance.', $failed['message']);
        self::assertFalse($uncertain['success']);
        self::assertSame('uncertain', $uncertain['status']);
        self::assertStringNotContainsString('test-token-never-used-for-network', $uncertain['message']);
    }

    private function sendMockResponse(string|false $response, int $httpCode, bool $networkError = false): array
    {
        return send_mobilesasa_sms('+254712345678', 'Test SMS', static function () use (
            $response,
            $httpCode,
            $networkError
        ): array {
            return [
                'response' => $response,
                'http_code' => $httpCode,
                'network_error' => $networkError,
            ];
        });
    }
}
