<?php
declare(strict_types=1);

if (!defined('IPTV_STANDALONE_DATA_DIRNAME')) define('IPTV_STANDALONE_DATA_DIRNAME', 'data_myvideo');
if (!defined('IPTV_STANDALONE_SERVICE_NAME')) define('IPTV_STANDALONE_SERVICE_NAME', 'myvideo');

function iptv_standalone_data_dir(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . IPTV_STANDALONE_DATA_DIRNAME;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}
function iptv_fs_log_failure(string $stage, string $file): void {
    $dir = iptv_standalone_data_dir();
    @file_put_contents($dir . DIRECTORY_SEPARATOR . 'fs_errors.log', '[' . date('c') . '] ' . $stage . ' ' . $file . "\n", FILE_APPEND | LOCK_EX);
}
function iptv_fs_atomic_write(string $file, string $content, int $perm = 0644): bool {
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { iptv_fs_log_failure('mkdir_failed', $file); return false; }
    $tmp = $file . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) { iptv_fs_log_failure('write_failed', $file); return false; }
    @chmod($tmp, $perm);
    if (!@rename($tmp, $file)) {
        @unlink($file);
        if (!@rename($tmp, $file)) { @unlink($tmp); iptv_fs_log_failure('rename_failed', $file); return false; }
    }
    @chmod($file, $perm);
    return true;
}
function iptv_fs_read_json(string $file, $default = null, ?int $maxBytes = null) {
    if (!is_file($file)) return $default;
    $size = @filesize($file);
    if ($maxBytes !== null && $size !== false && $size > $maxBytes) return $default;
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}
function iptv_fs_write_json(string $file, array $data, int $perm = 0644, bool $pretty = true): bool {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0);
    $json = json_encode($data, $flags);
    if (!is_string($json)) return false;
    return iptv_fs_atomic_write($file, $json . "\n", $perm);
}
function iptv_standalone_log_settings_file(): string { return iptv_standalone_data_dir() . DIRECTORY_SEPARATOR . 'log_settings.json'; }
function iptv_log_global_enabled(): bool {
    $settings = iptv_fs_read_json(iptv_standalone_log_settings_file(), null, 100000);
    if (is_array($settings) && array_key_exists('enabled', $settings)) return !empty($settings['enabled']);
    $cfg = iptv_fs_read_json(iptv_standalone_data_dir() . DIRECTORY_SEPARATOR . 'app_config.json', [], 200000);
    if (is_array($cfg) && array_key_exists('logEnabled', $cfg)) return !empty($cfg['logEnabled']);
    return true;
}
function iptv_log_global_set_enabled(bool $enabled): bool {
    return iptv_fs_write_json(iptv_standalone_log_settings_file(), ['enabled'=>$enabled, 'updated_at'=>date('c')], 0644, true);
}
function iptv_current_script_url(): string {
    $firstHeaderValue = static function ($value): string {
        return trim(explode(',', (string)$value, 2)[0]);
    };
    $forwardedProto = strtolower($firstHeaderValue($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = $forwardedProto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
    $scheme = $https ? 'https' : 'http';
    $host = $firstHeaderValue($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    $forwardedPort = $firstHeaderValue($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '');
    $port = $forwardedPort !== '' ? (int)$forwardedPort : (int)($_SERVER['SERVER_PORT'] ?? 0);
    $hostHasPort = preg_match('/:\d+$/', $host) === 1 || str_starts_with($host, '[');
    if ($port > 0 && !$hostHasPort && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) $host .= ':' . $port;
    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $path = is_string($requestPath) && $requestPath !== '' ? $requestPath : (string)($_SERVER['SCRIPT_NAME'] ?? '/' . basename(__FILE__));
    $path = str_replace('\\', '/', $path);
    return $scheme . '://' . $host . $path;
}
function iptv_base_url_dir(): string { return iptv_current_script_url(); }
function iptv_integrator_myvideo_list_refresh_hours(): float {
    if (function_exists('myv_app_config')) { $cfg = myv_app_config(); return max(0.25, (float)($cfg['channelRefreshHours'] ?? 4)); }
    return 4.0;
}
function iptv_integrator_myvideo_list_refresh_seconds(): int { return max(60, (int)round(iptv_integrator_myvideo_list_refresh_hours() * 3600)); }

if (!defined('MYV_HOST'))        define('MYV_HOST',        'mgw3.myvideo.net.tw');
if (!defined('MYV_APP_VERSION')) define('MYV_APP_VERSION', '5.0.2.20');
if (!defined('MYV_API_VERSION')) define('MYV_API_VERSION', '112');
if (!defined('MYV_API_CHK'))     define('MYV_API_CHK',     '9e76ab');
if (!defined('MYV_ANDROID_UA'))  define('MYV_ANDROID_UA',  'okhttp/4.12.0');
if (!defined('MYV_STREAM_CACHE_TTL'))    define('MYV_STREAM_CACHE_TTL', 300);
if (!defined('MYV_STREAM_CACHE_SCHEMA')) define('MYV_STREAM_CACHE_SCHEMA', 1);


function myv_paths(): array {
    static $p = null;
    if ($p !== null) return $p;
    $root = __DIR__;
    $dir  = $root . DIRECTORY_SEPARATOR . IPTV_STANDALONE_DATA_DIRNAME;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $streamDir = $dir . DIRECTORY_SEPARATOR . 'stream_cache';
    $lastRefreshDir = $dir . DIRECTORY_SEPARATOR . 'last_refresh';
    $jobsDir = $dir . DIRECTORY_SEPARATOR . 'jobs';

    foreach ([$streamDir, $lastRefreshDir, $jobsDir] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
    }

    $p = [
        'root'             => $root,
        'dir'              => $dir,
        'settings'         => $dir . DIRECTORY_SEPARATOR . 'myv_settings.json',
        'list'             => $dir . DIRECTORY_SEPARATOR . 'myv_list.json',
        'log'              => $dir . DIRECTORY_SEPARATOR . 'myv_player_debug.log',
        'lock'             => $dir . DIRECTORY_SEPARATOR . 'myv_auto_refresh.lock',
        'stream_dir'       => $streamDir,
        'last_refresh_dir' => $lastRefreshDir,
        'jobs_dir'         => $jobsDir,
    ];


    return $p;
}


function myv_now(): int { return time(); }

function myv_global_log_enabled(): bool {
    return function_exists('iptv_log_global_enabled') && iptv_log_global_enabled();
}

function myv_log_enabled(): bool {
    try {
        if (function_exists('myv_app_config')) {
            $cfg = myv_app_config();
            if (is_array($cfg) && array_key_exists('logEnabled', $cfg)) return !empty($cfg['logEnabled']);
        }
    } catch (\Throwable $e) { return false; }
    try {
        return myv_global_log_enabled();
    } catch (\Throwable $e) { return false; }
}

function myv_log_rotate(string $file): void {
    if (is_file($file) && filesize($file) > 1048576) {
        @file_put_contents($file, '[' . date('c') . "] log truncated: over 1MB\n", LOCK_EX);
    }
}

function myv_log_scrub($v) {
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $vv) {
            $lk = strtolower((string)$k);
            if (preg_match('/token|value|password|fsvalue|auth|header_key|link_id|enc_key|cookie/i', $lk)) {
                $out[$k] = '[redacted]';
            } else {
                $out[$k] = myv_log_scrub($vv);
            }
        }
        return $out;
    }
    if (is_string($v)) {
        $v = preg_replace('/([?&](?:token|fsVALUE|value|auth|key)=)[^&]+/i', '$1[redacted]', (string)$v);
        if (function_exists('mb_strlen') && mb_strlen($v, 'UTF-8') > 600) {
            return mb_substr($v, 0, 600, 'UTF-8') . '…';
        }
        if (strlen($v) > 600) return substr($v, 0, 600) . '…';
        return $v;
    }
    return $v;
}

function myv_log(string $event, array $context = [], bool $force = false): void {


    if (!myv_log_enabled()) return;
    $p = myv_paths();
    myv_log_rotate($p['log']);
    $line = '[' . date('c') . '] ' . $event;
    $meta = [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
    ];
    $payload = array_merge($meta, myv_log_scrub($context));
    if ($payload) $line .= ' ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($p['log'], $line . "\n", FILE_APPEND | LOCK_EX);
}


function myv_read_json(string $file, $default = null) {

    return iptv_fs_read_json($file, $default, null);
}

function myv_write_json(string $file, array $data, int $perm = 0644): bool {

    return iptv_fs_write_json($file, $data, $perm, true);
}


function myv_default_settings(): array {
    return [
        'version'            => 1,
        'updated_at'         => date('c'),
        'selection_rules'    => [
            'include_groups'      => [],
            'exclude_channel_ids' => [],
        ],
        'group_order'        => [],
        'channel_order'      => new \stdClass(),
        'channel_sort_mode'  => 'id_asc',
        'channel_group_map'  => new \stdClass(),
    ];
}

function myv_settings_cache(?array $set = null, bool $reset = false): ?array {
    static $cache = null;
    if ($reset) { $cache = null; return null; }
    if ($set !== null) { $cache = $set; }
    return $cache;
}
function myv_settings_load(): array {
    $cached = myv_settings_cache();
    if (is_array($cached)) return $cached;
    $p = myv_paths();
    $loaded = myv_read_json($p['settings'], null);
    $def = myv_default_settings();
    if (!is_array($loaded)) { myv_settings_cache($def); return $def; }

    $out = array_replace_recursive($def, $loaded);

    if (!isset($out['selection_rules']) || !is_array($out['selection_rules'])) {
        $out['selection_rules'] = $def['selection_rules'];
    }
    $out['selection_rules']['include_groups'] = array_values(array_filter((array)($out['selection_rules']['include_groups'] ?? []), 'is_string'));
    $out['selection_rules']['exclude_channel_ids'] = array_values(array_map('strval', (array)($out['selection_rules']['exclude_channel_ids'] ?? [])));
    if (!is_array($out['group_order']) || empty($out['group_order'])) {
        $out['group_order'] = $def['group_order'];
    }
    if (!is_array($out['channel_order'])) {
        $out['channel_order'] = (object)[];
    }

    $sortMode = (string)($out['channel_sort_mode'] ?? 'id_asc');
    $allowedSortModes = ['id_asc','id_then_name','name_asc','name_then_id','manual'];
    $out['channel_sort_mode'] = in_array($sortMode, $allowedSortModes, true) ? $sortMode : 'id_asc';
    if (!is_array($out['channel_group_map'])) {
        $out['channel_group_map'] = (object)[];
    }
    unset($out['auto_refresh_hours']);
    myv_settings_cache($out);
    return $out;
}


function myv_stream_cache_ttl_seconds(): int {

    return MYV_STREAM_CACHE_TTL;
}

function myv_settings_save(array $patch): array {
    $cur = myv_settings_load();

    $allowed = ['selection_rules','group_order','channel_order','channel_sort_mode','channel_group_map'];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $patch)) $cur[$k] = $patch[$k];
    }

    unset($cur['auto_refresh_hours']);
    $cur['updated_at'] = date('c');
    if (!is_array($cur['selection_rules'])) $cur['selection_rules'] = [];
    $cur['selection_rules']['include_groups'] = array_values(array_filter((array)($cur['selection_rules']['include_groups'] ?? []), 'is_string'));
    $cur['selection_rules']['exclude_channel_ids'] = array_values(array_map('strval', (array)($cur['selection_rules']['exclude_channel_ids'] ?? [])));
    if (!is_array($cur['group_order'])) $cur['group_order'] = [];
    $sortMode = (string)($cur['channel_sort_mode'] ?? 'id_asc');
    $allowedSortModes = ['id_asc','id_then_name','name_asc','name_then_id','manual'];
    $cur['channel_sort_mode'] = in_array($sortMode, $allowedSortModes, true) ? $sortMode : 'id_asc';

    $p = myv_paths();
    if (!myv_write_json($p['settings'], $cur)) {
        myv_log('settings_save_failed', ['file'=>$p['settings']], true);
        throw new \RuntimeException('WRITE_FAILED: myv_settings.json 無法寫入，請檢查 data_myvideo 目錄權限');
    }
    myv_settings_cache(null, true);
    myv_log('settings_saved', ['keys'=>array_keys($patch)]);
    return $cur;
}


function myv_stream_channel_dir(int $channelId): string {
    $dir = myv_paths()['stream_dir'] . DIRECTORY_SEPARATOR . 'channel_' . max(0, $channelId);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function myv_api_common_params(): array {
    return [
        'apiVersion' => MYV_API_VERSION,
        'devType' => 'Handset',
        'kidMode' => '0',
        'chk' => MYV_API_CHK,
        'chl' => 'android',
    ];
}

function myv_http_get_json(string $path, array $params = [], ?int $timeout = null): array {
    $timeout = $timeout ?? (function_exists('myv_req_timeout') ? myv_req_timeout() : 20);
    $timeout = max(5, min(120, $timeout));
    $path = '/' . ltrim($path, '/');
    $url = 'https://' . MYV_HOST . $path;
    if ($params) $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $body = false;
    $http = 0;
    $error = '';
    $effective = $url;
    $started = microtime(true);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => MYV_ANDROID_UA,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (defined('CURL_HTTP_VERSION_2TLS')) $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string)(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url);
        if ($body === false) $error = 'curl_' . curl_errno($ch) . ': ' . curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: " . MYV_ANDROID_UA . "\r\n",
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer'=>true, 'verify_peer_name'=>true],
        ]);
        $body = @file_get_contents($url, false, $context);
        foreach ((array)($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string)$line, $m)) $http = (int)$m[1];
        }
        if ($body === false) $error = 'stream_request_failed';
    }

    $payload = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($payload)) $payload = [];
    $meta = [
        'path'=>$path,
        'http'=>$http,
        'elapsed_ms'=>(int)round((microtime(true) - $started) * 1000),
        'host'=>(string)(parse_url($effective, PHP_URL_HOST) ?: MYV_HOST),
        'error'=>$error,
    ];
    myv_log('api_http', $meta);
    return [$http, $payload, $meta];
}

function myv_dedupe_channels(array $channels): array {
    $seen = [];
    $out = [];
    foreach ($channels as $channel) {
        if (!is_array($channel)) continue;
        $asset = trim((string)($channel['fsMyVideo_ID'] ?? ''));
        $name = trim((string)($channel['fsNAME'] ?? ''));
        if ($asset === '' || $name === '' || isset($seen[$asset])) continue;
        $seen[$asset] = true;
        $out[] = $channel;
    }
    myv_log('channels_deduplicated', [
        'input_count'=>count($channels),
        'output_count'=>count($out),
        'duplicates_removed'=>max(0, count($channels) - count($out)),
        'key'=>'tvChannelId',
    ], true);
    return $out;
}

function myv_get_all_channels(bool $force_refresh = false): array {
    static $memo = null;
    if (!$force_refresh && is_array($memo)) return $memo;

    $p = myv_paths();
    $cached = myv_read_json($p['list'], null);
    if (!$force_refresh && is_array($cached) && isset($cached['fetched_at'], $cached['channels'])
        && (myv_now() - (int)$cached['fetched_at']) < iptv_integrator_myvideo_list_refresh_seconds()) {
        $memo = is_array($cached['channels']) ? $cached['channels'] : [];
        $GLOBALS['MYVIDEO_LAST_CHANNEL_REFRESH'] = [
            'ok'=>true, 'updated'=>false, 'cache_hit'=>true,
            'count'=>count($memo), 'fetched_at'=>(int)$cached['fetched_at'],
        ];
        return $memo;
    }

    [$http, $payload, $meta] = myv_http_get_json(
        '/twmsgw.api/FindTVChannelFullList.json',
        array_merge(myv_api_common_params(), ['retImageType'=>'0'])
    );
    $status = is_array($payload['status'] ?? null) ? $payload['status'] : [];
    $categories = $payload['data']['tvCategoryList'] ?? null;
    if ($http < 200 || $http >= 300 || (string)($status['code'] ?? '') !== '0' || !is_array($categories)) {
        $memo = is_array($cached['channels'] ?? null) ? $cached['channels'] : [];
        $GLOBALS['MYVIDEO_LAST_CHANNEL_REFRESH'] = [
            'ok'=>false, 'updated'=>false, 'reason'=>'MYVIDEO_REMOTE_DATA_INVALID',
            'http'=>$http, 'api_code'=>(string)($status['code'] ?? ''),
            'message'=>(string)($status['message'] ?? $status['description'] ?? $meta['error'] ?? ''),
            'count'=>count($memo),
        ];
        myv_log('channels_failed', $GLOBALS['MYVIDEO_LAST_CHANNEL_REFRESH'], true);
        return $memo;
    }

    $channels = [];
    $officialGroups = [];
    $usedNumbers = [];
    $fallbackNumber = 1;
    foreach ($categories as $groupIndex => $category) {
        if (!is_array($category)) continue;
        $groupId = trim((string)($category['tvCategoryId'] ?? ''));
        $groupName = trim((string)($category['tvCategoryName'] ?? ''));
        if ($groupName === '') continue;
        $officialGroups[] = [
            'id'=>$groupId,
            'name'=>$groupName,
            'icon'=>trim((string)($category['tvCategoryIcon'] ?? '')),
            'order'=>(int)$groupIndex,
        ];
        foreach ((array)($category['tvChannelList'] ?? []) as $channelIndex => $raw) {
            if (!is_array($raw)) continue;
            $asset = trim((string)($raw['tvChannelId'] ?? ''));
            $name = trim((string)($raw['tvChannelName'] ?? ''));
            if ($asset === '' || $name === '') continue;
            $number = (int)($raw['tvChannelNumber'] ?? 0);
            while ($number <= 0 || isset($usedNumbers[$number])) $number = $fallbackNumber++;
            $usedNumbers[$number] = true;
            $fallbackNumber = max($fallbackNumber, $number + 1);
            $logo = trim((string)($raw['logoUrlNoBG'] ?? $raw['logoUrl16x9'] ?? $raw['logoUrlBG'] ?? $raw['mainPicUrl'] ?? ''));
            $channels[] = [
                'fnID'=>$number,
                'fsMyVideo_ID'=>$asset,
                'fsNAME'=>$name,
                'fsLOGO_MOBILE'=>$logo,
                'fsLOGO'=>$logo,
                'fcFREE'=>true,
                'fsCDN_ROUTE'=>'LIVE',
                'fsGROUP_ID'=>$groupId,
                'fsGROUP_NAME'=>$groupName,
                'fnGROUP_ORDER'=>(int)$groupIndex,
                'fnCHANNEL_ORDER'=>(int)$channelIndex,
                'fsDESCRIPTION'=>trim((string)($raw['description'] ?? '')),
                'fsGRADED_NAME'=>trim((string)($raw['gradedName'] ?? '')),
                'fcEVENT'=>strtoupper(trim((string)($raw['isEvent'] ?? 'N'))) === 'Y',
                'fsSTATUS_DESC'=>trim((string)($raw['channelStatusDesc'] ?? '')),
            ];
        }
    }
    $rawCount = count($channels);
    $channels = myv_dedupe_channels($channels);
    if (!$channels || count($officialGroups) === 0) {
        $memo = is_array($cached['channels'] ?? null) ? $cached['channels'] : [];
        $GLOBALS['MYVIDEO_LAST_CHANNEL_REFRESH'] = [
            'ok'=>false, 'updated'=>false, 'reason'=>'MYVIDEO_NORMALIZED_LIST_EMPTY', 'count'=>count($memo),
        ];
        myv_log('channels_failed', $GLOBALS['MYVIDEO_LAST_CHANNEL_REFRESH'], true);
        return $memo;
    }

    $snapshot = [
        'fetched_at'=>myv_now(),
        'fetched_at_iso'=>date('c'),
        'source'=>'android_app_api',
        'api_version'=>MYV_API_VERSION,
        'official_groups'=>$officialGroups,
        'channels'=>$channels,
    ];
    if (!myv_write_json($p['list'], $snapshot)) {
        $memo = is_array($cached['channels'] ?? null) ? $cached['channels'] : [];
        $GLOBALS['MYVIDEO_LAST_CHANNEL_REFRESH'] = [
            'ok'=>false, 'updated'=>false, 'reason'=>'MYVIDEO_CHANNEL_LIST_WRITE_FAILED', 'count'=>count($memo),
        ];
        myv_log('channels_cache_write_failed', ['file'=>$p['list']], true);
        return $memo;
    }

    $GLOBALS['MYVIDEO_LAST_CHANNEL_REFRESH'] = [
        'ok'=>true, 'updated'=>true, 'cache_hit'=>false,
        'count'=>count($channels), 'groups'=>count($officialGroups),
    ];
    myv_log('channels_updated', ['raw_count'=>$rawCount, 'count'=>count($channels), 'groups'=>count($officialGroups)], true);
    $memo = $channels;
    return $memo;
}

function myv_channel_logo_original(array $channel): string {
    return trim((string)($channel['fsLOGO_MOBILE'] ?? $channel['fsLOGO'] ?? ''));
}

function myv_list_load(): array {
    $p = myv_paths();
    $cached = myv_read_json($p['list'], null);
    if (is_array($cached) && isset($cached['channels']) && is_array($cached['channels'])) {
        return $cached['channels'];
    }
    return [];
}

function myv_sync(bool $force = false): array {
    try {
        return myv_get_all_channels($force);
    } catch (\Throwable $e) {
        myv_log('sync_failed', ['err'=>$e->getMessage()]);
        return myv_list_load();
    }
}

function myv_sync_checked(bool $force = false): array {
    $GLOBALS['MYVIDEO_LAST_CHANNEL_REFRESH'] = null;
    try {
        $channels = myv_get_all_channels($force);
        $status = $GLOBALS['MYVIDEO_LAST_CHANNEL_REFRESH'] ?? null;
        if (!is_array($status)) {
            $status = [
                'ok' => !$force,
                'updated' => false,
                'reason' => $force ? 'MYVIDEO_REFRESH_STATUS_MISSING' : '',
                'count' => is_array($channels) ? count($channels) : 0,
            ];
        }
        $status['channels'] = $channels;
        $status['count'] = is_array($channels) ? count($channels) : (int)($status['count'] ?? 0);
        if ($force && empty($status['updated'])) {
            $status['ok'] = false;
            if (empty($status['reason'])) $status['reason'] = 'MYVIDEO_REMOTE_REFRESH_FAILED_OR_STALE_CACHE';
        }
        return $status;
    } catch (\Throwable $e) {
        myv_log('sync_failed', ['err'=>$e->getMessage()]);
        $channels = myv_list_load();
        return [
            'ok' => false,
            'updated' => false,
            'reason' => 'MYVIDEO_SYNC_EXCEPTION',
            'error' => $e->getMessage(),
            'channels' => $channels,
            'count' => count($channels),
        ];
    }
}


function myv_select_channel(array $channels, int $channel_id = 0, string $asset_id = '', string $channel_name = ''): ?array {
    if ($channel_id > 0 && $asset_id !== '') {
        foreach ($channels as $channel) {
            if ((int)($channel['fnID'] ?? 0) === $channel_id && (string)($channel['fsMyVideo_ID'] ?? '') === $asset_id) return $channel;
        }
    }
    if ($channel_id > 0) {
        foreach ($channels as $channel) {
            if ((int)($channel['fnID'] ?? 0) === $channel_id) return $channel;
        }
    }
    if ($asset_id !== '') {
        foreach ($channels as $channel) {
            if ((string)($channel['fsMyVideo_ID'] ?? '') === $asset_id) return $channel;
        }
    }
    if ($channel_name !== '') {
        foreach ($channels as $channel) {
            if (strpos((string)($channel['fsNAME'] ?? ''), $channel_name) !== false) return $channel;
        }
    }
    return null;
}


function myv_stream_cache_file(int $cid, int $idx = 0): string {
    return myv_stream_channel_dir($cid) . DIRECTORY_SEPARATOR . 'stream_' . $idx . '.json';
}

function myv_stream_cache_all_file(int $cid, string $asset_id = ''): string {
    $suffix = $asset_id !== '' ? ('_' . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $asset_id)) : '';
    return myv_stream_channel_dir($cid) . DIRECTORY_SEPARATOR . 'urls' . $suffix . '.json';
}


