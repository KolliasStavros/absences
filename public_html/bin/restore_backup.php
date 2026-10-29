<?php
// Offline recovery. No web endpoint, no login bypass and no credentials in arguments.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
try {
    $options=getopt('',['config:','store:','root:','backup:','file:','sha256:','sha256-file:','confirm:','allow-no-live-backup']);
    if(($options['confirm']??'')!=='ΑΝΤΙΚΑΤΑΣΤΑΣΗ ΔΕΔΟΜΕΝΩΝ')throw new DomainException('Απαιτείται --confirm="ΑΝΤΙΚΑΤΑΣΤΑΣΗ ΔΕΔΟΜΕΝΩΝ". Η ενέργεια αντικαθιστά δεδομένα.');
    $config=realpath($options['config']??'');$root=realpath($options['root']??dirname(__DIR__));$store=realpath($options['store']??'');
    if(!$config || !is_file($config) || !$root || !$store)throw new DomainException('Δώστε --config με το έμπιστο αρχείο ρυθμίσεων DB, --store και προαιρετικά --root.');
    require $config;
    foreach(['DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $constant)if(!defined($constant))throw new DomainException('Το αρχείο ρυθμίσεων δεν ορίζει τα απαιτούμενα στοιχεία βάσης.');
    require_once dirname(__DIR__).'/includes/lifecycle.php';
    $user=defined('APP_MAINTENANCE_DB_USER')?APP_MAINTENANCE_DB_USER:DB_USER;
    $password=defined('APP_MAINTENANCE_DB_PASS')?APP_MAINTENANCE_DB_PASS:DB_PASS;
    $db=new PDO('mysql:host='.DB_HOST.';'.(defined('DB_PORT')?'port='.DB_PORT.';':'').'dbname='.DB_NAME.';charset=utf8mb4',$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]);
    $prefix=defined('DB_PREFIX')?DB_PREFIX:'';$manager=new AbsencesLifecycle($root,$store,$db,DB_NAME,$prefix);
    $id=$options['backup']??'';
    if(isset($options['file'])) {
        if($id!=='')throw new DomainException('Δώστε είτε --backup είτε --file.');
        $file=realpath($options['file']);if(!$file || !is_file($file))throw new DomainException('Δεν βρέθηκε το αρχείο backup.');
        try {$id=$manager->importBackup($file);}catch(DomainException $e) {
            $expected=$options['sha256']??'';
            if(isset($options['sha256-file'])) {
                $text=file_get_contents($options['sha256-file']);
                if(!is_string($text) || !preg_match('/^([a-f0-9]{64})(?:\s|$)/D',$text,$match))throw new DomainException('Μη έγκυρο ξεχωριστό αρχείο SHA256.');
                $expected=$match[1];
            }
            if(!preg_match('/^[a-f0-9]{64}$/D',$expected) || !hash_equals($expected,hash_file('sha256',$file)))throw new DomainException('Λείπει η ιδιωτική καταγραφή. Απαιτείται SHA256 που φυλάχθηκε χωριστά από το έμπιστο backup, όχι τιμή μέσα στο ίδιο ZIP.');
            $zip=new ZipArchive();if($zip->open($file)!==true)throw new DomainException('Μη έγκυρο ZIP.');
            try{$raw=$zip->getFromName('backup.json',65537);$meta=$raw!==false && strlen($raw)<=65536 ? json_decode($raw,true,64,JSON_THROW_ON_ERROR):null;}finally{$zip->close();}
            if(!is_array($meta) || !preg_match('/^[a-f0-9]{32}$/D',$meta['id']??'') || ($meta['database']??null)!==DB_NAME || ($meta['prefix']??null)!==$prefix || ($meta['root']??null)!==realpath($root))throw new DomainException('Το backup δεν αναγνωρίζεται για αυτή την εγκατάσταση. Παλιά backups χωρίς backup.json απαιτούν την αρχική ιδιωτική καταγραφή.');
            $id=$meta['id'];if(is_file($store.'/backup-'.$id.'.json'))throw new DomainException('Υπάρχει ήδη διαφορετική καταγραφή για το ίδιο αντίγραφο. Απαιτείται χειροκίνητος έλεγχος.');
            $meta['sha256']=$expected;$meta['size']=filesize($file);$manager->write('backup-'.$id.'.json',$meta);$id=$manager->importBackup($file);
        }
    }
    $meta=$manager->verifyRestoreBackup($id);$record=$manager->read('version.json');$version=$record['version']??'1.0.0';
    $result=$manager->restoreBackup($id,0,$version,$meta['sha256'],true,isset($options['allow-no-live-backup']));
    echo 'Η επαναφορά ολοκληρώθηκε. Έκδοση '.$result['version'].'. Αντίγραφο πριν την επαναφορά: '.($result['rescue']??'δεν δημιουργήθηκε — ρητή επιλογή χειριστή')."\n";
}catch(Throwable $e){
    $message=$e instanceof DomainException?$e->getMessage():'Η ανάκτηση δεν ολοκληρώθηκε. Ελέγξτε την ιδιωτική κατάσταση συντήρησης και τα αντίγραφα. Δεν εμφανίζονται διαπιστευτήρια ή SQL.';
    fwrite(STDERR,$message."\n");exit(1);
}
