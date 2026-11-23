# medicalprice-cms-data

面向 **MedicalPrice 医疗价格比价站**（FastAdmin / ThinkPHP 5 + `multilingualcms` 多语言插件）的 Agent 技能包：让任何 AI 在拿到这份技能后，都能独立完成**采集医疗收费数据 → 写入 CMS → 管理后台内容 → 改前台页面 → 部署上线**。

内容类型：医院、科室、医疗项目、价格行、医疗情报文章、静态页。每个语种独立成行（`lang` + `copy_id`），共 16 个语言站。

## 安装

克隆后把技能目录放进对应位置：

```bash
git clone https://github.com/<owner>/medicalprice-cms-skill.git

# Qoder（用户级，全局可用）
cp -R medicalprice-cms-skill ~/.qoder-cn/skills/medicalprice-cms-data

# Claude Code / 其他支持 SKILL.md 的 Agent
cp -R medicalprice-cms-skill <project>/.claude/skills/medicalprice-cms-data
```

技能入口是 `SKILL.md`，`references/` 与 `scripts/` 按需加载。

## 配置

CMS 项目根目录的 `.env` 需要能连数据库，FTP 部署还需要一段 `[ftp]`：

```ini
[app]
debug = 1
url_route_must = true

[database]
hostname = 127.0.0.1
database = bijia
username = bijia
password = <你的密码>
hostport = 3306
prefix = fa_

[ftp]
host = <服务器IP>
port = 21
username = <FTP账号>
password = <FTP密码>
```

脚本定位 CMS 根目录的顺序：`MP_CMS_ROOT` 环境变量 → 从脚本目录逐级向上找含 `.env` + `application/` 的目录 → 退回脚本上两级目录。

## 脚本

| 脚本 | 作用 |
|---|---|
| `scripts/extract.js` | 从静态站 `data/db.js` 抽取医院/科室/项目/价格/文章/页面 → `medicalprice.json` |
| `scripts/import.php` | 幂等导入 JSON 到 MySQL，覆盖所有启用语种 |
| `scripts/install.php` | 建表、建栏目树、注册扩展字段（自动 ALTER 补列），自带备份 |
| `scripts/check-templates.php` | 用 TP5 编译器编译全部前后台模板，查语法与 include 缺失 |
| `scripts/ftp-sync.php` | 与线上 FTP 逐文件比对 md5 / 上传并回读校验 |

```bash
node scripts/extract.js --src=https://example.com/MedicalPrice
php   scripts/import.php --dry-run     # 只演练, 打印每张表计划写的条数
php   scripts/import.php               # 真正写入 (幂等 upsert)
php   scripts/install.php
php   scripts/check-templates.php
php   scripts/ftp-sync.php upload application/xxx.php
php   scripts/ftp-sync.php check
```

脚本在 CMS 根目录之外时，用 `MP_CMS_ROOT=/path/to/cms` 指定项目根；FTP 凭据从 `.env` 的 `[ftp]` 段或 `MP_FTP_*` 环境变量读取（仓库里不含任何凭据）。

## 文档

- `references/data-model.md` — 表结构、栏目树、扩展字段、数据文件格式
- `references/recipes.md` — 加医院/科室/项目/价格/文章/字段/改页面/上线的步骤
- `references/gotchas.md` — ThinkPHP 5 语法、多语言行级复制、FTP EPSV、编码坑
- `references/sample-data.json` — 数据文件样例（各类型各 2~3 条）

## 许可

MIT。脚本与文档可自由用于其他同类 CMS 站点；示例数据为公开收费信息整理结果。
