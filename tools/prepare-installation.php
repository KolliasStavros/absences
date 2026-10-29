<?php
// SPDX-License-Identifier: GPL-3.0-only
// Optional CLI alternative to create-installation-key.html. Never run on a server.
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
$root=realpath(__DIR__.'/..');
$public=$root.'/public_html';
$config=$public.'/config';
if (!is_dir($config) || is_link($public) || is_link($config) || !is_file($public.'/install.php')) {
    fwrite(STDERR,"Run this tool only from an unpacked clean distribution containing public_html/config.\n"); exit(1);
}
foreach(['database.php','installed.lock','private-config.php','local.php','production.php','install-access.php'] as $name) {
    if (file_exists($config.'/'.$name) || is_link($config.'/'.$name)) { fwrite(STDERR,"This copy is already configured or prepared. Use a fresh unpacked public package.\n");exit(1); }
}
$keyPath=$root.'/INSTALLATION-KEY.txt';
if (file_exists($keyPath) || is_link($keyPath)) { fwrite(STDERR,"A key file already exists. Use a fresh copy.\n");exit(1); }
$created=[];
try {
    $key=bin2hex(random_bytes(32));
    $contents=[$keyPath=>$key."\n",$config.'/install-access.php'=>"<?php\nreturn 'sha256:".hash('sha256',$key)."';\n"];
    foreach($contents as $path=>$content) {
        $h=@fopen($path,'x+b');if(!$h)throw new RuntimeException('Cannot create preparation files.');
        $created[]=$path;@chmod($path,0600);
        try {if(fwrite($h,$content)!==strlen($content) || !fflush($h))throw new RuntimeException('Incomplete write.');} finally{fclose($h);}
    }
    echo "Prepared this local copy. Keep INSTALLATION-KEY.txt private. Upload only public_html contents.\n";
} catch(Throwable $e) {
    foreach($created as $path)@unlink($path);
    fwrite(STDERR,"Preparation failed; no existing configuration was overwritten.\n");exit(1);
}
