<?php
require_once __DIR__.'/portable_recovery.php';
trait AbsencesPortableRecovery {
    private bool $portableUsesRequestLock=false;
    private function portableLock() {
        $h=$GLOBALS['absencesRequestLock']??null;
        if(!is_resource($h))return $this->lock();
        if(is_file($this->store.'/state.json'))throw new DomainException('Υπάρχει εκκρεμής εργασία συντήρησης.');
        flock($h,LOCK_UN);
        if(!flock($h,LOCK_EX|LOCK_NB)){
            flock($h,LOCK_SH);
            throw new DomainException('Υπάρχουν ενεργά αιτήματα. Δοκιμάστε ξανά σε λίγο.');
        }
        if(is_file($this->store.'/state.json')){flock($h,LOCK_SH);throw new DomainException('Υπάρχει εκκρεμής εργασία συντήρησης.');}
        unset($GLOBALS['absencesRequestLock']);$this->portableUsesRequestLock=true;return $h;
    }
    private function portableUnlock($h): void {
        if($this->portableUsesRequestLock){$this->portableUsesRequestLock=false;flock($h,LOCK_SH);$GLOBALS['absencesRequestLock']=$h;}
        else $this->unlock($h);
    }
    private function portableService(): PortableSchoolRecovery {return new PortableSchoolRecovery($this->db,$this->prefix);}
    public function createPortableRecovery(string $version,int $actor): array {
        $h=$this->portableLock();$id=bin2hex(random_bytes(16));$path=$this->store.'/portable-'.$id.'.zip';
        try{
            $this->assertActor($actor);$this->write('state.json',['phase'=>'portable_export','actor'=>$actor]);
            $meta=$this->portableService()->export($path,$version)+['id'=>$id,'purpose'=>'export'];
            $this->portableService()->verify($path,$meta['sha256'],$version);$this->write('portable-'.$id.'.json',$meta);$this->audit('portable_export','verified',$actor);return $meta;
        }catch(Throwable $e){@unlink($path);throw $e;}finally{@unlink($this->store.'/state.json');$this->portableUnlock($h);}
    }
    public function portableRecoveryMeta(string $id): array {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Μη έγκυρο πακέτο ανάκτησης.');
        $meta=$this->read('portable-'.$id.'.json');$path=$this->store.'/portable-'.$id.'.zip';
        if(!$meta||($meta['id']??'')!==$id||!is_string($meta['sha256']??null)||!is_file($path)||!hash_equals($meta['sha256'],hash_file('sha256',$path)))throw new DomainException('Το πακέτο ανάκτησης δεν βρέθηκε ή έχει αλλάξει.');return $meta;
    }
    public function stagePortableRecovery(string $upload,string $sha,string $version,int $actor): array {
        $this->assertActor($actor);$preview=$this->portableService()->verify($upload,$sha,$version);$this->portableService()->fresh($actor);
        $id=bin2hex(random_bytes(16));$path=$this->store.'/portable-'.$id.'.zip';
        if(!copy($upload,$path)||!hash_equals($sha,hash_file('sha256',$path))){@unlink($path);throw new RuntimeException('Δεν αποθηκεύτηκε πλήρες πακέτο.');}chmod($path,0600);
        $meta=['id'=>$id,'purpose'=>'import','sha256'=>$sha,'actor'=>$actor,'expires'=>time()+1800,'created'=>$preview['created'],'version'=>$preview['version'],'counts'=>$preview['counts']];
        $this->write('portable-'.$id.'.json',$meta);return $meta;
    }
    public function cancelPortableRecovery(string $id,int $actor): void {
        $meta=$this->portableRecoveryMeta($id);$this->assertActor($actor);
        if(($meta['purpose']??'')!=='import'||($meta['actor']??0)!==$actor)throw new DomainException('Μη διαθέσιμη προεπισκόπηση.');
        if(!unlink($this->store.'/portable-'.$id.'.zip')||!unlink($this->store.'/portable-'.$id.'.json'))throw new RuntimeException('Δεν αφαιρέθηκε η προεπισκόπηση.');
    }
    public function importPortableRecovery(string $id,string $sha,string $version,int $actor): array {
        $meta=$this->portableRecoveryMeta($id);
        if(($meta['purpose']??'')!=='import'||($meta['actor']??0)!==$actor||($meta['expires']??0)<time()||!hash_equals($meta['sha256'],$sha))throw new DomainException('Η προεπισκόπηση έληξε ή άλλαξε. Ανεβάστε ξανά το πακέτο.');
        $h=$this->portableLock();$started=false;$committed=false;$rescue=null;
        try{
            $this->assertActor($actor);$meta=$this->portableRecoveryMeta($id);$service=$this->portableService();$service->verify($this->store.'/portable-'.$id.'.zip',$sha,$version);$service->fresh($actor);
            // Establish only the destination version if maintenance has not been used yet.
            if(!$this->read('version.json'))$this->write('version.json',['version'=>$version]);
            $this->write('state.json',['phase'=>'portable_import','actor'=>$actor]);$started=true;
            $rescue=$this->backup($this->inventory(),$version);$this->audit('portable_import','backup_verified',$actor);
            $admin=$service->import($this->store.'/portable-'.$id.'.zip',$sha,$version,$actor);$committed=true;
            $this->write('restore-generation.json',['generation'=>bin2hex(random_bytes(32))]);$this->audit('portable_import','completed',$admin);
            if(!unlink($this->store.'/state.json'))throw new RuntimeException('Δεν ολοκληρώθηκε η έξοδος από τη συντήρηση.');
            @unlink($this->store.'/portable-'.$id.'.zip');@unlink($this->store.'/portable-'.$id.'.json');
            return ['admin_id'=>$admin,'rescue'=>$rescue['id'],'version'=>$version];
        }catch(Throwable $e){if($started){if($committed)$this->write('state.json',['phase'=>'recovery_required','backup'=>$rescue['id']??null,'reason'=>'portable_finalize']);else @unlink($this->store.'/state.json');}$this->audit('portable_import','failed',$actor);throw $e;}
        finally{$this->portableUnlock($h);}
    }
}
