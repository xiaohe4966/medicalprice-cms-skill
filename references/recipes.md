# 任务配方

命令默认在 CMS 项目根目录执行。`$SK` = 本技能目录。

---

## 1. 刷新全量数据（采集 → 入库）

```bash
node $SK/scripts/extract.js --src=https://boyiyang93.github.io/MedicalPrice
php  $SK/scripts/import.php --dry-run     # 先看要写什么
php  $SK/scripts/import.php               # 确认后真正写入
```

- `--src` 可换成镜像/自建站 URL 或本地 MedicalPrice 目录；`--out=<file>` 改输出路径
- 远程源靠站点根目录的 `sitemap.xml` 发现文章页与页面清单（取不到时回退到首页/专科页链接）
- 抽取器会打印"没抓到中文名"的项目清单 —— 有输出说明源站结构变了，先看 `extract.js` 里对应的解析函数
- 导入是 upsert（按 `lang + code`），重复跑安全
- `--dry-run` 只读不写，最后打印每张表计划执行的 INSERT/UPDATE 条数；`--truncate` 与它互斥
- `--truncate` 先清空 4 张表再导（**慎用**，会丢掉手工在后台加的数据）
- **导入会用 JSON 覆盖库里的同名字段**：后台手工改过的标题/正文会被打回 JSON 版本。只想加一条就别整包导入，直接改 JSON 后跑导入（仍会覆盖同 code 行），或改完后重新导入该条
- 目标语种 = 已建医疗栏目的语种 ∪ 启用站点的语种（不只看 `fa_multilingualcms_web`，否则老语种数据会悄悄不更新）

离线数据源直接改 JSON 时：字段名用 camelCase，保存后重跑 `import.php` 即可。

## 2. 加一家医院

两条路，选一条：

**A. 走数据管线**（会同时铺到所有语种）
1. 在 `medicalprice.json` 的 `hospitals` 里加一条：`{"code":"newhosp","name":"新醫院","ownership":"private","link":"https://…","tag":"","order":15}`
2. 跑 `php $SK/scripts/import.php`
3. 若该医院也要出现在科室比价表里，还要在 `prices` 里加对应行（价格行靠 `hospital` 短码关联）

**B. 后台手工加**（只加一条、只有中文）
后台 → 医疗价格 → 医院 → 添加，填标题；扩展字段里的"短码"（`code`）必填，它是价格行关联和导入去重的键。

## 3. 加一个科室

`medicalprice.json` 的 `departments` 加一条，字段齐全最好（缺 `emoji`/`accent`/`cardDesc` 前台卡片会很难看）：

```json
{"code":"dermatology","name":"皮膚科","order":13,"emoji":"🧴",
 "cardDesc":"…","accent":"#0EA5A5","bg":"#ECFDF5","home":"specialty",
 "pageTitle":"…","shortTitle":"…","asideTitle":"","asideBg":"bg-teal-50","asideExtra":""}
```

`home`：`specialty` = 进首页专科卡片区；`baseline` = 进"基線"区。
科室页版式由 `department_detail.html` 按科室类型分发到 `public/mp_dept_surgery.html`（手術卡片）、`mp_dept_ward.html`（病房價目表）、`mp_dept_outpatient.html`（門診時段價）。

## 4. 加一个医疗项目

`items` 里加：`{"code":"cataract","dept":"ophthalmology","name":"白內障手術","aliases":"白內障,白内障,cataract"}`

- `dept` 必须是已有科室的 `code`
- `aliases` 是搜索别名（俗稱、症狀、英文），站内搜索靠它命中
- 项目页由 `medicalitem.html` 渲染，价格由该 `item` 的 `prices` 行决定

## 5. 加一行价格

`prices` 里加：

```json
{"item":"cataract","hospital":"szufh","slot":"regular","price":"25899",
 "label":"日間套餐","display":"HK$20,100 – 22,800",
 "remarks":"…","link":"https://…","slotText":"星期一至五 08:30-17:30","tags":"","alert":0}
```

- `item` / `hospital` 用短码（对应 items/hospitals 的 `code`），不是 id
- `price` 存数字串（用于排序），`display` 存展示区间串
- `slot`：`regular` / `specialty` / 病房类型等，`slotName()` 负责转显示名
- `alert`=1 触发"Coming Soon"等警示样式

