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

// 播放加速参数：缓存有效期保持 10 分钟；普通播放请求只在缓存真正过期时刷新。
// 预热任务可在到期前主动刷新，避免用户第一次点播时等待 YSPTP 多步握手。
const CACHE_REFRESH_MARGIN = 45;
const PREWARM_MARGIN = 360;
const PREWARM_LOCK_TIMEOUT = 8;
const PLAYLIST_CACHE_TTL = 1.2;
const PLAYLIST_CACHE_DIR = '/tmp/ysptp-playlist-cache';
const CURL_CONNECT_TIMEOUT = 4;
const CURL_TIMEOUT = 12;

$BASE = __DIR__;
$STATE_FILE = $BASE . '/device-state-php.json';
$CACHE_FILE = $BASE . '/proxy-cache-state-php.json';
$COOKIE_FILE = $BASE . '/ytp-cookies-php.txt';

// 频道映射表：聚合 ID、显示名称与播放列表分组
$CHANNELS = [
    'cctv1'    => ['id' => 'Live1717729995180256', 'name' => 'CCTV-1 综合', 'group' => '央视'],
    'cctv2'    => ['id' => 'Live1718261577870260', 'name' => 'CCTV-2 财经', 'group' => '央视'],
    'cctv3'    => ['id' => 'Live1718261955077261', 'name' => 'CCTV-3 综艺', 'group' => '央视'],
    'cctv4'    => ['id' => 'Live1718276148119264', 'name' => 'CCTV-4 中文国际', 'group' => '央视'],
    'cctv5'    => ['id' => 'Live1719474204987287', 'name' => 'CCTV-5 体育', 'group' => '央视'],
    'cctv5p'   => ['id' => 'Live1719473996025286', 'name' => 'CCTV-5+ 体育赛事', 'group' => '央视'],
    'cctv7'    => ['id' => 'Live1718276412224269', 'name' => 'CCTV-7 国防军事', 'group' => '央视'],
    'cctv8'    => ['id' => 'Live1718276458899270', 'name' => 'CCTV-8 电视剧', 'group' => '央视'],
    'cctv9'    => ['id' => 'Live1718276503187272', 'name' => 'CCTV-9 纪录', 'group' => '央视'],
    'cctv10'   => ['id' => 'Live1718276550002273', 'name' => 'CCTV-10 科教', 'group' => '央视'],
    'cctv11'   => ['id' => 'Live1718276603690275', 'name' => 'CCTV-11 戏曲', 'group' => '央视'],
    'cctv12'   => ['id' => 'Live1718276623932276', 'name' => 'CCTV-12 社会与法', 'group' => '央视'],
    'cctv13'   => ['id' => 'Live1718276575708274', 'name' => 'CCTV-13 新闻', 'group' => '央视'],
    'cctv14'   => ['id' => 'Live1718276498748271', 'name' => 'CCTV-14 少儿', 'group' => '央视'],
    'cctv15'   => ['id' => 'Live1718276319614267', 'name' => 'CCTV-15 音乐', 'group' => '央视'],
    'cctv16'   => ['id' => 'Live1718276256572265', 'name' => 'CCTV-16 奥林匹克', 'group' => '央视'],
    'cctv17'   => ['id' => 'Live1718276138318263', 'name' => 'CCTV-17 农业农村', 'group' => '央视'],
    'cctv4k'   => ['id' => 'Live1767871224782105', 'name' => 'CCTV-4K 超高清', 'group' => '央视'],
    'cctv8k'   => ['id' => 'Live1688400593818102', 'name' => 'CCTV-8K 超高清', 'group' => '央视'],
    'cctv164k' => ['id' => 'Live1704966749996185', 'name' => 'CCTV-16 4K', 'group' => '央视'],
    'cctv4kb'  => ['id' => 'Live1704872878572161', 'name' => 'CCTV-4K 备用', 'group' => '央视'],
    'cgtnen'   => ['id' => 'Live1719392219423280', 'name' => 'CGTN 英语频道', 'group' => 'CGTN'],
    'cgtnfr'   => ['id' => 'Live1719392670442283', 'name' => 'CGTN 法语频道', 'group' => 'CGTN'],
    'cgtnru'   => ['id' => 'Live1719392779653284', 'name' => 'CGTN 俄语频道', 'group' => 'CGTN'],
    'cgtnar'   => ['id' => 'Live1719392885692285', 'name' => 'CGTN 阿拉伯语', 'group' => 'CGTN'],
    'cgtnes'   => ['id' => 'Live1719392560433282', 'name' => 'CGTN 西班牙语', 'group' => 'CGTN'],
    'cgtndoc'  => ['id' => 'Live1719392360336281', 'name' => 'CGTN 纪录频道', 'group' => 'CGTN'],
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
    for ($i = 0; $i < $n; $i++) {
        $c = ord($s[$i]);
        $h = (($h * 31) + $c) & 0xffffffff;
        if ($h >= 0x80000000) $h -= 0x100000000;
    }
    return $h;
}

function java_uuid_hash(int $msb, int $lsb): string {
    $u1 = sprintf('%016x', $msb < 0 ? (0xffffffffffffffff + $msb + 1) : $msb);
    $u2 = sprintf('%016x', $lsb < 0 ? (0xffffffffffffffff + $lsb + 1) : $lsb);
    return $u1 . $u2;
}

function random_hex(int $n): string { return substr(bin2hex(random_bytes((int)ceil($n / 2))), 0, $n); }

