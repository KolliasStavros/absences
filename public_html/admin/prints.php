<?php
$pageTitle='Εκτυπώσεις';
require_once __DIR__.'/header.php';
?>
<section class="card" style="padding:24px">
<h1>🖨️ Εκτυπώσεις</h1>
<p>Κενά έντυπα για συμπλήρωση. Κάθε έντυπο ανοίγει σε νέα καρτέλα για εκτύπωση ή αποθήκευση PDF.</p>
<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:20px">
<a class="btn btn-secondary" href="absence_forms.php?type=application" target="_blank" rel="noopener">🖨️ Κενή αίτηση δικαιολόγησης</a>
<a class="btn btn-secondary" href="absence_forms.php?type=declaration" target="_blank" rel="noopener">🖨️ Κενή υπεύθυνη δήλωση</a>
</div>
</section>
<?php require_once __DIR__.'/footer.php'; ?>
