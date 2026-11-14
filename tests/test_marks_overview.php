<?php
// Offline filtering, pivot and HTML checks. No database connection or real data.
require_once __DIR__.'/../public_html/includes/marks_overview_data.php';
function overviewCheck(bool $ok,string $label): void {
    if(!$ok) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$courses=[
    ['id'=>10,'grade_id'=>1,'grade_name'=>'Α','section_id'=>11,'section_name'=>'Α1','subject_id'=>100,'subject_name'=>'Φυσική','is_active'=>1,'has_written_exam'=>1],
    ['id'=>20,'grade_id'=>1,'grade_name'=>'Α','section_id'=>11,'section_name'=>'Α1','subject_id'=>200,'subject_name'=>'Χημεία','is_active'=>1,'has_written_exam'=>0],
    ['id'=>30,'grade_id'=>1,'grade_name'=>'Α','section_id'=>12,'section_name'=>'Α2','subject_id'=>100,'subject_name'=>'Φυσική','is_active'=>1,'has_written_exam'=>1],
    ['id'=>40,'grade_id'=>2,'grade_name'=>'Β','section_id'=>21,'section_name'=>'Β1','subject_id'=>100,'subject_name'=>'Φυσική','is_active'=>1,'has_written_exam'=>1],
];
$teachers=[10=>[5=>'Teacher'],20=>[6=>'Other'],30=>[5=>'Teacher',6=>'Other'],40=>[5=>'Teacher']];
$ids=static fn($input)=>array_column(marksOverviewCourses($courses,$teachers,marksOverviewFilters($input)),'id');
overviewCheck($ids([])===[10,20,30,40],'all filters optional');
overviewCheck($ids(['grade_id'=>'1'])===[10,20,30],'grade includes all its sections');
overviewCheck($ids(['grade_id'=>'1','section_id'=>'11'])===[10,20],'section includes all its subjects');
overviewCheck($ids(['teacher_id'=>'5'])===[10,30,40],'teacher alone includes courses across grades');
overviewCheck($ids(['teacher_id'=>'5','grade_id'=>'1'])===[10,30],'teacher and grade intersect');
overviewCheck($ids(['teacher_id'=>'6','section_id'=>'11'])===[20],'teacher and section intersect');
overviewCheck($ids(['subject_id'=>'100','grade_id'=>'1','teacher_id'=>'6'])===[30],'subject uses subject ID across sections and supports co-teachers');
overviewCheck($ids(['grade_id'=>'2','section_id'=>'11'])===[],'contradictory filters never broaden results');
foreach([['grade_id'=>[]],['teacher_id'=>'1 OR 1'],['period'=>[]],['period'=>'invalid']] as $input) {
    try { marksOverviewFilters($input); throw new RuntimeException('Invalid filter accepted'); }
    catch(DomainException $e) {}
}
echo "PASS: invalid filters rejected\n";
$periods=[['id'=>1,'code'=>'term1','name'=>'Α΄ τετράμηνο'],['id'=>2,'code'=>'term2','name'=>'Β΄ τετράμηνο'],['id'=>3,'code'=>'written','name'=>'Γραπτά']];
foreach(['term1'=>[1],'term2'=>[2],'written'=>[3],'terms'=>[1,2],'all'=>[1,2,3]] as $selection=>$expected) {
    overviewCheck(array_column(marksOverviewPeriods($periods,$selection),'id')===$expected,'period columns '.$selection);
}
$row=static fn($student,$course,$code,$value,$status)=>['student_id'=>$student,'course_offering_id'=>$course,'am'=>(string)$student,'last_name'=>'Ίδιο','first_name'=>'Όνομα','code'=>$code,'mark_value'=>$value,'mark_status'=>$status];
$rows=[$row(1,10,'term1','0.00','value'),$row(1,10,'term2',null,'not_submitted'),$row(1,20,null,null,null),$row(2,10,'written',null,'no_show'),$row(3,30,'term1','18.00','value')];
$groups=marksOverviewGroups($courses,$rows);
overviewCheck(count($groups[11]['students'])===2,'same-name students stay separate by ID');
overviewCheck(count($groups[11]['students'][1]['courses'])===2,'one row per student across subjects');
overviewCheck(!isset($groups[11]['students'][2]['courses'][20]),'non-participation differs from missing grade');
overviewCheck(count(marksOverviewGroups([$courses[0]],$rows)[11]['students'])===2,'pivot excludes rows outside selected courses');

class OverviewFixtureStatement extends PDOStatement {
    public function __construct(private array $rows) {}
    public function execute(?array $params=null): bool { return true; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return $this->rows; }
}
class OverviewFixtureDB extends PDO {
    public array $queries=[];
    public function __construct(private array $teachers,private array $rows) {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $this->queries[]=$query;
        return new OverviewFixtureStatement(str_contains($query,'FROM course_teachers')?$this->teachers:$this->rows);
    }
}
$teacherRows=[];
foreach($teachers as $course=>$items) foreach($items as $id=>$name) $teacherRows[]=['course_offering_id'=>$course,'teacher_id'=>$id,'name'=>$name];
$db=new OverviewFixtureDB($teacherRows,$rows);
$admin=true; $yearId=1; $_GET=['period'=>'all'];
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$displayGrade=static fn($value)=>str_pad((string)(int)$value,2,'0',STR_PAD_LEFT);
$url=static fn($params)=>'marks.php?'.http_build_query($params);
$specialMarkLabels=['not_submitted'=>'Δεν κατατέθηκε βαθμός','no_show'=>'Δεν προσήλθε'];
ob_start(); require __DIR__.'/../public_html/includes/marks_overview.php'; $html=ob_get_clean();
overviewCheck(substr_count($html,'scope="colgroup"')===4,'render groups columns by course');
overviewCheck(str_contains($html,'rowspan="2"')&&str_contains($html,'colspan="3"'),'two header rows and three period subcolumns');
overviewCheck(str_contains($html,'>00</td>')&&str_contains($html,'>Δ</td>')&&str_contains($html,'>-1</td>'),'zero and special marks rendered distinctly');
overviewCheck(str_contains($html,'>Μ/Ε</td>')&&str_contains($html,'Δεν συμμετέχει στη διδασκαλία'),'non-written subjects and non-participation labelled');
overviewCheck(str_contains($db->queries[1],'c.id IN (10,20,30,40)')&&str_contains($db->queries[1],'p.academic_year_id=c.academic_year_id'),'read query bounded by courses and school year');
