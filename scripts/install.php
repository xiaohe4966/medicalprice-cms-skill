<?php
/**
 * 醫療價格（MedicalPrice）内容类型安装脚本
 *
 * 用法:
 *   php scripts/medicalprice/install.php          # 安装/更新（幂等）
 *   php scripts/medicalprice/install.php --backup # 只备份
 *
 * 做的事:
 *   1. 备份将被修改的表到 scripts/medicalprice/backup/
 *   2. 建 4 张新表（医院 / 科室 / 醫療項目 / 價格行）
 *   3. 注册扩展字段（fa_multilingualcms_fields），后台表单自动渲染
 *   4. 建 cht 栏目（fa_multilingualcms_cate）
 *   5. 建后台菜单权限（fa_auth_rule）
 *   6. 注册翻译字段表（fa_fanyi_tables）
 */

require __DIR__ . '/_bootstrap.php';

$root = mp_root();
$pdo = mp_db();
if (!in_array('cht', $langList, true)) {
    array_unshift($langList, 'cht');
}
echo "目标语言: " . implode(', ', $langList) . "\n";

$TABLES = [
    'hospital'    => 'fa_multilingualcms_hospital',
    'department'  => 'fa_multilingualcms_department',
    'medicalitem' => 'fa_multilingualcms_medicalitem',
    'medicalprice' => 'fa_multilingualcms_medicalprice',
    'article'     => 'fa_multilingualcms_article',
    'news'        => 'fa_multilingualcms_news',
    'page'        => 'fa_multilingualcms_page',
];

/* ------------------------------------------------------------------ 备份 */

function dumpTable(PDO $pdo, string $table): string
{
    $sql = "\n-- ----------------------------\n-- Table: {$table}\n-- ----------------------------\n";
    $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n" . $row['Create Table'] . ";\n";
    $count = (int)$pdo->query("SELECT COUNT(*) c FROM `{$table}`")->fetch()['c'];
    if ($count > 0) {
        $data = $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
        $cols = array_keys($data[0]);
        $colList = '`' . implode('`,`', $cols) . '`';
        foreach ($data as $r) {
            $vals = [];
            foreach ($cols as $c) {
                $v = $r[$c];
                $vals[] = $v === null ? 'NULL' : $pdo->quote($v);
            }
            $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(',', $vals) . ");\n";
        }
    }
    $sql .= "-- rows: {$count}\n";
    return $sql;
}

$backupDir = $root . '/scripts/medicalprice/backup';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}
$stamp = date('Ymd_His');
$backupFile = $backupDir . "/cms_{$stamp}.sql";

$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$backupTargets = [
    'fa_multilingualcms_cate',
    'fa_multilingualcms_fields',
    'fa_fanyi_tables',
    'fa_auth_rule',
];
foreach ($TABLES as $t) {
    if (in_array($t, $existing, true)) {
        $backupTargets[] = $t;
    }
}
$backup = "-- CMS 备份 {$stamp}\n-- 表: " . implode(', ', $backupTargets) . "\n";
foreach ($backupTargets as $t) {
    $backup .= dumpTable($pdo, $t);
}
file_put_contents($backupFile, $backup);
echo "备份完成: {$backupFile} (" . number_format(strlen($backup)) . " bytes)\n";

if ($backupOnly) {
    exit(0);
}

/* ------------------------------------------------------------ 建表 */

