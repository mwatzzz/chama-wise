<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/includes/sms_centre.php';
require_admin();

$tablesAvailable = sms_centre_tables_available();
$members = db()->query(
    "SELECT member_id, name, phone
     FROM members
     WHERE status = 'active'
     ORDER BY name, member_id"
)->fetchAll(PDO::FETCH_ASSOC);
$meetings = db()->query(
    "SELECT meeting_id, title, meeting_date
     FROM meetings
     WHERE status = 'scheduled' AND meeting_date >= CURDATE()
     ORDER BY meeting_date, meeting_id"
)->fetchAll(PDO::FETCH_ASSOC);
$templates = sms_centre_templates();
$history = [];
$historyError = null;
if ($tablesAvailable) {
    $filters = [
        'date_from' => trim((string) ($_GET['date_from'] ?? '')),
        'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        'category' => trim((string) ($_GET['category'] ?? '')),
        'status' => trim((string) ($_GET['status'] ?? '')),
        'recipient' => trim((string) ($_GET['recipient'] ?? '')),
    ];
    $where = [];
    $params = [];
    if ($filters['date_from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
        $where[] = 'c.created_at >= :date_from';
        $params['date_from'] = $filters['date_from'] . ' 00:00:00';
    }
    if ($filters['date_to'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
        $where[] = 'c.created_at <= :date_to';
        $params['date_to'] = $filters['date_to'] . ' 23:59:59';
    }
    if ($filters['category'] !== '') {
        $where[] = 'c.category = :category';
        $params['category'] = $filters['category'];
    }
    if (in_array($filters['status'], ['pending', 'accepted', 'delivered', 'failed', 'uncertain'], true)) {
        $where[] = 'r.status = :status';
        $params['status'] = $filters['status'];
    }
    if ($filters['recipient'] !== '') {
        $where[] = 'r.recipient_name LIKE :recipient';
        $params['recipient'] = '%' . $filters['recipient'] . '%';
    }
    $sql = 'SELECT c.campaign_id, c.category, c.template_key, c.provider,
                   c.status AS campaign_status,
                   COALESCE(r.sent_at, c.created_at) AS sent_at,
                   r.recipient_name, r.normalized_phone, r.rendered_message, r.status,
                   r.safe_response_message, r.failure_reason, b.batch_id, b.tracking_id, b.bulk_id,
                   b.status AS batch_status,
                   (SELECT MIN(r0.recipient_id) FROM sms_campaign_recipients r0 WHERE r0.batch_id = b.batch_id) AS first_batch_recipient_id,
                   r.recipient_id
            FROM sms_campaign_recipients r
            INNER JOIN sms_campaigns c ON c.campaign_id = r.campaign_id
            LEFT JOIN sms_campaign_batches b ON b.batch_id = r.batch_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.created_at DESC, r.recipient_id DESC LIMIT 100';
    $historyStmt = db()->prepare($sql);
    $historyStmt->execute($params);
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'SMS Centre | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="admin-layout">
    <?php require ROOT_PATH . '/includes/admin_sidebar.php'; ?>
    <section class="admin-content sms-centre" data-api="<?php echo e(url('admin/sms_api.php')); ?>">
        <div class="admin-heading">
            <div>
                <p class="eyebrow mb-2">Member communications</p>
                <h1>SMS Centre</h1>
                <p>Prepare, confirm, send, and reconcile member messages.</p>
            </div>
            <span class="admin-overview-badge">Provider: <?php echo e(ucfirst((string) env('SMS_PROVIDER', 'log'))); ?></span>
        </div>

        <?php if (!$tablesAvailable): ?>
            <div class="alert alert-warning" role="alert">
                The SMS Centre database migration has not been applied. Campaign sending and history are unavailable until the migration is reviewed and applied.
            </div>
        <?php endif; ?>

        <?php if (strtolower((string) env('SMS_PROVIDER', 'log')) === 'mobilesasa'
            && filter_var(env('MOBILESASA_TRIAL_MODE', 'true'), FILTER_VALIDATE_BOOLEAN)): ?>
            <div class="alert alert-warning" role="alert">
                MobileSasa trial mode is enabled. Campaign recipients are restricted to the configured registered trial phone until full sending access is confirmed.
            </div>
        <?php endif; ?>

        <div class="sms-centre-grid">
            <article class="admin-panel sms-compose-panel">
                <div class="admin-panel-title">
                    <h2>Compose a message</h2>
                    <a class="btn btn-outline-brand btn-sm" href="<?php echo e(url('admin/dashboard.php')); ?>#test-sms">Test SMS</a>
                </div>
                <form id="sms-compose-form">
                    <?php echo csrf_field(); ?>
                    <label>Recipients
                        <select name="audience" id="sms-audience" required>
                            <option value="all">Everyone eligible (active members with valid numbers)</option>
                            <option value="individual">One member</option>
                            <option value="selected">Selected members</option>
                            <option value="outstanding_loans">Members with outstanding loans</option>
                            <option value="overdue_loans">Members with overdue loans</option>
                            <option value="missed_contributions">Members with missed contributions</option>
                            <option value="upcoming_due">Loans due within 30 days</option>
                        </select>
                    </label>
                    <label id="sms-members-wrap" class="sms-hidden">Select members
                        <select name="member_ids[]" id="sms-members" multiple size="7">
                            <?php foreach ($members as $member): ?>
                                <option value="<?php echo e((string) $member['member_id']); ?>">
                                    <?php echo e($member['name']); ?> (<?php echo e(sms_centre_mask_phone((string) $member['phone'])); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Template
                        <select name="template" id="sms-template" required>
                            <?php foreach ($templates as $key => $template): ?>
                                <option value="<?php echo e($key); ?>" data-template="<?php echo e($template['message']); ?>">
                                    <?php echo e($template['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label id="sms-meeting-wrap" class="sms-hidden">Meeting
                        <select name="meeting_id" id="sms-meeting">
                            <option value="">Next scheduled meeting</option>
                            <?php foreach ($meetings as $meeting): ?>
                                <option value="<?php echo e((string) $meeting['meeting_id']); ?>">
                                    <?php echo e($meeting['title'] . ' - ' . $meeting['meeting_date']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Message <span class="text-muted">Supported placeholders are filled per recipient.</span>
                        <textarea name="message" id="sms-message" rows="5" maxlength="1600" required><?php echo e($templates['meeting_announcement']['message']); ?></textarea>
                    </label>
                    <p class="sms-placeholder-help">Available where applicable: {member_name}, {meeting_title}, {meeting_date}, {contribution_amount}, {contribution_period}, {loan_amount}, {due_date}.</p>
                    <div class="sms-compose-actions">
                        <button class="btn btn-outline-brand" type="button" id="sms-preview-button" <?php echo !$tablesAvailable ? 'disabled' : ''; ?>>Preview recipients</button>
                        <button class="btn btn-brand" type="button" id="sms-send-button" disabled>Confirm and send</button>
                    </div>
                </form>
                <section id="sms-preview" class="sms-preview sms-hidden" aria-live="polite">
                    <h3>Preview and confirmation</h3>
                    <p id="sms-recipient-summary"></p>
                    <div id="sms-preview-list"></div>
                    <div class="sms-progress-wrap sms-hidden" id="sms-progress-wrap">
                        <div class="sms-progress-track"><div id="sms-progress-bar"></div></div>
                        <p id="sms-progress-label" aria-live="polite"></p>
                    </div>
                    <p id="sms-result-message" role="status"></p>
                </section>
            </article>
            <aside class="admin-panel sms-guidance">
                <h2>Sending safeguards</h2>
                <ul>
                    <li>Everyone mode includes active members with valid numbers only.</li>
                    <li>MobileSasa acceptance means queued for processing, not delivered.</li>
                    <li>Delivery reports update each matched recipient separately.</li>
                    <li>Ambiguous network results are not retried automatically.</li>
                    <li>Trial accounts may only send to the registered trial phone.</li>
                </ul>
            </aside>
        </div>

        <article class="admin-panel table-panel sms-history-panel">
            <div class="admin-panel-title"><h2>SMS history</h2><span class="text-muted">Latest 100 recipient records</span></div>
            <?php if (!$tablesAvailable): ?>
                <p>History will be available after the reviewed migration is applied.</p>
            <?php else: ?>
                <form method="get" class="sms-history-filters">
                    <label>From <input type="date" name="date_from" value="<?php echo e((string) ($_GET['date_from'] ?? '')); ?>"></label>
                    <label>To <input type="date" name="date_to" value="<?php echo e((string) ($_GET['date_to'] ?? '')); ?>"></label>
                    <label>Category
                        <select name="category">
                            <option value="">All</option>
                            <?php foreach (['meeting', 'contribution', 'loan', 'announcement', 'custom'] as $category): ?>
                                <option value="<?php echo e($category); ?>" <?php echo (($_GET['category'] ?? '') === $category) ? 'selected' : ''; ?>><?php echo e(ucfirst($category)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Status
                        <select name="status">
                            <option value="">All</option>
                            <?php foreach (['pending', 'accepted', 'delivered', 'failed', 'uncertain'] as $status): ?>
                                <option value="<?php echo e($status); ?>" <?php echo (($_GET['status'] ?? '') === $status) ? 'selected' : ''; ?>>
                                    <?php echo e($status === 'accepted' ? 'Pending delivery (accepted)' : ucfirst($status)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Recipient <input type="search" name="recipient" maxlength="120" value="<?php echo e((string) ($_GET['recipient'] ?? '')); ?>"></label>
                    <button class="btn btn-outline-brand" type="submit">Filter</button>
                </form>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>Recipient</th><th>Phone</th><th>Message</th><th>Category</th><th>Date</th><th>Status</th><th>Provider result</th><th>Recovery</th></tr></thead>
                        <tbody>
                        <?php if ($history): ?>
                            <?php foreach ($history as $item): ?>
                                <tr>
                                    <td><?php echo e($item['recipient_name']); ?></td>
                                    <td><?php echo e(sms_centre_mask_phone((string) $item['normalized_phone'])); ?></td>
                                    <td><?php echo e($item['rendered_message']); ?></td>
                                    <td><?php echo e(ucfirst($item['category'])); ?></td>
                                    <td><?php echo e((string) $item['sent_at']); ?></td>
                                    <?php
                                    $displayStatus = $item['batch_status'] === 'sending' && $item['status'] === 'pending'
                                        ? 'sending'
                                        : ($item['status'] === 'accepted' ? 'pending delivery' : ucfirst($item['status']));
                                    ?>
                                    <td><span class="sms-status sms-status-<?php echo e(str_replace(' ', '-', strtolower($displayStatus))); ?>"><?php echo e(ucfirst($displayStatus)); ?></span></td>
                                    <td><?php echo e((string) ($item['failure_reason'] ?: $item['safe_response_message'] ?: '-')); ?></td>
                                    <td>
                                        <?php if ($item['batch_id']
                                            && in_array($item['status'], ['accepted', 'uncertain', 'pending'], true)
                                            && in_array($item['batch_status'], ['accepted', 'uncertain', 'sending'], true)
                                            && $item['provider'] === 'mobilesasa'): ?>
                                            <button type="button" class="btn btn-outline-brand btn-sm sms-reconcile" data-batch="<?php echo e((string) $item['batch_id']); ?>" data-csrf="<?php echo e(csrf_token()); ?>">Check delivery</button>
                                        <?php elseif ($item['batch_id']
                                            && $item['recipient_id'] === $item['first_batch_recipient_id']
                                            && $item['batch_status'] === 'pending'
                                            && in_array($item['campaign_status'], ['pending', 'processing'], true)): ?>
                                            <button type="button" class="btn btn-outline-brand btn-sm sms-resume-batch" data-batch="<?php echo e((string) $item['batch_id']); ?>" data-campaign="<?php echo e((string) $item['campaign_id']); ?>" data-csrf="<?php echo e(csrf_token()); ?>">Send pending batch</button>
                                        <?php elseif ($item['status'] === 'uncertain' || ($item['batch_status'] === 'sending' && $item['status'] === 'pending')): ?>
                                            Manual review required
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="8">No SMS history matches these filters.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </article>
    </section>
</div>

<script nonce="<?php echo e(CSP_NONCE); ?>">
(() => {
    const root = document.querySelector('.sms-centre');
    const form = document.getElementById('sms-compose-form');
    if (!root || !form) return;
    const api = root.dataset.api;
    const audience = document.getElementById('sms-audience');
    const membersWrap = document.getElementById('sms-members-wrap');
    const membersSelect = document.getElementById('sms-members');
    const template = document.getElementById('sms-template');
    const message = document.getElementById('sms-message');
    const meetingWrap = document.getElementById('sms-meeting-wrap');
    const preview = document.getElementById('sms-preview');
    const previewButton = document.getElementById('sms-preview-button');
    const sendButton = document.getElementById('sms-send-button');
    const csrf = form.querySelector('[name="_csrf"]').value;
    let activeCampaign = null;

    const post = async (data, token = csrf) => {
        data._csrf = token;
        const body = new URLSearchParams();
        Object.entries(data).forEach(([key, value]) => {
            if (Array.isArray(value)) value.forEach((item) => body.append(key + '[]', item));
            else body.append(key, String(value));
        });
        const response = await fetch(api, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString(),
            credentials: 'same-origin'
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'SMS Centre request failed.');
        return result;
    };

    const selectedMemberIds = () => Array.from(membersSelect.selectedOptions).map((option) => option.value);
    const requestData = (action) => ({
        action,
        audience: audience.value,
        member_ids: selectedMemberIds(),
        template: template.value,
        message: message.value,
        meeting_id: document.getElementById('sms-meeting').value
    });
    const setAudienceFields = () => {
        membersWrap.classList.toggle('sms-hidden', !['individual', 'selected'].includes(audience.value));
        membersSelect.multiple = audience.value !== 'individual';
        meetingWrap.classList.toggle('sms-hidden', !['meeting_announcement', 'meeting_reminder'].includes(template.value));
    };
    const showError = (text) => {
        document.getElementById('sms-result-message').textContent = text;
        preview.classList.remove('sms-hidden');
    };
    const updateProgress = async (campaignId) => {
        const data = await post({action: 'campaign_progress', campaign_id: campaignId});
        const counts = data.counts;
        const total = Object.values(counts).reduce((sum, count) => sum + Number(count), 0);
        const finished = total - counts.pending;
        const progressWrap = document.getElementById('sms-progress-wrap');
        const progressBar = document.getElementById('sms-progress-bar');
        progressWrap.classList.remove('sms-hidden');
        progressBar.style.width = (total ? Math.round((finished / total) * 100) : 0) + '%';
        document.getElementById('sms-progress-label').textContent =
            `${finished}/${total} processed · ${counts.accepted} pending delivery · ${counts.delivered} delivered · ${counts.failed} failed · ${counts.uncertain} uncertain · ${counts.pending} not sent`;
        return data;
    };
    const processCampaign = async (campaignId) => {
        activeCampaign = campaignId;
        sendButton.disabled = true;
        previewButton.disabled = true;
        try {
            let progress = await updateProgress(campaignId);
            for (const batch of progress.batches) {
                if (batch.status !== 'pending') continue;
                await post({action: 'process_batch', campaign_id: campaignId, batch_id: batch.batch_id});
                progress = await updateProgress(campaignId);
            }
            const pending = progress.batches.some((batch) => batch.status === 'pending');
            document.getElementById('sms-result-message').textContent = pending
                ? 'Some batches remain pending. Reload campaign progress before retrying.'
                : 'Campaign batch processing finished. Accepted messages remain pending delivery confirmation.';
        } catch (error) {
            showError(error.message);
        } finally {
            sendButton.disabled = false;
            previewButton.disabled = false;
        }
    };

    audience.addEventListener('change', setAudienceFields);
    template.addEventListener('change', () => {
        const option = template.selectedOptions[0];
        const templateText = option.dataset.template || '';
        if (templateText) message.value = templateText;
        setAudienceFields();
    });
    setAudienceFields();

    previewButton.addEventListener('click', async () => {
        previewButton.disabled = true;
        sendButton.disabled = true;
        document.getElementById('sms-result-message').textContent = '';
        try {
            const result = await post(requestData('preview'));
            document.getElementById('sms-recipient-summary').textContent =
                result.is_everyone
                    ? `This message will go to all ${result.recipient_count} eligible active members.`
                    : `${result.recipient_count} eligible recipient(s).`;
            const list = document.getElementById('sms-preview-list');
            list.replaceChildren();
            result.preview.forEach((item) => {
                const block = document.createElement('div');
                block.className = 'sms-message-preview';
                const who = document.createElement('strong');
                who.textContent = `${item.name} (${item.phone})`;
                const text = document.createElement('p');
                text.textContent = item.message;
                block.append(who, text);
                list.append(block);
            });
            preview.classList.remove('sms-hidden');
            sendButton.disabled = false;
        } catch (error) {
            showError(error.message);
        } finally {
            previewButton.disabled = false;
        }
    });

    sendButton.addEventListener('click', async () => {
        if (!window.confirm('Create this campaign and begin sending the confirmed recipient list?')) return;
        const submissionKey = Array.from(crypto.getRandomValues(new Uint8Array(32)))
            .map((value) => value.toString(16).padStart(2, '0')).join('');
        sendButton.disabled = true;
        try {
            const result = await post({
                ...requestData('create_campaign'),
                confirmed: '1',
                submission_key: submissionKey
            });
            await processCampaign(result.campaign_id);
        } catch (error) {
            showError(error.message);
            sendButton.disabled = false;
        }
    });

    document.querySelectorAll('.sms-reconcile').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!window.confirm('Check MobileSasa delivery reports? Phone-hash resolution can incur a lookup charge.')) return;
            button.disabled = true;
            try {
                const result = await post({
                    action: 'reconcile_batch',
                    batch_id: button.dataset.batch,
                    confirmed: '1'
                }, button.dataset.csrf);
                window.alert(`Updated ${result.updated} recipient(s); ${result.unmatched} need manual reconciliation.`);
                window.location.reload();
            } catch (error) {
                window.alert(error.message);
                button.disabled = false;
            }
        });
    });

    document.querySelectorAll('.sms-resume-batch').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!window.confirm('Send this pending batch to the configured SMS provider?')) return;
            button.disabled = true;
            try {
                await post({
                    action: 'process_batch',
                    batch_id: button.dataset.batch,
                    campaign_id: button.dataset.campaign
                }, button.dataset.csrf);
                window.location.reload();
            } catch (error) {
                window.alert(error.message);
                button.disabled = false;
            }
        });
    });
})();
</script>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
