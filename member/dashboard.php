<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_member();

$user = current_user();
$profilePicture = null;
try {
    db()->exec('ALTER TABLE members ADD COLUMN profile_picture VARCHAR(255) NULL');
} catch (PDOException $exception) {
    // The column already exists on databases imported from the current schema.
}

try {
    $stmt = db()->prepare('SELECT name, phone, email, joined_date, profile_picture FROM members WHERE member_id = :member_id LIMIT 1');
    $stmt->execute(['member_id' => $user['user_id']]);
    $member = $stmt->fetch() ?: [];
    $profilePicture = $member['profile_picture'] ?? null;
} catch (PDOException $exception) {
    $stmt = db()->prepare('SELECT name, phone, email, joined_date FROM members WHERE member_id = :member_id LIMIT 1');
    $stmt->execute(['member_id' => $user['user_id']]);
    $member = $stmt->fetch() ?: [];
}

$pageTitle = 'Member Dashboard | ' . APP_NAME;
$navType = 'member';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>

<div class="member-layout">
    <aside class="member-sidebar">
        <nav aria-label="Member navigation">
            <a class="member-nav-link" href="<?php echo e(url('member/dashboard.php')); ?>"><span aria-hidden="true">&#8962;</span> Dashboard</a>
            <a class="member-nav-link" href="<?php echo e(url('member/contributions.php')); ?>"><span aria-hidden="true">&#9776;</span> Contributions</a>
            <a class="member-nav-link" href="<?php echo e(url('member/penalties.php')); ?>"><span aria-hidden="true">&#9888;</span> Penalties</a>
            <a class="member-nav-link" href="<?php echo e(url('member/loans.php')); ?>"><span aria-hidden="true">&#9633;</span> Loans</a>
            <a class="member-nav-link" href="<?php echo e(url('member/repayments.php')); ?>"><span aria-hidden="true">&#8646;</span> Loan Repayment</a>
            <a class="member-nav-link" href="#"><span aria-hidden="true">&#9635;</span> Meetings</a>
            <a class="member-nav-link" href="#"><span aria-hidden="true">&#10022;</span> AI Advisor</a>
            <a class="member-nav-link active" href="<?php echo e(url('member/dashboard.php')); ?>"><span aria-hidden="true">&#9679;</span> Profile</a>
        </nav>
        <a class="member-nav-link member-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span aria-hidden="true">&#10132;</span> Logout</a>
    </aside>

    <section class="member-content">
        <div class="member-breadcrumb">Dashboard <span aria-hidden="true">&#8250;</span> Profile</div>
        <div class="member-heading">
            <div>
                <h1>Karibu, <?php echo e($member['name'] ?? $user['name']); ?>.</h1>
                <p>View and manage your personal information, membership details and preferences.</p>
            </div>
            <button class="btn btn-outline-brand" type="button">&#9998; Edit Profile</button>
        </div>

        <article class="profile-summary">
            <?php if ($profilePicture): ?>
                <img class="profile-avatar" src="<?php echo e(url($profilePicture)); ?>" alt="Profile picture">
            <?php else: ?>
                <div class="profile-avatar" aria-hidden="true">&#9679;</div>
            <?php endif; ?>
            <div class="profile-identity">
                <div class="profile-name-row">
                    <h2><?php echo e($member['name'] ?? $user['name']); ?></h2>
                    <span class="status-badge">Active Member</span>
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
            <button class="profile-tab active" type="button">Personal Information</button>
            <button class="profile-tab" type="button">Membership Details</button>
            <button class="profile-tab" type="button">Preferences</button>
            <button class="profile-tab" type="button">Documents</button>
        </div>

        <div class="profile-grid">
            <article class="profile-panel personal-panel">
                <h2>Personal Information</h2>
                <div class="profile-fields">
                    <label>Full Name<input type="text" value="<?php echo e($member['name'] ?? $user['name']); ?>" readonly></label>
                    <label>Phone Number<input type="text" value="<?php echo e($member['phone'] ?? ''); ?>" readonly></label>
                    <label>Email Address<input type="email" value="<?php echo e($member['email'] ?? $user['email']); ?>" readonly></label>
                    <label>Joined Date<input type="text" value="<?php echo e((string) ($member['joined_date'] ?? '')); ?>" readonly></label>
                </div>
            </article>

            <div class="profile-side-panels">
                <article class="profile-panel">
                    <h2>Profile Picture</h2>
                    <?php if ($profilePicture): ?>
                        <img class="picture-placeholder profile-image" src="<?php echo e(url($profilePicture)); ?>" alt="Profile picture">
                    <?php else: ?>
                        <div class="picture-placeholder" aria-hidden="true">&#9679;</div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo e(url('member/upload_picture.php')); ?>" enctype="multipart/form-data" class="profile-upload-form">
                        <?php echo csrf_field(); ?>
                        <label class="btn btn-outline-brand profile-action" for="profile_picture">&#128247; Change Picture</label>
                        <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/webp" hidden required>
                        <button class="btn btn-brand profile-action upload-submit" type="submit">Upload Picture</button>
                    </form>
                </article>
                <article class="profile-panel security-panel">
                    <h2>Account Security</h2>
                    <div class="setting-row"><span>&#128274; Password</span><button type="button">Change</button></div>
                    <div class="setting-row"><span>&#9673; Two-factor authentication</span><span class="setting-toggle" aria-hidden="true"></span></div>
                </article>
                <article class="profile-panel security-panel">
                    <h2>Notification Preferences</h2>
                    <div class="setting-row"><span>&#9993; Email notifications</span><span class="setting-toggle on" aria-hidden="true"></span></div>
                    <div class="setting-row"><span>&#9742; SMS notifications</span><span class="setting-toggle" aria-hidden="true"></span></div>
                </article>
            </div>
        </div>
    </section>
</div>

<?php require ROOT_PATH . '/includes/footer.php'; ?>
