<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_admin();

/**
 * @param list<string> $headers
 * @param list<array> $rows
 * @param list<int> $moneyColumns
 * @param list<int> $dateColumns
 */
function report_send_excel(
    string $reportType,
    string $reportTitle,
    string $reportDescription,
    string $headlineLabel,
    string $headlineValue,
    string $headlineHelp,
    array $headers,
    array $rows,
    array $moneyColumns,
    array $dateColumns,
    ?int $statusColumn
): void {
    require_once ROOT_PATH . '/vendor/autoload.php';

    $writer = \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createXLSXWriter();
    $titleStyle = (new \OpenSpout\Writer\Common\Creator\Style\StyleBuilder())
        ->setFontBold()
        ->setFontSize(16)
        ->setFontColor('176B4D')
        ->build();
    $noteStyle = (new \OpenSpout\Writer\Common\Creator\Style\StyleBuilder())
        ->setFontColor('52665B')
        ->setShouldWrapText()
        ->build();
    $headerStyle = (new \OpenSpout\Writer\Common\Creator\Style\StyleBuilder())
        ->setFontBold()
        ->setFontColor('FFFFFF')
        ->setBackgroundColor('176B4D')
        ->setShouldWrapText()
        ->build();
    $headlineStyle = (new \OpenSpout\Writer\Common\Creator\Style\StyleBuilder())
        ->setFontBold()
        ->setBackgroundColor('EAF4EE')
        ->setShouldWrapText()
        ->build();
    $wrapTextStyle = (new \OpenSpout\Writer\Common\Creator\Style\StyleBuilder())
        ->setShouldWrapText()
        ->build();
    $moneyStyle = (new \OpenSpout\Writer\Common\Creator\Style\StyleBuilder())
        ->setFormat('&quot;KES&quot; #,##0.00')
        ->build();
    $dateStyle = (new \OpenSpout\Writer\Common\Creator\Style\StyleBuilder())
        ->setFormat('dd mmm yyyy')
        ->build();
    $countStyle = (new \OpenSpout\Writer\Common\Creator\Style\StyleBuilder())
        ->setFormat('#,##0')
        ->build();

    $columnCount = count($headers);
    $writer->setShouldUseInlineStrings(true);
    $writer->mergeCells([0, 1], [$columnCount - 1, 1]);
    $writer->mergeCells([0, 2], [$columnCount - 1, 2]);
    $writer->mergeCells([0, 3], [$columnCount - 1, 3]);
    $writer->mergeCells([0, 5], [$columnCount - 1, 5]);

    $temporaryFile = tempnam(sys_get_temp_dir(), 'chamawise-report-');
    if ($temporaryFile === false) {
        throw new RuntimeException('Could not create a temporary Excel report file.');
    }

    $writerOpened = false;
    try {
        $writer->openToFile($temporaryFile);
        $writerOpened = true;
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Report');
        $sheet->setSheetView((new \OpenSpout\Writer\XLSX\Entity\SheetView())->setFreezeRow(8));

        $columnWidths = array_map(static fn (string $header): int => min(42, max(14, mb_strlen($header) + 3)), $headers);
        foreach ($rows as $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $columnWidths[$columnIndex] = min(42, max($columnWidths[$columnIndex], mb_strlen((string) ($value ?? '')) + 2));
            }
        }
        foreach ($columnWidths as $index => $width) {
            $writer->setColumnWidth((float) $width, $index + 1);
        }

        $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray([$reportTitle], $titleStyle));
        $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray([$reportDescription], $noteStyle));
        $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray(['Generated: ' . date('d M Y, H:i')], $noteStyle));
        $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray(['']));
        $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray([
            $headlineLabel . ': ' . $headlineValue . ' — ' . $headlineHelp,
        ], $headlineStyle));
        $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray(['']));
        $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRowFromArray($headers, $headerStyle));

        foreach ($rows as $row) {
            $cells = [];
            foreach (array_values($row) as $columnIndex => $value) {
                $displayText = (string) ($value ?? '');
                $cellStyle = $wrapTextStyle;
                if (in_array($columnIndex, $moneyColumns, true)) {
                    $value = (float) $value;
                    $cellStyle = $moneyStyle;
                } elseif (in_array($columnIndex, $dateColumns, true) && $displayText !== '') {
                    $value = new DateTimeImmutable($displayText);
                    $cellStyle = $dateStyle;
                } elseif ($reportType === 'summary' && $columnIndex === 1 && preg_match('/^KES\s+([\d,]+(?:\.\d{1,2})?)$/', $displayText, $matches) === 1) {
                    $value = (float) str_replace(',', '', $matches[1]);
                    $cellStyle = $moneyStyle;
                } elseif ($reportType === 'summary' && $columnIndex === 1 && is_numeric($value)) {
                    $value = (int) $value;
                    $cellStyle = $countStyle;
                } elseif ($columnIndex === $statusColumn) {
                    $value = ucwords(str_replace(['_', '-'], ' ', $displayText));
                }
                $cells[] = \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createCell($value, $cellStyle);
            }
            $writer->addRow(\OpenSpout\Writer\Common\Creator\WriterEntityFactory::createRow($cells));
        }

        $writer->close();
        $writerOpened = false;
    } catch (Throwable $exception) {
        if ($writerOpened) {
            $writer->close();
        }
        unlink($temporaryFile);
        throw $exception;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $reportType . '-report.xlsx"');
    header('Content-Length: ' . (string) filesize($temporaryFile));
    readfile($temporaryFile);
    unlink($temporaryFile);
    exit;
}

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
$reportDescription = '';
$moneyColumns = [];
$dateColumns = [];
$statusColumn = null;
$headlineLabel = 'Records in this report';
$headlineValue = '0';
$headlineHelp = 'All matching records are listed below, newest first.';

