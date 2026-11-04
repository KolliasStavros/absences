<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/marks.php';
header('Content-Type: application/json; charset=utf-8');
$user=requireApiLogin(); requirePost();
try {
    if((int)($_SERVER['CONTENT_LENGTH']??0)>262144) schoolJsonResponse(['error'=>'Υπερβολικά μεγάλο αίτημα.'],413);
    $input=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($input)||!verifyCsrfToken($input['csrf_token']??'')) schoolJsonResponse(['error'=>'Η συνεδρία ή το αίτημα ασφαλείας έληξε. Ανανεώστε τη σελίδα.'],403);
    $year=schoolRequestId($input['year_id']??null); $course=schoolRequestId($input['course_id']??null); $period=schoolRequestId($input['period_id']??null);
    if(!$year||!$course||!$period||!in_array($input['action']??'',['save','submit'],true)) throw new DomainException('Μη έγκυρο αίτημα βαθμολογίας.');
    marksSave(getDB(),$year,$course,$period,$user,$input);
    schoolJsonResponse(['success'=>true]);
} catch(DomainException $e) { schoolJsonResponse(['error'=>$e->getMessage()],409); }
catch(JsonException $e) { schoolJsonResponse(['error'=>'Μη έγκυρα δεδομένα.'],400); }
catch(Throwable $e) { schoolJsonResponse(['error'=>safeError($e)],500); }
