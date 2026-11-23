/**
 * MedicalPrice -> CMS 导入数据抽取
 *
 * 读 MedicalPrice/data/db.js (纯前端数据底座), 抽出:
 *   hospitals   医院
 *   departments 科室/专科
 *   items       医疗项目
 *   prices      价格行 (项目 × 医院)
 *
 * 用法: node scripts/medicalprice/extract.js [MedicalPrice项目目录]
 * 输出: scripts/medicalprice/medicalprice.json
 */
const fs = require('fs');
const { execSync } = require('child_process');
const path = require('path');

const ROOT = path.join(__dirname);
const args = process.argv.slice(2);
const argOf = (name, fallback) => {
    const hit = args.find(a => a.indexOf('--' + name + '=') === 0);
    return hit ? hit.slice(('--' + name + '=').length) : fallback;
};
// 数据源: 线上 URL (参照标准, 默认取已部署站点) 或本地目录
//   node scripts/extract.js --src=https://example.com/MedicalPrice
//   node scripts/extract.js --src=/path/to/MedicalPrice --out=/path/medicalprice.json
const DEFAULT_SRC = 'https://boyiyang93.github.io/MedicalPrice';
const SRC = argOf('src', args.find(a => a[0] !== '-' && !a.includes('=')) || DEFAULT_SRC);
const isRemote = /^https?:\/\//.test(SRC);
const SRC_LOCAL = isRemote ? process.env.MP_SRC_LOCAL || '' : SRC;
const OUT_FILE = argOf('out', path.join(ROOT, 'medicalprice.json'));