$core = "  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `lang` char(10) DEFAULT '{$LANG}' COMMENT '语言版本',
  `copy_id` int(10) unsigned DEFAULT NULL COMMENT '复制ID',
  `cate_id` int(10) unsigned DEFAULT NULL COMMENT '栏目id',
  `title` varchar(255) DEFAULT '' COMMENT '标题',
  `btitle` varchar(255) DEFAULT NULL COMMENT 'SEO标题',
  `hotdata` set('1','2','3','4') DEFAULT '' COMMENT '推荐',
  `diyname` varchar(128) DEFAULT NULL COMMENT '自定义路由',
  `image` varchar(255) DEFAULT '' COMMENT '缩略图',
  `images` text COMMENT '组图',
  `abstract` text COMMENT '摘要',
  `tags` varchar(255) DEFAULT '' COMMENT 'TAG',
  `weigh` int(10) DEFAULT NULL COMMENT '权重',
  `views` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '浏览次数',
  `memo` varchar(100) DEFAULT '' COMMENT '备注',
  `content` longtext COMMENT '内容',
  `switch` tinyint(1) NOT NULL DEFAULT '1' COMMENT '显示开关',
  `createtime` bigint(16) DEFAULT NULL COMMENT '创建时间',
  `updatetime` bigint(16) DEFAULT NULL COMMENT '更新时间',
  `publishtime` bigint(16) DEFAULT NULL COMMENT '发布时间',
  `deletetime` bigint(16) DEFAULT NULL COMMENT '删除时间',
  `seotitle` text COMMENT 'SEO标题',
  `keywords` text COMMENT '关键字',
  `description` text COMMENT '描述',
  `fanyi_switch` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否翻译',
  `fanyi_time` bigint(16) DEFAULT NULL COMMENT '翻译时间',
  `fanyi_sha1` char(64) DEFAULT NULL COMMENT '语言sha1',
  `fanyi_num` int(11) DEFAULT '0' COMMENT '翻译次数',
  `no_detail_switch` tinyint(1) unsigned DEFAULT '0' COMMENT '没有详情页',
  `no_follow_switch` tinyint(1) unsigned DEFAULT '0' COMMENT '不跟踪页面上的链接',
  `no_index_switch` tinyint(1) unsigned DEFAULT '0' COMMENT '完全禁止收录'";

$biz = [
    'hospital' => "  `code` varchar(64) DEFAULT NULL COMMENT '短码',
  `ownership` varchar(32) DEFAULT NULL COMMENT '性质',
  `link` varchar(255) DEFAULT NULL COMMENT '官网',
  `address` varchar(255) DEFAULT NULL COMMENT '地址',
  `phone` varchar(64) DEFAULT NULL COMMENT '电话'",
    'department' => "  `code` varchar(64) DEFAULT NULL COMMENT '短码',
  `icon` varchar(255) DEFAULT NULL COMMENT '图标'",
    'medicalitem' => "  `department_id` int(10) unsigned DEFAULT NULL COMMENT '科室id',
  `code` varchar(64) DEFAULT NULL COMMENT '短码',
  `unit` varchar(64) DEFAULT NULL COMMENT '计价单位'",
    'medicalprice' => "  `item_id` int(10) unsigned DEFAULT NULL COMMENT '項目id',
  `hospital_id` int(10) unsigned DEFAULT NULL COMMENT '医院id',
  `slot` varchar(32) DEFAULT NULL COMMENT '时段/房型',
  `price` varchar(64) DEFAULT NULL COMMENT '价格',
  `price_label` varchar(64) DEFAULT NULL COMMENT '价格标签',
  `display_price` varchar(64) DEFAULT NULL COMMENT '展示价格',
  `remarks` varchar(500) DEFAULT NULL COMMENT '备注',
  `link` varchar(255) DEFAULT NULL COMMENT '数据来源链接',
  `slot_text` text COMMENT '時段說明',
  `alert` tinyint(1) DEFAULT NULL COMMENT '警示標記'",
];

$comments = [
    'hospital' => '医院',
    'department' => '科室',
    'medicalitem' => '醫療項目',
    'medicalprice' => '醫療價格',
];