导入后检查：科室页价格表行数 = 该项目在 `prices` 里的条数。

## 6. 发一篇医疗情报

`articles` 里加一条，`body` 是 HTML：

```json
{"code":"new-article-slug","heroEyebrow":"費用須知 · 眼科","heroTitle":"頭圖大標",
 "title":"文章標題","lead":"導語","tag":"#費用須知","date":"2026-11-20",
 "dateText":"2026年11月","image":"","body":"<h2>…</h2><p>…</p>"}
```

- 文章进 `fa_multilingualcms_news`，按 `code` 里的关键词映射到 4 个子栏目之一（費用須知/理賠解析/跨境醫療/政策動態），映射逻辑在 `import.php` 的文章段
- `image` 留空则用默认图；`hero_eyebrow`/`hero_title` 是文章页顶部横幅字段（news 扩展字段）
- 文章详情模板 `medicalarticle_detail.html`；列表模板 `medicalarticle.html`

## 7. 加一个静态页

`pages` 里加：`{"code":"medical-faq","title":"常見問題","content":"<section>…</section>"}`
需要同时在 `install.php` 的 `$cates` 里加对应栏目（`table_key='page'`），否则没有入口页。

## 8. 给内容类型加字段

1. 在 `install.php` 的 `$fields` 数组加一行：`[表键, 字段名, 类型, 中文标题, 选项JSON, 权重]`
2. 跑 `php $SK/scripts/install.php` → 自动登记 `fa_multilingualcms_fields` + `ALTER TABLE` 补列
3. 后台 `Backend::formString()` 若不支持该 `field_type`，在 switch 里补一个 case（`selectpage` 已经补过）
4. 若字段要出现在前台，加进 `EntityBase::index()` 的 `$visible` 白名单，否则列表 JSON 里没有该字段
5. 前台取值统一走 `MedicalPrice.php` 的方法

## 9. 改前台页面

- 模板：`application/multilingualcms/view/index/*.html`
- 公共片段：`application/multilingualcms/view/index/public/*.html`
- 样式：`public/static/medicalprice/css/`（设计系统，Tailwind 风格工具类）
- 模板标签：`{tp:medprice type="..." name="xxx"}`，类型清单见 `application/common/library/Tp.php` 的 `medprice` 分支
- 改完必跑：`php $SK/scripts/check-templates.php`（编译所有前后台模板，报语法错与 include 缺失）

## 10. 上线部署

```bash
php $SK/scripts/ftp-sync.php upload application/xxx.php public/static/medicalprice/css/xx.css
php $SK/scripts/ftp-sync.php check     # 全量比对本地与线上（会回读每个文件算 md5）
```

FTP 凭据写在 CMS 项目 `.env` 的 `[ftp]` 段（`host` / `username` / `password` / `port`），或用 `MP_FTP_HOST` / `MP_FTP_USER` / `MP_FTP_PASS` 环境变量。**不要把密码写进代码或公开仓库。**

部署后自测：
- 前台：`https://bijia.he66.cn/` 与一个详情页
- 后台：`/h.php/<控制器>/index`（列表 JSON 必须含 `view_url`，否则预览按钮是 undefined）
- 注意线上 nginx 需要伪静态规则把非静态路径交给 `index.php`，否则所有 `.html` 链接 404

## 11. 后台列表/预览问题排查

后台列表 JSON 由 `EntityBase::index()` 组装，`$visible` 白名单决定哪些字段出现在 JSON 里。

| 现象 | 原因 | 修法 |
|---|---|---|
| 预览按钮点了没反应 / URL 是 undefined | `view_url` 不在 `$visible` | 把 `'view_url'` 加进 `$visible` 数组 |
| 列表少字段 | 字段不在 `$visible` | 同上 |
| 后台保存后前台不变 | 写错库 / 前台读的是缓存 | 清 `runtime/` 缓存，确认前台走 `MedicalPrice.php` 取数 |
| 表单报"未知字段类型" | `Backend::formString()` 缺该 `field_type` 的 case | 补 case |
