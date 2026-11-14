<?php
require_once __DIR__.'/portable_recovery.php';

/** Data-only annual archives. Every input table, field and reference is allowlisted. */
final class AcademicYears {
    private const SHARED=['users','grades','sections','subjects'];
    private const OMIT=['settings','calendar_managers','mark_teacher_aliases'];
    private const REFS=[
        'sections'=>['grade_id'=>'grades'],
        'student_sections'=>['student_id'=>'students','section_id'=>'sections'],
        'teacher_sections'=>['teacher_id'=>'users','section_id'=>'sections'],
        'attendance'=>['student_id'=>'students','section_id'=>'sections','teacher_id'=>'users'],
        'attendance_notes'=>['student_id'=>'students','teacher_id'=>'users'],
        'absence_day_reasons'=>['student_id'=>'students','justified_by'=>'users'],
        'course_offerings'=>['section_id'=>'sections','subject_id'=>'subjects'],
        'course_teachers'=>['course_offering_id'=>'course_offerings','teacher_id'=>'users'],
        'course_students'=>['course_offering_id'=>'course_offerings','student_id'=>'students'],
        'student_marks'=>['course_student_id'=>'course_students','assessment_period_id'=>'assessment_periods','updated_by'=>'users'],
        'mark_submissions'=>['course_offering_id'=>'course_offerings','assessment_period_id'=>'assessment_periods','submitted_by'=>'users'],
        'mark_audit_log'=>['mark_id'=>'student_marks','submission_id'=>'mark_submissions','course_offering_id'=>'course_offerings','changed_by'=>'users'],
        'school_calendar_events'=>['created_by'=>'users','updated_by'=>'users'],
        'calendar_event_readers'=>['event_id'=>'school_calendar_events','user_id'=>'users'],
        'calendar_audit_log'=>['event_id'=>'school_calendar_events','changed_by'=>'users'],
        'notification_logs'=>['student_id'=>'students','sent_by'=>'users'],
    ];
    private array $schema;
    public function __construct(private PDO $db,private string $prefix='') {
        $this->schema=(new PortableSchoolRecovery($db,$prefix))->schema();
    }
    private function t(string $name): string {
        if(!isset(PortableSchoolRecovery::COLUMNS[$name])&&!in_array($name,['attendance_edit_changes','attendance_edit_batches'],true))throw new DomainException('Άγνωστος πίνακας.');
        return '`'.$this->prefix.$name.'`';
    }
    private function query(string $sql,array $params=[]): array {$q=$this->db->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public function year(int $id,bool $lock=false): array {
        $rows=$this->query('SELECT * FROM '.$this->t('academic_years').' WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);
        if(!$rows)throw new DomainException('Το σχολικό έτος δεν βρέθηκε.');return $rows[0];
    }
    public function closed(int $id): bool {return ($this->query('SELECT setting_value FROM '.$this->t('settings').' WHERE setting_key=?',['academic_year_closed_'.$id])[0]['setting_value']??'')==='1';}
    private function closure(int $id,bool $closed): void {
        $this->db->prepare('INSERT INTO '.$this->t('settings').'(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute(['academic_year_closed_'.$id,$closed?'1':'0']);
    }
    public function listing(): array {
        $rows=$this->query('SELECT * FROM '.$this->t('academic_years').' ORDER BY name DESC');
        foreach($rows as &$row){$id=(int)$row['id'];$row['closed']=$this->closed($id);$row['counts']=[];
            foreach(['students','attendance','course_offerings','assessment_periods','school_calendar_events'] as $table)if(isset($this->schema[$table]))$row['counts'][$table]=(int)$this->query('SELECT COUNT(*) AS n FROM '.$this->t($table).' WHERE academic_year_id=?',[$id])[0]['n'];
            $row['counts']['student_marks']=isset($this->schema['student_marks'])?(int)$this->query('SELECT COUNT(*) AS n FROM '.$this->t('student_marks').' m JOIN '.$this->t('course_students').' cs ON cs.id=m.course_student_id JOIN '.$this->t('course_offerings').' c ON c.id=cs.course_offering_id WHERE c.academic_year_id=?',[$id])[0]['n']:0;
        }unset($row);return $rows;
    }
    private function transaction(callable $fn): mixed {
        $this->db->beginTransaction();
        try{$this->query('SELECT id FROM '.$this->t('academic_years').' ORDER BY id FOR UPDATE');$result=$fn();$this->db->commit();return $result;}
        catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function create(string $name,bool $activate): int {
        if(!preg_match('/^(20\d{2})-(20\d{2})$/D',$name,$m)||(int)$m[2]!==1+(int)$m[1])throw new DomainException('Δώστε δύο διαδοχικά έτη, π.χ. 2027-2028.');
        return $this->transaction(function()use($name,$activate){
            if($this->query('SELECT id FROM '.$this->t('academic_years').' WHERE name=?',[$name]))throw new DomainException('Το σχολικό έτος υπάρχει ήδη.');
            // Only the year row is created. No pupils, teaching assignments, marks or absences are copied.
            $this->db->prepare('INSERT INTO '.$this->t('academic_years').'(name,is_active) VALUES(?,0)')->execute([$name]);$id=(int)$this->db->lastInsertId();
            if($activate)$this->activateRows($id);return $id;
        });
    }
    private function activateRows(int $id): void {
        $this->year($id);if($this->closed($id))throw new DomainException('Ανοίξτε πρώτα το κλειστό έτος πριν το ενεργοποιήσετε.');
        $this->db->exec('UPDATE '.$this->t('academic_years').' SET is_active=0');
        $this->db->prepare('UPDATE '.$this->t('academic_years').' SET is_active=1 WHERE id=?')->execute([$id]);
    }
    public function activate(int $id): void {$this->transaction(fn()=>$this->activateRows($id));}
    public function setClosed(int $id,bool $closed): void {
        $this->transaction(function()use($id,$closed){$year=$this->year($id);if($closed&&(int)$year['is_active']===1)throw new DomainException('Ενεργοποιήστε πρώτα το επόμενο έτος. Το ενεργό έτος δεν κλείνει.');$this->closure($id,$closed);});
    }
    private function scope(string $table,int $year): string {
        $y=(string)$year;$students='SELECT id FROM '.$this->t('students').' WHERE academic_year_id='.$y;
        $courses='SELECT id FROM '.$this->t('course_offerings').' WHERE academic_year_id='.$y;
        $events='SELECT id FROM '.$this->t('school_calendar_events').' WHERE academic_year_id='.$y;
        if(isset($this->schema[$table]['academic_year_id'])||in_array($table,['attendance_edit_changes','attendance_edit_batches'],true))return 'academic_year_id='.$y;
        return match($table){
            'student_sections','notification_logs'=>'student_id IN ('.$students.')',
            'course_teachers','course_students','mark_submissions','mark_audit_log'=>'course_offering_id IN ('.$courses.')',
            'student_marks'=>'course_student_id IN (SELECT id FROM '.$this->t('course_students').' WHERE course_offering_id IN ('.$courses.'))',
            'calendar_event_readers','calendar_audit_log'=>'event_id IN ('.$events.')',
            default=>throw new DomainException('Ο πίνακας δεν έχει ορισμένη σχέση με σχολικό έτος.'),
        };
    }
    public function snapshot(int $year): array {
        $record=$this->year($year);$data=[];
        foreach(PortableSchoolRecovery::COLUMNS as $table=>$allowed){
            if(!isset($this->schema[$table])||in_array($table,array_merge(self::SHARED,self::OMIT,['academic_years']),true))continue;
            $columns=array_values(array_intersect(explode(' ',$allowed),array_keys($this->schema[$table])));
            $data[$table]=$this->query('SELECT '.implode(',',array_map(fn($c)=>'`'.$c.'`',$columns)).' FROM '.$this->t($table).' WHERE '.$this->scope($table,$year).' ORDER BY '.(isset($this->schema[$table]['id'])?'id':implode(',',$columns)));
        }
        // Shared identities are included only when actually referenced by this year's rows.
        $needed=[];
        foreach($data as $table=>$rows)foreach($rows as $row)foreach(self::REFS[$table]??[] as $column=>$parent)if(in_array($parent,self::SHARED,true)&&$row[$column]!==null)$needed[$parent][(int)$row[$column]]=true;
        foreach(['sections','grades','subjects','users'] as $table){
            if(!isset($this->schema[$table]))continue;$data[$table]=[];
            $columns=$table==='users'?['id','name','teacher_registry_number','email']:explode(' ',PortableSchoolRecovery::COLUMNS[$table]);$columns=array_values(array_intersect($columns,array_keys($this->schema[$table])));
            foreach(array_chunk(array_keys($needed[$table]??[]),500) as $ids){$rows=$this->query('SELECT '.implode(',',$columns).' FROM '.$this->t($table).' WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id',$ids);foreach($rows as $row){$data[$table][]=$row;if($table==='sections')$needed['grades'][(int)$row['grade_id']]=true;}}
            usort($data[$table],fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
        }
        // Put parents before children, independently of source IDs and destination IDs.
        $ordered=[];foreach(array_merge(self::SHARED,array_keys(PortableSchoolRecovery::COLUMNS)) as $table)if(isset($data[$table])&&!isset($ordered[$table]))$ordered[$table]=$data[$table];
        return ['year'=>['id'=>(int)$record['id'],'name'=>$record['name']],'tables'=>$ordered];
    }
    public function export(int $year,string $path,string $version): array {
        if(file_exists($path))throw new DomainException('Το αρχείο υπάρχει ήδη.');
        $snapshot=$this->transaction(fn()=>$this->snapshot($year));$this->validate($snapshot);
        $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Δεν δημιουργήθηκε ZIP.');
        try{
            $m=['application'=>'absences-academic-year','format'=>1,'version'=>$version,'created'=>gmdate('c'),'year'=>$snapshot['year'],'tables'=>[]];$total=0;
            foreach($snapshot['tables'] as $table=>$rows){$raw='';foreach($rows as $row){$line=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";if(strlen($line)>1048576)throw new DomainException('Υπερβολικό μέγεθος εγγραφής.');$raw.=$line;}
                if(count($rows)>200000||($total+=strlen($raw))>134217728)throw new DomainException('Το έτος υπερβαίνει το όριο πακέτου.');
                $m['tables'][$table]=['rows'=>count($rows),'bytes'=>strlen($raw),'sha256'=>hash('sha256',$raw)];if(!$zip->addFromString('data/'.$table.'.ndjson',$raw))throw new RuntimeException('Αποτυχία εγγραφής ZIP.');
            }
            if(!$zip->addFromString('manifest.json',json_encode($m,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))||!$zip->close())throw new RuntimeException('Αποτυχία ολοκλήρωσης ZIP.');chmod($path,0600);
            $meta=$this->verify($path,hash_file('sha256',$path),$version);return ['sha256'=>$meta['sha256'],'year'=>$m['year'],'created'=>$m['created'],'counts'=>array_map(fn($t)=>$t['rows'],$m['tables']),'size'=>filesize($path)];
        }catch(Throwable $e){@$zip->close();@unlink($path);throw $e;}
    }
    public function verify(string $path,string $sha,string $version): array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha)||!is_file($path)||filesize($path)>67108864||!hash_equals($sha,hash_file('sha256',$path)))throw new DomainException('Το ZIP δεν συμφωνεί με το SHA-256 ή υπερβαίνει τα 64 MB.');
        $z=new ZipArchive();if($z->open($path,ZipArchive::CHECKCONS)!==true)throw new DomainException('Μη έγκυρο ZIP.');
        try{
            $raw=$z->getFromName('manifest.json',1048577);if($raw===false||strlen($raw)>1048576)throw new DomainException('Λείπει η περιγραφή του πακέτου.');$m=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            if(!is_array($m)||array_keys($m)!==['application','format','version','created','year','tables']||$m['application']!=='absences-academic-year'||$m['format']!==1||!is_string($m['version'])||!preg_match('/^\d+\.\d+\.\d+$/D',$m['version'])||version_compare($m['version'],$version,'>')||!is_string($m['created'])||!is_array($m['year'])||!is_array($m['tables'])||$z->numFiles!==count($m['tables'])+1)throw new DomainException('Μη συμβατό ετήσιο πακέτο.');
            $expected=['manifest.json'=>true];$data=[];$bytes=0;
            foreach($m['tables'] as $table=>$spec){
                if(!isset($this->schema[$table])||!isset(PortableSchoolRecovery::COLUMNS[$table])||in_array($table,array_merge(self::OMIT,['academic_years']),true)||!is_array($spec)||array_keys($spec)!==['rows','bytes','sha256']||!is_int($spec['rows'])||$spec['rows']<0||$spec['rows']>200000||!is_int($spec['bytes'])||$spec['bytes']<0||($bytes+=$spec['bytes'])>134217728||!is_string($spec['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$spec['sha256']))throw new DomainException('Μη επιτρεπόμενα δεδομένα στο πακέτο.');
                $name='data/'.$table.'.ndjson';$expected[$name]=true;$stat=$z->statName($name);if(!$stat||$stat['size']!==$spec['bytes'])throw new DomainException('Λανθασμένο μέγεθος δεδομένων.');
                $text=$z->getFromName($name);if(!is_string($text)||!hash_equals($spec['sha256'],hash('sha256',$text)))throw new DomainException('Αποτυχία ακεραιότητας δεδομένων.');$rows=[];
                foreach(explode("\n",$text) as $line){if($line==='')continue;if(strlen($line)>1048576||count($rows)>=200000)throw new DomainException('Υπερβολική εγγραφή.');$rows[]=json_decode($line,true,32,JSON_THROW_ON_ERROR);}
                if(count($rows)!==$spec['rows'])throw new DomainException('Λανθασμένο πλήθος εγγραφών.');$data[$table]=$rows;
            }
            $seen=[];for($i=0;$i<$z->numFiles;$i++){$stat=$z->statIndex($i);$z->getExternalAttributesIndex($i,$os,$attr);$type=($attr>>16)&0170000;if(!isset($expected[$stat['name']])||isset($seen[$stat['name']])||($type!==0&&$type!==0100000)||($stat['encryption_method']??0)!==0)throw new DomainException('Το πακέτο περιέχει μη ασφαλή ή πρόσθετα αρχεία.');$seen[$stat['name']]=true;}
            $snapshot=['year'=>$m['year'],'tables'=>$data];$this->validate($snapshot);return $snapshot+['sha256'=>$sha,'created'=>$m['created'],'version'=>$m['version'],'counts'=>array_map(fn($r)=>count($r),$data)];
        }catch(JsonException $e){throw new DomainException('Μη έγκυρο JSON στο πακέτο.');}finally{$z->close();}
    }
    private function validate(array $snapshot): void {
        $year=$snapshot['year'];$data=$snapshot['tables'];
        if(array_keys($year)!==['id','name']||!is_int($year['id'])||$year['id']<1||!is_string($year['name'])||!preg_match('/^(20\d{2})-(20\d{2})$/D',$year['name'],$m)||(int)$m[2]!==1+(int)$m[1])throw new DomainException('Μη έγκυρο σχολικό έτος στο πακέτο.');
        foreach(['students','student_sections','teacher_sections','attendance','users','grades','sections'] as $t)if(!isset($data[$t]))throw new DomainException('Λείπουν βασικοί πίνακες του σχολικού έτους.');
        $ids=[];
        foreach($data as $table=>$rows){
            $cols=$table==='users'?['id','name','teacher_registry_number','email']:explode(' ',PortableSchoolRecovery::COLUMNS[$table]);$cols=array_values(array_intersect($cols,array_keys($this->schema[$table])));
            foreach($rows as $row){
                if(!is_array($row)||array_is_list($row)||array_keys($row)!==$cols)throw new DomainException('Μη επιτρεπόμενες ή ελλιπείς στήλες στο πακέτο.');
                foreach($row as $v)if(!is_string($v)&&!is_int($v)&&!is_float($v)&&$v!==null)throw new DomainException('Μη έγκυρη τιμή στο πακέτο.');
                if(isset($row['id'])){if(!ctype_digit((string)$row['id'])||(int)$row['id']<1||isset($ids[$table][(string)$row['id']]))throw new DomainException('Διπλό ή μη έγκυρο αναγνωριστικό.');$ids[$table][(string)$row['id']]=true;}
                if(isset($row['academic_year_id'])&&(int)$row['academic_year_id']!==$year['id'])throw new DomainException('Το πακέτο περιέχει δεδομένα άλλου σχολικού έτους.');
            }
        }
        foreach($data as $table=>$rows)foreach($rows as $row)foreach(self::REFS[$table]??[] as $col=>$parent)if($row[$col]!==null&&!isset($ids[$parent][(string)$row[$col]]))throw new DomainException('Η εγγραφή αναφέρεται σε δεδομένα έξω από το σχολικό έτος.');
    }
    public function preview(string $path,string $sha,string $version): array {
        $m=$this->verify($path,$sha,$version);
        if($this->query('SELECT id FROM '.$this->t('academic_years').' WHERE name=?',[$m['year']['name']]))throw new DomainException('Το έτος υπάρχει ήδη. Η επαναφορά δεν αντικαθιστά υπάρχον έτος. Κρατήστε backup και διαγράψτε το πρώτα από τη διαχείριση ετών, αν θέλετε να το επαναφέρετε.');
        return ['year'=>$m['year'],'sha256'=>$sha,'counts'=>$m['counts'],'created'=>$m['created']];
    }
    private function insert(string $table,array $row): int {
        $cols=array_keys($row);$this->db->prepare('INSERT INTO '.$this->t($table).' (`'.implode('`,`',$cols).'`) VALUES('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($row));return (int)$this->db->lastInsertId();
    }
    public function restore(string $path,string $sha,string $version): int {
        $m=$this->verify($path,$sha,$version);$mode=(string)$this->db->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        try{$this->db->exec('SET SESSION sql_mode='.$this->db->quote($mode.',STRICT_ALL_TABLES'));$this->db->exec('SET FOREIGN_KEY_CHECKS=1');
            return $this->transaction(function()use($m){
                if($this->query('SELECT id FROM '.$this->t('academic_years').' WHERE name=?',[$m['year']['name']]))throw new DomainException('Το έτος υπάρχει ήδη. Δεν έγινε αντικατάσταση.');
                $year=$this->insert('academic_years',['name'=>$m['year']['name'],'is_active'=>0]);$map=[];
                $order=array_merge(self::SHARED,array_values(array_diff(array_keys(PortableSchoolRecovery::COLUMNS),array_merge(self::SHARED,self::OMIT,['academic_years']))));
                foreach($order as $table)foreach($m['tables'][$table]??[] as $source){
                    $row=$source;$old=$row['id']??null;unset($row['id']);
                    foreach(self::REFS[$table]??[] as $col=>$parent)if($row[$col]!==null){if(!isset($map[$parent][(string)$row[$col]]))throw new DomainException('Ανεπίλυτη σχέση δεδομένων.');$row[$col]=$map[$parent][(string)$row[$col]];}
                    if(isset($row['academic_year_id']))$row['academic_year_id']=$year;
                    if(in_array($table,self::SHARED,true)){
                        $existing=[];
                        if($table==='users'){
                            $email=$this->query('SELECT id,teacher_registry_number FROM '.$this->t('users').' WHERE email=?',[$row['email']]);$registry=empty($row['teacher_registry_number'])?[]:$this->query('SELECT id,teacher_registry_number FROM '.$this->t('users').' WHERE teacher_registry_number=?',[$row['teacher_registry_number']]);
                            if(($email&&$registry&&(int)$email[0]['id']!==(int)$registry[0]['id'])||($email&&!empty($row['teacher_registry_number'])&&!empty($email[0]['teacher_registry_number'])&&$row['teacher_registry_number']!==$email[0]['teacher_registry_number']))throw new DomainException('Σύγκρουση ΑΜ και email καθηγητή. Διορθώστε την αντιστοίχιση πριν την επαναφορά.');
                            $existing=$registry?:$email;$row['role']='teacher';$row['status']='inactive';$row['password_hash']=null;
                        }elseif($table==='sections')$existing=$this->query('SELECT id FROM '.$this->t($table).' WHERE grade_id=? AND name=?',[$row['grade_id'],$row['name']]);
                        else{$key=$table==='subjects'?'source_name':'name';$existing=$this->query('SELECT id FROM '.$this->t($table).' WHERE `'.$key.'`=?',[$row[$key]]);}
                        $id=$existing?(int)$existing[0]['id']:$this->insert($table,$row);
                    }else $id=$this->insert($table,$row);
                    if($old!==null){if(in_array($id,$map[$table]??[],true))throw new DomainException('Δύο ιστορικές ταυτότητες αντιστοιχίστηκαν στην ίδια εγγραφή.');$map[$table][(string)$old]=$id;}
                }
                $this->closure($year,true);return $year;
            });
        }finally{$this->db->exec('SET SESSION sql_mode='.$this->db->quote($mode));}
    }
    public function delete(int $year,string $path,string $sha,string $version): void {
        $m=$this->verify($path,$sha,$version);
        $this->transaction(function()use($year,$m){
            $row=$this->year($year);if((int)$row['is_active']===1||!$this->closed($year)||(int)$this->query('SELECT COUNT(*) n FROM '.$this->t('academic_years'))[0]['n']<2)throw new DomainException('Διαγράφεται μόνο κλειστό, μη ενεργό έτος.');
            if($m['year']['id']!==$year||$m['year']['name']!==$row['name']||$m['tables']!==$this->snapshot($year)['tables'])throw new DomainException('Το έτος άλλαξε μετά το backup. Δημιουργήστε και κατεβάστε νέο backup πριν τη διαγραφή.');
            // Unknown extension FKs abort rather than cascading into another year or application.
            $tables=array_values(array_diff(array_keys($m['tables']),self::SHARED));$tables[]='academic_years';
            $all=$this->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()');$all=array_column($all,'TABLE_NAME');
            foreach(['attendance_edit_batches','attendance_edit_changes'] as $t)if(in_array($this->prefix.$t,$all,true))$tables[]=$t;
            $physical=array_map(fn($t)=>$this->prefix.$t,$tables);$edges=[];
            foreach($this->query('SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()') as $fk){
                if(!in_array($fk['REFERENCED_TABLE_NAME'],$physical,true))continue;
                if(!in_array($fk['TABLE_NAME'],$physical,true))throw new DomainException('Υπάρχει πρόσθετη εξάρτηση από το σχολικό έτος. Η διαγραφή ακυρώθηκε.');
                $edges[$fk['TABLE_NAME']][]=$fk['REFERENCED_TABLE_NAME'];
            }
            while($tables){$parents=[];foreach($tables as $t)$parents=array_merge($parents,array_intersect($edges[$this->prefix.$t]??[],array_map(fn($n)=>$this->prefix.$n,$tables)));$leaves=array_values(array_filter($tables,fn($t)=>!in_array($this->prefix.$t,$parents,true)));if(!$leaves)throw new DomainException('Κυκλική εξάρτηση κατά τη διαγραφή.');
                foreach($leaves as $t)$this->db->exec('DELETE FROM '.$this->t($t).' WHERE '.($t==='academic_years'?'id='.(int)$year:$this->scope($t,$year)));$tables=array_values(array_diff($tables,$leaves));
            }
            $this->db->prepare('DELETE FROM '.$this->t('settings').' WHERE setting_key=?')->execute(['academic_year_closed_'.$year]);
        });
    }
}