$CREATE_TABLES = ['hospital', 'department', 'medicalitem', 'medicalprice'];
foreach ($CREATE_TABLES as $key) {
    $table = $TABLES[$key];
    $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
{$core},
{$biz[$key]},
  PRIMARY KEY (`id`) USING BTREE,
  KEY `channel` (`weigh`,`id`),
  KEY `weigh` (`weigh`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='{$comments[$key]}'";
    $pdo->exec($sql);
    echo "建表: {$table}\n";
}

/* --------------------------------------------------- 注册扩展字段 */

$fields = [
    ['hospital', 'code', 'string', '短碼', null, 100],
    ['hospital', 'ownership', 'select', '性質', json_encode(['private' => '商業私立', 'public' => '公立', 'nonprofit' => '非牟利私立'], JSON_UNESCAPED_UNICODE), 90],
    ['hospital', 'link', 'string', '官網', null, 80],
    ['hospital', 'address', 'string', '地址', null, 70],
    ['hospital', 'phone', 'string', '電話', null, 60],

    ['department', 'code', 'string', '短碼', null, 100],
    ['department', 'icon', 'image', '圖標', null, 90],
    ['department', 'emoji', 'string', '圖標emoji', null, 88],
    ['department', 'card_desc', 'text', '首頁卡片描述', null, 87],
    ['department', 'accent', 'string', '卡片主色', null, 86],
    ['department', 'card_bg', 'string', '卡片底色', null, 85],
    ['department', 'home_card', 'string', '首頁卡片分區(specialty/baseline)', null, 84],
    ['department', 'page_title', 'text', '專科頁標題', null, 83],
    ['department', 'short_title', 'string', '頁頭短標題', null, 82],
    ['department', 'aside_title', 'string', '側邊欄當前專科', null, 81],
    ['department', 'aside_bg', 'string', '側邊欄底色', null, 80],
    ['department', 'aside_extra', 'string', '側邊欄附加樣式', null, 79],
    ['department', 'aliases', 'text', '搜尋別名(俗稱/症狀)', null, 82],

    ['medicalitem', 'department_id', 'selectpage', '科室', json_encode(['url' => 'multilingualcms/department/index', 'field' => 'title', 'primaryKey' => 'id'], JSON_UNESCAPED_UNICODE), 100],
    ['medicalitem', 'code', 'string', '短碼', null, 90],
    ['medicalitem', 'unit', 'string', '計價單位', null,  80],
    ['medicalitem', 'aliases', 'text', '搜尋別名(俗稱/症狀)', null, 79],

    ['medicalprice', 'item_id', 'selectpage', '醫療項目', json_encode(['url' => 'multilingualcms/medicalitem/index', 'field' => 'title', 'primaryKey' => 'id'], JSON_UNESCAPED_UNICODE), 100],
    ['medicalprice', 'hospital_id', 'selectpage', '醫院', json_encode(['url' => 'multilingualcms/hospital/index', 'field' => 'title', 'primaryKey' => 'id'], JSON_UNESCAPED_UNICODE), 95],
    ['medicalprice', 'slot', 'string', '時段/房型', null, 90],
    ['medicalprice', 'price', 'string', '價格', null, 85],
    ['medicalprice', 'price_label', 'string', '價格標籤', null, 80],
    ['medicalprice', 'display_price', 'string', '展示價格', null, 75],
    ['medicalprice', 'remarks', 'string', '備註', null, 70],
    ['medicalprice', 'link', 'string', '來源鏈接', null, 60],
    ['medicalprice', 'slot_text', 'text', '時段說明', null, 59],
    ['medicalprice', 'tags', 'text', '標籤/範圍', null, 58],
    ['medicalprice', 'alert', 'switch', '警示標記', null, 57],

    ['news', 'hero_eyebrow', 'string', '頭圖眉標', null, 70],
    ['news', 'hero_title', 'text', '頭圖標題', null, 69],
];

$columnTypes = [
    'string' => 'varchar(255)',
    'text' => 'text',
    'editor' => 'text',
    'number' => 'int(10) unsigned',
    'image' => 'varchar(255)',
    'images' => 'text',
    'select' => 'varchar(255)',
    'selectpage' => 'varchar(255)',
    'switch' => 'tinyint(1)',
    'date' => 'date',
    'time' => 'time',
    'datetime' => 'datetime',
];

$insField = $pdo->prepare(
    'INSERT INTO fa_multilingualcms_fields (type, table_name, cate_ids, field_type, name, title, content, default_value, weigh, switch, createtime, updatetime)
     VALUES ("table", :table_name, "", :field_type, :name, :title, :content, NULL, :weigh, 1, :ts, :ts)'
);
$existField = $pdo->prepare('SELECT id FROM fa_multilingualcms_fields WHERE table_name = ? AND name = ? LIMIT 1');
$updField = $pdo->prepare('UPDATE fa_multilingualcms_fields SET field_type = ?, title = ?, content = ?, weigh = ?, switch = 1, updatetime = ? WHERE id = ?');
$ts = time();
foreach ($fields as [$tbl, $name, $type, $title, $content, $weigh]) {
    $existField->execute([$TABLES[$tbl], $name]);
    $row = $existField->fetch();
    if ($row) {
        $updField->execute([$type, $title, $content, $weigh, $ts, $row['id']]);
        echo "更新扩展字段: {$TABLES[$tbl]}.{$name}\n";
    } else {
        $insField->execute([
            ':table_name' => $TABLES[$tbl],
            ':field_type' => $type,
            ':name' => $name,
            ':title' => $title,
            ':content' => $content,
            ':weigh' => $weigh,
            ':ts' => $ts,
        ]);
        echo "新增扩展字段: {$TABLES[$tbl]}.{$name}\n";
    }

    // 列不存在则补列 (扩展字段 = 真实列)
    $cols = $pdo->query("SHOW COLUMNS FROM `{$TABLES[$tbl]}`")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array($name, $cols, true)) {
        $type = isset($columnTypes[$type]) ? $columnTypes[$type] : 'varchar(255)';
        $pdo->exec("ALTER TABLE `{$TABLES[$tbl]}` ADD COLUMN `{$name}` {$type} DEFAULT NULL COMMENT '" . $title . "'");
        echo "  └ 补列: {$TABLES[$tbl]}.{$name} {$type}\n";
    }
}

