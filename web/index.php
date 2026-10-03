<?php
/**
 * YSPTP PHP core port for OpenWrt/iStoreOS.
 * Core flow ported from the Rust implementation:
 *   device identity -> app/start -> live/v1/01 -> live/v1/02 -> VDN getstream
 *   -> cache -> M3U8 rewrite -> TS proxy.
 *
 * Requirements:
 *   PHP 8.x + curl + openssl + json
 * Run:
 *   php -S 0.0.0.0:8766 ysptp.php
 */

const AK = '9f5c54c4ed0e50109b800f7e28fec205';
const CLOUD_GET_URL = 'https://ytpcloudws.cctv.cn/cloudps/wssapi/device/v2/get';
const APP_START_URL = 'https://ytpaddr.cctv.cn/gsnw/api/app/start/v1/01';
const DICTIONARY_URL = 'https://ytpaddr.cctv.cn/gsnw/player/dictionary/obtain/v1';
const LIVE_V1_01_URL = 'https://ytpaddr.cctv.cn/gsnw/api/live/v1/01';
const LIVE_V1_02_URL = 'https://ytpaddr.cctv.cn/gsnw/api/live/v1/02';
const VDN_GETSTREAM_URL = 'https://ytpvdn.cctv.cn/cctvmobileinf/rest/cctv/videoliveUrl/getstream';
const VERSION = '1.4.1';
const APP_CHANNEL = 'dangbei';
const VDN_APP_NAME = '央视频电视投屏助手';
const USER_AGENT = 'cctv_app_tv';
const CACHE_TTL = 600;
const SESSION_TTL = 7200;
const TIMEOUT = 15;

$BASE = __DIR__;
$STATE_FILE = $BASE . '/device-state-php.json';
$CACHE_FILE = $BASE . '/proxy-cache-state-php.json';
$COOKIE_FILE = $BASE . '/ytp-cookies-php.txt';

$CHANNELS = [
    'cctv5'   => 'Live1719474204987287',
    'cctv5p'  => 'Live1719473996025286',
    'cctv164k'=> 'Live1704966749996185',
    'cctv4k'  => 'Live1767871224782105',
    'cctv8k'  => 'Live1688400593818102',
];