function random_mac(): string {
    $b = random_bytes(6); $a = array_values(unpack('C6', $b));
    $a[0] = ($a[0] | 2) & 254;
    return implode(':', array_map(fn($x) => sprintf('%02x', $x), $a));
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

function curl_request(string $url, array $headers = [], ?string $body = null, string $method = 'POST', bool $form = false, bool $useCookies = true): array {
    global $COOKIE_FILE;
    if (!is_file($COOKIE_FILE)) @touch($COOKIE_FILE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => CURL_TIMEOUT,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_ENCODING => '',
        CURLOPT_TCP_NODELAY => true,
        CURLOPT_TCP_KEEPALIVE => 1,
        CURLOPT_TCP_KEEPIDLE => 20,
        CURLOPT_TCP_KEEPINTVL => 10,
        CURLOPT_COOKIEJAR => $useCookies ? $COOKIE_FILE : null,
        CURLOPT_COOKIEFILE => $useCookies ? $COOKIE_FILE : null,
        CURLOPT_HTTP_VERSION => (defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_1_1),
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    if ($resp === false) { $e = curl_error($ch); curl_close($ch); throw new Exception('curl: ' . $e); }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';
    curl_close($ch);
    return [$status, $resp, $ctype];
}

function uuid4(): string {
    $d = random_bytes(16); $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

function xuid(array $p): string {
    $build = '1698' . $p['hardware'] . $p['board'] . $p['brand'] . $p['device'] . $p['manufacturer'] . $p['model'] . $p['product'] . $p['tags'] . $p['build_type'] . $p['user'] . $p['resolution'] . $p['mac'];
    $uuid = java_uuid_hash(java_hashcode($build), java_hashcode($p['model']));
    return sha1_upper($p['android_id'] . '|' . $uuid);
}

function fingerprint(string $xuid, int $ms): string {
    $day0 = 86400000 * intdiv(intdiv($ms, 1000) + 28800, 86400) - 28800000;
    return sha256_hex(sha256_hex(AK . $xuid . $ms . $day0));
}

function profile_default(): array {
    $models = [
        ['Sony', 'Sony', 'XR-85Z9K', 'mt5895', 'mt5895', 'SONYTV.2022.XR_85Z9K', '7680-4320-280'],
        ['Samsung', 'Samsung', 'QA85QN900C', 's5e9935', 'neo8k', 'SAMSUNGTV.2023.QN900C', '7680-4320-280'],
        ['TCL', 'TCL', '85C845', 'mt9615', 'tcl4k', 'TCLTV.2023.C845', '3840-2160-300'],
        ['CHANGHONG', 'CHANGHONG', 'U65G7', 'mt9632', 'changhong4k', 'CHANGHONGTV.2022.U65G7', '3840-2160-260'],
    ];
    $m = $models[random_int(0, count($models) - 1)];
    [$brand, $man, $model, $hw, $board, $vid, $screen] = $m;
    $id = random_hex(16); $mac = random_mac();
    $device = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $brand . '_' . $model));
    return [
        'android_id' => $id, 'mac' => $mac, 'hardware' => $hw, 'board' => $board, 'brand' => $brand, 'device' => $device,
        'manufacturer' => $man, 'model' => $model, 'product' => $device, 'tags' => 'release-keys', 'build_type' => 'user', 'user' => 'build',
        'resolution' => str_replace('-', '*', implode('-', array_slice(explode('-', $screen), 0, 2))),
        'display' => $model . '-user 13 ' . $vid . ' 2024 release-keys', 'version_id' => $vid,
        'host' => strtolower($brand) . '-tv-build', 'fingerprint' => $man . '/' . $device . '/' . $device . ':13/' . $vid . '/2024:user/release-keys',
        'report_model' => preg_replace('/[^A-Za-z0-9]/', '', $model), 'screen_param' => $screen, 'cast_model' => $model
    ];
}

function load_state(string $file): array {
    if (is_file($file)) {
        $x = json_decode((string)file_get_contents($file), true);
        if (is_array($x) && !empty($x['profile']['android_id'])) return $x;
    }
    $p = profile_default();
    $s = [
        'schema_version' => 1, 'profile_source' => 'php_default', 'profile' => $p,
        'screen_param' => $p['screen_param'], 'cast_model' => $p['cast_model'],
        'x_uid' => xuid($p), 'cloud_guid' => '', 'created_at' => now_s()
    ];
    file_put_contents($file, json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $s;
}

function fresh_headers(array $id, string $ctype = 'application/json; charset=utf-8', ?string $accept = 'application/json', ?int $forceTs = null): array {
    return [
        'Accept: ' . ($accept ?? '*/*'), 'Accept-Language: zh-CN,zh;q=0.8', 'Referer: api.cctv.cn', 'User-Agent: ' . USER_AGENT,
        'UID: ' . ($id['android_id'] ?? ''), 'appChannel: ' . APP_CHANNEL, 'X-Uid: ' . $id['x_uid'], 'X-Fingerprint: ' . $id['x_fingerprint'],
        'X-Version: ' . VERSION, 'X-Timestamp: ' . ($forceTs ?? now_ms()), 'X-Nonce: ' . uuid4(), 'Content-Type: ' . $ctype,
        'Connection: Keep-Alive', 'Accept-Encoding: gzip', 'Cache-Control: no-cache'
    ];
}

function build_identity(array $p): array {
    $xu = xuid($p);
    $xf = fingerprint($xu, now_ms());
    return ['android_id' => $p['android_id'], 'x_uid' => $xu, 'x_fingerprint' => $xf];
}

function collect_report(array $p, string $xuid, string $sdkVersion = '1.0.0'): void {
    $value = [
        'cctv_id' => substr($xuid, 0, 64), 'device_id' => $p['android_id'], 'idfa' => '', 'idfv' => '', 'user_id' => '',
        'app_key' => '1178c84d-4818-44ff-b415-02106e87e144', 'imei' => '', 'android_id' => $p['android_id'], 'mac' => $p['mac'],
        'device_builder_type' => $p['build_type'], 'device_hardware' => $p['hardware'], 'device_board' => $p['board'],
        'device_brand' => $p['brand'], 'device_params' => $p['device'], 'device_display' => $p['display'], 'device_version_id' => $p['version_id'],
        'device_host' => $p['host'], 'device_product' => $p['product'], 'device_tags' => $p['tags'], 'device_user' => $p['user'],
        'device_fingerprint' => $p['fingerprint'], 'device_manufacturer' => $p['manufacturer'], 'device_model' => $p['report_model'],
        'device_resolution' => $p['resolution'], 'system_type' => 'Android', 'device_type' => 'TV', 'app_language' => 'CHINESE',
        'app_version' => VERSION, 'sdk_version' => $sdkVersion, 'os_version' => '13', 'app_channel' => APP_CHANNEL, 'data_time' => (string)now_ms()
    ];
    $info = json_encode(['key' => 'app_start_d1', 'value' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = [
        'Content-Type: application/x-www-form-urlencoded', 'Charset: UTF-8',
        'User-Agent: Dalvik/2.1.0 (Linux; U; Android 13; ' . $p['model'] . ' Build/' . $p['version_id'] . ')',
        'X-Uid: ' . $xuid, 'X-Fingerprint: ' . $xuid, 'X-Version: ' . VERSION, 'Connection: Keep-Alive', 'Accept-Encoding: gzip'
    ];
    [$st, $txt] = curl_request('https://collect.cctv.cn/cctvmobileinf/rest/cctv/receive/new/app', $headers, http_build_query(['info' => $info]));
    if ($st < 200 || $st >= 300) throw new Exception("collect report HTTP $st: " . substr($txt, 0, 300));
}

function dictionary_obtain(): void {
    [$st, $txt] = curl_request(
        DICTIONARY_URL,
        [
            'X-Uid: ROOT', 'X-Fingerprint: ROOT', 'X-Nonce: ' . uuid4(), 'X-Timestamp: ' . now_ms(),
            'X-Version: ' . VERSION, 'UID: ROOT', 'Referer: api.cctv.cn', 'User-Agent: ' . USER_AGENT,
            'appChannel: ROOT', 'Connection: Keep-Alive', 'Accept-Encoding: gzip'
        ],
        null, 'POST'
    );
    if ($st < 200 || $st >= 300) throw new Exception("dictionary HTTP $st: " . substr($txt, 0, 300));
}

function app_start(array &$id, array $p): string {
    $ts = now_ms();
    $id['x_fingerprint'] = fingerprint($id['x_uid'], $ts);
    $body = ['key' => 'app_start_d1', 'value' => [
        'cctv_id' => substr($id['x_uid'], 0, 64), 'device_id' => $p['android_id'], 'idfa' => '', 'idfv' => '', 'user_id' => '', 'app_key' => '1178c84d-4818-44ff-b415-02106e87e144', 'imei' => '',
        'android_id' => $p['android_id'], 'mac' => $p['mac'], 'device_builder_type' => $p['build_type'], 'device_hardware' => $p['hardware'], 'device_board' => $p['board'],
        'device_brand' => $p['brand'], 'device_params' => $p['device'], 'device_display' => $p['display'], 'device_version_id' => $p['version_id'], 'device_host' => $p['host'],
        'device_product' => $p['product'], 'device_tags' => $p['tags'], 'device_user' => $p['user'], 'device_fingerprint' => $p['fingerprint'], 'device_manufacturer' => $p['manufacturer'],
        'device_model' => $p['report_model'], 'device_resolution' => $p['resolution'], 'system_type' => 'Android', 'device_type' => 'TV', 'app_language' => 'CHINESE', 'app_version' => VERSION, 'sdk_version' => '', 'os_version' => '13', 'app_channel' => APP_CHANNEL, 'data_time' => (string)now_ms()
    ]];
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    [$st, $txt] = curl_request(APP_START_URL, array_merge(fresh_headers($id, 'application/json; charset=utf-8', 'application/json', $ts), ['UID:']), $payload);
    $j = json_decode($txt, true); $enc = '';
    if (is_array($j)) {
        $d = $j['data'] ?? null;
        if (is_array($d)) $enc = (string)($d['key'] ?? '');
        elseif (is_string($d)) $enc = $d;
    }
    if ($st >= 200 && $st < 300 && $enc !== '') {
        return aes_gcm_decrypt_b64($enc, substr($id['x_fingerprint'], 0, 32));
    }
    throw new Exception("app/start HTTP $st: " . substr($txt, 0, 500));
}

function report_single(array $id, array $body): void {
    $headers = fresh_headers($id);
    if (isset($id['uid_override'])) $headers = array_values(array_filter($headers, fn($h) => !str_starts_with($h, 'UID:')));
    if (isset($id['uid_override'])) $headers[] = 'UID: ' . $id['uid_override'];
    [$st, $txt] = curl_request('https://ytpdata.cctv.cn/das/app/data/message/single', $headers, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($st < 200 || $st >= 300) throw new Exception("report HTTP $st: " . substr($txt, 0, 300));
}

function app_event(array $id, array $p): void {
    $t = now_ms();
    $v = [
        'cctv_id' => substr($id['x_uid'], 0, 64), 'device_id' => $p['android_id'], 'idfa' => '', 'idfv' => '', 'user_id' => '', 'app_key' => '1178c84d-4818-44ff-b415-02106e87e144', 'imei' => '', 'android_id' => $p['android_id'], 'mac' => $p['mac'],
        'device_builder_type' => $p['build_type'], 'device_hardware' => $p['hardware'], 'device_board' => $p['board'], 'device_brand' => $p['brand'], 'device_params' => $p['device'], 'device_display' => $p['display'], 'device_version_id' => $p['version_id'], 'device_host' => $p['host'], 'device_product' => $p['product'], 'device_tags' => $p['tags'], 'device_user' => $p['user'], 'device_fingerprint' => $p['fingerprint'], 'device_manufacturer' => $p['manufacturer'], 'device_model' => $p['report_model'], 'device_resolution' => $p['resolution'], 'system_type' => 'Android', 'device_type' => 'TV', 'app_language' => 'CHINESE', 'app_version' => VERSION, 'sdk_version' => '', 'os_version' => '13', 'app_channel' => APP_CHANNEL, 'data_time' => (string)$t,
        'event_id' => 'app_start', 'event_name' => '应用启动', 'event_time' => (string)$t, 'network_type' => 'WIFI', 'cur_version' => VERSION, 'channel' => APP_CHANNEL, 'pre_version' => VERSION
    ];
    report_single(array_merge($id, ['uid_override' => '']), ['key' => 'event', 'value' => $v]);
}

function page_event(array $id, array $p): void {
    $end = now_ms(); $start = $end - 1000;
    $v = [
        'cctv_id' => substr($id['x_uid'], 0, 64), 'device_id' => $p['android_id'], 'idfa' => '', 'idfv' => '', 'user_id' => '', 'app_key' => '1178c84d-4818-44ff-b415-02106e87e144', 'imei' => '', 'android_id' => $p['android_id'], 'mac' => $p['mac'],
        'device_builder_type' => $p['build_type'], 'device_hardware' => $p['hardware'], 'device_board' => $p['board'], 'device_brand' => $p['brand'], 'device_params' => $p['device'], 'device_display' => $p['display'], 'device_version_id' => $p['version_id'], 'device_host' => $p['host'], 'device_product' => $p['product'], 'device_tags' => $p['tags'], 'device_user' => $p['user'], 'device_fingerprint' => $p['fingerprint'], 'device_manufacturer' => $p['manufacturer'], 'device_model' => $p['report_model'], 'device_resolution' => $p['resolution'], 'system_type' => 'Android', 'device_type' => 'TV', 'app_language' => 'CHINESE', 'app_version' => VERSION, 'sdk_version' => '', 'os_version' => '13', 'app_channel' => APP_CHANNEL, 'data_time' => (string)($end + 2),
        'start_time' => (string)$start, 'end_time' => (string)$end, 'duration' => '1000', 'page_name' => 'com.cctv.tv.mvp.ui.activity.MainActivity', 'session_id' => uuid4(), 'network_type' => 'WIFI'
    ];
    report_single($id, ['key' => 'page_d1', 'value' => $v]);
}

function heartbeat(array $id, array $p): void {
    $t = now_ms();
    $v = [
        'cctv_id' => substr($id['x_uid'], 0, 64), 'device_id' => $p['android_id'], 'idfa' => '', 'idfv' => '', 'user_id' => '', 'app_key' => '1178c84d-4818-44ff-b415-02106e87e144', 'imei' => '', 'android_id' => $p['android_id'], 'mac' => $p['mac'],
        'device_builder_type' => $p['build_type'], 'device_hardware' => $p['hardware'], 'device_board' => $p['board'], 'device_brand' => $p['brand'], 'device_params' => $p['device'], 'device_display' => $p['display'], 'device_version_id' => $p['version_id'], 'device_host' => $p['host'], 'device_product' => $p['product'], 'device_tags' => $p['tags'], 'device_user' => $p['user'], 'device_fingerprint' => $p['fingerprint'], 'device_manufacturer' => $p['manufacturer'], 'device_model' => $p['report_model'], 'device_resolution' => $p['resolution'], 'system_type' => 'Android', 'device_type' => 'TV', 'app_language' => 'CHINESE', 'app_version' => VERSION, 'sdk_version' => '', 'os_version' => '13', 'app_channel' => APP_CHANNEL, 'data_time' => (string)$t,
        'network_type' => 'WiFi', 'guid' => '', 'other' => ''
    ];
    [$st, $txt] = curl_request('https://ytpdata.cctv.cn/das/app/data/message/single', fresh_headers($id), json_encode(['key' => 'app_heartbeat', 'value' => $v], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($st < 200 || $st >= 300) throw new Exception("heartbeat HTTP $st: " . substr($txt, 0, 300));
}

function index_flow(array $id): void {
    $body = ['channel' => APP_CHANNEL, 'source' => 'application'];
    [$st, $txt] = curl_request('https://ytpaddr.cctv.cn/gsnw/api/index/v1/01', fresh_headers($id), json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($st < 200 || $st >= 300) throw new Exception("index HTTP $st: " . substr($txt, 0, 300));
}

function warmup_flow(array $id): void {
    $appcommon = json_encode(['adid' => '', 'av' => VERSION, 'an' => VDN_APP_NAME, 'ap' => 'cctv_app_tv'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    [$st, $txt] = curl_request('https://ytpaddr.cctv.cn/gsnw/drm/config/obtain/v1', fresh_headers($id, 'application/x-www-form-urlencoded', null), http_build_query(['appcommon' => $appcommon]));
    if ($st < 200 || $st >= 300) throw new Exception("drm config HTTP $st: " . substr($txt, 0, 300));
    $url = 'https://ytpaddr.cctv.cn/gsnw/version/config/obtain/v1?' . http_build_query(['appcommon' => $appcommon]);
    [$st, $txt] = curl_request($url, fresh_headers($id, '', null), null, 'GET');
    if ($st < 200 || $st >= 300) throw new Exception("version config HTTP $st: " . substr($txt, 0, 300));
}

function live01(array $id, array $state, string $sessionKey, string $liveId): array {
    $body = ['screenParam' => $state['screen_param'], 'rate' => '', 'systemType' => 'ios', 'model' => $state['cast_model'], 'id' => $liveId, 'userId' => 'BAEBFF2B-C516-4F34-ABC0-A824A6461CBD', 'clientSign' => 'cctvVideo', 'deviceId' => ['serial' => '', 'imei' => '', 'android_id' => '']];
    [$st, $txt] = curl_request(LIVE_V1_01_URL, fresh_headers($id), json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($st < 200 || $st >= 300) throw new Exception("live/v1/01 HTTP $st: " . substr($txt, 0, 300));
    $j = json_decode($txt, true);
    $videos = $j['data']['videoList'] ?? $j['data']['videos'] ?? [];
    $pick = null; $fallback = null;
    foreach ($videos as $v) {
        if (empty($v['url'])) continue;
        if ($fallback === null) $fallback = $v;
        if (($v['rate'] ?? '') === '36p') { $pick = $v; break; }
    }
    $v = $pick ?? $fallback;
    if (!$v) throw new Exception('live/v1/01 no usable URL');
    $url = $v['url'];
    if (!preg_match('~^https?://~i', $url)) $url = aes_gcm_decrypt_b64($url, $sessionKey);
    return ['url' => $url, 'rate' => $v['rate'] ?? '', 'rateName' => $v['rateName'] ?? ''];
}

function live02(array $id, string $sessionKey): string {
    $enc = aes_gcm_encrypt_b64('', $sessionKey);
    [$st, $txt] = curl_request(LIVE_V1_02_URL, fresh_headers($id), json_encode(['guid' => $enc]));
    if ($st < 200 || $st >= 300) throw new Exception("live/v1/02 HTTP $st: " . substr($txt, 0, 300));
    $j = json_decode($txt, true);
    $enc = $j['data']['appSecret'] ?? $j['data']['app_secret'] ?? $j['data'] ?? '';
    if (!$enc) throw new Exception('live/v1/02 missing appSecret');
    return aes_gcm_decrypt_b64($enc, $sessionKey);
}

function vdn(array $id, string $liveUrl, string $secret): array {
    $r = sprintf('%08x-0000-%04x-0000-00000000%04x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xffff));
    $sign = md5_hex(AK . $secret . $r);
    $appcommon = json_encode(['adid' => '', 'av' => VERSION, 'an' => VDN_APP_NAME, 'ap' => 'cctv_app_tv'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = array_merge(fresh_headers($id, 'application/x-www-form-urlencoded', null), ['APPID: ' . AK, 'APPSIGN: ' . $sign, 'APPRANDOMSTR: ' . $r]);
    $form = http_build_query(['appcommon' => $appcommon, 'url' => $liveUrl]);
    [$st, $txt] = curl_request(VDN_GETSTREAM_URL, $headers, $form);
    if ($st < 200 || $st >= 300) throw new Exception("VDN HTTP $st: " . substr($txt, 0, 300));
    $j = json_decode($txt, true);
    if ((string)($j['succeed'] ?? '') !== '1' || empty($j['url'])) throw new Exception('VDN did not return final URL: ' . substr($txt, 0, 400));
    return ['url' => $j['url'], 'sign' => $sign, 'random' => $r];
}

function resolve_channel(string $name, string $liveId): array {
    global $STATE_FILE, $CACHE_FILE;
    $state = load_state($STATE_FILE); $p = $state['profile']; $id = build_identity($p);
    collect_report($p, $id['x_uid']); dictionary_obtain(); $session = app_start($id, $p);
    app_event($id, $p); page_event($id, $p); heartbeat($id, $p); index_flow($id); warmup_flow($id);
    $l1 = live01($id, $state, $session, $liveId); $secret = live02($id, $session); $v = vdn($id, $l1['url'], $secret);
    
    $entry = [
        'channel' => $name, 'live_id' => $liveId, 'final_url' => $v['url'],
        'playback_headers' => ['UID' => $p['android_id'], 'APPID' => AK, 'Referer' => 'api.cctv.cn', 'User-Agent' => USER_AGENT, 'APPRANDOMSTR' => $v['random'], 'APPSIGN' => $v['sign']],
        'rate' => $l1['rate'], 'rate_name' => $l1['rateName'], 'refreshed_at' => now_s(), 'expires_at' => now_s() + CACHE_TTL, 'android_id' => $p['android_id']
    ];
    $cache = is_file($CACHE_FILE) ? json_decode((string)file_get_contents($CACHE_FILE), true) : [];
    if (!is_array($cache)) $cache = [];
    $cache[$name] = $entry;
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $tmp = $CACHE_FILE . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) @rename($tmp, $CACHE_FILE);
    return $entry;
}

function get_entry(string $name, string $liveId, int $refreshMargin = CACHE_REFRESH_MARGIN): array {
    global $CACHE_FILE;

    $loadCache = static function () use ($CACHE_FILE): array {
        if (!is_file($CACHE_FILE)) return [];
        $x = json_decode((string)file_get_contents($CACHE_FILE), true);
        return is_array($x) ? $x : [];
    };

    $cache = $loadCache();
    $cachedEntry = $cache[$name] ?? null;
    $now = now_s();

    // 热缓存直接返回，避免每次播放都触发 YSPTP 多步握手。
    if (is_array($cachedEntry)
        && !empty($cachedEntry['final_url'])
        && isset($cachedEntry['expires_at'])
        && $now < ((float)$cachedEntry['expires_at'] - $refreshMargin)) {
        return $cachedEntry;
    }

    // 同一频道只允许一个请求做刷新，避免多个播放器同时打开时重复请求 CCTV API。
    $lockFile = $CACHE_FILE . '.' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '.lock';
    $fp = @fopen($lockFile, 'c');
    if ($fp) {
        $locked = @flock($fp, LOCK_EX | LOCK_NB);
        if (!$locked) {
            $deadline = microtime(true) + PREWARM_LOCK_TIMEOUT;
            do {
                usleep(50000);
                $locked = @flock($fp, LOCK_EX | LOCK_NB);
            } while (!$locked && microtime(true) < $deadline);
        }

        if ($locked) {
            // 等待锁期间其他请求可能已经刷新成功，重新读取一次。
            $cache = $loadCache();
            $cachedEntry = $cache[$name] ?? null;
            $now = now_s();
            if (is_array($cachedEntry)
                && !empty($cachedEntry['final_url'])
                && isset($cachedEntry['expires_at'])
                && $now < ((float)$cachedEntry['expires_at'] - $refreshMargin)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                return $cachedEntry;
            }

            try {
                $entry = resolve_channel($name, $liveId);
                @flock($fp, LOCK_UN);
                @fclose($fp);
                return $entry;
            } catch (Throwable $ex) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
                // 刷新失败时优先使用旧地址，保证短时 API 波动不会直接黑屏。
                if (is_array($cachedEntry) && !empty($cachedEntry['final_url'])) return $cachedEntry;
                throw $ex;
            }
        }
        @fclose($fp);
    }

    // 无法加锁时，已有缓存优先兜底。
    if (is_array($cachedEntry) && !empty($cachedEntry['final_url'])) return $cachedEntry;
    return resolve_channel($name, $liveId);
}

function header_clean_value(string $v): string {
    return trim(str_replace(["\r", "\n"], '', $v));
}

function decode_chunked_php(string $raw): string {
    $pos = 0; $out = ''; $len = strlen($raw);
    while ($pos < $len) {
        $eol = strpos($raw, "\r\n", $pos);
        if ($eol === false) throw new Exception('chunked response malformed');
        $line = trim(substr($raw, $pos, $eol - $pos));
        $semi = strpos($line, ';');
        if ($semi !== false) $line = substr($line, 0, $semi);
        $n = hexdec($line); $pos = $eol + 2;
        if ($n === 0) break;
        if ($pos + $n > $len) throw new Exception('chunked response truncated');
        $out .= substr($raw, $pos, $n); $pos += $n;
        if (substr($raw, $pos, 2) === "\r\n") $pos += 2;
    }
    return $out;
}

function raw_http1(string $url, array $headers = [], ?string $range = null): array {
    $u = parse_url($url);
    if (!$u || strtolower($u['scheme'] ?? '') !== 'http') throw new Exception('raw HTTP requires http URL');
    $host = $u['host'] ?? '';
    if ($host === '') throw new Exception('upstream URL missing host');
    $port = (int)($u['port'] ?? 80);
    $hostHeader = $host . (isset($u['port']) ? ':' . $port : '');
    $target = $u['path'] ?? '/'; if ($target === '') $target = '/';
    if (isset($u['query'])) $target .= '?' . $u['query'];
    
    $fp = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, TIMEOUT, STREAM_CLIENT_CONNECT);
    if (!$fp) throw new Exception("upstream connect failed: $errstr ($errno)");
    stream_set_timeout($fp, TIMEOUT);
    
    $req = "GET $target HTTP/1.1\r\nHost: $hostHeader\r\n";
    foreach (['UID', 'APPID', 'APPRANDOMSTR', 'Referer', 'User-Agent', 'APPSIGN'] as $k) {
        if (isset($headers[$k]) && trim((string)$headers[$k]) !== '') {
            $req .= $k . ': ' . header_clean_value((string)$headers[$k]) . "\r\n";
        }
    }
    $req .= "Accept: */*\r\nAccept-Encoding: identity\r\nConnection: close\r\n";
    if ($range !== null) $req .= 'Range: ' . header_clean_value($range) . "\r\n";
    $req .= "\r\n";
    
    $written = @fwrite($fp, $req);
    if ($written === false) { fclose($fp); throw new Exception('upstream request write failed'); }
    
    $raw = '';
    while (!feof($fp)) {
        $chunk = fread($fp, 8192);
        if ($chunk === false || $chunk === '') break;
        $raw .= $chunk;
        if (strlen($raw) > 32 * 1024 * 1024) break;
    }
    fclose($fp);
    
    $split = strpos($raw, "\r\n\r\n");
    if ($split === false) throw new Exception('upstream response missing header/body separator');
    $ht = substr($raw, 0, $split); $body = substr($raw, $split + 4);
    $lines = explode("\r\n", $ht);
    $status = 0;
    if (isset($lines[0]) && preg_match('~^HTTP/\S+\s+(\d+)~', $lines[0], $m)) $status = (int)$m[1];
    if (!$status) throw new Exception('invalid upstream HTTP status');
    
    $ct = 'application/vnd.apple.mpegurl'; $chunked = false;
    foreach (array_slice($lines, 1) as $line) {
        $x = explode(':', $line, 2); if (count($x) !== 2) continue;
        $k = strtolower(trim($x[0])); $v = trim($x[1]);
        if ($k === 'content-type') $ct = $v;
        elseif ($k === 'transfer-encoding' && stripos($v, 'chunked') !== false) $chunked = true;
    }
    if ($chunked) $body = decode_chunked_php($body);
    return [$status, $body, $ct];
}

function upstream(string $url, array $headers = [], ?string $range = null): array {
    if (strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'http') return raw_http1($url, $headers, $range);
    $h = $headers; $h[] = 'Accept: */*'; $h[] = 'Accept-Encoding: identity';
    if ($range) $h[] = 'Range: ' . $range;
    return curl_request($url, $h, null, 'GET', false, false);
}

/**
 * 低延迟 TS 代理：
 * 原版会先把整个 TS 文件读完，再一次性 echo，播放器必须等一个切片完整下载后才能收到数据。
 * 这里改成边收边发，首字节到达就立即交给播放器。
 */
function stream_upstream(string $url, array $headers = [], ?string $range = null): void {
    $ch = curl_init($url);
    if (!$ch) throw new Exception('curl init failed');

    $sent = false;
    $status = 0;

    $httpHeaders = [];
    foreach ($headers as $k => $v) {
        if (is_int($k)) {
            $line = (string)$v;
            if (stripos($line, 'Accept:') === 0 || stripos($line, 'Accept-Encoding:') === 0 ||
                stripos($line, 'Connection:') === 0) continue;
            $httpHeaders[] = $line;
        } else {
            $httpHeaders[] = $k . ': ' . $v;
        }
    }
    $httpHeaders[] = 'Accept: */*';
    $httpHeaders[] = 'Accept-Encoding: identity';
    $httpHeaders[] = 'Connection: keep-alive';
    if ($range !== null) $httpHeaders[] = 'Range: ' . header_clean_value($range);

    while (ob_get_level() > 0) @ob_end_flush();
    @ob_implicit_flush(true);
    if (function_exists('set_time_limit')) @set_time_limit(0);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_TCP_NODELAY => true,
        CURLOPT_TCP_KEEPALIVE => 1,
        CURLOPT_TCP_KEEPIDLE => 20,
        CURLOPT_TCP_KEEPINTVL => 10,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$sent, &$status): int {
            $trim = trim($line);
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $trim, $m)) {
                $status = (int)$m[1];
                if ($status >= 200 && $status < 300) {
                    http_response_code($status);
                    header('Cache-Control: no-cache, no-store, max-age=0');
                    header('Access-Control-Allow-Origin: *');
                } elseif ($status >= 300) {
                    http_response_code($status);
                }
                return strlen($line);
            }
            if ($trim === '' || $sent) return strlen($line);

            if (stripos($line, 'Content-Type:') === 0) {
                header(trim($line));
            } elseif (stripos($line, 'Content-Length:') === 0) {
                header(trim($line));
            } elseif (stripos($line, 'Content-Range:') === 0) {
                header(trim($line));
            } elseif (stripos($line, 'Accept-Ranges:') === 0) {
                header(trim($line));
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$sent): int {
            if (!$sent) {
                $sent = true;
                if (!headers_sent()) {
                    header('Content-Type: video/MP2T');
                    header('Cache-Control: no-cache, no-store, max-age=0');
                    header('Access-Control-Allow-Origin: *');
                }
            }
            echo $data;
            flush();
            return strlen($data);
        },
    ]);

    if (!empty($headers['Cookie'])) {
        // no-op; retained for compatibility if future callers pass associative headers.
    }

    $ok = curl_exec($ch);
    if ($ok === false) {
        $err = curl_error($ch);
        curl_close($ch);
        if (!$sent) throw new Exception('stream curl: ' . $err);
        return;
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$sent && ($httpCode < 200 || $httpCode >= 300)) {
        http_response_code($httpCode ?: 502);
        echo 'upstream HTTP ' . ($httpCode ?: 0);
    }
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
    $pi = $_SERVER['PATH_INFO'] ?? '';
    return $pi !== '' ? $pi : $uriPath;
}


function playlist_cache_file(string $name): string {
    if (!is_dir(PLAYLIST_CACHE_DIR)) @mkdir(PLAYLIST_CACHE_DIR, 0755, true);
    return PLAYLIST_CACHE_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '.json';
}

function playlist_cached(string $name): ?string {
    $f = playlist_cache_file($name);
    if (!is_file($f)) return null;
    $x = json_decode((string)@file_get_contents($f), true);
    if (!is_array($x) || empty($x['body']) || (float)($x['expires'] ?? 0) < microtime(true)) return null;
    return (string)$x['body'];
}

function playlist_cache_put(string $name, string $body): void {
    $f = playlist_cache_file($name);
    $tmp = $f . '.' . getmypid() . '.tmp';
    $x = json_encode(['expires' => microtime(true) + PLAYLIST_CACHE_TTL, 'body' => $body], JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $x, LOCK_EX) !== false) @rename($tmp, $f);
}

function proxy_token(string $channel, string $url): string {
    return b64url_encode(json_encode(['c' => $channel, 'u' => $url], JSON_UNESCAPED_SLASHES));
}

function rewrite_playlist_fast(string $channel, string $playlist, string $finalUrl, string $proxyBase): string {
    $parts = parse_url($finalUrl);
    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'] ?? '';
    $baseOrigin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $dir = trim(str_replace('\\', '/', dirname($parts['path'] ?? '/')), '/');
    $out = [];
    foreach (preg_split('/\r?\n/', $playlist) as $line) {
        $s = trim($line);
        if ($s === '' || str_starts_with($s, '#')) { $out[] = $line; continue; }
        if (preg_match('~^https?://~i', $s)) $u = $s;
        elseif (str_starts_with($s, '/')) $u = $baseOrigin . $s;
        else $u = $baseOrigin . ($dir ? '/' . $dir : '') . '/' . $s;
        if (preg_match('~\.(?:ts|aac|m4s)(?:\?|$)~i', $u)) {
            $out[] = $proxyBase . '/proxy.ts?ts=' . proxy_token($channel, $u);
        } else {
            $out[] = $u;
        }
    }
    return implode("\n", $out) . "\n";
}

function handle(): void {
    global $CHANNELS, $CACHE_FILE;
    $path = request_subpath();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // 默认根路径返回 IPTV M3U 播放列表，附加 ?ui=1 访问网页版导航
    if ($path === '/' || $path === '') {
        $base = script_base();
        if (isset($_GET['ui'])) {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
            echo '<title>央视频 YSPTP 直播源</title><body style="font-family:sans-serif;max-width:720px;margin:40px auto;padding:0 18px">';
            echo '<h2>央视频电视直播</h2><ul>';
            foreach ($CHANNELS as $key => $info) {
                echo '<li><a href="' . htmlspecialchars($base . '/' . $key . '.m3u8', ENT_QUOTES) . '">' . htmlspecialchars($info['name']) . ' (' . $key . '.m3u8)</a></li>';
            }
            echo '</ul></body></html>';
            return;
        }

        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: no-cache, no-store, max-age=0');
        header('Access-Control-Allow-Origin: *');
        echo "#EXTM3U\n";
        foreach ($CHANNELS as $key => $info) {
            $name = $info['name'];
            $group = $info['group'];
            echo '#EXTINF:-1 tvg-name="' . str_replace('"', '', $name) . '" group-title="' . str_replace('"', '', $group) . '",' . $name . "\n";
            echo $base . '/' . $key . '.m3u8' . "\n";
        }
        return;
    }

    if (isset($_GET['status'])) {
        $cache = is_file($CACHE_FILE) ? json_decode((string)file_get_contents($CACHE_FILE), true) : [];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['channels' => $cache, 'php' => PHP_VERSION], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return;
    }

    // TS 切片代理
    if ($path === '/proxy.ts' || $path === '/proxy' || isset($_GET['proxy'])) {
        if ($method !== 'GET') { http_response_code(405); return; }
        $token = $_GET['ts'] ?? '';
        $decoded = b64url_decode($token);
        $real = false;
        $proxyChannel = '';
        if ($decoded !== false) {
            // 新格式：{"c":"cctv3","u":"http://...ts"}。
            $obj = json_decode($decoded, true);
            if (is_array($obj) && !empty($obj['u'])) {
                $real = (string)$obj['u'];
                $proxyChannel = strtolower((string)($obj['c'] ?? ''));
            } else {
                // 兼容旧版仅编码 URL 的 token。
                $real = $decoded;
            }
        }
        if ($real === false || !preg_match('~^https?://~i', $real)) {
            http_response_code(400); echo 'missing/invalid ts'; return;
        }

        // 新 token 直接按频道取鉴权头，避免每个 TS 请求都扫描整个 JSON 缓存。
        $ph = [];
        if ($proxyChannel !== '') {
            $cache = is_file($CACHE_FILE) ? json_decode((string)file_get_contents($CACHE_FILE), true) : [];
            if (is_array($cache) && isset($cache[$proxyChannel]) && is_array($cache[$proxyChannel])) {
                $ph = $cache[$proxyChannel]['playback_headers'] ?? [];
            }
        } else {
            // 兼容旧 token：仅在旧客户端请求时才走一次旧的 host 匹配。
            $cache = is_file($CACHE_FILE) ? json_decode((string)file_get_contents($CACHE_FILE), true) : [];
            if (is_array($cache)) {
                $realHost = strtolower((string)parse_url($real, PHP_URL_HOST));
                foreach ($cache as $entry) {
                    if (!is_array($entry) || empty($entry['final_url'])) continue;
                    if (strtolower((string)parse_url($entry['final_url'], PHP_URL_HOST)) === $realHost) {
                        $ph = $entry['playback_headers'] ?? [];
                        break;
                    }
                }
            }
        }
        $range = $_SERVER['HTTP_RANGE'] ?? null;
        stream_upstream($real, $ph, $range);
        return;
    }

    // 解析频道参数
    $name = isset($_GET['channel']) ? strtolower(trim((string)$_GET['channel'])) : '';
    if ($name === '') {
        $name = strtolower(trim($path, '/'));
        if (str_ends_with($name, '.m3u8')) $name = substr($name, 0, -5);
    }

    if (!isset($CHANNELS[$name])) {
        http_response_code(404); echo 'not found'; return;
    }
    if ($method === 'HEAD') {
        http_response_code(200); header('Content-Type: application/vnd.apple.mpegurl'); return;
    }

    try {
        $channelConfig = $CHANNELS[$name];

        // 极短 M3U8 缓存：多人同时打开时复用同一份直播列表，避免重复请求上游。
        $out = playlist_cached($name);
        $e = null;
        if ($out === null) {
            $e = get_entry($name, $channelConfig['id']);
            [$st, $playlist, $ct] = upstream($e['final_url'], $e['playback_headers']);
            if ($st < 200 || $st >= 300) throw new Exception("playlist HTTP $st");
            $out = rewrite_playlist_fast($name, $playlist, $e['final_url'], script_base());
            playlist_cache_put($name, $out);
        } else {
            $cache = is_file($CACHE_FILE) ? json_decode((string)file_get_contents($CACHE_FILE), true) : [];
            $e = is_array($cache) ? ($cache[$name] ?? null) : null;
            if (!is_array($e) || empty($e['final_url'])) {
                $e = get_entry($name, $channelConfig['id']);
            }
        }

        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: no-cache, no-store, max-age=0');
        header('Access-Control-Allow-Origin: *');
        header('X-Accel-Buffering: no');
        header('X-CCTV-Channel: ' . ($e['channel'] ?? $name));
        header('X-CCTV-Rate: ' . ($e['rate'] ?? ''));
        echo $out;
    } catch (Throwable $x) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'YSPTP PHP error: ' . $x->getMessage();
    }
}

if (PHP_SAPI === 'cli' && in_array('--prewarm', $argv ?? [], true)) {
    $started = microtime(true);
    $ok = 0; $fail = 0;
    foreach ($CHANNELS as $key => $info) {
        try {
            get_entry($key, $info['id'], PREWARM_MARGIN);
            echo date('c') . " prewarm OK $key\n";
            $ok++;
        } catch (Throwable $e) {
            echo date('c') . " prewarm FAIL $key: " . $e->getMessage() . "\n";
            $fail++;
        }
    }
    echo "prewarm done: ok=$ok fail=$fail elapsed=" . round(microtime(true) - $started, 2) . "s\n";
    exit($fail ? 1 : 0);
}

if (PHP_SAPI === 'cli') {
    $port = 8766;
    foreach ($argv ?? [] as $a) {
        if (str_starts_with($a, '--port=')) $port = (int)substr($a, 7);
    }
    echo "YSPTP PHP listening on 0.0.0.0:$port\n";
    passthru('php -S 0.0.0.0:' . (int)$port . ' ' . escapeshellarg(__FILE__));
    exit;
}

handle();
