/* ==========================================================================
   轩宇-导航 · 全局搜索组件（v2.5.0）
   - 首页：挂载到现有 .search-section，联想下拉与页内过滤并行
   - 其他页面：header 注入紧凑搜索按钮，弹出搜索面板，提交跳转 index.php?s=
   - 联想范围：名称 / 别名 / 标签 / 简介 / 网址 / 域名 / 分类名 + 拼音全拼/首字母
   - 排序：命中字段相关度加权 + 点击量
   - 最近搜索（localStorage，隐私模式可控）+ 一键清空
   - 自然语言意图词典 → 扩展关键词 + 分类推荐
   - 无匹配引导：添加为网址 / 用搜索引擎搜索
   ========================================================================== */

(function () {
    'use strict';

    var HISTORY_KEY = 'xy-nav-search-history';
    var PRIVACY_KEY = 'xy-nav-search-privacy';
    var HISTORY_LIMIT = 8;
    var SUGGEST_LIMIT = 8;

    /* ----------------------------------------------------------------------
       基础工具
       ---------------------------------------------------------------------- */

    function toArray(list) {
        return Array.prototype.slice.call(list || []);
    }

    function store(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (error) {
            /* 隐私浏览等场景 localStorage 不可用时静默降级 */
        }
    }

    function readStore(key) {
        try {
            return localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function debounce(fn, wait) {
        var timer = null;

        return function () {
            var args = arguments;
            var self = this;

            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fn.apply(self, args);
            }, wait);
        };
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined && text !== null) {
            node.textContent = text;
        }

        return node;
    }

    function lower(value) {
        return String(value || '').toLowerCase();
    }

    /* ----------------------------------------------------------------------
       拼音支持（首页已随页面加载 pinyin-pro，其余页面懒加载）
       ---------------------------------------------------------------------- */

    var pinyinLoading = false;
    var pinyinCallbacks = [];

    function pinyinReady() {
        return typeof window.pinyinPro !== 'undefined' &&
            typeof window.pinyinPro.pinyin === 'function';
    }

    function ensurePinyin(done) {
        if (pinyinReady()) {
            if (done) {
                done();
            }

            return;
        }

        if (done) {
            pinyinCallbacks.push(done);
        }

        if (pinyinLoading) {
            return;
        }

        pinyinLoading = true;

        var script = document.createElement('script');
        script.src = 'assets/js/pinyin-pro.min.js';
        script.async = true;

        script.onload = function () {
            pinyinCallbacks.forEach(function (callback) {
                if (callback) {
                    callback();
                }
            });
            pinyinCallbacks = [];
        };

        script.onerror = function () {
            pinyinCallbacks = [];
        };

        document.head.appendChild(script);
    }

    function pinyinText(source) {
        if (!pinyinReady() || !/[\u3400-\u9fff]/.test(source)) {
            return '';
        }

        try {
            var full = window.pinyinPro.pinyin(source, {
                toneType: 'none',
                type: 'string'
            }).toLowerCase().replace(/[^a-z0-9]+/g, '');

            var abbr = window.pinyinPro.pinyin(source, {
                pattern: 'first',
                toneType: 'none',
                type: 'string'
            }).toLowerCase().replace(/[^a-z0-9]+/g, '');

            return full + ' ' + abbr;
        } catch (error) {
            return '';
        }
    }

    /* ----------------------------------------------------------------------
       自然语言意图词典（本地匹配，无需任何外部接口）
       ---------------------------------------------------------------------- */

    var SEMANTIC_RULES = [
        { match: ['看视频', '视频', '追剧', '电影', '影视剧', '刷剧', '看剧', '影视', '动漫', '番剧', '看电影'],
          terms: ['视频', '影视', '电影', '动漫', '哔哩', 'bilibili', 'youtube', '爱奇艺', '优酷', '腾讯视频'],
          hints: ['影视', '视频', '娱乐', '影音'] },
        { match: ['ai', '人工智能', '大模型', 'gpt', 'ai工具', 'ai 工具', '画图', '聊天机器人', 'chatgpt'],
          terms: ['ai', 'gpt', '人工智能', 'openai', 'claude', 'gemini', 'kimi', '通义', '文心', 'midjourney'],
          hints: ['ai', '人工智能', '工具'] },
        { match: ['学习', '教程', '课程', '自学', '网课', '教育', '学英语'],
          terms: ['学习', '教程', '课程', '教育', '慕课', '学堂', '大学', '英语'],
          hints: ['学习', '教育', '教程'] },
        { match: ['音乐', '听歌', '歌曲', '唱歌'],
          terms: ['音乐', '听歌', '网易云', 'spotify', 'qq音乐', '歌曲', '音乐播放'],
          hints: ['音乐', '娱乐'] },
        { match: ['购物', '买东西', '网购', '电商', '淘宝', '京东', '剁手'],
          terms: ['购物', '淘宝', '京东', '拼多多', '电商', '天猫', '亚马逊'],
          hints: ['购物', '电商', '生活'] },
        { match: ['社交', '聊天', '交友', '社区', '论坛'],
          terms: ['社交', '微信', '微博', '社区', '豆瓣', '知乎', '贴吧', '论坛'],
          hints: ['社交', '社区', '交流'] },
        { match: ['新闻', '资讯', '热点', '头条', '看新闻'],
          terms: ['新闻', '资讯', '头条', '热点', '新浪', '腾讯新闻', '早报'],
          hints: ['新闻', '资讯', '资讯门户'] },
        { match: ['游戏', '打游戏', '玩游戏', '网游', '手游'],
          terms: ['游戏', 'steam', '网游', '手游', 'epic', '游戏平台'],
          hints: ['游戏', '娱乐'] },
        { match: ['下载', '网盘', '云盘', '找资源', '资源下载'],
          terms: ['下载', '网盘', '云盘', '百度网盘', '阿里云盘', '磁力', '资源'],
          hints: ['下载', '资源', '工具'] },
        { match: ['邮箱', '邮件', '收发邮件', '发邮件'],
          terms: ['邮箱', '邮件', 'gmail', 'outlook', 'qq邮箱', '163', 'mail'],
          hints: ['邮箱', '工具'] },
        { match: ['翻译', '翻译网站', '中英翻译', '翻墙查词'],
          terms: ['翻译', 'translate', 'deepl', '百度翻译', '谷歌翻译', '词典'],
          hints: ['翻译', '工具'] },
        { match: ['设计', '作图', '修图', '图片处理', '抠图', 'p图'],
          terms: ['设计', 'ps', 'photoshop', 'figma', '修图', '作图', '图片', 'canva'],
          hints: ['设计', '工具', '图片'] },
        { match: ['开发', '编程', '程序员', '代码', '写代码'],
          terms: ['开发', '编程', 'github', '代码', 'git', '文档', '博客'],
          hints: ['开发', '编程', '技术'] },
        { match: ['直播', '看直播'],
          terms: ['直播', '斗鱼', '虎牙', '哔哩', 'twitch'],
          hints: ['直播', '娱乐', '视频'] },
        { match: ['旅游', '旅行', '出游', '自驾游'],
          terms: ['旅游', '旅行', '携程', '地图', '攻略', '马蜂窝'],
          hints: ['旅游', '生活'] },
        { match: ['找工作', '招聘', '求职', '投简历'],
          terms: ['招聘', '求职', 'boss直聘', '拉勾', '猎聘', '简历'],
          hints: ['招聘', '求职', '职场'] },
        { match: ['天气', '查天气', '天气预报'],
          terms: ['天气', '天气预报', '气象'],
          hints: ['天气', '工具', '生活'] },
        { match: ['办公', 'office', '文档', '表格', 'ppt'],
          terms: ['办公', 'office', '文档', '表格', 'ppt', 'wps', '协作'],
          hints: ['办公', '工具'] }
    ];

    function matchSemanticRule(query) {
        var q = lower(query).trim();

        if (!q) {
            return null;
        }

        for (var i = 0; i < SEMANTIC_RULES.length; i++) {
            var rule = SEMANTIC_RULES[i];

            for (var j = 0; j < rule.match.length; j++) {
                var word = rule.match[j];

                if (q.indexOf(word) !== -1 || (word.length >= 2 && word.indexOf(q) !== -1)) {
                    return rule;
                }
            }
        }

        return null;
    }

    /* ----------------------------------------------------------------------
       索引数据：首页直接读 DOM（零请求），其他页面请求 api/search_index.php
       ---------------------------------------------------------------------- */

    var data = null;
    var dataPromise = null;

    function catNameOf(cats, id) {
        for (var i = 0; i < cats.length; i++) {
            if (cats[i].id === id) {
                return cats[i].name;
            }
        }

        return '';
    }

    function buildFromDom() {
        var cats = [];
        var catById = {};

        toArray(document.querySelectorAll('.rail-item')).forEach(function (item) {
            var id = parseInt(item.getAttribute('data-category') || '0', 10);

            if (!id || catById[id]) {
                return;
            }

            var name = item.getAttribute('data-name') || '';
            var parent = 0;
            var group = item.closest('.rail-group');

            if (item.classList.contains('is-sub') && group) {
                var parentItem = group.querySelector('.rail-item:not(.is-sub)');

                if (parentItem) {
                    parent = parseInt(parentItem.getAttribute('data-category') || '0', 10);
                }
            }

            var cat = { id: id, name: name, parent: parent };
            catById[id] = cat;
            cats.push(cat);
        });

        var sites = [];

        toArray(document.querySelectorAll('.site-card')).forEach(function (card) {
            var url = card.getAttribute('data-url') || '';

            if (!url) {
                return;
            }

            var catId = parseInt(card.getAttribute('data-category') || '0', 10);
            var favicon = card.querySelector('.site-favicon');

            sites.push({
                id: parseInt(card.getAttribute('data-id') || '0', 10),
                name: card.getAttribute('data-name') || '',
                url: url,
                host: card.getAttribute('data-host') || '',
                domain: card.getAttribute('data-domain') || '',
                desc: card.getAttribute('data-description') || '',
                alias: card.getAttribute('data-alias') || '',
                tags: card.getAttribute('data-tags') || '',
                cat: catId,
                catName: catNameOf(cats, catId),
                clicks: parseInt(card.getAttribute('data-clicks') || '0', 10) || 0,
                logo: favicon ? (favicon.getAttribute('data-src') || '') : '',
                pinyin: undefined
            });
        });

        return { sites: sites, cats: cats };
    }

    function loadData() {
        if (data) {
            return Promise.resolve(data);
        }

        if (dataPromise) {
            return dataPromise;
        }

        if (document.querySelector('.site-card')) {
            data = buildFromDom();
            return Promise.resolve(data);
        }

        dataPromise = window.fetch('api/search_index.php', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.ok ? response.json() : { sites: [], categories: [] };
        }).then(function (json) {
            data = {
                sites: Array.isArray(json.sites) ? json.sites : [],
                cats: Array.isArray(json.categories) ? json.categories : []
            };

            return data;
        }).catch(function () {
            data = { sites: [], cats: [] };
            dataPromise = null;

            return data;
        });

        return dataPromise;
    }

    function sitePinyin(site) {
        if (site.pinyin !== undefined) {
            return site.pinyin;
        }

        ensurePinyin();

        site.pinyin = pinyinText((site.name || '') + ' ' + (site.alias || ''));

        return site.pinyin;
    }

    function catPinyin(cat) {
        if (cat.pinyin !== undefined) {
            return cat.pinyin;
        }

        ensurePinyin();

        cat.pinyin = pinyinText(cat.name || '');

        return cat.pinyin;
    }

    /* ----------------------------------------------------------------------
       匹配与相关度排序
       ---------------------------------------------------------------------- */

    function tokenScore(token, site) {
        var name = lower(site.name);
        var alias = lower(site.alias);
        var tags = lower(site.tags);
        var desc = lower(site.desc);
        var catName = lower(site.catName);
        var addr = lower(site.domain) + ' ' + lower(site.host) + ' ' + lower(site.url);
        var pinyin = lower(sitePinyin(site));

        if (name.indexOf(token) !== -1) {
            return name.indexOf(token) === 0 ? 380 : 300;
        }

        if (alias && alias.indexOf(token) !== -1) {
            return 260;
        }

        if (tags && tags.indexOf(token) !== -1) {
            return 240;
        }

        if (pinyin && pinyin.indexOf(token) !== -1) {
            return pinyin.indexOf(token) === 0 ? 230 : 200;
        }

        if (catName && catName.indexOf(token) !== -1) {
            return 150;
        }

        if (desc && desc.indexOf(token) !== -1) {
            return 120;
        }

        if (addr.indexOf(token) !== -1) {
            return 90;
        }

        return -1;
    }

    /* 单个词的语义扩展命中权重较低，排在直接命中之后 */
    function semanticTermScore(token, site) {
        var score = tokenScore(token, site);

        if (score > 0) {
            return Math.round(score * 0.6);
        }

        return -1;
    }

    function scoreSite(site, tokens, semanticTerms) {
        var total = 0;
        var i;

        for (i = 0; i < tokens.length; i++) {
            var score = tokenScore(tokens[i], site);

            if (score < 0) {
                return -1;
            }

            total += score;
        }

        if (semanticTerms) {
            var best = -1;

            for (i = 0; i < semanticTerms.length; i++) {
                var s = semanticTermScore(semanticTerms[i], site);

                if (s > best) {
                    best = s;
                }
            }

            if (best > 0 && total < best) {
                total = best;
            } else if (best > 0) {
                total += Math.round(best * 0.3);
            }
        }

        return total + Math.min(site.clicks || 0, 50000) / 500;
    }

    function scoreCat(cat, tokens, semanticTerms) {
        var name = lower(cat.name);
        var pinyin = lower(catPinyin(cat));
        var matched = true;
        var i;
        var base = 0;

        for (i = 0; i < tokens.length; i++) {
            var token = tokens[i];

            if (name.indexOf(token) !== -1) {
                base += name.indexOf(token) === 0 ? 200 : 150;
            } else if (pinyin && pinyin.indexOf(token) !== -1) {
                base += 100;
            } else {
                matched = false;
                break;
            }
        }

        if (!matched && semanticTerms) {
            for (i = 0; i < semanticTerms.length; i++) {
                if (name.indexOf(semanticTerms[i]) !== -1) {
                    base = 80;
                    matched = true;
                    break;
                }
            }
        }

        return matched ? base : -1;
    }

    function buildQuery(query) {
        var tokens = lower(query).split(/\s+/).filter(function (term) {
            return term.length > 0;
        });

        var rule = null;

        if (tokens.length === 1) {
            rule = matchSemanticRule(query);
        }

        return {
            tokens: tokens,
            semantic: rule,
            semanticTerms: rule ? rule.terms.map(lower) : null
        };
    }

    /* ----------------------------------------------------------------------
       最近搜索与隐私模式
       ---------------------------------------------------------------------- */

    function privacyOn() {
        return readStore(PRIVACY_KEY) === '1';
    }

    function loadHistory() {
        if (privacyOn()) {
            return [];
        }

        try {
            var raw = JSON.parse(readStore(HISTORY_KEY) || '[]');

            return Array.isArray(raw) ? raw.filter(function (item) {
                return typeof item === 'string' && item.length > 0;
            }).slice(0, HISTORY_LIMIT) : [];
        } catch (error) {
            return [];
        }
    }

    function saveHistory(raw) {
        try {
            localStorage.setItem(HISTORY_KEY, JSON.stringify(raw.slice(0, HISTORY_LIMIT)));
        } catch (error) {
            /* 忽略写入失败 */
        }
    }

    function recordQuery(query) {
        var q = String(query || '').trim();

        if (!q || q.length > 60 || privacyOn()) {
            return;
        }

        var list = loadHistory().filter(function (item) {
            return item !== q;
        });

        list.unshift(q);
        saveHistory(list);
    }

    function clearHistory() {
        try {
            localStorage.removeItem(HISTORY_KEY);
        } catch (error) {
            /* 忽略 */
        }
    }

    function setPrivacy(on) {
        store(PRIVACY_KEY, on ? '1' : '0');

        if (on) {
            clearHistory();
        }
    }

    /* 首次进入时把 URL 中的 ?s= 记入历史（与其他页面提交跳转衔接） */
    (function () {
        if (typeof URLSearchParams === 'undefined') {
            return;
        }

        var s = new URLSearchParams(window.location.search).get('s');

        if (s && window.history && typeof window.history.replaceState === 'function') {
            recordQuery(s);
        }
    })();

    /* ----------------------------------------------------------------------
       动作
       ---------------------------------------------------------------------- */

    function currentEngine() {
        var engine = readStore('xy-nav-engine');
        var allowed = ['site', 'baidu', 'bing', 'google'];

        return allowed.indexOf(engine) !== -1 ? engine : 'site';
    }

    function externalSearchUrl(query, engine) {
        var engines = {
            baidu: 'https://www.baidu.com/s?wd=',
            bing: 'https://www.bing.com/search?q=',
            google: 'https://www.google.com/search?q='
        };
        var base = engines[engine] || engines.baidu;

        return base + encodeURIComponent(query);
    }

    function trackSiteClick(id) {
        if (!id || typeof window.URLSearchParams === 'undefined') {
            return;
        }

        var payload = new URLSearchParams();
        payload.set('id', String(id));

        if (navigator.sendBeacon) {
            try {
                navigator.sendBeacon('api/track_click.php', payload);
                return;
            } catch (error) {
                /* 回退到 fetch */
            }
        }

        if (window.fetch) {
            window.fetch('api/track_click.php', {
                method: 'POST',
                body: payload,
                keepalive: true,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                }
            }).catch(function () {
                /* 统计失败不影响跳转 */
            });
        }
    }

    function openSite(site) {
        if (!site || !site.url) {
            return;
        }

        trackSiteClick(site.id);

        var win = window.open(site.url, '_blank', 'noopener');

        if (!win) {
            /* 弹窗被拦截时退化为当前页跳转 */
            window.location.href = site.url;
        }
    }

    function isLoggedIn() {
        return !!document.getElementById('userMenu') ||
            !!document.getElementById('addBtn') ||
            !!document.getElementById('linkDialog');
    }

    function addAsSiteUrl(query) {
        var trimmed = String(query || '').trim();

        if (/^https?:\/\//i.test(trimmed) || /^[\w-]+(\.[\w-]+)+/.test(trimmed)) {
            var url = /^https?:\/\//i.test(trimmed) ? trimmed : 'https://' + trimmed;

            return isLoggedIn()
                ? 'my.php?add_url=' + encodeURIComponent(url)
                : 'apply.php?url=' + encodeURIComponent(url);
        }

        return isLoggedIn()
            ? 'my.php?add_url_name=' + encodeURIComponent(trimmed)
            : 'apply.php?url=';
    }

    function gotoSearch(query) {
        var q = String(query || '').trim();

        if (!q) {
            return;
        }

        recordQuery(q);

        if (document.querySelector('.search-section') && window.XYNavSearch) {
            window.XYNavSearch.apply(q);
            return;
        }

        window.location.href = 'index.php?s=' + encodeURIComponent(q);
    }

    function gotoCategory(cat) {
        if (!cat) {
            return;
        }

        if (document.querySelector('.search-section') && window.XYNavSearch) {
            window.XYNavSearch.setCategory(cat.id);
            return;
        }

        window.location.href = 'index.php?cat=' + encodeURIComponent(cat.id);
    }

    /* ----------------------------------------------------------------------
       高亮（纯 DOM 构建，避免任何 HTML 注入）
       ---------------------------------------------------------------------- */

    function appendHighlighted(parent, text, tokens) {
        var source = String(text || '');

        if (!source) {
            return;
        }

        var lowerSource = lower(source);
        var ranges = [];

        (tokens || []).forEach(function (token) {
            if (!token) {
                return;
            }

            var index = lowerSource.indexOf(token);

            if (index !== -1) {
                ranges.push([index, token.length]);
            }
        });

        if (!ranges.length) {
            parent.appendChild(document.createTextNode(source));
            return;
        }

        ranges.sort(function (a, b) {
            return a[0] - b[0];
        });

        var cursor = 0;

        ranges.forEach(function (range) {
            var start = range[0];
            var length = range[1];

            if (start < cursor) {
                return;
            }

            if (start > cursor) {
                parent.appendChild(document.createTextNode(source.slice(cursor, start)));
            }

            parent.appendChild(el('mark', null, source.slice(start, start + length)));

            cursor = start + length;
        });

        if (cursor < source.length) {
            parent.appendChild(document.createTextNode(source.slice(cursor)));
        }
    }

    /* ----------------------------------------------------------------------
       候选图标（favicon 多级兜底，失败显示首字母）
       ---------------------------------------------------------------------- */

    function iconChainFor(site) {
        var chain = [];

        if (site.logo) {
            chain.push(site.logo);
        }

        if (site.host) {
            chain.push('https://' + site.host + '/favicon.ico');
            chain.push('https://api.iowen.cn/favicon/' + site.host + '.png');
            chain.push('https://www.google.com/s2/favicons?sz=64&domain=' + encodeURIComponent(site.host));
        }

        return chain;
    }

    function buildIcon(site) {
        var wrap = el('span', 'ss-ico');
        var letter = el('span', 'ss-ico-letter', (site.name || '#').charAt(0).toUpperCase() || '#');

        wrap.appendChild(letter);

        var chain = iconChainFor(site);

        if (!chain.length) {
            return wrap;
        }

        var img = document.createElement('img');
        img.className = 'ss-ico-img';
        img.alt = '';
        img.loading = 'lazy';
        img.decoding = 'async';
        img.referrerPolicy = 'no-referrer';
        img.hidden = true;

        var index = 0;

        img.addEventListener('error', function () {
            index++;

            if (index < chain.length) {
                img.src = chain[index];
            } else {
                img.remove();
            }
        });

        img.addEventListener('load', function () {
            img.hidden = false;
            letter.hidden = true;
        });

        img.src = chain[0];
        wrap.appendChild(img);

        return wrap;
    }

    /* ----------------------------------------------------------------------
       联想面板渲染
       ---------------------------------------------------------------------- */

    function collectMatches(query, data) {
        var parsed = buildQuery(query);

        if (!parsed.tokens.length) {
            return { query: query, parsed: parsed, sites: [], cats: [] };
        }

        var scoredSites = [];
        var scoredCats = [];
        var i;

        for (i = 0; i < data.sites.length; i++) {
            var score = scoreSite(data.sites[i], parsed.tokens, parsed.semanticTerms);

            if (score > 0) {
                scoredSites.push([score, data.sites[i]]);
            }
        }

        scoredSites.sort(function (a, b) {
            return b[0] - a[0];
        });

        for (i = 0; i < data.cats.length; i++) {
            var catScore = scoreCat(data.cats[i], parsed.tokens, parsed.semanticTerms);

            if (catScore > 0) {
                scoredCats.push([catScore, data.cats[i]]);
            }
        }

        scoredCats.sort(function (a, b) {
            return b[0] - a[0];
        });

        return {
            query: query,
            parsed: parsed,
            sites: scoredSites.slice(0, SUGGEST_LIMIT).map(function (entry) {
                return entry[1];
            }),
            cats: scoredCats.slice(0, 3).map(function (entry) {
                return entry[1];
            })
        };
    }

    /**
     * 渲染联想/历史内容到 container。
     * opts:
     *   onPick(site)      点击网址候选
     *   onPickCat(cat)    点击分类候选
     *   onSubmit(query)   提交搜索（历史项点击 / 无候选回车）
     *   onExternal(query) 用搜索引擎搜索
     *   onAdd(query)      添加为网址
     *   interactive       是否显示引导与历史（紧凑面板与首页共用）
     */
    function createSuggestPanel(opts) {
        var panel = el('div', 'ss-panel');
        panel.setAttribute('role', 'listbox');
        panel.hidden = true;

        var items = [];          // 可键盘选择的元素
        var activeIndex = -1;

        function clear() {
            while (panel.firstChild) {
                panel.removeChild(panel.firstChild);
            }

            items = [];
            activeIndex = -1;
        }

        function markActive(index) {
            items.forEach(function (item, i) {
                item.classList.toggle('is-active', i === index);
                item.setAttribute('aria-selected', i === index ? 'true' : 'false');
            });

            activeIndex = index;

            if (items[index] && items[index].scrollIntoView) {
                items[index].scrollIntoView({ block: 'nearest' });
            }
        }

        function register(node) {
            items.push(node);
            return items.length - 1;
        }

        function siteRow(site, tokens) {
            var row = el('div', 'ss-item ss-item-site');
            row.setAttribute('role', 'option');

            row.appendChild(buildIcon(site));

            var main = el('span', 'ss-main');
            var name = el('span', 'ss-name');
            appendHighlighted(name, site.name, tokens);
            main.appendChild(name);

            var sub = [site.catName, site.desc].filter(function (part) {
                return part && part.length > 0;
            })[0];

            if (sub) {
                var subEl = el('span', 'ss-sub');
                appendHighlighted(subEl, sub.length > 42 ? sub.slice(0, 42) + '…' : sub, tokens);
                main.appendChild(subEl);
            }

            row.appendChild(main);

            var meta = el('span', 'ss-meta');
            meta.appendChild(el('span', 'ss-domain', site.domain || site.host || ''));

            if ((site.clicks || 0) > 0) {
                meta.appendChild(el('span', 'ss-clicks', (site.clicks || 0) + ' 次访问'));
            }

            row.appendChild(meta);

            row.addEventListener('click', function () {
                if (opts.onPick) {
                    opts.onPick(site);
                }
            });

            return row;
        }

        function catRow(cat, tokens) {
            var row = el('div', 'ss-item ss-item-cat');
            row.setAttribute('role', 'option');

            var ico = el('span', 'ss-ico ss-ico-cat');
            ico.appendChild(el('span', 'ss-ico-letter', '分'));
            row.appendChild(ico);

            var main = el('span', 'ss-main');
            var name = el('span', 'ss-name');
            appendHighlighted(name, cat.name, tokens);
            main.appendChild(name);
            main.appendChild(el('span', 'ss-sub', '分类'));
            row.appendChild(main);

            row.appendChild(el('span', 'ss-meta'));

            row.addEventListener('click', function () {
                if (opts.onPickCat) {
                    opts.onPickCat(cat);
                }
            });

            return row;
        }

        function historyRow(keyword) {
            var row = el('div', 'ss-item ss-item-history');
            row.setAttribute('role', 'option');

            var ico = el('span', 'ss-ico ss-ico-history');

            var svgNS = 'http://www.w3.org/2000/svg';
            var svg = document.createElementNS(svgNS, 'svg');
            svg.setAttribute('viewBox', '0 0 24 24');
            svg.setAttribute('fill', 'none');
            svg.setAttribute('stroke', 'currentColor');
            svg.setAttribute('stroke-width', '2');
            svg.setAttribute('stroke-linecap', 'round');

            var circle = document.createElementNS(svgNS, 'circle');
            circle.setAttribute('cx', '12');
            circle.setAttribute('cy', '12');
            circle.setAttribute('r', '9');
            svg.appendChild(circle);

            var path = document.createElementNS(svgNS, 'path');
            path.setAttribute('d', 'M12 7v5l3 2');
            svg.appendChild(path);

            ico.appendChild(svg);
            row.appendChild(ico);

            row.appendChild(el('span', 'ss-main', keyword));

            row.addEventListener('click', function () {
                if (opts.onSubmit) {
                    opts.onSubmit(keyword);
                }
            });

            return row;
        }

        function semanticHint(parsed) {
            if (!parsed.semantic) {
                return null;
            }

            return el('div', 'ss-hint', '语义匹配：已为你扩展「' + parsed.semantic.terms.slice(0, 4).join(' ') + '」等关键词');
        }

        function renderSuggest(query) {
            clear();

            return loadData().then(function (data) {
                if (!data.sites.length && !data.cats.length) {
                    panel.hidden = true;
                    return;
                }

                var result = collectMatches(query, data);

                if (result.parsed.tokens.length === 0) {
                    renderHistory();
                    return;
                }

                var hint = semanticHint(result.parsed);

                if (hint) {
                    panel.appendChild(hint);
                }

                if (result.cats.length) {
                    panel.appendChild(el('div', 'ss-label', '分类'));
                    result.cats.forEach(function (cat) {
                        var row = catRow(cat, result.parsed.tokens);
                        register(row);
                        panel.appendChild(row);
                    });
                }

                if (result.sites.length) {
                    panel.appendChild(el('div', 'ss-label', '网址'));
                    result.sites.forEach(function (site) {
                        var row = siteRow(site, result.parsed.tokens);
                        register(row);
                        panel.appendChild(row);
                    });
                }

                if (!result.sites.length && !result.cats.length && opts.interactive) {
                    var empty = el('div', 'ss-empty');
                    empty.appendChild(el('div', 'ss-empty-title', '未找到与「' + query + '」相关的内容'));
                    empty.appendChild(el('div', 'ss-empty-text', '可以将其添加为网址，或用搜索引擎搜索该词'));

                    var actions = el('div', 'ss-empty-actions');

                    var addBtn = el('button', 'ss-action', '添加为网址');
                    addBtn.type = 'button';
                    addBtn.addEventListener('click', function () {
                        if (opts.onAdd) {
                            opts.onAdd(query);
                        }
                    });
                    actions.appendChild(addBtn);

                    var searchBtn = el('button', 'ss-action ss-action-alt', '用' + engineLabel() + '搜索「' + query + '」');
                    searchBtn.type = 'button';
                    searchBtn.addEventListener('click', function () {
                        if (opts.onExternal) {
                            opts.onExternal(query);
                        }
                    });
                    actions.appendChild(searchBtn);

                    empty.appendChild(actions);
                    panel.appendChild(empty);
                }

                if (!panel.childElementCount) {
                    panel.hidden = true;
                    return;
                }

                panel.hidden = false;
                markActive(-1);
            });
        }

        function engineLabel() {
            var engine = currentEngine();

            if (engine === 'bing') {
                return '必应';
            }

            if (engine === 'google') {
                return 'Google';
            }

            return '百度';
        }

        function renderHistory() {
            clear();

            if (!opts.interactive) {
                panel.hidden = true;
                return;
            }

            var head = el('div', 'ss-head');
            head.appendChild(el('span', 'ss-head-title', '最近搜索'));

            var tools = el('span', 'ss-head-tools');

            var privacyLabel = el('label', 'ss-privacy');
            var privacyCheck = document.createElement('input');
            privacyCheck.type = 'checkbox';
            privacyCheck.checked = privacyOn();
            privacyCheck.addEventListener('change', function () {
                setPrivacy(privacyCheck.checked);
                renderHistory();
            });
            privacyLabel.appendChild(privacyCheck);
            privacyLabel.appendChild(document.createTextNode('隐私模式'));

            var clearBtn = el('button', 'ss-head-clear', '一键清空');
            clearBtn.type = 'button';
            clearBtn.addEventListener('click', function () {
                clearHistory();
                renderHistory();
            });

            tools.appendChild(privacyLabel);
            tools.appendChild(clearBtn);
            head.appendChild(tools);
            panel.appendChild(head);

            var history = loadHistory();

            if (!history.length) {
                panel.appendChild(el('div', 'ss-empty-hint', privacyOn()
                    ? '隐私模式已开启，不记录搜索历史'
                    : '暂无搜索记录，输入关键词开始搜索'));
            } else {
                history.forEach(function (keyword) {
                    var row = historyRow(keyword);
                    register(row);
                    panel.appendChild(row);
                });
            }

            panel.hidden = false;
            markActive(-1);
        }

        return {
            node: panel,
            renderSuggest: renderSuggest,
            renderHistory: renderHistory,
            hide: function () {
                panel.hidden = true;
                markActive(-1);
            },
            get visible() {
                return !panel.hidden;
            },
            move: function (delta) {
                if (!items.length || panel.hidden) {
                    return false;
                }

                var next = activeIndex + delta;

                if (next < 0) {
                    next = items.length - 1;
                } else if (next >= items.length) {
                    next = 0;
                }

                markActive(next);
                return true;
            },
            pick: function () {
                if (activeIndex < 0 || !items[activeIndex]) {
                    return false;
                }

                items[activeIndex].click();
                return true;
            },
            hasActive: function () {
                return activeIndex >= 0;
            }
        };
    }

    /* ----------------------------------------------------------------------
       首页模式：挂载到现有搜索框
       ---------------------------------------------------------------------- */

    function initIndexMode() {
        var input = document.getElementById('siteSearch');
        var bar = input ? input.closest('.search-bar') : null;

        if (!input || !bar) {
            return false;
        }

        var panel = createSuggestPanel({
            interactive: true,
            onPick: function (site) {
                recordQuery(input.value);
                openSite(site);
                panel.hide();
            },
            onPickCat: function (cat) {
                gotoCategory(cat);
                panel.hide();
            },
            onSubmit: function (query) {
                input.value = query;
                gotoSearch(query);
                panel.hide();
            },
            onExternal: function (query) {
                recordQuery(query);
                window.open(externalSearchUrl(query, currentEngine()), '_blank', 'noopener');
            },
            onAdd: function (query) {
                window.location.href = addAsSiteUrl(query);
            }
        });

        bar.appendChild(panel.node);

        var suggest = debounce(function () {
            var value = input.value.trim();

            if (!value) {
                /* 空输入时由下方同步监听负责渲染历史面板，这里不做隐藏 */
                return;
            }

            panel.renderSuggest(value);
        }, 120);

        input.addEventListener('input', function (event) {
            if (event && event.isComposing) {
                return;
            }

            suggest();
        });

        input.addEventListener('focus', function () {
            if (!input.value.trim()) {
                panel.renderHistory();
            }
        });

        /* 捕获阶段处理键盘：有激活候选时拦截，避免 nav.js 的默认回车逻辑抢跳 */
        input.addEventListener('keydown', function (event) {
            if (panel.hidden) {
                /* 面板收起时仅记录回车提交的搜索词 */
                if (event.key === 'Enter') {
                    var value = input.value.trim();

                    if (value) {
                        recordQuery(value);
                    }
                }

                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                event.stopPropagation();
                panel.move(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                event.stopPropagation();
                panel.move(-1);
            } else if (event.key === 'Enter') {
                if (panel.hasActive() && panel.pick()) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }

                var keyword = input.value.trim();

                if (keyword) {
                    recordQuery(keyword);
                }
            } else if (event.key === 'Escape') {
                panel.hide();
            }
        }, true);

        /* 搜索提交按钮（nav.js 负责执行，这里只补记录历史） */
        var searchSubmitBtn = document.getElementById('searchSubmit');

        if (searchSubmitBtn) {
            searchSubmitBtn.addEventListener('click', function () {
                var value = input.value.trim();

                if (value) {
                    recordQuery(value);
                }
            });
        }

        document.addEventListener('click', function (event) {
            if (!panel.visible) {
                return;
            }

            if (!bar.contains(event.target)) {
                panel.hide();
            }
        });

        /* 空输入重新聚焦时展示历史 */
        input.addEventListener('input', function () {
            if (!input.value.trim() && document.activeElement === input) {
                panel.renderHistory();
            }
        });

        return true;
    }

    /* ----------------------------------------------------------------------
       紧凑模式：其他页面 header 注入搜索按钮 + 弹出面板
       ---------------------------------------------------------------------- */

    function initCompactMode() {
        var tools = document.querySelector('.header-tools');

        if (!tools || document.querySelector('.search-section')) {
            return false;
        }

        var wrap = el('div', 'header-search-wrap');

        var button = el('button', 'header-search-btn');
        button.type = 'button';
        button.setAttribute('aria-label', '搜索网站');
        button.setAttribute('title', '搜索网站（全局搜索）');

        var svgNS = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(svgNS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');

        var circle = document.createElementNS(svgNS, 'circle');
        circle.setAttribute('cx', '11');
        circle.setAttribute('cy', '11');
        circle.setAttribute('r', '7');
        svg.appendChild(circle);

        var path = document.createElementNS(svgNS, 'path');
        path.setAttribute('d', 'm20 20-3.2-3.2');
        svg.appendChild(path);

        button.appendChild(svg);
        wrap.appendChild(button);

        var panelBox = el('div', 'header-search-panel');
        panelBox.hidden = true;

        var inputRow = el('div', 'qsp-input-row');

        var icon = el('span', 'qsp-icon');
        var iconSvg = document.createElementNS(svgNS, 'svg');
        iconSvg.setAttribute('viewBox', '0 0 24 24');
        iconSvg.setAttribute('fill', 'none');
        iconSvg.setAttribute('stroke', 'currentColor');
        iconSvg.setAttribute('stroke-width', '2');
        iconSvg.setAttribute('stroke-linecap', 'round');

        var iconCircle = document.createElementNS(svgNS, 'circle');
        iconCircle.setAttribute('cx', '11');
        iconCircle.setAttribute('cy', '11');
        iconCircle.setAttribute('r', '7');
        iconSvg.appendChild(iconCircle);

        var iconPath = document.createElementNS(svgNS, 'path');
        iconPath.setAttribute('d', 'm20 20-3.2-3.2');
        iconSvg.appendChild(iconPath);

        icon.appendChild(iconSvg);
        inputRow.appendChild(icon);

        var input = document.createElement('input');
        input.type = 'search';
        input.className = 'qsp-input';
        input.placeholder = '全局搜索：名称 / 别名 / 标签 / 拼音';
        input.autocomplete = 'off';
        input.spellcheck = false;
        inputRow.appendChild(input);
        panelBox.appendChild(inputRow);

        var panel = createSuggestPanel({
            interactive: true,
            onPick: function (site) {
                recordQuery(input.value);
                openSite(site);
            },
            onPickCat: function (cat) {
                gotoCategory(cat);
            },
            onSubmit: function (query) {
                input.value = query;
                gotoSearch(query);
                close();
            },
            onExternal: function (query) {
                recordQuery(query);
                window.open(externalSearchUrl(query, currentEngine()), '_blank', 'noopener');
            },
            onAdd: function (query) {
                window.location.href = addAsSiteUrl(query);
            }
        });

        panelBox.appendChild(panel.node);
        wrap.appendChild(panelBox);
        tools.insertBefore(wrap, tools.firstChild);

        function open() {
            panelBox.hidden = false;
            button.setAttribute('aria-expanded', 'true');
            window.setTimeout(function () {
                input.focus();
                panel.renderHistory();
            }, 30);
        }

        function close() {
            panelBox.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            panel.hide();
        }

        function toggle() {
            if (panelBox.hidden) {
                open();
            } else {
                close();
            }
        }

        button.addEventListener('click', function (event) {
            event.stopPropagation();
            toggle();
        });

        document.addEventListener('click', function (event) {
            if (panelBox.hidden) {
                return;
            }

            if (!wrap.contains(event.target)) {
                close();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !panelBox.hidden) {
                close();
            }
        });

        var suggest = debounce(function () {
            var value = input.value.trim();

            if (!value) {
                panel.renderHistory();
                return;
            }

            panel.renderSuggest(value);
        }, 140);

        input.addEventListener('input', function (event) {
            if (event && event.isComposing) {
                return;
            }

            suggest();
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                panel.move(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                panel.move(-1);
            } else if (event.key === 'Enter') {
                event.preventDefault();

                if (panel.hasActive() && panel.pick()) {
                    return;
                }

                var value = input.value.trim();

                if (value) {
                    gotoSearch(value);
                    close();
                }
            }
        });

        return true;
    }

    /* ----------------------------------------------------------------------
       页内搜索结果高亮（搜索时高亮命中卡片标题）
       ---------------------------------------------------------------------- */

    function initCardHighlight() {
        var input = document.getElementById('siteSearch');

        if (!input || !document.querySelector('.site-card')) {
            return;
        }

        var refresh = debounce(function () {
            var query = input.value.trim().toLowerCase();
            var tokens = query ? query.split(/\s+/).filter(Boolean) : [];

            toArray(document.querySelectorAll('.site-card')).forEach(function (card) {
                if (card.hidden) {
                    return;
                }

                var nameNode = card.querySelector('.site-name');

                if (!nameNode) {
                    return;
                }

                if (!nameNode.__origText) {
                    nameNode.__origText = nameNode.textContent;
                }

                var original = nameNode.__origText;

                if (!tokens.length) {
                    if (nameNode.textContent !== original) {
                        nameNode.textContent = original;
                    }

                    return;
                }

                var lowerName = lower(original);
                var hit = tokens.some(function (token) {
                    return lowerName.indexOf(token) !== -1;
                });

                if (!hit) {
                    if (nameNode.textContent !== original) {
                        nameNode.textContent = original;
                    }

                    return;
                }

                nameNode.textContent = '';
                appendHighlighted(nameNode, original, tokens);
            });
        }, 180);

        input.addEventListener('input', refresh);
    }

    /* ----------------------------------------------------------------------
       启动
       ---------------------------------------------------------------------- */

    /* 供 nav.js 页内过滤调用的语义扩展词典入口（单关键词意图匹配） */
    window.XYSearchSemantic = function (query) {
        var rule = matchSemanticRule(query);

        return rule ? rule.terms : null;
    };

    function init() {
        if (initIndexMode()) {
            initCardHighlight();
            ensurePinyin();
            return;
        }

        if (initCompactMode()) {
            /* 数据与拼音库延迟到面板首次展开时再加载 */
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.XY_SEARCH_VERSION = '2.5.0';
})();
