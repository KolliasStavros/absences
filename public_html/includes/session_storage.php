<?php
// SPDX-License-Identifier: GPL-3.0-only
// Configure file-backed sessions only, before session_start. No public fallback.
function prepareAbsencesSessionStorage(string $root): void {
    if(session_status()!==PHP_SESSION_NONE || ini_get('session.save_handler')!=='files')return;
    $explicit=defined('APP_SESSION_DIR') ? APP_SESSION_DIR : getenv('APP_SESSION_DIR');
    $maintenance=defined('APP_MAINTENANCE_DIR') ? APP_MAINTENANCE_DIR : getenv('APP_MAINTENANCE_DIR');
    if(!$explicit && !$maintenance)return; // Preserve existing settings on unconfigured installations.
    $path=$explicit ?: rtrim($maintenance,'/\\').'/sessions-'.substr(hash('sha256',realpath($root) ?: $root),0,12);
    if(!is_string($path) || str_contains($path,"\0") || !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~',$path) || is_link($path))throw new RuntimeException('Απαιτείται ασφαλής ιδιωτικός φάκελος συνεδριών.');
    $parent=realpath(is_dir($path)?$path:dirname($path));
    if(!$parent)throw new RuntimeException('Δεν υπάρχει ο γονικός ιδιωτικός φάκελος συνεδριών.');
    $normalize=static fn($p)=>strtolower(rtrim(str_replace('\\','/',$p),'/').'/');
    foreach([$root,$_SERVER['DOCUMENT_ROOT']??$root] as $public) {
        $resolved=realpath($public);
        if($resolved && str_starts_with($normalize($parent),$normalize($resolved)))throw new RuntimeException('Ο φάκελος συνεδριών πρέπει να βρίσκεται εκτός δημόσιου ιστοτόπου.');
    }
    if(!is_dir($path) && !@mkdir($path,0700) && !is_dir($path))throw new RuntimeException('Δεν δημιουργήθηκε ο ιδιωτικός φάκελος συνεδριών.');
    $resolved=realpath($path);
    if(!$resolved || !is_writable($resolved) || is_link($path))throw new RuntimeException('Ο ιδιωτικός φάκελος συνεδριών δεν είναι εγγράψιμος.');
    if(session_save_path($resolved)===false)throw new RuntimeException('Δεν ρυθμίστηκε η αποθήκευση συνεδριών.');
}
