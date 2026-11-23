<?php
// Sync helper: compares / deploys the local working tree with the online server over FTP.
// The server blocks the PASV port range, so all transfers use curl's EPSV mode.
// Parallel requests are issued through curl_multi to keep the round-trips short.
//
//   php scripts/medicalprice/ftp-sync.php check                -> compare local vs remote (name + size + md5)
//   php scripts/medicalprice/ftp-sync.php list                 -> print remote file list
//   php scripts/medicalprice/ftp-sync.php upload <file> ...    -> upload files, verify md5
require __DIR__ . '/_bootstrap.php';

$root = mp_root();
// FTP 凭据放 CMS 项目的 .env [ftp] 段, 或用环境变量覆盖; 不要把密码写进代码
$host = getenv('MP_FTP_HOST') ?: (mp_env('ftp', 'host') ?: '');
$port = (int)(getenv('MP_FTP_PORT') ?: (mp_env('ftp', 'port') ?: 21));
$user = getenv('MP_FTP_USER') ?: (mp_env('ftp', 'username') ?: '');
$pass = getenv('MP_FTP_PASS') ?: (mp_env('ftp', 'password') ?: '');
if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "缺少 FTP 配置, 请在 .env 里加 [ftp] 段 (host/username/password) 或设 MP_FTP_* 环境变量\n");
    exit(1);
}

const TARGETS = [
    'application/admin/controller/multilingualcms',
    'application/admin/model/multilingualcms',
    'application/admin/view/multilingualcms',
    'application/admin/lang/zh-cn/multilingualcms',
    'application/common/library/Tp.php',
    'application/common/controller/Backend.php',
    'application/multilingualcms',
    'addons/multilingualcms/library',
    'addons/multilingualcms/application',
    'addons/multilingualcms/helper.php',
    'addons/multilingualcms/config.php',
    'public/assets/js/backend/multilingualcms',
    'public/static/medicalprice',
];

function ftpUrl($host, $port, $path)
{
    return "ftp://{$host}:{$port}/" . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
}

/**
 * Run many curl requests in parallel.
 * $requests: [key => ['url'=>, 'out'=>(optional temp file)]]
 * returns [key => ['body'=>, 'head'=>, 'code'=>]]
 */
function curlMulti($requests, $concurrency = 10)
{
    $results = [];
    $queue = array_keys($requests);
    while ($queue) {
        $batch = array_splice($queue, 0, $concurrency);
        $mh = curl_multi_init();
        $handles = [];
        foreach ($batch as $key) {
            $req = $requests[$key];
            $ch = curl_init($req['url']);
            $opts = [
                CURLOPT_USERPWD => $GLOBALS['user'] . ':' . $GLOBALS['pass'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_NOBODY => !empty($req['head']),
                CURLOPT_FTP_USE_EPSV => true,
                CURLOPT_FTP_SKIP_PASV_IP => true,
            ];
            if (!empty($req['out'])) {
                $opts[CURLOPT_FILE] = fopen($req['out'], 'wb');
            }
            curl_setopt_array($ch, $opts);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            $results[$key] = ['body' => $body, 'code' => $code];
        }
        curl_multi_close($mh);
    }
    return $results;
}

function remoteFileMap($targets)
{
    global $host, $port, $user, $pass;
    $files = [];
    $dirs = [];
    foreach ($targets as $t) {
        $t = rtrim($t, '/');
        if (is_file($root . '/' . $t)) { $files[$t] = null; continue; }
        $dirs[] = $t;
    }
    while ($dirs) {
        $dir = array_shift($dirs);
        $res = curlMulti([$dir => ['url' => ftpUrl($host, $port, $dir)]]);
        $body = (string)($res[$dir]['body'] ?? '');
        $names = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '' || $line === '.' || $line === '..') continue;
            $names[] = $line;
        }
        if (!$names) { fwrite(STDERR, "REMOTE DIR MISSING/EMPTY: {$dir}\n"); continue; }
        $req = [];
        foreach ($names as $n) $req[$dir . '/' . $n] = ['url' => ftpUrl($host, $port, $dir . '/' . $n), 'head' => true];
        $res = curlMulti($req, 3);
        foreach ($res as $path => $r) {
            if (preg_match('/^\s*drwx/', (string)$r['body'])) {
                $dirs[] = $path;
            } else {
                $size = null;
                if (preg_match('/Content-Length:\s*(\d+)/i', (string)$r['body'], $m)) $size = (int)$m[1];
                $files[$path] = $size;
            }
        }
    }
    return $files;
}

