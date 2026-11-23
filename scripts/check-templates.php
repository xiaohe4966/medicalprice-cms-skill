<?php
/**
 * 模板语法体检
 *
 * 用 TP5 的模板编译器把模板编译一遍 (不执行), 提前发现语法错误 / include 目标缺失.
 *
 * 用法:
 *   php scripts/medicalprice/check-templates.php            # 在 CMS 项目根目录执行
 *   MP_CMS_ROOT=/path/to/cms php /path/to/skill/scripts/check-templates.php
 *
 * 注意: PHP 8.5 下 TP5 5.0.28 有若干 E_DEPRECATED (如 (boolean) 转型、
 * ReflectionMethod::setAccessible()), TP5 会把 notice 抛成异常, 所以这里
 * 接管错误处理: 屏蔽 deprecation, 其余错误仍按 TP5 语义抛异常.
 */
require __DIR__ . '/_bootstrap.php';

$CMS_ROOT = mp_root();
define('APP_PATH', $CMS_ROOT . '/application/');
require $CMS_ROOT . '/thinkphp/base.php';

set_error_handler(function ($no, $str, $file = '', $line = 0) {
    if ($no & (E_DEPRECATED | E_USER_DEPRECATED)) {
        return true;
    }
    \think\Error::appError($no, $str, $file, $line);
    return true;
});

\think\App::initCommon();

$groups = [
    '前台' => [
        'view_path' => APP_PATH . 'multilingualcms/view/',
        'files' => array_merge(
            glob(APP_PATH . 'multilingualcms/view/index/medical*.html'),
            glob(APP_PATH . 'multilingualcms/view/index/hospital*.html'),
            glob(APP_PATH . 'multilingualcms/view/index/department*.html')
        ),
    ],
    '后台' => [
        'view_path' => APP_PATH . 'admin/view/',
        'files' => array_merge(
            glob(APP_PATH . 'admin/view/multilingualcms/entity/*.html'),
            glob(APP_PATH . 'admin/view/multilingualcms/hospital/*.html'),
            glob(APP_PATH . 'admin/view/multilingualcms/department/*.html'),
            glob(APP_PATH . 'admin/view/multilingualcms/medicalitem/*.html'),
            glob(APP_PATH . 'admin/view/multilingualcms/medicalprice/*.html')
        ),
    ],
];

$cacheDir = RUNTIME_PATH . 'temp';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$failed = 0;
$total = 0;
foreach ($groups as $groupName => $group) {
    $config = \think\Config::get('template');
    $config['view_path'] = $group['view_path'];
    $template = new \think\Template($config);

    $reflection = new ReflectionClass($template);
    $compiler = $reflection->getMethod('compiler');
    if (PHP_VERSION_ID < 80100) {
        $compiler->setAccessible(true);
    }

    echo "== {$groupName} ==\n";
    foreach ($group['files'] as $file) {
        $total++;
        $content = file_get_contents($file);
        $cacheFile = $cacheDir . '/check_' . md5($file) . '.php';
        try {
            $compiler->invokeArgs($template, [&$content, $cacheFile]);
            printf("OK   %s\n", str_replace(APP_PATH, '', $file));
        } catch (\Throwable $e) {
            $failed++;
            printf("FAIL %s\n     %s\n", str_replace(APP_PATH, '', $file), $e->getMessage());
        }
    }
}

echo "\n共 {$total} 个模板, 失败 {$failed} 个\n";
exit($failed > 0 ? 1 : 0);
