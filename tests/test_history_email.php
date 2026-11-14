<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../public_html/includes/absence_history_email.php';
function checkHistory(bool $ok,string $label): void {if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "PASS: $label\n";}
checkHistory(historyEmailRange(null,null)===[null,null],'whole year');
foreach([['2026-02-30','2026-03-01'],['2026-10-31','2026-10-01'],[[],null],['2026-10-01',null]] as $range){$rejected=false;try{historyEmailRange(...$range);}catch(DomainException $e){$rejected=true;}checkHistory($rejected,'reject invalid range');}
$student=['last_name'=>'Δοκιμή','first_name'=>'Μαθητής','am'=>'123'];
$rows=[['date'=>'2026-10-01','absence_type'=>null],['date'=>'2026-10-01','absence_type'=>'justified'],['date'=>'2026-10-02','absence_type'=>'expulsion']];
$message=buildHistoryEmail($student,$rows,'2026-10-01','2026-10-31');
checkHistory(str_contains($message['body'],'Σύνολο: 3 ώρες'),'total hours');
checkHistory(str_contains($message['body'],'01/10/2026 | Σύνολο 2 | Αδικαιολόγητες 1 | Δικαιολογημένες 1 | Αποβολές 0'),'daily categories');
checkHistory(str_contains($message['body'],'02/10/2026 | Σύνολο 1 | Αδικαιολόγητες 0 | Δικαιολογημένες 0 | Αποβολές 1'),'expulsion separate');
checkHistory(str_contains(buildHistoryEmail($student,[],null,null)['body'],'Δεν υπάρχουν καταχωρισμένες απουσίες'),'empty history');
