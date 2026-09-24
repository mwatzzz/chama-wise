<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

$reportType = (string) ($_GET['report'] ?? 'contributions');
$format = (string) ($_GET['format'] ?? 'html');
$allowedReports = ['contributions', 'loans', 'repayments', 'penalties', 'summary'];
if (!in_array($reportType, $allowedReports, true)) {
    $reportType = 'contributions';
}

$reportTitles = [
    'contributions' => 'Contribution Report',
    'loans' => 'Loan Report',
    'repayments' => 'Repayment Report',
    'penalties' => 'Penalty Report',
    'summary' => 'Financial Summary',
];
$headers = [];
$rows = [];

if ($reportType === 'contributions') {
    $headers = ['Member', 'Amount (KES)', 'Date', 'Payment Method', 'Reference', 'Notes', 'Status'];
    $rows = db()->query("SELECT m.name, c.amount, c.contribution_date, COALESCE(pm.name, 'Not specified'), COALESCE(c.reference, '-'), COALESCE(c.notes, '-'), c.status FROM contributions c INNER JOIN members m ON m.member_id = c.member_id LEFT JOIN payment_methods pm ON pm.payment_method_id = c.payment_method_id ORDER BY c.contribution_date DESC")->fetchAll(PDO::FETCH_NUM);
} elseif ($reportType === 'loans') {
    $headers = ['Member', 'Requested (KES)', 'Approved (KES)', 'Outstanding (KES)', 'Status', 'Application Date'];
    $rows = db()->query("SELECT m.name, l.requested_amount, COALESCE(l.approved_amount, 0), COALESCE(l.outstanding_balance, 0), l.status, l.application_date FROM loans l INNER JOIN members m ON m.member_id = l.member_id ORDER BY l.application_date DESC")->fetchAll(PDO::FETCH_NUM);
} elseif ($reportType === 'repayments') {
    $headers = ['Member', 'Loan ID', 'Amount (KES)', 'Date', 'Payment Method', 'Reference'];
    $rows = db()->query("SELECT m.name, r.loan_id, r.amount, r.payment_date, COALESCE(pm.name, 'Not specified'), COALESCE(r.reference, '-') FROM loan_repayments r INNER JOIN loans l ON l.loan_id = r.loan_id INNER JOIN members m ON m.member_id = l.member_id LEFT JOIN payment_methods pm ON pm.payment_method_id = r.payment_method_id ORDER BY r.payment_date DESC")->fetchAll(PDO::FETCH_NUM);
} elseif ($reportType === 'penalties') {
    $headers = ['Member', 'Amount (KES)', 'Reason', 'Date', 'Status'];
    $rows = db()->query("SELECT m.name, p.amount, p.reason, p.penalty_date, p.status FROM penalties p INNER JOIN members m ON m.member_id = p.member_id ORDER BY p.penalty_date DESC")->fetchAll(PDO::FETCH_NUM);
} else {
    $headers = ['Metric', 'Value'];
    $rows = [
        ['Total Members', db()->query('SELECT COUNT(*) FROM members')->fetchColumn()],
        ['Active Members', db()->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn()],
        ['Total Contributions (KES)', db()->query('SELECT COALESCE(SUM(amount), 0) FROM contributions')->fetchColumn()],
        ['Total Loans', db()->query('SELECT COUNT(*) FROM loans')->fetchColumn()],
        ['Outstanding Loans (KES)', db()->query("SELECT COALESCE(SUM(outstanding_balance), 0) FROM loans WHERE status IN ('approved', 'overdue')")->fetchColumn()],
        ['Total Repayments (KES)', db()->query('SELECT COALESCE(SUM(amount), 0) FROM loan_repayments')->fetchColumn()],
        ['Outstanding Penalties (KES)', db()->query("SELECT COALESCE(SUM(amount), 0) FROM penalties WHERE status = 'outstanding'")->fetchColumn()],
    ];
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $reportType . '-report.csv"');
    $output = fopen('php://output', 'wb');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

$pageTitle = $reportTitles[$reportType] . ' | Admin Dashboard';
$navType = 'admin';
$bodyClass = 'dashboard-body';
require ROOT_PATH . '/includes/header.php';
?>
<div class="admin-layout">
    <aside class="admin-sidebar"><nav aria-label="Admin navigation">
        <a class="admin-nav-link" href="<?php echo e(url('admin/dashboard.php')); ?>"><span>&#8962;</span> Dashboard</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/members.php')); ?>"><span>&#128101;</span> Members</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/contributions.php')); ?>"><span>&#9776;</span> Contributions</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/loans.php')); ?>"><span>&#9633;</span> Loans</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/repayments.php')); ?>"><span>&#8646;</span> Repayments</a>
        <a class="admin-nav-link" href="<?php echo e(url('admin/meetings.php')); ?>"><span>&#9635;</span> Meetings</a>
        <a class="admin-nav-link active" href="<?php echo e(url('admin/reports.php')); ?>"><span>&#9646;</span> Reports</a>
    </nav><a class="admin-nav-link admin-logout" href="<?php echo e(url('auth/logout.php')); ?>"><span>&#10132;</span> Logout</a></aside>
    <section class="admin-content">
        <div class="admin-heading"><div><p class="eyebrow mb-2">Treasury Reporting</p><h1><?php echo e($reportTitles[$reportType]); ?></h1><p>Generate reports from current ChamaWise database records.</p></div><a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a></div>
        <article class="admin-panel report-controls"><form method="get"><label>Report Type<select name="report"><option value="contributions" <?php echo $reportType === 'contributions' ? 'selected' : ''; ?>>Contribution Report</option><option value="loans" <?php echo $reportType === 'loans' ? 'selected' : ''; ?>>Loan Report</option><option value="repayments" <?php echo $reportType === 'repayments' ? 'selected' : ''; ?>>Repayment Report</option><option value="penalties" <?php echo $reportType === 'penalties' ? 'selected' : ''; ?>>Penalty Report</option><option value="summary" <?php echo $reportType === 'summary' ? 'selected' : ''; ?>>Financial Summary</option></select></label><button class="btn btn-brand" type="submit">View Report</button><a class="btn btn-outline-brand" href="<?php echo e(url('admin/reports.php?report=' . $reportType . '&format=csv')); ?>">Download CSV</a></form></article>
        <article class="admin-panel table-panel report-output"><div class="report-print-heading"><h2><?php echo e($reportTitles[$reportType]); ?></h2><p>Generated <?php echo e(date('Y-m-d H:i')); ?></p></div><table><thead><tr><?php foreach ($headers as $header): ?><th><?php echo e($header); ?></th><?php endforeach; ?></tr></thead><tbody><?php if ($rows): ?><?php foreach ($rows as $row): ?><tr><?php foreach ($row as $value): ?><td><?php echo e((string) $value); ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php else: ?><tr><td colspan="<?php echo count($headers); ?>">No records available for this report.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
