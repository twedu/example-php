<?php
declare(strict_types=1);

/*
 *   http://your-host/hrtn.php?id=hbzhhd_3000
 *   http://your-host/hrtn.php?id=list
 */

const CACHE_TTL = 300;
const SIGN_SALT = 'hrtn027love021moretv2021live';

const APP_ID = 'DBhgAUd8n+YdABBo8DuquctsRhamfWwrwBFMU90dkD0=';
const APP_SECRET = 'wNbnXWH4zTakaOGhksB9X3aFscJPyn4RDfpAlZw66ZDCTcTQkmchIoMMWPmpuIhY';
const PACKAGE_ID = 'com.dangbei.oslive';
const SDK_VERSION = '2.1.5';
const DEX_VERSION = 102;
const API_VERSION = 2;

const SEED_SUID = '4dce50d7-edf3-4d0b-98ae-cc6978fe3340';
const SEED_TOKEN = 'b0g4OUI3a1pocWtXb1R1cUlYckM5N0tMTWZxdzFYWFBhVG5Sb3paSE5OaXJhMTB4QVZBd3dvMWlzL3NuSG83Uw==';
const SEED_CLIENT_IP = '223.102.240.122';

const DEVICE_ID = '3974a083ee9266fc628d71c9fda855e2';
const DEVICE_MAC = '020000000000';
const DEVICE_MODEL = 'PKG110';
const DEVICE_CHANNEL = 'hrtn_app_dangbei';
const DEVICE_APP_VERSION = '122';

