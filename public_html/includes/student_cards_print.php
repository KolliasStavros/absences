<?php
// This template is included only after the authenticated roster query.
if (!isset($studentsList,$activeYearId,$grades,$sections)) { http_response_code(404); exit; }
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$gradeLabel='Όλες οι τάξεις';
foreach ($grades as $grade) if ((int)$grade['id']===$filterGradeId) $gradeLabel=$grade['name'];
$sectionLabel='Όλα τα τμήματα';
foreach ($sections as $section) if ((int)$section['id']===$filterSectionId) $sectionLabel=$section['name'];
$riskLabels=['all'=>'Όλες οι κατηγορίες','with_absences'=>'Με απουσίες','safe'=>'Εντός ορίου (<60)','warning'=>'Προειδοποίηση (60–113)','danger'=>'Όριο ≥114','expulsions'=>'Με αποβολές'];
$totalHours=array_sum(array_column($studentsList,'total_absences'));
$issuedAt=(new DateTimeImmutable('now',new DateTimeZone('Europe/Athens')))->format('d/m/Y H:i');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Συγκεντρωτική αναφορά απουσιών — <?= $escape($schoolName) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; background: #e9edf2; font: 10pt Arial, sans-serif; }
        .print-controls { max-width: 277mm; margin: 16px auto; padding: 0 12px; display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
        .print-controls button { padding: 10px 16px; color: #fff; background: #4338ca; border: 0; border-radius: 6px; font: inherit; cursor: pointer; }
        .report { width: calc(100% - 24px); max-width: 277mm; margin: 0 auto 20px; padding: 10mm; background: #fff; }
        h1 { margin: 4px 0 8px; font-size: 16pt; }
        .school { margin: 0 0 4px; font-size: 12pt; font-weight: bold; }
        .metadata, .filters, .summary { margin: 6px 0; line-height: 1.45; overflow-wrap: anywhere; }
        .summary { margin: 10px 0 12px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 6px 5px; border: 1px solid #777; vertical-align: top; white-space: normal; overflow-wrap: anywhere; }
        th { background: #eee; text-align: left; font-size: 9pt; }
        td { font-size: 9.5pt; line-height: 1.3; }
        .number { text-align: center; }
        .empty { text-align: center; padding: 18px; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; page-break-inside: avoid; }
        @page { size: A4 landscape; margin: 10mm; }
        @media print {
            body { background: #fff; }
            .print-controls { display: none !important; }
            .report { width: 100%; max-width: none; margin: 0; padding: 0; }
            h1 { break-after: avoid; }
            th { color: #000; background: #eee; }
        }
    </style>
</head>
<body>
    <div class="print-controls">
        <button type="button" onclick="window.print()">Εκτύπωση / Αποθήκευση PDF</button>
        <span>Α4 οριζόντια · Ετήσια στοιχεία σύμφωνα με τα φίλτρα της λίστας.</span>
    </div>
    <main class="report">
        <p class="school"><?= $escape($schoolName) ?></p>
        <h1>Συγκεντρωτική αναφορά απουσιών &amp; ορίων</h1>
        <p class="metadata">Σχολικό έτος: <?= $escape($activeAcademicYear['name'] ?? '') ?> · Έκδοση: <?= $escape($issuedAt) ?></p>
        <p class="filters">Τάξη: <?= $escape($gradeLabel) ?> · Τμήμα: <?= $escape($sectionLabel) ?> · Κατηγορία: <?= $escape($riskLabels[$riskFilter] ?? $riskLabels['all']) ?><?php if ($searchQuery !== ''): ?> · Αναζήτηση: <?= $escape($searchQuery) ?><?php endif; ?></p>
        <p class="summary">Μαθητές αναφοράς: <?= count($studentsList) ?> · Σύνολο ωρών απουσίας: <?= (int)$totalHours ?></p>
        <table aria-label="Ετήσιες απουσίες μαθητών">
            <colgroup><col style="width:9%"><col style="width:24%"><col style="width:17%"><col style="width:7%"><col style="width:10%"><col style="width:10%"><col style="width:8%"><col style="width:15%"></colgroup>
            <thead><tr><th>Α.Μ.</th><th>Ονοματεπώνυμο</th><th>Τμήμα</th><th class="number">Σύνολο</th><th class="number">Αδικαιο-<br>λόγητες</th><th class="number">Δικαιολο-<br>γημένες</th><th class="number">Αποβολές</th><th>Κατάσταση ορίου</th></tr></thead>
            <tbody>
            <?php if (!$studentsList): ?><tr><td colspan="8" class="empty">Δεν βρέθηκαν μαθητές με τα επιλεγμένα φίλτρα.</td></tr><?php endif; ?>
            <?php foreach ($studentsList as $student): ?>
                <?php $total=(int)$student['total_absences']; $status=$total>=114?'Όριο ≥114':($total>=60?'Προειδοποίηση (60–113)':'Εντός ορίου (<60)'); ?>
                <tr>
                    <td><?= $escape($student['am']) ?></td>
                    <td><?= $escape($student['last_name'].' '.$student['first_name']) ?></td>
                    <td><?= $escape($student['section_names'] ?: '—') ?></td>
                    <td class="number"><?= $total ?></td>
                    <td class="number"><?= (int)$student['unjustified'] ?></td>
                    <td class="number"><?= (int)$student['justified'] ?></td>
                    <td class="number"><?= (int)$student['expulsions'] ?></td>
                    <td><?= $escape($status) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </main>
</body>
</html>
