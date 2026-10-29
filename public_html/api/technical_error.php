<?php
require_once __DIR__.'/../config/config.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);header('Allow: POST');exit;}
requireApiLogin();
if (!verifyCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN']??'')) {http_response_code(403);exit;}
if ((int)($_SERVER['CONTENT_LENGTH']??0)>2048) {http_response_code(413);exit;}
$body=file_get_contents('php://input',false,null,0,2049);
if (strlen($body)>2048) {http_response_code(413);exit;}
$data=json_decode($body,true);
if (!is_array($data) || !in_array($data['kind']??null,['RuntimeError','UnhandledRejection'],true)
    || !is_string($data['file']??null)
    || !preg_match('~^(assets/js/[a-zA-Z0-9_-]+\.js|(admin|teacher)/[a-zA-Z0-9_-]+\.php)$~D',$data['file'])
    || !is_int($data['line']??null) || $data['line']<0 || $data['line']>1000000) {http_response_code(400);exit;}
$file=dirname(__DIR__).'/'.$data['file'];
if (!is_file($file) || technicalRelativeFile($file)==='[external]') {http_response_code(400);exit;}
$window=(int)floor(time()/60);
if (($_SESSION['technical_window']??null)!==$window) {$_SESSION['technical_window']=$window;$_SESSION['technical_count']=0;}
if (($_SESSION['technical_count']??0)>=20) {http_response_code(429);exit;}
$_SESSION['technical_count']=($_SESSION['technical_count']??0)+1;
session_write_close();
technicalRecord('javascript',$data['kind'],$file,$data['line']);
http_response_code(204);