$channelList = array(
    array('name' => 'CCTV1', 'id' => 'cctv1hd_3000', 'channelCode' => 'hrtn_cctv1'),
    array('name' => '湖南卫视', 'id' => 'hnwshd_3000', 'channelCode' => 'hrtn_hunantv'),
    array('name' => '湖北综合', 'id' => 'hbzhhd_3000', 'channelCode' => 'hrtn_hubeizhonghe'),
    array('name' => '湖北广电导视', 'id' => 'jshd_4000', 'channelCode' => 'hrtn_jiangsutv4k'),
    array('name' => '湖北经视', 'id' => 'hbjsjd_3000', 'channelCode' => 'hrtn_hubeijingshi'),
    array('name' => '垄上', 'id' => 'hblshd_3000', 'channelCode' => 'hrtn_longshang'),
    array('name' => '湖北公共新闻', 'id' => 'hbgghd_3000', 'channelCode' => 'hrtn_hubeiggxinwen'),
    array('name' => '湖北影视', 'id' => 'hbys_1000', 'channelCode' => 'hrtn_hubeiyingshi'),
    array('name' => '湖北教育', 'id' => 'hbjy_1000', 'channelCode' => 'hrtn_hubeijiaoyu'),
    array('name' => '湖北生活', 'id' => 'hbsh_1000', 'channelCode' => 'hrtn_hubeishenghuo'),
    array('name' => 'CCTV新闻', 'id' => 'cctv13_1000', 'channelCode' => 'hrtn_cctvxinwen'),
    array('name' => 'CCTV2', 'id' => 'cctv2hd_3000', 'channelCode' => 'hrtn_cctv2'),
    array('name' => 'CCTV3', 'id' => 'cctv3hd_3000', 'channelCode' => 'hrtn_cctv3'),
    array('name' => 'CCTV4', 'id' => 'cctv4_1000', 'channelCode' => 'hrtn_cctv4'),
    array('name' => 'CCTV5', 'id' => 'cctv5hd_3000', 'channelCode' => 'hrtn_cctv5'),
    array('name' => 'CCTV5+', 'id' => 'cctv5jhd_3000', 'channelCode' => 'hrtn_cctv5jia'),
    array('name' => 'CCTV6', 'id' => 'cctv6hd_3000', 'channelCode' => 'hrtn_cctv6'),
    array('name' => 'CCTV7', 'id' => 'cctv7hd_3000', 'channelCode' => 'hrtn_cctv7'),
    array('name' => 'CCTV8', 'id' => 'cctv8hd_3000', 'channelCode' => 'hrtn_cctv8'),
    array('name' => 'CCTV9', 'id' => 'cctv9hd_3000', 'channelCode' => 'hrtn_cctv9'),
    array('name' => 'CCTV10', 'id' => 'cctv10hd_3000', 'channelCode' => 'hrtn_cctv10'),
    array('name' => 'CCTV11', 'id' => 'cctv11_1000', 'channelCode' => 'hrtn_cctv11'),
    array('name' => 'CCTV12', 'id' => 'cctv12hd_3000', 'channelCode' => 'hrtn_cctv12'),
    array('name' => 'CCTV14', 'id' => 'cctv17hd_3000', 'channelCode' => 'hrtn_cctv14'),
    array('name' => 'CCTV音乐', 'id' => 'cctv14_1000', 'channelCode' => 'hrtn_cctvmusic'),
    array('name' => 'CCTV16', 'id' => 'cctv16hd_3000', 'channelCode' => 'hrtn_cctv16'),
    array('name' => 'CCTV17', 'id' => 'cctv14hd_3000', 'channelCode' => 'hrtn_cctv17'),
    array('name' => 'CCTVNEWS', 'id' => 'cctvnews_1000', 'channelCode' => 'hrtn_cctvnews'),
    array('name' => '湖北卫视', 'id' => 'hbwshd_3000', 'channelCode' => 'hrtn_hubeiweishi'),
    array('name' => '浙江卫视', 'id' => 'zjwshd_3000', 'channelCode' => 'hrtn_zhejiagtv'),
    array('name' => '江苏卫视', 'id' => 'jswshd_3000', 'channelCode' => 'hrtn_jiangsutv'),
    array('name' => '东方卫视', 'id' => 'dfwshd_3000', 'channelCode' => 'hrtn_dongfangtv'),
    array('name' => '北京卫视', 'id' => 'bjwshd_3000', 'channelCode' => 'hrtn_beijingtv'),
    array('name' => '深圳卫视', 'id' => 'szwshd_3000', 'channelCode' => 'hrtn_shenzhentv'),
    array('name' => '安徽卫视', 'id' => 'ahwshd_3000', 'channelCode' => 'hrtn_anhuitv'),
    array('name' => '河北卫视', 'id' => 'hebws_1000', 'channelCode' => 'hrtn_hebeitv'),
    array('name' => '海南卫视', 'id' => 'hainwshd_3000', 'channelCode' => 'hrtn_hainantv'),
    array('name' => '陕西卫视', 'id' => 'shxws_1000', 'channelCode' => 'hrtn_shanxitv'),
    array('name' => '青海卫视', 'id' => 'qhws_1000', 'channelCode' => 'hrtn_qinghaitv'),
    array('name' => '宁夏卫视', 'id' => 'nxws_1000', 'channelCode' => 'hrtn_ningxiatv'),
    array('name' => '甘肃卫视', 'id' => 'gsws_1000', 'channelCode' => 'hrtn_ganshutv'),
    array('name' => '河南卫视', 'id' => 'henwshd_3000', 'channelCode' => 'hrtn_henantv'),
    array('name' => '辽宁卫视', 'id' => 'lnwshd_3000', 'channelCode' => 'hrtn_liaoningtv'),
    array('name' => '山东卫视', 'id' => 'sdwdhd_3000', 'channelCode' => 'hrtn_shandongtv'),
    array('name' => '广东卫视', 'id' => 'gdwshd_3000', 'channelCode' => 'hrtn_guangdongtv'),
    array('name' => '四川卫视', 'id' => 'scwshd_3000', 'channelCode' => 'hrtn_sichuantv'),
    array('name' => '江西卫视', 'id' => 'jxwshd_3000', 'channelCode' => 'hrtn_jiangxitv'),
    array('name' => '天津卫视', 'id' => 'tjwshd_3000', 'channelCode' => 'hrtn_tianjintv'),
    array('name' => '山西卫视', 'id' => 'sxws_1000', 'channelCode' => 'hrtn_shanxittv'),
    array('name' => '贵州卫视', 'id' => 'gzwshd_3000', 'channelCode' => 'hrtn_guizhoutv'),
    array('name' => '吉林卫视', 'id' => 'jlwshd_3000', 'channelCode' => 'hrtn_jilintv'),
    array('name' => '云南卫视', 'id' => 'ynws_1000', 'channelCode' => 'hrtn_yunnantv'),
    array('name' => '重庆卫视', 'id' => 'cqwshd_3000', 'channelCode' => 'hrtn_chongqingtv'),
    array('name' => '广西卫视', 'id' => 'gxwshd_3000', 'channelCode' => 'hrtn_guangxitv'),
    array('name' => '黑龙江卫视', 'id' => 'hljwshd_3000', 'channelCode' => 'hrtn_heilongjtv'),
    array('name' => '内蒙古卫视', 'id' => 'nmws_1000', 'channelCode' => 'hrtn_neimenggutv'),
    array('name' => '西藏卫视', 'id' => 'xzws_1000', 'channelCode' => 'hrtn_xizangtv'),
    array('name' => '新疆卫视', 'id' => 'xjws_1000', 'channelCode' => 'hrtn_xinjiangtv'),
    array('name' => '兵团卫视', 'id' => 'btws_1000', 'channelCode' => 'hrtn_bingtuantv'),
    array('name' => '福建东南卫视', 'id' => 'dnwshd_3000', 'channelCode' => 'hrtn_fujiandongnantv'),
    array('name' => '山东教育卫视', 'id' => 'sdjyws_1000', 'channelCode' => 'hrtn_shandongjiaoyutv'),
    array('name' => '金鹰卡通', 'id' => 'jjkt_1000', 'channelCode' => 'hrtn_jinyingkatong'),
    array('name' => 'KAKU少儿', 'id' => 'kkse_1000', 'channelCode' => 'hrtn_kakushaoer'),
    array('name' => '嘉佳卡通', 'id' => 'jiajkt_1000', 'channelCode' => 'hrtn_jiajiakatong'),
    array('name' => 'BTV冬奥纪实', 'id' => 'dajs_1000', 'channelCode' => 'hrtn_btvdongaojishi'),
    array('name' => 'WHTV-1', 'id' => 'jyjs_1000', 'channelCode' => 'hrtn_jinyingjishi'),
    array('name' => '劲爆体育', 'id' => 'shjs_1000', 'channelCode' => 'hrtn_jinbaotiyu'),
    array('name' => '家庭理财', 'id' => 'jtlc_1000', 'channelCode' => 'hrtn_jiatinglicai'),
    array('name' => '中国教育1', 'id' => 'zgjy1hd_3000', 'channelCode' => 'htrn_chianeducation1'),
    array('name' => '中国教育4', 'id' => 'zgjy4_1000', 'channelCode' => 'htrn_chianeducation4'),
    array('name' => '中国气象', 'id' => 'zgqx_1000', 'channelCode' => 'hrtn_zhongguoqixiang'),
    array('name' => '中国交通频道', 'id' => 'jyjs_1000', 'channelCode' => 'htn_epg_zhongguojiaotong'),
);

