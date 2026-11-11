<?php
// Offline date and actual month-template checks; no database or real events.
$appRoot=is_dir(__DIR__.'/../public_html')?__DIR__.'/../public_html':dirname(__DIR__);
require_once $appRoot.'/includes/school_calendar_holidays.php';
function holidayCheck(bool $ok): void { if (!$ok) throw new RuntimeException('Holiday check failed'); }
foreach (['2026-12-24','2027-01-07','2027-04-26','2027-05-09','2027-07-01','2027-08-31','2026-10-28','2027-03-15','2027-03-25','2027-05-01','2027-06-21'] as $date) holidayCheck(calendarSchoolHoliday($date)!==null);
foreach (['2026-12-23','2027-01-08','2027-04-25','2027-05-10','2027-06-30','2026-08-31','2027-09-01','2027-01-30','2026-11-17'] as $date) holidayCheck(calendarSchoolHoliday($date)===null);
$source=file_get_contents($appRoot.'/includes/school_calendar_page.php');
$start=strpos($source,'<?php for($d=1;');
$end=strpos($source,'<?php endfor; ?>',$start)+strlen('<?php endfor; ?>');
$grid=substr($source,$start,$end-$start);
$events=[]; $editable=false; $h=static fn($v)=>htmlspecialchars($v,ENT_QUOTES,'UTF-8');
foreach (['2026-12'=>[8,8], '2027-05'=>[10,9], '2027-06'=>[8,1]] as $month=>[$weekends,$holidays]) {
    $first=new DateTimeImmutable($month.'-01'); $last=$first->modify('last day of this month');
    ob_start(); eval('?>'.$grid); $html=ob_get_clean();
    holidayCheck(substr_count($html,'cal-weekend')===$weekends);
    holidayCheck(substr_count($html,'cal-holiday-label')===$holidays);
    holidayCheck(substr_count($html,'cal-day-heading')===(int)$last->format('j'));
}
echo "PASS: holiday boundaries, ordinary/school-celebration dates, actual month rendering and weekend/holiday overlaps\n";
