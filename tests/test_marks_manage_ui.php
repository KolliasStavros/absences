<?php
// Render the actual management template with synthetic PDO fixtures only.
require __DIR__.'/test_marks_overview.php';
class ManageFixtureDB extends OverviewFixtureDB {
    public function query(string $query,?int $fetchMode=null,mixed ...$args): PDOStatement|false {
        return new OverviewFixtureStatement([['id'=>5,'name'=>'Teacher','status'=>'active'],['id'=>6,'name'=>'Other','status'=>'active'],['id'=>7,'name'=>'Pending Teacher','status'=>'pending_activation'],['id'=>8,'name'=>'Inactive Teacher','status'=>'inactive']]);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(str_contains($query,'SELECT ct.*')) return new OverviewFixtureStatement([
            ['teacher_id'=>5,'name'=>'Teacher','is_active'=>1,'can_edit_marks'=>1],
            ['teacher_id'=>6,'name'=>'Other','is_active'=>0,'can_edit_marks'=>0]
        ]);
        return parent::prepare($query,$options);
    }
}
$courses=array_map(static fn($c)=>$c+['version'=>4],$courses);
$course=$courses[0]; $courseId=$course['id'];
$diff=['add'=>[],'remove'=>[]]; $error=''; $view='manage';
$baseFields=static function() { echo '<input name="csrf_token" value="fixture"><input name="year_id" value="1">'; };
$db=new ManageFixtureDB($teacherRows,[]); $_GET=['view'=>'manage']; $_POST=[]; $_SESSION=[];
ob_start(); require __DIR__.'/../public_html/includes/marks_manage.php'; $html=ob_get_clean();
overviewCheck(str_contains($html,'Pending Teacher · Αναμονή ενεργοποίησης')&&str_contains($html,'Inactive Teacher · Ανενεργός'),'pending and inactive teachers appear with status');
overviewCheck(str_contains($html,'name="action" value="assignment_add"')&&str_contains($html,'name="action" value="assignment_replace"'),'management provides add and replacement forms');
overviewCheck(str_contains($html,'name="edit_access_present"')&&str_contains($html,'name="can_edit_marks"'),'independent assignment and editing controls');
overviewCheck(!str_contains($html,'name="period"'),'management filters omit irrelevant period selection');
overviewCheck(str_contains($html,'name="action" value="bulk_preview"')&&!str_contains($html,'name="action" value="bulk_replace"'),'bulk changes start with a read-only preview');
$_POST=['action'=>'bulk_preview','old_teacher_id'=>'5','teacher_id'=>'6','can_edit_marks'=>'1'];
ob_start(); require __DIR__.'/../public_html/includes/marks_manage.php'; $html=ob_get_clean();
overviewCheck(count($_SESSION['marks_bulk']['input']['course_versions'])===3,'preview includes only old teacher assignments');
overviewCheck(str_contains($html,'name="action" value="bulk_replace"')&&str_contains($html,'name="bulk_selected[]"'),'preview allows final course selection and confirmation');
overviewCheck($_SESSION['marks_bulk']['input']['teacher_id']===6&&strlen($_SESSION['marks_bulk']['token'])===48,'confirmation binds stored replacement and random token');
