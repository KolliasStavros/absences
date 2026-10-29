<?php
// Run on the release workstation, not on the production web server.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
if(!extension_loaded('sodium'))exit("Απαιτείται sodium.\n");
$directory=realpath($argv[1]??'');$root=realpath(dirname(__DIR__));
if(!$directory || !is_dir($directory) || !is_writable($directory))exit("Δώστε υπάρχοντα ιδιωτικό φάκελο ως πρώτο όρισμα.\n");
foreach([$root,dirname($root)] as $public)if(str_starts_with(strtolower(str_replace('\\','/',$directory).'/'),strtolower(str_replace('\\','/',$public).'/')))exit("Τα κλειδιά πρέπει να βρίσκονται εκτός δημόσιου φακέλου.\n");
foreach(['release-secret.key','release-public.key'] as $name)if(file_exists($directory.'/'.$name))exit("Υπάρχουν ήδη κλειδιά. Δεν αντικαθίστανται.\n");
$pair=sodium_crypto_sign_keypair();
foreach(['release-secret.key'=>sodium_crypto_sign_secretkey($pair),'release-public.key'=>sodium_crypto_sign_publickey($pair)] as $name=>$bytes){
    $handle=fopen($directory.'/'.$name,'x');if(!$handle)throw new RuntimeException('Δεν δημιουργήθηκε το αρχείο κλειδιού.');
    try{$data=base64_encode($bytes)."\n";if(fwrite($handle,$data)!==strlen($data))throw new RuntimeException('Δεν ολοκληρώθηκε η αποθήκευση κλειδιού.');}finally{fclose($handle);}chmod($directory.'/'.$name,0600);
}
echo "Δημιουργήθηκαν δύο αρχεία κλειδιών. Το release-secret.key φυλάσσεται εκτός server και δεν ανεβαίνει στην εφαρμογή.\n";
