<?php
// Data-only disaster recovery. No SQL, executable files or installation credentials in the package.
final class PortableSchoolRecovery {
    public const SETTINGS=['school_name','school_phone','bell_schedule','co_teaching_sections','email_template_subject','email_template_body','email_history_subject','email_history_body','email_summary_subject','email_summary_body','email_custom_texts','mask_student_name'];
    public const COLUMNS=[
        'users'=>'id name teacher_registry_number email role status created_at updated_at',
        'academic_years'=>'id name is_active created_at',
        'grades'=>'id name',
        'sections'=>'id grade_id name',
        'students'=>'id academic_year_id am last_name first_name father_name mother_name gender last_name_gen first_name_gen student_email guardian_name guardian_name_voc guardian_email guardian_mobile guardian_name_2 guardian_email_2 guardian_mobile_2 created_at',
        'student_sections'=>'student_id section_id',
        'teacher_sections'=>'academic_year_id teacher_id section_id',
        'attendance'=>'id academic_year_id date hour section_id student_id teacher_id is_absent absence_type note email_notified sms_notified viber_notified created_at updated_at',
        'attendance_notes'=>'id academic_year_id student_id date hour note teacher_id created_at updated_at',
        'absence_day_reasons'=>'academic_year_id student_id date unjustified_reason justification_reason protocol justified_by updated_at',
        'subjects'=>'id source_name display_name is_active',
        'course_offerings'=>'id academic_year_id section_id subject_id weekly_hours has_written_exam is_active version',
        'course_teachers'=>'id course_offering_id teacher_id assignment_type can_edit_marks is_active',
        'assessment_periods'=>'id academic_year_id code name min_mark max_mark mark_step is_open version',
        'course_students'=>'id course_offering_id student_id is_active',
        'student_marks'=>'id course_student_id assessment_period_id mark_value mark_status updated_by updated_at version',
        'mark_submissions'=>'id course_offering_id assessment_period_id status submitted_by submitted_at version',
        'mark_audit_log'=>'id mark_id submission_id course_offering_id action old_value new_value changed_by changed_at reason',
        'mark_teacher_aliases'=>'source_key teacher_id',
        'calendar_managers'=>'user_id is_active granted_by updated_at',
        'school_calendar_events'=>'id academic_year_id title description internal_notes category status start_date end_date start_time end_time visibility version is_deleted created_by updated_by created_at updated_at',
        'calendar_event_readers'=>'event_id user_id is_active',
        'calendar_audit_log'=>'id event_id changed_by action details created_at',
        'notification_logs'=>'id channel student_id recipient subject message hours_summary status sent_by created_at',
        'settings'=>'setting_key setting_value',
    ];
    public const CORE=['users','academic_years','grades','sections','students','student_sections','teacher_sections','attendance','settings'];
    public static function safeSetting(string $key): bool {return in_array($key,self::SETTINGS,true)||(bool)preg_match('/^academic_year_closed_[1-9][0-9]{0,9}$/D',$key);}
    public function __construct(private PDO $db,private string $prefix) {
        if(!preg_match('/^[A-Za-z0-9_]{0,40}$/D',$prefix))throw new DomainException('Μη έγκυρο πρόθεμα.');
    }
    private function table(string $name): string {
        if(!isset(self::COLUMNS[$name]))throw new DomainException('Μη επιτρεπόμενος πίνακας ανάκτησης.');
        return '`'.$this->prefix.$name.'`';
    }
    public function schema(): array {
        $q=$this->db->query('SELECT TABLE_NAME,COLUMN_NAME,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION');$out=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $c)foreach(array_keys(self::COLUMNS) as $name)if($c['TABLE_NAME']===$this->prefix.$name)$out[$name][$c['COLUMN_NAME']]=$c;
        foreach(self::CORE as $name)if(!isset($out[$name]))throw new DomainException('Απαιτείται ολοκληρωμένη εγκατάσταση πριν την ανάκτηση.');
        $q=$this->db->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');foreach($q->fetchAll(PDO::FETCH_ASSOC) as $table)foreach(array_keys($out) as $name)if($table['TABLE_NAME']===$this->prefix.$name&&strtoupper((string)$table['ENGINE'])!=='INNODB')throw new DomainException('Η ασφαλής ανάκτηση απαιτεί πίνακες InnoDB.');
        return $out;
    }
    public function export(string $path,string $version): array {
        if(file_exists($path)||!preg_match('/^\d+\.\d+\.\d+$/D',$version))throw new DomainException('Μη έγκυρη έξοδος ανάκτησης.');
        $schema=$this->schema();$zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Δεν δημιουργήθηκε το πακέτο ανάκτησης.');
        $temps=[];$success=false;$transaction=false;
        try{
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();$transaction=true;
            $manifest=['application'=>'absences-school-recovery','format'=>1,'version'=>$version,'created'=>gmdate('c'),'passwords'=>'reset','tables'=>[]];
            $total=0;
            foreach(self::COLUMNS as $name=>$allowed){
                if(!isset($schema[$name]))continue;
                $columns=array_values(array_intersect(explode(' ',$allowed),array_keys($schema[$name])));
                $sql='SELECT '.implode(',',array_map(static fn($c)=>'`'.$c.'`',$columns)).' FROM '.$this->table($name);
                if($name==='settings')$sql.=' WHERE setting_key IN ('.implode(',',array_map(fn($v)=>$this->db->quote($v),self::SETTINGS)).') OR setting_key REGEXP \'^academic_year_closed_[1-9][0-9]{0,9}$\'';
                $file=tempnam(dirname($path),'.portable-');if($file===false)throw new RuntimeException('Δεν δημιουργήθηκε προσωρινό αρχείο.');$temps[]=$file;chmod($file,0600);$stream=fopen($file,'wb');if(!$stream)throw new RuntimeException('Δεν άνοιξε το αρχείο δεδομένων.');$count=0;$q=null;
                $buffered=$this->db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
                try{$q=$this->db->query($sql);while($row=$q->fetch(PDO::FETCH_ASSOC)){
                    // Defense in depth: only the declared source columns can reach disk.
                    $row=array_intersect_key($row,array_flip($columns));$line=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
                    $total+=strlen($line);if(strlen($line)>1048576||++$count>200000||$total>268435456)throw new DomainException('Το πακέτο υπερβαίνει τα όρια ανάκτησης.');
                    if(fwrite($stream,$line)!==strlen($line))throw new RuntimeException('Ανεπαρκής χώρος.');
                }}finally{fclose($stream);if($q)$q->closeCursor();$this->db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,$buffered);}
                $manifest['tables'][$name]=['columns'=>$columns,'rows'=>$count,'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file)];
                if(!$zip->addFile($file,'data/'.$name.'.ndjson'))throw new RuntimeException('Δεν προστέθηκαν τα δεδομένα.');
            }
            $this->db->commit();$transaction=false;
            if(!$zip->addFromString('manifest.json',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))||!$zip->close())throw new RuntimeException('Δεν ολοκληρώθηκε το πακέτο.');
            chmod($path,0600);$success=true;return ['sha256'=>hash_file('sha256',$path),'size'=>filesize($path),'version'=>$version,'created'=>$manifest['created'],'counts'=>array_map(static fn($t)=>$t['rows'],$manifest['tables'])];
        }finally{if($transaction&&$this->db->inTransaction())$this->db->rollBack();if(!$success){@$zip->close();@unlink($path);}foreach($temps as $file)@unlink($file);}
    }
    private function open(string $path,string $sha): ZipArchive {
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha)||!is_file($path)||filesize($path)>67108864||!hash_equals($sha,hash_file('sha256',$path)))throw new DomainException('Το αρχείο δεν συμφωνεί με το χωριστά φυλαγμένο SHA-256 ή υπερβαίνει τα 64 MB.');
        $zip=new ZipArchive();if($zip->open($path,ZipArchive::CHECKCONS)!==true)throw new DomainException('Δεν ανοίγει το πακέτο.');return $zip;
    }
    public function rows(ZipArchive $zip,string $name,array $spec): Generator {
        $stream=$zip->getStream('data/'.$name.'.ndjson');if(!$stream)throw new DomainException('Λείπουν δεδομένα ανάκτησης.');$count=0;
        try{while(($line=fgets($stream,1048578))!==false){
            if(strlen($line)>1048576||++$count>200000)throw new DomainException('Υπερβολική γραμμή δεδομένων.');
            $row=json_decode($line,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($row)||array_is_list($row)||array_keys($row)!==$spec['columns'])throw new DomainException('Οι στήλες δεδομένων δεν συμφωνούν με την προεπισκόπηση.');
            foreach($row as $value)if(!is_string($value)&&!is_int($value)&&!is_float($value)&&$value!==null)throw new DomainException('Μη έγκυρη τιμή δεδομένων.');
            if($name==='settings'&&(!self::safeSetting((string)($row['setting_key']??''))||(str_starts_with($row['setting_key'],'academic_year_closed_')&&!in_array($row['setting_value'],['0','1'],true))))throw new DomainException('Το πακέτο περιέχει μη επιτρεπόμενη ρύθμιση.');
            if($name==='users'&&(!in_array($row['role']??null,['admin','teacher','teacher_admin'],true)||!in_array($row['status']??null,['active','inactive','pending_activation'],true)))throw new DomainException('Μη έγκυρος λογαριασμός.');
            yield $row;
        }if($count!==$spec['rows'])throw new DomainException('Μη αναμενόμενο πλήθος εγγραφών.');}catch(JsonException $e){throw new DomainException('Το πακέτο περιέχει μη έγκυρα δεδομένα JSON.');}finally{fclose($stream);}
    }
    public function verify(string $path,string $sha,string $version): array {
        $zip=$this->open($path,$sha);
        try{
            $raw=$zip->getFromName('manifest.json',1048577);if($raw===false||strlen($raw)>1048576)throw new DomainException('Λείπει η περιγραφή πακέτου.');
            $m=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($m)||array_diff(array_keys($m),['application','format','version','created','passwords','tables'])||($m['application']??'')!=='absences-school-recovery'||($m['format']??0)!==1||($m['passwords']??'')!=='reset'||!is_string($m['version']??null)||!preg_match('/^\d+\.\d+\.\d+$/D',$m['version'])||version_compare($m['version'],$version,'>')||!is_string($m['created']??null)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/D',$m['created'])||!is_array($m['tables']??null)||array_diff(self::CORE,array_keys($m['tables']))||array_diff(array_keys($m['tables']),array_keys(self::COLUMNS)))throw new DomainException('Μη συμβατό πακέτο σχολικών δεδομένων. Εγκαταστήστε ίδια ή νεότερη συμβατή έκδοση.');
            if($zip->numFiles!==count($m['tables'])+1)throw new DomainException('Το πακέτο περιέχει πρόσθετα αρχεία.');
            $schema=$this->schema();$expected=['manifest.json'=>true];$total=0;
            foreach($m['tables'] as $name=>$spec){
                if(!is_array($spec)||array_keys($spec)!==['columns','rows','bytes','sha256']||!is_array($spec['columns'])||!array_is_list($spec['columns'])||!$spec['columns']||count($spec['columns'])!==count(array_unique($spec['columns']))||array_diff($spec['columns'],explode(' ',self::COLUMNS[$name]))||!is_int($spec['rows'])||$spec['rows']<0||$spec['rows']>200000||!is_int($spec['bytes'])||$spec['bytes']<0||!is_string($spec['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$spec['sha256']))throw new DomainException('Μη έγκυρη περιγραφή πίνακα.');
                $keys=match($name){'student_sections'=>['student_id','section_id'],'teacher_sections'=>['academic_year_id','teacher_id','section_id'],'absence_day_reasons'=>['academic_year_id','student_id','date'],'mark_teacher_aliases'=>['source_key','teacher_id'],'calendar_managers'=>['user_id','granted_by'],'calendar_event_readers'=>['event_id','user_id'],'settings'=>['setting_key','setting_value'],default=>['id']};
                if($name==='users')$keys=array_merge($keys,['name','email','role','status']);
                if(array_diff($keys,$spec['columns'])||!isset($schema[$name])||array_diff($spec['columns'],array_keys($schema[$name])))throw new DomainException('Το σχήμα της νέας εγκατάστασης δεν είναι συμβατό με το πακέτο.');
                foreach($schema[$name] as $c=>$info)if(!in_array($c,$spec['columns'],true)&&$info['IS_NULLABLE']==='NO'&&$info['COLUMN_DEFAULT']===null&&!str_contains($info['EXTRA'],'auto_increment')&&!str_contains($info['EXTRA'],'GENERATED'))throw new DomainException('Απαιτείται συμβατή εγκατάσταση πριν την εισαγωγή.');
                $expected['data/'.$name.'.ndjson']=true;
            }
            $seen=[];
            for($i=0;$i<$zip->numFiles;$i++){
                $s=$zip->statIndex($i);$name=$s['name'];$zip->getExternalAttributesIndex($i,$os,$attr);$type=($attr>>16)&0170000;
                if(!isset($expected[$name])||isset($seen[$name])||($type!==0&&$type!==0100000)||($s['encryption_method']??0)!==0||($total+=$s['size'])>268435456)throw new DomainException('Μη ασφαλές ή υπερβολικό πακέτο.');$seen[$name]=true;
            }
            $counts=[];
            foreach($m['tables'] as $name=>$spec){$s=$zip->statName('data/'.$name.'.ndjson');$stream=$zip->getStream('data/'.$name.'.ndjson');if(!$stream)throw new DomainException('Ελλιπές πακέτο.');$hash=hash_init('sha256');try{hash_update_stream($hash,$stream);}finally{fclose($stream);}if($s['size']!==$spec['bytes']||!hash_equals($spec['sha256'],hash_final($hash)))throw new DomainException('Αποτυχία ακεραιότητας δεδομένων.');foreach($this->rows($zip,$name,$spec) as $_){}$counts[$name]=$spec['rows'];}
            if($counts['users']<1)throw new DomainException('Λείπουν οι ιστορικοί λογαριασμοί.');return $m+['counts'=>$counts,'sha256'=>$sha];
        }catch(JsonException $e){throw new DomainException('Δεν αναγνωρίζεται η περιγραφή του πακέτου.');}finally{$zip->close();}
    }
    public function fresh(int $actor): array {
        $schema=$this->schema();$q=$this->db->query('SELECT * FROM '.$this->table('users'));$users=$q->fetchAll(PDO::FETCH_ASSOC);
        if(count($users)!==1||(int)$users[0]['id']!==$actor||$users[0]['role']!=='admin'||$users[0]['status']!=='active')throw new DomainException('Η ανάκτηση επιτρέπεται μόνο σε νέα εγκατάσταση με έναν ενεργό διαχειριστή.');
        foreach(array_keys($schema) as $name)if(!in_array($name,['users','settings','academic_years','grades','sections','assessment_periods'],true)&&(int)$this->db->query('SELECT COUNT(*) FROM '.$this->table($name))->fetchColumn()>0)throw new DomainException('Η εγκατάσταση περιέχει ήδη σχολικά δεδομένα. Χρησιμοποιήστε νέα, κενή εγκατάσταση.');
        // Never silently discard installation-specific history that references the initial administrator.
        $q=$this->db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');$all=$q->fetchAll(PDO::FETCH_COLUMN);
        foreach(['attendance_edit_batches','attendance_edit_changes'] as $name)if(in_array($this->prefix.$name,$all,true)&&(int)$this->db->query('SELECT COUNT(*) FROM `'.$this->prefix.$name.'`')->fetchColumn()>0)throw new DomainException('Υπάρχει ιστορικό καταχωρίσεων στη νέα εγκατάσταση.');
        return $users[0];
    }
    private function order(array $tables): array {
        $q=$this->db->query('SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()');$edges=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $fk){if(!str_starts_with($fk['REFERENCED_TABLE_NAME'],$this->prefix))continue;$child=str_starts_with($fk['TABLE_NAME'],$this->prefix)?substr($fk['TABLE_NAME'],strlen($this->prefix)):'!external';$parent=substr($fk['REFERENCED_TABLE_NAME'],strlen($this->prefix));if(in_array($parent,$tables,true)&&!in_array($child,$tables,true)&&!in_array($child,['password_resets','reset_requests','attendance_edit_batches','attendance_edit_changes'],true))throw new DomainException('Υπάρχει εξωτερική εξάρτηση από τα σχολικά δεδομένα.');if(in_array($child,$tables,true)&&in_array($parent,$tables,true)&&$parent!==$child)$edges[$child][]=$parent;}
        $out=[];while($tables){$ready=array_values(array_filter($tables,static fn($name)=>!array_intersect($edges[$name]??[],$tables)));if(!$ready)throw new DomainException('Κυκλική εξάρτηση πινάκων.');foreach($ready as $name)$out[]=$name;$tables=array_values(array_diff($tables,$ready));}return $out;
    }
    public function import(string $path,string $sha,string $version,int $actor): int {
        $m=$this->verify($path,$sha,$version);$this->fresh($actor);$tables=array_keys($this->schema());$order=$this->order(array_values(array_diff($tables,['settings'])));$zip=$this->open($path,$sha);
        $mode=(string)$this->db->query('SELECT @@SESSION.sql_mode')->fetchColumn();$strict=array_values(array_unique(array_filter(array_merge(explode(',',$mode),['STRICT_ALL_TABLES']))));
        try{
            $this->db->exec('SET SESSION sql_mode='.$this->db->quote(implode(',',$strict)));
            $this->db->exec('SET FOREIGN_KEY_CHECKS=1');$this->db->beginTransaction();$this->db->query('SELECT id FROM '.$this->table('users').' FOR UPDATE')->fetchAll();$admin=$this->fresh($actor);
            $q=$this->db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');$all=$q->fetchAll(PDO::FETCH_COLUMN);
            foreach(['password_resets','reset_requests'] as $name)if(in_array($this->prefix.$name,$all,true))$this->db->exec('DELETE FROM `'.$this->prefix.$name.'`');
            foreach(array_reverse($order) as $name)$this->db->exec('DELETE FROM '.$this->table($name));
            $adminId=0;$maxId=0;
            foreach($order as $name){if(!isset($m['tables'][$name]))continue;$spec=$m['tables'][$name];
                foreach($this->rows($zip,$name,$spec) as $row){
                    if($name==='users'){
                        $id=filter_var($row['id'],FILTER_VALIDATE_INT);if(!$id||$id<1||$id>2147483646)throw new DomainException('Μη έγκυρο αναγνωριστικό καθηγητή.');$maxId=max($maxId,$id);
                        $row['password_hash']=null;$row['auth_version']=1;$row['status']=$row['status']==='inactive'?'inactive':'pending_activation';
                        if(mb_strtolower($row['email'],'UTF-8')===mb_strtolower($admin['email'],'UTF-8')){$adminId=$id;$row['password_hash']=$admin['password_hash'];$row['auth_version']=(int)$admin['auth_version']+1;$row['role']='admin';$row['status']='active';}
                    }
                    $columns=array_keys($row);$q=$this->db->prepare('INSERT INTO '.$this->table($name).' ('.implode(',',array_map(static fn($c)=>'`'.$c.'`',$columns)).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')');$q->execute(array_values($row));
                }
            }
            if(!$adminId){$adminId=$maxId+1;$keep=['id'=>$adminId,'name'=>$admin['name'],'email'=>$admin['email'],'password_hash'=>$admin['password_hash'],'auth_version'=>(int)$admin['auth_version']+1,'role'=>'admin','status'=>'active'];$columns=array_keys($keep);$this->db->prepare('INSERT INTO '.$this->table('users').' ('.implode(',',array_map(static fn($c)=>'`'.$c.'`',$columns)).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($keep));}
            $q=$this->db->prepare('DELETE FROM '.$this->table('settings').' WHERE setting_key=?');foreach(self::SETTINGS as $key)$q->execute([$key]);
            $this->db->exec('DELETE FROM '.$this->table('settings')." WHERE setting_key REGEXP '^academic_year_closed_[1-9][0-9]{0,9}$'");
            foreach($this->rows($zip,'settings',$m['tables']['settings']) as $row)$this->db->prepare('INSERT INTO '.$this->table('settings').' (setting_key,setting_value) VALUES(?,?)')->execute([$row['setting_key'],$row['setting_value']]);
            $this->db->commit();return $adminId;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}finally{$zip->close();$this->db->exec('SET SESSION sql_mode='.$this->db->quote($mode));}
    }
}
