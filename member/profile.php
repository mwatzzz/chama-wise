<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$user = current_user();
$profilePicture = null;
$membershipStatus = 'Active';
$contributionTotal = 0.0;
$outstandingLoanBalance = 0.0;
$loanCount = 0;
$nextDueDay = (int) financial_setting('contribution_due_day');

try {
    db()->exec('ALTER TABLE members ADD COLUMN profile_picture VARCHAR(255) NULL');
} catch (PDOException $exception) {
    // The column already exists on databases imported from the current schema.
}

try {
    $stmt = db()->prepare('SELECT member_id, name, phone, email, status, joined_date, profile_picture FROM members WHERE member_id = :member_id LIMIT 1');
    $stmt->execute(['member_id' => $user['user_id']]);
    $member = $stmt->fetch() ?: [];
    $profilePicture = $member['profile_picture'] ?? null;
    $membershipStatus = ucfirst((string) ($member['status'] ?? 'active'));
} catch (PDOException $exception) {
    $stmt = db()->prepare('SELECT member_id, name, phone, email, joined_date FROM members WHERE member_id = :member_id LIMIT 1');
    $stmt->execute(['member_id' => $user['user_id']]);
    $member = $stmt->fetch() ?: [];
}

if (!empty($member)) {
    $contributionStmt = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM contributions WHERE member_id = :member_id AND status = 'recorded'");
    $contributionStmt->execute(['member_id' => $user['user_id']]);
    $contributionTotal = (float) ($contributionStmt->fetchColumn() ?? 0);

    $loanStmt = db()->prepare('SELECT COUNT(*), COALESCE(SUM(CASE WHEN outstanding_balance IS NOT NULL THEN outstanding_balance ELSE approved_amount END), 0) FROM loans WHERE member_id = :member_id AND status NOT IN (\'rejected\', \'cancelled\')');
    $loanStmt->execute(['member_id' => $user['user_id']]);
    $loanRow = $loanStmt->fetch();
    $loanCount = (int) ($loanRow[0] ?? 0);
    $outstandingLoanBalance = (float) ($loanRow[1] ?? 0);
}