function myv_stream_host_score(string $url): int {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') return 0;


    $score = 50;


    if (strpos($host, 'myvideofreetv-mozai.myvideo.tv') !== false) $score += 150;
    if (strpos($host, 'myvideofree-mozai.myvideo.tv') !== false) $score += 150;
    if (strpos($host, 'myvideofreetv-cds.cdn.hinet.net') !== false) $score += 80;
    if (strpos($host, 'myvideofree-cds.cdn.hinet.net') !== false) $score += 80;
    if (strpos($host, 'myvideo') !== false) $score += 30;
    if (strpos($host, 'hinet.net') !== false) $score += 20;
    if (strpos($host, 'mediatailor') !== false || strpos($host, 'amazonaws.com') !== false) $score -= 80;
    if (preg_match('/\.m3u8(\?|$)/i', $url)) $score += 10;
    return $score;
}

function myv_select_best_stream_index(array $urls, array $probeResults = []): int {
    $bestIdx = 0;
    $bestScore = PHP_INT_MIN;
    foreach (array_values($urls) as $i => $url) {
        $score = myv_stream_host_score((string)$url);
        if (isset($probeResults[$i]) && is_array($probeResults[$i])) {
            if (!empty($probeResults[$i]['ok'])) $score += 1000;
            else $score -= 1000;
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestIdx = (int)$i;
        }
    }
    return $bestIdx;
}

function myv_abs_url(string $base, string $ref): string {
    $ref = trim($ref);
    if ($ref === '') return '';
    if (preg_match('#^https?://#i', $ref)) return $ref;
    if (strpos($ref, '//') === 0) {
        $scheme = (string)(parse_url($base, PHP_URL_SCHEME) ?: 'https');
        return $scheme . ':' . $ref;
    }
    $bp = parse_url($base);
    $scheme = (string)($bp['scheme'] ?? 'https');
    $host = (string)($bp['host'] ?? '');
    if ($host === '') return $ref;
    $port = isset($bp['port']) ? (':' . (int)$bp['port']) : '';
    if (strpos($ref, '/') === 0) {
        $path = $ref;
    } else {
        $basePath = (string)($bp['path'] ?? '/');
        $dir = preg_replace('#/[^/]*$#', '/', $basePath);
        if ($dir === null || $dir === '') $dir = '/';
        $path = $dir . $ref;
    }
    $parts = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') {
            if (empty($parts)) $parts[] = '';
            continue;
        }
        if ($part === '..') {
            if (count($parts) > 1) array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }
    $norm = implode('/', $parts);
    if ($norm === '' || $norm[0] !== '/') $norm = '/' . $norm;
    return $scheme . '://' . $host . $port . $norm;
}

function myv_probe_http_get_range(string $url, int $timeout = 3, string $range = '0-65535', array $headers = []): array {
    $url = trim($url);
    if ($url === '') return ['ok'=>false, 'reason'=>'empty_url', 'body'=>''];
    if (!function_exists('curl_init')) return ['ok'=>true, 'reason'=>'curl_unavailable_skip_probe', 'body'=>''];
    $ch = curl_init($url);
    $httpHeaders = array_merge([
        'Accept: application/vnd.apple.mpegurl, application/x-mpegURL, */*',
        'Connection: close',
    ], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_NOBODY => false,
        CURLOPT_RANGE => $range,
        CURLOPT_USERAGENT => MYV_ANDROID_UA,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $eff = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $total = (int)round(((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME)) * 1000);
    curl_close($ch);
    $body = is_string($body) ? $body : '';
    $httpOk = ($code >= 200 && $code < 400);
    return [
        'ok' => ($errno === 0 && $httpOk),
        'reason' => ($errno === 0 ? ($httpOk ? 'ok' : ('http_'.$code)) : ('curl_'.$errno)),
        'http' => $code,
        'errno' => $errno,
        'err' => $err,
        'content_type' => $ctype,
        'effective_url' => $eff ?: $url,
        'effective_host' => parse_url($eff ?: $url, PHP_URL_HOST),
        'elapsed_ms' => $total,
        'body' => $body,
        'bytes' => strlen($body),
    ];
}

function myv_m3u8_first_variant_uri(string $text): string {
    $lines = preg_split('/\r?\n/', $text) ?: [];
    $expect = false;
    foreach ($lines as $line) {
        $s = trim((string)$line);
        if ($s === '') continue;
        if ($expect && $s[0] !== '#') return $s;
        $expect = (stripos($s, '#EXT-X-STREAM-INF') === 0);
    }
    return '';
}

function myv_m3u8_variant_candidates(string $text): array {
    $lines = preg_split('/\r?\n/', $text) ?: [];
    $out = [];
    $info = '';
    foreach ($lines as $line) {
        $s = trim((string)$line);
        if ($s === '') continue;
        if (stripos($s, '#EXT-X-STREAM-INF') === 0) {
            $info = $s;
            continue;
        }
        if ($info !== '' && $s[0] !== '#') {
            $height = 0;
            $bandwidth = 0;
            if (preg_match('/RESOLUTION\s*=\s*(\d+)x(\d+)/i', $info, $m)) $height = (int)$m[2];
            if (preg_match('/BANDWIDTH\s*=\s*(\d+)/i', $info, $m)) $bandwidth = (int)$m[1];
            $out[] = ['uri'=>$s, 'height'=>$height, 'bandwidth'=>$bandwidth];
            $info = '';
        }
    }
    usort($out, function($a, $b) {
        $ha = (int)($a['height'] ?? 0);
        $hb = (int)($b['height'] ?? 0);
        if ($ha !== $hb) return $hb <=> $ha;
        return ((int)($b['bandwidth'] ?? 0)) <=> ((int)($a['bandwidth'] ?? 0));
    });
    return $out;
}

function myv_m3u8_first_key_uri(string $text): string {
    $lines = preg_split('/\r?\n/', $text) ?: [];
    foreach ($lines as $line) {
        $s = trim((string)$line);
        if (stripos($s, '#EXT-X-KEY') !== 0) continue;
        if (preg_match('/METHOD\s*=\s*NONE/i', $s)) continue;
        if (preg_match('/URI\s*=\s*"([^"]+)"/i', $s, $m)) return trim($m[1]);
        if (preg_match("/URI\s*=\s*'([^']+)'/i", $s, $m)) return trim($m[1]);
        if (preg_match('/URI\s*=\s*([^,\s]+)/i', $s, $m)) return trim($m[1]);
    }
    return '';
}

function myv_m3u8_first_media_uri(string $text): string {
    $lines = preg_split('/\r?\n/', $text) ?: [];
    $expect = false;
    foreach ($lines as $line) {
        $s = trim((string)$line);
        if ($s === '') continue;
        if ($expect && $s[0] !== '#') return $s;
        if (stripos($s, '#EXTINF') === 0 || stripos($s, '#EXT-X-MAP') === 0) {
            if (preg_match('/URI\s*=\s*"([^"]+)"/i', $s, $m)) return trim($m[1]);
            $expect = true;
        }
    }
    // 如果頻道清單沒有標準的 EXTINF 標記，就改抓第一個看起來像影片片段的網址，讓格式不完整的來源也能嘗試播放。
    foreach ($lines as $line) {
        $s = trim((string)$line);
        if ($s !== '' && $s[0] !== '#') return $s;
    }
    return '';
}

function myv_select_playable_variant_for_direct(string $masterUrl, int $timeout = 3): array {
    $masterUrl = trim($masterUrl);
    if ($masterUrl === '' || !function_exists('curl_init')) {
        return ['ok'=>false, 'changed'=>false, 'url'=>$masterUrl, 'reason'=>'unavailable'];
    }
    $master = myv_probe_http_get_range($masterUrl, $timeout, '0-65535');
    $masterBody = (string)($master['body'] ?? '');
    $effectiveMaster = (string)($master['effective_url'] ?? $masterUrl);
    if (empty($master['ok']) || stripos($masterBody, '#EXTM3U') === false) {
        return ['ok'=>false, 'changed'=>false, 'url'=>$masterUrl, 'reason'=>'master_failed', 'http'=>(int)($master['http'] ?? 0)];
    }
    $candidates = myv_m3u8_variant_candidates($masterBody);
    if (empty($candidates)) {
        return ['ok'=>true, 'changed'=>false, 'url'=>$effectiveMaster, 'reason'=>'already_media_playlist'];
    }
    $failures = [];
    foreach ($candidates as $candidate) {
        $variantUrl = myv_abs_url($effectiveMaster, (string)($candidate['uri'] ?? ''));
        $variant = myv_probe_http_get_range($variantUrl, $timeout, '0-65535');
        $variantBody = (string)($variant['body'] ?? '');
        $effectiveVariant = (string)($variant['effective_url'] ?? $variantUrl);
        if (empty($variant['ok']) || stripos($variantBody, '#EXTM3U') === false) {
            $failures[] = ['height'=>(int)($candidate['height'] ?? 0), 'stage'=>'variant', 'http'=>(int)($variant['http'] ?? 0)];
            continue;
        }
        $mediaUri = myv_m3u8_first_media_uri($variantBody);
        if ($mediaUri === '') {
            $failures[] = ['height'=>(int)($candidate['height'] ?? 0), 'stage'=>'segment', 'reason'=>'no_media_segment'];
            continue;
        }
        $mediaUrl = myv_abs_url($effectiveVariant, $mediaUri);
        $segment = myv_probe_http_get_range($mediaUrl, $timeout, '0-2047', ['Accept: */*']);
        $http = (int)($segment['http'] ?? 0);
        $ctype = strtolower((string)($segment['content_type'] ?? ''));
        $bytes = (int)($segment['bytes'] ?? 0);
        $segOk = !empty($segment['ok']) && $bytes > 0 && strpos($ctype, 'text/html') === false;
        if ($segOk) {
            return [
                'ok'=>true,
                'changed'=>true,
                'url'=>$effectiveVariant,
                'height'=>(int)($candidate['height'] ?? 0),
                'bandwidth'=>(int)($candidate['bandwidth'] ?? 0),
                'segment_http'=>$http,
                'segment_host'=>parse_url($mediaUrl, PHP_URL_HOST),
            ];
        }
        $failures[] = ['height'=>(int)($candidate['height'] ?? 0), 'stage'=>'segment', 'http'=>$http, 'content_type'=>$ctype, 'bytes'=>$bytes];
    }
    return ['ok'=>false, 'changed'=>false, 'url'=>$effectiveMaster, 'reason'=>'no_playable_variant', 'failures'=>array_slice($failures, 0, 6)];
}

function myv_probe_stream_url(string $url, int $timeout = 3): array {
    $url = trim($url);
    if ($url === '') return ['ok'=>false, 'reason'=>'empty_url'];
    if (!function_exists('curl_init')) return ['ok'=>true, 'reason'=>'curl_unavailable_skip_probe'];

    $started = microtime(true);
    $manifest = myv_probe_http_get_range($url, $timeout, '0-65535');
    $body = (string)($manifest['body'] ?? '');
    $ctype = (string)($manifest['content_type'] ?? '');
    $effUrl = (string)($manifest['effective_url'] ?? $url);
    $looksHls = (stripos($body, '#EXTM3U') !== false)
        || preg_match('/mpegurl|vnd\.apple|application\/x-mpegurl/i', $ctype)
        || preg_match('/\.m3u8(\?|$)/i', $effUrl ?: $url);
    if (empty($manifest['ok']) || !$looksHls) {
        return [
            'ok'=>false,
            'reason'=>empty($manifest['ok']) ? (string)($manifest['reason'] ?? 'manifest_http_failed') : 'not_hls_manifest',
            'stage'=>'master',
            'http'=>(int)($manifest['http'] ?? 0),
            'errno'=>(int)($manifest['errno'] ?? 0),
            'err'=>(string)($manifest['err'] ?? ''),
            'content_type'=>$ctype,
            'effective_host'=>parse_url($effUrl ?: $url, PHP_URL_HOST),
            'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
        ];
    }

    $masterUrl = $effUrl ?: $url;
    $variantUrl = '';
    $variantHttp = null;
    $variantBody = $body;
    $variantCandidate = myv_m3u8_first_variant_uri($body);
    if ($variantCandidate !== '') {
        $variantUrl = myv_abs_url($masterUrl, $variantCandidate);
        $variantHttp = myv_probe_http_get_range($variantUrl, $timeout, '0-65535');
        if (empty($variantHttp['ok']) || stripos((string)($variantHttp['body'] ?? ''), '#EXTM3U') === false) {
            return [
                'ok'=>false,
                'reason'=>empty($variantHttp['ok']) ? (string)($variantHttp['reason'] ?? 'variant_http_failed') : 'variant_not_m3u8',
                'stage'=>'variant',
                'http'=>(int)($variantHttp['http'] ?? 0),
                'errno'=>(int)($variantHttp['errno'] ?? 0),
                'err'=>(string)($variantHttp['err'] ?? ''),
                'effective_host'=>parse_url((string)($variantHttp['effective_url'] ?? $variantUrl), PHP_URL_HOST),
                'master_host'=>parse_url($masterUrl, PHP_URL_HOST),
                'variant_host'=>parse_url($variantUrl, PHP_URL_HOST),
                'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
            ];
        }
        $variantBody = (string)$variantHttp['body'];
        $variantUrl = (string)($variantHttp['effective_url'] ?? $variantUrl);
    } else {
        $variantUrl = $masterUrl;
    }

    $keyUri = myv_m3u8_first_key_uri($variantBody);
    $keyOk = null;
    $keyUrl = '';
    if ($keyUri !== '') {
        $keyUrl = myv_abs_url($variantUrl, $keyUri);
        $keyResp = myv_probe_http_get_range($keyUrl, $timeout, '0-1023', ['Accept: */*']);
        $keyOk = !empty($keyResp['ok']) && ((int)($keyResp['bytes'] ?? 0) > 0 || (int)($keyResp['http'] ?? 0) === 204);
        if (!$keyOk) {
            return [
                'ok'=>false,
                'reason'=>(string)($keyResp['reason'] ?? 'key_failed'),
                'stage'=>'key',
                'http'=>(int)($keyResp['http'] ?? 0),
                'errno'=>(int)($keyResp['errno'] ?? 0),
                'err'=>(string)($keyResp['err'] ?? ''),
                'master_host'=>parse_url($masterUrl, PHP_URL_HOST),
                'variant_host'=>parse_url($variantUrl, PHP_URL_HOST),
                'key_host'=>parse_url($keyUrl, PHP_URL_HOST),
                'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
            ];
        }
    }

    $mediaUri = myv_m3u8_first_media_uri($variantBody);
    if ($mediaUri === '') {
        return [
            'ok'=>false,
            'reason'=>'no_media_segment',
            'stage'=>'segment',
            'master_host'=>parse_url($masterUrl, PHP_URL_HOST),
            'variant_host'=>parse_url($variantUrl, PHP_URL_HOST),
            'key_checked'=>($keyOk !== null),
            'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
        ];
    }
    $mediaUrl = myv_abs_url($variantUrl, $mediaUri);
    $segResp = myv_probe_http_get_range($mediaUrl, $timeout, '0-2047', ['Accept: */*']);
    $segOk = !empty($segResp['ok']) && ((int)($segResp['bytes'] ?? 0) > 0 || in_array((int)($segResp['http'] ?? 0), [204, 206], true));
    if (!$segOk) {
        return [
            'ok'=>false,
            'reason'=>(string)($segResp['reason'] ?? 'segment_failed'),
            'stage'=>'segment',
            'http'=>(int)($segResp['http'] ?? 0),
            'errno'=>(int)($segResp['errno'] ?? 0),
            'err'=>(string)($segResp['err'] ?? ''),
            'master_host'=>parse_url($masterUrl, PHP_URL_HOST),
            'variant_host'=>parse_url($variantUrl, PHP_URL_HOST),
            'segment_host'=>parse_url($mediaUrl, PHP_URL_HOST),
            'key_checked'=>($keyOk !== null),
            'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
        ];
    }

    return [
        'ok'=>true,
        'reason'=>'hls_chain_ok',
        'stage'=>'segment',
        'http'=>(int)($segResp['http'] ?? 0),
        'master_host'=>parse_url($masterUrl, PHP_URL_HOST),
        'variant_host'=>parse_url($variantUrl, PHP_URL_HOST),
        'segment_host'=>parse_url($mediaUrl, PHP_URL_HOST),
        'has_variant'=>($variantCandidate !== ''),
        'key_checked'=>($keyOk !== null),
        'elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
    ];
}

function myv_probe_stream_candidates(array $urls, int $timeout = 3): array {
    $urls = array_values($urls);
    if (empty($urls)) return [];
    $order = array_keys($urls);
    usort($order, function($a, $b) use ($urls) {
        return myv_stream_host_score((string)$urls[$b]) <=> myv_stream_host_score((string)$urls[$a]);
    });


    $results = [];
    foreach ($order as $i) {
        $url = (string)$urls[$i];
        $results[$i] = myv_probe_stream_url($url, $timeout);
        myv_log('stream_probe_result', [
            'idx'=>$i,
            'host'=>parse_url($url, PHP_URL_HOST),
            'score'=>myv_stream_host_score($url),
            'deep'=>true,
            'result'=>$results[$i],
        ]);
        if (!empty($results[$i]['ok'])) break;
    }
    ksort($results);
    return $results;
}

function myv_stream_cache_repair_selection(array $cache, string $cacheFile = ''): array {
    $urls = is_array($cache['final_urls'] ?? null) ? array_values($cache['final_urls']) : [];
    if (empty($urls)) return $cache;
    $oldBest = isset($cache['best_index']) ? (int)$cache['best_index'] : null;
    $oldSchema = (int)($cache['cache_schema'] ?? 0);
    $probeResults = [];
    if ($oldSchema >= MYV_STREAM_CACHE_SCHEMA && is_array($cache['probe_results'] ?? null)) {
        $probeResults = (array)$cache['probe_results'];
    }
    $scores = array_map('myv_stream_host_score', $urls);
    $hasProbe = !empty($probeResults);
    $best = myv_select_best_stream_index($urls, $probeResults);
    $cache['cache_schema'] = MYV_STREAM_CACHE_SCHEMA;
    $cache['selection_algo'] = $hasProbe ? 'hls_chain_probe_v4' : 'host_score_only_v1';
    $cache['scores'] = $scores;
    $cache['best_index'] = $best;
    $cache['best_host'] = isset($urls[$best]) ? parse_url((string)$urls[$best], PHP_URL_HOST) : '';
    if ($oldSchema !== MYV_STREAM_CACHE_SCHEMA || $oldBest !== $best) {
        myv_log('stream_cache_best_index_repaired', [
            'file'=>($cacheFile !== '' ? basename($cacheFile) : ''),
            'cid'=>$cache['channel_id'] ?? null,
            'asset'=>$cache['asset_id'] ?? '',
            'old_schema'=>$oldSchema,
            'old_best'=>$oldBest,
            'new_best'=>$best,
            'scores'=>$scores,
            'best_host'=>$cache['best_host'],
        ], true);
        if ($cacheFile !== '') @myv_write_json($cacheFile, $cache, 0600);
    }
    return $cache;
}

function myv_update_stream_cache_probe(int $cid, string $assetId, array $all, array $probe): array {
    $urls = is_array($all['final_urls'] ?? null) ? array_values($all['final_urls']) : [];
    $best = myv_select_best_stream_index($urls, $probe);
    $all['cache_schema'] = MYV_STREAM_CACHE_SCHEMA;
    $all['selection_algo'] = !empty($probe) ? 'hls_chain_probe_v4' : 'host_score_only_v1';
    $all['probe_depth'] = !empty($probe) ? 'master_variant_segment_key' : '';
    $all['probe_results'] = $probe;
    $all['scores'] = array_map('myv_stream_host_score', $urls);
    $all['best_index'] = $best;
    $all['best_host'] = isset($urls[$best]) ? parse_url((string)$urls[$best], PHP_URL_HOST) : '';
    $all['probe_checked_at'] = myv_now();
    @myv_write_json(myv_stream_cache_all_file($cid, $assetId), $all, 0600);
    return $all;
}

function myv_any_probe_ok(array $probe): bool {
    foreach ($probe as $r) {
        if (is_array($r) && !empty($r['ok'])) return true;
    }
    return false;
}

function myv_probe_result_ok($result): bool {
    return is_array($result) && !empty($result['ok']);
}

function myv_best_stream_index_from_probe(array $urls, array $probeResults): int {
    $idx = myv_select_best_stream_index($urls, $probeResults);
    if (isset($urls[$idx])) return (int)$idx;
    foreach (array_keys($urls) as $i) return (int)$i;
    return 0;
}

function myv_request_is_third_party_player(): bool {
    foreach (['external', 'thirdparty', 'third_party'] as $key) {
        $flag = myv_request_bool_param($key);
        if ($flag === true) return true;
    }
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') return true;
    if (preg_match('/lavf|ffmpeg|vlc|libvlc|kodi|tivimate|ott|iptv|exoplayer|okhttp|stagefright|gstreamer/i', $ua)) return true;
    return preg_match('/mozilla|chrome|safari|firefox|edge|edg|applewebkit/i', $ua) !== 1;
}

function myv_probe_result_allows_direct($probe): bool {
    return is_array($probe) && myv_probe_result_ok($probe);
}

function myv_preferred_direct_index_for_request(array $urls, array $probeResults, int $defaultIdx): int {
    if (!myv_request_is_third_party_player()) {
        foreach ($urls as $i => $url) {
            $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
            if ($host !== '' && strpos($host, 'myvideo.tv') !== false && myv_probe_result_allows_direct($probeResults[$i] ?? null)) {
                return (int)$i;
            }
        }
        return $defaultIdx;
    }
    foreach ($urls as $i => $url) {
        $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
        if ($host !== '' && strpos($host, 'hinet.net') !== false && myv_probe_result_allows_direct($probeResults[$i] ?? null)) {
            return (int)$i;
        }
    }
    foreach ($urls as $i => $url) {
        $host = strtolower((string)parse_url((string)$url, PHP_URL_HOST));
        if ($host !== '' && strpos($host, 'myvideo.tv') === false && myv_probe_result_allows_direct($probeResults[$i] ?? null)) {
            return (int)$i;
        }
    }
    return $defaultIdx;
}


function myv_get_channel_stream_urls(int $channel_id, bool $force_refresh = false, string $asset_id_hint = ''): array {
    $cacheAll = myv_stream_cache_all_file($channel_id, $asset_id_hint);
    if (!$force_refresh && is_file($cacheAll) && (myv_now() - filemtime($cacheAll)) < myv_stream_cache_ttl_seconds()) {
        $c = myv_read_json($cacheAll, null);
        if (is_array($c) && isset($c['final_urls']) && is_array($c['final_urls'])) {
            $c = myv_stream_cache_repair_selection($c, $cacheAll);
            if ((int)($c['cache_schema'] ?? 0) === MYV_STREAM_CACHE_SCHEMA
                && (string)($c['probe_depth'] ?? '') === 'master_variant_segment_key'
                && myv_any_probe_ok((array)($c['probe_results'] ?? []))) return $c;
        }
    }

    $channels = myv_list_load();
    if (!$channels) $channels = myv_sync(true);
    $channel = myv_select_channel($channels, $channel_id, $asset_id_hint);
    if (!$channel) return ['ok'=>false, 'reason'=>'channel_not_found', 'raw_urls'=>[], 'final_urls'=>[]];

    $asset_id = (string)($channel['fsMyVideo_ID'] ?? $asset_id_hint);
    [$http, $payload, $meta] = myv_http_get_json(
        '/twmsgw.api/FindTVChannelUrl.json',
        array_merge(myv_api_common_params(), ['tvChannelId'=>$asset_id])
    );
    $status = is_array($payload['status'] ?? null) ? $payload['status'] : [];
    $code = (string)($status['code'] ?? '');
    $message = trim((string)($status['message'] ?? $status['description'] ?? $meta['error'] ?? ''));
    $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $url = trim((string)($data['tvChannelUrl'] ?? ''));
    $hasRight = strtoupper(trim((string)($data['hasPlayRight'] ?? ''))) === 'Y';
    if ($http < 200 || $http >= 300 || $code !== '0' || !$hasRight || !preg_match('#^https?://#i', $url)) {
        $temporary = $code === '1031';
        $reason = $temporary ? 'event_not_started' : ($code !== '' && $code !== '0' ? 'api_status_' . $code : (!$hasRight ? 'no_play_right' : 'no_stream_url'));
        myv_log('stream_failed', [
            'cid'=>$channel_id, 'asset'=>$asset_id, 'mode'=>'anonymous',
            'http'=>$http, 'api_code'=>$code, 'reason'=>$reason,
            'temporary'=>$temporary, 'message'=>$message,
        ], true);
        return [
            'ok'=>false, 'anonymous'=>true, 'temporary'=>$temporary,
            'reason'=>$reason, 'http'=>$http, 'status'=>$code,
            'message'=>$message, 'raw_urls'=>[], 'final_urls'=>[],
        ];
    }

    $finalUrls = [$url];
    $probeResults = myv_probe_stream_candidates($finalUrls, 4);
    $bestIndex = 0;
    $out = [
        'ok'=>true,
        'anonymous'=>true,
        'source_api'=>'FindTVChannelUrl',
        'cache_schema'=>MYV_STREAM_CACHE_SCHEMA,
        'selection_algo'=>'hls_chain_probe_v1',
        'probe_depth'=>'master_variant_segment_key',
        'probe_results'=>$probeResults,
        'probe_checked_at'=>myv_now(),
        'cached_at'=>myv_now(),
        'channel_id'=>$channel_id,
        'asset_id'=>$asset_id,
        'channel'=>$channel,
        'raw_urls'=>$finalUrls,
        'final_urls'=>$finalUrls,
        'scores'=>[0],
        'best_index'=>$bestIndex,
        'best_host'=>(string)(parse_url($url, PHP_URL_HOST) ?: ''),
        'http'=>$http,
        'status'=>$code,
        'play_right_type'=>(string)($data['playRightType'] ?? ''),
        'channel_type'=>(int)($data['tvChannelType'] ?? 0),
    ];
    if (myv_any_probe_ok($probeResults) && !myv_write_json($cacheAll, $out)) {
        myv_log('stream_cache_all_write_failed', ['cid'=>$channel_id, 'asset'=>$asset_id], true);
    }
    myv_log('stream_urls_updated', [
        'cid'=>$channel_id,
        'asset'=>$asset_id,
        'count'=>1,
        'hosts'=>[$out['best_host']],
        'best_index'=>$bestIndex,
        'best_host'=>$out['best_host'],
        'probe_ok'=>myv_any_probe_ok($probeResults),
        'source_api'=>$out['source_api'],
        'selection_algo'=>$out['selection_algo'],
    ]);
    return $out;
}

function myv_get_channel_stream(int $channel_id, bool $force_refresh = false, ?int $idx = null, string $asset_id_hint = ''): ?string {
    $explicitIdx = ($idx !== null) || isset($_GET['i']);
    if ($idx === null && isset($_GET['i'])) $idx = max(0, (int)$_GET['i']);
    $all = myv_get_channel_stream_urls($channel_id, $force_refresh, $asset_id_hint);
    $urls = is_array($all['final_urls'] ?? null) ? array_values($all['final_urls']) : [];
    if (empty($urls)) return null;

    $all = myv_stream_cache_repair_selection($all);
    $probeResults = is_array($all['probe_results'] ?? null) ? (array)$all['probe_results'] : [];
    $bestIdx = isset($all['best_index']) ? max(0, (int)$all['best_index']) : myv_best_stream_index_from_probe($urls, $probeResults);
    if (!isset($urls[$bestIdx])) $bestIdx = myv_best_stream_index_from_probe($urls, $probeResults);

    if ($idx === null) {
        if (myv_request_is_third_party_player()) {
            $probeChanged = false;
            foreach ($urls as $candidateIdx => $candidateUrl) {
                $candidateHost = strtolower((string)parse_url((string)$candidateUrl, PHP_URL_HOST));
                if ($candidateHost === '' || strpos($candidateHost, 'hinet.net') === false) continue;
                if (!array_key_exists($candidateIdx, $probeResults) || !is_array($probeResults[$candidateIdx])) {
                    $probeResults[$candidateIdx] = myv_probe_stream_url((string)$candidateUrl, 3);
                    $probeChanged = true;
                    myv_log('stream_third_party_hinet_probe_result', [
                        'cid'=>$channel_id,
                        'idx'=>(int)$candidateIdx,
                        'host'=>parse_url((string)$candidateUrl, PHP_URL_HOST),
                        'result'=>$probeResults[$candidateIdx],
                    ]);
                }
                if (myv_probe_result_ok($probeResults[$candidateIdx] ?? null)) break;
            }
            if ($probeChanged) {
                $all = myv_update_stream_cache_probe($channel_id, (string)($all['asset_id'] ?? $asset_id_hint), $all, $probeResults);
                $probeResults = is_array($all['probe_results'] ?? null) ? (array)$all['probe_results'] : $probeResults;
                $bestIdx = isset($all['best_index']) ? max(0, (int)$all['best_index']) : myv_best_stream_index_from_probe($urls, $probeResults);
                if (!isset($urls[$bestIdx])) $bestIdx = myv_best_stream_index_from_probe($urls, $probeResults);
            }
        }
        $idx = myv_preferred_direct_index_for_request($urls, $probeResults, $bestIdx);
        if ($idx !== $bestIdx) {
            myv_log('stream_third_party_direct_preferred', [
                'cid'=>$channel_id,
                'selected_idx'=>$idx,
                'best_index'=>$bestIdx,
                'selected_host'=>parse_url((string)$urls[$idx], PHP_URL_HOST),
                'best_host'=>isset($urls[$bestIdx]) ? parse_url((string)$urls[$bestIdx], PHP_URL_HOST) : '',
                'ua'=>substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
            ]);
        }
    } else {
        $requestedIdx = max(0, (int)$idx);
        $idx = $requestedIdx;
        $explicitOk = false;
        $explicitReason = '';

        if (!isset($urls[$requestedIdx])) {
            $explicitReason = 'explicit_index_out_of_range';
        } else {
            $hasProbeForRequested = array_key_exists($requestedIdx, $probeResults) && is_array($probeResults[$requestedIdx]);

            if (!$hasProbeForRequested) {
                $probeResults[$requestedIdx] = myv_probe_stream_url((string)$urls[$requestedIdx], 3);
                myv_log('stream_explicit_probe_result', [
                    'cid'=>$channel_id,
                    'requested_idx'=>$requestedIdx,
                    'host'=>parse_url((string)$urls[$requestedIdx], PHP_URL_HOST),
                    'result'=>$probeResults[$requestedIdx],
                ]);
                $all = myv_update_stream_cache_probe($channel_id, (string)($all['asset_id'] ?? $asset_id_hint), $all, $probeResults);
                $probeResults = is_array($all['probe_results'] ?? null) ? (array)$all['probe_results'] : $probeResults;
                $bestIdx = isset($all['best_index']) ? max(0, (int)$all['best_index']) : myv_best_stream_index_from_probe($urls, $probeResults);
            }

            if (myv_probe_result_ok($probeResults[$requestedIdx] ?? null)) {
                $explicitOk = true;
            } else {
                $r = $probeResults[$requestedIdx] ?? null;
                $explicitReason = is_array($r) ? (string)($r['reason'] ?? 'explicit_index_probe_failed') : 'explicit_index_unverified';
            }
        }

        if (!$explicitOk) {
            $fallbackIdx = myv_preferred_direct_index_for_request($urls, $probeResults, myv_best_stream_index_from_probe($urls, $probeResults));
            if (!isset($urls[$fallbackIdx])) $fallbackIdx = isset($urls[$bestIdx]) ? $bestIdx : 0;
            if (!isset($urls[$fallbackIdx])) return null;
            myv_log('stream_explicit_index_fallback', [
                'cid'=>$channel_id,
                'requested_idx'=>$requestedIdx,
                'selected_idx'=>$fallbackIdx,
                'reason'=>$explicitReason,
                'requested_host'=>isset($urls[$requestedIdx]) ? parse_url((string)$urls[$requestedIdx], PHP_URL_HOST) : '',
                'selected_host'=>parse_url((string)$urls[$fallbackIdx], PHP_URL_HOST),
                'best_index'=>$all['best_index'] ?? null,
                'scores'=>$all['scores'] ?? null,
            ], true);
            $idx = $fallbackIdx;
        }
    }

    if (!isset($urls[$idx])) $idx = isset($urls[$bestIdx]) ? $bestIdx : 0;
    if (!isset($urls[$idx])) return null;
    $url = (string)$urls[$idx];
    $cache = myv_stream_cache_file($channel_id, $idx);
    if (!myv_write_json($cache, $all + ['url'=>$url, 'selected_index'=>$idx, 'requested_index'=>($explicitIdx ? ($requestedIdx ?? $idx) : null), 'explicit_index'=>$explicitIdx])) {
        myv_log('stream_cache_write_failed', ['cid'=>$channel_id, 'idx'=>$idx], true);
    }
    $selectedHost = (string)parse_url($url, PHP_URL_HOST);
    myv_log($explicitIdx ? 'stream_selected' : 'stream_auto_selected', [
        'cid'=>$channel_id,
        'idx'=>$idx,
        'requested_idx'=>($explicitIdx ? ($requestedIdx ?? $idx) : null),
        'count'=>count($urls),
        'host'=>$selectedHost,
        'best_index'=>$all['best_index'] ?? null,
        'scores'=>$all['scores'] ?? null,
        'cache_layout'=>'channel_classified',
    ]);
    return $url;
}


function myv_channel_group_default(array $c): string {
    $group = trim((string)($c['fsGROUP_NAME'] ?? ''));
    return $group !== '' ? $group : '未分類';
}

function myv_channel_group(array $c, array $settings): string {
    $id = (string)($c['fnID'] ?? '');
    $map = (array)($settings['channel_group_map'] ?? []);
    if ($id !== '' && isset($map[$id]) && is_string($map[$id]) && $map[$id] !== '') return $map[$id];
    return myv_channel_group_default($c);
}

function myv_is_free_channel_value($value): bool {
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return (int)$value === 1;
    $text = strtoupper(trim((string)$value));
    return in_array($text, ['Y', 'YES', 'TRUE', '1'], true);
}

function myv_effective_channels(array $channels, array $settings): array {
    $excludeIds = array_flip(array_map('strval', (array)($settings['selection_rules']['exclude_channel_ids'] ?? [])));
    $includeGroups = array_filter((array)($settings['selection_rules']['include_groups'] ?? []), 'is_string');
    $out = [];
    foreach ($channels as $c) {
        $id = (string)($c['fnID'] ?? '');
        if ($id === '') continue;
        if (isset($excludeIds[$id])) continue;

        $g = myv_channel_group($c, $settings);
        if (!empty($includeGroups) && !in_array($g, $includeGroups, true)) continue;
        $c['_group'] = $g;
        $out[] = $c;
    }
    return $out;
}

function myv_grouped_channels(array $channels, array $settings): array {
    $eff = myv_effective_channels($channels, $settings);
    $officialOrder = [];
    foreach ($channels as $channel) {
        if (!is_array($channel)) continue;
        $group = myv_channel_group_default($channel);
        if ($group !== '' && !in_array($group, $officialOrder, true)) $officialOrder[] = $group;
    }
    $groupOrder = array_values(array_unique(array_filter(array_merge((array)($settings['group_order'] ?? []), $officialOrder), 'is_string')));
    $byGroup = [];
    foreach ($eff as $c) {
        $g = (string)($c['_group'] ?? '未分類');
        if ($g === '') $g = '未分類';
        $byGroup[$g][] = $c;
    }


    $sortMode = (string)($settings['channel_sort_mode'] ?? 'id_asc');
    $chOrder = (array)($settings['channel_order'] ?? []);
    foreach ($byGroup as $g => &$list) {
        if ($sortMode === 'manual') {
            $order = (array)($chOrder[$g] ?? []);
            $orderMap = array_flip(array_map('strval', $order));
            usort($list, function($a, $b) use ($orderMap) {
                $ia = $orderMap[(string)($a['fnID'] ?? '')] ?? PHP_INT_MAX;
                $ib = $orderMap[(string)($b['fnID'] ?? '')] ?? PHP_INT_MAX;
                if ($ia !== $ib) return $ia <=> $ib;

                return (int)($a['fnID'] ?? 0) <=> (int)($b['fnID'] ?? 0);
            });
        } elseif ($sortMode === 'name_asc' || $sortMode === 'name_then_id') {
            usort($list, function($a, $b) {
                $nameCmp = strcmp((string)($a['fsNAME'] ?? ''), (string)($b['fsNAME'] ?? ''));
                if ($nameCmp !== 0) return $nameCmp;
                return (int)($a['fnID'] ?? 0) <=> (int)($b['fnID'] ?? 0);
            });
        } else {
            usort($list, function($a, $b) {
                $ida = (int)($a['fnID'] ?? 0);
                $idb = (int)($b['fnID'] ?? 0);
                if ($ida !== $idb) return $ida <=> $idb;
                return strcmp((string)($a['fsNAME'] ?? ''), (string)($b['fsNAME'] ?? ''));
            });
        }
    }
    unset($list);

    $out = [];
    foreach ($groupOrder as $g) {
        if (isset($byGroup[$g])) {
            $out[$g] = $byGroup[$g];
            unset($byGroup[$g]);
        }
    }
    foreach ($byGroup as $g => $list) {
        $out[$g] = $list;
    }
    return $out;
}

function myv_count_grouped_channels(array $grouped): int {
    $n = 0;
    foreach ($grouped as $list) {
        if (is_array($list)) $n += count($list);
    }
    return $n;
}

function myv_export_rows(): array {


    $channels = myv_list_load();
    if (empty($channels)) {

        $channels = myv_sync(true);
    }
    $settings = myv_settings_load();
    $effective = myv_effective_channels($channels, $settings);
    $grouped = myv_grouped_channels($channels, $settings);
    $rows = [];
    foreach ($grouped as $g => $list) {
        foreach ($list as $c) {
            $id = (string)($c['fnID'] ?? '');
            if ($id === '') continue;
            $name = (string)($c['fsNAME'] ?? '');
            $rows[] = [
                'id'      => $id,
                'name'    => $name,
                'group'   => (string)$g,
                'logo'    => myv_channel_logo_original($c),
                'url'     => myv_export_channel_url($id, (string)($c['fsMyVideo_ID'] ?? '')),
                'tvgId'   => $id,
                'tvgName' => $name,
            ];
        }
    }
    $groupsFound = [];
    foreach ($channels as $c) {
        if (is_array($c)) $groupsFound[] = myv_channel_group($c, $settings);
    }
    $debug = [
        'channels_total'=>count($channels),
        'access_mode'=>'anonymous',
        'effective_total'=>count($effective),
        'grouped_total'=>myv_count_grouped_channels($grouped),
        'rows_total'=>count($rows),
        'include_groups'=>(array)($settings['selection_rules']['include_groups'] ?? []),
        'exclude_count'=>count((array)($settings['selection_rules']['exclude_channel_ids'] ?? [])),
        'groups_found'=>array_values(array_unique(array_filter($groupsFound, 'strlen'))),
        'groups_exported'=>array_keys($grouped),
    ];
    myv_log('myv_export_rows_debug', $debug, true);
    if (empty($rows)) {
        myv_log('myv_export_rows_empty', $debug, true);
    }
    return $rows;
}


function myv_status(): array {
    return [
        'access_mode'      => 'anonymous',
        'anonymous'        => true,
        'export_all_channels' => true,
        'settings'         => myv_settings_load(),
        'channels_cached'  => is_file(myv_paths()['list']),
        'last_refresh'     => myv_last_refresh_info(),
        'refresh_job'      => myv_refresh_job_load(),
    ];
}


function myv_entry_base_url(): string {
    if (function_exists('myv_app_base_url')) {
        return myv_app_base_url();
    }
    if (function_exists('iptv_base_url_dir')) {
        return iptv_base_url_dir();
    }
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '.') $dir = '';
    $dir = rtrim($dir, '/');
    return $scheme . '://' . $host . ($dir === '' ? '/' : ($dir . '/'));
}