/* -------------------------------------------------------- 建栏目 */

$cates = [
    // [名称, 别名, diyname, table_key, listtpl, showtpl, pagesize, weigh, parent_diyname, isnav]
    ['醫療價格', '醫療收費參考', 'medical-price', 'medicalitem', 'medicalitem', 'medicalitem_detail', 20, 90, null, 1],
    ['醫療項目', '收費項目', 'medical-items', 'medicalitem', 'medicalitem', 'medicalitem_detail', 20, 14, 'medical-price', 1],
    ['醫院', '合作醫院', 'hospitals', 'hospital', 'hospital', 'hospital_detail', 20, 13, 'medical-price', 1],
    ['科室', '專科分類', 'departments', 'department', 'department', 'department_detail', 30, 12, 'medical-price', 1],
    ['價格明細', '醫院收費明細', 'medical-prices', 'medicalprice', 'medicalprice', 'medicalprice_detail', 30, 11, 'medical-price', 0],
    ['醫療情報', '醫療專題及實用指南', 'medical-articles', 'news', 'medicalarticle', 'medicalarticle_detail', 12, 10, 'medical-price', 1],
    ['費用須知', '費用須知', 'fee-notice', 'news', 'medicalarticle', 'medicalarticle_detail', 12, 4, 'medical-articles', 1],
    ['理賠解析', '保險理賠解析', 'claim-analysis', 'news', 'medicalarticle', 'medicalarticle_detail', 12, 3, 'medical-articles', 1],
    ['跨境醫療', '跨境醫療資訊', 'cross-border', 'news', 'medicalarticle', 'medicalarticle_detail', 12, 2, 'medical-articles', 1],
    ['政策動態', '醫療政策動態', 'policy-update', 'news', 'medicalarticle', 'medicalarticle_detail', 12, 1, 'medical-articles', 1],
    ['資料來源', '資料來源與更新', 'medical-sources', 'page', 'medicalpage', 'medicalpage', 10, 9, 'medical-price', 1, 'page'],
    ['隱私政策', '隱私政策', 'medical-privacy', 'page', 'medicalpage', 'medicalpage', 10, 8, 'medical-price', 0, 'page'],
    ['Cookie 政策', 'Cookie 政策', 'medical-cookies', 'page', 'medicalpage', 'medicalpage', 10, 7, 'medical-price', 0, 'page'],
    ['使用條款', '使用條款', 'medical-terms', 'page', 'medicalpage', 'medicalpage', 10, 6, 'medical-price', 0, 'page'],
    ['搜尋', '搜尋結果', 'medical-search', 'medicalitem', 'medicalsearch', 'medicalsearch', 20, 5, 'medical-price', 0],
];

