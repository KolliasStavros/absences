<?php
// admin/footer.php - Shared Admin Layout Footer
?>
        <footer style="margin-top:40px; padding:20px 0; border-top:1px solid var(--border); font-size:13px; color:var(--text-muted); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div>
                🏫 <strong><?= htmlspecialchars($schoolName ?? 'Παρουσιολόγιο & Απουσίες') ?></strong> &bull; Σύστημα Ηλεκτρονικής Καταγραφής Απουσιών
            </div>
            <div style="display:flex; align-items:center; gap:8px;">
                <span class="badge badge-info" style="font-size:11px;">v1.0</span>
                <span>Δημιουργός: <strong style="color:var(--text-main);">Κόλλιας Σταύρος</strong> &copy; <?= date('Y') ?></span>
                <a href="../license.php" style="color:var(--primary);">Άδεια GPLv3</a>
            </div>
        </footer>
    </div>
</body>
</html>
