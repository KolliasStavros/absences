<?php
require_once __DIR__.'/lifecycle_guard.php';
require_once __DIR__.'/lifecycle_restore.php';

final class AbsencesLifecycle {
    use AbsencesRestore;
    private const TABLES=['academic_years','absence_day_reasons','attendance_notes','attendance','grades','login_attempts','notification_logs','password_resets','reset_requests','student_sections','teacher_sections','sections','settings','smtp_accounts','students','users'];
    public function __construct(public readonly string $root, public readonly string $store, private readonly PDO $db, private readonly string $database, private readonly string $prefix) {
        if (!preg_match('/^[a-zA-Z0-9_]{0,40}$/D',$prefix) || !preg_match('/^[a-zA-Z0-9_]+$/D',$database)) throw new DomainException('Μη έγκυρα στοιχεία εγκατάστασης.');
        if (!is_dir($store) || !is_writable($store)) throw new DomainException('Δεν είναι διαθέσιμος ο ιδιωτικός φάκελος συντήρησης.');
        foreach ([$root,$_SERVER['DOCUMENT_ROOT'] ?? $root] as $public) {
            $a=strtolower(str_replace('\\','/',realpath($store) ?: $store).'/');
            $b=strtolower(str_replace('\\','/',realpath($public) ?: $public).'/');
            if (str_starts_with($a,$b)) throw new DomainException('Η αποθήκευση πρέπει να είναι εκτός δημόσιου φακέλου.');
        }
        $identity=['root'=>realpath($root),'database'=>$database,'prefix'=>$prefix];
        $binding=$store.'/installation.json';
        if(!is_file($binding)) {
            if(is_file($store.'/state.json') || is_file($store.'/uninstalled.json') || is_file($store.'/version.json'))throw new DomainException('Ο ιδιωτικός φάκελος περιέχει ήδη κατάσταση άλλης ή παλιάς εγκατάστασης. Επιλέξτε ξεχωριστό φάκελο.');
            $handle=@fopen($binding,'x');
            if($handle){try{$raw=json_encode($identity,JSON_THROW_ON_ERROR);if(fwrite($handle,$raw)!==strlen($raw))throw new RuntimeException('Δεν καταγράφηκε η ταυτότητα εγκατάστασης.');}finally{fclose($handle);}chmod($binding,0600);}
        }
        $existing=is_file($binding)?json_decode(file_get_contents($binding),true,32,JSON_THROW_ON_ERROR):null;
        if($existing!==$identity)throw new DomainException('Ο ιδιωτικός φάκελος ανήκει σε διαφορετική εγκατάσταση. Δεν επιτρέπεται κοινός φάκελος συντήρησης.');
    }
    public function read(string $name): ?array {
        $file=$this->store.'/'.$name;
        return is_file($file) ? json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR) : null;
    }
    public function write(string $name, array $value): void {
        $temp=$this->store.'/.'.bin2hex(random_bytes(12)).'.tmp';
        if (file_put_contents($temp,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false) throw new RuntimeException('Αποτυχία ιδιωτικής αποθήκευσης.');
        chmod($temp,0600);
        if (!rename($temp,$this->store.'/'.$name)) throw new RuntimeException('Αποτυχία ολοκλήρωσης ιδιωτικής αποθήκευσης.');
    }
    public function audit(string $action, string $result, int $actor): void {
        $line=json_encode(['time'=>gmdate('c'),'actor'=>$actor,'action'=>$action,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($this->store.'/audit.jsonl',$line,FILE_APPEND|LOCK_EX)===false) throw new RuntimeException('Δεν καταγράφηκε η ενέργεια συντήρησης.');
        chmod($this->store.'/audit.jsonl',0600);
    }
    public function inventory(bool $allowPartial=false): array {
        $q=$this->db->prepare('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=?');$q->execute([$this->database]);
        $all=$q->fetchAll(PDO::FETCH_ASSOC);$managed=[];$unknown=[];
        $expected=array_map(fn($t)=>$this->prefix.$t,self::TABLES);
        foreach ($all as $row) {
            $name=$row['TABLE_NAME'];
            if (in_array($name,$expected,true)) {
                if ($row['TABLE_TYPE']!=='BASE TABLE' || strcasecmp((string)$row['ENGINE'],'InnoDB')!==0) throw new DomainException('Απαιτούνται πίνακες InnoDB για συνεπές backup.');
                $managed[]=$name;
            } elseif ($this->prefix==='' || str_starts_with($name,$this->prefix)) $unknown[]=$name;
        }
        if ($unknown) throw new DomainException('Υπάρχουν μη αναγνωρισμένοι πίνακες στην περιοχή της εγκατάστασης. Απαιτείται έλεγχος ιδιοκτησίας πριν από συντήρηση.');
        foreach (['users'=>['auth_version','role','password_hash'],'students'=>['am','academic_year_id'],'attendance'=>['student_id','teacher_id','academic_year_id']] as $table=>$columns) {
            $name=$this->prefix.$table;
            if (!in_array($name,$managed,true)) {if($allowPartial)continue;throw new DomainException('Δεν αναγνωρίστηκε πλήρης εγκατάσταση Absences.');}
            $q=$this->db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');$q->execute([$this->database,$name]);
            if (array_diff($columns,$q->fetchAll(PDO::FETCH_COLUMN))) throw new DomainException('Το σχήμα της εγκατάστασης δεν αναγνωρίστηκε.');
        }
        $q=$this->db->prepare('SELECT TABLE_SCHEMA,TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=?');$q->execute([$this->database]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $fk) if (in_array($fk['REFERENCED_TABLE_NAME'],$managed,true) && ($fk['TABLE_SCHEMA']!==$this->database || !in_array($fk['TABLE_NAME'],$managed,true))) throw new DomainException('Άλλη εφαρμογή αναφέρεται σε πίνακες Absences. Η απεγκατάσταση εμποδίζεται.');
        sort($managed);return $managed;
    }
    public function canDrop(): bool {
        foreach ($this->db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if (preg_match('/^GRANT (.+) ON (.+) TO /i',$grant,$m)) {
                $scope=str_replace('`','',$m[2]);
                if (in_array($scope,['*.*',$this->database.'.*'],true) && preg_match('/\b(ALL PRIVILEGES|DROP)\b/',$m[1])) return true;
            }
        }
        return false;
    }
    private function dropOrder(array $tables): array {
        $q=$this->db->prepare('SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_SCHEMA=?');
        $q->execute([$this->database,$this->database]);$dependencies=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $fk) if($fk['TABLE_NAME']!==$fk['REFERENCED_TABLE_NAME'] && in_array($fk['TABLE_NAME'],$tables,true) && in_array($fk['REFERENCED_TABLE_NAME'],$tables,true))$dependencies[$fk['TABLE_NAME']][]=$fk['REFERENCED_TABLE_NAME'];
        $order=[];
        while($tables){
            $parents=[];foreach($tables as $child)foreach($dependencies[$child]??[] as $parent)if(in_array($parent,$tables,true))$parents[]=$parent;
            $leaves=array_values(array_diff($tables,$parents));
            if(!$leaves)throw new DomainException('Κυκλικές εξαρτήσεις πινάκων: απαιτείται χειροκίνητη απεγκατάσταση.');
            array_push($order,...$leaves);$tables=array_values(array_diff($tables,$leaves));
        }
        return $order;
    }
    private function lock(bool $resume=false) {
        if (!$resume && is_file($this->store.'/state.json')) throw new DomainException('Υπάρχει ήδη εκκρεμής εργασία συντήρησης.');
        $h=fopen($this->store.'/request.lock','c+b');
        if (!$h || !flock($h,LOCK_EX|LOCK_NB)) throw new DomainException('Υπάρχουν ενεργά αιτήματα. Περιμένετε να ολοκληρωθούν και δοκιμάστε ξανά.');
        if (!$resume && is_file($this->store.'/state.json')) {$this->unlock($h);throw new DomainException('Υπάρχει ήδη εκκρεμής εργασία συντήρησης.');}
        return $h;
    }
    public function setPublicKey(string $encoded,int $actor): void {
        $key=base64_decode(trim($encoded),true);
        if($key===false || strlen($key)!==32)throw new DomainException('Απαιτείται δημόσιο κλειδί Ed25519 σε base64.');
        $h=$this->lock();try{
            $this->audit('trust_key','changed',$actor);
            $this->atomicFile($this->store.'/release-public-key.txt',base64_encode($key));
            chmod($this->store.'/release-public-key.txt',0600);
        }finally{$this->unlock($h);}
    }
    private function unlock($h): void { flock($h,LOCK_UN);fclose($h); }
    private function assertActor(int $actor): void {
        $q=$this->db->prepare('SELECT role,status FROM `'.$this->prefix.'users` WHERE id=?');$q->execute([$actor]);$user=$q->fetch(PDO::FETCH_ASSOC);
        if(!$user || $user['role']!=='admin' || $user['status']!=='active')throw new DomainException('Ο λογαριασμός δεν είναι πλέον ενεργός διαχειριστής.');
        if(PHP_SAPI!=='cli'){
            $current=getCurrentUser();
            if(!$current || (int)$current['id']!==$actor || $current['role']!=='admin')throw new DomainException('Η συνεδρία διαχειριστή άλλαξε. Συνδεθείτε ξανά.');
        }
    }
    public function checkedPath(string $path, bool $backupPath = false): string {
        $pattern=$backupPath ? '~^[A-Za-z0-9_. -]+(?:/[A-Za-z0-9_. -]+)*$~D' : '~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D';
        if (!preg_match($pattern,$path) || preg_match('~(^|/)\.{1,2}(/|$)~',$path)) throw new DomainException('Μη ασφαλής διαδρομή πακέτου.');
        if ($backupPath) foreach (explode('/',$path) as $part) {
            if (rtrim($part,' .')!==$part) throw new DomainException('Μη ασφαλής διαδρομή πακέτου.');
        }
        $parts=explode('/',$path);$current=rtrim($this->root,'/\\');
        foreach ($parts as $part) { $current.='/'.$part;if(is_link($current)) throw new DomainException('Δεν επιτρέπονται συμβολικοί σύνδεσμοι.'); }
        return $current;
    }
    private function updatePath(string $path): string {
        $target=$this->checkedPath($path);
        if (preg_match('~^(config|release|scratch|tests|\.git|\.codex|\.agents|\.aws)(/|$)~i',$path) || in_array(strtolower($path),['.htaccess','install.php','includes/lifecycle.php','includes/lifecycle_guard.php','includes/lifecycle_restore.php','admin/maintenance.php','bin/restore_backup.php'],true)) throw new DomainException('Το πακέτο επιχειρεί να αλλάξει προστατευμένα αρχεία εγκατάστασης ή συντήρησης.');
        return $target;
    }
    private function migrationPreflight(string $sql): void {
        $sql=str_replace('{{prefix}}',$this->prefix,$sql);
        $start=preg_replace('~\A(?:\s+|/\*.*?\*/|--[^\r\n]*(?:\r?\n|$)|\#[^\r\n]*(?:\r?\n|$))*~s','',$sql);
        if(!preg_match('/^(ALTER\s+TABLE|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([A-Za-z0-9_]+)`?\s/i',$start,$m))throw new DomainException('Η μεταβολή βάσης δεν έχει υποστηριζόμενη μορφή. Απαιτείται χειροκίνητος έλεγχος.');
        if(!in_array($m[2],array_map(fn($t)=>$this->prefix.$t,self::TABLES),true))throw new DomainException('Η μεταβολή βάσης αφορά μη καταγεγραμμένο πίνακα. Απαιτείται έλεγχος ιδιοκτησίας.');
        $clean=preg_replace("~'(?:''|\\\\.|[^'\\\\])*'|\"(?:\"\"|\\\\.|[^\"\\\\])*\"|/\\*.*?\\*/|--[^\\r\\n]*|\\#[^\\r\\n]*~s",'',$start);
        if(str_contains(rtrim($clean,"; \t\r\n"),';'))throw new DomainException('Κάθε αρχείο μεταβολής πρέπει να περιέχει μία μόνο εντολή SQL.');
        $needed=strtoupper(strtok($m[1],' '));
        foreach($this->db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant){
            if(preg_match('/^GRANT (.+) ON (.+) TO /i',$grant,$g) && in_array(str_replace('`','',$g[2]),['*.*',$this->database.'.*'],true)) {
                $privileges=array_map('trim',explode(',',$g[1]));
                if(in_array('ALL PRIVILEGES',$privileges,true) || in_array($needed,$privileges,true))return;
            }
        }
        throw new DomainException('Λείπει δικαίωμα βάσης για τη μεταβολή '.$needed.'. Ρυθμίστε τον ιδιωτικό λογαριασμό συντήρησης πριν από την ενημέρωση.');
    }
    public function verifyPackage(string $file, string $version): array {
        if (!extension_loaded('sodium') || !class_exists('ZipArchive')) throw new DomainException('Απαιτούνται οι επεκτάσεις sodium και zip.');
        $keyFile=$this->store.'/release-public-key.txt';
        $key=is_file($keyFile) ? base64_decode(trim(file_get_contents($keyFile)),true) : false;
        if ($key===false || strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw new DomainException('Ορίστε πρώτα το έμπιστο δημόσιο κλειδί εκδόσεων.');
        if (!is_file($file) || filesize($file)>67108864) throw new DomainException('Το πακέτο πρέπει να είναι έως 64 MB.');
        $zip=new ZipArchive();if($zip->open($file)!==true) throw new DomainException('Μη έγκυρο πακέτο ZIP.');
        try {
            $raw=$zip->getFromName('manifest.json',1048577);$signature=$zip->getFromName('signature.bin',65);
            if ($raw===false || strlen($raw)>1048576 || $signature===false || strlen($signature)!==64 || !sodium_crypto_sign_verify_detached($signature,$raw,$key)) throw new DomainException('Η υπογραφή του πακέτου δεν είναι έγκυρη.');
            $m=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if (($m['application']??'')!=='absences' || ($m['format']??0)!==1 || ($m['from_version']??'')!==$version || !is_string($m['version']??null) || !preg_match('/^\d+\.\d+\.\d+$/D',$m['version']) || !version_compare($m['version'],$version,'>') || !version_compare(PHP_VERSION,$m['min_php']??'8.2.0','>=')) throw new DomainException('Το πακέτο δεν είναι συμβατό με την έκδοση της εγκατάστασης.');
            foreach (['files','migrations'] as $kind) if (!is_array($m[$kind]??[])) throw new DomainException('Μη έγκυρη λίστα αρχείων.');
            if (!is_array($m['delete']??[])) throw new DomainException('Μη έγκυρη λίστα διαγραφών.');
            $allowed=['manifest.json'=>null,'signature.bin'=>null];$targets=[];
            foreach (($m['files']??[]) as $path=>$hash) {
                if(!is_string($path)) throw new DomainException('Μη έγκυρη διαδρομή.');
                $this->updatePath($path);$targets[]=strtolower($path);$allowed['files/'.$path]=$hash;
            }
            foreach (($m['delete']??[]) as $path) { if(!is_string($path))throw new DomainException('Μη έγκυρη διαδρομή.');$this->updatePath($path);$targets[]=strtolower($path); }
            if (count($targets)!==count(array_unique($targets))) throw new DomainException('Διπλές ή αντικρουόμενες διαδρομές.');
            foreach (($m['migrations']??[]) as $path=>$hash) {
                if(!is_string($path) || !preg_match('~^[A-Za-z0-9_-]+\.sql$~D',$path)) throw new DomainException('Μη έγκυρο αρχείο μεταβολής βάσης.');
                $allowed['sql/'.$path]=$hash;
            }
            if ($zip->numFiles!==count($allowed) || $zip->numFiles>20000) throw new DomainException('Το πακέτο περιέχει μη δηλωμένα ή υπερβολικά αρχεία.');
            $seen=[];$total=0;
            for($i=0;$i<$zip->numFiles;$i++) {
                $s=$zip->statIndex($i);$name=$s['name'];$fold=strtolower($name);
                if(isset($seen[$fold]) || !array_key_exists($name,$allowed))throw new DomainException('Μη δηλωμένη ή διπλή εγγραφή ZIP.');$seen[$fold]=true;
                $zip->getExternalAttributesIndex($i,$os,$attr);$type=($attr>>16)&0170000;
                if($type!==0 && $type!==0100000)throw new DomainException('Σύνδεσμοι και ειδικά αρχεία δεν επιτρέπονται.');
                $total+=$s['size'];if($total>268435456 || $s['size']>16777216)throw new DomainException('Υπερβολικό αποσυμπιεσμένο μέγεθος.');
                if($allowed[$name]!==null) {
                    if(!is_string($allowed[$name]) || !preg_match('/^[a-f0-9]{64}$/D',$allowed[$name]))throw new DomainException('Μη έγκυρο αποτύπωμα αρχείου.');
                    $data=$zip->getFromIndex($i);if($data===false || !hash_equals($allowed[$name],hash('sha256',$data)))throw new DomainException('Αλλοιωμένο αρχείο πακέτου.');
                }
            }
            foreach(array_keys($m['migrations']??[]) as $path)$this->migrationPreflight($zip->getFromName('sql/'.$path));
            return $m;
        } finally { $zip->close(); }
    }
    private function backup(array $tables, string $version): array {
        $record=$this->read('version.json');
        if(($record['version']??'1.0.0')!==$version)throw new DomainException('Η έκδοση άλλαξε από άλλη ενέργεια. Ανανεώστε τη σελίδα.');
        $id=bin2hex(random_bytes(16));$created=time();$sql=$this->store.'/'.$id.'.sql';$archive=$this->store.'/'.$id.'.zip';
        $identity=['id'=>$id,'created'=>$created,'version'=>$version,'database'=>$this->database,'prefix'=>$this->prefix,'root'=>realpath($this->root),'tables'=>$tables];
        $out=fopen($sql,'xb');if(!$out)throw new RuntimeException('Αποτυχία δημιουργίας backup βάσης.');chmod($sql,0600);
        try {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();
            $put=static function($s)use($out){if(fwrite($out,$s)!==strlen($s))throw new RuntimeException('Ανεπαρκής χώρος backup.');};
            $sqlMode=(string)$this->db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $put("SET NAMES utf8mb4;\nSET SQL_MODE=".$this->db->quote($sqlMode).";\nSET FOREIGN_KEY_CHECKS=0;\n");
            foreach($tables as $table) {
                $create=$this->db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
                $put('DROP TABLE IF EXISTS `'.$table."`;\n".$create.";\n");
                $buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$q=null;
                $this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
                try {
                    $q=$this->db->query('SELECT * FROM `'.$table.'`');
                    while($row=$q->fetch(PDO::FETCH_ASSOC)) {
                        $columns=implode(',',array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',array_keys($row)));
                        $values=implode(',',array_map(fn($v)=>$v===null?'NULL':$this->db->quote((string)$v),array_values($row)));
                        $put('INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.$values.");\n");
                    }
                } finally {if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
            }
            $put("SET FOREIGN_KEY_CHECKS=1;\n");$this->db->commit();
        } catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;} finally {fclose($out);}
        $zip=new ZipArchive();if($zip->open($archive,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Αποτυχία δημιουργίας backup αρχείων.');
        $hashes=[];
        try {
            $zip->addFile($sql,'database.sql');$hashes['database.sql']=hash_file('sha256',$sql);
            $backupInfo=json_encode($identity,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if(!$zip->addFromString('backup.json',$backupInfo))throw new RuntimeException('Δεν καταγράφηκαν τα στοιχεία backup.');
            $hashes['backup.json']=hash('sha256',$backupInfo);
            $walk=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS));
            foreach($walk as $item) {
                $relative=str_replace('\\','/',substr($item->getPathname(),strlen(rtrim($this->root,'/\\'))+1));
                if(preg_match('~^(release|scratch|tests|\.[^/]+)(/|$)~i',$relative) && $relative!=='.htaccess')continue;
                if($item->isLink())throw new DomainException('Υπάρχει σύνδεσμος στην εγκατάσταση· ελέγξτε χειροκίνητα το backup.');
                if(!$item->isFile())continue;
                $hashes['files/'.$relative]=hash_file('sha256',$item->getPathname());
                if(!$zip->addFile($item->getPathname(),'files/'.$relative))throw new RuntimeException('Αποτυχία προσθήκης αρχείου backup.');
            }
            if(!$zip->addFromString('hashes.json',json_encode($hashes,JSON_THROW_ON_ERROR)) || !$zip->close())throw new RuntimeException('Δεν ολοκληρώθηκε το backup.');
        }catch(Throwable $e){@$zip->close();throw $e;}
        chmod($archive,0600);
        $check=new ZipArchive();if($check->open($archive,ZipArchive::CHECKCONS)!==true)throw new RuntimeException('Αποτυχία ελέγχου backup.');
        try{foreach($hashes as $name=>$hash){$stream=$check->getStream($name);if(!$stream)throw new RuntimeException('Ελλιπές backup.');$ctx=hash_init('sha256');hash_update_stream($ctx,$stream);fclose($stream);if(!hash_equals($hash,hash_final($ctx)))throw new RuntimeException('Αλλοιωμένο backup.');}}finally{$check->close();}
        $meta=$identity+['sha256'=>hash_file('sha256',$archive),'size'=>filesize($archive)];
        $this->write('backup-'.$id.'.json',$meta);return $meta;
    }
    public function createBackup(string $version,int $actor): array {
        $h=$this->lock();try{
            $this->write('state.json',['phase'=>'backup','actor'=>$actor]);
            $meta=$this->backup($this->inventory(),$version);$this->audit('backup','verified',$actor);return $meta;
        }finally{@unlink($this->store.'/state.json');$this->unlock($h);}
    }
    public function prepareUninstall(string $version,int $actor): array {
        if(!$this->canDrop())throw new DomainException('Ο λογαριασμός βάσης δεν διαθέτει DROP. Ρυθμίστε ξεχωριστό λογαριασμό συντήρησης στο ιδιωτικό config· τα δικαιώματα δεν αλλάζουν αυτόματα.');
        $h=$this->lock();
        try{
            $this->assertActor($actor);
            $this->write('state.json',['phase'=>'preparing_uninstall','actor'=>$actor]);
            $tables=$this->inventory();$this->dropOrder($tables);$meta=$this->backup($tables,$version);
            $this->audit('prepare_uninstall','backup_verified',$actor);
            $this->write('state.json',['phase'=>'uninstall_prepared','actor'=>$actor,'backup'=>$meta['id']]);return $meta;
        }catch(Throwable $e){@unlink($this->store.'/state.json');throw $e;}finally{$this->unlock($h);}
    }
    public function backupMeta(string $id): array {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Μη έγκυρο αντίγραφο.');
        $meta=$this->read('backup-'.$id.'.json');
        if(!$meta || $meta['database']!==$this->database || $meta['prefix']!==$this->prefix || $meta['root']!==realpath($this->root) || !is_file($this->store.'/'.$id.'.zip') || !hash_equals($meta['sha256'],hash_file('sha256',$this->store.'/'.$id.'.zip')))throw new DomainException('Το αντίγραφο δεν αντιστοιχεί στην εγκατάσταση ή απέτυχε η επαλήθευσή του.');
        return $meta;
    }
    public function cancel(int $actor): void {
        $h=$this->lock(true);try{
            $this->assertActor($actor);
            $state=$this->read('state.json');if(($state['phase']??'')!=='uninstall_prepared')throw new DomainException('Η εργασία δεν μπορεί να ακυρωθεί αυτόματα. Απαιτείται έλεγχος ανάκτησης.');
            $this->audit('cancel_uninstall','ok',$actor);if(!unlink($this->store.'/state.json'))throw new RuntimeException('Δεν ακυρώθηκε η συντήρηση.');
        }finally{$this->unlock($h);}
    }
    public function uninstall(string $backupId,int $actor): void {
        $h=$this->lock(true);try{
            $this->assertActor($actor);
            $state=$this->read('state.json');
            if(($state['phase']??'')!=='uninstall_prepared' || ($state['backup']??'')!==$backupId)throw new DomainException('Προηγείται προετοιμασία απεγκατάστασης.');
            $meta=$this->backupMeta($backupId);$tables=$this->inventory();
            if($tables!==$meta['tables'] || !$this->canDrop())throw new DomainException('Άλλαξε η λίστα πινάκων ή τα δικαιώματα. Η απεγκατάσταση σταμάτησε.');
            $this->audit('uninstall','starting',$actor);
            // A tombstone precedes DDL: even a crash cannot reopen an empty/partial installation.
            $this->write('uninstalled.json',['time'=>gmdate('c'),'backup'=>$backupId,'status'=>'starting']);
            // Keep FK checks enabled. Hidden external references must also block deletion.
            $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
            $this->db->exec('DROP TABLE '.implode(',',array_map(fn($t)=>'`'.$t.'`',$this->dropOrder($tables))));
            $this->write('uninstalled.json',['time'=>gmdate('c'),'backup'=>$backupId,'status'=>'completed']);
            $this->audit('uninstall','completed',$actor);
        }finally{$this->unlock($h);}
    }
    private function atomicFile(string $file,string $data): void {
        $directory=dirname($file);if(!is_dir($directory) && !mkdir($directory,0755,true))throw new RuntimeException('Δεν δημιουργήθηκε φάκελος ενημέρωσης.');
        $temp=$directory.'/.update-'.bin2hex(random_bytes(12)).'.tmp';
        if(file_put_contents($temp,$data)!==strlen($data))throw new RuntimeException('Δεν γράφτηκε πλήρες αρχείο ενημέρωσης.');
        chmod($temp,is_file($file)?(fileperms($file)&0777):0644);
        if(!@rename($temp,$file)){@unlink($temp);throw new RuntimeException('Δεν αντικαταστάθηκε αρχείο ενημέρωσης.');}
        if(function_exists('opcache_invalidate'))opcache_invalidate($file,true);
    }
    public function update(string $package,string $version,int $actor): array {
        $m=$this->verifyPackage($package,$version);$h=$this->lock();$meta=null;$databaseStarted=false;$touched=[];$started=false;
        try{
            $this->assertActor($actor);
            $record=$this->read('version.json');
            if(($record['version']??'1.0.0')!==$version)throw new DomainException('Η έκδοση άλλαξε από άλλη ενέργεια. Ανανεώστε τη σελίδα.');
            $m=$this->verifyPackage($package,$version);
            $this->write('state.json',['phase'=>'updating','actor'=>$actor]);
            $started=true;
            $meta=$this->backup($this->inventory(),$version);$this->audit('update','backup_verified',$actor);
            $zip=new ZipArchive();if($zip->open($package)!==true)throw new RuntimeException('Το πακέτο δεν είναι διαθέσιμο.');
            try{
                foreach(($m['migrations']??[]) as $path=>$hash){$databaseStarted=true;$sql=$zip->getFromName('sql/'.$path);$this->db->exec(str_replace('{{prefix}}',$this->prefix,$sql));}
                foreach(($m['files']??[]) as $path=>$hash){$file=$this->updatePath($path);$touched[$path]=is_file($file);$this->atomicFile($file,$zip->getFromName('files/'.$path));if(!hash_equals($hash,hash_file('sha256',$file)))throw new RuntimeException('Αποτυχία επαλήθευσης νέου αρχείου.');}
                foreach(($m['delete']??[]) as $path){$file=$this->updatePath($path);if(is_file($file)){$touched[$path]=true;if(!unlink($file))throw new RuntimeException('Δεν αφαιρέθηκε παλιό αρχείο.');}}
            }finally{$zip->close();}
            $this->write('version.json',['version'=>$m['version']]);$this->audit('update','completed',$actor);if(!unlink($this->store.'/state.json'))throw new RuntimeException('Δεν ολοκληρώθηκε η έξοδος από συντήρηση.');
            return $meta;
        }catch(Throwable $e){
            if(!$started)throw $e;
            $restored=true;
            if($meta && $touched){
                $old=new ZipArchive();if($old->open($this->store.'/'.$meta['id'].'.zip')!==true)$restored=false;
                else{try{foreach($touched as $path=>$existed){$file=$this->updatePath($path);if($existed){$bytes=$old->getFromName('files/'.$path);if($bytes===false)throw new RuntimeException('Ελλιπές αντίγραφο επαναφοράς.');$this->atomicFile($file,$bytes);}elseif(is_file($file) && !unlink($file))throw new RuntimeException('Αποτυχία αφαίρεσης νέου αρχείου.');}}catch(Throwable $restoreError){$restored=false;}finally{$old->close();}}
            }
            if(!$databaseStarted && $restored){$this->write('version.json',['version'=>$version]);@unlink($this->store.'/state.json');}
            else $this->write('state.json',['phase'=>'recovery_required','backup'=>$meta['id']??null]);
            $this->audit('update','failed',$actor);throw $e;
        }finally{$this->unlock($h);}
    }
}
