---
name: medicalprice-cms-data
description: 采集并维护 MedicalPrice 医疗价格 CMS（FastAdmin + multilingualcms 插件）的内容数据与前台页面。任务涉及"抓取/刷新医疗价格数据""新增医院、科室、医疗项目或价格行""发布医疗情报与政策动态文章""加静态页或扩展字段""改前台比价页/科室页/后台列表与预览链接""把改动部署上线"时使用。提供 extract→JSON→import 的幂等数据管线、4 张内容类型表与栏目树规范、前台模板与 {tp:medprice} 标签约定，以及 ThinkPHP 5 / 多语言 / FTP 上线已踩过的坑。
---

# MedicalPrice CMS 数据运维

一套用 FastAdmin(ThinkPHP 5) + `multilingualcms` 插件搭的多语言医疗收费比价站：医院、科室、医疗项目、价格行、医疗情报文章、静态页六类内容，后台可 CRUD，前台按 16 种语言分别渲染。数据源头是一个静态站 `MedicalPrice`（`data/db.js` 是唯一数据底座）。

## 先定位，再动手

| 要找什么 | 去哪 |
|---|---|
| 数据库表 / 字段 / 栏目树 / JSON 格式 | `references/data-model.md` |
| 具体任务步骤（加医院、加价格、发文章、上线…） | `references/recipes.md` |
| 会踩的坑（TP5 语法、多语言、FTP、编码） | `references/gotchas.md` |
| 可执行脚本 | `scripts/`（见下方） |

CMS 项目根目录（本技能假定工具在跑）：
- 优先 `MP_CMS_ROOT` 环境变量
- 否则从脚本所在目录逐级向上找同时含 `.env` 与 `application/` 的目录
- 脚本会在 `<CMS根>/scripts/medicalprice/` 下找数据文件，找不到就用技能目录里的

在 CMS 根目录执行脚本最省事（`php scripts/medicalprice/import.php`）；技能目录里的副本用 `MP_CMS_ROOT` 指向项目即可。

线上站点（正式域名）：`https://www.medicalprice.hk/`（`medicalprice.hk` 会 301 到 `www`，以 `www` 为准）。默认语种是繁体中文（`cht`），页面路径形如 `/cht/dept-orthopedics-7.html`。数据库在 `.env` 的 `[database]` 段里，通常就是线上那台机的 MySQL。

数据源仍是静态站 `https://boyiyang93.github.io/MedicalPrice`（`data/db.js` 是唯一数据底座），正式域名是 CMS 渲染的成品，两者不要混。

## 铁律

1. **导入脚本必须幂等**——`import.php` 按 `(lang, code)` upsert，重复跑不会产生重复数据。改数据前先备份（`install.php` 自带备份，或 `cleanup-categories.php --dry-run` 先看影响面）。
2. **多语言靠行级复制**，不是靠字段翻译：`fa_multilingualcms_*` 每张表每个语种一行，`lang` 区分、`copy_id` 指向主语言行。写入必须覆盖所有 `switch=1` 的语种。
3. **扩展字段 = 真实列**。往 `fa_multilingualcms_fields` 插一行只是登记，后台表单要能渲染还得真在表上加列；两者都要做，缺一个后台就白屏或存不进去。
4. **模板语法是 ThinkPHP 5 的**，不是 Laravel 也不是 Twig：`{volist}`/`{if}`/`{tp:xxx}`，无 `??`、无动态数组下标 `{$a[$k]}`。改完模板跑 `scripts/check-templates.php` 体检。
5. **上线走 FTP 且必须回读校验**——同名文件会互相覆盖，上传后逐文件比对 md5。

## 数据管线

```
静态站 data/db.js ──extract.js──> medicalprice.json ──import.php──> MySQL ──前台模板──> 页面
```

- `node scripts/extract.js --src=https://<站点>/MedicalPrice` 抓取（`--src` 也可给本地目录；远程源靠 `sitemap.xml` 发现文章与页面）
- `php scripts/import.php` 导入全部启用语种（`--data=<file>` 指定数据文件，`--dry-run` 只演练不落库）
- `php scripts/install.php` 建表 / 建栏目 / 注册扩展字段（幂等，`--backup-only` 只备份）
- `php scripts/check-templates.php` 编译全部前后台模板，查语法与 include 缺失
- `php scripts/ftp-sync.php check|upload <file>...` 与线上比对 / 上传并回读 md5

脚本用法细节见 `references/recipes.md`。

⚠️ **重新导入会用 JSON 覆盖库里的同名字段**（含后台手工改过的标题、正文）。只想加数据就改 JSON 后导入；只想看影响面就先 `--dry-run`。

## 代码地图（相对 CMS 根目录）

```
application/multilingualcms/view/index/     前台模板（medical*.html / hospital*.html / department*.html）
application/multilingualcms/view/index/public/  前台公共片段（mpheader*.html / mpfooter.html / mp_dept_*.html）
application/admin/controller/multilingualcms/  后台 4 个内容类型的控制器（共用 EntityBase）
application/admin/view/multilingualcms/entity/    后台共用列表/表单模板
application/common/library/Tp.php           模板标签库（{tp:medprice} 实现）
addons/multilingualcms/library/MedicalPrice.php 前台数据读取库（所有查询集中在这里）
addons/multilingualcms/helper.php           前台 helper（medicalprice_nav_active 等）
addons/cmssitemap/Cmssitemap.php            每语言路由注册（决定 URL 形态）
public/static/medicalprice/                设计系统 CSS/JS/图片
```

## 改内容的三种路径

1. **批量/刷新数据** → 改 `medicalprice.json` 或数据源，跑 `import.php`。
2. **单条内容** → 后台 UI 操作（菜单：医疗价格 → 医院/科室/医疗项目/价格明细/医疗情报）。后台写入的数据立刻进库，前台模板读的就是这些表。
3. **页面/样式** → 改模板与 `public/static/medicalprice/`，跑模板体检后部署。

选哪条：用户说"抓取/批量/更新价格数据"走 1；说"后台加一条/改个字"走 2；说"页面不好看/链接不对/404"走 3。
