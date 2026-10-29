<?php
// CLI-only release tooling. It is never called by the application updater.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
if(count($argv)!==5)exit("Χρήση: php bin/sign_update.php manifest.json source-directory release-secret.key output.zip\n");
[$script,$manifestFile,$source,$secretFile,$output]=$argv;
$source=realpath($source);$secretFile=realpath($secretFile);$parent=realpath(dirname($output));$app=realpath(dirname(__DIR__));
if(!$source || !$secretFile || !$parent || file_exists($output))throw new RuntimeException('Ελέγξτε διαδρομές. Το αρχείο εξόδου δεν πρέπει να υπάρχει.');
foreach([$app,dirname($app),$source] as $public){
    $boundary=strtolower(str_replace('\\','/',$public).'/');
    if(str_starts_with(strtolower(str_replace('\\','/',$secretFile)),$boundary) || str_starts_with(strtolower(str_replace('\\','/',$parent).'/'),$boundary))throw new RuntimeException('Ιδιωτικό κλειδί και έξοδος πρέπει να είναι εκτός δημόσιου/πηγαίου φακέλου.');
}
$secret=base64_decode(trim(file_get_contents($secretFile)),true);if($secret===false || strlen($secret)!==64)throw new RuntimeException('Μη έγκυρο κλειδί υπογραφής.');
$manifest=json_decode(file_get_contents($manifestFile),true,64,JSON_THROW_ON_ERROR);$manifest['application']='absences';$manifest['format']=1;$entries=[];
foreach(['files'=>'files/','migrations'=>'sql/'] as $kind=>$zipPrefix){
    $paths=$manifest[$kind]??[];if(!is_array($paths))throw new RuntimeException('Μη έγκυρη λίστα αρχείων.');
    $paths=array_is_list($paths)?$paths:array_keys($paths);$manifest[$kind]=[];
    foreach($paths as $path){
        if(!is_string($path) || !preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D',$path) || preg_match('~(^|/)\.{1,2}(/|$)~',$path))throw new RuntimeException('Μη ασφαλής διαδρομή.');
        if($kind==='files' && preg_match('~^(config|release|scratch|tests|\.[^/]+)(/|$)~i',$path))throw new RuntimeException('Δεν επιτρέπεται συσκευασία τοπικών ή ιδιωτικών αρχείων.');
        $current=$source;foreach(explode('/',$path) as $part){$current.='/'.$part;if(is_link($current))throw new RuntimeException('Δεν επιτρέπονται σύνδεσμοι.');}
        if(!is_file($current))throw new RuntimeException('Λείπει αρχείο πακέτου.');
        $manifest[$kind][$path]=hash_file('sha256',$current);$entries[$zipPrefix.$path]=$current;
    }
}
$manifest['delete']=$manifest['delete']??[];
$raw=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$zip=new ZipArchive();if($zip->open($output,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Δεν δημιουργήθηκε το πακέτο.');
try{
    if(!$zip->addFromString('manifest.json',$raw) || !$zip->addFromString('signature.bin',sodium_crypto_sign_detached($raw,$secret)))throw new RuntimeException('Δεν γράφτηκε η υπογραφή.');
    foreach($entries as $name=>$file)if(!$zip->addFile($file,$name))throw new RuntimeException('Δεν γράφτηκε αρχείο πακέτου.');
    if(!$zip->close())throw new RuntimeException('Δεν ολοκληρώθηκε το πακέτο.');
}finally{sodium_memzero($secret);}
chmod($output,0600);echo 'SHA256: '.hash_file('sha256',$output)."\n";
