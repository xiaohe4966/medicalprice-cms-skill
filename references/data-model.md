# 数据模型速查

## 内容类型表（4 张自建 + 2 张复用插件）

| 表 | 内容 | 关联 |
|---|---|---|
| `fa_multilingualcms_hospital` | 医院 | 被价格行引用 `hospital_id` |
| `fa_multilingualcms_department` | 科室/专科 | 被医疗项目引用 `department_id` |
| `fa_multilingualcms_medicalitem` | 医疗项目（收费条目） | 属于科室，被价格行引用 `item_id` |
| `fa_multilingualcms_medicalprice` | 价格行（项目 × 医院 × 时段） | 同时挂 `item_id` + `hospital_id` |
| `fa_multilingualcms_news` | 文章（医疗情报 4 个子栏目） | `cate_id` 指向子栏目 |
| `fa_multilingualcms_page` | 静态页（资料来源/隐私/条款） | `cate_id` 指向 page 类栏目 |

四张自建表共用插件的核心列：

```
id, lang(char10), copy_id, cate_id, title, btitle, hotdata, diyname, image, images,
abstract, tags, weigh, views, memo, content, switch, createtime, updatetime, publishtime,
deletetime, seotitle, keywords, description, fanyi_switch, fanyi_time, fanyi_sha1, fanyi_num,
no_detail_switch, no_follow_switch, no_index_switch
```

各自业务列：

```
hospital      : code, ownership(private/public/nonprofit), link, address, phone
department    : code, icon, emoji, card_desc, accent, card_bg, home_card(specialty/baseline),
                page_title, short_title, aside_title, aside_bg, aside_extra, aliases
medicalitem   : department_id, code, unit, aliases
medicalprice  : item_id, hospital_id, slot, price, price_label, display_price, remarks,
                link, slot_text, tags, alert
news(扩展)    : hero_eyebrow, hero_title
```

### 写入必填

- `lang`：语种代码（`cht` / `en` / …），**每个语种都要插一行**
- 目标语种范围 = 已建医疗栏目的语种 ∪ `fa_multilingualcms_web` 里 `switch=1` 的语种。只看后者会漏掉"有栏目但站点开关没开"的语种
- `copy_id`：翻译行指向主语言行 id；中文源写 `NULL`
- `diyname`：URL 路由片段，全站唯一。约定 `<前缀>-<短码>-<id>`（`hospital-szufh-14`、`dept-urology-13`、`item-outpatient-1`、`medical-prices-show/952`）
- `cate_id`：指向 `fa_multilingualcms_cate.id`
- `weigh`：**数值越大越靠前**。价格行导入时从总数递减赋值，保证医院顺序与源站一致

## 栏目树（`fa_multilingualcms_cate`）

医疗价格这套内容在每个启用语种下都有一套同名栏目，`diyname` 是跨语种锚点：

| diyname | 名称 | 表 | listtpl / showtpl | 父级 |
|---|---|---|---|---|
| `medical-price` | 醫療價格 | medicalitem | medicalitem / medicalitem_detail | - |
| `medical-items` | 醫療項目 | medicalitem | medicalitem / medicalitem_detail | medical-price |
| `hospitals` | 醫院 | hospital | hospital / hospital_detail | medical-price |
| `departments` | 科室 | department | department / department_detail | medical-price |
| `medical-prices` | 價格明細 | medicalprice | medicalprice / medicalprice_detail | medical-price |
| `medical-articles` | 醫療情報 | news | medicalarticle / medicalarticle_detail | medical-price |
| `fee-notice` | 費用須知 | news | 同上 | medical-articles |
| `claim-analysis` | 理賠解析 | news | 同上 | medical-articles |
| `cross-border` | 跨境醫療 | news | 同上 | medical-articles |
| `policy-update` | 政策動態 | news | 同上 | medical-articles |
| `medical-sources` / `medical-privacy` / `medical-cookies` / `medical-terms` | 資料來源/隱私/Cookie/條款 | page | medicalpage / medicalpage | medical-price |
| `medical-search` | 搜尋 | medicalitem | medicalsearch / medicalsearch | medical-price |

关键列：`type`（内容类型）、`table_name`（实际表名）、`listtpl`/`showtpl`（模板名）、`parent_id`、`isnav_switch`（是否进导航）、`status`、`pagesize`、`weigh`。

## 扩展字段（`fa_multilingualcms_fields`）

- 一行 = 一个字段声明：`type='table'`、`table_name`、`field_type`、`name`、`title`、`content`（select/selectpage 的选项 JSON）、`weigh`、`switch`
- **声明 ≠ 列**：脚本会 `ALTER TABLE ... ADD COLUMN` 补真实列（`install.php` 自动做）
- `field_type` 可用：`string` `text` `editor` `number` `image` `images` `select` `selectpage` `switch` `date` `time` `datetime`
- `selectpage` 的 `content` 示例：
  `{"url":"multilingualcms/department/index","field":"title","primaryKey":"id"}`
- 后台渲染入口：`application/common/controller/Backend.php` 的 `formString()`（`selectpage` 分支要写在这里，否则报"未知字段类型"）

## 翻译表（`fa_fanyi_tables`）

登记哪些表参与翻译，`fanyi_fields` 是逗号分隔的字段名列表。新增自建表时必须补一行，否则翻译功能不认这张表。

## 数据文件 `medicalprice.json`

```jsonc
{
  "version": "…", "lastUpdated": "…",
  "hospitals":   [{ "code","name","ownership","link","tag","order" }],
  "departments": [{ "code","name","order","emoji","cardDesc","accent","bg","home",
                    "pageTitle","shortTitle","asideTitle","asideBg","asideExtra","aliases" }],
  "items":       [{ "code","dept","name","aliases" }],
  "prices":      [{ "item","hospital","slot","price","label","display","remarks","link",
                    "slotText","tags","alert" }],
  "articles":    [{ "code","heroEyebrow","heroTitle","title","lead","tag","date","dateText",
                    "image","body" }],
  "pages":       [{ "code","title","content" }]
}
```

注意 JSON 用 camelCase，入库时映射成 snake_case 列名（`cardDesc`→`card_desc`、`display`→`display_price`）。

## 前台数据读取

`addons/multilingualcms/library/MedicalPrice.php` 是前台唯一取数入口，静态方法：
`hospitals()` `departments()` `itemsWithRange()` `departmentDetail()` `matrix()` `hospitalPrices()` `outpatientCards()` `wardTable()` `search()` `articles()` `articleCount()` `articleCategories()` `moduleCards()` `surgeryModules()` `hiddenItems()` `hospitalFilterGroups()` `costTicker()`。

新增前台数据需求时**加方法到这里**，不要在模板里直接查库。