function myv_export_channel_resolver_url(string $id, string $asset_id = ''): string {

    $base = myv_entry_base_url() . '?action=myvideo&id=' . rawurlencode($id);
    if ($asset_id !== '') $base .= '&asset=' . rawurlencode($asset_id);
    return $base;
}

function myv_export_channel_url(string $id, string $asset_id = ''): string {
    return myv_export_channel_resolver_url($id, $asset_id);
}

function myv_proxy_url_for_stream(string $streamUrl): string {
    $encoded = rtrim(strtr(base64_encode($streamUrl), '+/', '-_'), '=');
    return myv_entry_base_url() . '?action=proxy&u=' . rawurlencode($encoded);
}

function myv_request_bool_param(string $key): ?bool {
    if (!array_key_exists($key, $_GET)) return null;
    $v = strtolower(trim((string)$_GET[$key]));
    if (in_array($v, ['', '1', 'true', 'yes', 'on'], true)) return true;
    if (in_array($v, ['0', 'false', 'no', 'off'], true)) return false;
    return null;
}

function myv_route_should_proxy(): bool {
    $proxy = myv_request_bool_param('proxy');
    if ($proxy !== null) return $proxy;
    return (string)(myv_app_config()['deviceMode'] ?? 'proxy') === 'proxy';
}

function myv_refresh_job_file(): string {
    return myv_paths()['jobs_dir'] . DIRECTORY_SEPARATOR . 'refresh.json';
}

function myv_refresh_job_load(): array {
    $j = myv_read_json(myv_refresh_job_file(), []);
    return is_array($j) ? $j : [];
}

function myv_refresh_job_save(array $job): bool {
    $job['updated_ts'] = myv_now();
    return myv_write_json(myv_refresh_job_file(), $job, 0600);
}

function myv_refresh_job_clear(): void {
    @unlink(myv_refresh_job_file());
}

function myv_refresh_job_cleanup_stale(int $maxAgeSeconds = 1800): void {
    $p = myv_paths();
    $now = myv_now();
    foreach ((array)glob($p['jobs_dir'] . DIRECTORY_SEPARATOR . '*.json') as $file) {
        $j = myv_read_json($file, []);
        $ts = (int)($j['updated_ts'] ?? $j['started_ts'] ?? 0);
        if ($ts > 0 && ($now - $ts) > $maxAgeSeconds && (($j['state'] ?? '') === 'running')) {


            $startedTs = (int)($j['started_ts'] ?? $ts);
            $j['state'] = 'stale';
            $j['error'] = 'STALE_JOB_TIMEOUT';
            $j['updated_ts'] = $now;
            $j['finished_ts'] = $now;
            $j['elapsed_ms'] = max(0, ($now - $startedTs) * 1000);
            $j['stale_marked_at'] = date('c', $now);
            myv_write_json($file, $j, 0600);
            myv_log('refresh_job_marked_stale', [
                'file' => basename($file),
                'age_seconds' => $now - $ts,
                'started_ts' => $startedTs,
            ], true);
        }
    }
}

function myv_refresh_start_job(bool $force = true): array {


    myv_refresh_job_cleanup_stale();
    @set_time_limit(120);
    $channels = myv_get_all_channels(true);
    $settings = myv_settings_load();
    $eff = myv_effective_channels($channels, $settings);
    $total = 0;
    foreach ($eff as $c) { if ((int)($c['fnID'] ?? 0) > 0) $total++; }

    $now = myv_now();
    $job = [
        'state'=>'done',
        'started_ts'=>$now,
        'updated_ts'=>$now,
        'finished_ts'=>$now,
        'elapsed_ms'=>0,
        'access_mode'=>'anonymous',
        'items'=>[],
        'total'=>$total,
        'index'=>$total,
        'success'=>$total,
        'failed'=>0,
        'failed_ids'=>[],
        'list_refreshed'=>true,
        'stream_links_refreshed'=>false,
    ];
    if (!myv_refresh_job_save($job)) throw new \RuntimeException('WRITE_FAILED: refresh job 無法寫入，請檢查 data_myvideo/jobs 目錄權限');

    $info = [
        'at'=>$now, 'mode'=>'full_job',
        'access_mode'=>'anonymous',
        'count'=>$total, 'success'=>$total, 'failed'=>0, 'failed_ids'=>[],
        'list_refreshed'=>true, 'stream_links_refreshed'=>false, 'elapsed_ms'=>0,
    ];
    myv_write_json(myv_last_refresh_file(), $info, 0600);
    myv_log('refresh_job_done', ['total'=>$total, 'access_mode'=>'anonymous'], true);
    return $job;
}

function myv_refresh_step_job(int $batch = 5): array {

    myv_refresh_job_cleanup_stale();
    $job = myv_refresh_job_load();
    if (!$job || !is_array($job)) return ['ok'=>false, 'error'=>'NO_JOB'];
    return ['ok'=>true, 'job'=>$job, 'done'=>(($job['state'] ?? '') === 'done')];
}


function myv_last_refresh_file(): string {
    return myv_paths()['last_refresh_dir'] . DIRECTORY_SEPARATOR . 'refresh.json';
}

function myv_last_refresh_info(): array {
    $f = myv_last_refresh_file();
    if (!is_file($f)) return ['at'=>0, 'count'=>0, 'success'=>0, 'failed'=>0];
    $j = myv_read_json($f, []);
    return is_array($j) ? $j : ['at'=>0, 'count'=>0, 'success'=>0, 'failed'=>0];
}

function myv_run_auto_refresh(array $opts = []): array {
    $t0 = microtime(true);
    @set_time_limit(600);
    $force = array_key_exists('force', $opts) ? !empty($opts['force']) : true;
    $requestedMode = strtolower(trim((string)($opts['mode'] ?? 'full')));
    myv_log('auto_refresh_start', ['requested_mode'=>$requestedMode, 'mode'=>'full'], true);


    $channels = myv_get_all_channels(true);
    $settings = myv_settings_load();
    $eff = myv_effective_channels($channels, $settings);


    $success = 0; $failed = 0; $failedIds = [];
    $info = [
        'at' => myv_now(),
        'mode' => 'full',
        'requested_mode' => $requestedMode,
        'access_mode' => 'anonymous',
        'count' => count($eff),
        'success' => count($eff),
        'failed' => 0,
        'failed_ids' => [],
        'list_refreshed' => true,
        'stream_links_refreshed' => false,
        'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
    ];
    if (!myv_write_json(myv_last_refresh_file(), $info)) {
        myv_log('last_refresh_write_failed', ['file'=>myv_last_refresh_file()], true);
    }
    myv_log('auto_refresh_done', $info, true);
    return $info;
}


function myv_integrator_interval_hours(): float {
    return function_exists('iptv_integrator_myvideo_list_refresh_hours') ? iptv_integrator_myvideo_list_refresh_hours() : 4.0;
}

