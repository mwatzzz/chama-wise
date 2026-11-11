<?php

declare(strict_types=1);

$memberPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$memberActivePage = $memberPage === 'loan.php' ? 'loans.php' : $memberPage;
$memberNavigation = [
    ['dashboard.php', 'member/dashboard.php', '&#8962;', 'Dashboard'],
    ['contributions.php', 'member/contributions.php', '&#9776;', 'Contributions'],
    ['loans.php', 'member/loans.php', '&#9633;', 'Loans'],
    ['repayments.php', 'member/repayments.php', '&#8646;', 'Loan Repayment'],
    ['penalties.php', 'member/penalties.php', '&#9888;', 'Penalties'],
    ['meetings.php', 'member/meetings.php', '&#9635;', 'Meetings'],
    ['investments.php', 'member/investments.php', '&#9679;', 'Investments'],
    ['ai_advisor.php', 'member/ai_advisor.php', '&#10022;', 'AI Advisor'],
    ['profile.php', 'member/profile.php', '&#9679;', 'Profile'],
];
?>
<aside class="member-sidebar">
    <nav aria-label="Member navigation">
        <?php foreach ($memberNavigation as [$page, $path, $icon, $label]): ?>
            <a class="member-nav-link<?php echo $memberActivePage === $page ? ' active' : ''; ?>"
               href="<?php echo e(url($path)); ?>"<?php echo $memberActivePage === $page ? ' aria-current="page"' : ''; ?>>
                <span aria-hidden="true"><?php echo $icon; ?></span>
                <?php echo e($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <form method="post" action="<?php echo e(url('auth/logout.php')); ?>">
        <?php echo csrf_field(); ?>
        <button class="member-nav-link member-logout" type="submit"><span aria-hidden="true">&#10132;</span> Logout</button>
    </form>
</aside>
