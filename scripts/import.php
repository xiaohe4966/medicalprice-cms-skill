<?php
/**
 * MedicalPrice 数据导入 CMS
 *
 * 用法:
 *   php scripts/medicalprice/import.php            # 导入 (幂等, 可重复跑)
 *   php scripts/medicalprice/import.php --truncate # 先清空再导入
 *
 * 数据源: scripts/medicalprice/medicalprice.json (由 extract.js 生成)
 * 会导入到所有已启用的语言站点 (fa_multilingualcms_web.switch = 1)
 */

require __DIR__ . '/_bootstrap.php';

$root = mp_root();
$dryRun = in_array('--dry-run', $argv);
$pdo = mp_db($dryRun);
echo "连接成功: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . " @ " . $pdo->query('SELECT @@hostname')->fetchColumn() . "\n";

// 数据文件优先级: --data= 参数 > CMS 项目内 scripts/medicalprice/medicalprice.json > 本目录
$dataArg = null;
foreach ($argv as $a) {
    if (strpos($a, '--data=') === 0) {
        $dataArg = substr($a, 7);
    }
}
$candidates = $dataArg ? [$dataArg] : [
    $root . '/scripts/medicalprice/medicalprice.json',
    __DIR__ . '/medicalprice.json',
];
$jsonFile = null;
foreach ($candidates as $c) {
    if (is_file($c)) {
        $jsonFile = $c;
        break;
    }
}
if (!$jsonFile) {
    exit("找不到数据文件 (" . implode(' | ', $candidates) . "), 先跑 node scripts/extract.js\n");
}
echo "数据文件: {$jsonFile}\n";
$data = json_decode(file_get_contents($jsonFile), true);
if (!$data) {
    exit("medicalprice.json 解析失败\n");
}

$truncate = in_array('--truncate', $argv);
if ($truncate && $dryRun) {
    exit("--truncate 与 --dry-run 不能同时使用\n");
}

$T_HOSPITAL = 'fa_multilingualcms_hospital';
$T_DEPARTMENT = 'fa_multilingualcms_department';
$T_ITEM = 'fa_multilingualcms_medicalitem';
$T_PRICE = 'fa_multilingualcms_medicalprice';
$T_ARTICLE = 'fa_multilingualcms_news';
$T_PAGE = 'fa_multilingualcms_page';

// 目标语言 = 已建医疗栏目的语种 ∪ 启用中的站点语种
// 只看 fa_multilingualcms_web 会漏掉"有栏目但站点开关没开"的语种，导致老语种数据不再更新
$langList = [];
foreach ($pdo->query("SELECT DISTINCT lang FROM fa_multilingualcms_cate WHERE diyname IN ('medical-price','medical-items','hospitals','departments','medical-prices','medical-articles','medical-sources','medical-privacy','medical-cookies','medical-terms','medical-search') ORDER BY lang") as $r) {
    $langList[] = $r['lang'];
}
foreach ($pdo->query("SELECT lang FROM fa_multilingualcms_web WHERE switch = 1 AND deletetime IS NULL ORDER BY id") as $r) {
    if (!in_array($r['lang'], $langList, true)) {
        $langList[] = $r['lang'];
    }
}
if (!in_array('cht', $langList, true)) {
    array_unshift($langList, 'cht');
}
$allLangs = $langList;
echo "目标语言: " . implode(', ', $allLangs) . "\n";

// 栏目 diyname -> id (按语言)
$cateMap = [];
foreach ($pdo->query("SELECT id, lang, diyname, name FROM fa_multilingualcms_cate") as $r) {
    $cateMap[$r['lang'] . '|' . $r['diyname']] = $r;
}
$needCates = ['hospitals', 'departments', 'medical-items', 'medical-prices', 'medical-articles', 'medical-sources', 'medical-privacy', 'medical-cookies', 'medical-terms'];
$langList = [];
foreach ($allLangs as $lg) {
    $missing = [];
    foreach ($needCates as $diyname) {
        if (!isset($cateMap[$lg . '|' . $diyname])) {
            $missing[] = $diyname;
        }
    }
    if ($missing) {
        echo "[跳过] {$lg} 缺少栏目: " . implode(', ', $missing) . " (先跑 scripts/medicalprice/install.php)\n";
        continue;
    }
    $langList[] = $lg;
}
if (!$langList) {
    exit("没有可用语种, 先跑 scripts/medicalprice/install.php 建栏目\n");
}