$selCate = $pdo->prepare('SELECT id, parent_id, type, table_name, listtpl, showtpl FROM fa_multilingualcms_cate WHERE lang = ? AND diyname = ? LIMIT 1');
$insCate = $pdo->prepare(
    'INSERT INTO fa_multilingualcms_cate
     (lang, copy_id, type, model_id, parent_id, name, byname, abstract, image, imagebj, flag, seotitle, keywords, description, outlink, items, weigh, tpl, listtpl, showtpl, pagesize, isnav_switch, status, diyname, table_name, createtime, updatetime, deletetime, fanyi_switch, fanyi_sha1, fanyi_time, fanyi_num, multilingualcms, no_detail_switch, no_follow_switch, no_index_switch)
     VALUES (:lang, 0, :type, 0, :parent_id, :name, :byname, NULL, "", "", "", NULL, NULL, NULL, "", 0, :weigh, "", :listtpl, :showtpl, :pagesize, :isnav, "1", :diyname, :table_name, :ts, :ts, NULL, 1, NULL, 0, 0, "1", 0, 0, 0)'
);

$cateIds = [];
foreach ($langList as $lg) {
    foreach ($cates as $cateDef) {
        [$name, $byname, $diyname, $tblKey, $listtpl, $showtpl, $pagesize, $weigh, $parentDiyname, $isnav] = $cateDef;
        $cateType = isset($cateDef[10]) ? $cateDef[10] : 'list';
        $parentId = 0;
        if ($parentDiyname !== null) {
            $selCate->execute([$lg, $parentDiyname]);
            $row = $selCate->fetch();
            if (!$row) {
                echo "!! 父栏目不存在: {$lg}/{$parentDiyname}\n";
                continue;
            }
            $parentId = (int)$row['id'];
        }
        $selCate->execute([$lg, $diyname]);
        $row = $selCate->fetch();
        if ($row) {
            $cateIds[$lg . '|' . $diyname] = (int)$row['id'];
            $tableName = $TABLES[$tblKey];
            if ($row['type'] !== $cateType || $row['table_name'] !== $tableName || (int)$row['parent_id'] !== $parentId) {
                $pdo->prepare("UPDATE fa_multilingualcms_cate SET type = ?, listtpl = ?, showtpl = ?, table_name = ?, parent_id = ? WHERE id = ?")
                    ->execute([$cateType, $listtpl, $showtpl, $tableName, $parentId, $row['id']]);
                echo "栏目已存在(已修正): {$lg}/{$diyname} (#{$row['id']}) -> {$cateType} / {$tableName}\n";
            }
            continue;
        }
        $insCate->execute([
            ':lang' => $lg,
            ':type' => $cateType,
            ':parent_id' => $parentId,
            ':name' => $name,
            ':byname' => $byname,
            ':weigh' => $weigh,
            ':listtpl' => $listtpl,
            ':showtpl' => $showtpl,
            ':pagesize' => $pagesize,
            ':isnav' => $isnav,
            ':diyname' => $diyname,
            ':table_name' => $TABLES[$tblKey],
            ':ts' => $ts,
        ]);
        $cateIds[$lg . '|' . $diyname] = (int)$pdo->lastInsertId();
    }
    echo "栏目就绪 [{$lg}]: " . count($cateIds) . " 个\n";
}

/* ---------------------------------------------------- 后台菜单权限 */

