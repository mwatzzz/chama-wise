<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/sms_centre.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sms_api_respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    sms_api_respond(['success' => false, 'message' => 'The request could not be verified.'], 403);
}

if (!sms_centre_tables_available()) {
    sms_api_respond(['success' => false, 'message' => 'Apply the reviewed SMS Centre migration before using campaigns.'], 503);
}

$action = (string) ($_POST['action'] ?? '');
$adminId = (int) (current_user()['user_id'] ?? 0);

try {
    if ($action === 'preview') {
        $prepared = sms_centre_prepare_campaign_recipients([
            'audience' => (string) ($_POST['audience'] ?? ''),
            'member_ids' => (array) ($_POST['member_ids'] ?? []),
            'template' => (string) ($_POST['template'] ?? ''),
            'message' => (string) ($_POST['message'] ?? ''),
            'meeting_id' => (int) ($_POST['meeting_id'] ?? 0),
        ]);
        $previewRecipients = [];
        foreach (array_slice($prepared['recipients'], 0, 5) as $recipient) {
            $previewRecipients[] = [
                'name' => $recipient['name'],
                'phone' => sms_centre_mask_phone($recipient['phone']),
                'message' => $recipient['message'],
            ];
        }
        sms_api_respond([
            'success' => true,
            'recipient_count' => count($prepared['recipients']),
            'preview' => $previewRecipients,
            'audience' => $prepared['audience'],
            'is_everyone' => $prepared['audience'] === 'all',
        ]);
    }

    if ($action === 'create_campaign') {
        $campaignId = sms_centre_create_campaign([
            'audience' => (string) ($_POST['audience'] ?? ''),
            'member_ids' => (array) ($_POST['member_ids'] ?? []),
            'template' => (string) ($_POST['template'] ?? ''),
            'message' => (string) ($_POST['message'] ?? ''),
            'meeting_id' => (int) ($_POST['meeting_id'] ?? 0),
            'confirmed' => (string) ($_POST['confirmed'] ?? '') === '1',
            'submission_key' => (string) ($_POST['submission_key'] ?? ''),
        ], $adminId);
        sms_api_respond(['success' => true, 'campaign_id' => $campaignId]);
    }

    if ($action === 'process_batch') {
        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        if ($batchId === false || $batchId < 1) {
            sms_api_respond(['success' => false, 'message' => 'Choose a valid campaign batch.'], 422);
        }
        $result = sms_centre_process_batch((int) $batchId, $adminId);
        sms_api_respond([
            'success' => true,
            'message' => (string) ($result['message'] ?? 'Batch processing completed.'),
            'status' => (string) ($result['status'] ?? 'uncertain'),
            'campaign_id' => (int) ($_POST['campaign_id'] ?? 0),
        ]);
    }

    if ($action === 'campaign_progress') {
        $campaignId = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT);
        if ($campaignId === false || $campaignId < 1) {
            sms_api_respond(['success' => false, 'message' => 'Choose a valid campaign.'], 422);
        }
        sms_api_respond(['success' => true] + sms_centre_campaign_progress((int) $campaignId, $adminId));
    }

    if ($action === 'reconcile_batch') {
        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        if ($batchId === false || $batchId < 1) {
            sms_api_respond(['success' => false, 'message' => 'Choose a valid campaign batch.'], 422);
        }
        $result = sms_centre_reconcile_batch(
            (int) $batchId,
            $adminId,
            (string) ($_POST['confirmed'] ?? '') === '1'
        );
        sms_api_respond(['success' => true, 'updated' => $result['updated'], 'unmatched' => $result['unmatched']]);
    }

    sms_api_respond(['success' => false, 'message' => 'Unsupported SMS Centre action.'], 400);
} catch (InvalidArgumentException $exception) {
    sms_api_respond(['success' => false, 'message' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    sms_api_respond(['success' => false, 'message' => $exception->getMessage()], 409);
} catch (RuntimeException $exception) {
    sms_api_respond(['success' => false, 'message' => $exception->getMessage()], 503);
} catch (Throwable $exception) {
    error_log('SMS Centre request failed.');
    sms_api_respond(['success' => false, 'message' => 'The SMS Centre request could not be completed.'], 500);
}
