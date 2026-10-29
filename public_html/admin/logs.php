<?php
// admin/logs.php - Audit Trail & Notification History (Email, SMS, Viber)
$pageTitle = 'Ιστορικό Ειδοποιήσεων';
require_once __DIR__ . '/header.php';

$db = getDB();

require_once __DIR__.'/../includes/request_validation.php';
$filters=[]; $invalid=false;
foreach(['q','from','to','channel','status'] as $key) {
    $value=$_GET[$key] ?? '';
    if (!is_string($value)) { $invalid=true; $value=''; }
    $filters[$key]=trim($value);
}
$page=isset($_GET['page']) ? schoolRequestId($_GET['page']) : 1;
if ($page===null || $page>1000000 || strlen($filters['q'])>200) $invalid=true;
foreach(['from','to'] as $key) if ($filters[$key]!=='' && schoolRequestDate($filters[$key])===null) $invalid=true;
if ($filters['from']!=='' && $filters['to']!=='' && $filters['from']>$filters['to']) $invalid=true;
if (!in_array($filters['channel'],['','email','sms','viber'],true) || !in_array($filters['status'],['','sent','failed'],true)) $invalid=true;
if ($invalid) {
    http_response_code(400);
    echo '<div class="alert alert-danger">Μη έγκυρα φίλτρα. Ελέγξτε το διάστημα ημερών και τις επιλογές σας. <a href="logs.php">Καθαρισμός φίλτρων</a></div>';
    require __DIR__.'/footer.php'; exit;
}
$where=[]; $params=[];
if ($filters['from']!=='') { $where[]='l.created_at >= ?'; $params[]=$filters['from'].' 00:00:00'; }
if ($filters['to']!=='') { $where[]='l.created_at <= ?'; $params[]=$filters['to'].' 23:59:59'; }
foreach(['channel','status'] as $key) if ($filters[$key]!=='') { $where[]='l.'.$key.' = ?'; $params[]=$filters[$key]; }
if ($filters['q']!=='') {
    $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filters['q']).'%';
    $where[]="(s.am LIKE ? ESCAPE '!' OR CONCAT(s.last_name,' ',s.first_name) LIKE ? ESCAPE '!' OR l.recipient LIKE ? ESCAPE '!')";
    array_push($params,$pattern,$pattern,$pattern);
}
$join=' FROM notification_logs l LEFT JOIN students s ON l.student_id=s.id LEFT JOIN users u ON l.sent_by=u.id';
$condition=$where ? ' WHERE '.implode(' AND ',$where) : '';
$countQuery=$db->prepare('SELECT COUNT(*)'.$join.$condition); $countQuery->execute($params);
$total=(int)$countQuery->fetchColumn(); $pageSize=50; $pages=max(1,(int)ceil($total/$pageSize)); $page=min($page,$pages);
$offset=($page-1)*$pageSize;
$stmt=$db->prepare('SELECT l.*, s.am AS student_am, s.last_name, s.first_name, u.name AS sender_name'.$join.$condition.' ORDER BY l.created_at DESC,l.id DESC LIMIT '.$pageSize.' OFFSET '.$offset);
$stmt->execute($params);
$logs = $stmt->fetchAll();
$escape=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$pageUrl=static fn($number)=>'logs.php?'.http_build_query(array_merge($filters,['page'=>$number]));
?>
<link rel="stylesheet" href="../assets/css/ui-components.css?v=20261029-2">
<link rel="stylesheet" href="../assets/css/notification-history.css?v=20261029-2">
<div class="card notification-history-filters">
    <h1>Ιστορικό Ειδοποιήσεων</h1>
    <form method="get" action="logs.php">
        <div><label for="logSearch">Μαθητής, Α.Μ. ή παραλήπτης</label><input id="logSearch" name="q" class="form-control" maxlength="200" value="<?= $escape($filters['q']) ?>" placeholder="Αναζήτηση…"></div>
        <div><label for="logFrom">Από ημερομηνία</label><input type="date" id="logFrom" name="from" class="form-control" value="<?= $escape($filters['from']) ?>"></div>
        <div><label for="logTo">Έως ημερομηνία</label><input type="date" id="logTo" name="to" class="form-control" value="<?= $escape($filters['to']) ?>"></div>
        <div><label for="logChannel">Κανάλι</label><select id="logChannel" name="channel" class="form-control"><?php foreach([''=>'Όλα','email'=>'Email','sms'=>'SMS'] as $value=>$label): ?><option value="<?= $escape($value) ?>" <?= $filters['channel']===$value?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></div>
        <div><label for="logStatus">Αποτέλεσμα</label><select id="logStatus" name="status" class="form-control"><?php foreach([''=>'Όλα','sent'=>'Εστάλη','failed'=>'Αποτυχία'] as $value=>$label): ?><option value="<?= $escape($value) ?>" <?= $filters['status']===$value?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></div>
        <div class="notification-history-actions"><button type="submit" class="btn btn-primary">Εφαρμογή φίλτρων</button><a class="btn btn-secondary" href="logs.php">Καθαρισμός</a></div>
    </form>
    <p>Το διάστημα αφορά την ημερομηνία της ειδοποίησης, όχι την ημερομηνία απουσίας. Χωρίς φίλτρα εμφανίζονται εγγραφές όλων των σχολικών ετών.</p>
