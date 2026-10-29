<?php
trait AbsencesRestore {
    public function listBackups(): array {
        $items=[];
        foreach(glob($this->store.'/backup-*.json') ?: [] as $file) {
            try {
                $meta=json_decode(file_get_contents($file),true,64,JSON_THROW_ON_ERROR);
                if(!is_array($meta) || !preg_match('/^[a-f0-9]{32}$/D',$meta['id']??'') || basename($file)!=='backup-'.$meta['id'].'.json')continue;
                if(($meta['database']??null)!==$this->database || ($meta['prefix']??null)!==$this->prefix || ($meta['root']??null)!==realpath($this->root))continue;
                $meta['available']=is_file($this->store.'/'.$meta['id'].'.zip');$items[]=$meta;
            }catch(Throwable $e){continue;}
        }
        usort($items,fn($a,$b)=>($b['created']??0)<=>($a['created']??0));return $items;
    }
    public function importBackup(string $file): string {
        if(!is_file($file) || filesize($file)>2147483648)throw new DomainException('Το backup λείπει ή υπερβαίνει το επιτρεπόμενο μέγεθος.');
        $digest=hash_file('sha256',$file);
        foreach($this->listBackups() as $meta) {
            if(!is_string($meta['sha256']??null) || !hash_equals($meta['sha256'],$digest))continue;
            $destination=$this->store.'/'.$meta['id'].'.zip';
            if(!is_file($destination) || !hash_equals($digest,hash_file('sha256',$destination))) {
                $temporary=$this->store.'/.import-'.bin2hex(random_bytes(12)).'.tmp';
                if(!copy($file,$temporary) || !hash_equals($digest,hash_file('sha256',$temporary))){@unlink($temporary);throw new RuntimeException('Δεν αποθηκεύτηκε πλήρες backup.');}
                chmod($temporary,0600);if(!rename($temporary,$destination)){@unlink($temporary);throw new RuntimeException('Δεν αποθηκεύτηκε το backup.');}
            }
            return $meta['id'];
        }
        throw new DomainException('Το αρχείο δεν αντιστοιχεί σε καταγεγραμμένο backup αυτής της εγκατάστασης. Δεν αρκούν τα hashes που περιέχει το ίδιο το ZIP.');
    }
    private function restoreProtected(string $path): bool {
        return (bool)preg_match('~^(config|bin|release|scratch|tests|\.[^/]+)(/|$)~i',$path) || (bool)preg_match('/\.(zip|tar|gz|key|pem)$/i',$path) || in_array(strtolower($path),['install.php','admin/maintenance.php','includes/auth.php','includes/security.php','includes/diagnostics.php','includes/lifecycle.php','includes/lifecycle_guard.php','includes/lifecycle_restore.php','includes/lifecycle.md'],true);
    }
    private function restorePrivileges(): bool {
        $privileges=[];
        foreach($this->db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) {
            if(preg_match('/^GRANT (.+) ON (.+) TO /i',$grant,$m) && in_array(str_replace('`','',$m[2]),['*.*',$this->database.'.*'],true)) {
                $list=array_map('trim',explode(',',$m[1]));if(in_array('ALL PRIVILEGES',$list,true))return true;
                array_push($privileges,...$list);
            }
        }
        return !array_diff(['SELECT','INSERT','UPDATE','CREATE','DROP','REFERENCES'],$privileges);
    }
    private function restoreFile(ZipArchive $zip,string $path): void {
        $target=$this->checkedPath($path,true);$directory=dirname($target);
        if(!is_dir($directory) && !mkdir($directory,0755,true))throw new RuntimeException('Δεν δημιουργήθηκε φάκελος επαναφοράς.');
        $input=$zip->getStream('files/'.$path);if(!$input)throw new RuntimeException('Λείπει αρχείο επαναφοράς.');
        $temporary=$directory.'/.restore-'.bin2hex(random_bytes(12)).'.tmp';$output=fopen($temporary,'xb');
        if(!$output){fclose($input);throw new RuntimeException('Δεν δημιουργήθηκε προσωρινό αρχείο.');}
        try {
            $bytes=stream_copy_to_stream($input,$output);$expected=$zip->statName('files/'.$path)['size'];
            if($bytes===false || $bytes!==$expected)throw new RuntimeException('Δεν αντιγράφηκε πλήρες αρχείο.');
        }catch(Throwable $e){fclose($output);fclose($input);@unlink($temporary);throw $e;}
        fclose($output);fclose($input);chmod($temporary,is_file($target)?(fileperms($target)&0777):0644);
        if(!@rename($temporary,$target)){@unlink($temporary);throw new RuntimeException('Δεν αντικαταστάθηκε αρχείο επαναφοράς.');}
        if(function_exists('opcache_invalidate'))opcache_invalidate($target,true);
    }
    private function sqlStatements($stream): Generator {
        $statement='';$quote=null;$escape=false;$noBackslash=false;
        while(!feof($stream)) {
            $chunk=fread($stream,8192);if($chunk===false)throw new RuntimeException('Δεν διαβάστηκε το SQL backup.');
            for($i=0,$length=strlen($chunk);$i<$length;$i++) {
                $char=$chunk[$i];$statement.=$char;
                if(strlen($statement)>33554432)throw new DomainException('Μια εντολή SQL υπερβαίνει το όριο ασφαλούς εισαγωγής.');
                if($quote!==null) {
                    if($escape){$escape=false;continue;}
                    if($char==='\\' && $quote!=='`' && !$noBackslash){$escape=true;continue;}
                    if($char===$quote)$quote=null;
                } elseif(in_array($char,["'",'"','`'],true))$quote=$char;
                elseif($char===';') {
                    $sql=trim(substr($statement,0,-1));$statement='';
                    if(preg_match('/^SET SQL_MODE=/i',$sql))$noBackslash=str_contains(strtoupper($sql),'NO_BACKSLASH_ESCAPES');
                    if($sql!=='')yield $sql;
                }
            }
        }
        if($quote!==null || trim($statement)!=='')throw new DomainException('Ελλιπής ή μη αναγνωρίσιμη εντολή στο SQL backup.');
    }
    private function checkRestoreSQL(string $sql,array $tables): ?array {
        if(preg_match('/^SET NAMES utf8mb4$/i',$sql) || preg_match("/^SET SQL_MODE='[A-Z0-9_,]*'$/i",$sql) || preg_match('/^SET FOREIGN_KEY_CHECKS=[01]$/i',$sql))return null;
        if(!preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([A-Za-z0-9_]+)`(?:\s|$)/i',$sql,$m) || !in_array($m[2],$tables,true))throw new DomainException('Το SQL περιέχει μη αναμενόμενη εντολή ή πίνακα.');
        return [strtoupper($m[1]),$m[2]];
    }
    public function verifyRestoreBackup(string $id): array {
        $meta=$this->backupMeta($id);
        if(!is_array($meta['tables']??null) || !is_string($meta['version']??null) || !preg_match('/^\d+\.\d+\.\d+$/D',$meta['version']))throw new DomainException('Μη έγκυρα στοιχεία backup.');
        $known=array_map(fn($name)=>$this->prefix.$name,self::TABLES);
        if(array_diff($meta['tables'],$known) || count($meta['tables'])!==count(array_unique($meta['tables'])))throw new DomainException('Το backup περιέχει μη καταγεγραμμένους πίνακες.');
        foreach(['users','students','attendance'] as $required)if(!in_array($this->prefix.$required,$meta['tables'],true))throw new DomainException('Το backup δεν περιέχει πλήρη βάση εφαρμογής.');
        $zip=new ZipArchive();if($zip->open($this->store.'/'.$id.'.zip',ZipArchive::CHECKCONS)!==true)throw new DomainException('Δεν ανοίγει το backup.');
        try {
            $raw=$zip->getFromName('hashes.json',8388609);$hashes=$raw!==false?json_decode($raw,true,64,JSON_THROW_ON_ERROR):null;
            if(!is_array($hashes) || strlen($raw)>8388608 || !isset($hashes['database.sql']) || $zip->numFiles!==count($hashes)+1 || $zip->numFiles>50000)throw new DomainException('Ελλιπής ή υπερβολικός κατάλογος backup.');
            $seen=[];$bytes=0;$restorable=[];
            for($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i);$name=$stat['name'];$fold=strtolower($name);
                if(isset($seen[$fold]))throw new DomainException('Διπλή διαδρομή backup.');$seen[$fold]=true;
                $zip->getExternalAttributesIndex($i,$os,$attributes);$type=($attributes>>16)&0170000;
                if($type!==0 && $type!==0100000)throw new DomainException('Το backup περιέχει σύνδεσμο ή ειδικό αρχείο.');
                $bytes+=$stat['size'];if($bytes>2147483648)throw new DomainException('Το αποσυμπιεσμένο backup υπερβαίνει τα 2 GB.');
                if($name==='hashes.json')continue;
                if(!isset($hashes[$name]) || !is_string($hashes[$name]) || !preg_match('/^[a-f0-9]{64}$/D',$hashes[$name]))throw new DomainException('Μη δηλωμένο αρχείο backup.');
                if($name!=='database.sql' && $name!=='backup.json') {
                    if(!str_starts_with($name,'files/'))throw new DomainException('Μη αναμενόμενη διαδρομή backup.');
                    $path=substr($name,6);$this->checkedPath($path,true);
                    if(!$this->restoreProtected($path))$restorable[]=$path;
                }
                $stream=$zip->getStream($name);if(!$stream)throw new DomainException('Ελλιπές αρχείο backup.');
                $hash=hash_init('sha256');hash_update_stream($hash,$stream);fclose($stream);
                if(!hash_equals($hashes[$name],hash_final($hash)))throw new DomainException('Αποτυχία ακεραιότητας backup.');
            }
            if(isset($hashes['backup.json'])) {
                $info=$zip->getFromName('backup.json',65537);$info=$info!==false && strlen($info)<=65536 ? json_decode($info,true,64,JSON_THROW_ON_ERROR) : null;
                foreach(['id','created','version','database','prefix','root','tables'] as $field)if(!is_array($info) || ($info[$field]??null)!==($meta[$field]??null))throw new DomainException('Τα συνοδευτικά στοιχεία backup δεν συμφωνούν με την καταγραφή της εγκατάστασης.');
            }
            $stream=$zip->getStream('database.sql');if(!$stream)throw new DomainException('Λείπει το SQL backup.');$creates=[];$drops=[];
            try{foreach($this->sqlStatements($stream) as $sql){$command=$this->checkRestoreSQL($sql,$meta['tables']);if($command){if($command[0]==='CREATE TABLE')$creates[]=$command[1];if($command[0]==='DROP TABLE IF EXISTS')$drops[]=$command[1];}}}finally{fclose($stream);}
            sort($creates);sort($drops);$expected=$meta['tables'];sort($expected);
            if($creates!==$expected || $drops!==$expected)throw new DomainException('Το SQL δεν αντιστοιχεί ακριβώς στους πίνακες του backup.');
            $meta['restore_files']=$restorable;return $meta;
        }finally{$zip->close();}
    }
    public function restoreBackup(string $id,int $actor,string $currentVersion,string $expectedHash,bool $emergency=false,bool $allowNoLiveBackup=false): array {
        if($emergency && PHP_SAPI!=='cli')throw new DomainException('Η ανάκτηση χωρίς σύνδεση επιτρέπεται μόνο από γραμμή εντολών.');
        if($allowNoLiveBackup && !$emergency)throw new DomainException('Δεν επιτρέπεται παράκαμψη του νέου backup από τη διαχείριση.');
        $meta=$this->verifyRestoreBackup($id);
        if(!hash_equals($meta['sha256'],$expectedHash))throw new DomainException('Το backup άλλαξε μετά την προεπισκόπηση.');
        if(!$this->restorePrivileges())throw new DomainException('Η επαναφορά απαιτεί SELECT, INSERT, UPDATE, CREATE, DROP και REFERENCES για τη συγκεκριμένη βάση.');
        $h=$this->lock($emergency);$started=false;$rescue=null;$previousState=$this->read('state.json');
        try {
            if(!$emergency)$this->assertActor($actor);
            $meta=$this->verifyRestoreBackup($id);if(!hash_equals($meta['sha256'],$expectedHash))throw new DomainException('Το backup άλλαξε.');
            $currentTables=$this->inventory($emergency);$order=$this->dropOrder($currentTables);
            $this->write('state.json',['phase'=>'restoring','actor'=>$actor,'source_backup'=>$id]);$started=true;
            try {$rescue=$this->backup($currentTables,$currentVersion);}catch(Throwable $e){if(!$allowNoLiveBackup)throw $e;}
            $this->audit('restore','starting',$actor);
            $zip=new ZipArchive();if($zip->open($this->store.'/'.$id.'.zip')!==true)throw new RuntimeException('Δεν ανοίγει το επιλεγμένο backup.');
            $originalMode=(string)$this->db->query('SELECT @@SESSION.sql_mode')->fetchColumn();$destructive=false;
            try {
                $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
                $destructive=true;
                if($order)$this->db->exec('DROP TABLE '.implode(',',array_map(fn($name)=>'`'.$name.'`',$order)));
                $stream=$zip->getStream('database.sql');if(!$stream)throw new RuntimeException('Λείπει το SQL backup.');
                try{foreach($this->sqlStatements($stream) as $sql){$this->checkRestoreSQL($sql,$meta['tables']);$this->db->exec($sql);}}finally{fclose($stream);}
                $this->db->exec('SET FOREIGN_KEY_CHECKS=1');
                $restored=$this->inventory();$expected=$meta['tables'];sort($expected);
                if($restored!==$expected)throw new RuntimeException('Δεν αποκαταστάθηκαν οι αναμενόμενοι πίνακες.');
                if((int)$this->db->query("SELECT COUNT(*) FROM `".$this->prefix."users` WHERE role='admin' AND status='active'")->fetchColumn()<1)throw new DomainException('Το αντίγραφο δεν περιέχει ενεργό διαχειριστή. Απαιτείται ελεγχόμενη ανάκτηση.');
                // Never replace connection settings, recovery engine or local installation identity.
                foreach($meta['restore_files'] as $path){
                    $this->restoreFile($zip,$path);
                }
                if($rescue){
                    $before=new ZipArchive();if($before->open($this->store.'/'.$rescue['id'].'.zip')!==true)throw new RuntimeException('Δεν ανοίγει το αντίγραφο πριν την επαναφορά.');
                    try{for($i=0;$i<$before->numFiles;$i++){$name=$before->getNameIndex($i);if(!str_starts_with($name,'files/'))continue;$path=substr($name,6);if($this->restoreProtected($path) || in_array($path,$meta['restore_files'],true))continue;$target=$this->checkedPath($path,true);if(is_file($target) && !unlink($target))throw new RuntimeException('Δεν αφαιρέθηκε νεότερο αρχείο εφαρμογής.');}}finally{$before->close();}
                }
                $this->db->exec('UPDATE `'.$this->prefix.'users` SET auth_version=auth_version+1');
                $this->write('restore-generation.json',['generation'=>bin2hex(random_bytes(32))]);
                $this->write('version.json',['version'=>$meta['version']]);$this->audit('restore','completed',$actor);
                if(is_file($this->store.'/uninstalled.json') && !unlink($this->store.'/uninstalled.json'))throw new RuntimeException('Δεν αφαιρέθηκε η ένδειξη απεγκατάστασης.');
                if(!unlink($this->store.'/state.json'))throw new RuntimeException('Δεν ολοκληρώθηκε η συντήρηση.');
                return ['source'=>$id,'rescue'=>$rescue['id']??null,'version'=>$meta['version']];
            }finally{$zip->close();$this->db->exec('SET FOREIGN_KEY_CHECKS=1');$this->db->exec('SET SESSION sql_mode='.$this->db->quote($originalMode));}
        }catch(Throwable $e){
            if($started){
                if(!empty($destructive))$this->write('state.json',['phase'=>'recovery_required','backup'=>$rescue['id']??null,'source_backup'=>$id]);
                elseif($previousState)$this->write('state.json',$previousState);
                else @unlink($this->store.'/state.json');
            }
            throw $e;
        }finally{$this->unlock($h);}
    }
}
