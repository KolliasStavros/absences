<?php
// teacher/help.php - Teacher User Guide & Instructions
require_once __DIR__ . '/../config/config.php';
requireTeacher();

$user = getCurrentUser();
$schoolName = getSchoolSetting('school_name', '1ο Γενικό Λύκειο');
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Οδηγίες Χρήσης για Εκπαιδευτικούς - <?= htmlspecialchars($schoolName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css?v=daylight-20261029-1">
    <?php require __DIR__.'/../includes/diagnostics_client.php'; ?>
    <script src="../assets/js/theme.js?v=20261029-1"></script>
    <style>
        .help-box {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
        }
        .help-box h3 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .status-demo-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin: 16px 0;
        }
        .status-demo-item {
            padding: 14px;
            border-radius: 10px;
            border: 1px solid var(--border);
        }
        .step-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            background: var(--primary);
            color: #fff;
            border-radius: 50%;
            font-size: 13px;
            font-weight: 700;
            margin-right: 8px;
        }
    </style>
</head>
<body class="teacher-help-page">
    <nav class="app-navbar">
        <a href="index.php" class="nav-brand">
            <span>📋</span>
            <div>
                <div>Οδηγίες Εκπαιδευτικού</div>
                <div style="font-size:11px; font-weight:normal; color:var(--text-muted);"><?= htmlspecialchars($schoolName) ?></div>
            </div>
        </a>
        <div class="nav-user">
            <a href="index.php" class="btn btn-primary btn-sm">&larr; Επιστροφή στο Παρουσιολόγιο</a>
            <div class="user-badge">
                👤 <?= htmlspecialchars($user['name']) ?>
            </div>
            <?= logoutForm('btn btn-secondary btn-sm') ?>
        </div>
    </nav>

    <div class="main-container" style="max-width: 860px; margin: 24px auto; padding: 0 16px;">
        
        <div style="background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%); color:#fff; border-radius:14px; padding:28px 24px; margin-bottom:24px;">
            <h1 style="font-size:24px; font-weight:700; margin-bottom:6px;">👨‍🏫 Οδηγός Καταχώρησης Παρουσιών Τμήματος</h1>
            <p style="color:#e0e7ff; font-size:14px; margin:0; line-height:1.6;">
                Σύντομος οδηγός για την καθημερινή καταγραφή απουσιών, αποβολών και δικαιολογημένων απουσιών από υπολογιστή, tablet ή κινητό.
            </p>
        </div>

        <!-- Section 1 -->
        <div class="help-box">
            <h3><span class="step-num">1</span> Πώς παίρνουμε Παρουσίες</h3>
            <ol style="color:var(--text-muted); font-size:14px; line-height:1.8; margin-left:20px;">
                <li><b>Επιλέξτε Ώρα:</b> Επιλέξτε τη διδακτική ώρα του μαθήματος (1η, 2η, ..., 7η ώρα).</li>
                <li><b>Επιλέξτε Τμήμα:</b> Επιλέξτε το τμήμα στο οποίο διδάσκετε. Ο καθηγητής βλέπει τα τμήματα που του έχουν ανατεθεί στο ενεργό έτος. Οι ρόλοι admin και teacher_admin έχουν πρόσβαση σε όλα τα τμήματα.</li>
                <li><b>Εναλλαγή Κατάστασης:</b> Πατήστε στο κουμπί κάθε μαθητή για να αλλάξετε την κατάστασή του.</li>
                <li><b>Πατήστε Αποθήκευση:</b> Μόλις ολοκληρώσετε, πατήστε <b>«💾 Αποθήκευση Παρουσιολογίου»</b> και περιμένετε την επιβεβαίωση με ώρα, τμήμα και ημερομηνία.</li>
            </ol>
        </div>

        <!-- Section 2 -->
        <div class="help-box">
            <h3><span class="step-num">2</span> Οι 4 Καταστάσεις Παρουσιολογίου</h3>
            <p style="color:var(--text-muted); font-size:14px; margin-bottom:12px;">
                Για τον καθηγητή η εναλλαγή είναι Παρών → Απών → Ωριαία αποβολή → Παρών. Η δικαιολογημένη απουσία εμφανίζεται για ενημέρωση και καταχωρείται μόνο από admin:
            </p>

            <div class="status-demo-card">
                <div class="status-demo-item" style="background:#f0fdf4; border-color:#bbf7d0;">
                    <div style="font-weight:700; color:#166534; font-size:15px; margin-bottom:4px;">
                        ✓ Παρών / Παρούσα
                    </div>
                    <div style="font-size:12px; color:#166534; line-height:1.5;">
                        Ο μαθητής βρίσκεται στην αίθουσα. Αν υπάρχει αποθηκευμένο παρουσιολόγιο, φορτώνονται οι καταχωρημένες καταστάσεις.
                    </div>
                </div>

                <div class="status-demo-item" style="background:#fef2f2; border-color:#fecaca;">
                    <div style="font-weight:700; color:#991b1b; font-size:15px; margin-bottom:4px;">
                        ✗ Απών / Απούσα
                    </div>
                    <div style="font-size:12px; color:#991b1b; line-height:1.5;">
                        Απουσία. Ενημερώνει τη Γραμματεία για αποστολή email στον κηδεμόνα.
                    </div>
                </div>

                <div class="status-demo-item" style="background:#fffbeb; border-color:#fde68a;">
                    <div style="font-weight:700; color:#92400e; font-size:15px; margin-bottom:4px;">
                        ⚠️ Ω.Α. (Ωριαία Αποβολή)
                    </div>
                    <div style="font-size:12px; color:#92400e; line-height:1.5;">
                        Καταγραφή ωριαίας αποβολής από τον διδάσκοντα καθηγητή.
                    </div>
                </div>

                <div class="status-demo-item" style="background:#f0f9ff; border-color:#bae6fd;">
                    <div style="font-weight:700; color:#075985; font-size:15px; margin-bottom:4px;">
                        ℹ️ Δικ. (Δικαιολογημένη)
                    </div>
                    <div style="font-size:12px; color:#075985; line-height:1.5;">
                        Καταχωρείται μόνο από admin. Δεν τροποποιείται από καθηγητή ή teacher_admin.
                    </div>
                </div>
            </div>
        </div>

        <div class="help-box">
            <h3>Ώρα και Μπάρα 1–7</h3>
            <p>Η προβολή «Ώρα» αφορά τη διδακτική ώρα που επιλέξατε. Η «Μπάρα 1–7» δείχνει τις καταστάσεις όλων των ωρών, αλλά ο καθηγητής επεξεργάζεται μόνο την επιλεγμένη ώρα. Μόνο ο admin διαθέτει την επιλογή «Όλες οι ώρες (1η–7η)» για επεξεργασία διαφορετικών ωρών απευθείας στη μπάρα.</p>
            <p>Περιμένετε να ολοκληρωθεί η αποθήκευση πριν αλλάξετε προβολή ή αποχωρήσετε. Σε αποτυχία οι αλλαγές παραμένουν για επανάληψη. Ο teacher_admin έχει διαχειριστική πρόσβαση στις καταχωρήσεις άλλων καθηγητών, αλλά η δικαιολόγηση παραμένει στον admin.</p>
        </div>
        <!-- Section 3 -->
        <div class="help-box" style="border-left: 4px solid #ef4444;">
            <h3 style="color:#b91c1c;">⚠️ Προστασία Μη Αποθηκευμένων Αλλαγών</h3>
            <p style="color:var(--text-muted); font-size:14px; line-height:1.6; margin-bottom:12px;">
                Για να μην χαθούν κατά λάθος απουσίες που σημειώσατε:
            </p>
            <ul style="color:var(--text-muted); font-size:13px; line-height:1.6; margin-left:20px;">
                <li>Μόλις αλλάξετε έναν μαθητή, το κουμπί Αποθήκευσης <b>γίνεται κόκκινο και πάλλεται έντονα</b>.</li>
                <li>Αν πάτε να αλλάξετε ώρα ή τμήμα πριν αποθηκεύσετε, το σύστημα <b>σας προειδοποιεί</b> ότι δεν αποθηκεύτηκαν οι αλλαγές.</li>
            </ul>
        </div>

        <!-- Section 4 -->
        <div class="help-box">
            <h3>❓ Συχνές Απορίες Εκπαιδευτικών</h3>
            <div style="font-size:14px; color:var(--text-main); line-height:1.7;">
                <p><b>Δεν βλέπω ένα τμήμα στο μενού μου. Τι να κάνω;</b><br>
                <span style="color:var(--text-muted);">Επικοινωνήστε με τη Γραμματεία του σχολείου ώστε να σας αναθέσει το τμήμα από την καρτέλα «Καθηγητές & Αναθέσεις».</span></p>

                <p style="margin-top:12px;"><b>Μπορώ να αλλάξω τις απουσίες προηγούμενης ώρας;</b><br>
                <span style="color:var(--text-muted);">Επιλέξτε την ώρα και ημερομηνία. Ο καθηγητής μπορεί να τροποποιήσει μόνο δικές του καταχωρήσεις, εφόσον δεν είναι κλειδωμένες. Δεν μπορεί να σβήσει απουσίες άλλου καθηγητή ή προστατευμένες δικαιολογημένες απουσίες.</span></p>

                <p style="margin-top:12px;"><b>Λειτουργεί από το κινητό;</b><br>
                <span style="color:var(--text-muted);">Απολύτως. Μπορείτε να ανοίξετε τη σελίδα από το πρόγραμμα περιήγησης (Chrome, Safari κλπ.) του κινητού σας και να πάρετε παρουσίες απευθείας.</span></p>
            </div>
        </div>

        <div style="text-align:center; margin: 30px 0;">
            <a href="index.php" class="btn btn-primary" style="padding: 12px 28px; font-size: 15px; font-weight: 600;">
                &larr; Επιστροφή στο Παρουσιολόγιο Τμήματος
            </a>
        </div>

    </div>
</body>
</html>