$channelMap = buildChannelMap($channelList);

main($channelMap, $channelList);

function main(array $channelMap, array $channelList): void
{
    $id = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
    if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
        fail(400, 'missing or invalid id');
    }

    if ($id === 'list') {
        echoChannelList($channelList);
        return;
    }

    $channelCode = resolveChannelCode($id, $channelMap);
    if ($channelCode === null) {
        fail(404, 'unknown id');
    }

    $cached = readUrlCache($id);
    if ($cached !== null) {
        redirectTo($cached);
    }

    $auth = requestAuth();
    $videoUri = requestVideoUri($channelCode, $auth);
    $playUrl = decodeVideoUri($videoUri);

    if (!is_string($playUrl) || !preg_match('#^https?://#i', $playUrl)) {
        fail(502, 'invalid play url');
    }

    writeUrlCache($id, $playUrl);
    redirectTo($playUrl);
}

function resolveChannelCode(string $id, array $channelMap): ?string
{
    if (isset($channelMap[$id])) {
        return $channelMap[$id];
    }

    if (strpos($id, 'hrtn_') === 0 || strpos($id, 'htrn_') === 0 || strpos($id, 'htn_') === 0) {
        return $id;
    }

    return null;
}

function buildChannelMap(array $channelList): array
{
    $map = array();
    foreach ($channelList as $item) {
        if (empty($item['id']) || empty($item['channelCode'])) {
            continue;
        }
        if (!isset($map[$item['id']])) {
            $map[$item['id']] = $item['channelCode'];
        }
    }

    return $map;
}

