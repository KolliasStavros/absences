<?php
require_once __DIR__.'/academic_years.php';

/** Private archive storage and exclusive application-request lock, shared with maintenance. */
final class AcademicYearArchives {
    public readonly AcademicYears $years;
    public function __construct(private PDO $db,private string $prefix,private string $store,private int $actor) {
        $this->years=new AcademicYears($db,$prefix);
    }
    private function actor(): void {
        $q=$this->db->prepare('SELECT role,status FROM `'.$this->prefix.'users` WHERE id=?');$q->execute([$this->actor]);$u=$q->fetch(PDO::FETCH_ASSOC);
        if(!$u||$u['role']!=='admin'||$u['status']!=='active')throw new DomainException('Απαιτείται ενεργός διαχειριστής.');
    }
    public function exclusive(callable $fn): mixed {
        $this->actor();$h=$GLOBALS['absencesRequestLock']??null;$guarded=is_resource($h);
        if($guarded){flock($h,LOCK_UN);unset($GLOBALS['absencesRequestLock']);}else $h=fopen($this->store.'/request.lock','c+b');
        if(!$h||!flock($h,LOCK_EX|LOCK_NB)){if(is_resource($h))fclose($h);throw new DomainException('Υπάρχουν ενεργά αιτήματα. Δοκιμάστε ξανά σε λίγο.');}
        try{if(is_file($this->store.'/state.json')||is_file($this->store.'/uninstalled.json'))throw new DomainException('Υπάρχει εκκρεμής εργασία συντήρησης.');$this->actor();$this->purgeExpired();return $fn();}
        finally{
            if($guarded){flock($h,LOCK_SH);$GLOBALS['absencesRequestLock']=$h;}
            else{flock($h,LOCK_UN);fclose($h);}
        }
    }
    private function purgeExpired(): void {
        foreach(glob($this->store.'/academic-year-*.json')?:[] as $path){
            if(!preg_match('/^academic-year-([a-f0-9]{32})\.json$/D',basename($path),$match)||filesize($path)>1048576)continue;
            $meta=json_decode(file_get_contents($path),true);
            if(is_array($meta)&&($meta['purpose']??'')==='restore'&&is_int($meta['expires']??null)&&$meta['expires']<time()){@unlink($this->path($match[1],'zip'));@unlink($path);}
        }
    }
    private function path(string $id,string $suffix): string {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!in_array($suffix,['zip','json'],true))throw new DomainException('Μη έγκυρο αντίγραφο.');return $this->store.'/academic-year-'.$id.'.'.$suffix;
    }
    private function write(array $m): void {
        $path=$this->path($m['id'],'json');$temp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        try{if(file_put_contents($temp,json_encode($m,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Δεν αποθηκεύτηκε η κατάσταση αντιγράφου.');chmod($temp,0600);if(!rename($temp,$path))throw new RuntimeException('Δεν αποθηκεύτηκε η κατάσταση αντιγράφου.');}finally{if(is_file($temp))unlink($temp);}
    }
    public function meta(string $id): array {
        $this->actor();$path=$this->path($id,'json');$m=is_file($path)?json_decode(file_get_contents($path),true):null;
        if(!is_array($m)||($m['id']??'')!==$id||($m['actor']??0)!==$this->actor||!is_file($this->path($id,'zip'))||!hash_equals($m['sha256']??'',hash_file('sha256',$this->path($id,'zip'))))throw new DomainException('Το αντίγραφο δεν βρέθηκε ή έχει αλλάξει.');return $m;
    }
    public function export(int $year,string $version): array {
        return $this->exclusive(function()use($year,$version){$id=bin2hex(random_bytes(16));$path=$this->path($id,'zip');
            try{$m=$this->years->export($year,$path,$version)+['id'=>$id,'actor'=>$this->actor,'purpose'=>'backup','zip_downloaded'=>false,'sha_downloaded'=>false];$this->write($m);return $m;}
            catch(Throwable $e){if(is_file($path))unlink($path);throw $e;}
        });
    }
    public function download(string $id,bool $digest): array {
        return $this->exclusive(function()use($id,$digest){$m=$this->meta($id);if($m['purpose']!=='backup')throw new DomainException('Δεν είναι διαθέσιμη λήψη.');$m[$digest?'sha_downloaded':'zip_downloaded']=true;$this->write($m);return ['path'=>$this->path($id,'zip'),'meta'=>$m];});
    }
    public function stage(string $upload,string $sha,string $version): array {
        return $this->exclusive(function()use($upload,$sha,$version){$preview=$this->years->preview($upload,$sha,$version);$id=bin2hex(random_bytes(16));$path=$this->path($id,'zip');
            if(!copy($upload,$path)||!hash_equals($sha,hash_file('sha256',$path))){if(is_file($path))unlink($path);throw new RuntimeException('Δεν αποθηκεύτηκε το πλήρες ZIP.');}chmod($path,0600);
            try{$m=$preview+['id'=>$id,'actor'=>$this->actor,'purpose'=>'restore','expires'=>time()+1800];$this->write($m);return $m;}catch(Throwable $e){unlink($path);throw $e;}
        });
    }
    public function restore(string $id,string $version): int {
        return $this->exclusive(function()use($id,$version){$m=$this->meta($id);if($m['purpose']!=='restore'||$m['expires']<time())throw new DomainException('Η προεπισκόπηση έληξε. Ανεβάστε ξανά το ZIP.');
            $year=$this->years->restore($this->path($id,'zip'),$m['sha256'],$version);
            // Package can remain as private evidence if cleanup fails; never report a committed restore as failed.
            @unlink($this->path($id,'zip'));@unlink($this->path($id,'json'));return $year;
        });
    }
    public function delete(int $year,string $backup,string $version): void {
        $this->exclusive(function()use($year,$backup,$version){$m=$this->meta($backup);
            if($m['purpose']!=='backup'||!$m['zip_downloaded']||!$m['sha_downloaded'])throw new DomainException('Κατεβάστε πρώτα το ZIP και το SHA-256 του έτους.');
            $this->years->delete($year,$this->path($backup,'zip'),$m['sha256'],$version);
        });
    }
    public function cancel(string $id): void {
        $this->exclusive(function()use($id){$m=$this->meta($id);if($m['purpose']!=='restore')throw new DomainException('Δεν είναι προεπισκόπηση επαναφοράς.');if(!unlink($this->path($id,'zip'))||!unlink($this->path($id,'json')))throw new RuntimeException('Δεν διαγράφηκε η προεπισκόπηση.');});
    }
}