function localFileMap($targets)
{
    global $root;
    $result = [];
    $skip = ['runtime', 'uploads', 'node_modules', 'vendor', '.git'];
    foreach ($targets as $t) {
        $t = rtrim($t, '/');
        $full = $root . '/' . $t;
        if (is_file($full)) { $result[$t] = filesize($full); continue; }
        if (!is_dir($full)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = substr($f->getPathname(), strlen($root) + 1);
            if (in_array(explode('/', $rel)[0], $skip)) continue;
            $result[$rel] = $f->getSize();
        }
    }
    return $result;
}

function remoteMd5Map($paths)
{
    global $host, $port;
    $tmpDir = sys_get_temp_dir() . '/ftpdl';
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
    $req = [];
    foreach ($paths as $i => $p) {
        $req[$i] = ['url' => ftpUrl($host, $port, $p), 'out' => "{$tmpDir}/{$i}.bin"];
    }
    $res = curlMulti($req, 3);
    $map = [];
    foreach ($paths as $i => $p) {
        $f = "{$tmpDir}/{$i}.bin";
        $map[$p] = (is_file($f) && filesize($f) > 0) ? md5_file($f) : '';
        @unlink($f);
    }
    return $map;
}

$mode = $argv[1] ?? 'check';
$args = array_slice($argv, 2);

if ($mode === 'upload') {
    $ok = 0; $fail = [];
    foreach ($args as $f) {
        $f = ltrim($f, '/');
        $local = $root . '/' . $f;
        if (!is_file($local)) { $fail[] = "$f: 本地文件不存在"; continue; }
        $url = ftpUrl($host, $port, $f);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => fopen($local, 'rb'),
            CURLOPT_INFILESIZE => filesize($local),
            CURLOPT_USERPWD => "{$user}:{$pass}",
            CURLOPT_TIMEOUT => 180,
            CURLOPT_FTP_USE_EPSV => true,
            CURLOPT_FTP_CREATE_MISSING_DIRS => true,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        if ($body === false) { $fail[] = "$f: 上传失败 {$err}"; continue; }
        $tmp = tempnam(sys_get_temp_dir(), 'ftp');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_USERPWD => "{$user}:{$pass}", CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_FTP_USE_EPSV => true]);
        $down = curl_exec($ch);
        file_put_contents($tmp, (string)$down);
        $rmd5 = filesize($tmp) > 0 ? md5_file($tmp) : '';
        @unlink($tmp);
        $lmd5 = md5_file($local);
        if ($rmd5 === $lmd5) { echo "OK   {$f}\n"; $ok++; }
        else { echo "FAIL {$f} local={$lmd5} remote={$rmd5}\n"; $fail[] = "$f: md5 不一致"; }
    }
    echo "\n已上传并校验 {$ok} 个文件，失败 " . count($fail) . " 个\n";
    foreach ($fail as $x) echo "  {$x}\n";
    exit($fail ? 1 : 0);
}

$lmap = localFileMap(TARGETS);
if ($mode === 'list') {
    foreach (remoteFileMap(TARGETS) as $p2 => $sz) echo $p2 . "\t" . ($sz ?? '-') . "\n";
    exit(0);
}

// check: download every local file from the server and compare md5
$paths = array_keys($lmap);
$tmpDir = sys_get_temp_dir() . '/ftpdl';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
$req = [];
foreach ($paths as $i => $p2) $req[$i] = ['url' => ftpUrl($host, $port, $p2), 'out' => "{$tmpDir}/{$i}.bin"];
$res = curlMulti($req, 4);
$missing = []; $diff = []; $same = 0;
foreach ($paths as $i => $p2) {
    $f = "{$tmpDir}/{$i}.bin";
    if (!is_file($f) || filesize($f) === 0) { $missing[] = $p2; @unlink($f); continue; }
    $rmd5 = md5_file($f);
    @unlink($f);
    $lmd5 = md5_file($root . '/' . $p2);
    if ($rmd5 === $lmd5) $same++; else $diff[] = [$p2, "本地={$lmd5} 线上={$rmd5}"];
}
echo "本地文件 " . count($paths) . "\n";
echo "完全一致 {$same}\n";
echo "内容不同 (" . count($diff) . "):\n";
foreach ($diff as $d) echo "  {$d[0]}  {$d[1]}\n";
echo "线上缺失 (" . count($missing) . "):\n";
foreach ($missing as $m) echo "  {$m}\n";
exit(0);