</div>
<div class="card">
    <div class="card-title">
        <span>Αρχείο Καταγραφής Απεσταλμένων Ειδοποιήσεων</span>
        <span class="badge badge-info" id="notificationLogCount"><?= $total ?> αποτελέσματα · Σελίδα <?= $page ?> / <?= $pages ?></span>
    </div>

    <p style="font-size:13px; color:var(--text-muted); margin-bottom:16px;">
        Καταγεγραμμένες προσπάθειες ειδοποίησης, με παραλήπτη, περιεχόμενο και αποτέλεσμα. Το «Εστάλη» δεν αποτελεί επιβεβαίωση παράδοσης ή ανάγνωσης.
    </p>

    <?php if (empty($logs)): ?>
        <div style="text-align:center; padding:40px; color:var(--text-muted);">
            Δεν βρέθηκαν ειδοποιήσεις με τα επιλεγμένα φίλτρα.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Ημερομηνία & Ώρα</th>
                        <th>Κανάλι</th>
                        <th>Μαθητής</th>
                        <th>Παραλήπτης</th>
                        <th>Ώρες Απουσίας</th>
                        <th>Κατάσταση</th>
                        <th>Χρήστης (Αποστολέας)</th>
                        <th>Προβολή</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                        <tr class="notification-log-row">
                            <td style="white-space:nowrap; font-size:13px;">
                                <?= date('d/m/Y H:i', strtotime($l['created_at'])) ?>
                            </td>
                            <td>
                                <?php if ($l['channel'] === 'email'): ?>
                                    <span class="badge badge-info">✉️ Email</span>
                                <?php elseif ($l['channel'] === 'sms'): ?>
                                    <span class="badge badge-warning">📱 SMS</span>
                                <?php else: ?>
                                    <span class="badge badge-primary">💬 Viber</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= $escape(trim(($l['last_name'] ?? '').' '.($l['first_name'] ?? '')) ?: 'Μη διαθέσιμος μαθητής') ?></strong>
                                <span style="font-size:11px; color:var(--text-muted);">(ΑΜ: <?= $escape($l['student_am'] ?? '—') ?>)</span>
                            </td>
                            <td>
                                <span style="font-size:13px;"><?= htmlspecialchars($l['recipient']) ?></span>
                            </td>
                            <td>
                                <span class="badge badge-gray"><?= htmlspecialchars($l['hours_summary'] ?: '-') ?></span>
                            </td>
                            <td>
                                <?php if ($l['status'] === 'sent'): ?>
                                    <span class="badge badge-success">✓ Εστάλη</span>
                                <?php else: ?>
                                    <span class="badge badge-danger" title="<?= $escape($l['error_message']) ?>">✕ Αποτυχία</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-size:12px; color:var(--text-muted);"><?= htmlspecialchars($l['sender_name'] ?: 'Σύστημα') ?></span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-secondary btn-sm" aria-label="Λεπτομέρειες ειδοποίησης <?= (int)$l['id'] ?>" onclick="showLogDetails(<?= $escape(json_encode($l,JSON_INVALID_UTF8_SUBSTITUTE)) ?>)">
                                    👁️
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($pages>1): ?>
    <nav class="notification-history-pagination" aria-label="Σελίδες ιστορικού">
        <?php if ($page>1): ?><a class="btn btn-secondary" href="<?= $escape($pageUrl($page-1)) ?>">← Προηγούμενη</a><?php endif; ?>
        <span>Σελίδα <?= $page ?> από <?= $pages ?> · έως 50 εγγραφές ανά σελίδα</span>
        <?php if ($page<$pages): ?><a class="btn btn-secondary" href="<?= $escape($pageUrl($page+1)) ?>">Επόμενη →</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>

<!-- Modal for Viewing Log Details -->
<div id="logModal" class="app-dialog" role="dialog" aria-modal="true" aria-labelledby="modalLogSubject">
    <div class="app-dialog-panel notification-history-details">
        <h3 id="modalLogSubject" style="margin-bottom:8px; font-size:17px;">Λεπτομέρειες Ειδοποίησης</h3>
        <div id="modalLogMeta" style="font-size:12px; color:var(--text-muted); margin-bottom:14px;"></div>
        <div id="modalLogMessage" style="background:var(--bg-main); border:1px solid var(--border); border-radius:8px; padding:14px; font-size:13px; line-height:1.6; white-space:pre-wrap; max-height:260px; overflow-y:auto;"></div>
        <div id="modalLogError" style="margin-top:10px; color:var(--danger); font-size:12px;"></div>
        <div class="app-dialog-actions">
            <button type="button" class="btn btn-secondary btn-sm" onclick="closeLogModal()">Κλείσιμο</button>
        </div>
    </div>
</div>

<script>
    function showLogDetails(log) {
        document.getElementById('modalLogSubject').textContent = log.subject || 'Χωρίς θέμα';
        document.getElementById('modalLogMeta').textContent = `Προς: ${log.recipient} | Ώρα: ${log.created_at} | Ώρες: ${log.hours_summary || '-'}`;
        document.getElementById('modalLogMessage').textContent = log.message;

        const errEl = document.getElementById('modalLogError');
        if (log.error_message) {
            errEl.textContent = 'Σφάλμα: ' + log.error_message;
            errEl.style.display = 'block';
        } else {
            errEl.style.display = 'none';
        }

        document.getElementById('logModal').style.display = 'flex';
    }

    function closeLogModal() {
        document.getElementById('logModal').style.display = 'none';
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
