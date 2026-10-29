<?php
// Deliberately excludes error messages, SQL, traces with arguments and request data.
function technicalLogDirectory(): ?string {
    $configured = defined('APP_TECHNICAL_LOG_DIR') ? APP_TECHNICAL_LOG_DIR : getenv('APP_TECHNICAL_LOG_DIR');
    if (!$configured) {
        $store = defined('APP_MAINTENANCE_DIR') ? APP_MAINTENANCE_DIR : getenv('APP_MAINTENANCE_DIR');
        if (!$store) return null;
        $configured = rtrim($store, '/\\').'/technical-errors';
    }
    if (!is_string($configured) || is_link($configured)) return null;
    $parent = realpath(is_dir($configured) ? $configured : dirname($configured));
    if (!$parent) return null;
    $normalize = static fn($s) => strtolower(str_replace('\\','/',$s)).'/';
    foreach ([dirname(__DIR__), $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)] as $public) {
        $public = realpath($public);
        if ($public && str_starts_with($normalize($parent), $normalize($public))) return null;
    }
    if (!is_dir($configured) && !@mkdir($configured,0700)) return null;
    if (!is_writable($configured)) return null;
    $directory=realpath($configured);
    foreach (glob($directory.'/errors-????-??-??.jsonl') ?: [] as $old) {
        if (!is_link($old) && preg_match('/^errors-\d{4}-\d{2}-\d{2}\.jsonl$/D',basename($old)) && basename($old) < 'errors-'.gmdate('Y-m-d',time()-29*86400).'.jsonl') @unlink($old);
    }
    return $directory;
}

function technicalRelativeFile(string $file): string {
    $root = str_replace('\\','/',realpath(dirname(__DIR__))).'/';
    $file = str_replace('\\','/',realpath($file) ?: '');
    if (!str_starts_with(strtolower($file),strtolower($root))) return '[external]';
    $relative = substr($file,strlen($root));
    return preg_match('~^[a-zA-Z0-9_./-]{1,180}$~D',$relative) ? $relative : '[external]';
}

function technicalRecord(string $category, string $kind, string $file = '', int $line = 0): string {
    static $busy = false, $count = 0;
    $id = substr(hash('sha256',uniqid('',true).mt_rand()),0,16);
    if ($busy || ++$count > 30) return $id;
    $busy = true;
    $handle = null;
    try {
        $id = bin2hex(random_bytes(8));
        $directory = technicalLogDirectory();
        if (!$directory) {
            // Safe fallback; no exception text or sensitive context.
            error_log('Absences technical event='.$id.' private_storage_unavailable');
            return $id;
        }
        if (!in_array($category,['php','database','javascript'],true)) $category='php';
        $kind = preg_match('/^[a-zA-Z0-9_\\\\]{1,100}$/D',$kind) ? $kind : 'Error';
        $record = ['id'=>$id,'time'=>gmdate('c'),'category'=>$category,'kind'=>$kind,
            'file'=>technicalRelativeFile($file),'line'=>max(0,min(1000000,$line)),
            'route'=>technicalRelativeFile($_SERVER['SCRIPT_FILENAME'] ?? '')];
        $path = $directory.'/errors-'.gmdate('Y-m-d').'.jsonl';
        if (is_link($path)) return $id;
        $handle = @fopen($path,'c+b');
        if (!$handle || !flock($handle,LOCK_EX)) return $id;
        @chmod($path,0600);
        $size = fstat($handle)['size'];
        if ($size < 1048576) {
            fseek($handle,0,SEEK_END);
            fwrite($handle,json_encode($record,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
            fflush($handle);
        }
    } catch (Throwable $ignored) {
        // Logging must never interrupt attendance operations.
    } finally {
        if (is_resource($handle)) { flock($handle,LOCK_UN); fclose($handle); }
        $busy = false;
    }
    return $id;
}

function technicalException(Throwable $error): string {
    return technicalRecord($error instanceof PDOException ? 'database' : 'php',get_class($error),$error->getFile(),$error->getLine());
}

function technicalRegisterHandlers(): void {
    set_error_handler(static function(int $level,string $message,string $file,int $line): bool {
        if (!(error_reporting() & $level)) return false;
        $names=[E_WARNING=>'Warning',E_NOTICE=>'Notice',E_USER_WARNING=>'UserWarning',E_USER_NOTICE=>'UserNotice',E_DEPRECATED=>'Deprecated',E_USER_DEPRECATED=>'UserDeprecated',E_USER_ERROR=>'UserError',E_RECOVERABLE_ERROR=>'RecoverableError'];
        technicalRecord('php',$names[$level] ?? 'Error',$file,$line);
        // Preserve fatal semantics for explicitly raised fatal errors.
        if (in_array($level,[E_USER_ERROR,E_RECOVERABLE_ERROR],true)) return false;
        return true;
    });
    register_shutdown_function(static function(): void {
        $last = error_get_last();
        if ($last && in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) {
            technicalRecord('php','FatalError',$last['file'],$last['line']);
        }
    });
}

function technicalReadEvents(string $day): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)) return [];
    $directory=technicalLogDirectory();
    if (!$directory) return [];
    $path=$directory.'/errors-'.$day.'.jsonl';
    if (!is_file($path) || is_link($path) || filesize($path)>1052672) return [];
    $handle=@fopen($path,'rb');
    if (!$handle) return [];
    $events=[];
    try {
        if (!flock($handle,LOCK_SH)) return [];
        while (($line=fgets($handle,4096))!==false) {
            $event=json_decode($line,true);
            if (is_array($event) && isset($event['id'],$event['time'],$event['category'],$event['kind'],$event['file'],$event['line'],$event['route'])) $events[]=$event;
        }
    } finally {flock($handle,LOCK_UN);fclose($handle);}
    return array_reverse($events);
}