if ($reportType === 'contributions') {
    $reportDescription = 'Each row is a contribution entry for a member. Only entries marked Recorded are included in the recorded-contributions total.';
    $headers = ['Member', 'Amount', 'Contribution date', 'Paid using', 'Reference', 'Notes', 'Status'];
    $rows = db()->query("SELECT m.name, c.amount, c.contribution_date, COALESCE(pm.name, 'Not specified'), COALESCE(c.reference, '-'), COALESCE(c.notes, '-'), c.status FROM contributions c INNER JOIN members m ON m.member_id = c.member_id LEFT JOIN payment_methods pm ON pm.payment_method_id = c.payment_method_id ORDER BY c.contribution_date DESC")->fetchAll(PDO::FETCH_NUM);
    $moneyColumns = [1];
    $dateColumns = [2];
    $statusColumn = 6;
    $recordedTotal = array_sum(array_map(
        static fn (array $row): float => strtolower((string) $row[6]) === 'recorded' ? (float) $row[1] : 0.0,
        $rows
    ));
    $headlineLabel = 'Recorded contributions';
    $headlineValue = 'KES ' . number_format($recordedTotal, 2);
    $headlineHelp = 'Total of entries with Recorded status; other statuses are excluded.';
} elseif ($reportType === 'loans') {
    $reportDescription = 'Each row is one loan application. Requested is what the member asked for; approved is what was granted; outstanding is the current unpaid balance.';
    $headers = ['Member', 'Amount requested', 'Amount approved', 'Still owed', 'Loan status', 'Application date'];
    $rows = db()->query("SELECT m.name, l.requested_amount, COALESCE(l.approved_amount, 0), COALESCE(l.outstanding_balance, 0), l.status, l.application_date FROM loans l INNER JOIN members m ON m.member_id = l.member_id ORDER BY l.application_date DESC")->fetchAll(PDO::FETCH_NUM);
    $moneyColumns = [1, 2, 3];
    $dateColumns = [5];
    $statusColumn = 4;
    $headlineValue = 'KES ' . number_format(array_sum(array_map(
        static fn (array $row): float => in_array(strtolower((string) $row[4]), ['approved', 'overdue'], true) ? (float) $row[3] : 0.0,
        $rows
    )), 2);
    $headlineLabel = 'Current outstanding loan balance';
    $headlineHelp = 'Outstanding balances for approved or overdue loans; this is not the original amount borrowed.';
} elseif ($reportType === 'repayments') {
    $reportDescription = 'Each row is a repayment linked to a member and loan. The loan number identifies the loan account, not the member.';
    $headers = ['Member', 'Loan number', 'Amount repaid', 'Repayment date', 'Paid using', 'Reference'];
    $rows = db()->query("SELECT m.name, r.loan_id, r.amount, r.payment_date, COALESCE(pm.name, 'Not specified'), COALESCE(r.reference, '-') FROM loan_repayments r INNER JOIN loans l ON l.loan_id = r.loan_id INNER JOIN members m ON m.member_id = l.member_id LEFT JOIN payment_methods pm ON pm.payment_method_id = r.payment_method_id ORDER BY r.payment_date DESC")->fetchAll(PDO::FETCH_NUM);
    $moneyColumns = [2];
    $dateColumns = [3];
    $headlineLabel = 'Total repayments recorded';
    $headlineValue = 'KES ' . number_format(array_sum(array_map(static fn (array $row): float => (float) $row[2], $rows)), 2);
    $headlineHelp = 'Sum of repayment entries listed below.';
} elseif ($reportType === 'penalties') {
    $reportDescription = 'Each row is a penalty assigned to a member. Outstanding means it has not yet been cleared; check the status before following up.';
    $headers = ['Member', 'Penalty amount', 'Reason', 'Penalty date', 'Status'];
    $rows = db()->query("SELECT m.name, p.amount, p.reason, p.penalty_date, p.status FROM penalties p INNER JOIN members m ON m.member_id = p.member_id ORDER BY p.penalty_date DESC")->fetchAll(PDO::FETCH_NUM);
    $moneyColumns = [1];
    $dateColumns = [3];
    $statusColumn = 4;
    $headlineLabel = 'Outstanding penalties';
    $headlineValue = 'KES ' . number_format(array_sum(array_map(
        static fn (array $row): float => strtolower((string) $row[4]) === 'outstanding' ? (float) $row[1] : 0.0,
        $rows
    )), 2);
    $headlineHelp = 'Total of penalties currently marked Outstanding.';
} else {
    $reportDescription = 'A snapshot of current totals from ChamaWise records. These figures are not a bank balance or a statement of cash held.';
    $headers = ['Financial measure', 'Current value', 'What this means'];
    $rows = [
        ['All members', (int) db()->query('SELECT COUNT(*) FROM members')->fetchColumn(), 'Every member account, regardless of status.'],
        ['Active members', (int) db()->query("SELECT COUNT(*) FROM members WHERE status = 'active'")->fetchColumn(), 'Member accounts currently marked active.'],
        ['Recorded contributions', 'KES ' . number_format((float) db()->query("SELECT COALESCE(SUM(amount), 0) FROM contributions WHERE status = 'recorded'")->fetchColumn(), 2), 'Contributions with Recorded status only.'],
        ['Loan applications', (int) db()->query('SELECT COUNT(*) FROM loans')->fetchColumn(), 'All loan applications, including pending and declined applications.'],
        ['Outstanding loan balances', 'KES ' . number_format((float) db()->query("SELECT COALESCE(SUM(outstanding_balance), 0) FROM loans WHERE status IN ('approved', 'overdue')")->fetchColumn(), 2), 'Unpaid balances on approved or overdue loans.'],
        ['Repayments recorded', 'KES ' . number_format((float) db()->query('SELECT COALESCE(SUM(amount), 0) FROM loan_repayments')->fetchColumn(), 2), 'Sum of repayment entries currently in the repayment ledger.'],
        ['Outstanding penalties', 'KES ' . number_format((float) db()->query("SELECT COALESCE(SUM(amount), 0) FROM penalties WHERE status = 'outstanding'")->fetchColumn(), 2), 'Penalties currently marked Outstanding; cleared penalties are excluded.'],
    ];
    $headlineLabel = 'How to read this summary';
    $headlineValue = 'Current totals';
    $headlineHelp = 'Each measure below explains which records are included.';
}

