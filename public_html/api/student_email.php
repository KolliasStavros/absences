<?php
// SPDX-License-Identifier: GPL-3.0-only
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/request_validation.php';
require_once __DIR__.'/../includes/email_delivery_state.php';
require_once __DIR__.'/../includes/bulk_notification_preview.php';
require_once __DIR__.'/../includes/absence_history_email.php';
$user=requireApiLogin();requirePost();
if(!in_array($user['role'],['admin','teacher_admin'],true))schoolJsonResponse(['error'=>'Δεν επιτρέπεται η αποστολή.'],403);
if(!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN']??''))schoolJsonResponse(['error'=>'Μη έγκυρο αίτημα ασφαλείας.'],403);
$raw=file_get_contents('php://input',false,null,0,131073);$input=json_decode($raw,true);
if(strlen($raw)>131072 || !is_array($input))schoolJsonResponse(['error'=>'Μη έγκυρο μέγεθος ή περιεχόμενο.'],400);
function composeRows(PDO $db,int $id,int $year,string $date): array {$q=$db->prepare('SELECT id,hour,absence_type,email_notified FROM attendance WHERE student_id=? AND academic_year_id=? AND date=? AND is_absent=1 ORDER BY hour');$q->execute([$id,$year,$date]);return $q->fetchAll(PDO::FETCH_ASSOC);}
function composeSnapshot(array $rows): string {return hash('sha256',json_encode(array_map(static fn($r)=>[(int)$r['id'],(int)$r['hour'],$r['absence_type'],$r['date']??null],$rows)));}
function composeContacts(array $student): array {$out=[];foreach(['guardian_email','guardian_email_2'] as $slot=>$field){$email=trim($student[$field]??'');if($email!=='')$out[(string)$slot]=$email;}return $out;}
function composeTicket(array $data): string {$_SESSION['email_composer']??=[];foreach($_SESSION['email_composer'] as $key=>$old)if(time()-$old['created']>900)unset($_SESSION['email_composer'][$key]);while(count($_SESSION['email_composer'])>=5)array_shift($_SESSION['email_composer']);$nonce=bin2hex(random_bytes(24));$_SESSION['email_composer'][$nonce]=$data+['created'=>time(),'used'=>false];return $nonce;}
try {
 $db=getDB();$year=(int)(getActiveAcademicYear()['id']??0);$action=$input['action']??'';
 if($action==='prepare') {
  $id=schoolRequestId($input['student_id']??null);$mode=$input['mode']??'';$date=$mode==='history'?date('Y-m-d'):schoolRequestDate($input['date']??null);
  [$from,$to]=$mode==='history'?historyEmailRange($input['from']??null,$input['to']??null):[null,null];
  if(!$id || !$date || !in_array($mode,['direct','absence','history'],true))schoolJsonResponse(['error'=>'Μη έγκυρη επιλογή.'],400);
  $q=$db->prepare('SELECT * FROM students WHERE id=? AND academic_year_id=?');$q->execute([$id,$year]);$student=$q->fetch(PDO::FETCH_ASSOC);if(!$student)schoolJsonResponse(['error'=>'Ο μαθητής δεν ανήκει στο ενεργό έτος.'],404);
  $rows=$mode==='history'?historyEmailRows($db,$id,$year,$from,$to):composeRows($db,$id,$year,$date);if($mode==='absence' && !$rows)schoolJsonResponse(['error'=>'Δεν υπάρχουν πλέον απουσίες για αυτή την ημέρα.'],409);
  $contacts=composeContacts($student);$message=$mode==='absence'?buildBulkEmailPreview($student,$rows,$date):($mode==='history'?buildHistoryEmail($student,$rows,$from,$to):['subject'=>'','body'=>'']);
  if($mode==='history' && mb_strlen($message['body'])>20000)throw new DomainException('Το ιστορικό είναι μεγάλο. Επιλέξτε μικρότερο διάστημα.');
  $ticket=composeTicket(['student_id'=>$id,'year'=>$year,'date'=>$date,'mode'=>$mode,'from'=>$from,'to'=>$to,'actor'=>(int)$user['id'],'contacts'=>$contacts,'snapshot'=>composeSnapshot($rows)]);
  schoolJsonResponse(['nonce'=>$ticket,'name'=>$student['last_name'].' '.$student['first_name'],'contacts'=>(object)$contacts,'subject'=>$message['subject'],'body'=>$message['body']]);
 }
 if($action!=='send')schoolJsonResponse(['error'=>'Μη έγκυρη ενέργεια.'],400);
 $nonce=$input['nonce']??null;$ticket=is_string($nonce)?($_SESSION['email_composer'][$nonce]??null):null;
 if(!$ticket || time()-$ticket['created']>900 || $ticket['year']!==$year || $ticket['actor']!==(int)$user['id'])schoolJsonResponse(['error'=>'Η φόρμα έληξε. Ανοίξτε νέο μήνυμα.'],409);
 if($ticket['used']) {if(isset($ticket['response']))schoolJsonResponse($ticket['response']);schoolJsonResponse(['error'=>'Η αποστολή έχει ήδη υποβληθεί. Ελέγξτε το ιστορικό πριν επαναλάβετε.'],409);}
 $subject=$input['subject']??null;$body=$input['body']??null;$slots=$input['slots']??null;
 if(!is_string($subject)||!is_string($body)||trim($subject)===''||trim($body)===''||mb_strlen($subject)>200||strlen($body)>60000||mb_strlen($body)>20000||preg_match('/[\r\n\x00]/',$subject)||str_contains($body,"\0")||!is_array($slots)||!array_is_list($slots)||!$slots||count($slots)>2)schoolJsonResponse(['error'=>'Συμπληρώστε παραλήπτες, θέμα έως 200 χαρακτήρες και κείμενο έως 20.000 χαρακτήρες.'],400);
 $q=$db->prepare('SELECT * FROM students WHERE id=? AND academic_year_id=?');$q->execute([$ticket['student_id'],$year]);$student=$q->fetch(PDO::FETCH_ASSOC);if(!$student)schoolJsonResponse(['error'=>'Ο μαθητής δεν είναι διαθέσιμος.'],409);$current=composeContacts($student);$selected=[];
 foreach($slots as $slot){if(!is_string($slot)||!array_key_exists($slot,$ticket['contacts'])||!isset($current[$slot])||$current[$slot]!==$ticket['contacts'][$slot])schoolJsonResponse(['error'=>'Οι παραλήπτες άλλαξαν. Ανοίξτε ξανά τη φόρμα.'],409);$selected[$slot]=$current[$slot];}
 $lockName='abs-email-'.substr(hash('sha256',DB_NAME.'|'.(defined('DB_PREFIX')?DB_PREFIX:'').'|'.$ticket['student_id'].'|'.$ticket['date']),0,45);$lock=$db->prepare('SELECT GET_LOCK(?,5)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1)schoolJsonResponse(['error'=>'Εκτελείται άλλη αποστολή. Δοκιμάστε ξανά.'],409);
 try {
  $rows=$ticket['mode']==='history'?historyEmailRows($db,$ticket['student_id'],$year,$ticket['from'],$ticket['to']):composeRows($db,$ticket['student_id'],$year,$ticket['date']);if(in_array($ticket['mode'],['absence','history'],true)&&composeSnapshot($rows)!==$ticket['snapshot'])throw new DomainException('Οι απουσίες άλλαξαν. Ανοίξτε ξανά την ειδοποίηση και ελέγξτε το κείμενο.');
  $_SESSION['email_composer'][$nonce]['used']=true;$results=[];$failed=[];$seen=[];$reference=$ticket['mode']==='absence'?emailDeliveryReference($ticket['date'],$rows):($ticket['mode']==='history'?'Ιστορικό | '.($ticket['from']??'αρχή έτους').' — '.($ticket['to']??'τέλος έτους'):'Μήνυμα | '.$ticket['date']);
  foreach($selected as $slot=>$recipient){$key=strtolower($recipient);if(isset($seen[$key])){$results[]=['slot'=>(string)$slot,'success'=>$seen[$key]];if(!$seen[$key])$failed[(string)$slot]=$recipient;continue;}
   $duplicate=false;if(in_array($ticket['mode'],['absence','history'],true)){$q=$db->prepare("SELECT id FROM notification_logs WHERE channel='email' AND student_id=? AND recipient=? AND hours_summary=? AND BINARY subject=BINARY ? AND BINARY message=BINARY ? AND status='sent' LIMIT 1");$q->execute([$ticket['student_id'],$recipient,$reference,$subject,$body]);$duplicate=(bool)$q->fetchColumn();}
   $result=$duplicate?['success'=>true]:sendNotification('email',$recipient,$subject,$body,['student_id'=>$ticket['student_id'],'sent_by'=>$user['id'],'hours_summary'=>$reference]);$success=!empty($result['success'])&&empty($result['simulated']);$seen[$key]=$success;$results[]=['slot'=>(string)$slot,'success'=>$success];if(!$success)$failed[(string)$slot]=$recipient;
  }
  if($ticket['mode']==='absence'){$ledger=emailDeliveryLedger($db,$ticket['date'],$year);$attempts=$ledger['logs'][$ticket['student_id']][$reference]??[];$all=(bool)$current;foreach($current as $contact)if(($attempts[strtolower($contact)]??'')!=='sent')$all=false;$ids=array_column($rows,'id');$db->prepare('UPDATE attendance SET email_notified=? WHERE academic_year_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')')->execute(array_merge([$all?1:0,$year],$ids));}
  $retry=null;if($failed){$next=$ticket;unset($next['created'],$next['used'],$next['response']);$next['contacts']=$failed;$retry=composeTicket($next);}
  $response=['results'=>$results,'retry_nonce'=>$retry];$_SESSION['email_composer'][$nonce]=$ticket+['response'=>$response];$_SESSION['email_composer'][$nonce]['used']=true;
 } finally {$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);}
 schoolJsonResponse($response);
} catch(DomainException $e){schoolJsonResponse(['error'=>$e->getMessage()],409);}
catch(Throwable $e){schoolJsonResponse(['error'=>safeError($e)],500);}