function echoChannelList(array $channelList): void
{
    header('Content-Type: text/plain; charset=utf-8');

    $baseUrl = publicScriptUrl();
    foreach ($channelList as $item) {
        if (empty($item['name']) || empty($item['id'])) {
            continue;
        }
        echo $item['name'] . ',' . $baseUrl . '?id=' . rawurlencode($item['id']) . "\n";
    }
}

function publicScriptUrl(): string
{
    $https = isset($_SERVER['HTTPS']) ? strtolower((string) $_SERVER['HTTPS']) : '';
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '' ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
    $script = isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '' ? (string) $_SERVER['SCRIPT_NAME'] : '/hrtn.php';

    return $scheme . '://' . $host . $script;
}

function requestAuth(): array
{
    $seed = readAuthCache();
    $payload = buildCommonPayload(array(), $seed);
    $body = wrapPayload($payload);

    $response = postJson('http://auapi.hrtn.net/sdkconfig/auth', $body);
    if (!isset($response['status']) || (int) $response['status'] !== 200 || empty($response['data'])) {
        fail(502, 'auth failed');
    }

    $data = $response['data'];
    $auth = array(
        'suid' => isset($data['suid']) ? (string) $data['suid'] : SEED_SUID,
        'token' => isset($data['token']) ? (string) $data['token'] : SEED_TOKEN,
        'currClientIp' => isset($data['currClientIp']) ? (string) $data['currClientIp'] : SEED_CLIENT_IP,
    );
    writeAuthCache($auth);

    return $auth;
}

function requestVideoUri(string $channelCode, array $auth): string
{
    $payload = buildCommonPayload(array(
        'cdnCode' => 'tencent',
        'hCode' => 'h265',
        'channelCode' => $channelCode,
    ), $auth);

    $response = postJson('http://liveapi.hrtn.net/live/channel/streams', wrapPayload($payload));
    if (!isset($response['status']) || (int) $response['status'] !== 200 || empty($response['data'])) {
        fail(502, 'stream request failed');
    }

    $uris = isset($response['data']['channelLiveStreamUris']) && is_array($response['data']['channelLiveStreamUris'])
        ? $response['data']['channelLiveStreamUris']
        : array();

    $fallback = null;
    foreach ($uris as $item) {
        if (!is_array($item) || empty($item['videoUri'])) {
            continue;
        }
        if ($fallback === null) {
            $fallback = (string) $item['videoUri'];
        }
        if (isset($item['hCode']) && (string) $item['hCode'] === 'h265') {
            return (string) $item['videoUri'];
        }
    }

    if ($fallback !== null) {
        return $fallback;
    }

    fail(502, 'empty video uri');
}