function now_ms(): int { return (int)round(microtime(true) * 1000); }
function now_s(): float { return microtime(true); }
function b64url_encode(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_decode(string $s): string|false { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }
function sha256_hex(string $s): string { return hash('sha256', $s); }
function sha1_upper(string $s): string { return strtoupper(hash('sha1', $s)); }
function md5_hex(string $s): string { return hash('md5', $s); }
function java_hashcode(string $s): int {
    $h = 0;
    $n = strlen($s);
    for ($i=0; $i<$n; $i++) {
        $c = ord($s[$i]);
        $h = (($h * 31) + $c) & 0xffffffff;
        if ($h >= 0x80000000) $h -= 0x100000000;
    }
    return $h;
}
function java_uuid_hash(int $msb, int $lsb): string {
    // Match Rust: (hash as i64 as u64) packed into the high/low 64-bit halves.
    // PHP must sign-extend negative Java int hashes to 64 bits.
    $u1 = sprintf('%016x', $msb < 0 ? (0xffffffffffffffff + $msb + 1) : $msb);
    $u2 = sprintf('%016x', $lsb < 0 ? (0xffffffffffffffff + $lsb + 1) : $lsb);
    return $u1 . $u2;
}
function random_hex(int $n): string { return substr(bin2hex(random_bytes((int)ceil($n/2))), 0, $n); }
function random_mac(): string {
    $b = random_bytes(6); $a = array_values(unpack('C6', $b));
    $a[0] = ($a[0] | 2) & 254;
    return implode(':', array_map(fn($x)=>sprintf('%02x',$x), $a));
}

function aes_gcm_decrypt_b64(string $value, string $key): string {
    $raw = base64_decode($value, true);
    if ($raw === false || strlen($raw) <= 12) throw new Exception('AES-GCM payload too short');
    $k = substr(str_pad($key, 32, "\0"), 0, 32);
    $nonce = substr($raw, 0, 12);
    $cipher = substr($raw, 12);
    $tag = substr($cipher, -16);
    $ct = substr($cipher, 0, -16);
    $plain = openssl_decrypt($ct, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plain === false) throw new Exception('AES-GCM decrypt failed');
    return $plain;
}
function aes_gcm_encrypt_b64(string $value, string $key): string {
    $k = substr(str_pad($key, 32, "\0"), 0, 32);
    $nonce = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($value, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ct === false) throw new Exception('AES-GCM encrypt failed');
    return base64_encode($nonce . $ct . $tag);
}

function curl_request(string $url, array $headers=[], ?string $body=null, string $method='POST', bool $form=false, bool $useCookies=true): array {
    global $COOKIE_FILE;
    if (!is_file($COOKIE_FILE)) @touch($COOKIE_FILE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => TIMEOUT,
        CURLOPT_TIMEOUT => TIMEOUT,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_ENCODING => '',
        CURLOPT_COOKIEJAR => $useCookies ? $COOKIE_FILE : null,
        CURLOPT_COOKIEFILE => $useCookies ? $COOKIE_FILE : null,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    if ($resp === false) { $e = curl_error($ch); curl_close($ch); throw new Exception('curl: '.$e); }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    curl_close($ch);
    return [$status, $resp, $ctype];
}
function uuid4(): string {
    $d = random_bytes(16); $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function xuid(array $p): string {
    $build = '1698'.$p['hardware'].$p['board'].$p['brand'].$p['device'].$p['manufacturer'].$p['model'].$p['product'].$p['tags'].$p['build_type'].$p['user'].$p['resolution'].$p['mac'];
    $uuid = java_uuid_hash(java_hashcode($build), java_hashcode($p['model']));
    return sha1_upper($p['android_id'].'|'.$uuid);
}
function fingerprint(string $xuid, int $ms): string {
    $day0 = 86400000 * intdiv(intdiv($ms,1000)+28800,86400) - 28800000;
    return sha256_hex(sha256_hex(AK.$xuid.$ms.$day0));
}
function profile_default(): array {
    $models = [
        ['Sony','Sony','XR-85Z9K','mt5895','mt5895','SONYTV.2022.XR_85Z9K','7680-4320-280'],
        ['Samsung','Samsung','QA85QN900C','s5e9935','neo8k','SAMSUNGTV.2023.QN900C','7680-4320-280'],
        ['TCL','TCL','85C845','mt9615','tcl4k','TCLTV.2023.C845','3840-2160-300'],
        ['CHANGHONG','CHANGHONG','U65G7','mt9632','changhong4k','CHANGHONGTV.2022.U65G7','3840-2160-260'],
    ];
    $m = $models[random_int(0,count($models)-1)]; [$brand,$man,$model,$hw,$board,$vid,$screen]=$m;
    $id = random_hex(16); $mac = random_mac(); $device = strtolower(preg_replace('/[^a-zA-Z0-9]+/','_', $brand.'_'.$model));
    return [
        'android_id'=>$id,'mac'=>$mac,'hardware'=>$hw,'board'=>$board,'brand'=>$brand,'device'=>$device,
        'manufacturer'=>$man,'model'=>$model,'product'=>$device,'tags'=>'release-keys','build_type'=>'user','user'=>'build',
        'resolution'=>str_replace('-', '*', implode('-', array_slice(explode('-', $screen),0,2))),
        'display'=>$model.'-user 13 '.$vid.' 2024 release-keys','version_id'=>$vid,
        'host'=>strtolower($brand).'-tv-build','fingerprint'=>$man.'/'.$device.'/'.$device.':13/'.$vid.'/2024:user/release-keys',
        'report_model'=>preg_replace('/[^A-Za-z0-9]/','',$model),'screen_param'=>$screen,'cast_model'=>$model
    ];
}
function load_state(string $file): array {
    if (is_file($file)) { $x=json_decode((string)file_get_contents($file), true); if (is_array($x) && !empty($x['profile']['android_id'])) return $x; }
    $p=profile_default(); $s=['schema_version'=>1,'profile_source'=>'php_default','profile'=>$p,'screen_param'=>$p['screen_param'],'cast_model'=>$p['cast_model'],'x_uid'=>xuid($p),'cloud_guid'=>'','created_at'=>now_s()];
    file_put_contents($file, json_encode($s, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); return $s;
}
function fresh_headers(array $id, string $ctype='application/json; charset=utf-8', ?string $accept='application/json', ?int $forceTs=null): array {
    return [
        'Accept: '.($accept ?? '*/*'), 'Accept-Language: zh-CN,zh;q=0.8', 'Referer: api.cctv.cn', 'User-Agent: '.USER_AGENT,
        'UID: '.($id['android_id'] ?? ''), 'appChannel: '.APP_CHANNEL, 'X-Uid: '.$id['x_uid'], 'X-Fingerprint: '.$id['x_fingerprint'],
        'X-Version: '.VERSION, 'X-Timestamp: '.($forceTs ?? now_ms()), 'X-Nonce: '.uuid4(), 'Content-Type: '.$ctype, 'Connection: Keep-Alive', 'Accept-Encoding: gzip', 'Cache-Control: no-cache'
    ];
}
function build_identity(array $p): array {
    $xu=xuid($p); $xf=fingerprint($xu,now_ms()); return ['android_id'=>$p['android_id'],'x_uid'=>$xu,'x_fingerprint'=>$xf];
}
function collect_report(array $p, string $xuid, string $sdkVersion='1.0.0'): void {
    $value = [
        'cctv_id'=>substr($xuid,0,64),'device_id'=>$p['android_id'],'idfa'=>'','idfv'=>'','user_id'=>'',
        'app_key'=>'1178c84d-4818-44ff-b415-02106e87e144','imei'=>'','android_id'=>$p['android_id'],'mac'=>$p['mac'],
        'device_builder_type'=>$p['build_type'],'device_hardware'=>$p['hardware'],'device_board'=>$p['board'],
        'device_brand'=>$p['brand'],'device_params'=>$p['device'],'device_display'=>$p['display'],'device_version_id'=>$p['version_id'],
        'device_host'=>$p['host'],'device_product'=>$p['product'],'device_tags'=>$p['tags'],'device_user'=>$p['user'],
        'device_fingerprint'=>$p['fingerprint'],'device_manufacturer'=>$p['manufacturer'],'device_model'=>$p['report_model'],
        'device_resolution'=>$p['resolution'],'system_type'=>'Android','device_type'=>'TV','app_language'=>'CHINESE',
        'app_version'=>VERSION,'sdk_version'=>$sdkVersion,'os_version'=>'13','app_channel'=>APP_CHANNEL,'data_time'=>(string)now_ms()
    ];
    $info=json_encode(['key'=>'app_start_d1','value'=>$value],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $headers=[
        'Content-Type: application/x-www-form-urlencoded','Charset: UTF-8','User-Agent: Dalvik/2.1.0 (Linux; U; Android 13; '.$p['model'].' Build/'.$p['version_id'].')',
        'X-Uid: '.$xuid,'X-Fingerprint: '.$xuid,'X-Version: '.VERSION,'Connection: Keep-Alive','Accept-Encoding: gzip'
    ];
    [$st,$txt]=curl_request('https://collect.cctv.cn/cctvmobileinf/rest/cctv/receive/new/app',$headers,http_build_query(['info'=>$info]));
    if($st<200||$st>=300) throw new Exception("collect report HTTP $st: ".substr($txt,0,300));
}

function dictionary_obtain(): void {
    [$st,$txt] = curl_request(
        DICTIONARY_URL,
        [
            'X-Uid: ROOT',
            'X-Fingerprint: ROOT',
            'X-Nonce: '.uuid4(),
            'X-Timestamp: '.now_ms(),
            'X-Version: '.VERSION,
            'UID: ROOT',
            'Referer: api.cctv.cn',
            'User-Agent: '.USER_AGENT,
            'appChannel: ROOT',
            'Connection: Keep-Alive',
            'Accept-Encoding: gzip'
        ],
        null,
        'POST'
    );
    if ($st < 200 || $st >= 300) {
        throw new Exception("dictionary HTTP $st: ".substr($txt,0,300));
    }
}

function app_start(array &$id, array $p): string {
    // Rust recomputes X-Fingerprint immediately before app/start and uses the
    // same timestamp as X-Timestamp. PHP must do exactly the same thing.
    $ts = now_ms();
    $id['x_fingerprint'] = fingerprint($id['x_uid'], $ts);
    $body=['key'=>'app_start_d1','value'=>[
        'cctv_id'=>substr($id['x_uid'],0,64),'device_id'=>$p['android_id'],'idfa'=>'','idfv'=>'','user_id'=>'','app_key'=>'1178c84d-4818-44ff-b415-02106e87e144','imei'=>'',
        'android_id'=>$p['android_id'],'mac'=>$p['mac'],'device_builder_type'=>$p['build_type'],'device_hardware'=>$p['hardware'],'device_board'=>$p['board'],
        'device_brand'=>$p['brand'],'device_params'=>$p['device'],'device_display'=>$p['display'],'device_version_id'=>$p['version_id'],'device_host'=>$p['host'],
        'device_product'=>$p['product'],'device_tags'=>$p['tags'],'device_user'=>$p['user'],'device_fingerprint'=>$p['fingerprint'],'device_manufacturer'=>$p['manufacturer'],
        'device_model'=>$p['report_model'],'device_resolution'=>$p['resolution'],'system_type'=>'Android','device_type'=>'TV','app_language'=>'CHINESE','app_version'=>VERSION,'sdk_version'=>'','os_version'=>'13','app_channel'=>APP_CHANNEL,'data_time'=>(string)now_ms()
    ]];
    $payload=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    [$st,$txt]=curl_request(APP_START_URL, array_merge(fresh_headers($id, 'application/json; charset=utf-8', 'application/json', $ts),['UID:']), $payload);
    $j=json_decode($txt,true); $enc='';
    if (is_array($j)) {
        $d=$j['data']??null;
        if (is_array($d)) $enc=(string)($d['key']??'');
        elseif (is_string($d)) $enc=$d;
    }
    if ($st>=200 && $st<300 && $enc!=='') {
        return aes_gcm_decrypt_b64($enc,substr($id['x_fingerprint'],0,32));
    }
    throw new Exception("app/start HTTP $st: ".substr($txt,0,500));
}
function report_single(array $id, array $body): void {
    $headers=fresh_headers($id); if(isset($id['uid_override'])) $headers=array_values(array_filter($headers,fn($h)=>!str_starts_with($h,'UID:'))); if(isset($id['uid_override'])) $headers[]='UID: '.$id['uid_override'];
    [$st,$txt]=curl_request('https://ytpdata.cctv.cn/das/app/data/message/single', $headers, json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    if($st<200||$st>=300) throw new Exception("report HTTP $st: ".substr($txt,0,300));
}
function app_event(array $id, array $p): void {
    $t=now_ms();
    $v=[
        'cctv_id'=>substr($id['x_uid'],0,64),'device_id'=>$p['android_id'],'idfa'=>'','idfv'=>'','user_id'=>'','app_key'=>'1178c84d-4818-44ff-b415-02106e87e144','imei'=>'','android_id'=>$p['android_id'],'mac'=>$p['mac'],
        'device_builder_type'=>$p['build_type'],'device_hardware'=>$p['hardware'],'device_board'=>$p['board'],'device_brand'=>$p['brand'],'device_params'=>$p['device'],'device_display'=>$p['display'],'device_version_id'=>$p['version_id'],'device_host'=>$p['host'],'device_product'=>$p['product'],'device_tags'=>$p['tags'],'device_user'=>$p['user'],'device_fingerprint'=>$p['fingerprint'],'device_manufacturer'=>$p['manufacturer'],'device_model'=>$p['report_model'],'device_resolution'=>$p['resolution'],'system_type'=>'Android','device_type'=>'TV','app_language'=>'CHINESE','app_version'=>VERSION,'sdk_version'=>'','os_version'=>'13','app_channel'=>APP_CHANNEL,'data_time'=>(string)$t,
        'event_id'=>'app_start','event_name'=>'应用启动','event_time'=>(string)$t,'network_type'=>'WIFI','cur_version'=>VERSION,'channel'=>APP_CHANNEL,'pre_version'=>VERSION
    ];
    report_single(array_merge($id,['uid_override'=>'']),['key'=>'event','value'=>$v]);
}
function page_event(array $id, array $p): void {
    $end=now_ms(); $start=$end-1000;
    $v=[
        'cctv_id'=>substr($id['x_uid'],0,64),'device_id'=>$p['android_id'],'idfa'=>'','idfv'=>'','user_id'=>'','app_key'=>'1178c84d-4818-44ff-b415-02106e87e144','imei'=>'','android_id'=>$p['android_id'],'mac'=>$p['mac'],
        'device_builder_type'=>$p['build_type'],'device_hardware'=>$p['hardware'],'device_board'=>$p['board'],'device_brand'=>$p['brand'],'device_params'=>$p['device'],'device_display'=>$p['display'],'device_version_id'=>$p['version_id'],'device_host'=>$p['host'],'device_product'=>$p['product'],'device_tags'=>$p['tags'],'device_user'=>$p['user'],'device_fingerprint'=>$p['fingerprint'],'device_manufacturer'=>$p['manufacturer'],'device_model'=>$p['report_model'],'device_resolution'=>$p['resolution'],'system_type'=>'Android','device_type'=>'TV','app_language'=>'CHINESE','app_version'=>VERSION,'sdk_version'=>'','os_version'=>'13','app_channel'=>APP_CHANNEL,'data_time'=>(string)($end+2),
        'start_time'=>(string)$start,'end_time'=>(string)$end,'duration'=>'1000','page_name'=>'com.cctv.tv.mvp.ui.activity.MainActivity','session_id'=>uuid4(),'network_type'=>'WIFI'
    ];
    report_single($id,['key'=>'page_d1','value'=>$v]);
}

function heartbeat(array $id, array $p): void {
    $t=now_ms();
    $v=[
        'cctv_id'=>substr($id['x_uid'],0,64),'device_id'=>$p['android_id'],'idfa'=>'','idfv'=>'','user_id'=>'','app_key'=>'1178c84d-4818-44ff-b415-02106e87e144','imei'=>'','android_id'=>$p['android_id'],'mac'=>$p['mac'],
        'device_builder_type'=>$p['build_type'],'device_hardware'=>$p['hardware'],'device_board'=>$p['board'],'device_brand'=>$p['brand'],'device_params'=>$p['device'],'device_display'=>$p['display'],'device_version_id'=>$p['version_id'],'device_host'=>$p['host'],'device_product'=>$p['product'],'device_tags'=>$p['tags'],'device_user'=>$p['user'],'device_fingerprint'=>$p['fingerprint'],'device_manufacturer'=>$p['manufacturer'],'device_model'=>$p['report_model'],'device_resolution'=>$p['resolution'],'system_type'=>'Android','device_type'=>'TV','app_language'=>'CHINESE','app_version'=>VERSION,'sdk_version'=>'','os_version'=>'13','app_channel'=>APP_CHANNEL,'data_time'=>(string)$t,
        'network_type'=>'WiFi','guid'=>'','other'=>''
    ];
    [$st,$txt]=curl_request('https://ytpdata.cctv.cn/das/app/data/message/single',fresh_headers($id),json_encode(['key'=>'app_heartbeat','value'=>$v],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    if($st<200||$st>=300)throw new Exception("heartbeat HTTP $st: ".substr($txt,0,300));
}
function index_flow(array $id): void {
    $body=['channel'=>APP_CHANNEL,'source'=>'application'];
    [$st,$txt]=curl_request('https://ytpaddr.cctv.cn/gsnw/api/index/v1/01',fresh_headers($id),json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    if($st<200||$st>=300)throw new Exception("index HTTP $st: ".substr($txt,0,300));
}
function warmup_flow(array $id): void {
    $appcommon=json_encode(['adid'=>'','av'=>VERSION,'an'=>VDN_APP_NAME,'ap'=>'cctv_app_tv'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    [$st,$txt]=curl_request('https://ytpaddr.cctv.cn/gsnw/drm/config/obtain/v1',fresh_headers($id,'application/x-www-form-urlencoded',null),http_build_query(['appcommon'=>$appcommon]));
    if($st<200||$st>=300)throw new Exception("drm config HTTP $st: ".substr($txt,0,300));
    $url='https://ytpaddr.cctv.cn/gsnw/version/config/obtain/v1?'.http_build_query(['appcommon'=>$appcommon]);
    [$st,$txt]=curl_request($url,fresh_headers($id,'',null),null,'GET');
    if($st<200||$st>=300)throw new Exception("version config HTTP $st: ".substr($txt,0,300));
}

function live01(array $id, array $state, string $sessionKey, string $liveId): array {
    $body=['screenParam'=>$state['screen_param'],'rate'=>'','systemType'=>'ios','model'=>$state['cast_model'],'id'=>$liveId,'userId'=>'BAEBFF2B-C516-4F34-ABC0-A824A6461CBD','clientSign'=>'cctvVideo','deviceId'=>['serial'=>'','imei'=>'','android_id'=>'']];
    [$st,$txt]=curl_request(LIVE_V1_01_URL,fresh_headers($id),json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    if ($st<200||$st>=300) throw new Exception("live/v1/01 HTTP $st: ".substr($txt,0,300));
    $j=json_decode($txt,true); $videos=$j['data']['videoList']??$j['data']['videos']??[]; $pick=null; $fallback=null;
    foreach($videos as $v){ if(empty($v['url'])) continue; if($fallback===null)$fallback=$v; if(($v['rate']??'')==='36p'){$pick=$v;break;} }
    $v=$pick??$fallback; if(!$v) throw new Exception('live/v1/01 no usable URL');
    $url=$v['url']; if(!preg_match('~^https?://~i',$url))$url=aes_gcm_decrypt_b64($url,$sessionKey);
    return ['url'=>$url,'rate'=>$v['rate']??'','rateName'=>$v['rateName']??''];
}
function live02(array $id, string $sessionKey): string {
    $enc=aes_gcm_encrypt_b64('',$sessionKey); [$st,$txt]=curl_request(LIVE_V1_02_URL,fresh_headers($id),json_encode(['guid'=>$enc]));
    if($st<200||$st>=300)throw new Exception("live/v1/02 HTTP $st: ".substr($txt,0,300));
    $j=json_decode($txt,true); $enc=$j['data']['appSecret']??$j['data']['app_secret']??$j['data']??''; if(!$enc)throw new Exception('live/v1/02 missing appSecret');
    return aes_gcm_decrypt_b64($enc,$sessionKey);
}
function vdn(array $id,string $liveUrl,string $secret): array {
    $r=sprintf('%08x-0000-%04x-0000-00000000%04x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xffff));
    $sign=md5_hex(AK.$secret.$r); $appcommon=json_encode(['adid'=>'','av'=>VERSION,'an'=>VDN_APP_NAME,'ap'=>'cctv_app_tv'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $headers=array_merge(fresh_headers($id,'application/x-www-form-urlencoded',null),['APPID: '.AK,'APPSIGN: '.$sign,'APPRANDOMSTR: '.$r]);
    $form=http_build_query(['appcommon'=>$appcommon,'url'=>$liveUrl]); [$st,$txt]=curl_request(VDN_GETSTREAM_URL,$headers,$form);
    if($st<200||$st>=300)throw new Exception("VDN HTTP $st: ".substr($txt,0,300)); $j=json_decode($txt,true);
    if((string)($j['succeed']??'')!=='1'||empty($j['url']))throw new Exception('VDN did not return final URL: '.substr($txt,0,400));
    return ['url'=>$j['url'],'sign'=>$sign,'random'=>$r];
}
function resolve_channel(string $name,string $liveId): array {
    global $STATE_FILE,$CACHE_FILE;
    $state=load_state($STATE_FILE); $p=$state['profile']; $id=build_identity($p); collect_report($p,$id['x_uid']); dictionary_obtain(); $session=app_start($id,$p); app_event($id,$p); page_event($id,$p); heartbeat($id,$p); index_flow($id); warmup_flow($id); $l1=live01($id,$state,$session,$liveId); $secret=live02($id,$session); $v=vdn($id,$l1['url'],$secret);
    $entry=['channel'=>$name,'live_id'=>$liveId,'final_url'=>$v['url'],'playback_headers'=>['UID'=>$p['android_id'],'APPID'=>AK,'Referer'=>'api.cctv.cn','User-Agent'=>USER_AGENT,'APPRANDOMSTR'=>$v['random'],'APPSIGN'=>$v['sign']],'rate'=>$l1['rate'],'rate_name'=>$l1['rateName'],'refreshed_at'=>now_s(),'expires_at'=>now_s()+CACHE_TTL,'android_id'=>$p['android_id']];
    $cache=is_file($CACHE_FILE)?json_decode((string)file_get_contents($CACHE_FILE),true):[]; if(!is_array($cache))$cache=[]; $cache[$name]=$entry; file_put_contents($CACHE_FILE,json_encode($cache,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); return $entry;
}
function get_entry(string $name,string $liveId): array {
    global $CACHE_FILE; $cache=is_file($CACHE_FILE)?json_decode((string)file_get_contents($CACHE_FILE),true):[]; $e=is_array($cache)?($cache[$name]??null):null;
    if(is_array($e)&&!empty($e['final_url'])&&now_s()<$e['expires_at'])return $e;
    try{return resolve_channel($name,$liveId);}catch(Throwable $e){ if(is_array($e)&&!empty($e['final_url']))return $e; throw $e; }
}
function header_clean_value(string $v): string {
    return trim(str_replace(["\r", "\n"], '', $v));
}
function decode_chunked_php(string $raw): string {
    $pos=0; $out=''; $len=strlen($raw);
    while($pos<$len){
        $eol=strpos($raw,"\r\n",$pos); if($eol===false) throw new Exception('chunked response malformed');
        $line=trim(substr($raw,$pos,$eol-$pos)); $semi=strpos($line,';'); if($semi!==false)$line=substr($line,0,$semi);
        $n=hexdec($line); $pos=$eol+2;
        if($n===0) break;
        if($pos+$n>$len) throw new Exception('chunked response truncated');
        $out.=substr($raw,$pos,$n); $pos+=$n;
        if(substr($raw,$pos,2)==="\r\n")$pos+=2;
    }
    return $out;
}
function raw_http1(string $url,array $headers=[],?string $range=null): array {
    $u=parse_url($url); if(!$u||strtolower($u['scheme']??'')!=='http') throw new Exception('raw HTTP requires http URL');
    $host=$u['host']??''; if($host==='') throw new Exception('upstream URL missing host');
    $port=(int)($u['port']??80);
    $hostHeader=$host.(isset($u['port'])?':'.$port:'');
    $target=$u['path']??'/'; if($target==='')$target='/'; if(isset($u['query']))$target.='?'.$u['query'];
    $fp=@stream_socket_client('tcp://'.$host.':'.$port,$errno,$errstr,TIMEOUT,STREAM_CLIENT_CONNECT);
    if(!$fp) throw new Exception("upstream connect failed: $errstr ($errno)");
    stream_set_timeout($fp,TIMEOUT);
    $req="GET $target HTTP/1.1\r\nHost: $hostHeader\r\n";
    foreach(['UID','APPID','APPRANDOMSTR','Referer','User-Agent','APPSIGN'] as $k){
        if(isset($headers[$k]) && trim((string)$headers[$k])!=='') $req.=$k.': '.header_clean_value((string)$headers[$k])."\r\n";
    }
    $req.="Accept: */*\r\nAccept-Encoding: identity\r\nConnection: close\r\n";
    if($range!==null)$req.='Range: '.header_clean_value($range)."\r\n";
    $req.="\r\n";
    $written=@fwrite($fp,$req); if($written===false){fclose($fp);throw new Exception('upstream request write failed');}
    $raw=''; while(!feof($fp)){ $chunk=fread($fp,8192); if($chunk===false)break; if($chunk==='')break; $raw.=$chunk; if(strlen($raw)>32*1024*1024)break; }
    fclose($fp);
    $split=strpos($raw,"\r\n\r\n"); if($split===false)throw new Exception('upstream response missing header/body separator');
    $ht=substr($raw,0,$split); $body=substr($raw,$split+4); $lines=explode("\r\n",$ht);
    $status=0; if(isset($lines[0])&&preg_match('~^HTTP/\S+\s+(\d+)~',$lines[0],$m))$status=(int)$m[1];
    if(!$status)throw new Exception('invalid upstream HTTP status');
    $ct='application/vnd.apple.mpegurl'; $chunked=false;
    foreach(array_slice($lines,1) as $line){$x=explode(':',$line,2);if(count($x)!==2)continue;$k=strtolower(trim($x[0]));$v=trim($x[1]);if($k==='content-type')$ct=$v;elseif($k==='transfer-encoding'&&stripos($v,'chunked')!==false)$chunked=true;}
    if($chunked)$body=decode_chunked_php($body);
    return [$status,$body,$ct];
}
function upstream(string $url,array $headers=[],?string $range=null): array {
    if(strtolower((string)parse_url($url,PHP_URL_SCHEME))==='http') return raw_http1($url,$headers,$range);
    $h=$headers; $h[]='Accept: */*'; $h[]='Accept-Encoding: identity'; if($range)$h[]='Range: '.$range;
    return curl_request($url,$h,null,'GET',false,false);
}
function rewrite_playlist(string $playlist,string $origin): string {
    $out=[]; foreach(preg_split('/\r?\n/',$playlist) as $line){$s=trim($line); if($s===''||str_starts_with($s,'#')){$out[]=$line;continue;} $u=$s;
        if(!preg_match('~^https?://~i',$u)){
            // Resolve relative segment against playlist base is handled by the upstream URL path in the caller only for absolute URLs.
            $out[]=$line; continue;
        }
        if(preg_match('~\.ts(?:\?|$)~i',$u))$out[]=$origin.'/proxy.ts?ts='.b64url_encode($u); else $out[]=$line;
    } return implode("\n",$out)."\n";
}
function script_base(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/ysptp.php';
    return $scheme . '://' . $host . $script;
}
function request_subpath(): string {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($script !== '' && str_starts_with($uriPath, $script)) {
        $sub = substr($uriPath, strlen($script));
        return $sub === '' ? '/' : $sub;
    }
    // Some CGI/uhttpd configurations expose PATH_INFO separately.
    $pi = $_SERVER['PATH_INFO'] ?? '';
    return $pi !== '' ? $pi : $uriPath;
}
function origin(): string {
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    return $scheme.'://'.($_SERVER['HTTP_HOST']??'127.0.0.1');
}
function handle(): void {
    global $CHANNELS,$CACHE_FILE;
    $path=request_subpath();
    $method=$_SERVER['REQUEST_METHOD']??'GET';

    // Root URL is an IPTV M3U playlist so apps can put
    // http://host/ysptp.php directly into their "直播" URL field.
    // Add ?ui=1 if a browser-style channel page is wanted.
    if($path==='/' || $path===''){
        if(isset($_GET['ui'])) {
            $base=script_base();
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
            echo '<title>YSPTP CCTV</title><body style="font-family:sans-serif;max-width:720px;margin:40px auto;padding:0 18px">';
            echo '<h2>央视直播</h2><ul>';
            foreach($CHANNELS as $n=>$id){$display=$n==='cctv164k'?'cctv16':$n;echo '<li><a href="'.htmlspecialchars($base.'/'.$display.'.m3u8',ENT_QUOTES).'">'.$display.'.m3u8</a></li>';}
            echo '</ul></body></html>';
            return;
        }
        $base=script_base();
        // 5 个直播源统一归到“央视”分类
        $labels=[
            'cctv5'=>['CCTV5','央视'],
            'cctv5p'=>['CCTV5+','央视'],
            'cctv164k'=>['CCTV16','央视'],
            'cctv4k'=>['CCTV4K','央视'],
            'cctv8k'=>['CCTV8K','央视'],
        ];
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: no-cache, no-store, max-age=0');
        header('Access-Control-Allow-Origin: *');
        echo '#EXTM3U'."\n";
        foreach($CHANNELS as $n=>$id){
            $label=$labels[$n][0]??$n;
            $group=$labels[$n][1]??'央视';
            $display=$n==='cctv164k'?'cctv16':$n;
            echo '#EXTINF:-1 tvg-name="'.str_replace('\"','',$label).'" group-title="'.str_replace('\"','',$group).'",'.$label."\n";
            echo $base.'/'.$display.'.m3u8'."\n";
        }
        return;
    }
    if(isset($_GET['status'])){
        $cache=is_file($CACHE_FILE)?json_decode((string)file_get_contents($CACHE_FILE),true):[];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['channels'=>$cache,'php'=>PHP_VERSION],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);return;
    }

    // Supports both /ysptp.php/proxy.ts?ts=... and /ysptp.php?proxy=1&ts=...
    if($path==='/proxy.ts'||$path==='/proxy'||isset($_GET['proxy'])){
        if($method!=='GET'){http_response_code(405);return;}
        $token=$_GET['ts']??''; $real=b64url_decode($token);
        if($real===false||!preg_match('~^https?://~i',$real)){http_response_code(400);echo 'missing/invalid ts';return;}
        $cache=is_file($CACHE_FILE)?json_decode((string)file_get_contents($CACHE_FILE),true):[];
        $ph=[]; foreach($cache as $e){$ph=array_merge($ph,$e['playback_headers']??[]);}
        $range=$_SERVER['HTTP_RANGE']??null; [$st,$body,$ct]=upstream($real,$ph,$range);
        http_response_code($st?:502); header('Content-Type: '.($ct?:'video/MP2T')); header('Cache-Control: no-cache, no-store, max-age=0'); header('Access-Control-Allow-Origin: *'); echo $body; return;
    }

    // Also support /ysptp.php?channel=cctv5 for clients that cannot use PATH_INFO.
    $name = isset($_GET['channel']) ? strtolower(trim((string)$_GET['channel'])) : '';
    if($name==='') {
        $name=strtolower(trim($path,'/'));
        if(str_ends_with($name,'.m3u8'))$name=substr($name,0,-5);
    }
    if($name==='cctv16')$name='cctv164k';
    if(!isset($CHANNELS[$name])){http_response_code(404);echo 'not found';return;}
    if($method==='HEAD'){http_response_code(200);header('Content-Type: application/vnd.apple.mpegurl');return;}
    try{
        $e=get_entry($name,$CHANNELS[$name]);
        [$st,$playlist,$ct]=upstream($e['final_url'],$e['playback_headers']);
        if($st<200||$st>=300)throw new Exception("playlist HTTP $st");
        $base=$e['final_url']; $parts=parse_url($base);
        $baseOrigin=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');
        // Keep the directory without leading/trailing slashes so concatenation
        // cannot produce URLs such as //live/segment.ts.
        $dir=trim(str_replace('\\','/',dirname($parts['path']??'/')),'/');
        $proxyBase=script_base();
        $abs=[];
        foreach(preg_split('/\r?\n/',$playlist) as $line){
            $s=trim($line);
            if($s===''||str_starts_with($s,'#')){$abs[]=$line;continue;}
            if(preg_match('~^https?://~i',$s))$u=$s;
            elseif(str_starts_with($s,'/'))$u=$baseOrigin.$s;
            else $u=$baseOrigin.($dir?'/'.$dir:'').'/'.$s;
            // Rust first resolves relative media URLs against the final M3U8 URL,
            // then proxies every .ts URL. Do the same here.
            $abs[] = preg_match('~\.ts(?:\?|$)~i',$u)?$proxyBase.'/proxy.ts?ts='.b64url_encode($u):$u;
        }
        $out=implode("\n",$abs)."\n";
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: no-cache, no-store, max-age=0');
        header('Access-Control-Allow-Origin: *');
        header('X-CCTV-Channel: '.$e['channel']);
        header('X-CCTV-Rate: '.$e['rate']);
        echo $out;
    }catch(Throwable $x){http_response_code(502);header('Content-Type:text/plain; charset=utf-8');echo 'YSPTP PHP error: '.$x->getMessage();}
}

if (PHP_SAPI === 'cli') {
    // CLI is still supported for testing, but normal Web-PHP deployment does not need it.
    $port=8766; foreach($argv??[] as $a){if(str_starts_with($a,'--port='))$port=(int)substr($a,7);}
    echo "YSPTP PHP listening on 0.0.0.0:$port\n";
    passthru('php -S 0.0.0.0:'.(int)$port.' '.escapeshellarg(__FILE__));
    exit;
}
handle();
