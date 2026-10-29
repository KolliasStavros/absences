<?php
// admin/student_cards.php - Student Absence Dossiers, Legal Limits (114 hours) & Batch Justification
$pageTitle = 'Καρτέλες Απουσιών & Όρια';
require_once __DIR__ . '/header.php';

define('ABSENCES_STUDENT_CARDS_CONTEXT', true);
require __DIR__.'/../includes/student_cards_controller.php';
?>

<link rel="stylesheet" href="../assets/css/ui-components.css?v=20261029-1">
<link rel="stylesheet" href="../assets/css/student-cards.css?v=20261029-2">
<div class="card absence-report-heading">
    <div class="absence-report-titlebar">
        <div>
            <h1 style="font-size:22px; font-weight:700; margin-bottom:4px; display:flex; align-items:center; gap:8px;">
                <span>📋</span> Καρτέλες Απουσιών & Παρακολούθηση Ορίων
            </h1>
            <p style="color:var(--text-muted); font-size:13.5px; margin:0;">
                Ετήσια συγκεντρωτική εικόνα απουσιών, έλεγχος υπέρβασης ορίου 114 ωρών (ΥΠΑΙΘ) και μαζική δικαιολόγηση.
            </p>
        </div>
    </div>
    <section class="absence-export-panel" aria-labelledby="absenceExportTitle">
        <h2 id="absenceExportTitle" class="absence-export-title">Εξαγωγές &amp; Εκτύπωση</h2>
            <form method="GET" action="student_cards.php" class="absence-export-form">
                <div>
                    <label for="exportFrom">Από ημερομηνία</label>
                    <input type="date" id="exportFrom" name="export_from" class="form-control" value="<?= htmlspecialchars($exportFromValue,ENT_QUOTES,'UTF-8') ?>" required>
                </div>
                <div>
                    <label for="exportTo">Έως ημερομηνία</label>
                    <input type="date" id="exportTo" name="export_to" class="form-control" value="<?= htmlspecialchars($exportToValue,ENT_QUOTES,'UTF-8') ?>" required>
                </div>
                <div class="absence-export-actions">
                    <button type="submit" name="export" value="xls" class="btn btn-primary">📥 Excel (.xls)</button>
                    <button type="submit" name="export" value="csv" class="btn btn-secondary">📥 CSV</button>
                    <a href="<?= htmlspecialchars($printUrl,ENT_QUOTES,'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-secondary absence-report-print" title="Προεπισκόπηση εκτύπωσης σε Α4 οριζόντια">🖨️ Εκτύπωση αναφοράς</a>
                </div>
            </form>
        <p class="absence-export-note"><b>Excel / CSV:</b> όλοι οι μαθητές, στο διάστημα που ορίζετε, για το ενεργό σχολικό έτος. Excel ανά ημέρα στο ίδιο φύλλο · CSV συγκεντρωτικά.<br><b>Εκτύπωση:</b> ετήσια στοιχεία με τα φίλτρα προβολής, χωρίς περιορισμό ημερομηνιών.</p>
    </section>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="padding:14px 18px; border-radius:10px; background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; margin-bottom:18px;">
        ✓ <?= htmlspecialchars($message) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger" style="padding:14px 18px; border-radius:10px; background:#fee2e2; color:#991b1b; border:1px solid #fecaca; margin-bottom:18px;">
        ⚠️ <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- Filters Bar -->
