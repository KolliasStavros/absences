<?php
// Opaque comparison value for an attendance slot, including existing present rows.
function attendanceSlotVersion(?array $row): string {
    if ($row === null) return hash('sha256', 'empty');
    $values = [];
    foreach (['id','is_absent','absence_type','teacher_id','section_id','updated_at','note'] as $field) {
        $values[] = isset($row[$field]) ? (string)$row[$field] : null;
    }
    return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}
