<?php
// MySchool's three reports. Only allowlisted columns enter preview/session/storage.
require_once __DIR__.'/marks_import.php';
require_once __DIR__.'/greek_helper.php';

function myschoolRegistryReady(PDO $db): bool {
    try {$db->query('SELECT teacher_registry_number FROM users LIMIT 0');return true;}
    catch(PDOException $e){if((int)($e->errorInfo[1]??0)===1054)return false;throw $e;}
}
function myschoolInstallRegistry(PDO $db): void {
    if(!myschoolRegistryReady($db))$db->exec('ALTER TABLE users ADD COLUMN teacher_registry_number VARCHAR(30) DEFAULT NULL, ADD UNIQUE KEY unique_teacher_registry_number (teacher_registry_number)');
}
function myschoolNumber(string $value): string {
    $value=trim($value);
    if(!preg_match('/^[0-9]{1,30}$/D',$value))throw new DomainException('Ο ΑΜ πρέπει να περιέχει από 1 έως 30 ψηφία.');
    return $value;
}
function myschoolNameKey(string $value): string {
    $parts=preg_split('/\s+/u',marksNameKey($value));sort($parts,SORT_STRING);return implode(' ',$parts);
}
function myschoolGrade(string $section): string {
    $s=marksNameKey($section);$s=strtr($s,['A'=>'Α','B'=>'Β']);
    return preg_match('/^([ΑΒΓ])(?:[0-9\s\-]|$)/u',$s,$m)?$m[1]:'';
}
function myschoolReadPeople(string $path,string $kind): array {
    if(!in_array($kind,['students','teachers'],true))throw new DomainException('Μη έγκυρος τύπος αναφοράς.');
    if(!is_file($path)||filesize($path)>2097152)throw new DomainException('Χρειάζεται αρχείο XLS/XLSX έως 2 MB.');
    require_once __DIR__.'/spreadsheet/vendor/autoload.php';
    $magic=file_get_contents($path,false,null,0,8);
    if($magic==="\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")$reader=new \PhpOffice\PhpSpreadsheet\Reader\Xls();
    elseif(str_starts_with($magic,"PK\x03\x04")){
        $zip=new ZipArchive();if($zip->open($path)!==true)throw new DomainException('Μη έγκυρο XLSX.');
        try{$total=0;if($zip->numFiles>500)throw new DomainException('Πολύ σύνθετο XLSX.');for($i=0;$i<$zip->numFiles;$i++){$s=$zip->statIndex($i);$total+=$s['size'];if($s['size']>4194304||$total>16777216||$s['size']/max(1,$s['comp_size'])>100||preg_match('~vbaProject|externalLinks|embeddings~i',$s['name']))throw new DomainException('Μη υποστηριζόμενο XLSX.');}}finally{$zip->close();}
        $reader=new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    }else throw new DomainException('Χρειάζεται πραγματικό XLS ή XLSX MySchool.');
    $reader->setReadDataOnly(true);$info=$reader->listWorksheetInfo($path);
    if(count($info)!==1||$info[0]['totalRows']>5000||$info[0]['totalColumns']>30)throw new DomainException('Χρειάζεται ένα φύλλο έως 5.000 γραμμές και 30 στήλες.');
    $columns=$kind==='students'?['A','B','C','D','G']:['A','B','C','D'];
    $reader->setReadFilter(new class($columns) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
        public function __construct(private array $columns){}
        public function readCell(string $columnAddress,int $row,string $worksheetName=''): bool {return $row<=5000&&in_array($columnAddress,$this->columns,true);}
    });
    $book=$reader->load($path);
    try{
        $sheet=$book->getSheet(0);$header=0;
        $cell=static function(string $column,int $row)use($sheet):string{$c=$sheet->getCell($column.$row);if($c->getDataType()==='f')throw new DomainException('Δεν επιτρέπονται τύποι στις στήλες δεδομένων.');return trim((string)$c->getValue());};
        for($i=1;$i<=min(50,$sheet->getHighestDataRow());$i++){
            if(marksNameKey($cell('B',$i))==='ΑΡΙΘΜΟΣ ΜΗΤΡΩΟΥ'&&str_starts_with(marksNameKey($cell('C',$i)),'ΕΠΩΝΥΜΟ')&&str_starts_with(marksNameKey($cell('D',$i)),'ΟΝΟΜΑ')){$header=$i;break;}
        }
        if(!$header||($kind==='students'&&marksNameKey($cell('G',$header))!=='ΤΜΗΜΑΤΑ')||($kind==='teachers'&&(marksNameKey($cell('C',$header))!=='ΕΠΩΝΥΜΟ'||marksNameKey($cell('D',$header))!=='ΟΝΟΜΑ')))throw new DomainException('Δεν αναγνωρίστηκαν οι απαιτούμενες στήλες της αναφοράς '.($kind==='students'?'μαθητών':'μητρώου καθηγητών').'.');
        $out=[];$grade='';
        for($i=$header+1;$i<=$sheet->getHighestDataRow();$i++){
            $a=$cell('A',$i);$raw=$cell('B',$i);
            if(str_contains(marksNameKey($a.' '.$raw),'ΤΑΞΗ ΕΓΓΡΑΦΗΣ')){
                $text=marksNameKey($a.' '.$raw.' '.$cell('C',$i).' '.$cell('D',$i));
                if(preg_match('/ΤΑΞΗ ΕΓΓΡΑΦΗΣ\s*:?\s*([ΑΒΓ])\b/u',$text,$m))$grade=$m[1];
                continue;
            }
            if($raw===''||!preg_match('/^[0-9]+$/D',$raw))continue;
            $am=myschoolNumber($raw);$last=$cell('C',$i);$first=$cell('D',$i);
            if($last===''||$first===''||mb_strlen($last)>100||mb_strlen($first)>100||mb_strlen($last.' '.$first)>150)throw new DomainException('Ελλιπές ή πολύ μεγάλο όνομα στη γραμμή '.$i.'.');
            if(isset($out[$am]))throw new DomainException('Διπλός ΑΜ στη γραμμή '.$i.'.');
            $item=['am'=>$am,'last_name'=>$last,'first_name'=>$first,'name'=>$last.' '.$first,'line'=>$i];
            if($kind==='students'){
                $sections=array_values(array_unique(array_filter(array_map('trim',preg_split('/[,;\/\r\n]+/u',$cell('G',$i))))));
                if(!$sections)throw new DomainException('Λείπουν τα τμήματα μαθητή στη γραμμή '.$i.'.');
                foreach($sections as $section)if(mb_strlen($section)>100)throw new DomainException('Πολύ μεγάλο όνομα τμήματος στη γραμμή '.$i.'.');
                $inferred=array_values(array_unique(array_filter(array_map('myschoolGrade',$sections))));
                $item['sections']=$sections;$item['grade']=$grade?:(count($inferred)===1?$inferred[0]:'');
            }
            $out[$am]=$item;
        }
        if(!$out)throw new DomainException('Δεν βρέθηκαν εγγραφές στην αναφορά.');
        return array_values($out);
    }finally{$book->disconnectWorksheets();}
}
function myschoolSetTeacherNumber(PDO $db,int $id,mixed $value): void {
    if(!is_string($value))throw new DomainException('Μη έγκυρος ΑΜ καθηγητή.');
    $am=trim($value)===''?null:myschoolNumber($value);
    if($am!==null){$q=$db->prepare('SELECT id FROM users WHERE teacher_registry_number=? AND id<>?');$q->execute([$am,$id]);if($q->fetchColumn())throw new DomainException('Ο ΑΜ χρησιμοποιείται ήδη από άλλον καθηγητή.');}
    $db->prepare('UPDATE users SET teacher_registry_number=? WHERE id=?')->execute([$am,$id]);
}
function myschoolPreview(array $students,array $teachers,array $assignments): array {
    $byName=[];$sections=[];$links=[];
    foreach($teachers as $t)$byName[myschoolNameKey($t['name'])][]=$t['am'];
    foreach($students as $s)foreach($s['sections'] as $section){$grade=$s['grade']?:myschoolGrade($section);if(isset($sections[$section])&&$sections[$section]!==$grade)$sections[$section]='';else $sections[$section]=$grade;}
    foreach($assignments['rows'] as $row){if(!isset($sections[$row['section']]))$sections[$row['section']]=myschoolGrade($row['section']);$candidates=$byName[myschoolNameKey($row['teacher_name'])]??[];$links[$row['teacher_key']]=['name'=>$row['teacher_name'],'am'=>count($candidates)===1?$candidates[0]:''];}
    return ['students'=>$students,'teachers'=>$teachers,'assignments'=>$assignments,'sections'=>$sections,'links'=>$links];
}
function myschoolAccountMatches(PDO $db,array $preview): array {
    $users=$db->query("SELECT id,name,email,status,role,teacher_registry_number FROM users WHERE role IN ('teacher','teacher_admin','admin') ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC);$matches=[];
    foreach($preview['teachers'] as $t){$ids=[];$exact=[];foreach($users as $u){if((string)$u['teacher_registry_number']===$t['am'])$exact[]=$u['id'];elseif($u['teacher_registry_number']===null&&myschoolNameKey($u['name'])===myschoolNameKey($t['name']))$ids[]=$u['id'];}$matches[$t['am']]=count($exact)===1?$exact[0]:(count($ids)===1?$ids[0]:($ids?'':'new'));}
    return ['users'=>$users,'matches'=>$matches];
}
function myschoolState(PDO $db,int $year): string {
    $state=[];
    foreach(['users'=>'SELECT id,name,email,role,status,teacher_registry_number FROM users ORDER BY id','sections'=>'SELECT id,grade_id,name FROM sections ORDER BY id','students'=>'SELECT id,am,last_name,first_name FROM students WHERE academic_year_id='.(int)$year.' ORDER BY id','memberships'=>'SELECT ss.student_id,ss.section_id FROM student_sections ss JOIN students s ON s.id=ss.student_id WHERE s.academic_year_id='.(int)$year.' ORDER BY ss.student_id,ss.section_id','courses'=>'SELECT id,version FROM course_offerings WHERE academic_year_id='.(int)$year.' ORDER BY id'] as $key=>$sql)$state[$key]=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));
}
function myschoolCommit(PDO $db,int $year,array $actor,array $preview,array $accounts,array $links,array $sectionMap,array $grades,string $policy): array {
    if(($actor['role']??'')!=='admin'||!in_array($policy,['preserve','replace'],true))throw new DomainException('Μη έγκυρη εισαγωγή.');
    if(!myschoolRegistryReady($db))throw new DomainException('Απαιτείται προετοιμασία του ΑΜ καθηγητών.');
    $db->beginTransaction();
    try{
        marksActiveYearLock($db,$year);
        $db->query('SELECT id FROM users ORDER BY id FOR UPDATE')->fetchAll();
        if(!is_string($preview['snapshot']??null)||!hash_equals($preview['snapshot'],myschoolState($db,$year)))throw new DomainException('Τα δεδομένα άλλαξαν μετά την προεπισκόπηση. Ανεβάστε ξανά τα αρχεία.');
        $q=$db->prepare("SELECT id FROM users WHERE id=? AND role='admin' AND status='active' FOR UPDATE");$q->execute([$actor['id']]);if(!$q->fetchColumn())throw new DomainException('Απαιτείται ενεργός διαχειριστής.');
        $q=$db->prepare('SELECT name FROM academic_years WHERE id=?');$q->execute([$year]);if($q->fetchColumn()!==$preview['assignments']['year_name'])throw new DomainException('Το έτος της αναφοράς δεν είναι ενεργό.');
        $resolvedSections=[];$sectionIds=[];
        foreach($preview['sections'] as $name=>$_){
            $selection=$sectionMap[hash('sha256',$name)]??'';
            if($selection==='new'){
                $grade=$grades[hash('sha256',$name)]??'';if(!in_array($grade,['Α','Β','Γ'],true))throw new DomainException('Επιλέξτε τάξη για κάθε νέο τμήμα.');
                $db->prepare('INSERT IGNORE INTO grades(name) VALUES(?)')->execute([$grade]);$q=$db->prepare('SELECT id FROM grades WHERE name=?');$q->execute([$grade]);$gid=(int)$q->fetchColumn();
                $db->prepare('INSERT IGNORE INTO sections(grade_id,name) VALUES(?,?)')->execute([$gid,$name]);$q=$db->prepare('SELECT id FROM sections WHERE grade_id=? AND name=?');$q->execute([$gid,$name]);$id=(int)$q->fetchColumn();
            }else{$id=schoolRequestId($selection);$q=$db->prepare('SELECT id FROM sections WHERE id=?');$q->execute([$id]);if(!$id||!$q->fetchColumn())throw new DomainException('Αντιστοιχίστε όλα τα τμήματα.');}
            if(isset($sectionIds[$id])&&$sectionIds[$id]!==$name)throw new DomainException('Δύο διαφορετικά τμήματα αντιστοιχίστηκαν στο ίδιο τμήμα.');
            $resolvedSections[$name]=$id;$sectionIds[$id]=$name;
        }
        $newStudents=0;$updatedStudents=0;
        foreach($preview['students'] as $s){
            $q=$db->prepare('SELECT id FROM students WHERE academic_year_id=? AND am=? FOR UPDATE');$q->execute([$year,$s['am']]);$id=(int)$q->fetchColumn();
            if($id){$db->prepare('UPDATE students SET last_name=?,first_name=? WHERE id=?')->execute([$s['last_name'],$s['first_name'],$id]);$updatedStudents++;}
            else{$db->prepare('INSERT INTO students(academic_year_id,am,last_name,first_name,gender) VALUES(?,?,?,?,?)')->execute([$year,$s['am'],$s['last_name'],$s['first_name'],detect_gender_from_name($s['first_name'])]);$id=(int)$db->lastInsertId();$newStudents++;}
            $db->prepare('DELETE FROM student_sections WHERE student_id=?')->execute([$id]);
            foreach($s['sections'] as $name)$db->prepare('INSERT INTO student_sections(student_id,section_id) VALUES(?,?)')->execute([$id,$resolvedSections[$name]]);
        }
        $resolvedTeachers=[];$usedAccounts=[];$newTeachers=0;
        foreach($preview['teachers'] as $t){
            $selection=$accounts[$t['am']]??'';
            $q=$db->prepare('SELECT id FROM users WHERE teacher_registry_number=? FOR UPDATE');$q->execute([$t['am']]);$owner=(int)$q->fetchColumn();
            if($selection==='new'){
                if($owner)throw new DomainException('Ο ΑΜ ανήκει ήδη σε λογαριασμό. Ανανεώστε την προεπισκόπηση.');
                $db->prepare("INSERT INTO users(name,email,role,status,teacher_registry_number) VALUES(?,?,'teacher','pending_activation',?)")->execute([$t['name'],'pending.'.bin2hex(random_bytes(12)).'@myschool.local',$t['am']]);$id=(int)$db->lastInsertId();$newTeachers++;
            }else{
                $id=schoolRequestId($selection);$q=$db->prepare('SELECT teacher_registry_number,role FROM users WHERE id=? FOR UPDATE');$q->execute([$id]);$u=$q->fetch(PDO::FETCH_ASSOC);
                if(!$id||!$u||!in_array($u['role'],['admin','teacher','teacher_admin'],true)||($owner&&$owner!==$id)||($u['teacher_registry_number']!==null&&$u['teacher_registry_number']!==$t['am']))throw new DomainException('Ασυμβίβαστη αντιστοίχιση ΑΜ καθηγητή.');
                $db->prepare('UPDATE users SET teacher_registry_number=?,name=? WHERE id=?')->execute([$t['am'],$t['name'],$id]);
            }
            if(isset($usedAccounts[$id]))throw new DomainException('Δύο μητρώα αντιστοιχίστηκαν στον ίδιο λογαριασμό.');$usedAccounts[$id]=true;$resolvedTeachers[$t['am']]=$id;
        }
        $teacherMap=[];
        foreach($preview['links'] as $key=>$_){$am=$links[hash('sha256',$key)]??'';if(!isset($resolvedTeachers[$am]))throw new DomainException('Αντιστοιχίστε κάθε όνομα αναθέσεων σε έναν ΑΜ καθηγητή.');$teacherMap[$key]=$resolvedTeachers[$am];}
        $n=marksCommitImport($db,$year,$actor,$preview['assignments'],$teacherMap,$resolvedSections,$policy,false);
        // Membership changes also affect existing courses not present in this report.
        foreach($db->query('SELECT * FROM course_offerings WHERE academic_year_id='.(int)$year.' ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $course)marksSyncRoster($db,$course);
        $db->commit();return ['students_new'=>$newStudents,'students_updated'=>$updatedStudents,'teachers_new'=>$newTeachers,'assignments'=>$n];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