/** 生成唯一 diyname */
function makeDiyname(string $prefix, string $base, int $id): string
{
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $base));
    $base = trim($base, '-');
    if ($base === '') {
        $base = 'x';
    }
    return $prefix . '-' . $base . '-' . $id;
}

if ($truncate) {
    echo "清空: " . implode(', ', [$T_HOSPITAL, $T_DEPARTMENT, $T_ITEM, $T_PRICE]) . "\n";
    foreach ([$T_HOSPITAL, $T_DEPARTMENT, $T_ITEM, $T_PRICE] as $t) {
        $pdo->exec("DELETE FROM `{$t}`");
    }
}

$now = time();
$summary = [];

foreach ($langList as $LANG) {
    echo "\n---- 语言: {$LANG} ----\n";

    /* ------------------------------------------------------------ 医院 */

    $selHospital = $pdo->prepare("SELECT id FROM `{$T_HOSPITAL}` WHERE lang = ? AND code = ? LIMIT 1");
    $insHospital = $pdo->prepare(
        "INSERT INTO `{$T_HOSPITAL}`
     (lang, copy_id, cate_id, title, btitle, hotdata, diyname, image, images, abstract, tags, weigh, views, memo, content, switch, createtime, updatetime, publishtime, deletetime, seotitle, keywords, description, fanyi_switch, fanyi_time, fanyi_sha1, fanyi_num, no_detail_switch, no_follow_switch, no_index_switch, code, ownership, link, address, phone)
     VALUES (?, NULL, ?, ?, NULL, '', ?, '', NULL, NULL, '', ?, 0, '', NULL, 1, ?, ?, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 0, 0, 0, 0, ?, ?, ?, NULL, NULL)"
    );
    $hospitalIds = [];
    foreach ($data['hospitals'] as $h) {
        $selHospital->execute([$LANG, $h['code']]);
        $row = $selHospital->fetch();
        if ($row) {
            $id = (int)$row['id'];
            $hospitalIds[$h['code']] = $id;
            $pdo->prepare("UPDATE `{$T_HOSPITAL}` SET diyname = ?, title = ?, weigh = ?, ownership = ?, link = ?, tags = ?, updatetime = ? WHERE id = ?")
                ->execute([makeDiyname('hospital', $h['code'], $id), $h['name'], $h['order'], $h['ownership'], $h['link'], isset($h['tag']) ? $h['tag'] : '', $now, $id]);
            continue;
        }
        $insHospital->execute([
            $LANG,
            $cateMap[$LANG . '|hospitals']['id'],
            $h['name'],
            makeDiyname('hospital', $h['code'], 0),
            $h['order'],
            $now,
            $now,
            $h['code'],
            $h['ownership'],
            $h['link'],
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE `{$T_HOSPITAL}` SET diyname = ?, tags = ? WHERE id = ?")->execute([makeDiyname('hospital', $h['code'], $id), isset($h['tag']) ? $h['tag'] : '', $id]);
        $hospitalIds[$h['code']] = $id;
    }
    echo "医院: " . count($hospitalIds) . " 家\n";

    /* ------------------------------------------------------------ 科室 */

    $selDept = $pdo->prepare("SELECT id FROM `{$T_DEPARTMENT}` WHERE lang = ? AND code = ? LIMIT 1");
    $insDept = $pdo->prepare(
        "INSERT INTO `{$T_DEPARTMENT}`
     (lang, copy_id, cate_id, title, btitle, hotdata, diyname, image, images, abstract, tags, weigh, views, memo, content, switch, createtime, updatetime, publishtime, deletetime, seotitle, keywords, description, fanyi_switch, fanyi_time, fanyi_sha1, fanyi_num, no_detail_switch, no_follow_switch, no_index_switch, code, icon)
     VALUES (?, NULL, ?, ?, NULL, '', ?, '', NULL, NULL, '', ?, 0, '', NULL, 1, ?, ?, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 0, 0, 0, 0, ?, NULL)"
    );
    $deptIds = [];
    foreach ($data['departments'] as $d) {
        $selDept->execute([$LANG, $d['code']]);
        $row = $selDept->fetch();
        if ($row) {
            $id = (int)$row['id'];
            $deptIds[$d['code']] = $id;
            $pdo->prepare(
                "UPDATE `{$T_DEPARTMENT}` SET diyname = ?, title = ?, weigh = ?, emoji = ?, card_desc = ?, accent = ?, card_bg = ?, home_card = ?, page_title = ?, short_title = ?, aside_title = ?, aside_bg = ?, aside_extra = ?, aliases = ?, updatetime = ? WHERE id = ?"
            )->execute([
                makeDiyname('dept', $d['code'], $id),
                $d['name'],
                $d['order'],
                $d['emoji'],
                $d['cardDesc'],
                $d['accent'],
                $d['bg'],
                $d['home'],
                $d['pageTitle'],
                $d['shortTitle'],
                $d['asideTitle'],
                $d['asideBg'],
                $d['asideExtra'],
                $d['aliases'],
                $now,
                $id,
            ]);
            continue;
        }
        $insDept->execute([
            $LANG,
            $cateMap[$LANG . '|departments']['id'],
            $d['name'],
            makeDiyname('dept', $d['code'], 0),
            $d['order'],
            $now,
            $now,
            $d['code'],
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE `{$T_DEPARTMENT}` SET diyname = ? WHERE id = ?")->execute([makeDiyname('dept', $d['code'], $id), $id]);
        $deptIds[$d['code']] = $id;
    }
    echo "科室: " . count($deptIds) . " 个\n";

    /* ------------------------------------------------------------ 项目 */

    $selItem = $pdo->prepare("SELECT id FROM `{$T_ITEM}` WHERE lang = ? AND code = ? LIMIT 1");
    $insItem = $pdo->prepare(
        "INSERT INTO `{$T_ITEM}`
     (lang, copy_id, cate_id, title, btitle, hotdata, diyname, image, images, abstract, tags, weigh, views, memo, content, switch, createtime, updatetime, publishtime, deletetime, seotitle, keywords, description, fanyi_switch, fanyi_time, fanyi_sha1, fanyi_num, no_detail_switch, no_follow_switch, no_index_switch, department_id, code, unit, aliases)
     VALUES (?, NULL, ?, ?, NULL, '', ?, '', NULL, NULL, '', ?, 0, '', NULL, 1, ?, ?, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 0, 0, 0, 0, ?, ?, '', ?)"
    );
    $itemIds = [];
    // 權重遞減, 讓「權重大的排前面」= 原始專科頁的項目順序
    $itemWeigh = count($data['items']);
    foreach ($data['items'] as $i) {
        $selItem->execute([$LANG, $i['code']]);
        $row = $selItem->fetch();
        if ($row) {
            $id = (int)$row['id'];
            $itemIds[$i['code']] = $id;
            $pdo->prepare(
                "UPDATE `{$T_ITEM}` SET diyname = ?, title = ?, weigh = ?, aliases = ?, updatetime = ? WHERE id = ?"
            )->execute([
                makeDiyname('item', $i['code'], $id),
                $i['name'],
                $itemWeigh,
                $i['aliases'],
                $now,
                $id,
            ]);
            $itemWeigh--;
            continue;
        }
        $insItem->execute([
            $LANG,
            $cateMap[$LANG . '|medical-items']['id'],
            $i['name'],
            makeDiyname('item', $i['code'], 0),
            $itemWeigh,
            $now,
            $now,
            isset($deptIds[$i['dept']]) ? $deptIds[$i['dept']] : null,
            $i['code'],
            $i['aliases'],
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE `{$T_ITEM}` SET diyname = ? WHERE id = ?")->execute([makeDiyname('item', $i['code'], $id), $id]);
        $itemIds[$i['code']] = $id;
        $itemWeigh--;
    }
    echo "项目: " . count($itemIds) . " 个\n";

    /* ------------------------------------------------------------ 价格行 */

    $hospitalNames = [];
    foreach ($pdo->query("SELECT code, title FROM `{$T_HOSPITAL}` WHERE lang = '{$LANG}'") as $r) {
        $hospitalNames[$r['code']] = $r['title'];
    }
    $itemNames = [];
    foreach ($pdo->query("SELECT code, title FROM `{$T_ITEM}` WHERE lang = '{$LANG}'") as $r) {
        $itemNames[$r['code']] = $r['title'];
    }

    $selPrice = $pdo->prepare(
        "SELECT id FROM `{$T_PRICE}` WHERE lang = ? AND item_id = ? AND hospital_id = ? AND slot = ? LIMIT 1"
    );
    $insPrice = $pdo->prepare(
        "INSERT INTO `{$T_PRICE}`
     (lang, copy_id, cate_id, title, btitle, hotdata, diyname, image, images, abstract, tags, weigh, views, memo, content, switch, createtime, updatetime, publishtime, deletetime, seotitle, keywords, description, fanyi_switch, fanyi_time, fanyi_sha1, fanyi_num, no_detail_switch, no_follow_switch, no_index_switch, item_id, hospital_id, slot, price, price_label, display_price, remarks, link, slot_text, alert)
     VALUES (?, NULL, ?, ?, NULL, '', '', '', NULL, NULL, '', ?, 0, '', NULL, 1, ?, ?, NULL, NULL, NULL, NULL, NULL, 1, NULL, NULL, 0, 0, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $priceCount = 0;
    // 權重遞減: 第一條價格行權重最大 (「權重大的排前面」= 原始醫院順序)
    $priceWeigh = count($data['prices']);
    foreach ($data['prices'] as $p) {
        if (!isset($itemIds[$p['item']]) || !isset($hospitalIds[$p['hospital']])) {
            continue;
        }
        $itemId = $itemIds[$p['item']];
        $hospitalId = $hospitalIds[$p['hospital']];
        $slot = $p['slot'];
        $itemName = isset($itemNames[$p['item']]) ? $itemNames[$p['item']] : $p['item'];
        $hospitalName = isset($hospitalNames[$p['hospital']]) ? $hospitalNames[$p['hospital']] : $p['hospital'];
        $rowTitle = $itemName . ' - ' . $hospitalName . ($slot !== '' ? ' (' . $slot . ')' : '');
        $selPrice->execute([$LANG, $itemId, $hospitalId, $slot]);
        $row = $selPrice->fetch();
        if ($row) {
            $pdo->prepare(
                "UPDATE `{$T_PRICE}` SET title = ?, price = ?, price_label = ?, display_price = ?, remarks = ?, link = ?, slot_text = ?, tags = ?, alert = ?, weigh = ?, updatetime = ? WHERE id = ?"
            )->execute([$rowTitle, $p['price'], $p['label'], $p['display'], $p['remarks'], $p['link'], isset($p['slotText']) ? $p['slotText'] : '', isset($p['tags']) ? $p['tags'] : '', isset($p['alert']) ? $p['alert'] : 0, $priceWeigh, $now, $row['id']]);
            $priceCount++;
            $priceWeigh--;
            continue;
        }
        $insPrice->execute([
            $LANG,
            $cateMap[$LANG . '|medical-prices']['id'],
            $rowTitle,
            $priceWeigh,
            $now,
            $now,
            $itemId,
            $hospitalId,
            $slot,
            $p['price'],
            $p['label'],
            $p['display'],
            $p['remarks'],
            $p['link'],
            isset($p['slotText']) ? $p['slotText'] : '',
            isset($p['alert']) ? $p['alert'] : 0,
        ]);
        $priceCount++;
        $priceWeigh--;
    }
    echo "价格行: {$priceCount} 条\n";

    /* ------------------------------------------------------------ 文章 */

    $selArticle = $pdo->prepare("SELECT id FROM `{$T_ARTICLE}` WHERE lang = ? AND diyname = ? LIMIT 1");
    $insArticle = $pdo->prepare(
        "INSERT INTO `{$T_ARTICLE}`
     (lang, fanyi_switch, fanyi_time, fanyi_sha1, fanyi_num, copy_id, cate_id, title, btitle, cate_ids, hotdata, image, images, abstract, tags, weigh, views, memo, content, switch, createtime, updatetime, publishtime, deletetime, diyname, seotitle, keywords, description, scheduled_time, scheduled_switch, no_detail_switch, no_follow_switch, no_index_switch, hero_eyebrow, hero_title)
     VALUES (?, 1, NULL, NULL, 0, NULL, ?, ?, NULL, NULL, '', ?, NULL, ?, ?, ?, 0, '', ?, 1, ?, ?, ?, NULL, ?, NULL, NULL, ?, ?, 0, 0, 0, 0, ?, ?)"
    );
    // 文章分类 (tag) -> 子栏目 diyname
    $tagCateMap = [
        '費用須知' => 'fee-notice',
        '理賠解析' => 'claim-analysis',
        '跨境醫療' => 'cross-border',
        '政策動態' => 'policy-update',
    ];
    $articleWeigh = 0;
    foreach ($data['articles'] as $a) {
        $articleWeigh++;
        $diyname = 'article-' . $a['code'];
        $tag = trim((string)$a['tag'], '#');
        $cateKey = $tagCateMap[$tag] ?? 'medical-articles';
        $articleCateId = $cateMap[$LANG . '|' . $cateKey]['id'];
        $time = $a['date'] ? strtotime($a['date']) : $now;
        $selArticle->execute([$LANG, $diyname]);
        $row = $selArticle->fetch();
        if ($row) {
            $pdo->prepare(
                "UPDATE `{$T_ARTICLE}` SET title = ?, abstract = ?, tags = ?, content = ?, image = ?, weigh = ?, hero_eyebrow = ?, hero_title = ?, updatetime = ? WHERE id = ?"
            )->execute([$a['title'], $a['lead'], $a['tag'], $a['body'], $a['image'], $articleWeigh, $a['heroEyebrow'], $a['heroTitle'], $now, $row['id']]);
            continue;
        }
        $insArticle->execute([
            $LANG,
            $articleCateId,
            $a['title'],
            $a['image'],
            $a['lead'],
            $tag,
            $articleWeigh,
            $a['body'],
            $time,
            $now,
            $time,
            $diyname,
            $a['lead'],
            0,
            $a['heroEyebrow'],
            $a['heroTitle'],
        ]);
    }
    // 清掉旧的 article 表数据 (文章已迁到 news 表)
    $pdo->exec("DELETE FROM fa_multilingualcms_article WHERE diyname LIKE 'article-%'");
    echo "文章: " . count($data['articles']) . " 篇 (news 表, 已归入子栏目)\n";

    /* ------------------------------------------------------------ 页面 */

    $selPage = $pdo->prepare("SELECT id FROM `{$T_PAGE}` WHERE lang = ? AND cate_id = ? LIMIT 1");
    $insPage = $pdo->prepare(
        "INSERT INTO `{$T_PAGE}`
     (lang, fanyi_switch, fanyi_time, fanyi_sha1, fanyi_num, copy_id, cate_id, title, content, description, weigh, views, createtime, updatetime, deletetime, zdycss, switch)
     VALUES (?, 1, NULL, NULL, 0, NULL, ?, ?, ?, NULL, 50, 0, ?, ?, NULL, NULL, 1)"
    );
    foreach ($data['pages'] as $pg) {
        if (!isset($cateMap[$LANG . '|' . $pg['code']])) {
            continue;
        }
        $cateId = $cateMap[$LANG . '|' . $pg['code']]['id'];
        $selPage->execute([$LANG, $cateId]);
        $row = $selPage->fetch();
        if ($row) {
            $pdo->prepare("UPDATE `{$T_PAGE}` SET title = ?, content = ?, updatetime = ? WHERE id = ?")
                ->execute([$pg['title'], $pg['content'], $now, $row['id']]);
            continue;
        }
        $insPage->execute([$LANG, $cateId, $pg['title'], $pg['content'], $now, $now]);
    }
    echo "页面: " . count($data['pages']) . " 个\n";

    $summary[$LANG] = [
        '医院' => count($hospitalIds),
        '科室' => count($deptIds),
        '项目' => count($itemIds),
        '价格行' => $priceCount,
        '文章' => count($data['articles']),
        '页面' => count($data['pages']),
    ];
}

/* ------------------------------------------------------------ 统计 */

foreach ($summary as $lg => $stat) {
    echo "\n[{$lg}] ";
    foreach ($stat as $k => $v) {
        echo "{$k}={$v} ";
    }
    echo "\n";
}

if ($dryRun) {
    $byTable = [];
    foreach ($pdo->writes as $w) {
        if (preg_match('/`?(fa_[a-z_]+)`?/i', $w['sql'], $m)) {
            $t = $m[1];
            $op = preg_match('/^\s*INSERT/i', $w['sql']) ? 'INSERT' : (preg_match('/^\s*UPDATE/i', $w['sql']) ? 'UPDATE' : 'OTHER');
            $byTable[$t][$op] = ($byTable[$t][$op] ?? 0) + 1;
        }
    }
    echo "\n[演练模式] 未写入任何数据, 计划写操作:\n";
    foreach ($byTable as $t => $ops) {
        $parts = [];
        foreach ($ops as $op => $n) $parts[] = "{$op}={$n}";
        echo "  {$t}: " . implode(' ', $parts) . "\n";
    }
    echo "  合计写语句: " . count($pdo->writes) . "\n";
    exit(0);
}

echo "\n完成。\n";
