# 已踩过的坑

## ThinkPHP 5 模板

- **没有 `??` 空合并运算符**。PHP 8 的 `??` 在 TP5 模板里不生效，模板里用 `{if !empty($x)}` 包裹。
- **不支持动态数组下标** `{$a[$k]}`。需要按 key 取值就在 PHP 侧预先把数据 zip 成列表传给模板。
- **`{volist}` 的 `key` 从 1 开始**，不是 0。做颜色/序号循环时用 `$tk % 6 == 1` 判定，而不是 `$tk % 6 == 0`。
- **同一个标签不能有两个同名属性**。`<tp:medprice type="articles" type="x" />` 里第二个会静默覆盖第一个，表现为数据取错分支。改用不同属性名（例如 `kind=`）。
- **`Url::build($url, [], true)` 一定会追加后缀**。所以 `multilingualcms_url('/medical-articles.html')` 会变成 `/medical-articles.html.html`——传路径时**不带 `.html`**。
- **include 的目标必须存在**，缺一个片段整页 500。改完模板跑 `check-templates.php`。
- 站点全局 CSS 里若有 `header { height: 4rem }` 这类宽泛选择器，会压扁页面内部的 `<header>` 元素，导致标题和正文重叠。给内容区元素换标签（`<div>`）或加更具体的选择器。

## 多语言

- **翻译是行级复制**：`lang` 区分语种、`copy_id` 指主语言行。同一条内容在 16 个语种下就是 16 行。
- **只写中文行 = 只有中文站有数据**。导入必须遍历 `fa_multilingualcms_web` 里 `switch=1` 的语种。
- **`default_weblang` / `open_default_site_lang` 决定根域名出哪个语种**，改了会影响 `/` 的渲染；线上与本地要一致（配置在 `application/extra/site.php`，这是后台会写回的文件，别用 `git checkout` 覆盖）。
- 路由由 `addons/cmssitemap/Cmssitemap.php` 按语种注册（`regRoute2`），新表要在它的 `tableDiynameArr` 白名单里登记，否则详情页没有 URL。
- **同形异义字符**：源数据里「膽」是 U+81BD（异体字），标准是 U+8103。抓取时要归一化，**查询也要归一化**，否则搜索查不到。`MedicalPrice::normalizeText()` 处理查询侧，`extract.js` 的 `normalizeDeep()` 处理入库侧。

## 数据

- **MySQL 客户端缺 `mysql_native_password` 插件**，命令行连不上。改用 PHP PDO（脚本里已统一走 PDO）。
- **`weigh` 越大越靠前**。价格行导入时从 `count(prices)` 往下递减，才能保持源站的医院顺序。
- **不要给价格表排序**（去掉 usort），原站就是按医院固定顺序，不是按价格。
- **同名文件互相覆盖**：FTP/scp 传同名文件到同一目录会串内容（曾把线上 H5 首页覆盖成后台列表页）。上传后逐文件回读比对 md5。
- `import.php` 的 `--truncate` 会清空 4 张表，后台手工加的数据全丢。非必要不用。

## 部署

- **FTP 的 PASV 端口段被 IDC 防火墙挡死**：PHP 的 `ftp_*` 函数会卡在 `ftp_pasv()` 超时后登录失败；curl 必须用 EPSV（PHP 里 `CURLOPT_FTP_USE_EPSV => true`，命令行 `--epsv`）。
- **FTP 并发别开太大**：连接数超限的请求会挂到超时。并发 3~4 稳妥。
- **PHP 常量名别想当然**：`CURLOPT_FTP_MKDIR` / `CURLOPT_FTP_CREATE_DIRS` 在部分 PHP 版本不存在，本机是 `CURLOPT_FTP_CREATE_MISSING_DIRS`。
- **线上 nginx 伪静态**：`/en/xxx.html` 404 但 `/index.php?s=/en/xxx.html` 200，就是缺 rewrite 规则。规则：
  ```nginx
  if (!-e $request_filename) {
      rewrite ^(.*)$ /index.php?s=/$1 last;
  }
  ```
- **线上真实域名要先确认**：这个项目在服务器上的域名是 `bijia.he66.cn`；`bijia.cn` 的公共 DNS 指向另一台机。本地 `/etc/hosts` 可能把 `bijia.cn` 指到 127.0.0.1，测线上要显式带 Host 头。

## 杂项

- PHP 8.5 起 `curl_close()` 是 deprecated（无实际作用），新脚本别调用。
- **PHP 8.5 + TP5 5.0.28 会把 E_DEPRECATED 抛成异常**（框架里有 `(boolean)` 转型、`ReflectionMethod::setAccessible()`）。写需要启动框架的命令行脚本时，自己接管 `set_error_handler` 屏蔽 deprecation，其余错误仍交给 `think\Error::appError()`。`scripts/check-templates.php` 是这么处理的。
- **`fa_admin_log` / `fa_auth_group_access` 里的临时账号行要一并删干净。**
- 站点 `debug=1` 时线上错误会直接吐源码，排查方便但上线前记得关。
- 远程抓取站点时，GitHub Pages 偶尔会返回空响应；抓取函数要带重试，否则会静默少抓文章。
- 解析 `sitemap.xml` 里的 `<loc>` 要把**域名和站点根路径一起剥掉**（`https://host/MedicalPrice/articles/x.html` → `articles/x.html`），只剥域名会漏掉文章。
- 远程页面的正文要按 `<main>…</main>` 提取，直接存整页会把 `<!DOCTYPE html>` 一起塞进内容字段。
