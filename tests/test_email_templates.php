<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
$settings=[];
function getSchoolSetting($key,$default=''){global $settings;return $settings[$key]??$default;}
require_once __DIR__.'/../public_html/includes/absence_history_email.php';
require_once __DIR__.'/../public_html/includes/bulk_notification_preview.php';
function templateCheck(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
function templateReject(callable $call,string $label): void {try{$call();}catch(DomainException $e){templateCheck(true,$label);return;}throw new RuntimeException($label);}
$custom=validateCustomEmailTexts(['my_text'],["Κείμενο σχολείου\nΔεύτερη γραμμή"]);
templateCheck($custom['my_text']==="Κείμενο σχολείου\nΔεύτερη γραμμή",'multiline custom text preserved');
foreach([['school_name'],['total'],['Same'],['my-text'],['same','same']] as $names) templateReject(fn()=>validateCustomEmailTexts($names,array_fill(0,count($names),'text')),'reject reserved, invalid or duplicate name');
templateReject(fn()=>validateCustomEmailTexts(['my_text'],['{student_full_name}']),'reject recursive text variables');
templateReject(fn()=>validateCustomEmailTexts(['my_text'],[str_repeat('x',12001)]),'limit custom text length');
templateReject(fn()=>validateSchoolEmailTemplate('{my_text}','history',[]),'reject deleting referenced variable');
templateReject(fn()=>validateSchoolEmailTemplate('{hours}','summary',[]),'reject wrong template context');
templateReject(fn()=>validateSchoolEmailTemplate("subject\nheader",'history',[],true),'reject multiline subject');
validateSchoolEmailTemplate('{my_text} {student_full_name}','summary',$custom);
templateCheck(renderSchoolEmailText('{my_text} {school_name}',['school_name'=>'Σχολείο'],$custom)==="Κείμενο σχολείου\nΔεύτερη γραμμή Σχολείο",'custom and builtin variables render together');
templateCheck(renderSchoolEmailText('{my_text}',[],['my_text'=>'{school_name}'])==='{school_name}','single pass substitution');
$settings=['email_custom_texts'=>json_encode($custom),'email_history_subject'=>'{school_name} {student_full_name}', 'email_history_body'=>"{my_text}\n{am} {academic_year}\n{absence_details}",'email_summary_body'=>"{my_text}\n{total}\n{absence_details}",'email_template_body'=>"{my_text}\n{hours}",'mask_student_name'=>'1'];
$student=['last_name'=>'ΔΟΚΙΜΗ','first_name'=>'ΜΑΘΗΤΗΣ','am'=>'123','last_name_gen'=>'','first_name_gen'=>'','guardian_name_voc'=>'','guardian_name'=>''];
$rows=[['date'=>'2026-10-01','hour'=>1,'absence_type'=>'absent']];
$history=buildHistoryEmail($student,$rows,null,null,'Σχολείο','2026-2027');
templateCheck($history['subject']==='Σχολείο ΔΟ**** ΜΑ*****'&&str_starts_with($history['body'],$custom['my_text'])&&str_contains($history['body'],'123 2026-2027'),'history loads saved templates and keeps masking');
$summary=buildAbsenceSummaryEmail($student,$rows,null,null,'2026-2027','Σχολείο',true);
templateCheck(str_starts_with($summary['body'],$custom['my_text'])&&str_contains($summary['body'],'01/10/2026 | Σύνολο 1'),'summary custom text and optional daily data');
$daily=buildBulkEmailPreview($student,$rows,'2026-10-01');
templateCheck(str_starts_with($daily['body'],$custom['my_text'])&&str_contains($daily['body'],'1η'),'daily template uses same custom variables');
$settings['email_custom_texts']='[]';
templateCheck(customEmailTexts()===[],'deleting all custom texts persists without restoring defaults');
unset($settings['email_custom_texts']);
templateCheck(isset(customEmailTexts()['justification_info']),'initial supplied text available without inserting into templates');
$_SERVER['REQUEST_METHOD']='GET';$error='';
$settings['email_custom_texts']=json_encode(['school_info'=>'<script>alert(1)</script>']);
ob_start();require __DIR__.'/../public_html/includes/email_template_settings.php';$html=ob_get_clean();
templateCheck(str_contains($html,'name="email_history_body"')&&str_contains($html,'name="email_summary_body"'),'settings render both report template editors');
templateCheck(str_contains($html,'&lt;script&gt;alert(1)&lt;/script&gt;')&&!str_contains($html,'<script>alert(1)</script>'),'custom text escaped in settings');
templateCheck(str_contains($html,'id="add-custom-email-text"')&&str_contains($html,'data-remove-custom-text'),'settings render add and remove controls');
echo "All template tests passed; no database or email used.\n";