function buildCommonPayload(array $prefix, array $auth): array
{
    $payload = $prefix;
    $payload['appId'] = APP_ID;
    $payload['appSecret'] = APP_SECRET;
    $payload['packageId'] = PACKAGE_ID;
    $payload['suid'] = isset($auth['suid']) ? (string) $auth['suid'] : SEED_SUID;
    $payload['token'] = isset($auth['token']) ? (string) $auth['token'] : SEED_TOKEN;
    $payload['currClientIp'] = isset($auth['currClientIp']) ? (string) $auth['currClientIp'] : SEED_CLIENT_IP;
    $payload['user'] = array(
        'userId' => DEVICE_ID,
        'userMemberType' => '',
    );
    $payload['device'] = array(
        'mac' => DEVICE_MAC,
        'ip' => '',
        'model' => DEVICE_MODEL,
        'channel' => DEVICE_CHANNEL,
        'appVersion' => DEVICE_APP_VERSION,
        'os' => '0',
        'deviceId' => '',
        'wiredMac' => DEVICE_MAC,
        'wifiMac' => '',
        'btMac' => '',
        'sn' => '',
    );
    $payload['apiVersion'] = API_VERSION;
    $payload['sdkVersion'] = SDK_VERSION;
    $payload['dexVersion'] = DEX_VERSION;

    return $payload;
}

function wrapPayload(array $payload): array
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fail(500, 'json encode failed');
    }

    $data = base64_encode($json);
    return array(
        'data' => $data,
        'md5' => strtoupper(md5($data . SIGN_SALT)),
    );
}

function decodeVideoUri(string $videoUri): string
{
    $shifted = caesarShift($videoUri, -3);
    $decoded = base64_decode($shifted, true);
    if ($decoded === false) {
        fail(502, 'video uri decode failed');
    }

    return $decoded;
}

function caesarShift(string $value, int $shift): string
{
    $out = '';
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $ord = ord($value[$i]);
        if ($ord >= 65 && $ord <= 90) {
            $out .= chr((($ord - 65 + $shift + 2600) % 26) + 65);
        } elseif ($ord >= 97 && $ord <= 122) {
            $out .= chr((($ord - 97 + $shift + 2600) % 26) + 97);
        } else {
            $out .= $value[$i];
        }
    }

    return $out;
}

function postJson(string $url, array $body): array
{
    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fail(500, 'json encode failed');
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nUser-Agent: okhttp/3.12.0\r\n",
            'content' => $json,
            'timeout' => 12,
            'ignore_errors' => true,
        ),
    ));

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || $raw === '') {
        fail(502, 'http request failed');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fail(502, 'invalid json response');
    }

    return $decoded;
}

function cacheDir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'hrtn_cache';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fail(500, 'cache dir create failed');
    }

    return $dir;
}

function cacheFileForId(string $id): string
{
    return cacheDir() . DIRECTORY_SEPARATOR . 'url_' . sha1($id) . '.json';
}

function authCacheFile(): string
{
    return cacheDir() . DIRECTORY_SEPARATOR . 'auth.json';
}

function readUrlCache(string $id): ?string
{
    $file = cacheFileForId($id);
    if (!is_file($file)) {
        return null;
    }

    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || empty($data['url']) || empty($data['expiresAt'])) {
        return null;
    }

    if ((int) $data['expiresAt'] <= time()) {
        return null;
    }

    return (string) $data['url'];
}

function writeUrlCache(string $id, string $url): void
{
    $data = array(
        'url' => $url,
        'expiresAt' => time() + CACHE_TTL,
    );
    @file_put_contents(cacheFileForId($id), json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function readAuthCache(): array
{
    $file = authCacheFile();
    if (!is_file($file)) {
        return array(
            'suid' => SEED_SUID,
            'token' => SEED_TOKEN,
            'currClientIp' => SEED_CLIENT_IP,
        );
    }

    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || empty($data['suid']) || empty($data['token'])) {
        return array(
            'suid' => SEED_SUID,
            'token' => SEED_TOKEN,
            'currClientIp' => SEED_CLIENT_IP,
        );
    }

    return array(
        'suid' => (string) $data['suid'],
        'token' => (string) $data['token'],
        'currClientIp' => isset($data['currClientIp']) ? (string) $data['currClientIp'] : SEED_CLIENT_IP,
    );
}

function writeAuthCache(array $auth): void
{
    @file_put_contents(authCacheFile(), json_encode($auth, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function redirectTo(string $url): void
{
    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
    exit;
}

function fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}
