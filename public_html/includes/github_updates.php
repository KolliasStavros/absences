<?php
// SPDX-License-Identifier: GPL-3.0-only
// Fixed public origin. No credentials, arbitrary repositories or URLs supplied by users.
final class AbsencesGitHubUpdates {
    public const REPOSITORY='KolliasStavros/absences';
    public static function select(array $releases,string $current): ?array {
        $selected=null;
        foreach($releases as $release) {
            if(!is_array($release) || !empty($release['draft']) || !empty($release['prerelease']))continue;
            $tag=$release['tag_name']??null;
            if(!is_string($tag) || !preg_match('/^v(\d+\.\d+\.\d+)$/D',$tag,$m) || !version_compare($m[1],$current,'>'))continue;
            $name='absences-update-'.$current.'-to-'.$m[1].'.zip';
            foreach((is_array($release['assets']??null)?$release['assets']:[]) as $asset) {
                if(!is_array($asset) || ($asset['name']??'')!==$name || ($asset['state']??'')!=='uploaded' || !is_int($asset['size']??null) || $asset['size']<1 || $asset['size']>67108864)continue;
                $url='https://github.com/'.self::REPOSITORY.'/releases/download/'.$tag.'/'.$name;
                if(($asset['browser_download_url']??'')!==$url)continue;
                if(!$selected || version_compare($m[1],$selected['version'],'>'))$selected=['version'=>$m[1],'from_version'=>$current,'url'=>$url,'size'=>$asset['size'],'page'=>'https://github.com/'.self::REPOSITORY.'/releases/tag/'.$tag];
            }
        }
        return $selected;
    }
    private static function validUrl(string $url): bool {
        $p=parse_url($url);
        return is_array($p) && ($p['scheme']??'')==='https' && !isset($p['user']) && !isset($p['pass']) && (!isset($p['port']) || $p['port']===443)
            && in_array($p['host']??'', ['api.github.com','github.com','release-assets.githubusercontent.com','objects.githubusercontent.com'],true);
    }
    private static function transfer(string $url,$destination,int $limit): void {
        if(!extension_loaded('curl'))throw new DomainException('Απαιτείται η επέκταση PHP cURL για έλεγχο και λήψη ενημερώσεων.');
        for($redirects=0;$redirects<5;$redirects++) {
            if(!self::validUrl($url))throw new DomainException('Μη επιτρεπόμενος προορισμός ενημέρωσης.');
            rewind($destination);ftruncate($destination,0);$bytes=0;$location='';$status=0;$overflow=false;
            $ch=curl_init($url);
            curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'Absences-Updater',CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json'],
                CURLOPT_HEADERFUNCTION=>static function($ch,$line) use (&$location,&$status) {if(preg_match('~^HTTP/\S+ (\d+)~',$line,$m)){$status=(int)$m[1];$location='';}if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);},
                CURLOPT_WRITEFUNCTION=>static function($ch,$data) use ($destination,$limit,&$bytes,&$overflow,&$status) {if($status>=300 && $status<400)return strlen($data);$bytes+=strlen($data);if($bytes>$limit){$overflow=true;return 0;}return fwrite($destination,$data);}
            ]);
            if(PHP_OS_FAMILY==='Windows' && defined('CURLSSLOPT_NATIVE_CA'))curl_setopt($ch,CURLOPT_SSL_OPTIONS,CURLSSLOPT_NATIVE_CA);
            try{$ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$curlCode=curl_errno($ch);}finally{curl_close($ch);}
            if($overflow)throw new DomainException('Το αρχείο υπερβαίνει το επιτρεπόμενο μέγεθος.');
            if($ok===false){$reason=match($curlCode){6=>'Δεν επιλύεται η διεύθυνση GitHub (DNS).',7=>'Δεν είναι δυνατή η σύνδεση με το GitHub.',28=>'Έληξε ο χρόνος αναμονής σύνδεσης/λήψης.',35,60,77=>'Απέτυχε ο έλεγχος ασφαλούς σύνδεσης TLS. Ελέγξτε τα πιστοποιητικά CA του hosting.',default=>'Αποτυχία ασφαλούς σύνδεσης/λήψης.'};throw new DomainException($reason.' Κωδικός cURL: '.$curlCode.'.');}
            if($code>=300 && $code<400 && $location!==''){$url=$location;continue;}
            if($code!==200)throw new DomainException('Το GitHub δεν επέτρεψε τον έλεγχο/λήψη (HTTP '.$code.'). Δοκιμάστε αργότερα.');
            return;
        }
        throw new DomainException('Υπερβολικές ανακατευθύνσεις λήψης.');
    }
    public static function check(string $current): array {
        $h=tmpfile();if(!$h)throw new RuntimeException('Δεν υπάρχει προσωρινός χώρος ελέγχου.');
        try{self::transfer('https://api.github.com/repos/'.self::REPOSITORY.'/releases?per_page=30',$h,2097152);rewind($h);$releases=json_decode(stream_get_contents($h),true,64,JSON_THROW_ON_ERROR);if(!is_array($releases)||!array_is_list($releases))throw new DomainException('Μη έγκυρη απάντηση εκδόσεων.');return ['checked_at'=>time(),'current'=>$current,'available'=>self::select($releases,$current)];}finally{fclose($h);}
    }
    public static function download(array $release,string $path): void {
        if(!preg_match('/^\d+\.\d+\.\d+$/D',$release['version']??'') || !preg_match('/^\d+\.\d+\.\d+$/D',$release['from_version']??''))throw new DomainException('Μη έγκυρη έκδοση.');
        $expected='https://github.com/'.self::REPOSITORY.'/releases/download/v'.$release['version'].'/absences-update-'.$release['from_version'].'-to-'.$release['version'].'.zip';
        if(($release['url']??'')!==$expected)throw new DomainException('Μη έγκυρη διεύθυνση πακέτου.');
        $h=fopen($path,'x+b');if(!$h)throw new RuntimeException('Δεν δημιουργήθηκε προσωρινό πακέτο.');chmod($path,0600);
        try{self::transfer($expected,$h,67108864);if(!fflush($h) || fstat($h)['size']!==$release['size'])throw new DomainException('Η λήψη του πακέτου δεν είναι πλήρης.');}catch(Throwable $e){fclose($h);unlink($path);throw $e;}fclose($h);
    }
}

// Stable authenticated entry point is retained; only its signed module is replaced.
$maintenanceEntry=realpath(__DIR__.'/../admin/maintenance.php');
if(PHP_SAPI!=='cli' && $maintenanceEntry!==false && realpath($_SERVER['SCRIPT_FILENAME']??'')===$maintenanceEntry && is_file(__DIR__.'/maintenance_controller.php') && !defined('ABSENCES_MAINTENANCE_DISPATCHED')) {
    define('ABSENCES_MAINTENANCE_DISPATCHED',true);
    require __DIR__.'/maintenance_controller.php';
    exit;
}
