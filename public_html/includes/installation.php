<?php
function installationKeyHashValid(mixed $hash): bool {
    return is_string($hash) && (preg_match('/^sha256:[a-f0-9]{64}$/D',$hash)===1 || preg_match('/^\$2y\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/D',$hash)===1);
}
function installationKeyMatches(string $provided, string $hash): bool {
    if (!installationKeyHashValid($hash) || strlen($provided)>256) return false;
    if (str_starts_with($hash,'sha256:')) {
        // This format is for 256-bit random installation secrets, never human passwords.
        return preg_match('/^[a-f0-9]{64}$/D',$provided)===1 && hash_equals(substr($hash,7),hash('sha256',$provided));
    }
    return password_verify($provided,$hash);
}
function installationPrivateDefault(): string {
    $public=realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__.'/..') ?: realpath(__DIR__.'/..');
    return dirname($public).'/absences-private-'.substr(hash('sha256',realpath(__DIR__.'/..')),0,10);
}
function installationPrivateDirectory(string $path): string {
    if ($path==='' || str_contains($path,"\0") || !preg_match('~^(?:[a-zA-Z]:[\\\\/]|/)~',$path)) throw new RuntimeException('Ο ιδιωτικός φάκελος χρειάζεται πλήρη διαδρομή.');
    $parent=realpath(is_dir($path)?$path:dirname($path));
    if (!$parent || is_link($path)) throw new RuntimeException('Δεν υπάρχει προσβάσιμος γονικός ιδιωτικός φάκελος.');
    $normalize=static fn($v)=>strtolower(str_replace('\\','/',$v)).'/';
    foreach ([realpath(__DIR__.'/..'),realpath($_SERVER['DOCUMENT_ROOT']??__DIR__.'/..')] as $public) {
        if ($public && str_starts_with($normalize($parent),$normalize($public))) throw new RuntimeException('Ο ιδιωτικός φάκελος πρέπει να βρίσκεται εκτός δημόσιου φακέλου.');
    }
    if (!is_dir($path) && !@mkdir($path,0700)) throw new RuntimeException('Δεν μπορεί να δημιουργηθεί ιδιωτικός φάκελος. Ζητήστε από το hosting εγγράψιμο χώρο εκτός web root και συμπληρώστε τη διαδρομή του.');
    $path=realpath($path);
    if (!is_writable($path) || file_exists($path.'/config.php') || file_exists($path.'/smtp_master.key')) throw new RuntimeException('Ο ιδιωτικός φάκελος δεν είναι εγγράψιμος ή περιέχει ήδη ρυθμίσεις. Επιλέξτε νέο ξεχωριστό φάκελο.');
    return $path;
}
function installationWriteNew(string $path,string $contents): void {
    $handle=@fopen($path,'x+b');
    if (!$handle) throw new RuntimeException('Δεν μπορεί να δημιουργηθεί νέο ιδιωτικό αρχείο.');
    try {
        @chmod($path,0600);
        if (fwrite($handle,$contents)!==strlen($contents) || !fflush($handle)) throw new RuntimeException('Η αποθήκευση ρυθμίσεων δεν ολοκληρώθηκε.');
    } finally {fclose($handle);}
}