// ---------------------------------------------------------------- 读 db.js
function loadDb(file) {
    let s;
    if (/^https?:\/\//.test(file)) {
        s = execSync('curl -sL ' + JSON.stringify(file), { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
    } else {
        s = fs.readFileSync(file, 'utf8');
    }
    s = s.replace(/^\s*\/\/.*$/gm, '');
    const marker = s.indexOf('globalMedicalData');
    if (marker < 0) throw new Error('db.js 结构异常: 找不到 globalMedicalData');
    const start = s.indexOf('{', marker);
    // 用括号配对找对象结尾 (跳过字符串/模板串里的括号)
    let depth = 0, end = -1, inStr = null;
    for (let i = start; i < s.length; i++) {
        const ch = s[i];
        if (inStr) {
            if (ch === '\\') { i++; continue; }
            if (ch === inStr) inStr = null;
            continue;
        }
        if (ch === '"' || ch === "'" || ch === '`') { inStr = ch; continue; }
        if (ch === '{') depth++;
        else if (ch === '}') {
            depth--;
            if (depth === 0) { end = i; break; }
        }
    }
    if (end < 0) throw new Error('db.js 结构异常: 对象未闭合');
    return eval('(' + s.slice(start, end + 1) + ')');
}

// ------------------------------------------------------------- 远程工具
function fetchRemote(url, attempt = 0) {
    let out = '';
    try {
        out = execSync('curl -sL --max-time 60 ' + JSON.stringify(url), {
            encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, stdio: ['ignore', 'pipe', 'ignore']
        });
    } catch (e) {
        out = '';
    }
    if (!out && attempt < 2) {
        return fetchRemote(url, attempt + 1);
    }
    return out;
}

/** 远程站点页面清单: 优先 sitemap.xml, 失败则回退到常见页面名 */
function remotePages(src) {
    const base = src.replace(/\/$/, '');
    const sitemap = fetchRemote(base + '/sitemap.xml');
    const pages = [];
    const articles = [];
    if (sitemap && sitemap.indexOf('<loc>') >= 0) {
        const re = /<loc>\s*([^<]+?)\s*<\/loc>/g;
        let m;
        while ((m = re.exec(sitemap))) {
            // 去掉域名与站点根路径, 只留站点内相对路径
            let loc = m[1].trim().replace(/^https?:\/\/[^/]+/, '');
            const basePath = (base.match(/^https?:\/\/[^/]+(\/[^?#]*)/) || [, ''])[1].replace(/\/$/, '');
            if (basePath && loc.indexOf(basePath) === 0) loc = loc.slice(basePath.length);
            loc = loc.replace(/^\//, '');
            if (/^articles\//.test(loc)) {
                articles.push(loc.replace(/^articles\//, '').replace(/\.html$/, ''));
            } else {
                pages.push(loc);
            }
        }
    }
    if (!pages.length) {
        pages.push('index.html');
        for (const d of DEPARTMENTS) pages.push(d.code.toLowerCase() + '.html');
    }
    if (!articles.length) {
        for (const page of ['index.html', 'intelligence.html']) {
            const html = fetchRemote(base + '/' + page);
            const re = /href="[^"]*articles\/([a-z0-9-]+)\.html"/g;
            let m;
            while ((m = re.exec(html))) {
                if (!articles.includes(m[1])) articles.push(m[1]);
            }
        }
    }
    return { pages, articles };
}

// ------------------------------------------------- 项目中文名 (从 HTML 抽取)
// 本地目录: 扫描目录下所有 html; 远程站点: 抓取首页 + 各专科页
function extractSectionTitles(src) {
    const map = {};
    const scan = (file, html) => {
        const re = /<section[^>]*id="([a-z0-9_]+)"[^>]*>[\s\S]{0,400}?<h2[^>]*>([^<]+)<\/h2>/g;
        let m;
        while ((m = re.exec(html))) {
            const title = m[2].trim();
            if (title && !map[m[1]]) map[m[1]] = title;
        }
    };
    if (/^https?:\/\//.test(src)) {
        for (const page of remotePages(src).pages) {
            const html = fetchRemote(src.replace(/\/$/, '') + '/' + page);
            if (html && html.indexOf('<section') >= 0) scan(page, html);
        }
        return map;
    }
    const files = fs.readdirSync(src).filter(f => f.endsWith('.html') && f !== '404.html');
    for (const file of files) {
        scan(file, fs.readFileSync(path.join(src, file), 'utf8'));
    }
    return map;
}

// ---------------------------------------------------------------- 科室定义
// 科室定义 (顺序 = 首页「按專科查看」卡片顺序)
//   home: 'specialty' = 首页專科卡片区 | 'baseline' = 首页門診/病房区 | '' = 不上首页
const DEPARTMENTS = [
    {
        code: 'imaging', name: '影像科', home: 'specialty', emoji: '🔬', accent: '#7C3AED', bg: '#F5F3FF', shortTitle: '內窺鏡與影像',
        cardDesc: '胃鏡、大腸鏡、CT 及 MRI 等檢查套餐價格。',
        pageTitle: '港資私家醫院內窺鏡與影像掃描套餐價格比較'
    },
    {
        code: 'gynecology', name: '婦科', home: 'specialty', emoji: '👩', accent: '#F43F5E', bg: '#FDF2F8', shortTitle: '婦科收費比較',
        cardDesc: '子宮肌瘤、子宮鏡及婦科手術套餐價格比較。',
        pageTitle: '港資私家醫院婦科手術套餐價格比較'
    },
    {
        code: 'generalSurgery', name: '普通外科', home: 'specialty', emoji: '✂️', accent: '#1E40AF', bg: '#DBEAFE', shortTitle: '外科收費比較', asideTitle: '✂️ 日間外科手術套餐價格比較', asideBg: 'bg-blue-50', asideExtra: 'flex items-center gap-2',
        cardDesc: '腹腔鏡膽囊切除等日間手術套餐價格。',
        pageTitle: '港資私家醫院日間一般外科手術套餐價格比較'
    },
    {
        code: 'orthopedics', name: '骨科', home: 'specialty', emoji: '🦴', accent: '#D97706', bg: '#FFFBEB', shortTitle: '骨科收費比較', asideTitle: '🦴 骨科手術套餐價格比較', asideBg: 'bg-blue-50', asideExtra: '',
        cardDesc: '全膝關節置換等骨科手術套餐明細。',
        pageTitle: '港資私家醫院骨科手術套餐價格比較'
    },
    {
        code: 'painManagement', name: '疼痛管理', home: 'specialty', emoji: '💉', accent: '#6366F1', bg: '#EEF2FF', shortTitle: '疼痛科收費比較', asideTitle: '💉 疼痛介入治療套餐價格比較', asideBg: 'bg-indigo-50', asideExtra: 'flex items-center gap-2',
        cardDesc: '神經阻滯、椎間盤內視鏡及脊髓電刺激等疼痛治療套餐。',
        pageTitle: '港資私家醫院疼痛管理套餐價格比較'
    },
    {
        code: 'ent', name: '耳鼻喉科', home: 'specialty', emoji: '👂', accent: '#0284C7', bg: '#F0F9FF', shortTitle: '耳鼻喉科收費比較', asideTitle: '👂 耳鼻喉科套餐價格比較', asideBg: 'bg-blue-50', asideExtra: '',
        cardDesc: '常見耳鼻喉手術及聽力、暈眩檢查收費。',
        pageTitle: '港資私家醫院耳鼻喉科套餐價格比較'
    },
    {
        code: 'ophthalmology', name: '眼科', home: 'specialty', emoji: '👁️', accent: '#4F46E5', bg: '#EEF2FF', shortTitle: '眼科收費比較', asideTitle: '👁️ 眼科手術套餐價格比較', asideBg: 'bg-blue-50', asideExtra: '',
        cardDesc: '白內障手術、視網膜治療及激光矯視費用。',
        pageTitle: '港資私家醫院眼科手術套餐價格比較'
    },
    {
        code: 'cardiology', name: '心臟科', home: 'specialty', emoji: '❤️', accent: '#DC2626', bg: '#FEF2F2', shortTitle: '心內科收費比較', asideTitle: '❤️ 心臟科檢查套餐價格比較', asideBg: 'bg-blue-50', asideExtra: '',
        cardDesc: '心臟造影及通波仔（冠狀動脈支架）自付額估算。',
        pageTitle: '港資私家醫院心臟科套餐價格比較'
    },
    {
        code: 'plastics', name: '整形', home: 'specialty', emoji: '💄', accent: '#C026D3', bg: '#FAF5FF', shortTitle: '整形美容收費比較', asideTitle: '💄 整形外科套餐價格比較', asideBg: 'bg-blue-50', asideExtra: '',
        cardDesc: '醫學美容及整形手術收費比較。',
        pageTitle: '港資私家醫院整形外科套餐價格比較'
    },
    {
        code: 'urology', name: '泌尿科', home: 'specialty', emoji: '💧', accent: '#0284C7', bg: '#E0F2FE', shortTitle: '泌尿外科收費比較', asideTitle: '💧 泌尿外科套餐價格比較', asideBg: 'bg-sky-50', asideExtra: 'flex items-center gap-2',
        cardDesc: '前列腺、泌尿結石及膀胱鏡檢查套餐價格。',
        pageTitle: '港資私家醫院泌尿外科手術套餐價格比較'
    },
    {
        code: 'outpatient', name: '門診', home: 'baseline', emoji: '🏥', accent: '#2563EB', bg: '#EFF6FF', shortTitle: '門診收費比較', asideTitle: '🏥 門診／急症收費比較', asideBg: 'bg-blue-50', asideExtra: 'flex items-center gap-2',
        cardDesc: '港資私家醫院全科及專科門診登記費、診金及常見藥費比較。',
        pageTitle: '港資私家醫院門診診金比較'
    },
    {
        code: 'ward', name: '病房', home: 'baseline', emoji: '🛏️', accent: '#2563EB', bg: '#EFF6FF', shortTitle: '病房收費收費比較', asideTitle: '🛏️ 私家醫院病房收費', asideBg: 'bg-blue-50', asideExtra: 'flex items-center gap-2',
        cardDesc: '主要港資私家醫院各級病房日租比較（普通／半私家／私家房）。',
        pageTitle: '港資私家醫院病房日租比較'
    },
    {
        code: 'outpatientSpecialty', name: '專科門診', home: '', emoji: '', accent: '', bg: '', shortTitle: '', cardDesc: '', pageTitle: '' }
];

// B/C 型 (门诊/病房) 的项目
const B_ITEMS = [
    { code: 'outpatient', dept: 'outpatient', name: '全科門診診症費' },
    { code: 'outpatientSpecialty', dept: 'outpatientSpecialty', name: '專科門診診症費' },
];
const C_ITEMS = [
    { slot: 'standard', name: '標準病房' },
    { slot: 'semiPrivate', name: '半私家病房' },
    { slot: 'private', name: '私家病房' },
];
const SLOT_NAMES = {
    regular: '日常門診',
    night: '夜診/急診',
    holiday: '節日/惡劣天氣',
    standard: '標準病房',
    semiPrivate: '半私家病房',
    private: '私家病房',
};

// 異體字规范化: 源数据里「膽」用的是 U+81BD (異體), 换成常用的 U+8103,
// 否则用户用输入法打出的「膽石」永远搜不到
const VARIANTS = {
    '\u81bd': '\u8103'   // 膽
};

function normalizeText(value) {
    if (typeof value !== 'string') return value;
    return value.replace(/[\u81bd]/g, (m) => VARIANTS[m] || m);
}

function normalizeDeep(value) {
    if (typeof value === 'string') return normalizeText(value);
    if (Array.isArray(value)) return value.map(normalizeDeep);
    if (value && typeof value === 'object') {
        const out = {};
        for (const k of Object.keys(value)) out[k] = normalizeDeep(value[k]);
        return out;
    }
    return value;
}

const dbFile = isRemote ? SRC.replace(/\/$/, '') + '/data/db.js' : path.join(SRC, 'data', 'db.js');
const db = normalizeDeep(loadDb(dbFile));
const sectionTitles = extractSectionTitles(isRemote ? SRC : SRC_LOCAL);

const hospitals = [];
const order = db.hospitalOrder || Object.keys(db.hospitals || {});
order.forEach((code, index) => {
    const h = db.hospitals[code];
    if (!h) return;
    hospitals.push({
        code: code,
        name: h.name,
        ownership: h.ownership || '',
        link: h.link || '',
        tag: h.tag || '',
        order: index + 1,
    });
});

const departments = DEPARTMENTS.map((d, i) => ({
    code: d.code,
    name: d.name,
    order: i + 1,
    emoji: d.emoji || '',
    cardDesc: d.cardDesc || '',
    accent: d.accent || '',
    bg: d.bg || '',
    home: d.home || '',
    pageTitle: d.pageTitle || '',
    shortTitle: d.shortTitle || '',
    asideTitle: d.asideTitle || '',
    asideBg: d.asideBg || 'bg-blue-50',
    asideExtra: d.asideExtra || ''
}));

const items = [];
const prices = [];

function addItem(code, dept, name) {
    items.push({ code: code, dept: dept, name: name });
}

// --- B 型: 门诊 / 专科门诊 (按医院 × 时段)
for (const def of B_ITEMS) {
    addItem(def.code, def.dept, def.name);
    const module = db.modules[def.code] || {};
    for (const hcode of order) {
        const entry = module[hcode];
        if (!entry) continue;
        for (const slot of ['regular', 'night', 'holiday']) {
            const price = entry.prices ? entry.prices[slot] : undefined;
            if (price === undefined || price === null) continue;
            prices.push({
                item: def.code,
                hospital: hcode,
                slot: slot,
                price: String(price),
                label: SLOT_NAMES[slot],
                display: (entry.displayPrices && entry.displayPrices[slot]) ? String(entry.displayPrices[slot]) : String(price),
                remarks: entry.remarks || '',
                link: entry.link || '',
                slotText: (entry.timeSlots && entry.timeSlots[slot]) ? String(entry.timeSlots[slot]) : '',
                tags: Array.isArray(entry.scopes) ? entry.scopes.join(',') : (Array.isArray(entry.tags) ? entry.tags.join(',') : ''),
                alert: entry.alert ? 1 : 0
            });
        }
    }
}

// --- C 型: 病房 (按医院 × 房型)
addItem('ward.standard', 'ward', C_ITEMS[0].name);
addItem('ward.semiPrivate', 'ward', C_ITEMS[1].name);
addItem('ward.private', 'ward', C_ITEMS[2].name);
const ward = db.modules.ward || {};
for (const hcode of order) {
    const entry = ward[hcode];
    if (!entry) continue;
    for (const room of C_ITEMS) {
        const price = entry.prices ? entry.prices[room.slot] : undefined;
        if (price === undefined || price === null) continue;
        prices.push({
            item: 'ward.' + room.slot,
            hospital: hcode,
            slot: room.slot,
            price: String(price),
            label: room.name,
            display: (entry.ranges && entry.ranges[room.slot]) ? String(entry.ranges[room.slot]) : String(price),
            remarks: '',
            link: entry.link || '',
        });
    }
}

// --- D 型: 手术/检查项目 (按项目 × 医院)
for (const dept of DEPARTMENTS) {
    const module = db.modules[dept.code];
    if (!module || dept.code === 'outpatient' || dept.code === 'outpatientSpecialty' || dept.code === 'ward') continue;
    for (const proc of Object.keys(module)) {
        const code = dept.code + '.' + proc;
        const perHospital = module[proc];
        // 项目名: 优先取 HTML 标题, 其次取 priceLabel
        let name = sectionTitles[proc];
        if (!name) {
            for (const hcode of order) {
                if (perHospital[hcode] && perHospital[hcode].priceLabel) {
                    name = perHospital[hcode].priceLabel;
                    break;
                }
            }
        }
        if (!name) name = proc;
        // 去掉网页标题里的"價格比較"等字样
        name = name.replace(/價格比較/g, '').replace(/\s{2,}/g, ' ').trim();
        addItem(code, dept.code, name);
        for (const hcode of order) {
            const entry = perHospital[hcode];
            if (!entry) continue;
            prices.push({
                item: code,
                hospital: hcode,
                slot: '',
                price: entry.price === undefined || entry.price === null ? '' : String(entry.price),
                label: entry.priceLabel || '',
                display: entry.displayPrice || (entry.price === undefined || entry.price === null ? '' : String(entry.price)),
                remarks: entry.remarks || '',
                link: entry.link || '',
                tags: Array.isArray(entry.tags) ? entry.tags.join(',') : '',
                alert: entry.alert ? 1 : 0,
            });
        }
    }
}

// ---------------------------------------------------- 搜尋別名 (症狀/俗稱)
// searchIndex: [{keywords:[], page:'imaging.html', hash:'#gastroscopy', label:'胃鏡'}]
const PAGE_TO_MODULE = {
    'general-surgery.html': 'generalSurgery',
    'gyn.html': 'gynecology',
    'imaging.html': 'imaging',
    'ent.html': 'ent',
    'ophthalmology.html': 'ophthalmology',
    'cardiology.html': 'cardiology',
    'plastics.html': 'plastics',
    'urology.html': 'urology',
    'orthopedics.html': 'orthopedics',
    'pain-management.html': 'painManagement',
    'outpatient.html': 'outpatient',
    'ward.html': 'ward',
    'outpatientSpecialty.html': 'outpatientSpecialty'
};

const aliasMap = {};   // item code -> [alias]
const deptAliasMap = {}; // dept code -> [alias]
for (const entry of db.searchIndex || []) {
    const module = PAGE_TO_MODULE[entry.page];
    if (!module) continue;
    const proc = entry.hash ? entry.hash.replace('#', '') : '';
    const key = proc ? module + '.' + proc : module;
    const target = proc ? aliasMap : deptAliasMap;
    if (!target[key]) target[key] = [];
    for (const kw of entry.keywords || []) {
        if (kw && target[key].indexOf(kw) === -1) target[key].push(kw);
    }
}
for (const item of items) {
    item.aliases = (aliasMap[item.code] || []).join(',');
}
for (const dept of departments) {
    dept.aliases = (deptAliasMap[dept.code] || []).join(',');
}

// 过滤掉没有价格的空项目
const pricedItems = new Set(prices.map(p => p.item));
const finalItems = items.filter(i => pricedItems.has(i.code));

// ---------------------------------------------------------- 文章 / 頁面

/** 抓 <main>...</main> 之間的內容 */
function extractMain(file) {
    return extractMainHtml(fs.readFileSync(file, 'utf8'));
}

/** 从整页 HTML 里取 <main> 内容与 h1 标题 */
function extractMainHtml(html) {
    const start = html.indexOf('<main');
    if (start < 0) return { html: '', title: '' };
    const bodyStart = html.indexOf('>', start) + 1;
    const end = html.lastIndexOf('</main>');
    let content = html.slice(bodyStart, end);
    const h1 = content.match(/<h1[^>]*>([\s\S]*?)<\/h1>/);
    return {
        html: content.trim(),
        title: h1 ? h1[1].replace(/<[^>]+>/g, '').trim() : ''
    };
}

function rewriteAssetPaths(html) {
    return html
        .replace(/\.\.\/assets\//g, '/static/medicalprice/assets/')
        .replace(/src="assets\//g, 'src="/static/medicalprice/assets/')
        .replace(/href="css\//g, 'href="/static/medicalprice/css/')
        // 只留 webp (png 太大), 去掉 <picture> 的 source
        .replace(/<source type="image\/webp"[^>]*>/g, '')
        .replace(/(src="[^"]+)\.png"/g, '$1.webp"')
        .replace(/\.png"/g, '.webp"');
}

const articles = [];

function parseArticle(code, html) {

    const pick = (re) => {
        const m = html.match(re);
        return m ? m[1].trim() : '';
    };
    const title = pick(/<h1 class="article-title">([\s\S]*?)<\/h1>/);
    const lead = pick(/<p class="article-lead">([\s\S]*?)<\/p>/);
    const tag = pick(/<span class="article-tag">([\s\S]*?)<\/span>/);
    const dateIso = pick(/<time datetime="([^"]+)"/);
    const dateText = pick(/<time[^>]*>([\s\S]*?)<\/time>/);

    // 正文: article-body 到 footer-nav 之間
    const bodyStart = html.indexOf('<div class="article-body">');
    const bodyEnd = html.indexOf('<nav class="article-footer-nav"');
    let body = bodyStart > 0 && bodyEnd > bodyStart ? html.slice(bodyStart, bodyEnd) : '';
    body = body.replace(/<div class="article-body">/, '').replace(/<\/div>\s*$/, '').trim();

    const heroMatch = html.match(/<figure class="article-hero">([\s\S]*?)<\/figure>/);
    const imgMatch = heroMatch ? heroMatch[1].match(/<img src="([^"]+)"/) : null;

    // 无 PNG 图时, 原站用文字横幅 (article-hero-banner)
    let heroEyebrow = '';
    let heroTitle = '';
    if (!imgMatch) {
        const bStart = html.indexOf('<div class="article-hero-banner"');
        const bEnd = html.indexOf('<div class="article-body">');
        if (bStart > 0 && bEnd > bStart) {
            const seg = html.slice(bStart, bEnd);
            const eyebrowMatch = seg.match(/article-hero-banner__eyebrow">([\s\S]*?)<\/div>/);
            const titleMatch = seg.match(/article-hero-banner__title">([\s\S]*?)<\/p>/);
            heroEyebrow = eyebrowMatch ? normalizeText(eyebrowMatch[1].replace(/<[^>]+>/g, '').trim()) : '';
            heroTitle = titleMatch ? normalizeText(titleMatch[1].replace(/<[^>]+>/g, '').trim()) : '';
        }
    }

    articles.push({
        code: code,
        heroEyebrow: heroEyebrow,
        heroTitle: heroTitle,
        title: normalizeText(title.replace(/<[^>]+>/g, '').trim()),
        lead: normalizeText(lead.replace(/<[^>]+>/g, '').trim()),
        tag: normalizeText(tag.replace(/<[^>]+>/g, '').trim()),
        date: dateIso,
        dateText: dateText.replace(/<[^>]+>/g, '').trim(),
        image: imgMatch ? imgMatch[1].replace(/\.\.\//, '/static/medicalprice/') : '',
        body: normalizeText(rewriteAssetPaths(body))
    });
}

if (isRemote) {
    // 远程站点: 从 sitemap / 页面链接发现文章
    const base = SRC.replace(/\/$/, '');
    const found = remotePages(SRC).articles;
    for (const code of found) {
        const html = fetchRemote(base + '/articles/' + code + '.html');
        if (html && html.indexOf('article-body') > 0) {
            parseArticle(code, html);
        } else {
            console.error('[!] 文章抓取失败: ' + code);
        }
    }
    console.log('文章发现: ' + found.length + ' 篇');
} else {
    const articlesDir = path.join(SRC_LOCAL, 'articles');
    for (const file of fs.readdirSync(articlesDir)) {
        if (!file.endsWith('.html') || file === '_template.html') continue;
        const code = file.replace(/\.html$/, '');
        parseArticle(code, fs.readFileSync(path.join(articlesDir, file), 'utf8'));
    }
}

const pages = [];
for (const [code, file] of [['medical-sources', 'sources.html'], ['medical-privacy', 'privacy.html'], ['medical-cookies', 'cookies.html'], ['medical-terms', 'terms.html']]) {
    let main;
    if (isRemote) {
        main = extractMainHtml(fetchRemote(SRC.replace(/\/$/, '') + '/' + file));
    } else {
        main = extractMain(path.join(SRC_LOCAL, file));
    }
    pages.push({
        code: code,
        title: normalizeText(main.title),
        content: normalizeText(rewriteAssetPaths(main.html))
    });
}

const out = {
    version: db.version,
    lastUpdated: db.lastUpdated,
    hospitals: hospitals,
    departments: departments,
    items: finalItems,
    prices: prices,
    articles: articles,
    pages: pages,
};

const outFile = OUT_FILE;
fs.writeFileSync(outFile, JSON.stringify(out, null, 1));

console.log('医院:', hospitals.length);
console.log('科室:', departments.length);
console.log('项目:', finalItems.length);
console.log('价格行:', prices.length);
console.log('文章:', articles.length);
console.log('页面:', pages.length);
console.log('输出:', outFile);
console.log('数据源:', dbFile);

// 检查没有中文名的项目
const unnamed = finalItems.filter(i => !i.name || /^[a-z0-9_.]+$/i.test(i.name));
if (unnamed.length) {
    console.log('\n[!] 以下项目没抓到中文名:');
    unnamed.forEach(i => console.log('   ', i.code));
}
