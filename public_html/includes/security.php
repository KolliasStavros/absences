<?php
require_once __DIR__.'/diagnostics.php';
function safeError(Throwable $error): string {
    $requestId = technicalException($error);
    return 'Δεν ήταν δυνατή η ολοκλήρωση της ενέργειας. Κωδικός αναφοράς: '.$requestId;
}