$menus = [
    ['multilingualcms/hospital', '醫院管理', 'fa fa-hospital', 9996],
    ['multilingualcms/department', '科室管理', 'fa fa-stethoscope', 9995],
    ['multilingualcms/medicalitem', '醫療項目管理', 'fa fa-list-alt', 9994],
    ['multilingualcms/medicalprice', '價格管理', 'fa fa-money', 9993],
];
$actions = [
    'index' => ['列表', 1, 'fa fa-list'],
    'add' => ['添加', 1, 'fa fa-plus'],
    'edit' => ['编辑', 0, 'fa fa-pencil'],
    'del' => ['删除', 0, 'fa fa-trash'],
    'multi' => ['批量更新', 0, 'fa fa-list'],
    'multiedit' => ['批量编辑', 0, 'fa fa-pencil-square'],
    'recyclebin' => ['回收站', 0, 'fa fa-recycle'],
    'destroy' => ['真实删除', 0, 'fa fa-trash'],
    'restore' => ['还原', 0, 'fa fa-recycle'],
];

$selRule = $pdo->prepare('SELECT id, pid FROM fa_auth_rule WHERE name = ? LIMIT 1');
$insRule = $pdo->prepare(
    'INSERT INTO fa_auth_rule (`pid`, `type`, `name`, `title`, `icon`, `url`, `condition`, `remark`, `ismenu`, `menutype`, `extend`, `py`, `pinyin`, `createtime`, `updatetime`, `weigh`, `status`)
     VALUES (:pid, "file", :name, :title, :icon, "", "", "", :ismenu, NULL, "", "", "", :ts, :ts, :weigh, "normal")'
);

function ensureRule(PDO $pdo, PDOStatement $sel, PDOStatement $ins, string $name, string $title, string $icon, int $pid, int $ismenu, int $weigh, int $ts): int
{
    $sel->execute([$name]);
    $row = $sel->fetch();
    if ($row) {
        return (int)$row['id'];
    }
    $ins->execute([
        ':pid' => $pid,
        ':name' => $name,
        ':title' => $title,
        ':icon' => $icon,
        ':ts' => $ts,
        ':ismenu' => $ismenu,
        ':weigh' => $weigh,
    ]);
    return (int)$pdo->lastInsertId();
}

$rootPid = ensureRule($pdo, $selRule, $insRule, 'multilingualcms/medical', '醫療價格管理', 'fa fa-heartbeat', 0, 1, 9997, $ts);
echo "菜单: 醫療價格管理 id={$rootPid}\n";

foreach ($menus as [$name, $title, $icon, $weigh]) {
    $nodeId = ensureRule($pdo, $selRule, $insRule, $name, $title, $icon, $rootPid, 1, $weigh, $ts);
    foreach ($actions as $act => [$actTitle, $isMenu, $actIcon]) {
        ensureRule($pdo, $selRule, $insRule, "{$name}/{$act}", $actTitle, $actIcon, $nodeId, $isMenu, 0, $ts);
    }
    echo "菜单: {$title} id={$nodeId}\n";
}

/* -------------------------------------------------- 翻译字段注册 */

$fanyiRows = [
    ['fa_multilingualcms_hospital', 'title,btitle,abstract,content,seotitle,keywords,description,code,address'],
    ['fa_multilingualcms_department', 'title,btitle,abstract,content,seotitle,keywords,description,code'],
    ['fa_multilingualcms_medicalitem', 'title,btitle,abstract,content,seotitle,keywords,description,code,unit'],
    ['fa_multilingualcms_medicalprice', 'title,remarks'],
];
$selFanyi = $pdo->prepare('SELECT id FROM fa_fanyi_tables WHERE table_name = ? LIMIT 1');
$insFanyi = $pdo->prepare('INSERT INTO fa_fanyi_tables (table_name, fanyi_fields) VALUES (?, ?)');
$updFanyi = $pdo->prepare('UPDATE fa_fanyi_tables SET fanyi_fields = ? WHERE id = ?');
foreach ($fanyiRows as [$table, $fields]) {
    $selFanyi->execute([$table]);
    $row = $selFanyi->fetch();
    if ($row) {
        $updFanyi->execute([$fields, $row['id']]);
        echo "翻译表更新: {$table}\n";
    } else {
        $insFanyi->execute([$table, $fields]);
        echo "翻译表新增: {$table}\n";
    }
}

echo "\n完成。栏目 id: " . json_encode($cateIds, JSON_UNESCAPED_UNICODE) . "\n";