$pageTitle = 'My Profile | ' . APP_NAME;
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="member-layout">
    <?php require ROOT_PATH . '/includes/member_sidebar.php'; ?>

    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span aria-hidden="true">&#8250;</span> Profile</div>
        <div class="member-heading">
            <div>
                <h1>Karibu, <?php echo e($member['name'] ?? $user['name']); ?>.</h1>
                <p>View and manage your personal information, membership details and preferences.</p>
            </div>
            <a class="btn btn-outline-brand" href="#profile-picture">Manage Profile Picture</a>
        </div>

        <article class="profile-summary">
            <?php if ($profilePicture): ?>
                <img class="profile-avatar" src="<?php echo e(url('member/profile_picture.php')); ?>" alt="Profile picture">
            <?php else: ?>
                <div class="profile-avatar" aria-hidden="true">&#9679;</div>
            <?php endif; ?>
            <div class="profile-identity">
                <div class="profile-name-row">
                    <h2><?php echo e($member['name'] ?? $user['name']); ?></h2>
                    <span class="status-badge"><?php echo e($membershipStatus); ?> Member</span>
                </div>
                <p>&#128100; <?php echo e($member['name'] ?? $user['name']); ?></p>
                <p>&#9993; <?php echo e($member['email'] ?? $user['email']); ?></p>
                <p>&#9742; <?php echo e($member['phone'] ?? 'Phone not added'); ?></p>
            </div>
            <div class="profile-meta">
                <p><strong>&#128197;</strong> Joined<br><span><?php echo e((string) ($member['joined_date'] ?? 'Not available')); ?></span></p>
                <p><strong>#</strong> Member ID<br><span><?php echo e((string) ($user['user_id'] ?? '')); ?></span></p>
            </div>
        </article>

        <div class="profile-tabs" role="tablist" aria-label="Profile sections">
            <button class="profile-tab active" type="button" data-profile-tab="personal" aria-selected="true">Personal Information</button>
            <button class="profile-tab" type="button" data-profile-tab="membership" aria-selected="false">Membership Details</button>
            <button class="profile-tab" type="button" data-profile-tab="preferences" aria-selected="false">Preferences</button>
            <button class="profile-tab" type="button" data-profile-tab="documents" aria-selected="false">Documents</button>
        </div>

        <div class="profile-grid">
            <article class="profile-panel profile-section active" data-profile-section="personal">
                <h2>Personal Information</h2>
                <div class="profile-fields">
                    <label>Full Name<input type="text" value="<?php echo e($member['name'] ?? $user['name']); ?>" readonly></label>
                    <label>Phone Number<input type="text" value="<?php echo e($member['phone'] ?? ''); ?>" readonly></label>
                    <label>Email Address<input type="email" value="<?php echo e($member['email'] ?? $user['email']); ?>" readonly></label>
                    <label>Joined Date<input type="text" value="<?php echo e((string) ($member['joined_date'] ?? '')); ?>" readonly></label>
                </div>
            </article>

            <article class="profile-panel profile-section" data-profile-section="membership" hidden>
                <h2>Membership Details</h2>
                <div class="profile-fields">
                    <label>Member ID<input type="text" value="<?php echo e((string) ($user['user_id'] ?? '')); ?>" readonly></label>
                    <label>Membership Status<input type="text" value="<?php echo e($membershipStatus); ?>" readonly></label>
                    <label>Total Contributions<input type="text" value="KES <?php echo e(number_format($contributionTotal, 2)); ?>" readonly></label>
                    <label>Next Due Date<input type="text" value="<?php echo e($nextDueDay . 'th of every month'); ?>" readonly></label>
                </div>
            </article>

            <article class="profile-panel profile-section" data-profile-section="preferences" hidden>
                <h2>Preferences</h2>
                <div class="profile-fields">
                    <label>Email Contact<input type="text" value="<?php echo e(!empty($member['email']) ? 'Available on your account' : 'Not provided'); ?>" readonly></label>
                    <label>Phone Contact<input type="text" value="<?php echo e(!empty($member['phone']) ? 'Available on your account' : 'Not provided'); ?>" readonly></label>
                    <label>Email Notification Settings<input type="text" value="Not configurable in this system" readonly></label>
                    <label>SMS Notification Settings<input type="text" value="Not configurable in this system" readonly></label>
                </div>
            </article>

            <article class="profile-panel profile-section" data-profile-section="documents" hidden>
                <h2>Documents</h2>
                <div class="profile-fields">
                    <label>Profile Photo<input type="text" value="<?php echo e($profilePicture ? 'Uploaded' : 'Not uploaded'); ?>" readonly></label>
                    <label>Membership Form<input type="text" value="Not available in this system" readonly></label>
                    <label>Loan Records<input type="text" value="<?php echo e($loanCount > 0 ? 'Available' : 'Not available'); ?>" readonly></label>
                    <label>Contribution Records<input type="text" value="<?php echo e($contributionTotal > 0 ? 'Available' : 'No records yet'); ?>" readonly></label>
                </div>
            </article>

            <div class="profile-side-panels">
                <article class="profile-panel" id="profile-picture">
                    <h2>Profile Picture</h2>
                    <?php if ($profilePicture): ?>
                        <img class="picture-placeholder profile-image" src="<?php echo e(url('member/profile_picture.php')); ?>" alt="Profile picture">
                    <?php else: ?>
                        <div class="picture-placeholder" aria-hidden="true">&#9679;</div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo e(url('member/upload_picture.php')); ?>" enctype="multipart/form-data" class="profile-upload-form">
                        <?php echo csrf_field(); ?>
                        <label class="btn btn-outline-brand profile-action" for="profile_picture">&#128247; Change Picture</label>
                        <input class="profile-file-input" type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/webp">
                        <span class="profile-file-name text-muted"></span>
                        <button class="btn btn-brand profile-action upload-submit" type="submit">Upload Picture</button>
                    </form>
                </article>
                <article class="profile-panel security-panel">
                    <h2>Account Security</h2>
                    <div class="setting-row">
                        <span>&#128274; Password</span>
                        <a class="btn btn-outline-brand btn-sm" href="<?php echo e(url('auth/forgot_password.php')); ?>">Reset Password</a>
                    </div>
                </article>
            </div>
        </div>
    </section>
</div>

<script nonce="<?php echo e(CSP_NONCE); ?>">
document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('.profile-tab');
    const panels = document.querySelectorAll('.profile-section');

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            const target = tab.dataset.profileTab;

            tabs.forEach((item) => {
                const isActive = item === tab;
                item.classList.toggle('active', isActive);
                item.setAttribute('aria-selected', String(isActive));
            });

            panels.forEach((panel) => {
                const isMatch = panel.dataset.profileSection === target;
                panel.hidden = !isMatch;
                panel.classList.toggle('active', isMatch);
            });
        });
    });
});
</script>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
