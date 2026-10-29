<?php
// Optional: unconfigured installations retain their existing request behavior.
function lifecycleStorage(string $root): string {
    $path=defined('APP_MAINTENANCE_DIR') ? APP_MAINTENANCE_DIR : getenv('APP_MAINTENANCE_DIR');
    if (!$path || !is_dir($path) || !is_writable($path)) throw new RuntimeException('Ορίστε εγγράψιμο ιδιωτικό APP_MAINTENANCE_DIR εκτός δημόσιου φακέλου.');
    $real=realpath($path); $public=realpath($_SERVER['DOCUMENT_ROOT'] ?? $root) ?: realpath($root);
    foreach ([$public,realpath($root)] as $denied) {
        $a=strtolower(str_replace('\\','/',$real).'/');$b=strtolower(str_replace('\\','/',$denied).'/');
        if (str_starts_with($a,$b)) throw new RuntimeException('Ο φάκελος συντήρησης πρέπει να βρίσκεται εκτός δημόσιου φακέλου.');
    }
    return $real;
}

function lifecycleRequestGuard(string $root): void {
    if (PHP_SAPI==='cli') return;
    $configured=defined('APP_MAINTENANCE_DIR') ? APP_MAINTENANCE_DIR : getenv('APP_MAINTENANCE_DIR');
    if (!$configured) return;
    try { $store=lifecycleStorage($root); } catch (Throwable $e) { http_response_code(503); exit('Η ιδιωτική αποθήκευση συντήρησης δεν είναι διαθέσιμη. Ελέγξτε τη ρύθμιση APP_MAINTENANCE_DIR.'); }
    if(is_file($store.'/installation.json')) {
        $identity=json_decode(file_get_contents($store.'/installation.json'),true);
        if(($identity['root']??null)!==realpath($root)){http_response_code(503);exit('Ο φάκελος συντήρησης ανήκει σε διαφορετική εγκατάσταση. Ελέγξτε την ιδιωτική ρύθμιση.');}
    }
    if (is_file($store.'/uninstalled.json')) { http_response_code(410); exit('Η εγκατάσταση Absences έχει απεγκατασταθεί. Τα αρχεία και τα ιδιωτικά αντίγραφα διατηρήθηκαν για ελεγχόμενη ανάκτηση.'); }
    $script=realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
    $controller=realpath($root.'/admin/maintenance.php') ?: $root.'/admin/maintenance.php';
    if ($script===$controller || in_array($script,[realpath($root.'/index.php'),realpath($root.'/logout.php')],true)) return;
    if (is_file($store.'/state.json')) { http_response_code(503); header('Retry-After: 60'); exit('Η εφαρμογή βρίσκεται σε συντήρηση. Δοκιμάστε ξανά αργότερα.'); }
    $handle=fopen($store.'/request.lock','c+b');
    if (!$handle || !flock($handle,LOCK_SH|LOCK_NB)) { http_response_code(503); exit('Η εφαρμογή βρίσκεται σε συντήρηση.'); }
    if (is_file($store.'/state.json') || is_file($store.'/uninstalled.json')) { flock($handle,LOCK_UN);fclose($handle);http_response_code(503);exit('Η εφαρμογή βρίσκεται σε συντήρηση.'); }
    $GLOBALS['absencesRequestLock']=$handle;
    register_shutdown_function(static function() use($handle) { if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);} });
}
