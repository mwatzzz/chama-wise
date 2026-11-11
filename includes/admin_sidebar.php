<?php

declare(strict_types=1);

$adminPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$adminActivePage = $adminPage === 'member.php' ? 'members.php' : $adminPage;
$adminNavigation = [
    ['dashboard.php', 'admin/dashboard.php', '&#8962;', 'Dashboard'],
    ['sms.php', 'admin/sms.php', '&#9993;', 'SMS Centre'],
    ['members.php', 'admin/members.php', '&#128101;', 'Members'],
    ['contributions.php', 'admin/contributions.php', '&#9776;', 'Contributions'],
    ['loans.php', 'admin/loans.php', '&#9633;', 'Loans'],
    ['repayments.php', 'admin/repayments.php', '&#8646;', 'Repayments'],
    ['penalties.php', 'admin/penalties.php', '&#9888;', 'Penalties'],
    ['meetings.php', 'admin/meetings.php', '&#9635;', 'Meetings'],
    ['reports.php', 'admin/reports.php', '&#9646;', 'Reports'],
    ['investments.php', 'admin/investments.php', '&#9679;', 'Investments'],
    ['settings.php', 'admin/settings.php', '&#9881;', 'Financial Settings'],
    ['intelligence.php', 'admin/intelligence.php', '&#10022;', 'ChamaWise Intelligence'],
];
?>
<aside class="admin-sidebar">
    <nav aria-label="Admin navigation">
        <?php foreach ($adminNavigation as [$page, $path, $icon, $label]): ?>
            <a class="admin-nav-link<?php echo $adminActivePage === $page ? ' active' : ''; ?>"
               href="<?php echo e(url($path)); ?>"<?php echo $adminActivePage === $page ? ' aria-current="page"' : ''; ?>>
                <span aria-hidden="true"><?php echo $icon; ?></span>
                <?php echo e($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <form method="post" action="<?php echo e(url('auth/logout.php')); ?>">
        <?php echo csrf_field(); ?>
        <button class="admin-nav-link admin-logout" type="submit"><span aria-hidden="true">&#10132;</span> Logout</button>
    </form>
</aside>