function myv_auto_refresh_if_needed(array $opts = []): array {
    $trigger = (string)($opts['trigger'] ?? 'unknown');
    $mode = strtolower(trim((string)($opts['mode'] ?? 'full')));
    if (!in_array($mode, ['light', 'full'], true)) $mode = 'full';

    if ($mode === 'light') $mode = 'full';
    $force = !empty($opts['force']);
    $p = myv_paths();

    try {

        $hours = myv_integrator_interval_hours();
        if ($hours < 0) $hours = 0;
        if ($hours > 72) $hours = 72;
        if (!$force && $hours <= 0) {
            return ['ok'=>true, 'skipped'=>true, 'reason'=>'DISABLED', 'trigger'=>$trigger, 'data_dir'=>$p['dir']];
        }

        $ttl = max(0, $hours * 3600);
        $now = myv_now();
        $last = myv_last_refresh_info();
        $lastAt = (int)($last['at'] ?? 0);
        $age = $lastAt > 0 ? ($now - $lastAt) : null;

        $listMissing = (!is_file($p['list']) || filesize($p['list']) <= 2);
        if (!$force && !$listMissing && $ttl > 0 && $lastAt > 0 && ($now - $lastAt) < $ttl) {
            return [
                'ok'=>true,
                'skipped'=>true,
                'reason'=>'TTL_SKIP',
                'trigger'=>$trigger,
                'ttl'=>$ttl,
                'age'=>$now - $lastAt,
                'last_refresh_at'=>$lastAt,
                'data_dir'=>$p['dir'],
            ];
        }

        $fp = @fopen($p['lock'], 'c+');
        if (!$fp) {
            myv_log('auto_refresh_lock_open_failed', ['trigger'=>$trigger, 'lock'=>$p['lock']], true);
            return ['ok'=>false, 'reason'=>'LOCK_OPEN_FAILED', 'trigger'=>$trigger, 'data_dir'=>$p['dir']];
        }

        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return ['ok'=>true, 'skipped'=>true, 'reason'=>'LOCK_BUSY', 'trigger'=>$trigger, 'data_dir'=>$p['dir']];
        }

        try {

            if (!$force) {
                $last2 = myv_last_refresh_info();
                $lastAt2 = (int)($last2['at'] ?? 0);
                $listMissing2 = (!is_file($p['list']) || filesize($p['list']) <= 2);
                if (!$listMissing2 && $ttl > 0 && $lastAt2 > 0 && ($now - $lastAt2) < $ttl) {
                    return [
                        'ok'=>true,
                        'skipped'=>true,
                        'reason'=>'TTL_SKIP_AFTER_LOCK',
                        'trigger'=>$trigger,
                        'ttl'=>$ttl,
                        'age'=>$now - $lastAt2,
                        'last_refresh_at'=>$lastAt2,
                        'data_dir'=>$p['dir'],
                    ];
                }
            }

            @set_time_limit(600);
            $info = myv_run_auto_refresh(['mode'=>'full', 'force'=>true]);
            $info['ok'] = true;
            $info['trigger'] = $trigger;
            $info['mode'] = $mode;
            $info['auto_trigger'] = true;
            $info['ttl'] = $ttl;
            $info['age_before_refresh'] = $age;
            $info['data_dir'] = $p['dir'];
            if (!myv_write_json(myv_last_refresh_file(), $info)) {
                myv_log('last_refresh_write_failed', ['trigger'=>$trigger, 'file'=>myv_last_refresh_file()], true);
            }
            myv_log('auto_refresh_if_needed_done', $info, true);
            return $info;
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);

        }
    } catch (\Throwable $e) {
        myv_log('auto_refresh_if_needed_error', ['trigger'=>$trigger, 'error'=>$e->getMessage(), 'data_dir'=>$p['dir']], true);
        return ['ok'=>false, 'reason'=>'EXCEPTION', 'trigger'=>$trigger, 'error'=>$e->getMessage(), 'data_dir'=>$p['dir']];
    }
}


function myv_handle_index_route(): void {


    $id = (int)($_GET['id'] ?? $_GET['ch'] ?? 0);
    $idx = isset($_GET['i']) ? max(0, (int)$_GET['i']) : null;
    $asset = trim((string)($_GET['asset'] ?? ''));
    if ($id <= 0) { http_response_code(400); echo 'missing id'; exit; }
    $proxyMode = myv_route_should_proxy();
    myv_app_log('play_requested', [
        'id'=>$id,
        'idx'=>$idx,
        'asset'=>$asset,
        'refresh'=>isset($_GET['refresh']),
        'delivery'=>$proxyMode ? 'proxy_redirect' : 'direct_redirect',
        'deviceMode'=>(string)(myv_app_config()['deviceMode'] ?? 'proxy'),
    ]);
    $url = myv_get_channel_stream($id, isset($_GET['refresh']), $idx, $asset);
    if (!$url) {
        myv_app_log('play_failed', ['id'=>$id, 'idx'=>$idx, 'asset'=>$asset, 'reason'=>'stream_not_found']);
        http_response_code(404); echo 'channel not found / not playable'; exit;
    }
    if (myv_request_is_third_party_player()) {
        $variant = myv_select_playable_variant_for_direct($url, 3);
        if (!empty($variant['changed']) && !empty($variant['url'])) {
            myv_log('route_variant_302', [
                'id'=>$id,
                'idx'=>$idx,
                'asset'=>$asset,
                'master_host'=>parse_url($url, PHP_URL_HOST),
                'variant_host'=>parse_url((string)$variant['url'], PHP_URL_HOST),
                'height'=>$variant['height'] ?? 0,
                'bandwidth'=>$variant['bandwidth'] ?? 0,
                'segment_http'=>$variant['segment_http'] ?? 0,
                'segment_host'=>$variant['segment_host'] ?? '',
                'role'=>function_exists('iptv_role') ? iptv_role() : '',
            ]);
            $url = (string)$variant['url'];
        } elseif (!empty($variant['reason']) && (string)$variant['reason'] !== 'already_media_playlist') {
            myv_log('route_variant_probe_failed', [
                'id'=>$id,
                'idx'=>$idx,
                'asset'=>$asset,
                'host'=>parse_url($url, PHP_URL_HOST),
                'reason'=>$variant['reason'] ?? '',
                'failures'=>$variant['failures'] ?? null,
            ], true);
        }
    }
    if ($proxyMode) {
        $proxyUrl = myv_proxy_url_for_stream($url);
        myv_app_log('play_redirected', [
            'id'=>$id,
            'idx'=>$idx,
            'asset'=>$asset,
            'delivery'=>'proxy_redirect',
            'upstream_host'=>(string)(parse_url($url, PHP_URL_HOST) ?: ''),
            'proxy_host'=>(string)(parse_url($proxyUrl, PHP_URL_HOST) ?: ''),
        ]);
        myv_log('route_proxy_302', ['id'=>$id, 'idx'=>$idx, 'asset'=>$asset, 'host'=>parse_url($url, PHP_URL_HOST), 'auto_index'=>($idx === null), 'role'=>function_exists('iptv_role') ? iptv_role() : '', 'proxy_host'=>parse_url($proxyUrl, PHP_URL_HOST)], true);
        header('Location: ' . $proxyUrl, true, 302);
        exit;
    }
    myv_app_log('play_redirected', [
        'id'=>$id,
        'idx'=>$idx,
        'asset'=>$asset,
        'delivery'=>'direct_redirect',
        'upstream_host'=>(string)(parse_url($url, PHP_URL_HOST) ?: ''),
    ]);
    myv_log('route_302', ['id'=>$id, 'idx'=>$idx, 'asset'=>$asset, 'host'=>parse_url($url, PHP_URL_HOST), 'auto_index'=>($idx === null), 'role'=>function_exists('iptv_role') ? iptv_role() : '']);
    header('Location: ' . $url, true, 302);
    exit;
}

function myv_require_post_api(): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['ok'=>false, 'error'=>'METHOD_NOT_ALLOWED', 'message'=>'This API requires POST'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function myv_app_file(string $name): string { return myv_paths()['dir'] . DIRECTORY_SEPARATOR . $name; }
function myv_app_read_json(string $file, array $default = []): array {
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : $default;
}
function myv_app_write_json(string $file, array $data): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return is_string($json) && @file_put_contents($file, $json . "\n", LOCK_EX) !== false;
}
function myv_app_default_config(): array {
    return [
        'enabled' => true,
        'deviceMode' => 'proxy',
        'logEnabled' => true,
        'maintenanceCooldownMinutes' => 2.0,
        'channelRefreshEnabled' => true,
        'channelRefreshHours' => 4.0,
        'requestTimeoutSeconds' => 20,
        'updated_at' => '',
    ];
}
function myv_req_timeout(): int { return max(5, min(120, (int)round((float)(myv_app_config()['requestTimeoutSeconds'] ?? 20)))); }
function myv_ip_is_private(string $ip): bool {
    $ip = trim($ip); if ($ip === '') return true;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        if (str_starts_with($ip,'127.')||str_starts_with($ip,'10.')||str_starts_with($ip,'192.168.')||str_starts_with($ip,'169.254.')||str_starts_with($ip,'0.')) return true;
        if (preg_match('/^172\.(1[6-9]|2\d|3[01])\./',$ip)) return true;
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { return $ip==='::1'||stripos($ip,'fc')===0||stripos($ip,'fd')===0||stripos($ip,'fe80')===0; }
    return true;
}
function myv_proxy_dest_blocked(string $url): bool {
    $host = strtolower(trim((string)(parse_url($url, PHP_URL_HOST) ?: ''), '[] '));
    if ($host === '' || $host === 'localhost') return true;
    if (filter_var($host, FILTER_VALIDATE_IP)) return myv_ip_is_private($host);
    $ips = @gethostbynamel($host); if (!is_array($ips) || !$ips) { $one=@gethostbyname($host); $ips=($one!==''&&$one!==$host)?[$one]:[]; }
    if (!$ips) return false;
    foreach ($ips as $ip) { if (myv_ip_is_private((string)$ip)) return true; }
    return false;
}
function myv_app_status_patch(array $patch): void {
    try { $f=myv_app_file('app_status.json'); $st=myv_app_read_json($f); if(!is_array($st))$st=[]; foreach($patch as $k=>$v)$st[$k]=$v; myv_app_write_json($f,$st); } catch (\Throwable $e) {}
}
function myv_app_status_bump(string $key): int {
    try { $f=myv_app_file('app_status.json'); $st=myv_app_read_json($f); if(!is_array($st))$st=[]; $n=(int)($st[$key] ?? 0)+1; $st[$key]=$n; myv_app_write_json($f,$st); return $n; } catch (\Throwable $e) { return 0; }
}

function myv_app_config_cache(?array $set = null, bool $reset = false): ?array {
    static $cache = null;
    if ($reset) { $cache = null; return null; }
    if ($set !== null) { $cache = $set; }
    return $cache;
}
function myv_app_config(): array {
    $cached = myv_app_config_cache();
    if (is_array($cached)) return $cached;
    $defaults = myv_app_default_config();
    $stored = myv_app_read_json(myv_app_file('app_config.json'));
    $out = $defaults;
    foreach ($defaults as $key => $_) {
        if (array_key_exists($key, $stored)) $out[$key] = $stored[$key];
    }
    foreach (['enabled','logEnabled','channelRefreshEnabled'] as $k) $out[$k] = !empty($out[$k]);
    $out['deviceMode'] = in_array((string)($out['deviceMode'] ?? 'proxy'), ['direct','proxy'], true) ? (string)$out['deviceMode'] : 'proxy';
    $out['maintenanceCooldownMinutes'] = max(0.1, min(1440.0, (float)$out['maintenanceCooldownMinutes']));
    $out['channelRefreshHours'] = max(0.25, min(720.0, (float)$out['channelRefreshHours']));
    $out['requestTimeoutSeconds'] = max(5, min(120, (int)round((float)($out['requestTimeoutSeconds'] ?? 20))));
    myv_app_config_cache($out);
    return $out;
}
function myv_app_save_config(array $patch): array {
    $defaults = myv_app_default_config();
    $cfg = myv_app_config();
    foreach ($defaults as $k => $_) if (array_key_exists($k, $patch)) $cfg[$k] = $patch[$k];
    $cfg['updated_at'] = date('c');
    myv_app_write_json(myv_app_file('app_config.json'), $cfg);
    myv_app_config_cache(null, true);
    if (array_key_exists('logEnabled', $patch) && function_exists('iptv_log_global_set_enabled')) iptv_log_global_set_enabled(!empty($cfg['logEnabled']));
    if (array_key_exists('logEnabled', $patch) && empty($cfg['logEnabled'])) {
        @file_put_contents(myv_app_file('app.log'), '');
        @file_put_contents(myv_paths()['log'], '');
    }
    myv_app_log('config_saved', ['changed_keys'=>array_keys($patch)]);
    return myv_app_config();
}
function myv_app_log(string $event, array $data = []): void {
    if (empty(myv_app_config()['logEnabled'])) return;
    myv_log_rotate(myv_app_file('app.log'));
    $row = ['ts'=>date('c'), 'event'=>$event, 'ip'=>(string)($_SERVER['REMOTE_ADDR'] ?? ''), 'ua'=>(string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'data'=>myv_log_scrub($data)];
    @file_put_contents(myv_app_file('app.log'), json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
function myv_app_tail(string $file, int $lines = 160): string {
    if (!is_file($file)) return '';
    $rows = @file($file, FILE_IGNORE_NEW_LINES);
    return is_array($rows) ? implode("\n", array_slice($rows, -$lines)) : '';
}
function myv_app_log_payload(int $appLines = 160, int $debugLines = 80): array {
    if (empty(myv_app_config()['logEnabled'])) return ['tail'=>'', 'size'=>0];
    $appFile = myv_app_file('app.log');
    return [
        'tail' => myv_app_tail($appFile, $appLines) . "\n" . myv_app_tail(myv_paths()['log'], $debugLines),
        'size' => (is_file($appFile) ? filesize($appFile) : 0),
    ];
}
function myv_app_base_url(): string {
    return iptv_current_script_url();
}
function myv_app_urls(): array { $base = myv_app_base_url(); return ['m3u'=>$base . '?m3u', 'txt'=>$base . '?txt']; }
function myv_no_cache_headers(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
function myv_app_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    myv_no_cache_headers();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function myv_app_body(): array {
    $data = json_decode((string)file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}
function myv_app_enabled_or_die(): void {
    if (!empty(myv_app_config()['enabled'])) return;
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'MyVideo 已停用';
    exit;
}
function myv_app_maintenance(string $source): array {
    $cfg = myv_app_config();
    if (empty($cfg['enabled'])) {
        myv_app_log('maintenance_skipped', ['source'=>$source, 'reason'=>'disabled']);
        return ['ok'=>false, 'disabled'=>true];
    }
    $mlock = @fopen(myv_app_file('maintenance.lock'), 'c');
    if ($mlock === false || !@flock($mlock, LOCK_EX | LOCK_NB)) {
        if (is_resource($mlock)) @fclose($mlock);
        myv_app_log('maintenance_skipped', ['source'=>$source, 'reason'=>'locked']);
        return ['ok'=>true, 'skipped'=>true, 'reason'=>'locked'];
    }
    try {
    $now = time();
    $statusFile = myv_app_file('app_status.json');
    $st = myv_app_read_json($statusFile);
    $cooldown = (int)round((float)$cfg['maintenanceCooldownMinutes'] * 60);
    if (!empty($st['last_maintenance_ts']) && ($now - (int)$st['last_maintenance_ts']) < $cooldown) {
        myv_app_log('maintenance_skipped', [
            'source'=>$source,
            'reason'=>'cooldown',
            'elapsed'=>$now - (int)$st['last_maintenance_ts'],
            'cooldown'=>$cooldown,
        ]);
        return ['ok'=>true, 'skipped'=>true, 'reason'=>'cooldown', 'status'=>$st];
    }
    $st['last_maintenance_ts'] = $now;
    $st['last_maintenance_at'] = date('c', $now);
    $st['last_maintenance_source'] = $source;
    myv_app_write_json($statusFile, $st);
    myv_app_log('maintenance_started', ['source'=>$source, 'cooldown'=>$cooldown]);
    $results = [];
    if (!empty($cfg['channelRefreshEnabled'])) {
        $last = (int)($st['last_channel_refresh_ts'] ?? 0);
        $due = empty(myv_list_load()) || ($now - $last) >= (float)$cfg['channelRefreshHours'] * 3600;
        myv_app_log('maintenance_task_checked', ['task'=>'channel_refresh', 'due'=>$due, 'last'=>$last, 'interval_seconds'=>(float)$cfg['channelRefreshHours'] * 3600]);
        if ($due) {
            myv_app_log('maintenance_task_started', ['task'=>'channel_refresh']);
            $r = function_exists('myv_sync_checked') ? myv_sync_checked(true) : ['ok'=>true, 'channels'=>myv_sync(true)];
            $st['last_channel_refresh_ts'] = time();
            $st['last_channel_refresh_at'] = date('c');
            $st['last_channel_refresh_ok'] = !empty($r['ok']);
            $st['last_channel_refresh_count'] = (int)($r['count'] ?? count(myv_list_load()));
            $results['channel_refresh'] = $r;
            myv_app_log('maintenance_task_finished', ['task'=>'channel_refresh', 'ok'=>!empty($r['ok']), 'count'=>(int)($r['count'] ?? count(myv_list_load()))]);
        }
    } else {
        myv_app_log('maintenance_task_skipped', ['task'=>'channel_refresh', 'reason'=>'disabled']);
    }
    myv_app_write_json($statusFile, $st);
    myv_app_log('maintenance_finished', ['source'=>$source, 'results'=>array_keys($results), 'status'=>$st]);
    return ['ok'=>true, 'results'=>$results, 'status'=>$st];
    } finally {
        @flock($mlock, LOCK_UN); @fclose($mlock);
    }
}
function myv_app_channels(int $limit = 500): array {
    $settings = myv_settings_load();
    $channels = myv_effective_channels(myv_list_load(), $settings);
    $out = [];
    foreach ($channels as $c) {
        if (!is_array($c)) continue;
        $id = (string)($c['fnID'] ?? '');
        if ($id === '') continue;
        $out[] = [
            'id'=>$id,
            'asset'=>(string)($c['fsMyVideo_ID'] ?? ''),
            'name'=>(string)($c['fsNAME'] ?? $id),
            'logo'=>myv_channel_logo_original($c),
            'group'=>myv_channel_group($c, $settings),
            'event'=>!empty($c['fcEVENT']),
            'status_desc'=>(string)($c['fsSTATUS_DESC'] ?? ''),
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}
function myv_app_all_channels(int $limit = 1200): array {
    $settings = myv_settings_load();
    $channels = myv_list_load();
    $out = [];
    foreach ($channels as $c) {
        if (!is_array($c)) continue;
        $id = (string)($c['fnID'] ?? '');
        if ($id === '') continue;
        $out[] = [
            'id' => $id,
            'asset' => (string)($c['fsMyVideo_ID'] ?? ''),
            'name' => (string)($c['fsNAME'] ?? $id),
            'logo' => myv_channel_logo_original($c),
            'group' => myv_channel_group($c, $settings),
            'groupDefault' => myv_channel_group_default($c),
            'free' => myv_is_free_channel_value($c['fcFREE'] ?? false),
            'event' => !empty($c['fcEVENT']),
            'status_desc' => (string)($c['fsSTATUS_DESC'] ?? ''),
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}
function myv_app_group_options(array $channels = null, array $settings = null): array {
    $channels = is_array($channels) ? $channels : myv_list_load();
    $settings = is_array($settings) ? $settings : myv_settings_load();
    $groups = [];
    foreach ((array)($settings['group_order'] ?? []) as $g) {
        $g = trim((string)$g);
        if ($g !== '') $groups[$g] = true;
    }
    foreach ($channels as $c) {
        if (!is_array($c)) continue;
        $defaultGroup = trim(myv_channel_group_default($c));
        if ($defaultGroup !== '') $groups[$defaultGroup] = true;
        $g = trim(myv_channel_group($c, $settings));
        if ($g !== '') $groups[$g] = true;
    }
    return array_keys($groups);
}
function myv_order_save(array $groupOrder, array $channelOrder): array {
    $settings = myv_settings_load();
    $channels = myv_list_load();
    $knownGroups = myv_app_group_options($channels, $settings);
    $knownGroupSet = array_fill_keys($knownGroups, true);
    $knownIds = [];
    foreach ($channels as $c) {
        if (is_array($c) && (string)($c['fnID'] ?? '') !== '') $knownIds[(string)$c['fnID']] = true;
    }
    $groups = [];
    foreach ($groupOrder as $g) {
        $g = trim((string)$g);
        if ($g !== '' && !isset($groups[$g])) $groups[$g] = true;
    }
    foreach ($knownGroups as $g) {
        if ($g !== '' && !isset($groups[$g])) $groups[$g] = true;
    }
    $orders = [];
    foreach ($channelOrder as $g => $ids) {
        $g = trim((string)$g);
        if ($g === '' || (!isset($knownGroupSet[$g]) && !isset($groups[$g]))) continue;
        $seen = [];
        foreach ((array)$ids as $id) {
            $id = (string)$id;
            if ($id !== '' && isset($knownIds[$id]) && !isset($seen[$id])) $seen[$id] = true;
        }
        $orders[$g] = array_keys($seen);
    }
    $saved = myv_settings_save([
        'group_order' => array_keys($groups),
        'channel_order' => $orders,
        'channel_sort_mode' => 'manual',
    ]);
    myv_app_log('order_saved', ['groups'=>count($groups), 'ordered_groups'=>count($orders)]);
    return ['ok'=>true, 'settings_saved'=>true, 'data'=>['group_order'=>$saved['group_order'], 'channel_order'=>$saved['channel_order'], 'channel_sort_mode'=>$saved['channel_sort_mode']]];
}
function myv_group_save(array $channelGroupMap): array {
    $settings = myv_settings_load();
    $channels = myv_list_load();
    $knownIds = [];
    foreach ($channels as $c) {
        if (is_array($c) && (string)($c['fnID'] ?? '') !== '') $knownIds[(string)$c['fnID']] = $c;
    }
    $map = [];
    $groups = array_fill_keys((array)($settings['group_order'] ?? []), true);
    foreach ($channelGroupMap as $id => $group) {
        $id = (string)$id;
        $group = trim((string)$group);
        if ($id === '' || $group === '' || !isset($knownIds[$id])) continue;
        $map[$id] = $group;
        $groups[$group] = true;
    }
    foreach (myv_app_group_options($channels, $settings) as $g) {
        if ($g !== '') $groups[$g] = true;
    }
    $saved = myv_settings_save([
        'channel_group_map' => $map,
        'group_order' => array_keys($groups),
    ]);
    myv_app_log('group_saved', ['mapped_channels'=>count($map), 'groups'=>count($groups)]);
    return ['ok'=>true, 'settings_saved'=>true, 'count'=>count($map), 'data'=>$saved['channel_group_map']];
}
function myv_app_status(): array {
    $status = myv_status();
    return ['ok'=>true, 'config'=>myv_app_config(), 'runtime'=>myv_app_read_json(myv_app_file('app_status.json')), 'status'=>$status, 'channels'=>myv_app_channels(400), 'allChannels'=>myv_app_all_channels(1200), 'groupOptions'=>myv_app_group_options(), 'urls'=>myv_app_urls(), 'log'=>myv_app_log_payload()];
}
function myv_app_resolve(string $id, string $asset = ''): array {
    myv_app_maintenance('resolve');
    $idn = (int)$id;
    if ($idn <= 0) return ['ok'=>false, 'error'=>'MISSING_ID'];
    $url = myv_get_channel_stream($idn, true, null, $asset);
    if (!$url) return ['ok'=>false, 'error'=>'STREAM_NOT_FOUND'];
    return ['ok'=>true, 'id'=>(string)$idn, 'asset'=>$asset, 'url'=>$url];
}
function myv_app_output_m3u(): void {
    myv_app_maintenance('playlist_m3u');
    header('Content-Type: application/x-mpegURL; charset=utf-8');
    header('Cache-Control: no-store');
    $rows = myv_export_rows();
    $cfg = myv_app_config();
    myv_app_log('playlist_requested', [
        'format'=>'m3u',
        'count'=>count($rows),
        'deviceMode'=>(string)($cfg['deviceMode'] ?? 'proxy'),
    ]);
    echo "#EXTM3U\n\n";
    foreach ($rows as $r) {
        $name = str_replace(["\r","\n"], '', (string)($r['name'] ?? $r['id'] ?? ''));
        $url = (string)($r['url'] ?? '');
        if ($name === '' || $url === '') continue;
        echo '#EXTINF:-1 tvg-id="'.str_replace('"',"'",(string)($r['tvgId'] ?? $r['id'] ?? '')).'" tvg-name="'.str_replace('"',"'",$name).'" tvg-logo="'.str_replace('"',"'",(string)($r['logo'] ?? '')).'" group-title="'.str_replace('"',"'",(string)($r['group'] ?? '直播')).'",'.$name."\n".$url."\n\n";
    }
    exit;
}
function myv_app_output_txt(): void {
    myv_app_maintenance('playlist_txt');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $rows = myv_export_rows();
    $cfg = myv_app_config();
    myv_app_log('playlist_requested', [
        'format'=>'txt',
        'count'=>count($rows),
        'deviceMode'=>(string)($cfg['deviceMode'] ?? 'proxy'),
    ]);
    $cur = '';
    foreach ($rows as $r) {
        $g = (string)($r['group'] ?? '直播');
        if ($g !== $cur) { $cur = $g; echo str_replace(["\r","\n"], '', $cur) . ",#genre#\n"; }
        $name = str_replace(["\r","\n"], '', (string)($r['name'] ?? $r['id'] ?? ''));
        $url = str_replace(["\r","\n"], '', (string)($r['url'] ?? ''));
        if ($name !== '' && $url !== '') echo $name . ',' . $url . "\n";
    }
    exit;
}

function myv_app_b64url_encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function myv_app_b64url_decode(string $value): string {
    $text = strtr($value, '-_', '+/');
    $text .= str_repeat('=', (4 - strlen($text) % 4) % 4);
    $decoded = base64_decode($text, true);
    return is_string($decoded) ? $decoded : '';
}
function myv_app_abs_url(string $base, string $rel): string {
    $rel = trim($rel);
    if ($rel === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $rel)) return $rel;
    $p = parse_url($base);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) return $rel;
    if (str_starts_with($rel, '//')) return $p['scheme'] . ':' . $rel;
    $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($rel, '/')) return $root . $rel;
    $path = (string)($p['path'] ?? '/');
    $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
    $parts = [];
    foreach (explode('/', $dir . $rel) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') array_pop($parts); else $parts[] = $part;
    }
    return $root . '/' . implode('/', $parts);
}
function myv_app_proxy_url(string $url): string { return myv_app_base_url() . '?action=proxy&u=' . rawurlencode(myv_app_b64url_encode($url)); }
function myv_app_rewrite_m3u8(string $body, string $baseUrl): string {
    $out = [];
    foreach (preg_split('/\R/', $body) ?: [] as $line) {
        $trim = trim($line);
        if ($trim === '') { $out[] = $line; continue; }
        if (str_starts_with($trim, '#')) {
            if (preg_match_all('/URI="([^"]+)"/', $line, $matches)) {
                foreach ($matches[1] as $uri) {
                    $abs = myv_app_abs_url($baseUrl, $uri);
                    if (preg_match('#^https?://#i', $abs)) $line = str_replace('URI="' . $uri . '"', 'URI="' . myv_app_proxy_url($abs) . '"', $line);
                }
            }
            $out[] = $line;
            continue;
        }
        $abs = myv_app_abs_url($baseUrl, $trim);
        $out[] = preg_match('#^https?://#i', $abs) ? myv_app_proxy_url($abs) : $line;
    }
    return implode("\n", $out) . "\n";
}
function myv_app_proxy_stream(string $url): array {
    if (!function_exists('curl_init')) return ['ok'=>false, 'started'=>false, 'http'=>0];
    $ch = curl_init($url);
    $started = false;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5,
        CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>30, CURLOPT_ENCODING=>'',
        CURLOPT_USERAGENT=>MYV_ANDROID_UA, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HEADERFUNCTION=>function($c, string $line): int {
            $low = strtolower($line);
            if (strncmp($low, 'content-type:', 13) === 0) header('Content-Type: ' . trim(substr($line, 13)));
            elseif (strncmp($low, 'content-length:', 15) === 0) header('Content-Length: ' . trim(substr($line, 15)));
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION=>function($c, string $data) use (&$started): int {
            $started = true; echo $data; return strlen($data);
        },
    ]);
    header('Cache-Control: no-store');
    $res = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['ok'=>$res !== false && $http >= 200 && $http < 400, 'started'=>$started, 'http'=>$http];
}
function myv_app_proxy_serve(): void {
    $url = myv_app_b64url_decode((string)($_GET['u'] ?? ''));
    if (!preg_match('#^https?://#i', $url)) { http_response_code(400); header('Content-Type: text/plain; charset=utf-8'); echo 'Invalid proxy URL'; exit; }
    if (myv_proxy_dest_blocked($url)) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo 'Proxy destination blocked'; myv_app_log('proxy_blocked', ['host'=>(string)(parse_url($url, PHP_URL_HOST) ?: ''), 'reason'=>'private_or_loopback']); exit; }
    if (!function_exists('curl_init')) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'CURL_EXT_MISSING'; exit; }
    $host = parse_url($url, PHP_URL_HOST);
    $reqPath = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));
    $isSegment = preg_match('/\.(ts|m4s|mp4|aac)(\?|$)/i', $reqPath) === 1;
    if ($isSegment) {
        $res = myv_app_proxy_stream($url);
        if (empty($res['ok']) && empty($res['started'])) {
            myv_app_status_patch(['last_proxy_error_at'=>date('c'), 'last_proxy_error_host'=>(string)$host]); myv_app_status_bump('proxy_fail_count');
            http_response_code(502); header('Content-Type: text/plain; charset=utf-8'); echo 'Proxy upstream failed';
            myv_app_log('proxy_requested', ['host'=>$host, 'http'=>(int)($res['http'] ?? 0), 'ok'=>false, 'streamed'=>true]);
            exit;
        }
        myv_app_log('proxy_requested', ['host'=>$host, 'http'=>(int)($res['http'] ?? 0), 'ok'=>!empty($res['ok']), 'streamed'=>true]);
        exit;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>30, CURLOPT_ENCODING=>'', CURLOPT_USERAGENT=>MYV_ANDROID_UA, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    myv_app_log('proxy_requested', ['host'=>$host, 'http'=>$http, 'errno'=>$errno, 'ok'=>is_string($body) && $errno === 0 && $http < 400, 'streamed'=>false]);
    if (!is_string($body) || $errno !== 0 || $http >= 400) { myv_app_status_patch(['last_proxy_error_at'=>date('c'), 'last_proxy_error_host'=>(string)$host]); myv_app_status_bump('proxy_fail_count'); http_response_code(502); header('Content-Type: text/plain; charset=utf-8'); echo 'Proxy upstream failed'; exit; }
    $path = strtolower((string)(parse_url($effective !== '' ? $effective : $url, PHP_URL_PATH) ?: ''));
    if (str_contains(strtolower($ctype), 'mpegurl') || str_contains($path, '.m3u8')) {
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: no-store');
        echo myv_app_rewrite_m3u8($body, $effective !== '' ? $effective : $url);
        exit;
    }
    header('Content-Type: ' . ($ctype !== '' ? $ctype : 'application/octet-stream'));
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}
if ((string)($_GET['action'] ?? '') === 'proxy') { myv_app_enabled_or_die(); myv_app_proxy_serve(); }
if (isset($_GET['m3u'])) { myv_app_enabled_or_die(); myv_app_output_m3u(); }
if (isset($_GET['txt'])) { myv_app_enabled_or_die(); myv_app_output_txt(); }
if ((string)($_GET['action'] ?? '') === 'myvideo') { myv_app_enabled_or_die(); myv_app_maintenance('stream'); myv_handle_index_route(); }
if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];
    $body = myv_app_body();
    try {
        if ($api === 'status') {
            myv_app_json(myv_status());
        }
        if ($api === 'app_status') myv_app_json(myv_app_status());
        if ($api === 'panel_status') {
            $channels = myv_list_load();
            $settings = myv_settings_load();
            $exclude = array_fill_keys(array_map('strval', (array)($settings['selection_rules']['exclude_channel_ids'] ?? [])), true);
            $freeCount = 0;
            $paidCount = 0;
            $groups = [];
            foreach ($channels as $c) {
                if (!is_array($c)) continue;
                $free = myv_is_free_channel_value($c['fcFREE'] ?? false);
                if ($free) $freeCount++; else $paidCount++;
                $group = myv_channel_group($c, $settings);
                if ($group !== '') $groups[$group] = true;
            }
            $outputCount = count(myv_effective_channels($channels, $settings));
            myv_app_json([
                'ok' => true,
                'config' => myv_app_config(),
                'runtime' => myv_app_read_json(myv_app_file('app_status.json')),
                'status' => myv_status(),
                'counts' => [
                    'total' => count($channels),
                    'free' => $freeCount,
                    'paid' => $paidCount,
                    'output' => $outputCount,
                    'groups' => count($groups),
                    'excluded' => max(0, count($channels) - $outputCount),
                ],
                'urls' => myv_app_urls(),
                'log' => myv_app_log_payload(220, 80),
            ]);
        }
        if ($api === 'list') {
            $channels = myv_list_load();
            if (empty($channels)) {
                try { $channels = myv_sync(false); } catch (\Throwable $e) { $channels = []; }
            }
            $settings = myv_settings_load();
            $out = [];
            $freeCount = 0;
            $paidCount = 0;
            foreach ($channels as $c) {
                if (!is_array($c)) continue;
                $free = myv_is_free_channel_value($c['fcFREE'] ?? false);
                if ($free) $freeCount++; else $paidCount++;
                $out[] = [
                    'id' => (string)($c['fnID'] ?? ''),
                    'name' => (string)($c['fsNAME'] ?? ''),
                    'asset' => (string)($c['fsMyVideo_ID'] ?? ''),
                    'group_default' => myv_channel_group_default($c),
                    'group' => myv_channel_group($c, $settings),
                    'logo' => myv_channel_logo_original($c),
                    'free' => $free,
                    'event' => !empty($c['fcEVENT']),
                    'status_desc' => (string)($c['fsSTATUS_DESC'] ?? ''),
                ];
            }
            myv_app_json([
                'ok' => true,
                'access_mode' => 'anonymous',
                'anonymous' => true,
                'export_all_channels' => true,
                'free_count' => $freeCount,
                'paid_count' => $paidCount,
                'channels' => $out,
                'settings' => $settings,
            ]);
        }
        if ($api === 'settings_save') { myv_app_log('settings_save_requested', ['keys'=>array_keys($body)]); myv_app_json(['ok'=>true, 'settings'=>myv_settings_save($body)]); }
        if ($api === 'save') myv_app_json(['ok'=>true, 'config'=>myv_app_save_config($body)]);
        if ($api === 'reset_channels') {
            myv_app_log('reset_channels_requested');
            $p = myv_paths();
            @unlink($p['list']);
            foreach ([$p['stream_dir'], $p['last_refresh_dir'], $p['jobs_dir']] as $dir) {
                foreach ((array)glob($dir . DIRECTORY_SEPARATOR . '*') as $entry) {
                    if (is_dir($entry)) {
                        foreach ((array)glob($entry . DIRECTORY_SEPARATOR . '*') as $file) {
                            if (is_file($file)) @unlink($file);
                        }
                        @rmdir($entry);
                    } elseif (is_file($entry)) {
                        @unlink($entry);
                    }
                }
            }
            @unlink(myv_app_file('app_status.json'));
            myv_settings_cache(null, true);
            if (!myv_write_json($p['settings'], myv_default_settings())) {
                throw new \RuntimeException('WRITE_FAILED: 無法重置頻道設定');
            }
            myv_settings_cache(null, true);
            $job = myv_refresh_start_job(true);
            myv_app_json(['ok' => true, 'job' => $job]);
        }
        if ($api === 'sync' || $api === 'refresh_start' || $api === 'auto_refresh_now') { myv_app_log('manual_sync_requested', ['api'=>$api]); $job = myv_refresh_start_job(true); myv_app_log('manual_sync_finished', ['api'=>$api, 'state'=>(string)($job['state'] ?? ''), 'total'=>(int)($job['total'] ?? 0), 'success'=>(int)($job['success'] ?? 0), 'failed'=>(int)($job['failed'] ?? 0)]); myv_app_json(['ok'=>true, 'job'=>$job]); }
        if ($api === 'refresh_step') {
            $batch = max(1, min(20, (int)($body['batch'] ?? 5)));
            myv_app_json(myv_refresh_step_job($batch));
        }
        if ($api === 'refresh_status') myv_app_json(['ok'=>true, 'job'=>myv_refresh_job_load()]);
        if ($api === 'refresh_cancel') { myv_refresh_job_clear(); myv_app_json(['ok'=>true]); }
        if ($api === 'maintenance') { myv_app_log('manual_maintenance_requested'); myv_app_json(myv_app_maintenance('manual')); }
        if ($api === 'resolve') { $rid = (string)($body['id'] ?? $_GET['id'] ?? ''); $asset = (string)($body['asset'] ?? $_GET['asset'] ?? ''); myv_app_log('resolve_test_requested', ['id'=>$rid, 'asset'=>$asset]); $r = myv_app_resolve($rid, $asset); myv_app_log('resolve_test_finished', ['id'=>$rid, 'asset'=>$asset, 'ok'=>!empty($r['ok']), 'error'=>(string)($r['error'] ?? ''), 'upstream_host'=>!empty($r['url']) ? (string)(parse_url((string)$r['url'], PHP_URL_HOST) ?: '') : '']); myv_app_json($r); }
        if ($api === 'order_save') myv_app_json(myv_order_save((array)($body['group_order'] ?? []), (array)($body['channel_order'] ?? [])));
        if ($api === 'group_save') myv_app_json(myv_group_save((array)($body['channel_group_map'] ?? [])));
        if ($api === 'clear_log') { @file_put_contents(myv_app_file('app.log'), ''); @file_put_contents(myv_paths()['log'], ''); myv_app_log('log_cleared'); myv_app_json(['ok'=>true]); }
        myv_app_json(['ok'=>false, 'error'=>'UNKNOWN_API'], 400);
    } catch (Throwable $e) {
        myv_app_log('api_exception', ['api'=>$api, 'message'=>$e->getMessage()]);
        myv_app_json(['ok'=>false, 'error'=>$e->getMessage()], 500);
    }
}

