<?php
// Shared strict parsers: reject arrays, booleans, floats, partial numbers and overflow.
function schoolRequestId(mixed $value): ?int {
    if (is_int($value)) return $value>0 ? $value : null;
    if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D',$value)) return null;
    $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    return $id===false ? null : $id;
}
function schoolOptionalRequestId(mixed $value): ?int {
    return in_array($value,[null,'',0,'0'],true) ? 0 : schoolRequestId($value);
}
function schoolRequestHour(mixed $value,int $maximum=8): ?int {
    $hour=schoolRequestId($value);
    return $hour!==null && $hour<=$maximum ? $hour : null;
}
function schoolRequestDate(mixed $value): ?string {
    if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value) || substr($value,0,4)==='0000') return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date && $date->format('Y-m-d')===$value ? $value : null;
}
function schoolJsonResponse(array $payload,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
