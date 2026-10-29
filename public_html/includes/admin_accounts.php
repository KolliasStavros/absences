<?php
function resolveRoleSections(PDO $db, string $role, mixed $submitted): array {
    $all = array_map('intval', $db->query('SELECT id FROM sections ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
    if (in_array($role, ['admin','teacher_admin'], true)) return $all;
    if ($role !== 'teacher' || !is_array($submitted)) throw new DomainException('Μη έγκυρες αναθέσεις τμημάτων.');
    $selected = [];
    foreach ($submitted as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if (!$id || !in_array($id, $all, true)) throw new DomainException('Μη έγκυρο τμήμα ανάθεσης.');
        $selected[] = $id;
    }
    return array_values(array_unique($selected));
}

// Lock accounts in a consistent order before changing privileged roles.
function lockAdminAccounts(PDO $db): array {
    $rows = $db->query('SELECT id,role,status FROM users ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $actor = null;
    foreach ($rows as $row) if ((int)$row['id'] === (int)($_SESSION['user_id'] ?? 0)) $actor = $row;
    if (!$actor || $actor['role'] !== 'admin' || $actor['status'] !== 'active' || !getCurrentUser()) {
        throw new DomainException('Απαιτείται ενεργός λογαριασμός διαχειριστή.');
    }
    return $rows;
}

function assertAdminRoleChange(array $rows, int $targetId, string $newRole): void {
    if (!in_array($newRole, ['teacher','teacher_admin','admin'], true)) throw new DomainException('Μη έγκυρος ρόλος.');
    $target = null;
    $activeAdmins = 0;
    foreach ($rows as $row) {
        if ($row['role'] === 'admin' && $row['status'] === 'active') $activeAdmins++;
        if ((int)$row['id'] === $targetId) $target = $row;
    }
    if (!$target) throw new DomainException('Ο λογαριασμός δεν βρέθηκε.');
    if ($target['role'] === 'admin' && $target['status'] === 'active' && $newRole !== 'admin' && $activeAdmins <= 1) {
        throw new DomainException('Δεν μπορείτε να αφαιρέσετε τα δικαιώματα του τελευταίου ενεργού διαχειριστή.');
    }
}