if ($format === 'xlsx') {
    report_send_excel(
        $reportType,
        $reportTitles[$reportType],
        $reportDescription,
        $headlineLabel,
        $headlineValue,
        $headlineHelp,
        $headers,
        $rows,
        $moneyColumns,
        $dateColumns,
        $statusColumn
    );
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
        <div class="admin-heading"><div><p class="eyebrow mb-2">Treasury Reporting</p><h1><?php echo e($reportTitles[$reportType]); ?></h1><p>Generated from current ChamaWise records. These reports cover all available dates; no date filter is applied.</p></div><a class="btn btn-brand" href="<?php echo e(url('admin/dashboard.php')); ?>">&#8592; Dashboard</a></div>
        <article class="admin-panel report-controls"><form method="get"><label>Report Type<select name="report"><option value="contributions" <?php echo $reportType === 'contributions' ? 'selected' : ''; ?>>Contribution Report</option><option value="loans" <?php echo $reportType === 'loans' ? 'selected' : ''; ?>>Loan Report</option><option value="repayments" <?php echo $reportType === 'repayments' ? 'selected' : ''; ?>>Repayment Report</option><option value="penalties" <?php echo $reportType === 'penalties' ? 'selected' : ''; ?>>Penalty Report</option><option value="summary" <?php echo $reportType === 'summary' ? 'selected' : ''; ?>>Financial Summary</option></select></label><button class="btn btn-brand" type="submit">View Report</button><a class="btn btn-brand" href="<?php echo e(url('admin/reports.php?report=' . $reportType . '&format=xlsx')); ?>">Download Excel (.xlsx)</a><a class="btn btn-outline-brand" href="<?php echo e(url('admin/reports.php?report=' . $reportType . '&format=csv')); ?>">Download CSV</a></form></article>
        <article class="admin-panel report-explainer"><div><h2><?php echo e($headlineLabel); ?></h2><strong><?php echo e($headlineValue); ?></strong></div><p><?php echo e($reportDescription); ?> <?php echo e($headlineHelp); ?></p></article>
        <article class="admin-panel table-panel report-output"><div class="report-print-heading"><h2><?php echo e($reportTitles[$reportType]); ?></h2><p>Generated <?php echo e(date('d M Y, H:i')); ?></p></div><table><thead><tr><?php foreach ($headers as $header): ?><th><?php echo e($header); ?></th><?php endforeach; ?></tr></thead><tbody><?php if ($rows): ?><?php foreach ($rows as $row): ?><tr><?php foreach ($row as $index => $value): ?><?php
            $displayValue = (string) $value;
            if (in_array($index, $moneyColumns, true)) {
                $displayValue = 'KES ' . number_format((float) $value, 2);
            } elseif (in_array($index, $dateColumns, true) && $value !== null && $value !== '') {
                $timestamp = strtotime((string) $value);
                if ($timestamp !== false) {
                    $displayValue = date('d M Y', $timestamp);
                }
            } elseif ($index === $statusColumn) {
                $displayValue = ucwords(str_replace(['_', '-'], ' ', $displayValue));
            }
            ?><td><?php echo e($displayValue); ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php else: ?><tr><td colspan="<?php echo count($headers); ?>">No records available for this report.</td></tr><?php endif; ?></tbody></table></article>
    </section>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