$csrf = '';
header('Content-Type: text/html; charset=utf-8');
myv_no_cache_headers();
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>MyVideo 免登入版</title>
<script src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin></script>
<script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
<style>/*
 * 檔案說明：
 * 畫面樣式表。
 * 它只控制顏色、間距、大小、排版與手機/電腦響應式外觀，不處理資料。
 * 修改後要同時看手機與桌面，確認文字沒有重疊或超出畫面。
 * 給非程式使用者的提醒：這些註解只解釋用途，不會改變程式實際執行結果。
 */
*,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }::backdrop{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: } *,:after,:before{box-sizing:border-box;border:0 solid #e5e7eb}:after,:before{--tw-content:""}:host,html{line-height:1.5;-webkit-text-size-adjust:100%;-moz-tab-size:4;-o-tab-size:4;tab-size:4;font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji;font-feature-settings:normal;font-variation-settings:normal;-webkit-tap-highlight-color:transparent}body{margin:0;line-height:inherit}hr{height:0;color:inherit;border-top-width:1px}abbr:where([title]){-webkit-text-decoration:underline dotted;text-decoration:underline dotted}h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}b,strong{font-weight:bolder}code,kbd,pre,samp{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace;font-feature-settings:normal;font-variation-settings:normal;font-size:1em}small{font-size:80%}sub,sup{font-size:75%;line-height:0;position:relative;vertical-align:baseline}sub{bottom:-.25em}sup{top:-.5em}table{text-indent:0;border-color:inherit;border-collapse:collapse}button,input,optgroup,select,textarea{font-family:inherit;font-feature-settings:inherit;font-variation-settings:inherit;font-size:100%;font-weight:inherit;line-height:inherit;letter-spacing:inherit;color:inherit;margin:0;padding:0}button,select{text-transform:none}button,input:where([type=button]),input:where([type=reset]),input:where([type=submit]){-webkit-appearance:button;background-color:transparent;background-image:none}:-moz-focusring{outline:auto}:-moz-ui-invalid{box-shadow:none}progress{vertical-align:baseline}::-webkit-inner-spin-button,::-webkit-outer-spin-button{height:auto}[type=search]{-webkit-appearance:textfield;outline-offset:-2px}::-webkit-search-decoration{-webkit-appearance:none}::-webkit-file-upload-button{-webkit-appearance:button;font:inherit}summary{display:list-item}blockquote,dd,dl,figure,h1,h2,h3,h4,h5,h6,hr,p,pre{margin:0}fieldset{margin:0}fieldset,legend{padding:0}menu,ol,ul{list-style:none;margin:0;padding:0}dialog{padding:0}textarea{resize:vertical}input::-moz-placeholder,textarea::-moz-placeholder{opacity:1;color:#9ca3af}input::placeholder,textarea::placeholder{opacity:1;color:#9ca3af}[role=button],button{cursor:pointer}:disabled{cursor:default}audio,canvas,embed,iframe,img,object,svg,video{display:block;vertical-align:middle}img,video{max-width:100%;height:auto}[hidden]:where(:not([hidden=until-found])){display:none}.container{width:100%}@media (min-width:640px){.container{max-width:640px}}@media (min-width:768px){.container{max-width:768px}}@media (min-width:1024px){.container{max-width:1024px}}@media (min-width:1280px){.container{max-width:1280px}}@media (min-width:1536px){.container{max-width:1536px}}.collapse{visibility:collapse}.static{position:static}.fixed{position:fixed}.absolute{position:absolute}.relative{position:relative}.sticky{position:sticky}.inset-0{inset:0}.bottom-6{bottom:1.5rem}.left-1\/2{left:50%}.left-3{left:.75rem}.right-2{right:.5rem}.top-0{top:0}.top-1\/2{top:50%}.top-3\.5{top:.875rem}.z-10{z-index:10}.z-50{z-index:50}.z-\[9999\]{z-index:9999}.mx-auto{margin-left:auto;margin-right:auto}.mb-0\.5{margin-bottom:.125rem}.mb-1{margin-bottom:.25rem}.mb-1\.5{margin-bottom:.375rem}.mb-2{margin-bottom:.5rem}.mb-3{margin-bottom:.75rem}.mb-4{margin-bottom:1rem}.mb-5{margin-bottom:1.25rem}.mb-6{margin-bottom:1.5rem}.mb-8{margin-bottom:2rem}.me-2{margin-inline-end:.5rem}.ml-1{margin-left:.25rem}.ml-2{margin-left:.5rem}.ml-auto{margin-left:auto}.mr-1{margin-right:.25rem}.mr-2{margin-right:.5rem}.mr-3{margin-right:.75rem}.mt-0\.5{margin-top:.125rem}.mt-1{margin-top:.25rem}.mt-2{margin-top:.5rem}.mt-3{margin-top:.75rem}.mt-4{margin-top:1rem}.mt-5{margin-top:1.25rem}.mt-6{margin-top:1.5rem}.block{display:block}.inline{display:inline}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.grid{display:grid}.hidden{display:none}.h-10{height:2.5rem}.h-11{height:2.75rem}.h-12{height:3rem}.h-16{height:4rem}.h-2{height:.5rem}.h-4{height:1rem}.h-48{height:12rem}.h-8{height:2rem}.h-9{height:2.25rem}.h-full{height:100%}.max-h-40{max-height:10rem}.max-h-48{max-height:12rem}.min-h-\[10px\]{min-height:10px}.min-h-\[250px\]{min-height:250px}.min-h-screen{min-height:100vh}.w-0{width:0}.w-10{width:2.5rem}.w-11{width:2.75rem}.w-12{width:3rem}.w-16{width:4rem}.w-32{width:8rem}.w-4{width:1rem}.w-40{width:10rem}.w-48{width:12rem}.w-8{width:2rem}.w-9{width:2.25rem}.w-full{width:100%}.min-w-0{min-width:0}.min-w-\[140px\]{min-width:140px}.max-w-5xl{max-width:64rem}.max-w-6xl{max-width:72rem}.max-w-7xl{max-width:80rem}.max-w-\[150px\]{max-width:150px}.max-w-lg{max-width:32rem}.flex-1{flex:1 1 0%}.shrink-0{flex-shrink:0}.-translate-x-1\/2{--tw-translate-x:-50%}.-translate-x-1\/2,.-translate-y-1\/2{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-y-1\/2{--tw-translate-y:-50%}.transform{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.cursor-move{cursor:move}.cursor-pointer{cursor:pointer}.select-none{-webkit-user-select:none;-moz-user-select:none;user-select:none}.resize{resize:both}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.flex-col{flex-direction:column}.flex-wrap{flex-wrap:wrap}.items-start{align-items:flex-start}.items-center{align-items:center}.justify-end{justify-content:flex-end}.justify-center{justify-content:center}.justify-between{justify-content:space-between}.gap-1{gap:.25rem}.gap-2{gap:.5rem}.gap-3{gap:.75rem}.gap-4{gap:1rem}.gap-6{gap:1.5rem}.gap-8{gap:2rem}.space-y-1>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.25rem*var(--tw-space-y-reverse))}.space-y-2>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.5rem*var(--tw-space-y-reverse))}.space-y-3>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.75rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.75rem*var(--tw-space-y-reverse))}.space-y-4>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1rem*var(--tw-space-y-reverse))}.space-y-5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.25rem*var(--tw-space-y-reverse))}.space-y-6>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.5rem*var(--tw-space-y-reverse))}.divide-y>:not([hidden])~:not([hidden]){--tw-divide-y-reverse:0;border-top-width:calc(1px*(1 - var(--tw-divide-y-reverse)));border-bottom-width:calc(1px*var(--tw-divide-y-reverse))}.divide-gray-50>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(249 250 251/var(--tw-divide-opacity,1))}.self-end{align-self:flex-end}.self-center{align-self:center}.overflow-auto{overflow:auto}.overflow-hidden{overflow:hidden}.overflow-y-auto{overflow-y:auto}.truncate{overflow:hidden;text-overflow:ellipsis}.truncate,.whitespace-nowrap{white-space:nowrap}.whitespace-pre-wrap{white-space:pre-wrap}.break-all{word-break:break-all}.rounded{border-radius:.25rem}.rounded-2xl{border-radius:1rem}.rounded-full{border-radius:9999px}.rounded-lg{border-radius:.5rem}.rounded-none{border-radius:0}.rounded-xl{border-radius:.75rem}.border{border-width:1px}.border-2{border-width:2px}.border-b{border-bottom-width:1px}.border-b-2{border-bottom-width:2px}.border-l-4{border-left-width:4px}.border-t{border-top-width:1px}.border-dashed{border-style:dashed}.border-amber-200{--tw-border-opacity:1;border-color:rgb(253 230 138/var(--tw-border-opacity,1))}.border-amber-300{--tw-border-opacity:1;border-color:rgb(252 211 77/var(--tw-border-opacity,1))}.border-amber-500\/40{border-color:rgba(245,158,11,.4)}.border-blue-100{--tw-border-opacity:1;border-color:rgb(219 234 254/var(--tw-border-opacity,1))}.border-blue-200{--tw-border-opacity:1;border-color:rgb(191 219 254/var(--tw-border-opacity,1))}.border-gray-100{--tw-border-opacity:1;border-color:rgb(243 244 246/var(--tw-border-opacity,1))}.border-gray-200{--tw-border-opacity:1;border-color:rgb(229 231 235/var(--tw-border-opacity,1))}.border-gray-300{--tw-border-opacity:1;border-color:rgb(209 213 219/var(--tw-border-opacity,1))}.border-green-100{--tw-border-opacity:1;border-color:rgb(220 252 231/var(--tw-border-opacity,1))}.border-indigo-200{--tw-border-opacity:1;border-color:rgb(199 210 254/var(--tw-border-opacity,1))}.border-indigo-500{--tw-border-opacity:1;border-color:rgb(99 102 241/var(--tw-border-opacity,1))}.border-indigo-600{--tw-border-opacity:1;border-color:rgb(79 70 229/var(--tw-border-opacity,1))}.border-purple-200{--tw-border-opacity:1;border-color:rgb(233 213 255/var(--tw-border-opacity,1))}.border-purple-300{--tw-border-opacity:1;border-color:rgb(216 180 254/var(--tw-border-opacity,1))}.border-red-100{--tw-border-opacity:1;border-color:rgb(254 226 226/var(--tw-border-opacity,1))}.border-red-200{--tw-border-opacity:1;border-color:rgb(254 202 202/var(--tw-border-opacity,1))}.border-red-500\/40{border-color:rgba(239,68,68,.4)}.border-rose-200{--tw-border-opacity:1;border-color:rgb(254 205 211/var(--tw-border-opacity,1))}.border-rose-500\/20{border-color:rgba(244,63,94,.2)}.border-sky-200{--tw-border-opacity:1;border-color:rgb(186 230 253/var(--tw-border-opacity,1))}.border-slate-200{--tw-border-opacity:1;border-color:rgb(226 232 240/var(--tw-border-opacity,1))}.border-slate-600{--tw-border-opacity:1;border-color:rgb(71 85 105/var(--tw-border-opacity,1))}.border-white\/10{border-color:hsla(0,0%,100%,.1)}.border-white\/20{border-color:hsla(0,0%,100%,.2)}.border-white\/5{border-color:hsla(0,0%,100%,.05)}.bg-amber-100{--tw-bg-opacity:1;background-color:rgb(254 243 199/var(--tw-bg-opacity,1))}.bg-amber-50{--tw-bg-opacity:1;background-color:rgb(255 251 235/var(--tw-bg-opacity,1))}.bg-amber-500{--tw-bg-opacity:1;background-color:rgb(245 158 11/var(--tw-bg-opacity,1))}.bg-amber-600{--tw-bg-opacity:1;background-color:rgb(217 119 6/var(--tw-bg-opacity,1))}.bg-black\/10{background-color:rgba(0,0,0,.1)}.bg-black\/30{background-color:rgba(0,0,0,.3)}.bg-blue-100{--tw-bg-opacity:1;background-color:rgb(219 234 254/var(--tw-bg-opacity,1))}.bg-blue-50{--tw-bg-opacity:1;background-color:rgb(239 246 255/var(--tw-bg-opacity,1))}.bg-blue-500{--tw-bg-opacity:1;background-color:rgb(59 130 246/var(--tw-bg-opacity,1))}.bg-emerald-600{--tw-bg-opacity:1;background-color:rgb(5 150 105/var(--tw-bg-opacity,1))}.bg-gray-100{--tw-bg-opacity:1;background-color:rgb(243 244 246/var(--tw-bg-opacity,1))}.bg-gray-50{--tw-bg-opacity:1;background-color:rgb(249 250 251/var(--tw-bg-opacity,1))}.bg-gray-50\/50{background-color:rgba(249,250,251,.5)}.bg-gray-600{--tw-bg-opacity:1;background-color:rgb(75 85 99/var(--tw-bg-opacity,1))}.bg-gray-900{--tw-bg-opacity:1;background-color:rgb(17 24 39/var(--tw-bg-opacity,1))}.bg-green-100{--tw-bg-opacity:1;background-color:rgb(220 252 231/var(--tw-bg-opacity,1))}.bg-green-50{--tw-bg-opacity:1;background-color:rgb(240 253 244/var(--tw-bg-opacity,1))}.bg-green-600{--tw-bg-opacity:1;background-color:rgb(22 163 74/var(--tw-bg-opacity,1))}.bg-indigo-100{--tw-bg-opacity:1;background-color:rgb(224 231 255/var(--tw-bg-opacity,1))}.bg-indigo-50{--tw-bg-opacity:1;background-color:rgb(238 242 255/var(--tw-bg-opacity,1))}.bg-indigo-600{--tw-bg-opacity:1;background-color:rgb(79 70 229/var(--tw-bg-opacity,1))}.bg-purple-100{--tw-bg-opacity:1;background-color:rgb(243 232 255/var(--tw-bg-opacity,1))}.bg-purple-50\/50{background-color:rgba(250,245,255,.5)}.bg-red-100{--tw-bg-opacity:1;background-color:rgb(254 226 226/var(--tw-bg-opacity,1))}.bg-red-50{--tw-bg-opacity:1;background-color:rgb(254 242 242/var(--tw-bg-opacity,1))}.bg-red-50\/50{background-color:hsla(0,86%,97%,.5)}.bg-red-500{--tw-bg-opacity:1;background-color:rgb(239 68 68/var(--tw-bg-opacity,1))}.bg-red-500\/10{background-color:rgba(239,68,68,.1)}.bg-rose-50{--tw-bg-opacity:1;background-color:rgb(255 241 242/var(--tw-bg-opacity,1))}.bg-rose-500\/10{background-color:rgba(244,63,94,.1)}.bg-sky-100{--tw-bg-opacity:1;background-color:rgb(224 242 254/var(--tw-bg-opacity,1))}.bg-slate-50{--tw-bg-opacity:1;background-color:rgb(248 250 252/var(--tw-bg-opacity,1))}.bg-slate-600{--tw-bg-opacity:1;background-color:rgb(71 85 105/var(--tw-bg-opacity,1))}.bg-slate-800{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.bg-slate-950\/20{background-color:rgba(2,6,23,.2)}.bg-transparent{background-color:transparent}.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.bg-white\/10{background-color:hsla(0,0%,100%,.1)}.bg-white\/5{background-color:hsla(0,0%,100%,.05)}.bg-white\/70{background-color:hsla(0,0%,100%,.7)}.object-contain{-o-object-fit:contain;object-fit:contain}.p-0{padding:0}.p-1{padding:.25rem}.p-2{padding:.5rem}.p-3{padding:.75rem}.p-4{padding:1rem}.p-6{padding:1.5rem}.p-8{padding:2rem}.px-0\.5{padding-left:.125rem;padding-right:.125rem}.px-1{padding-left:.25rem;padding-right:.25rem}.px-1\.5{padding-left:.375rem;padding-right:.375rem}.px-2{padding-left:.5rem;padding-right:.5rem}.px-3{padding-left:.75rem;padding-right:.75rem}.px-4{padding-left:1rem;padding-right:1rem}.px-6{padding-left:1.5rem;padding-right:1.5rem}.py-0{padding-top:0;padding-bottom:0}.py-0\.5{padding-top:.125rem;padding-bottom:.125rem}.py-1{padding-top:.25rem;padding-bottom:.25rem}.py-1\.5{padding-top:.375rem;padding-bottom:.375rem}.py-2{padding-top:.5rem;padding-bottom:.5rem}.py-2\.5{padding-top:.625rem;padding-bottom:.625rem}.py-20{padding-top:5rem;padding-bottom:5rem}.py-3{padding-top:.75rem;padding-bottom:.75rem}.py-4{padding-top:1rem;padding-bottom:1rem}.py-6{padding-top:1.5rem;padding-bottom:1.5rem}.pb-10{padding-bottom:2.5rem}.pb-2{padding-bottom:.5rem}.pb-20{padding-bottom:5rem}.pb-3{padding-bottom:.75rem}.pl-10{padding-left:2.5rem}.pr-10{padding-right:2.5rem}.pr-4{padding-right:1rem}.pt-1{padding-top:.25rem}.pt-2{padding-top:.5rem}.pt-5{padding-top:1.25rem}.text-left{text-align:left}.text-center{text-align:center}.text-right{text-align:right}.font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace}.text-2xl{font-size:1.5rem;line-height:2rem}.text-3xl{font-size:1.875rem;line-height:2.25rem}.text-\[10px\]{font-size:10px}.text-\[11px\]{font-size:11px}.text-base{font-size:1rem;line-height:1.5rem}.text-lg{font-size:1.125rem;line-height:1.75rem}.text-sm{font-size:.875rem;line-height:1.25rem}.text-xl{font-size:1.25rem;line-height:1.75rem}.text-xs{font-size:.75rem;line-height:1rem}.font-black{font-weight:900}.font-bold{font-weight:700}.font-medium{font-weight:500}.font-normal{font-weight:400}.font-semibold{font-weight:600}.uppercase{text-transform:uppercase}.normal-case{text-transform:none}.leading-relaxed{line-height:1.625}.leading-snug{line-height:1.375}.leading-tight{line-height:1.25}.tracking-tight{letter-spacing:-.025em}.tracking-wide{letter-spacing:.025em}.text-amber-300{--tw-text-opacity:1;color:rgb(252 211 77/var(--tw-text-opacity,1))}.text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.text-amber-500{--tw-text-opacity:1;color:rgb(245 158 11/var(--tw-text-opacity,1))}.text-amber-700{--tw-text-opacity:1;color:rgb(180 83 9/var(--tw-text-opacity,1))}.text-amber-700\/80{color:rgba(180,83,9,.8)}.text-amber-800{--tw-text-opacity:1;color:rgb(146 64 14/var(--tw-text-opacity,1))}.text-blue-500{--tw-text-opacity:1;color:rgb(59 130 246/var(--tw-text-opacity,1))}.text-blue-600{--tw-text-opacity:1;color:rgb(37 99 235/var(--tw-text-opacity,1))}.text-blue-700{--tw-text-opacity:1;color:rgb(29 78 216/var(--tw-text-opacity,1))}.text-emerald-300{--tw-text-opacity:1;color:rgb(110 231 183/var(--tw-text-opacity,1))}.text-gray-200{--tw-text-opacity:1;color:rgb(229 231 235/var(--tw-text-opacity,1))}.text-gray-300{--tw-text-opacity:1;color:rgb(209 213 219/var(--tw-text-opacity,1))}.text-gray-400{--tw-text-opacity:1;color:rgb(156 163 175/var(--tw-text-opacity,1))}.text-gray-500{--tw-text-opacity:1;color:rgb(107 114 128/var(--tw-text-opacity,1))}.text-gray-600{--tw-text-opacity:1;color:rgb(75 85 99/var(--tw-text-opacity,1))}.text-gray-700{--tw-text-opacity:1;color:rgb(55 65 81/var(--tw-text-opacity,1))}.text-gray-800{--tw-text-opacity:1;color:rgb(31 41 55/var(--tw-text-opacity,1))}.text-green-600{--tw-text-opacity:1;color:rgb(22 163 74/var(--tw-text-opacity,1))}.text-green-700{--tw-text-opacity:1;color:rgb(21 128 61/var(--tw-text-opacity,1))}.text-indigo-500{--tw-text-opacity:1;color:rgb(99 102 241/var(--tw-text-opacity,1))}.text-indigo-600{--tw-text-opacity:1;color:rgb(79 70 229/var(--tw-text-opacity,1))}.text-indigo-700{--tw-text-opacity:1;color:rgb(67 56 202/var(--tw-text-opacity,1))}.text-indigo-800{--tw-text-opacity:1;color:rgb(55 48 163/var(--tw-text-opacity,1))}.text-indigo-900{--tw-text-opacity:1;color:rgb(49 46 129/var(--tw-text-opacity,1))}.text-purple-600{--tw-text-opacity:1;color:rgb(147 51 234/var(--tw-text-opacity,1))}.text-purple-700{--tw-text-opacity:1;color:rgb(126 34 206/var(--tw-text-opacity,1))}.text-purple-700\/80{color:rgba(126,34,206,.8)}.text-red-300{--tw-text-opacity:1;color:rgb(252 165 165/var(--tw-text-opacity,1))}.text-red-500{--tw-text-opacity:1;color:rgb(239 68 68/var(--tw-text-opacity,1))}.text-red-600{--tw-text-opacity:1;color:rgb(220 38 38/var(--tw-text-opacity,1))}.text-red-700{--tw-text-opacity:1;color:rgb(185 28 28/var(--tw-text-opacity,1))}.text-rose-200{--tw-text-opacity:1;color:rgb(254 205 211/var(--tw-text-opacity,1))}.text-rose-300{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.text-rose-700{--tw-text-opacity:1;color:rgb(190 18 60/var(--tw-text-opacity,1))}.text-sky-700{--tw-text-opacity:1;color:rgb(3 105 161/var(--tw-text-opacity,1))}.text-slate-100{--tw-text-opacity:1;color:rgb(241 245 249/var(--tw-text-opacity,1))}.text-slate-200{--tw-text-opacity:1;color:rgb(226 232 240/var(--tw-text-opacity,1))}.text-slate-200\/90{color:rgba(226,232,240,.9)}.text-slate-300{--tw-text-opacity:1;color:rgb(203 213 225/var(--tw-text-opacity,1))}.text-slate-400{--tw-text-opacity:1;color:rgb(148 163 184/var(--tw-text-opacity,1))}.text-slate-500{--tw-text-opacity:1;color:rgb(100 116 139/var(--tw-text-opacity,1))}.text-slate-700{--tw-text-opacity:1;color:rgb(51 65 85/var(--tw-text-opacity,1))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.underline{text-decoration-line:underline}.accent-green-600{accent-color:#16a34a}.opacity-100{opacity:1}.shadow-2xl{--tw-shadow:0 25px 50px -12px rgba(0,0,0,.25);--tw-shadow-colored:0 25px 50px -12px var(--tw-shadow-color)}.shadow-2xl,.shadow-\[0_-4px_6px_-1px_rgba\(0\2c 0\2c 0\2c 0\.05\)\]{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_-4px_6px_-1px_rgba\(0\2c 0\2c 0\2c 0\.05\)\]{--tw-shadow:0 -4px 6px -1px rgba(0,0,0,.05);--tw-shadow-colored:0 -4px 6px -1px var(--tw-shadow-color)}.shadow-\[0_18px_56px_rgba\(0\2c 0\2c 0\2c \.55\)\]{--tw-shadow:0 18px 56px rgba(0,0,0,.55);--tw-shadow-colored:0 18px 56px var(--tw-shadow-color)}.shadow-\[0_18px_56px_rgba\(0\2c 0\2c 0\2c \.55\)\],.shadow-inner{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-inner{--tw-shadow:inset 0 2px 4px 0 rgba(0,0,0,.05);--tw-shadow-colored:inset 0 2px 4px 0 var(--tw-shadow-color)}.shadow-lg{--tw-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);--tw-shadow-colored:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}.shadow-lg,.shadow-md{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-md{--tw-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);--tw-shadow-colored:0 4px 6px -1px var(--tw-shadow-color),0 2px 4px -2px var(--tw-shadow-color)}.shadow-sm{--tw-shadow:0 1px 2px 0 rgba(0,0,0,.05);--tw-shadow-colored:0 1px 2px 0 var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.outline-none{outline:2px solid transparent;outline-offset:2px}.outline{outline-style:solid}.ring-2{--tw-ring-offset-shadow:var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color);--tw-ring-shadow:var(--tw-ring-inset) 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color);box-shadow:var(--tw-ring-offset-shadow),var(--tw-ring-shadow),var(--tw-shadow,0 0 #0000)}.ring-indigo-300{--tw-ring-opacity:1;--tw-ring-color:rgb(165 180 252/var(--tw-ring-opacity,1))}.filter{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.backdrop-blur-xl{--tw-backdrop-blur:blur(24px);-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-all{transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-colors{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-opacity{transition-property:opacity;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-transform{transition-property:transform;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.duration-200{transition-duration:.2s}.duration-300{transition-duration:.3s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.placeholder\:text-slate-400::-moz-placeholder{--tw-text-opacity:1;color:rgb(148 163 184/var(--tw-text-opacity,1))}.placeholder\:text-slate-400::placeholder{--tw-text-opacity:1;color:rgb(148 163 184/var(--tw-text-opacity,1))}.hover\:bg-amber-100:hover{--tw-bg-opacity:1;background-color:rgb(254 243 199/var(--tw-bg-opacity,1))}.hover\:bg-amber-200:hover{--tw-bg-opacity:1;background-color:rgb(253 230 138/var(--tw-bg-opacity,1))}.hover\:bg-amber-600:hover{--tw-bg-opacity:1;background-color:rgb(217 119 6/var(--tw-bg-opacity,1))}.hover\:bg-amber-700:hover{--tw-bg-opacity:1;background-color:rgb(180 83 9/var(--tw-bg-opacity,1))}.hover\:bg-blue-50\/50:hover{background-color:rgba(239,246,255,.5)}.hover\:bg-blue-600:hover{--tw-bg-opacity:1;background-color:rgb(37 99 235/var(--tw-bg-opacity,1))}.hover\:bg-emerald-50:hover{--tw-bg-opacity:1;background-color:rgb(236 253 245/var(--tw-bg-opacity,1))}.hover\:bg-gray-100:hover{--tw-bg-opacity:1;background-color:rgb(243 244 246/var(--tw-bg-opacity,1))}.hover\:bg-gray-200:hover{--tw-bg-opacity:1;background-color:rgb(229 231 235/var(--tw-bg-opacity,1))}.hover\:bg-gray-50:hover{--tw-bg-opacity:1;background-color:rgb(249 250 251/var(--tw-bg-opacity,1))}.hover\:bg-gray-700:hover{--tw-bg-opacity:1;background-color:rgb(55 65 81/var(--tw-bg-opacity,1))}.hover\:bg-green-200:hover{--tw-bg-opacity:1;background-color:rgb(187 247 208/var(--tw-bg-opacity,1))}.hover\:bg-green-700:hover{--tw-bg-opacity:1;background-color:rgb(21 128 61/var(--tw-bg-opacity,1))}.hover\:bg-indigo-50:hover{--tw-bg-opacity:1;background-color:rgb(238 242 255/var(--tw-bg-opacity,1))}.hover\:bg-indigo-50\/50:hover{background-color:rgba(238,242,255,.5)}.hover\:bg-indigo-700:hover{--tw-bg-opacity:1;background-color:rgb(67 56 202/var(--tw-bg-opacity,1))}.hover\:bg-purple-50:hover{--tw-bg-opacity:1;background-color:rgb(250 245 255/var(--tw-bg-opacity,1))}.hover\:bg-red-100:hover{--tw-bg-opacity:1;background-color:rgb(254 226 226/var(--tw-bg-opacity,1))}.hover\:bg-red-200:hover{--tw-bg-opacity:1;background-color:rgb(254 202 202/var(--tw-bg-opacity,1))}.hover\:bg-red-50:hover{--tw-bg-opacity:1;background-color:rgb(254 242 242/var(--tw-bg-opacity,1))}.hover\:bg-red-600:hover{--tw-bg-opacity:1;background-color:rgb(220 38 38/var(--tw-bg-opacity,1))}.hover\:bg-rose-100:hover{--tw-bg-opacity:1;background-color:rgb(255 228 230/var(--tw-bg-opacity,1))}.hover\:bg-slate-100:hover{--tw-bg-opacity:1;background-color:rgb(241 245 249/var(--tw-bg-opacity,1))}.hover\:bg-slate-700:hover{--tw-bg-opacity:1;background-color:rgb(51 65 85/var(--tw-bg-opacity,1))}.hover\:bg-white\/10:hover{background-color:hsla(0,0%,100%,.1)}.hover\:text-emerald-600:hover{--tw-text-opacity:1;color:rgb(5 150 105/var(--tw-text-opacity,1))}.hover\:text-gray-500:hover{--tw-text-opacity:1;color:rgb(107 114 128/var(--tw-text-opacity,1))}.hover\:text-gray-700:hover{--tw-text-opacity:1;color:rgb(55 65 81/var(--tw-text-opacity,1))}.hover\:text-indigo-600:hover{--tw-text-opacity:1;color:rgb(79 70 229/var(--tw-text-opacity,1))}.hover\:text-indigo-800:hover{--tw-text-opacity:1;color:rgb(55 48 163/var(--tw-text-opacity,1))}.hover\:text-red-500:hover{--tw-text-opacity:1;color:rgb(239 68 68/var(--tw-text-opacity,1))}.hover\:text-red-600:hover{--tw-text-opacity:1;color:rgb(220 38 38/var(--tw-text-opacity,1))}.hover\:text-slate-700:hover{--tw-text-opacity:1;color:rgb(51 65 85/var(--tw-text-opacity,1))}.hover\:text-white:hover{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.hover\:shadow-lg:hover{--tw-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);--tw-shadow-colored:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}.hover\:shadow-lg:hover,.hover\:shadow-md:hover{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.hover\:shadow-md:hover{--tw-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);--tw-shadow-colored:0 4px 6px -1px var(--tw-shadow-color),0 2px 4px -2px var(--tw-shadow-color)}.focus\:border-blue-400:focus{--tw-border-opacity:1;border-color:rgb(96 165 250/var(--tw-border-opacity,1))}.focus\:border-indigo-400:focus{--tw-border-opacity:1;border-color:rgb(129 140 248/var(--tw-border-opacity,1))}.focus\:border-indigo-500:focus{--tw-border-opacity:1;border-color:rgb(99 102 241/var(--tw-border-opacity,1))}.focus\:border-purple-400:focus{--tw-border-opacity:1;border-color:rgb(192 132 252/var(--tw-border-opacity,1))}.focus\:ring-2:focus{--tw-ring-offset-shadow:var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color);--tw-ring-shadow:var(--tw-ring-inset) 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color);box-shadow:var(--tw-ring-offset-shadow),var(--tw-ring-shadow),var(--tw-shadow,0 0 #0000)}.focus\:ring-blue-100:focus{--tw-ring-opacity:1;--tw-ring-color:rgb(219 234 254/var(--tw-ring-opacity,1))}.focus\:ring-indigo-100:focus{--tw-ring-opacity:1;--tw-ring-color:rgb(224 231 255/var(--tw-ring-opacity,1))}.focus\:ring-indigo-500:focus{--tw-ring-opacity:1;--tw-ring-color:rgb(99 102 241/var(--tw-ring-opacity,1))}.focus\:ring-purple-100:focus{--tw-ring-opacity:1;--tw-ring-color:rgb(243 232 255/var(--tw-ring-opacity,1))}.active\:scale-95:active{--tw-scale-x:.95;--tw-scale-y:.95;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.disabled\:cursor-not-allowed:disabled{cursor:not-allowed}.disabled\:bg-gray-100:disabled{--tw-bg-opacity:1;background-color:rgb(243 244 246/var(--tw-bg-opacity,1))}.disabled\:bg-gray-300:disabled{--tw-bg-opacity:1;background-color:rgb(209 213 219/var(--tw-bg-opacity,1))}.disabled\:bg-gray-400:disabled{--tw-bg-opacity:1;background-color:rgb(156 163 175/var(--tw-bg-opacity,1))}.disabled\:opacity-40:disabled{opacity:.4}.disabled\:opacity-50:disabled{opacity:.5}.disabled\:shadow-none:disabled{--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}@media (min-width:640px){.sm\:mr-1{margin-right:.25rem}.sm\:mt-0{margin-top:0}.sm\:block{display:block}.sm\:inline{display:inline}.sm\:h-56{height:14rem}.sm\:h-auto{height:auto}.sm\:w-1\/2{width:50%}.sm\:w-20{width:5rem}.sm\:w-auto{width:auto}.sm\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.sm\:grid-cols-\[auto_1fr\]{grid-template-columns:auto 1fr}.sm\:flex-row{flex-direction:row}.sm\:items-center{align-items:center}.sm\:justify-end{justify-content:flex-end}.sm\:justify-between{justify-content:space-between}.sm\:gap-2{gap:.5rem}.sm\:gap-4{gap:1rem}.sm\:self-auto{align-self:auto}.sm\:border-l-8{border-left-width:8px}.sm\:p-4{padding:1rem}.sm\:p-6{padding:1.5rem}.sm\:px-3{padding-left:.75rem;padding-right:.75rem}.sm\:px-4{padding-left:1rem;padding-right:1rem}.sm\:px-6{padding-left:1.5rem;padding-right:1.5rem}.sm\:py-2{padding-top:.5rem;padding-bottom:.5rem}.sm\:text-2xl{font-size:1.5rem;line-height:2rem}.sm\:text-xs{font-size:.75rem;line-height:1rem}}@media (min-width:768px){.md\:block{display:block}.md\:h-auto{height:auto}.md\:max-h-\[90vh\]{max-height:90vh}.md\:w-\[95\%\]{width:95%}.md\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.md\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.md\:grid-cols-\[180px_1fr\]{grid-template-columns:180px 1fr}.md\:flex-row{flex-direction:row}.md\:items-center{align-items:center}.md\:justify-between{justify-content:space-between}.md\:rounded-xl{border-radius:.75rem}.md\:p-4{padding:1rem}.md\:p-6{padding:1.5rem}.md\:p-8{padding:2rem}.md\:opacity-0{opacity:0}.group:hover .md\:group-hover\:opacity-100{opacity:1}}@media (min-width:1024px){.lg\:max-w-4xl{max-width:56rem}.lg\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.lg\:border-l{border-left-width:1px}.lg\:px-6{padding-left:1.5rem;padding-right:1.5rem}.lg\:pl-8{padding-left:2rem}}@media (min-width:1280px){.xl\:col-span-5{grid-column:span 5/span 5}.xl\:col-span-7{grid-column:span 7/span 7}.xl\:grid-cols-12{grid-template-columns:repeat(12,minmax(0,1fr))}}

/*
 * 檔案說明：
 * 畫面樣式表。
 * 它只控制顏色、間距、大小、排版與手機/電腦響應式外觀，不處理資料。
 * 修改後要同時看手機與桌面，確認文字沒有重疊或超出畫面。
 * 給非程式使用者的提醒：這些註解只解釋用途，不會改變程式實際執行結果。
 */
html{min-width:320px;height:100%;-webkit-text-size-adjust:100%;text-size-adjust:100%;}
  body{background:#0f172a;color:#e5e7eb;font-family:"Noto Sans TC",system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.55;min-width:320px;overflow-x:hidden;}
  .card{background:#162235;border:1px solid #334155;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.35);}
  .btn{min-height:40px;padding:7px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#e5e7eb;cursor:pointer;font-size:14px;line-height:1.25;white-space:normal;text-align:center;}
  .btn:hover{background:rgba(255,255,255,.10);}
  .btn.primary{background:#2563eb;border-color:#3b82f6;}
  .btn.primary:hover{background:#1d4ed8;}
  .btn.danger{background:#7f1d1d;border-color:#991b1b;}
  .btn.danger:hover{background:#991b1b;}
  #myvStatusBlock,#myvMaintenanceBlock{grid-column:1/-1}
  .myv-maint-card{background:#162235;border:1px solid #334155;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.35);padding:14px;margin-bottom:14px;min-width:0}
  .myv-maint-card h2{font-size:17px;line-height:1.35;font-weight:700;margin:0 0 12px;letter-spacing:0}
  .myv-maint-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(150px,240px);gap:12px;align-items:center;margin:9px 0}
  .myv-maint-row.timed{grid-template-columns:minmax(0,1fr) minmax(86px,130px) minmax(86px,120px);gap:8px}
  .myv-maint-row span{color:#a3b1c6;font-size:15px;font-weight:700;line-height:1.35;min-width:0}
  .myv-maint-row input,.myv-maint-row select{width:100%;min-width:0;background:#0b1220;color:#e5e7eb;border:1px solid rgba(148,163,184,.35);border-radius:8px;padding:9px 10px;font-size:16px}
  .myv-maint-row .btn{width:100%;font-weight:700}
  .myv-maint-msg{min-height:20px;color:#fcd34d;margin-top:8px;font-size:14px}
  .myv-log-card{padding:14px;margin-bottom:0;min-width:0}
  .myv-log-card h2{font-size:17px;line-height:1.35;font-weight:700;margin:0 0 12px;letter-spacing:0}
  .myv-log-controls{display:grid;grid-template-columns:minmax(0,1fr) minmax(88px,130px) minmax(92px,130px);gap:8px;align-items:center;margin:8px 0 10px}
  .myv-log-controls span{color:inherit;font-size:16px;font-weight:400;line-height:inherit;min-width:0}
  .myv-log-controls .btn{width:100%;min-height:42px;font-size:15px;font-weight:400}
  .myv-log-controls #myvLogToggle{font-weight:700}
  .myv-log-pre{white-space:pre-wrap;max-height:340px;overflow:auto;background:#080d16;border:1px solid #334155;border-radius:6px;padding:10px;font-size:12px;line-height:normal;color:#e5e7eb;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;overflow-wrap:anywhere}
  .myv-log-msg{min-height:20px;color:#fcd34d;margin-top:8px;font-size:14px}
  .btn.good,.btn.on{background:#16a34a;border-color:#16a34a;color:#fff}
  .btn.good:hover,.btn.on:hover{background:#15803d}
  .btn.off{background:#dc2626;border-color:#dc2626;color:#fff}
  .btn.off:hover{background:#b91c1c}
  .myv-status-card{background:#162235;border:1px solid #334155;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.35);padding:14px;margin-bottom:14px;min-width:0}
  .myv-status-card h2{font-size:17px;line-height:1.35;font-weight:700;margin:0 0 12px;letter-spacing:0}
  .status-pill-box{display:block}
  .pill{display:inline-flex;gap:6px;align-items:center;border:1px solid #334155;background:#101827;border-radius:999px;padding:6px 10px;margin:3px;font-size:13px;line-height:1.35;font-weight:400;max-width:100%;box-shadow:none}
  .pill b{color:#cbd5e1;white-space:nowrap;font-weight:700}
  .pill span:last-child{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:400}
  .ok{color:#86efac}.badText{color:#fca5a5}.warnText{color:#fcd34d}.muted{color:#94a3b8}
  .links{display:grid;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,.11)}
  .linkRow{display:grid;grid-template-columns:44px minmax(0,1fr);gap:8px;align-items:start}
  .linkRow b{color:#94a3b8;font-size:13px;padding-top:2px}
  .linkRow a{color:#93c5fd;word-break:break-all;overflow-wrap:anywhere;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;line-height:1.35}
  .myv-top-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;align-items:start}
  input,select,textarea{min-height:40px;line-height:1.35;}
  input,select,textarea,button{max-width:100%;}
  pre,code{overflow-wrap:anywhere;word-break:break-word;}
  img{max-width:100%;}
  .myv-page-header{width:min(100%,1440px);max-width:1440px!important;}
  .myv-page-header h1{font-size:28px;line-height:1.25;font-weight:800;margin:0;letter-spacing:0;color:#f8fafc;}
  #root,#myvTopGrid{width:min(100%,1440px);max-width:1440px!important;}
  ::selection{background:rgba(96,165,250,.45);color:#fff;}
  ::-webkit-scrollbar{width:8px;height:8px}
  ::-webkit-scrollbar-track{background:transparent}
  ::-webkit-scrollbar-thumb{background:rgba(255,255,255,.22);border-radius:8px}
  @media (max-width:1024px){
    .myv-page-header,#root,#myvTopGrid{max-width:100%;padding-left:12px!important;padding-right:12px!important;}
    #root,#myvTopGrid{padding-top:14px!important;padding-bottom:20px!important;}
    .card{border-radius:10px;}
    .ml-auto{margin-left:0!important;}
  }
  @media (max-width:420px){
    .pill{width:100%;justify-content:space-between;font-size:15px}
  }
  @media (max-width:640px){
    body{font-size:14px;}
    .myv-page-header,#root,#myvTopGrid{padding-left:max(10px,env(safe-area-inset-left))!important;padding-right:max(10px,env(safe-area-inset-right))!important;}
    .myv-page-header{padding-top:14px!important;padding-bottom:0!important;}
    .myv-page-header h1{font-size:22px;line-height:1.25;}
    #root,#myvTopGrid{padding-top:10px!important;padding-bottom:max(14px,env(safe-area-inset-bottom))!important;}
    .myv-top-grid{grid-template-columns:1fr;gap:10px}
    .myv-status-card,.myv-maint-card{padding:16px!important}
    .myv-status-card h2,.myv-maint-card h2{font-size:20px;margin-bottom:12px}
    .myv-maint-row{grid-template-columns:minmax(0,1fr) 136px;margin:11px 0;gap:8px}
    .myv-maint-row span{font-size:16px}
    .myv-maint-row.timed{grid-template-columns:minmax(0,1fr) 72px 72px;gap:7px}
    .myv-maint-row.timed input{padding-left:8px;padding-right:8px}
    .myv-maint-row .btn{min-height:44px;padding:8px 6px}
    .linkRow{grid-template-columns:1fr;gap:2px}
    .linkRow b{padding-top:0}
    h1{font-size:20px!important;line-height:1.25!important;}
    .card{padding:14px!important;border-radius:10px;}


    .btn{width:100%;min-height:44px;padding:9px 12px;font-size:13px;}
    .flex > .btn { width:auto !important; flex:0 0 auto; min-width:32px; min-height:36px; padding:5px 10px; }
    .flex.flex-wrap > .btn { flex:1 1 auto; min-width:0; }


    input:not([type="checkbox"]),select,textarea{width:100%;min-height:44px;font-size:16px!important;}
    .flex > select,
    .flex > input:not([type="checkbox"]) { width:auto !important; min-height:34px !important; font-size:13px !important; flex:0 0 auto; }


    .border-b.border-white\/5 {
      display: grid !important;
      grid-template-columns: 20px 36px 1fr 32px 32px 68px;
      align-items: center;
      gap: 6px;
      padding: 6px 2px;
    }
    .border-b.border-white\/5 .flex-1.min-w-0 { min-width:0; overflow:hidden; }
    .border-b.border-white\/5 .flex-1.min-w-0 .truncate { font-size:13px; }
    .border-b.border-white\/5 > select {
      width:100% !important; min-height:32px !important; font-size:12px !important;
      padding:2px 4px !important;
    }
    .border-b.border-white\/5 > .btn {
      width:100% !important; min-height:32px !important; padding:4px 0 !important;
      font-size:16px !important; text-align:center;
    }


    .flex.items-center.gap-2.flex-wrap.mb-2 > .btn { width:auto !important; flex:0 0 auto; padding:4px 10px; min-height:34px; }

    .flex{min-width:0;}
    .gap-2,.gap-3{gap:8px!important;}
    .text-lg{font-size:16px!important;line-height:1.35!important;}
    .text-sm{font-size:13px!important;line-height:1.5!important;}
    .text-xs{font-size:12px!important;line-height:1.45!important;}
    label.flex{align-items:flex-start!important;}
    .grid.grid-cols-1 { gap:8px; }
    pre{max-height:50vh!important;}
    .w-48.h-48{width:160px!important;height:160px!important;}
  }
.chanList{display:grid;gap:8px;margin-top:8px}.grpCard{border:1px solid #334155;background:#101827;border-radius:8px;overflow:hidden}.grpHead{display:flex;align-items:center;gap:8px;padding:10px 12px;cursor:pointer;user-select:none}.grpHead b{font-size:15px}.grpCount{font-size:12px;color:#a3b1c6;background:#0b1220;border:1px solid #334155;border-radius:999px;padding:1px 8px}.grpMove{margin-left:auto;display:flex;gap:4px}.grpCaret{color:#a3b1c6;margin-left:6px}.grpBody{border-top:1px solid #334155}.chRow{display:flex;align-items:center;gap:6px;padding:5px 8px;border-bottom:1px solid rgba(255,255,255,.05)}.chRow:last-child{border-bottom:0}.chChk{width:18px;height:18px;flex:0 0 auto;margin:0}.chLogo{width:26px;height:26px;flex:0 0 auto;object-fit:contain;border-radius:4px;background:#fff}.chName{flex:1 1 auto;min-width:0;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.chSel{flex:0 0 auto;width:78px;min-width:78px;font-size:12px;padding:5px 4px;border-radius:6px;background:#0b1220;color:#e5e7eb;border:1px solid #334155}.chRow .ordBtn,.grpMove .ordBtn{flex:0 0 auto;width:28px;min-height:30px;padding:0;font-size:12px;border:0;border-radius:5px;background:#334155;color:#fff;cursor:pointer}.ordBtn:disabled{opacity:.35}@media(max-width:420px){.chSel{width:64px;min-width:64px}.chRow .ordBtn,.grpMove .ordBtn{width:26px}}.chListTitle{font-size:20px}.chName .chNm{font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.chName .chId{font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}</style>
<style>.fl-filter{display:grid;grid-template-columns:minmax(0,1fr) minmax(120px,170px) auto;gap:8px;margin:2px 0 10px}.fl-filter input,.fl-filter select,.fl-filter .btn{min-height:40px}.fl-filter input,.fl-filter select{width:100%;min-width:0;background:#0b1220!important;color:#e5e7eb!important;-webkit-text-fill-color:#e5e7eb;border:1px solid #334155;border-radius:8px;padding:9px 10px;font-size:16px;line-height:1.35}.fl-filter .btn{font-size:15px}@media(max-width:520px){.fl-filter{grid-template-columns:1fr 1fr}.fl-filter input{grid-column:1/-1}}.myv-log-pre.nowrap{white-space:pre}.myv-log-pre .fl-row{display:block;padding:1px 0;border-bottom:1px solid rgba(255,255,255,.05)}.myv-log-pre .fl-ts{color:var(--muted,#9ca3af);margin-right:6px}.myv-log-pre .fl-cli{color:#67e8f9;margin-right:6px;font-size:11px}.myv-log-pre .fl-evt{font-weight:700;margin-right:6px}.myv-log-pre .fl-evt.ev-ok{color:#86efac}.myv-log-pre .fl-evt.ev-bad{color:#fca5a5}.myv-log-pre .fl-evt.ev-info{color:#93c5fd}.myv-log-pre .fl-evt.ev-warn{color:#fcd34d}.myv-log-pre .fl-detail{opacity:.85}</style>
<style>.btn.busy{position:relative;color:transparent!important;pointer-events:none}.btn.busy::after{content:"";position:absolute;inset:0;margin:auto;width:15px;height:15px;border:2px solid rgba(255,255,255,.5);border-top-color:#fff;border-radius:50%;animation:fspin .6s linear infinite}@keyframes fspin{to{transform:rotate(360deg)}}.myv-log-msg,.myv-maint-msg{transition:opacity .25s ease}.myv-log-msg.ok,.myv-maint-msg.ok{color:#86efac}.myv-log-msg.badText,.myv-maint-msg.badText{color:#fca5a5}.status-pill-box{display:flex;flex-wrap:wrap;gap:6px}#myvPageHeader{display:flex;align-items:center;justify-content:space-between;gap:12px}#myvThemeBtn{width:auto;min-height:36px;padding:6px 12px;font-size:13px;flex:0 0 auto}@media(max-width:420px){.status-pill-box .myv-status-pill{min-height:30px}}html[data-theme="light"] body{background:#eef2f7;color:#0f172a}html[data-theme="light"] .myv-status-card,html[data-theme="light"] .myv-log-card,html[data-theme="light"] .myv-maint-card,html[data-theme="light"] .grpCard,html[data-theme="light"] .card{background:#fff!important;border-color:#cbd5e1!important;color:#0f172a!important}html[data-theme="light"] .myv-log-pre,html[data-theme="light"] pre{background:#f8fafc!important;color:#0f172a!important}html[data-theme="light"] input:not([type=checkbox]),html[data-theme="light"] select,html[data-theme="light"] textarea,html[data-theme="light"] .chSel{background:#fff!important;color:#0f172a!important;border-color:#cbd5e1!important}html[data-theme="light"] .grpCount{background:#f1f5f9!important;color:#334155!important}</style>
</head>
<body class="min-h-screen">
<div id="myvPageHeader" class="myv-page-header max-w-5xl mx-auto px-4 pt-6">
  <h1>MyVideo 免登入版</h1>
</div>
<div id="myvTopGrid" class="myv-top-grid max-w-5xl mx-auto px-4 pt-3">
  <div id="myvStatusBlock"></div>
  <div id="myvMaintenanceBlock"></div>
</div>
<div id="root" class="max-w-5xl mx-auto px-4 py-6"></div>
<div id="myvLogBlock" class="max-w-5xl mx-auto px-4 pb-8"></div>
<script>window.MYVIDEO_CSRF = "";</script>
<script>// 檔案說明：
// MyVideo 免登入版前端程式。
// 它控制清單更新、頻道勾選與狀態顯示。
// 給非程式使用者的提醒：這些註解只解釋用途，不會改變程式實際執行結果。

const { useState, useEffect, useCallback, useRef } = React;
const CSRF = window.MYVIDEO_CSRF || "";
const API = (a) => "?api=" + a;
function shortServerText(text) {
  const t = String(text || "");
  if (/^\s*<!doctype|^\s*<html|<title>MyVideo 免登入版<\/title>/i.test(t)) {
    return "伺服器回傳 HTML 頁面，不是 JSON；可能是 API 路由錯誤或 PHP fatal error";
  }
  return t ? t.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim().slice(0, 180) : "";
}
async function readJsonResponse(r) {
  const text = await r.text();
  let j = null;
  try {
    j = text ? JSON.parse(text) : null;
  } catch (_) {
  }
  if (!r.ok) {
    const msg = j && (j.error || j.message) ? j.error || j.message : shortServerText(text) || "HTTP " + r.status;
    throw new Error(msg + " (HTTP " + r.status + ")");
  }
  if (!j) throw new Error(shortServerText(text) || "伺服器回應不是 JSON");
  return j;
}
let myvActiveBtn=null;document.addEventListener("click",function(e){var b=(e.target&&e.target.closest)?e.target.closest(".btn"):null;if(b)myvActiveBtn=b;},true);
function myvSetBusy(b,on){if(!b)return;if(on){b.classList.add("busy");b.disabled=true;}else{b.classList.remove("busy");b.disabled=false;}}
function myvGetCookie(n){var m=document.cookie.match(new RegExp("(?:^|; )"+n+"=([^;]*)"));return m?decodeURIComponent(m[1]):"";}
function myvSetCookie(n,v){document.cookie=n+"="+encodeURIComponent(v)+";path=/;max-age=31536000;samesite=Lax";}
function myvApplyTheme(){document.documentElement.removeAttribute("data-theme");}
function myvCycleTheme(){myvApplyTheme();}
async function jget(a) {
  var btn=myvActiveBtn;myvActiveBtn=null;myvSetBusy(btn,true);
  try {
    const r = await fetch(API(a), { cache: "no-store", headers: { "Accept": "application/json" } });
    return readJsonResponse(r);
  } finally { myvSetBusy(btn,false); }
}
async function jpost(a, body) {
  var btn=myvActiveBtn;myvActiveBtn=null;myvSetBusy(btn,true);
  try {
    const r = await fetch(API(a), {
      method: "POST",
      cache: "no-store",
      headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-Token": CSRF || "" },
      body: body ? JSON.stringify(body) : "{}"
    });
    return readJsonResponse(r);
  } finally { myvSetBusy(btn,false); }
}
function myvEsc(v) {
  return String(v == null ? "" : v).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}
async function myvCopyLink(id, asset){try{let url=location.origin+location.pathname+"?action=myvideo&id="+encodeURIComponent(id);if(asset)url+="&asset="+encodeURIComponent(asset);await navigator.clipboard.writeText(url);}catch(e){alert("複製失敗");}}
async function myvResetChannels(){if(!confirm("確定刪除並重置頻道清單？將重新從 MyVideo Android API 取得官方分類、頻道 ID 與圖標。"))return;try{await jpost("reset_channels");location.reload();}catch(e){alert("刪除重置失敗："+e.message);}}
async function myvRunRefresh() {
  const start = await jpost("refresh_start");
  let job = start.job || null;
  while (job && job.state === "running") {
    const r = await jpost("refresh_step", { batch: 5 });
    job = r && r.job ? r.job : job;
    await new Promise((resolve) => setTimeout(resolve, 120));
  }
}
function myvFormatStatusTime(v) {
  if (!v) return "無";
  if (typeof v === "number") return v > 0 ? new Date(v * 1000).toLocaleString() : "無";
  const s = String(v);
  if (/^\d+$/.test(s)) return Number(s) > 0 ? new Date(Number(s) * 1000).toLocaleString() : "無";
  const d = new Date(s);
  return Number.isNaN(d.getTime()) ? s : d.toLocaleString();
}
function myvStatusPill(label, value, cls) {
  return `<span class="pill"><b>${myvEsc(label)}</b><span class="${cls || "muted"}">${myvEsc(value)}</span></span>`;
}

let myvMaintenanceSaveTimer = null;
function myvMaintenanceMessage(text, good) {
  const el = document.getElementById("myvMaintenanceMsg");
  if (!el) return;
  el.className = good ? "myv-maint-msg ok" : "myv-maint-msg badText";
  el.textContent = text ? ((/bad|red/.test(el.className)?"\u26a0 ":"\u2713 ") + text) : "";
  if (el._myvMsgT) { clearTimeout(el._myvMsgT); el._myvMsgT = null; }
  if (text) { el._myvMsgT = setTimeout(function(){ el.textContent=""; }, /bad|red/.test(el.className)?7000:4000); }
}
function myvMaintBool(v) {
  return v === true || v === 1 || v === "1" || v === "true" || v === "on" || v === "yes";
}
function myvMaintToggle(key, active, onText, offText) {
  return `<button type="button" class="btn ${active ? "good on" : "off"}" data-myv-toggle="${myvEsc(key)}">${myvEsc(active ? onText : offText)}</button>`;
}
function myvMaintTimed(label, valueKey, enabledKey, cfg) {
  const val = cfg[valueKey] == null ? "" : cfg[valueKey];
  const on = myvMaintBool(cfg[enabledKey]);
  return `
    <div class="myv-maint-row timed">
      <span>${myvEsc(label)}</span>
      <input type="number" min="0" step="0.25" data-myv-number="${myvEsc(valueKey)}" value="${myvEsc(val)}">
      ${myvMaintToggle(enabledKey, on, "\u555f\u7528", "\u505c\u7528")}
    </div>
  `;
}
async function myvMaintenanceSave(cfg, rerender) {
  const saved = await jpost("save", cfg);
  if (!saved || !saved.ok) throw new Error(saved && saved.error || "\u5132\u5b58\u5931\u6557");
  myvMaintenanceMessage("\u5df2\u81ea\u52d5\u5132\u5b58", true);
  await myvStatusBlockRender();
  if (rerender) await myvMaintenanceBlockRender();
}
function myvMaintenanceScheduleSave(cfg) {
  if (myvMaintenanceSaveTimer) clearTimeout(myvMaintenanceSaveTimer);
  myvMaintenanceSaveTimer = setTimeout(async () => {
    try { await myvMaintenanceSave(cfg, false); }
    catch (e) { myvMaintenanceMessage("\u81ea\u52d5\u5132\u5b58\u5931\u6557\uff1a" + e.message, false); }
  }, 500);
}
async function myvMaintenanceBlockRender() {
  const root = document.getElementById("myvMaintenanceBlock");
  if (!root) return;
  try {
    const data = await jget("panel_status");
    const cfg = Object.assign({}, data.config || {});
    const mode = (cfg.deviceMode || "proxy") === "proxy" ? "proxy" : "direct";
    root.innerHTML = `
      <section class="card myv-maint-card">
        <h2>\u81ea\u52d5\u7dad\u8b77\u8a2d\u5b9a</h2>
        <div class="myv-maint-row">
          <span>\u7e3d\u958b\u95dc</span>
          ${myvMaintToggle("enabled", myvMaintBool(cfg.enabled), "\u555f\u7528\u72c0\u614b", "\u505c\u7528\u72c0\u614b")}
        </div>
        <div class="myv-maint-row">
          <span>MyVideo \u9023\u7dda\u6a21\u5f0f</span>
          <select data-myv-select="deviceMode">
            <option value="direct" ${mode === "direct" ? "selected" : ""}>\u76f4\u9023</option>
            <option value="proxy" ${mode === "proxy" ? "selected" : ""}>\u4ee3\u7406</option>
          </select>
        </div>
        <div class="myv-maint-row">
          <span>\u7dad\u8b77\u6aa2\u67e5\u51b7\u537b(\u5206\u9418)</span>
          <input type="number" min="0.1" step="0.1" data-myv-number="maintenanceCooldownMinutes" value="${myvEsc(cfg.maintenanceCooldownMinutes ?? 2)}">
        </div>
        ${myvMaintTimed("\u983b\u9053\u5217\u8868\u91cd\u53d6(\u5c0f\u6642)", "channelRefreshHours", "channelRefreshEnabled", cfg)}
        <div id="myvMaintenanceMsg" class="myv-maint-msg"></div>
      </section>
    `;
    root.querySelectorAll("[data-myv-toggle]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const key = btn.getAttribute("data-myv-toggle");
        if (!key) return;
        cfg[key] = !myvMaintBool(cfg[key]);
        myvMaintenanceMessage("\u6b63\u5728\u81ea\u52d5\u5132\u5b58...", true);
        try { await myvMaintenanceSave(cfg, true); }
        catch (e) { myvMaintenanceMessage("\u81ea\u52d5\u5132\u5b58\u5931\u6557\uff1a" + e.message, false); }
      });
    });
    root.querySelectorAll("[data-myv-select]").forEach((sel) => {
      sel.addEventListener("change", async () => {
        const key = sel.getAttribute("data-myv-select");
        if (!key) return;
        cfg[key] = sel.value;
        myvMaintenanceMessage("\u6b63\u5728\u81ea\u52d5\u5132\u5b58...", true);
        try { await myvMaintenanceSave(cfg, true); }
        catch (e) { myvMaintenanceMessage("\u81ea\u52d5\u5132\u5b58\u5931\u6557\uff1a" + e.message, false); }
      });
    });
    root.querySelectorAll("[data-myv-number]").forEach((input) => {
      const update = () => {
        const key = input.getAttribute("data-myv-number");
        if (!key) return;
        const n = Number(input.value);
        if (!Number.isFinite(n)) return;
        cfg[key] = n;
        myvMaintenanceMessage("\u6b63\u5728\u81ea\u52d5\u5132\u5b58...", true);
        myvMaintenanceScheduleSave(cfg);
      };
      input.addEventListener("input", update);
      input.addEventListener("change", update);
    });
  } catch (e) {
    root.innerHTML = '<div class="card" style="color:#fca5a5">\u81ea\u52d5\u7dad\u8b77\u8a2d\u5b9a\u8f09\u5165\u5931\u6557\uff1a' + myvEsc(e.message) + '</div>';
  }
}

function myvLogSetMessage(text, good) {
  const el = document.getElementById("myvLogMsg");
  if (!el) return;
  el.className = good ? "myv-log-msg ok" : "myv-log-msg badText";
  el.textContent = text ? ((/bad|red/.test(el.className)?"\u26a0 ":"\u2713 ") + text) : "";
  if (el._myvMsgT) { clearTimeout(el._myvMsgT); el._myvMsgT = null; }
  if (text) { el._myvMsgT = setTimeout(function(){ el.textContent=""; }, /bad|red/.test(el.className)?7000:4000); }
}

var myvLogRaw="", myvLogKw="", myvLogEv="", myvLogWrap=true;
var MYV_EV={http_flow:['網址流程','info'],proxy_blocked:['代理封鎖','bad'],play_requested:['播放請求','info'],play_redirected:['播放轉址','ok'],play_failed:['播放失敗','bad'],proxy_requested:['代理請求','info'],stream_failed:['取流失敗','bad'],stream_probe_result:['串流探測','info'],stream_explicit_probe_result:['指定探測','info'],stream_cache_probe_refreshed:['快取探測更新','info'],stream_third_party_direct_preferred:['三方直連','info'],resolve_test_requested:['解析測試請求','info'],resolve_test_finished:['解析測試完成','ok'],channels_updated:['頻道更新','ok'],channels_failed:['頻道更新失敗','bad'],config_saved:['設定已儲存','info'],settings_saved:['設定已儲存','info'],settings_save_requested:['儲存設定請求','info'],group_saved:['分組已儲存','info'],order_saved:['排序已儲存','info'],maintenance_started:['維護開始','info'],maintenance_finished:['維護完成','info'],maintenance_skipped:['維護略過','warn'],maintenance_task_started:['維護任務開始','info'],maintenance_task_finished:['維護任務完成','info'],maintenance_task_skipped:['維護任務略過','warn'],maintenance_task_checked:['維護任務檢查','info'],manual_sync_requested:['手動同步請求','info'],manual_sync_finished:['手動同步完成','info'],manual_maintenance_requested:['手動維護','info'],bootstrap_ok:['啟動完成','ok'],bootstrap_failed:['啟動失敗','bad'],cache_purged:['快取清除','info'],reset_channels_requested:['重置頻道','info'],log_cleared:['清除 Log','warn'],playlist_requested:['清單請求','info'],route_302:['轉址','ok'],route_proxy_302:['代理轉址','ok'],route_variant_302:['變體轉址','ok'],route_variant_probe_failed:['變體探測失敗','warn'],api_curl_error:['API 連線錯誤','bad'],api_invalid_json:['API JSON 錯誤','bad'],api_response_shape:['API 回應','info'],api_tls13_fallback:['TLS 回退','warn'],api_exception:['API 例外','bad'],refresh_job_done:['背景更新完成','ok'],refresh_job_refresh_job_marked_stale:['標記過期','warn'],auto_refresh_start:['自動更新開始','info'],auto_refresh_done:['自動更新完成','ok'],auto_refresh_if_needed_done:['自動更新檢查完成','info']};
function myvEvClass(ev){ if(/fail|error|invalid|missing|exception|not_found|degrad/i.test(ev)) return 'bad'; if(/skip|stale|fallback|retry|incomplete/i.test(ev)) return 'warn'; if(/ok|done|finish|success|redirect|saved|updated|repaired|refreshed|purged|touched/i.test(ev)) return 'ok'; return 'info'; }
function myvEvMeta(ev){ var m=MYV_EV[ev]; if(m) return m; return [String(ev).replace(/_/g,' '), myvEvClass(ev)]; }
function myvParseLogLine(line){
  line=line.trim(); if(!line) return null;
  if(line.charAt(0)==='{'){ try{ var o=JSON.parse(line); return {ts:o.ts||'', event:o.event||'', ip:o.ip||'', ua:o.ua||'', data:o.data||{}}; }catch(e){ return null; } }
  var m=line.match(/^\[([^\]]+)\]\s+(\S+)(?:\s+(\{[\s\S]*\}))?\s*$/);
  if(m){ var d={}; if(m[3]){ try{ d=JSON.parse(m[3]); }catch(e){ d={}; } } return {ts:m[1]||'', event:m[2]||'', ip:d.ip||'', ua:d.ua||'', data:d}; }
  return null;
}
var MYV_DET_ORDER=['task','id','idx','asset','delivery','deviceMode','reason','error','err','msg','message','path','method','http','http_code','total_time_ms','primary_ip','host','upstream_host','proxy_host','master_host','variant_host','segment_host','segment_http','height','bandwidth','count','total','failed','skipped','deleted','ok','success','status','data_type','hk_len','name','preview','url','effective_url'];
function myvFmtDetail(o){
  var d=o.data||{}, p=[], seen={ip:1,ua:1};
  MYV_DET_ORDER.forEach(function(k){
    if(!(k in d)) return; seen[k]=1; var v=d[k];
    if(v===''||v===null||v===undefined) return;
    if(k==='total_time_ms'){ p.push(v+'ms'); return; }
    if(k==='http'||k==='http_code'){ p.push('HTTP '+v); return; }
    if(k==='ok'||k==='success'){ p.push(v?'OK':'FAIL'); return; }
    if(typeof v==='boolean'){ if(v) p.push(k); return; }
    if(Array.isArray(v)){ if(v.length) p.push(k+':'+v.slice(0,6).join(',')); return; }
    if(typeof v==='object'){ try{ p.push(k+':'+JSON.stringify(v).slice(0,80)); }catch(e){} return; }
    p.push(String(v));
  });
  Object.keys(d).forEach(function(k){ if(seen[k]) return; var v=d[k]; if(v===''||v===null||v===undefined||typeof v==='object') return; if(typeof v==='boolean'){ if(v) p.push(k); return; } p.push(k+':'+String(v)); });
  var x=p.join(' · '); if(x.length>320) x=x.slice(0,320)+'…'; return x;
}
function myvLogPopulateEvents(){
  var sel=document.getElementById("myvLogEvent"); if(!sel) return; var set={};
  String(myvLogRaw||'').split(/\r?\n/).forEach(function(line){ var o=myvParseLogLine(line); if(o&&o.event) set[o.event]=1; });
  var opts=['<option value="">全部事件</option>'];
  Object.keys(set).sort().forEach(function(ev){ opts.push('<option value="'+myvEsc(ev)+'"'+(ev===myvLogEv?' selected':'')+'>'+myvEsc(myvEvMeta(ev)[0])+'</option>'); });
  sel.innerHTML=opts.join('');
}
function myvLogRenderLines(){
  var box=document.getElementById("myvLogText"); if(!box) return;
  var kw=myvLogKw.trim().toLowerCase(), ev=myvLogEv, rows=[];
  String(myvLogRaw||'').split(/\r?\n/).forEach(function(line){
    if(!line.trim()) return;
    if(kw && line.toLowerCase().indexOf(kw)<0) return;
    var o=myvParseLogLine(line);
    if(!o){ if(!ev) rows.push('<span class="fl-row fl-detail">'+myvEsc(line)+'</span>'); return; }
    if(ev && o.event!==ev) return;
    var m=myvEvMeta(o.event);
    var ts=o.ts?String(o.ts).replace('T',' ').replace(/[.+].*$/,''):'';
    var dev=myvDeviceFromUa(o.ua);
    var cli=(o.ip||dev)?('<span class="fl-cli">['+myvEsc(dev?dev+' ':'')+myvEsc(o.ip||'')+']</span>'):'';
    rows.push('<span class="fl-row"><span class="fl-ts">'+myvEsc(ts)+'</span>'+cli+'<span class="fl-evt ev-'+m[1]+'">'+myvEsc(m[0])+'</span><span class="fl-detail">'+myvEsc(myvFmtDetail(o))+'</span></span>');
  });
  box.innerHTML=rows.join('')||'<span class="fl-detail">（無符合的紀錄）</span>';
  box.classList.toggle('nowrap', !myvLogWrap);
}
function myvDeviceFromUa(ua){ ua=String(ua||'').toLowerCase(); if(!ua) return ''; if(ua.indexOf('tivimate')>=0) return 'TiviMate'; if(ua.indexOf('ott navigator')>=0) return 'OTT Navigator'; if(ua.indexOf('kodi')>=0) return 'Kodi'; if(ua.indexOf('vlc')>=0) return 'VLC'; if(ua.indexOf('lavf')>=0||ua.indexOf('ffmpeg')>=0) return 'FFmpeg'; if(ua.indexOf('android')>=0) return 'Android'; if(ua.indexOf('iphone')>=0) return 'iPhone'; if(ua.indexOf('windows')>=0) return 'Windows'; if(ua.indexOf('mac os')>=0) return 'macOS'; return ''; }
async function myvLogBlockRender() {
  const root = document.getElementById("myvLogBlock");
  if (!root) return;
  try {
    const data = await jget("panel_status");
    const cfg = data.config || {};
    const log = data.log || {};
    const enabled = myvMaintBool(cfg.logEnabled);
    const tail = String(log.tail || "").trim();
    root.innerHTML = `
      <section class="card myv-log-card">
        <h2>Log</h2>
        <div class="myv-log-controls">
          <span>記錄 Log</span>
          <button type="button" id="myvLogToggle" class="btn ${enabled ? "good on" : "off"}">${enabled ? "啟用" : "停用"}</button>
          <button type="button" id="myvLogClear" class="btn">清除 Log</button>
        </div>
        <div class="fl-filter"><input id="myvLogFilter" placeholder="篩選關鍵字 / 事件" autocomplete="off"><select id="myvLogEvent"><option value="">全部事件</option></select><button type="button" id="myvLogWrap" class="btn">換行:開</button></div>
        <pre id="myvLogText" class="myv-log-pre"></pre>
        <div id="myvLogMsg" class="myv-log-msg"></div>
      </section>
    `;
    document.getElementById("myvLogToggle")?.addEventListener("click", async () => {
      try {
        myvLogSetMessage("正在儲存 Log 開關...", true);
        const saved = await jpost("save", { logEnabled: !enabled });
        if (!saved || !saved.ok) throw new Error(saved && saved.error || "儲存失敗");
        await myvLogBlockRender();
      } catch (e) {
        myvLogSetMessage("Log 開關儲存失敗：" + e.message, false);
      }
    });
    document.getElementById("myvLogClear")?.addEventListener("click", async () => {
      try {
        myvLogSetMessage("正在清除 Log...", true);
        const r = await jpost("clear_log");
        if (!r || !r.ok) throw new Error(r && r.error || "清除失敗");
        await myvLogBlockRender();
      } catch (e) {
        myvLogSetMessage("清除 Log 失敗：" + e.message, false);
      }
    });
    myvLogRaw = tail;
    myvLogPopulateEvents();
    var _ff=document.getElementById("myvLogFilter"); if(_ff){ _ff.value=myvLogKw; _ff.addEventListener("input", function(){ myvLogKw=_ff.value; myvLogRenderLines(); }); }
    var _fe=document.getElementById("myvLogEvent"); if(_fe){ _fe.value=myvLogEv; _fe.addEventListener("change", function(){ myvLogEv=_fe.value; myvLogRenderLines(); }); }
    var _fw=document.getElementById("myvLogWrap"); if(_fw){ _fw.textContent="換行:"+(myvLogWrap?"開":"關"); _fw.addEventListener("click", function(){ myvLogWrap=!myvLogWrap; _fw.textContent="換行:"+(myvLogWrap?"開":"關"); myvLogRenderLines(); }); }
    myvLogRenderLines();
  } catch (e) {
    root.innerHTML = '<div class="card" style="color:#fca5a5">Log 載入失敗：' + myvEsc(e.message) + '</div>';
  }
}

async function myvStatusBlockRender() {
  const root = document.getElementById("myvStatusBlock");
  if (!root) return;
  try {
    const data = await jget("panel_status");
    const cfg = data.config || {};
    const st = data.status || {};
    const rt = data.runtime || {};
    const c = data.counts || {};
    const urls = data.urls || {};
    const lastMaintenance = myvFormatStatusTime(rt.last_maintenance_at || rt.last_maintenance_ts || "");
    const pills = [
      ["存取模式", "免登入", "ok"],
      ["總開關", cfg.enabled ? "啟用" : "停用", cfg.enabled ? "ok" : "badText"],
      ["輸出頻道", String(c.output || 0), c.output ? "ok" : "warnText"],
      ["頻道快取", st.channels_cached ? "存在" : "尚未建立", st.channels_cached ? "ok" : "warnText"],
      ["連線模式", (cfg.deviceMode || "proxy") === "proxy" ? "代理" : "直連", (cfg.deviceMode || "proxy") === "proxy" ? "ok" : "warnText"],
      ["圖標來源", "上游", "ok"],
      ["代理失敗", String(rt.proxy_fail_count||0) + (rt.last_proxy_error_host ? (" / " + rt.last_proxy_error_host) : ""), rt.proxy_fail_count ? "warnText" : "ok"],
      ["最後維護", lastMaintenance, rt.last_maintenance_at || rt.last_maintenance_ts ? "muted" : "warnText"],
    ];
    root.innerHTML = `
      <section class="card myv-status-card">
        <h2>資料狀態</h2>
        <div class="status-pill-box">${pills.map((p) => myvStatusPill(p[0], p[1], p[2])).join("")}</div>
        <div class="flex gap-2 flex-wrap" style="margin-top:12px"><button id="myvStatusSync" class="btn">同步頻道清單</button></div>
        <div class="links">
          ${urls.m3u ? `<div class="linkRow"><b>M3U</b><a href="${myvEsc(urls.m3u)}" target="_blank" rel="noopener">${myvEsc(urls.m3u)}</a></div>` : ""}
          ${urls.txt ? `<div class="linkRow"><b>TXT</b><a href="${myvEsc(urls.txt)}" target="_blank" rel="noopener">${myvEsc(urls.txt)}</a></div>` : ""}
        </div>
      </section>
    `;
    document.getElementById("myvStatusSync")?.addEventListener("click", async () => {
      const button = document.getElementById("myvStatusSync");
      if (button) { button.disabled = true; button.textContent = "同步中..."; }
      try { await myvRunRefresh(); location.reload(); }
      catch (e) { if (button) { button.disabled = false; button.textContent = "同步頻道清單"; } alert("同步頻道清單失敗：" + e.message); }
    });
  } catch (e) {
    root.innerHTML = '<div class="card" style="color:#fca5a5">資料狀態載入失敗：' + myvEsc(e.message) + '</div>';
  }
}
function App() {
  const [list, setList] = useState(null);
  const [saving, setSaving] = useState(false);
  const [savedAt, setSavedAt] = useState(null);
  const [error, setError] = useState("");
  const [openGroups, setOpenGroups] = useState({});
  const saveTimerRef = useRef(null);
  const reload = useCallback(async () => {
    try {
      const st = await jget("status");
    } catch (e) {
      setError("載入狀態失敗: " + e.message);
      return;
    }
    try {
      const ls = await jget("list");
      if (ls && ls.ok) setList(ls);
    } catch (e) {
      setError("載入頻道清單失敗: " + e.message);
    }
  }, []);
  useEffect(() => {
    reload();
  }, [reload]);
  const saveSettingsNow = useCallback(async (nextSettings, label = "儲存") => {
    if (saveTimerRef.current) {
      clearTimeout(saveTimerRef.current);
      saveTimerRef.current = null;
    }
    setSaving(true);
    try {
      const r = await jpost("settings_save", nextSettings);
      if (r && r.ok) {
        setSavedAt(  new Date());
        setList((ls) => ls ? { ...ls, settings: r.settings } : ls);
        return true;
      }
      setError(label + "失敗：伺服器未回傳成功狀態");
      return false;
    } catch (e) {
      setError(label + "失敗：" + e.message);
      return false;
    } finally {
      setSaving(false);
    }
  }, []);
  const scheduleSave = useCallback((nextSettings) => {
    if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
    saveTimerRef.current = setTimeout(async () => {
      await saveSettingsNow(nextSettings, "儲存");
    }, 800);
  }, [saveSettingsNow]);
  const updateSettings = useCallback((mut) => {
    setList((ls) => {
      if (!ls) return ls;
      const ns = JSON.parse(JSON.stringify(ls.settings || {}));
      mut(ns);
      scheduleSave(ns);
      return { ...ls, settings: ns };
    });
  }, [scheduleSave]);
  if (!list) return   React.createElement("div", { className: "text-slate-400" }, "載入中...");
  const s = list.settings || {};
  const channels = list.channels || [];
  const sourceGroups = channels.map((c) => c.group_default).filter(Boolean);
  const customGroups = Object.values(s.channel_group_map || {}).filter(Boolean);
  const groupOrder = Array.from(new Set([...(s.group_order || []), ...sourceGroups, ...customGroups]));
  const FIXED_GROUPS = groupOrder;
  const grouped = {};
  for (const g of groupOrder) grouped[g] = [];
  for (const c of channels) {
    const g = s.channel_group_map && s.channel_group_map[c.id] || c.group_default;
    if (!grouped[g]) grouped[g] = [];
    grouped[g].push(c);
  }
  const sortMode = ["manual", "id_then_name", "name_asc", "name_then_id"].includes(s.channel_sort_mode) ? s.channel_sort_mode : "id_asc";
  for (const g of Object.keys(grouped)) {
    if (sortMode === "manual") {
      const order = s.channel_order && s.channel_order[g] || [];
      const m = new Map(order.map((id, i) => [String(id), i]));
      grouped[g].sort((a, b) => {
        const ia = m.has(a.id) ? m.get(a.id) : 1e9;
        const ib = m.has(b.id) ? m.get(b.id) : 1e9;
        if (ia !== ib) return ia - ib;
        return (parseInt(a.id) || 0) - (parseInt(b.id) || 0);
      });
    } else if (sortMode === "name_asc") {
      grouped[g].sort((a, b) => String(a.name || "").localeCompare(String(b.name || ""), "zh-TW"));
    } else if (sortMode === "name_then_id") {
      grouped[g].sort((a, b) => {
        const nc = String(a.name || "").localeCompare(String(b.name || ""), "zh-TW");
        if (nc !== 0) return nc;
        return (parseInt(a.id) || 0) - (parseInt(b.id) || 0);
      });
    } else {
      grouped[g].sort((a, b) => {
        const da = parseInt(a.id) || 0, db = parseInt(b.id) || 0;
        if (da !== db) return da - db;
        return String(a.name || "").localeCompare(String(b.name || ""));
      });
    }
  }
  const excludeSet = new Set(s.selection_rules && s.selection_rules.exclude_channel_ids || []);
  const allGroupNames = Array.from(  new Set([...groupOrder, ...Object.keys(grouped)])).filter(Boolean);
  return   React.createElement("div", null, error &&   React.createElement("div", { className: "card p-3 mb-3 text-red-300 border-red-500/40" }, error, " ",   React.createElement("button", { className: "ml-2 underline", onClick: () => setError("") }, "×")),   (saving || savedAt) &&   React.createElement("div", { className: "text-xs text-slate-400 mb-3 text-right" }, saving ? "儲存中…" : "已自動儲存 " + savedAt.toLocaleTimeString()),    React.createElement("div", { className: "space-y-4" }, React.createElement("section", { className: "card full" }, React.createElement("div", { className: "channelHead" }, React.createElement("h2", { className: "chListTitle" }, "頻道清單"), React.createElement("button", { className: "btn", style: { width: "auto", minHeight: "34px", padding: "6px 12px", fontSize: "14px", flex: "0 0 auto", marginLeft: "auto" }, onClick: () => myvRunRefresh().then(() => location.reload()).catch((e) => alert("同步失敗：" + e.message)) }, "同步清單"), React.createElement("button", { className: "btn bad", style: { width: "auto", minHeight: "34px", padding: "6px 12px", fontSize: "14px", flex: "0 0 auto" }, onClick: myvResetChannels }, "刪除重置")), React.createElement("div", { className: "muted", style: { marginBottom: "8px" } }, "預設收合。展開可勾選匯出、選群組、▲▼ 調順序、複製連結。取消勾選則不匯出。"), React.createElement("div", { className: "chanList" }, allGroupNames.filter((g) => (grouped[g] || []).length > 0).map((g, gi, arr) =>  React.createElement("div", { key: g, className: "grpCard" },   React.createElement("div", { className: "grpHead", onClick: () => setOpenGroups((m) => ({ ...m, [g]: !m[g] })) },    React.createElement("input", { type: "checkbox", className: "chChk", onClick: (e) => e.stopPropagation(), checked: (grouped[g] || []).some((c) => !excludeSet.has(c.id)), onChange: (e) => updateSettings((ns) => { if (!ns.selection_rules) ns.selection_rules = {}; const set = new Set(ns.selection_rules.exclude_channel_ids || []); (grouped[g] || []).forEach((c) => { if (e.target.checked) set.delete(c.id); else set.add(c.id); }); ns.selection_rules.exclude_channel_ids = Array.from(set); }) }),    React.createElement("b", null, g),    React.createElement("span", { className: "grpCount" }, (grouped[g] || []).length),    React.createElement("span", { className: "grpMove" },     React.createElement("button", { className: "ordBtn", disabled: gi === 0, onClick: (e) => { e.stopPropagation(); updateSettings((ns) => { const b = arr.slice(); const t = b[gi-1]; b[gi-1]=b[gi]; b[gi]=t; ns.group_order = b; }); } }, "▲"),     React.createElement("button", { className: "ordBtn", disabled: gi === arr.length - 1, onClick: (e) => { e.stopPropagation(); updateSettings((ns) => { const b = arr.slice(); const t = b[gi+1]; b[gi+1]=b[gi]; b[gi]=t; ns.group_order = b; }); } }, "▼")),    React.createElement("span", { className: "grpCaret" }, openGroups[g] ? "▾" : "▸")),   openGroups[g] && React.createElement("div", { className: "grpBody" },    (grouped[g] || []).map((c, idx) =>     React.createElement("div", { key: c.id, className: "chRow" },      React.createElement("input", { type: "checkbox", className: "chChk", checked: !excludeSet.has(c.id), onChange: (e) => updateSettings((ns) => { if (!ns.selection_rules) ns.selection_rules = {}; const set = new Set(ns.selection_rules.exclude_channel_ids || []); if (e.target.checked) set.delete(c.id); else set.add(c.id); ns.selection_rules.exclude_channel_ids = Array.from(set); }) }),      c.logo && React.createElement("img", { src: c.logo, alt: "", className: "chLogo" }),      React.createElement("div", { className: "chName" }, React.createElement("div", { className: "chNm" }, c.name), React.createElement("div", { className: "chId" }, c.asset || "", c.id ? " / #" + c.id : "", c.status_desc ? " / " + c.status_desc : "")),      React.createElement("select", { className: "chSel", value: (s.channel_group_map && s.channel_group_map[c.id]) || c.group_default, onChange: (e) => updateSettings((ns) => { if (!ns.channel_group_map) ns.channel_group_map = {}; const v = e.target.value; if (v === c.group_default) delete ns.channel_group_map[c.id]; else ns.channel_group_map[c.id] = v; }) }, FIXED_GROUPS.map((gg) => React.createElement("option", { key: gg, value: gg }, gg))),      React.createElement("button", { className: "ordBtn", disabled: idx === 0, onClick: () => updateSettings((ns) => { if (!ns.channel_order) ns.channel_order = {}; const cur = (ns.channel_order[g] || (grouped[g] || []).map((x) => x.id)).slice(); const i = cur.indexOf(c.id); if (i <= 0) return; const t = cur[i-1]; cur[i-1]=cur[i]; cur[i]=t; ns.channel_order[g] = cur; }) }, "▲"),      React.createElement("button", { className: "ordBtn", disabled: idx === (grouped[g] || []).length - 1, onClick: () => updateSettings((ns) => { if (!ns.channel_order) ns.channel_order = {}; const cur = (ns.channel_order[g] || (grouped[g] || []).map((x) => x.id)).slice(); const i = cur.indexOf(c.id); if (i < 0 || i >= cur.length - 1) return; const t = cur[i+1]; cur[i+1]=cur[i]; cur[i]=t; ns.channel_order[g] = cur; }) }, "▼"),      React.createElement("button", { className: "ordBtn", title: "複製連結", onClick: () => myvCopyLink(c.id, c.asset) }, "⧉")))))))))); 
}
if (!window.__MYV_GTV_APP_RENDERED__) {
  window.__MYV_GTV_APP_RENDERED__ = true;
  const rootEl = document.getElementById("root");
  if (rootEl) ReactDOM.createRoot(rootEl).render(  React.createElement(App, null));
  myvStatusBlockRender();
  myvMaintenanceBlockRender();
  myvLogBlockRender();
  myvApplyTheme();
}
</script>
</body>
</html>