<section class="card filter-card absence-filter-panel no-print" aria-labelledby="absenceFilterTitle">
    <h2 id="absenceFilterTitle" class="absence-filter-title">Αναζήτηση &amp; Φίλτρα προβολής</h2>
    <form method="GET" action="student_cards.php" class="absence-filter-form">
        <div>
            <label for="absenceSearch">Αναζήτηση Μαθητή</label>
            <input type="search" id="absenceSearch" name="q" class="form-control" placeholder="Επώνυμο, Όνομα ή Α.Μ...." value="<?= htmlspecialchars($searchQuery) ?>">
        </div>
        <div>
            <label for="absenceGrade">Τάξη</label>
            <select id="absenceGrade" name="grade_id" class="form-control" onchange="this.form.elements.section_id.value=''; this.form.submit()">
                <option value="">Όλες οι Τάξεις</option>
                <?php foreach ($grades as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= $filterGradeId === (int)$g['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($g['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="absenceSection">Τμήμα</label>
            <select id="absenceSection" name="section_id" class="form-control" onchange="this.form.submit()">
                <option value="">Όλα τα Τμήματα</option>
                <?php foreach ($sections as $sec): ?>
                    <?php if ($filterGradeId > 0 && $sec['grade_id'] != $filterGradeId) continue; ?>
                    <option value="<?= $sec['id'] ?>" <?= $filterSectionId === (int)$sec['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sec['name']) ?> (<?= htmlspecialchars($sec['grade_name']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="absenceRisk">Φίλτρο Ορίου</label>
            <select id="absenceRisk" name="risk_filter" class="form-control" onchange="this.form.submit()">
                <option value="all" <?= $riskFilter === 'all' ? 'selected' : '' ?>>Όλοι οι Μαθητές</option>
                <option value="with_absences" <?= $riskFilter === 'with_absences' ? 'selected' : '' ?>>Με απουσίες</option>
                <option value="danger" <?= $riskFilter === 'danger' ? 'selected' : '' ?>>🚨 Κρίσιμο Όριο (&ge;114)</option>
                <option value="warning" <?= $riskFilter === 'warning' ? 'selected' : '' ?>>⚠️ Προειδοποίηση (60-113)</option>
                <option value="safe" <?= $riskFilter === 'safe' ? 'selected' : '' ?>>✓ Εντός Ορίου (&lt;60)</option>
                <option value="expulsions" <?= $riskFilter === 'expulsions' ? 'selected' : '' ?>>🛑 Με Αποβολές</option>
            </select>
        </div>
        <div class="absence-filter-actions">
            <button type="submit" class="btn btn-primary">Εφαρμογή φίλτρων</button>
        </div>
    </form>
    <div class="absence-filter-summary" role="status">
        <strong><?= count($studentsList) ?> μαθητές στην προβολή</strong>
        <?php if ($activeFilterLabels): ?>
            <span>Ενεργά φίλτρα:</span>
            <?php foreach ($activeFilterLabels as $filterLabel): ?>
                <span class="absence-filter-chip"><?= htmlspecialchars($filterLabel,ENT_QUOTES,'UTF-8') ?></span>
            <?php endforeach; ?>
        <?php else: ?>
            <span>Όλοι οι μαθητές · χωρίς φίλτρα προβολής</span>
        <?php endif; ?>
        <?php if ($filterGradeId || $filterSectionId || $riskFilter !== 'all' || $searchQuery): ?>
            <a href="student_cards.php" class="absence-filter-reset">✕ Καθαρισμός φίλτρων</a>
        <?php endif; ?>
    </div>
</section>

<!-- KPI Summary Cards -->
<div class="absence-kpis" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:20px;">
    <div class="card" style="padding:16px; border-left:4px solid var(--primary); text-align:center;">
        <div style="font-size:12px; font-weight:600; color:var(--text-muted); text-transform:uppercase;">Συνολικοι Μαθητες</div>
        <div style="font-size:26px; font-weight:700; color:var(--text-main); margin-top:4px;"><?= $statsTotalStudents ?></div>
        <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;"><?= $statsTotalAbsencesSum ?> σύνολο απουσιών</div>
    </div>
    <div class="card" style="padding:16px; border-left:4px solid var(--success); text-align:center;">
        <div style="font-size:12px; font-weight:600; color:#065f46; text-transform:uppercase;">Εντος Οριου (&lt;60)</div>
        <div style="font-size:26px; font-weight:700; color:#10b981; margin-top:4px;"><?= $statsSafeCount ?></div>
        <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">Ασφαλής φοίτηση</div>
    </div>
    <div class="card" style="padding:16px; border-left:4px solid var(--warning); text-align:center;">
        <div style="font-size:12px; font-weight:600; color:#92400e; text-transform:uppercase;">Προειδοποιηση (60-114)</div>
        <div style="font-size:26px; font-weight:700; color:#f59e0b; margin-top:4px;"><?= $statsWarningCount ?></div>
        <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">Απαιτείται ενημέρωση κηδεμόνα</div>
    </div>
    <div class="card" style="padding:16px; border-left:4px solid var(--danger); text-align:center;">
        <div style="font-size:12px; font-weight:600; color:#991b1b; text-transform:uppercase;">Υπερβαση Οριου (&gt;114)</div>
        <div style="font-size:26px; font-weight:700; color:#ef4444; margin-top:4px;"><?= $statsDangerCount ?></div>
        <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">Κίνδυνος ανεπαρκούς φοίτησης</div>
    </div>
</div>

<!-- Students Absence Cards Table -->
<div class="card" style="padding:0; overflow:hidden;">
    <div style="overflow-x:auto;">
        <table class="table-custom absence-cards-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="width:75px; text-align:center; padding-left:20px;">Α.Μ.</th>
                    <th>Μαθητής/τρια</th>
                    <th style="width:100px;">Τμήμα</th>
                    <th style="width:140px; text-align:center;">Σύνολο Απουσιών</th>
                    <th style="width:220px;">Κατάσταση Ορίου (114 ώρες)</th>
                    <th style="width:160px; text-align:center;">Ανάλυση</th>
                    <th style="width:110px; text-align:center;">Τελευταία</th>
                    <th style="width:210px; text-align:right; padding-right:22px;" class="no-print">Ενέργειες</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($studentsList)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:40px; color:var(--text-muted);">
                            <div style="font-size:32px; margin-bottom:8px;">🔍</div>
                            Δεν βρέθηκαν μαθητές με τα επιλεγμένα κριτήρια.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($studentsList as $s): ?>
                        <?php 
                            $tot = (int)$s['total_absences'];
                            $unjustified = (int)$s['unjustified'];
                            $justified = (int)$s['justified'];
                            $expulsions = (int)$s['expulsions'];
                            $percent = min(100, round(($tot / 114) * 100));

                            $barColor = '#10b981';
                            $statusBadge = '<span class="badge badge-success">Εντός Ορίου</span>';
                            if ($tot >= 114) {
                                $barColor = '#ef4444';
                                $statusBadge = '<span class="badge badge-danger">Κρίσιμο όριο (≥114)</span>';
                            } elseif ($tot >= 60) {
                                $barColor = '#f59e0b';
                                $statusBadge = '<span class="badge badge-warning">Προειδοποίηση (60–113)</span>';
                            }
                        ?>
                        <tr class="absence-student-row">
                            <td style="text-align:center; font-weight:700; color:var(--text-muted); font-size:12px; padding-left:20px;">
                                <?= htmlspecialchars($s['am']) ?>
                            </td>
                            <td>
                                <div class="absence-student-name">
                                    <?= htmlspecialchars($s['last_name'] . ' ' . $s['first_name']) ?>
                                    <span class="absence-student-am">Α.Μ. <?= htmlspecialchars($s['am']) ?></span>
                                </div>
                                <div class="absence-contacts">
                                    <?php if ($s['guardian_email']): ?>
                                        <span>✉️ <?= htmlspecialchars($s['guardian_email']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($s['guardian_mobile']): ?>
                                        <span style="margin-left:8px;">📞 <?= htmlspecialchars($s['guardian_mobile']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge absence-sections" style="font-size:11.5px;">
                                    <?= htmlspecialchars($s['section_names'] ?: '-') ?>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <div class="absence-total">
                                    <?= $tot ?>
                                </div>
                                <div style="font-size:11px; color:var(--text-muted);">ώρες</div>
                            </td>
                            <td>
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                    <?= $statusBadge ?>
                                    <span style="font-size:11px; font-weight:700; color:var(--text-muted);"><?= $percent ?>%</span>
                                </div>
                                <div style="background:var(--border); height:7px; border-radius:10px; overflow:hidden;">
                                    <div style="background:<?= $barColor ?>; width:<?= $percent ?>%; height:100%; border-radius:10px; transition:width 0.3s ease;"></div>
                                </div>
                            </td>
                            <td style="text-align:center; font-size:12px;">
                                <div class="absence-analysis">
                                    <span title="Αδικαιολόγητες" style="color:var(--text-muted); font-weight:600;"><?= $unjustified ?> αδ.</span>
                                    <span>&bull;</span>
                                    <span title="Δικαιολογημένες" style="color:#059669; font-weight:600;"><?= $justified ?> δικ.</span>
                                    <?php if ($expulsions > 0): ?>
                                        <span>&bull;</span>
                                        <span title="Αποβολές" style="color:#b45309; font-weight:700;"><?= $expulsions ?> αποβ.</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="text-align:center; font-size:12px; color:var(--text-muted);">
                                <?= $s['last_absence_date'] ? date('d/m/Y', strtotime($s['last_absence_date'])) : '-' ?>
                            </td>
                            <td style="text-align:right; padding-right:22px;" class="no-print">
                                <div class="absence-row-actions">
                                    <?php if (canJustifyAbsences()): ?>
                                    <button 
                                        type="button" 
                                        class="btn btn-secondary btn-sm" 
                                        onclick="openJustifyModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['last_name'] . ' ' . $s['first_name'])) ?>')"
                                        title="Δικαιολόγηση Ημέρας">
                                        ⚖️ Δικαιολόγηση
                                    </button>
                                    <?php endif; ?>
                                    <button 
                                        type="button" 
                                        class="btn btn-secondary btn-sm" 
                                        onclick="openDetailsModal(<?= $s['id'] ?>)"
                                        aria-label="Αναλυτικό Ιστορικό" title="Αναλυτικό Ιστορικό">
                                        👁️ Ιστορικό
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal 1: Batch Justification Modal -->
<?php if (canJustifyAbsences()): ?>
<div id="justifyModal" class="modal app-dialog" role="dialog" aria-modal="true" aria-labelledby="justifyStudentTitle">
    <div class="modal-content app-dialog-panel">
        <div class="app-dialog-heading">
            <h3 class="app-dialog-title" id="justifyStudentTitle">⚖️ Δικαιολόγηση Ημέρας</h3>
            <button type="button" onclick="closeJustifyModal()" aria-label="Κλείσιμο" title="Κλείσιμο" class="app-dialog-close">&times;</button>
        </div>
        <form method="POST" action="student_cards.php">
            <?= csrfField() ?>
            <input type="hidden" name="action_justify_range" value="1">
            <input type="hidden" name="student_id" id="justifyStudentId" value="">

            <div class="form-group">
                <label for="justifyDateFrom">Ημερομηνία απουσιών</label>
                <input type="date" name="date_from" id="justifyDateFrom" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <input type="hidden" name="date_to" id="justifyDateTo" value="<?= date('Y-m-d') ?>">

            <div class="form-group">
                <label for="justificationReason">Αιτιολογία / Τύπος Δικαιολόγησης</label>
                <select name="reason" id="justificationReason" class="form-control">
                    <?php foreach (justificationReasons() as $key=>$label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= $key==='medical'?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="justificationProtocol">Αριθμός Πρωτοκόλλου / Σημειώσεις (Προαιρετικό)</label>
                <input type="text" id="justificationProtocol" maxlength="255" name="protocol" class="form-control" placeholder="π.χ. Αρ. Πρωτ. 412/15-10">
            </div>

            <div class="form-group">
                <label for="unjustifiedReason">Αιτία Αδικαιολόγητης απουσίας (Προαιρετικό)</label>
                <select name="unjustified_reason" id="unjustifiedReason" class="form-control">
                    <option value="">— Χωρίς αιτία —</option>
                    <?php foreach (unjustifiedReasons() as $key=>$label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Η αιτία αφορά τις αδικαιολόγητες απουσίες/αποβολές της ημέρας. Δεν αλλάζει τον τύπο τους.</small>
                <button type="submit" name="reason_action" value="save_unjustified" class="btn btn-secondary btn-sm app-dialog-secondary-action">Αποθήκευση αιτίας αδικαιολόγητης</button>
            </div>
            <p id="reasonLoadStatus" role="status" class="app-dialog-status"></p>

            <div class="app-dialog-actions">
                <button type="button" class="btn btn-secondary" onclick="closeJustifyModal()">Ακύρωση</button>
                <button type="submit" name="reason_action" value="justify" class="btn btn-primary">Εφαρμογή Δικαιολόγησης</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Modal 2: Student Details Modal (AJAX Loaded) -->
<div id="detailsModal" class="modal app-dialog" role="dialog" aria-modal="true" aria-labelledby="detailsModalTitle">
    <div class="modal-content app-dialog-panel app-dialog-panel-wide">
        <div class="app-dialog-heading">
            <h3 class="app-dialog-title" id="detailsModalTitle">📋 Αναλυτικό Δελτίο Απουσιών</h3>
            <button type="button" onclick="closeDetailsModal()" aria-label="Κλείσιμο" title="Κλείσιμο" class="app-dialog-close">&times;</button>
        </div>
        <div id="detailsModalContent" class="app-dialog-history">
            <div style="text-align:center; padding:30px; color:var(--text-muted);">⏳ Φόρτωση αναλυτικών στοιχείων...</div>
        </div>
        <div class="app-dialog-actions app-dialog-actions-split">
            <button type="button" class="btn btn-secondary btn-sm" onclick="printStudentDossier()">🖨️ Εκτύπωση Καρτέλας</button>
            <button type="button" class="btn btn-secondary" onclick="closeDetailsModal()">Κλείσιμο</button>
        </div>
    </div>
</div>

<script type="application/json" id="studentCardsConfig"><?= json_encode([
    'justificationLabels'=>justificationReasons(), 'unjustifiedLabels'=>unjustifiedReasons(),
    'schoolName'=>$schoolName, 'printDate'=>date('d/m/Y'),
], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<script src="../assets/js/student-cards.js?v=20261028-1"></script>

<script src="../assets/js/absence-calendar.js?v=single-day-1" defer></script>
<?php require_once __DIR__ . '/footer.php'; ?>

