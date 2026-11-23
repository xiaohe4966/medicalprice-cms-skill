<?php
/**
 * 公共引导: 定位 CMS 项目根目录 + 建立数据库连接。
 *
 * 根目录解析优先级:
 *   1. 环境变量 MP_CMS_ROOT
 *   2. 从本文件所在目录逐级向上找同时含 .env 与 application/ 的目录
 *   3. 退回本文件的上两级目录 (scripts/xxx/ 的传统布局)
 */

function mp_root(): string
{
    $env = getenv('MP_CMS_ROOT');
    if ($env && is_dir(rtrim($env, '/') . '/application')) {
        return rtrim($env, '/');
    }
    $dir = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_file($dir . '/.env') && is_dir($dir . '/application')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) break;
        $dir = $parent;
    }
    return dirname(dirname(__DIR__));
}

/** 读取 .env 里的自定义键 (如 ftp.host) */
function mp_env(string $section, string $key, $default = null)
{
    $root = mp_root();
    $envFile = $root . '/.env';
    if (!is_file($envFile)) return $default;
    $env = parse_ini_file($envFile, true);
    return $env[$section][$key] ?? $default;
}

/** 演练模式下的假语句: 记录写操作但不落库 */
class MpDryStatement
{
    private array $params = [];

    public function __construct(private string $sql, private MpDb $owner)
    {
    }

    public function execute($params = null): bool
    {
        $this->params = $params ?: [];
        $this->owner->recordWrite($this->sql, $this->params);
        return true;
    }

    public function fetch($mode = null)
    {
        return false;
    }

    public function fetchAll($mode = null, $args = null)
    {
        return [];
    }

    public function rowCount(): int
    {
        return 0;
    }
}

/** PDO 包装: 支持只读的演练模式 */
class MpDb
{
    public PDO $pdo;
    public bool $dryRun = false;
    public array $writes = [];
    private int $fakeId = 900000;

    public function __construct(PDO $pdo, bool $dryRun = false)
    {
        $this->pdo = $pdo;
        $this->dryRun = $dryRun;
    }

    public function recordWrite(string $sql, array $params): void
    {
        $this->writes[] = ['sql' => $sql, 'params' => $params];
    }

    public function prepare(string $sql)
    {
        if (!$this->dryRun || preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)/i', $sql)) {
            return $this->pdo->prepare($sql);
        }
        return new MpDryStatement($sql, $this);
    }

    public function query(string $sql)
    {
        return $this->pdo->query($sql);
    }

    public function exec(string $sql)
    {
        if ($this->dryRun) {
            $this->recordWrite($sql, []);
            return 0;
        }
        return $this->pdo->exec($sql);
    }

    public function lastInsertId()
    {
        return $this->dryRun ? ++$this->fakeId : $this->pdo->lastInsertId();
    }
}

/** 建立数据库连接 (演练模式只读) */
function mp_db(bool $dryRun = false): MpDb
{
    $root = mp_root();
    $envFile = $root . '/.env';
    if (!is_file($envFile)) {
        throw new RuntimeException("找不到 {$envFile}，请设置 MP_CMS_ROOT 指向 CMS 项目根目录");
    }
    $env = parse_ini_file($envFile, true);
    $db = $env['database'] ?? [];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $db['hostname'] ?? '127.0.0.1',
        $db['hostport'] ?: 3306,
        $db['database'] ?? ''
    );
    $pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return new MpDb($pdo, $dryRun);
}
