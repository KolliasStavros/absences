<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once __DIR__.'/../config/config.php';
requireLogin();
if (!isAdmin() && !isTeacherAdmin()) { http_response_code(403); exit; }
$type=is_string($_GET['type']??null) ? $_GET['type'] : '';
if (!in_array($type,['application','declaration'],true)) { http_response_code(400); exit('Μη έγκυρο έντυπο.'); }
$schoolName=getSchoolSetting('school_name','');
$escape=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$declaration=$type==='declaration';
?>
<!doctype html><html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $declaration ? 'Υπεύθυνη δήλωση κηδεμόνα' : 'Αίτηση δικαιολόγησης απουσιών' ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef1f5;color:#111;font:11pt/1.4 Arial,sans-serif}.toolbar{max-width:190mm;margin:18px auto;padding:14px;background:white;border-radius:8px;display:flex;gap:14px;align-items:center;flex-wrap:wrap}.toolbar button{padding:12px 18px;cursor:pointer}.sheet{width:190mm;min-height:270mm;margin:0 auto 20px;background:#fff;padding:7mm 5mm}h1{text-align:center;font-size:17pt;margin:2mm 0}h2{font-size:12pt;margin:5mm 0 2mm}.subtitle{text-align:center;font-size:10pt;margin:0 0 4mm}.recipient{border:1px solid #222;padding:3mm;margin:5mm 0;overflow-wrap:anywhere}.row{display:flex;gap:5mm;margin-bottom:3mm}.row>div{flex:1;min-width:0}.field{border-bottom:1px dotted #555;min-height:9mm;padding-top:1mm}.writing{height:9mm;border-bottom:1px dotted #666}.signature{text-align:right;margin-top:7mm;padding-right:6mm;line-height:2}.small{font-size:8pt;line-height:1.25}.identity{width:100%;border-collapse:collapse;margin:4mm 0}.identity td{border:1px solid #444;padding:2mm;height:10mm;vertical-align:top;font-size:10pt}.bodytext{margin:5mm 0;line-height:1.8}.footnotes{margin-top:7mm;border-top:1px solid #777;padding-top:2mm}a{color:#16477a}@page{size:A4 portrait;margin:12mm 10mm}@media print{body{background:white}.toolbar{display:none!important}.sheet{margin:0;padding:0;width:100%;min-height:0;break-after:avoid}h1,.recipient,.identity,.signature{break-inside:avoid}}@media screen and (max-width:740px){.sheet{margin:0;width:100%;min-height:0;padding:18px}.toolbar{margin:10px}.row{flex-wrap:wrap}.row>div{flex-basis:44%}}
</style></head><body>
<nav class="toolbar" aria-label="Εκτύπωση εντύπου"><button type="button" onclick="window.print()">Εκτύπωση / Αποθήκευση PDF</button><a href="student_cards.php">Καρτέλες απουσιών</a><span>Α4 κατακόρυφα · Απενεργοποιήστε τις κεφαλίδες/υποσέλιδα του browser.</span></nav>
<main class="sheet">
<?php if ($declaration): ?>
<h1>ΥΠΕΥΘΥΝΗ ΔΗΛΩΣΗ</h1><p class="subtitle">(άρθρο 8 Ν. 1599/1986)</p>
<p class="small">Η ακρίβεια των στοιχείων που υποβάλλονται με αυτή τη δήλωση μπορεί να ελεγχθεί με βάση το αρχείο άλλων υπηρεσιών (άρθρο 8, παρ. 4 Ν. 1599/1986).</p>
<div class="recipient"><b>ΠΡΟΣ:</b> <?= $escape($schoolName) ?></div>
<table class="identity"><tr><td>Όνομα:</td><td>Επώνυμο:</td></tr><tr><td colspan="2">Όνομα και επώνυμο πατέρα:</td></tr><tr><td colspan="2">Όνομα και επώνυμο μητέρας:</td></tr><tr><td>Ημερομηνία γέννησης (ολογράφως):</td><td>Τόπος γέννησης:</td></tr><tr><td>Αριθμός δελτίου ταυτότητας:</td><td>Τηλέφωνο:</td></tr><tr><td>Τόπος κατοικίας:</td><td>Οδός — Αριθμός — Τ.Κ.:</td></tr><tr><td>Διεύθυνση ηλεκτρονικού ταχυδρομείου:</td><td>Αρ. τηλεομοιοτύπου (Fax):</td></tr></table>
<p class="bodytext">Με ατομική μου ευθύνη και γνωρίζοντας τις κυρώσεις που προβλέπονται από τις διατάξεις της παρ. 6 του άρθρου 22 του Ν. 1599/1986, δηλώνω ότι:</p>
<p class="bodytext">Είμαι κηδεμόνας του/της μαθητή/μαθήτριας ........................................................<br>του τμήματος ................ και οι απουσίες του/της από ........................ έως ........................ οφείλονται στο ότι:</p>
<?php for ($i=0;$i<4;$i++): ?><div class="writing"></div><?php endfor; ?>
<div class="signature">Ημερομηνία: ......... / ......... / ................<br>Ο/Η Δηλών/Δηλούσα<br><br>(Υπογραφή)</div>
<p class="small footnotes">Όποιος εν γνώσει του δηλώνει ψευδή γεγονότα ή αρνείται ή αποκρύπτει τα αληθινά με έγγραφη υπεύθυνη δήλωση του άρθρου 8 τιμωρείται με φυλάκιση τουλάχιστον τριών μηνών. Εάν ο υπαίτιος αυτών των πράξεων σκόπευε να προσπορίσει στον εαυτόν του ή σε άλλον περιουσιακό όφελος βλάπτοντας τρίτον ή σκόπευε να βλάψει άλλον, τιμωρείται με κάθειρξη μέχρι 10 ετών.<br>Σε περίπτωση ανεπάρκειας χώρου η δήλωση συνεχίζεται στην πίσω όψη και υπογράφεται από τον/τη δηλούντα/δηλούσα.</p>
<?php else: ?>
<h1>ΑΙΤΗΣΗ</h1><p class="subtitle">Δικαιολόγηση απουσιών</p>
<div class="recipient"><b>ΠΡΟΣ:</b> <?= $escape($schoolName) ?></div>
<div class="row"><div><h2>Στοιχεία αιτούντος/αιτούσας</h2><div class="field">Όνομα:</div><div class="field">Επώνυμο:</div><div class="field">Διεύθυνση κατοικίας:</div><div class="writing"></div><div class="field">Τηλέφωνο επικοινωνίας:</div><div class="field">Email:</div></div><div><h2>Στοιχεία μαθητή/μαθήτριας</h2><div class="field">Όνομα:</div><div class="field">Επώνυμο:</div><div class="field">Τάξη:</div><div class="field">Τμήμα:</div><div class="field">Αριθμός μητρώου:</div></div></div>
<p class="bodytext">Παρακαλώ να δικαιολογηθούν οι απουσίες του/της παραπάνω μαθητή/μαθήτριας για το ακόλουθο διάστημα:</p>
<div class="row"><div class="field">Από:</div><div class="field">Έως:</div><div class="field">Σύνολο ημερών:</div></div>
<h2>Λόγος απουσίας</h2><?php for ($i=0;$i<4;$i++): ?><div class="writing"></div><?php endfor; ?>
<h2>Συνημμένα δικαιολογητικά</h2><div class="field">1.</div><div class="field">2.</div><div class="field">3.</div>
<div class="signature">Τόπος: ........................................<br>Ημερομηνία: ......... / ......... / ................<br>Ο/Η Αιτών/Αιτούσα<br><br>(Υπογραφή)</div>
<?php endif; ?>
</main></body></html>
