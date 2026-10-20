/* ==========================================================================
   轩宇-导航 · 前台交互（v2.0.0）
   - 明暗主题 / 配色皮肤 / 显示方式（网格·列表·紧凑）
   - 左侧分类索引栏（二级折叠、移动端抽屉）
   - 分组化搜索与分类筛选
   - favicon 多级兜底、可用性与响应速度检测
   - 官网标题异步校正、点击量统计
   - 卡片 3D 悬浮倾斜、回到顶部
   ========================================================================== */

(function () {
    'use strict';

    var root = document.documentElement;
    var body = document.body;

    var themeToggle = document.getElementById('themeToggle');
    var themeColorMeta = document.getElementById('themeColor');
    var searchInput = document.getElementById('siteSearch');
    var searchClear = document.getElementById('searchClear');
    var searchSubmit = document.getElementById('searchSubmit');
    var searchEngine = document.getElementById('searchEngine');
    var searchEngineToggle = document.getElementById('searchEngineToggle');
    var searchEngineMenu = document.getElementById('searchEngineMenu');
    var searchEngineLabel = document.getElementById('searchEngineLabel');
    var railNav = document.getElementById('railNav');
    var railOpen = document.getElementById('railOpen');
    var railClose = document.getElementById('railClose');
    var railBackdrop = document.getElementById('railBackdrop');
    var railCurrent = document.getElementById('railCurrent');
    var sectionTitle = document.getElementById('sectionTitle');
    var sectionCount = document.getElementById('sectionCount');
    var sectionAside = document.getElementById('sectionAside');
    var emptyState = document.getElementById('emptyState');
    var emptyTitle = document.getElementById('emptyTitle');
    var emptyText = document.getElementById('emptyText');
    var emptyReset = document.getElementById('emptyReset');
    // 记录初始空状态文案：登录用户与访客的引导语不同，由服务端渲染后在这里缓存
    var emptyDefaultTitle = emptyTitle ? emptyTitle.textContent : '';
    var emptyDefaultText = emptyText ? emptyText.textContent : '';
    var toTop = document.getElementById('toTop');
    var modeCycle = document.getElementById('modeCycle');
    var hotStrip = document.querySelector('.hot-strip');
    var userMenu = document.getElementById('userMenu');
    var skinPicker = document.getElementById('skinPicker');

    var MODES = ['grid', 'list', 'compact'];
    var SKINS = ['default', 'purple', 'orange', 'mint'];

    var SEARCH_ENGINES = {
        site:   { label: '站内', placeholder: '搜索网站，支持拼音（按 / 聚焦）', url: '' },
        baidu:  { label: '百度', placeholder: '百度搜索，回车新标签页打开', url: 'https://www.baidu.com/s?wd=' },
        bing:   { label: '必应', placeholder: '必应搜索，回车新标签页打开', url: 'https://www.bing.com/search?q=' },
        google: { label: '谷歌', placeholder: 'Google 搜索，回车新标签页打开', url: 'https://www.google.com/search?q=' }
    };

    var cards = toArray(document.querySelectorAll('.site-card'));
    var groups = toArray(document.querySelectorAll('.link-group'));
    var hotItems = toArray(document.querySelectorAll('.hot-item'));
    var totalCards = cards.length;

    var state = { category: 'all', query: '' };
    var searchTimer = null;
    var prefersReducedMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var MOBILE_QUERY = '(max-width: 960px)';

    function toArray(list) {
        return Array.prototype.slice.call(list);
    }

    function qsa(selector, scope) {
        return toArray((scope || document).querySelectorAll(selector));
    }

    function store(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (error) {
            /* 隐私模式下 localStorage 不可用时仅当前页面生效 */
        }
    }

    function isMobileLayout() {
        return window.matchMedia && window.matchMedia(MOBILE_QUERY).matches;
    }

    /* ----------------------------------------------------------------------
       主题
       ---------------------------------------------------------------------- */

    function applyTheme(theme) {
        var next = theme === 'dark' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);

        if (themeColorMeta) {
            themeColorMeta.setAttribute('content', next === 'dark' ? '#0a1120' : '#f4f6fa');
        }

        if (themeToggle) {
            var label = next === 'dark' ? '切换到浅色模式' : '切换到深色模式';
            themeToggle.setAttribute('aria-label', label);
            themeToggle.setAttribute('title', label);
            themeToggle.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
        }
    }

    applyTheme(root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light');

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            store('xy-nav-theme', next);
        });
    }

    /* ----------------------------------------------------------------------
       配色皮肤
       ---------------------------------------------------------------------- */

    function applySkin(skin) {
        var next = SKINS.indexOf(skin) === -1 ? 'default' : skin;
        root.setAttribute('data-skin', next);

        qsa('.skin-option').forEach(function (option) {
            var active = option.getAttribute('data-skin') === next;
            option.classList.toggle('is-active', active);
            option.setAttribute('aria-checked', active ? 'true' : 'false');
        });
    }

    function closeSkinMenu() {
        if (skinPicker) {
            skinPicker.classList.remove('is-open');
        }

        var toggle = document.getElementById('skinToggle');

        if (toggle) {
            toggle.setAttribute('aria-expanded', 'false');
        }
    }

    var skinToggle = document.getElementById('skinToggle');

    if (skinToggle && skinPicker) {
        skinToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = skinPicker.classList.toggle('is-open');
            skinToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    qsa('.skin-option').forEach(function (option) {
        option.addEventListener('click', function () {
            var skin = option.getAttribute('data-skin');
            applySkin(skin);
            store('xy-nav-skin', skin);
            closeSkinMenu();
        });
    });

    document.addEventListener('click', function (event) {
        if (skinPicker && skinPicker.classList.contains('is-open')
            && !skinPicker.contains(event.target)) {
            closeSkinMenu();
        }

        if (userMenu && userMenu.hasAttribute('open') && !userMenu.contains(event.target)) {
            userMenu.removeAttribute('open');
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        closeSkinMenu();

        if (userMenu) {
            userMenu.removeAttribute('open');
        }

        closeRail();
    });

    /* ----------------------------------------------------------------------
       显示方式：网格 / 列表 / 紧凑
       ---------------------------------------------------------------------- */

    function applyMode(mode) {
        var next = MODES.indexOf(mode) === -1 ? 'grid' : mode;
        root.setAttribute('data-mode', next);

        qsa('.mode-btn').forEach(function (button) {
            button.classList.toggle('is-active', button.getAttribute('data-mode') === next);
        });

        if (modeCycle) {
            modeCycle.setAttribute('title', '当前：' + modeLabel(next) + '（点击切换）');
        }
    }

    function modeLabel(mode) {
        if (mode === 'list') {
            return '列表显示';
        }

        if (mode === 'compact') {
            return '紧凑显示';
        }

        return '网格显示';
    }

    function setMode(mode, persist) {
        applyMode(mode);

        if (persist !== false) {
            store('xy-nav-mode', root.getAttribute('data-mode'));
        }
    }

    qsa('.mode-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            setMode(button.getAttribute('data-mode'));
        });
    });

    if (modeCycle) {
        modeCycle.addEventListener('click', function () {
            var current = root.getAttribute('data-mode');
            var index = MODES.indexOf(current === null ? 'grid' : current);
            var next = MODES[(index + 1) % MODES.length];
            setMode(next);
        });
    }

    setMode(root.getAttribute('data-mode') || 'grid', false);

    /* ----------------------------------------------------------------------
       分类索引栏
       ---------------------------------------------------------------------- */

    function toggleGroup(group, open) {
        var sub = group.querySelector('.rail-sub');
        var item = group.querySelector(':scope > .rail-item') || group.querySelector('.rail-item');

        group.classList.toggle('is-open', open);

        if (sub) {
            sub.hidden = !open;
        }

        if (item) {
            item.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function openRail() {
        body.classList.add('rail-open');

        if (railBackdrop) {
            railBackdrop.hidden = false;
        }

        if (railClose) {
            railClose.focus();
        }
    }

    function closeRail() {
        if (!body.classList.contains('rail-open')) {
            return;
        }

        body.classList.remove('rail-open');

        if (railBackdrop) {
            railBackdrop.hidden = true;
        }
    }

    if (railOpen) {
        railOpen.addEventListener('click', openRail);
    }

    if (railClose) {
        railClose.addEventListener('click', closeRail);
    }

    if (railBackdrop) {
        railBackdrop.addEventListener('click', closeRail);
    }

    if (railNav) {
        railNav.addEventListener('click', function (event) {
            var item = event.target.closest ? event.target.closest('.rail-item') : null;

            if (!item) {
                return;
            }

            var group = item.closest('.rail-group');
            var caretClick = event.target.closest && event.target.closest('.rail-caret');

            // 点击折叠箭头只负责展开/收起，不改变当前分类。
            if (caretClick && group) {
                toggleGroup(group, !group.classList.contains('is-open'));
                return;
            }

            setCategory(item.getAttribute('data-category'), true);

            if (group && group.classList.contains('has-children')) {
                toggleGroup(group, true);
            }

            if (isMobileLayout()) {
                closeRail();
            }
        });
    }

    function markActiveRail(category) {
        var activeItem = null;

        qsa('.rail-item').forEach(function (item) {
            var active = item.getAttribute('data-category') === category;
            item.classList.toggle('is-active', active);

            if (active) {
                activeItem = item;
            }
        });

        // 选中子分类时同步展开其父分组。
        if (activeItem && activeItem.classList.contains('is-sub')) {
            var parentGroup = activeItem.closest('.rail-group');

            if (parentGroup) {
                toggleGroup(parentGroup, true);
            }
        }

        if (railCurrent) {
            var label = activeItem
                ? (activeItem.getAttribute('data-name') || '全部')
                : '全部';

            railCurrent.textContent = label;
        }

        return activeItem;
    }

    /* ----------------------------------------------------------------------
       筛选：搜索 + 分类，按分组控制可见性
       ---------------------------------------------------------------------- */

    function gridInScope(grid, category) {
        if (category === 'all') {
            return true;
        }

        if (grid.getAttribute('data-grid-category') === category) {
            return true;
        }

        // 选中一级分类时，其下二级分类的网格也应一并展示。
        var group = grid.closest('.link-group');

        return !!(group && group.getAttribute('data-category') === category);
    }

    // 分类名映射：搜索时命中「分类/分组名称」（rail 侧栏自带 data-category / data-name）
    var categoryNameById = {};

    qsa('.rail-item').forEach(function (item) {
        var id = item.getAttribute('data-category');

        if (id && id !== 'all') {
            categoryNameById[id] = item.getAttribute('data-name') || '';
        }
    });

    function cardHaystack(card) {
        var catId = card.getAttribute('data-category') || '';

        return [
            card.getAttribute('data-name') || '',
            card.getAttribute('data-title') || '',
            card.getAttribute('data-domain') || '',
            card.getAttribute('data-host') || '',
            card.getAttribute('data-description') || '',
            card.getAttribute('data-url') || '',
            card.getAttribute('data-tags') || '',
            card.getAttribute('data-alias') || '',
            categoryNameById[catId] || ''
        ].join(' ').toLowerCase();
    }

    /* 自然语言语义扩展：search.js 加载后提供意图词典（单关键词时生效） */
    function semanticTermsOf() {
        if (!window.XYSearchSemantic || !state.query || state.query.indexOf(' ') !== -1) {
            return null;
        }

        try {
            var terms = window.XYSearchSemantic(state.query);

            return (terms && terms.length) ? terms : null;
        } catch (error) {
            return null;
        }
    }

    function cardMatches(card) {
        if (!state.query) {
            return true;
        }

        var terms = state.query.split(/\s+/).filter(function (term) {
            return term.length > 0;
        });

        if (terms.length === 0) {
            return true;
        }

        var haystack = cardHaystack(card);

        var direct = terms.every(function (term) {
            if (haystack.indexOf(term) !== -1) {
                return true;
            }

            return cardPinyin(card).indexOf(term) !== -1;
        });

        if (direct) {
            return true;
        }

        // 语义扩展：如「看视频的网站」→ 命中 视频/影视/电影 等扩展关键词
        var semanticTerms = semanticTermsOf();

        if (!semanticTerms) {
            return false;
        }

        return semanticTerms.some(function (term) {
            return haystack.indexOf(term) !== -1 || cardPinyin(card).indexOf(term) !== -1;
        });
    }

    var pinyinSupported = typeof window.pinyinPro !== 'undefined' &&
        typeof window.pinyinPro.pinyin === 'function';

    function cardPinyin(card) {
        if (card.__pinyinCache !== undefined) {
            return card.__pinyinCache;
        }

        var cache = '';

        if (pinyinSupported) {
            var source = (card.getAttribute('data-name') || '') + ' ' +
                (card.getAttribute('data-title') || '');

            if (/[\u3400-\u9fff]/.test(source)) {
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

                    cache = full + ' ' + abbr;
                } catch (error) {
                    cache = '';
                }
            }
        }

        card.__pinyinCache = cache;
        return cache;
    }

    function applyFilters() {
        var visibleTotal = 0;
        var filtering = state.category !== 'all' || state.query !== '';

        groups.forEach(function (group) {
            var groupVisible = 0;
            var grids = qsa('.site-grid', group);

            grids.forEach(function (grid) {
                var inScope = gridInScope(grid, state.category);
                var gridVisible = 0;

                qsa('.site-card', grid).forEach(function (card) {
                    var show = inScope && cardMatches(card);
                    card.hidden = !show;

                    if (show) {
                        gridVisible++;
                    }
                });

                grid.hidden = gridVisible === 0;
                groupVisible += gridVisible;
            });

            // 二级分组标题跟随其后的网格一起显示/隐藏。
            toArray(group.children).forEach(function (node) {
                if (!node.classList.contains('group-subhead')) {
                    return;
                }

                var next = node.nextElementSibling;
                node.hidden = !next || next.hidden;
            });

            group.hidden = groupVisible === 0;
            visibleTotal += groupVisible;

            var counter = qsa('.group-count', group)[0];

            if (counter) {
                counter.textContent = groupVisible;
            }
        });

        if (sectionTitle) {
            if (state.query) {
                sectionTitle.textContent = '搜索结果';
            } else if (state.category !== 'all') {
                var activeItem = qsa('.rail-item.is-active')[0];
                sectionTitle.textContent = activeItem
                    ? (activeItem.getAttribute('data-name') || '全部网站')
                    : '全部网站';
            } else {
                sectionTitle.textContent = '全部网站';
            }
        }

        if (sectionCount) {
            sectionCount.textContent = visibleTotal + ' 个网站';
        }

        if (sectionAside) {
            sectionAside.hidden = filtering;
        }

        if (hotStrip) {
            hotStrip.hidden = filtering;
        }

        if (emptyState) {
            emptyState.hidden = visibleTotal !== 0;
        }

        if (visibleTotal === 0 && emptyState) {
            if (state.query) {
                if (emptyTitle) emptyTitle.textContent = '没有找到匹配的网站';
                if (emptyText) emptyText.textContent = '换个关键词试试，或清除筛选查看全部网站。';
            } else if (state.category !== 'all') {
                if (emptyTitle) emptyTitle.textContent = '该分类下暂无网站';
                if (emptyText) emptyText.textContent = '切换其他分类看看，或查看全部网站。';
            } else {
                // 用页面初始文案（区分登录用户/访客）作为兜底，不再写死后台提示
                if (emptyTitle) emptyTitle.textContent = emptyDefaultTitle;
                if (emptyText) emptyText.textContent = emptyDefaultText;
            }
        }

        if (emptyReset) {
            emptyReset.hidden = totalCards === 0;
        }
    }

    function setCategory(category, shouldApply) {
        state.category = category || 'all';
        markActiveRail(state.category);

        if (shouldApply !== false) {
            applyFilters();

            // 分类切换后平滑滚动到结果区域顶部（快速定位，减少翻找）
            var target = document.getElementById('siteMain');
            if (target && typeof target.scrollIntoView === 'function') {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function updateSearchClear() {
        if (searchClear) {
            searchClear.hidden = !searchInput || searchInput.value.length === 0;
        }
    }

    function commitSearch() {
        if (!searchInput) {
            return;
        }

        if (searchTimer) {
            clearTimeout(searchTimer);
            searchTimer = null;
        }

        state.query = searchInput.value.trim().toLowerCase();
        applyFilters();
    }

    /* ----------------------------------------------------------------------
       搜索方式：站内 / 百度 / 必应 / Google
       ---------------------------------------------------------------------- */

    function readStoredEngine() {
        try {
            return localStorage.getItem('xy-nav-engine') || 'site';
        } catch (error) {
            return 'site';
        }
    }

    var currentEngine = SEARCH_ENGINES[readStoredEngine()] ? readStoredEngine() : 'site';

    function applyEngine(engine, persist) {
        if (!SEARCH_ENGINES[engine]) {
            engine = 'site';
        }

        currentEngine = engine;

        if (searchEngineLabel) {
            searchEngineLabel.textContent = SEARCH_ENGINES[engine].label;
        }

        if (searchInput) {
            searchInput.setAttribute('placeholder', SEARCH_ENGINES[engine].placeholder);
        }

        qsa('.search-engine-option', searchEngineMenu).forEach(function (option) {
            var active = option.getAttribute('data-engine') === engine;
            option.classList.toggle('is-active', active);
            option.setAttribute('aria-checked', active ? 'true' : 'false');
        });

        if (persist !== false) {
            store('xy-nav-engine', engine);
        }
    }

    function closeEngineMenu() {
        if (searchEngineMenu) {
            searchEngineMenu.hidden = true;
        }

        if (searchEngineToggle) {
            searchEngineToggle.setAttribute('aria-expanded', 'false');
        }
    }

    function openExternalSearch() {
        if (!searchInput) {
            return;
        }

        var keyword = searchInput.value.trim();

        if (keyword === '') {
            searchInput.focus();
            return;
        }

        var engine = SEARCH_ENGINES[currentEngine];

        if (!engine || !engine.url) {
            commitSearch();
            return;
        }

        window.open(engine.url + encodeURIComponent(keyword), '_blank', 'noopener');
    }

    if (searchEngineToggle && searchEngineMenu) {
        searchEngineToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = searchEngineMenu.hidden;
            searchEngineMenu.hidden = !open;
            searchEngineToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        qsa('.search-engine-option', searchEngineMenu).forEach(function (option) {
            option.addEventListener('click', function () {
                applyEngine(option.getAttribute('data-engine'));
                closeEngineMenu();

                if (searchInput) {
                    searchInput.focus();
                }
            });
        });

        document.addEventListener('click', function (event) {
            if (!searchEngineMenu.hidden && searchEngine && !searchEngine.contains(event.target)) {
                closeEngineMenu();
            }
        });
    }

    applyEngine(currentEngine, false);

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            updateSearchClear();

            if (searchTimer) {
                clearTimeout(searchTimer);
            }

            searchTimer = setTimeout(commitSearch, 110);
        });

        searchInput.addEventListener('search', function () {
            if (!searchInput.value) {
                state.query = '';
                updateSearchClear();
                applyFilters();
            }
        });

        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();

                if (currentEngine !== 'site') {
                    openExternalSearch();
                } else {
                    commitSearch();
                }
            } else if (event.key === 'Escape') {
                searchInput.value = '';
                state.query = '';
                updateSearchClear();
                applyFilters();
                searchInput.blur();
            }
        });
    }

    if (searchClear) {
        searchClear.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }

            state.query = '';
            updateSearchClear();
            applyFilters();
        });
    }

    if (searchSubmit) {
        searchSubmit.addEventListener('click', function () {
            if (currentEngine !== 'site') {
                openExternalSearch();
                return;
            }

            commitSearch();

            var main = document.getElementById('siteMain');

            if (main && typeof main.scrollIntoView === 'function') {
                main.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    if (emptyReset) {
        emptyReset.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
            }

            state.query = '';
            updateSearchClear();
            setCategory('all', true);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        var target = event.target;
        var typing = target && (
            target.tagName === 'INPUT' ||
            target.tagName === 'TEXTAREA' ||
            target.isContentEditable
        );

        if (event.key === '/' && !typing && searchInput) {
            event.preventDefault();
            searchInput.focus();
            searchInput.select();
        }
    });

    updateSearchClear();
    setCategory('all', false);
    applyFilters();

    /* ----------------------------------------------------------------------
       公告滚动：按文本长度调整时长，避免长文本一闪而过
       ---------------------------------------------------------------------- */

    (function initAnnouncement() {
        var text = document.querySelector('.announce-text');

        if (!text || !text.classList.contains('is-scrolling')) {
            return;
        }

        var width = text.scrollWidth || 0;

        if (width > 0) {
            var seconds = Math.max(18, Math.round(width / 42));

            if (seconds !== 26) {
                text.style.animationDuration = seconds + 's';
            }
        }
    })();

    /* ----------------------------------------------------------------------
       favicon：站点 favicon → 第三方图标服务 → 首字母占位
       ---------------------------------------------------------------------- */

    function initFavicons() {
        qsa('.site-favicon').forEach(function (img) {
            var chain = [
                img.getAttribute('data-src'),
                img.getAttribute('data-fb1'),
                img.getAttribute('data-fb2'),
                img.getAttribute('data-fb3'),
                img.getAttribute('data-fb4')
            ].filter(function (url) {
                return typeof url === 'string' && url.length > 0;
            });

            if (!chain.length) {
                return;
            }

            var index = 0;

            img.addEventListener('load', function () {
                img.classList.add('is-loaded');
            });

            img.addEventListener('error', function () {
                index++;

                if (index < chain.length) {
                    img.src = chain[index];
                } else {
                    img.classList.remove('is-loaded');
                }
            });

            img.src = chain[index];
        });
    }

    initFavicons();

    /* ----------------------------------------------------------------------
       可用性 / 速度检测
       ---------------------------------------------------------------------- */

    var statusQueue = [];
    var statusRunning = 0;
    var STATUS_LIMIT = 4;
    var STATUS_TIMEOUT = 6000;

    function queueStatus(card) {
        if (!card || card.getAttribute('data-status-requested')) {
            return;
        }

        card.setAttribute('data-status-requested', '1');
        statusQueue.push(card);
        pumpStatus();
    }

    function pumpStatus() {
        while (statusRunning < STATUS_LIMIT && statusQueue.length > 0) {
            statusRunning++;
            probeStatus(statusQueue.shift()).then(function () {
                statusRunning--;
                pumpStatus();
            }, function () {
                statusRunning--;
                pumpStatus();
            });
        }
    }

    function probeWithImage(card) {
        return new Promise(function (resolve) {
            var host = card.getAttribute('data-host') || '';
            var started = Date.now();
            var done = false;
            var image = new Image();
            var timer = setTimeout(function () {
                if (done) {
                    return;
                }
                done = true;
                setStatus(card, 'offline');
                resolve();
            }, STATUS_TIMEOUT);

            image.onload = function () {
                if (done) {
                    return;
                }
                done = true;
                clearTimeout(timer);
                setStatus(card, 'online', Math.max(1, Date.now() - started));
                resolve();
            };

            image.onerror = function () {
                if (done) {
                    return;
                }
                done = true;
                clearTimeout(timer);
                setStatus(card, 'offline');
                resolve();
            };

            image.src = 'https://' + host + '/favicon.ico?nav_probe=' + Date.now();
        });
    }

    function probeStatus(card) {
        var url = card.getAttribute('data-url');

        if (!url) {
            setStatus(card, 'offline');
            return Promise.resolve();
        }

        if (!window.fetch || typeof window.AbortController === 'undefined') {
            return probeWithImage(card);
        }

        return probeOnce(url).then(function (ms) {
            if (ms !== null) {
                setStatus(card, 'online', ms);
                return;
            }

            // 首次探测失败（超时或被中断）时再试一次，避免网络抖动导致误报「无法访问」
            return probeOnce(url).then(function (retryMs) {
                if (retryMs !== null) {
                    setStatus(card, 'online', retryMs);
                } else {
                    setStatus(card, 'offline');
                }
            });
        });
    }

    /** 单次探测：成功返回耗时毫秒，失败（超时 / 被中断）返回 null。 */
    function probeOnce(url) {
        var controller = new AbortController();
        var timer = setTimeout(function () {
            controller.abort();
        }, STATUS_TIMEOUT);
        var started = window.performance && performance.now ? performance.now() : Date.now();

        return fetch(url, {
            method: 'HEAD',
            mode: 'no-cors',
            cache: 'no-store',
            redirect: 'follow',
            credentials: 'omit',
            referrerPolicy: 'no-referrer',
            signal: controller.signal
        }).then(function () {
            var ended = window.performance && performance.now ? performance.now() : Date.now();
            return Math.max(1, Math.round(ended - started));
        }).catch(function () {
            return null;
        }).then(function (ms) {
            clearTimeout(timer);
            return ms;
        });
    }

    function setStatus(card, status, ms) {
        var statusEl = card.querySelector('.site-status');
        var speedEl = card.querySelector('.site-speed');

        card.setAttribute('data-status', status);

        if (status === 'offline') {
            card.classList.add('is-offline');
        } else {
            card.classList.remove('is-offline');
        }

        if (statusEl) {
            var label = status === 'online' ? '在线' : (status === 'offline' ? '离线' : '检测中');
            statusEl.setAttribute('data-state', status);
            statusEl.setAttribute('data-label', label);
            statusEl.setAttribute('aria-label', '网站状态：' + label);
        }

        if (!speedEl) {
            return;
        }

        if (status === 'online' && typeof ms === 'number' && isFinite(ms)) {
            var level = 'mid';
            var levelLabel = '一般';

            if (ms < 300) {
                level = 'fast';
                levelLabel = '快';
            } else if (ms > 1000) {
                level = 'slow';
                levelLabel = '慢';
            }

            speedEl.textContent = ms + ' 毫秒 · ' + levelLabel;
            speedEl.setAttribute('data-level', level);
            speedEl.setAttribute('data-state', 'ok');
            speedEl.setAttribute('title', '响应时间约 ' + ms + ' 毫秒');
        } else {
            speedEl.textContent = status === 'offline' ? '无法访问' : '检测中';
            speedEl.removeAttribute('data-level');
            speedEl.setAttribute('data-state', status === 'offline' ? 'error' : 'pending');
            speedEl.setAttribute('title', status === 'offline' ? '网站当前无法访问' : '正在检测网站状态');
        }
    }

    /* ----------------------------------------------------------------------
       官方网站标题：异步获取，失败时保留后台名称
       ---------------------------------------------------------------------- */

    var titleQueue = [];
    var titleRunning = 0;
    var TITLE_LIMIT = 3;
    var titleCache = new Map();

    function queueTitle(card) {
        if (!card || card.getAttribute('data-title-requested')) {
            return;
        }

        card.setAttribute('data-title-requested', '1');
        titleQueue.push(card);
        pumpTitle();
    }

    function pumpTitle() {
        while (titleRunning < TITLE_LIMIT && titleQueue.length > 0) {
            titleRunning++;
            loadTitle(titleQueue.shift()).then(function () {
                titleRunning--;
                pumpTitle();
            }, function () {
                titleRunning--;
                pumpTitle();
            });
        }
    }

    function loadTitle(card) {
        var url = card.getAttribute('data-url');

        if (!url || !window.fetch) {
            return Promise.resolve();
        }

        var promise = titleCache.get(url);

        if (!promise) {
            promise = fetch('api/site_meta.php?url=' + encodeURIComponent(url), {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                return data && data.ok && typeof data.title === 'string' ? data.title.trim() : '';
            }).catch(function () {
                return '';
            });

            titleCache.set(url, promise);
        }

        return promise.then(function (title) {
            if (title) {
                applyOfficialTitle(card, title);
            }
        });
    }

    function applyOfficialTitle(card, title) {
        var clean = title.replace(/\s+/g, ' ').trim();

        if (clean.length < 2 || clean.length > 120) {
            return;
        }

        if (/^(403|404|500|502|503|forbidden|access denied|just a moment|attention required|cloudflare|website|home|index|网站首页|首页)$/i.test(clean)) {
            return;
        }

        card.setAttribute('data-title', clean);

        var nameEl = card.querySelector('.site-name');

        if (nameEl && nameEl.textContent.trim() !== clean) {
            nameEl.textContent = clean;
            nameEl.setAttribute('title', clean);
        }

        var domain = card.getAttribute('data-domain') || '';

        if (domain) {
            card.setAttribute('aria-label', '访问 ' + clean + '（' + domain + '）');
        }

        if (state.query) {
            applyFilters();
        }
    }

    /* ----------------------------------------------------------------------
       懒检测：进入视口后再请求，避免阻塞首屏
       ---------------------------------------------------------------------- */

    function startMonitoring() {
        if (!cards.length) {
            return;
        }

        var monitoringEnabled = body.getAttribute('data-monitor') !== '0';

        function process(card) {
            if (monitoringEnabled) {
                queueStatus(card);
            }

            queueTitle(card);
        }

        if (!('IntersectionObserver' in window)) {
            cards.forEach(process);
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }

                process(entry.target);
                observer.unobserve(entry.target);
            });
        }, {
            root: null,
            rootMargin: '240px 0px',
            threshold: 0.01
        });

        cards.forEach(function (card) {
            observer.observe(card);
        });
    }

    if (document.readyState === 'complete') {
        setTimeout(startMonitoring, 300);
    } else {
        window.addEventListener('load', function () {
            setTimeout(startMonitoring, 300);
        }, { once: true });
    }

    /* ----------------------------------------------------------------------
       点击量统计
       ---------------------------------------------------------------------- */

    function formatClicks(value) {
        var number = parseInt(value, 10);

        if (!isFinite(number) || number < 0) {
            number = 0;
        }

        return number.toLocaleString('zh-CN') + ' 次';
    }

    function trackClick(element) {
        var id = element.getAttribute('data-id');

        if (!id || typeof window.URLSearchParams === 'undefined') {
            return;
        }

        var current = parseInt(element.getAttribute('data-clicks') || '0', 10);

        if (!isFinite(current) || current < 0) {
            current = 0;
        }

        var next = current + 1;
        element.setAttribute('data-clicks', String(next));

        var clicksEl = element.querySelector('.site-clicks');

        if (clicksEl) {
            clicksEl.textContent = formatClicks(next);
            clicksEl.setAttribute('title', '累计点击 ' + next + ' 次');
        }

        var hotCountEl = element.querySelector('.hot-count');

        if (hotCountEl) {
            hotCountEl.textContent = String(next);
        }

        var payload = new URLSearchParams();
        payload.set('id', id);

        var sent = false;

        if (navigator.sendBeacon) {
            try {
                sent = navigator.sendBeacon('api/track_click.php', payload);
            } catch (error) {
                sent = false;
            }
        }

        if (!sent && window.fetch) {
            fetch('api/track_click.php', {
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

    cards.concat(hotItems).forEach(function (element) {
        element.addEventListener('click', function () {
            trackClick(element);
        });

        element.addEventListener('auxclick', function (event) {
            if (event.button === 1) {
                trackClick(element);
            }
        });
    });

    /* ----------------------------------------------------------------------
       卡片 3D 悬浮倾斜（仅在精细指针 + 允许动效时启用）
       ---------------------------------------------------------------------- */

    (function initTilt() {
        var finePointer = window.matchMedia
            && window.matchMedia('(hover: hover) and (pointer: fine)').matches;

        if (!finePointer || prefersReducedMotion || !cards.length) {
            return;
        }

        root.setAttribute('data-tilt', '1');

        var MAX_DEG = 6;

        cards.forEach(function (card) {
            var rect = null;
            var frame = 0;
            var rx = 0;
            var ry = 0;

            function paint() {
                frame = 0;
                card.style.setProperty('--rx', rx.toFixed(2) + 'deg');
                card.style.setProperty('--ry', ry.toFixed(2) + 'deg');
            }

            function schedule() {
                if (!frame) {
                    frame = window.requestAnimationFrame(paint);
                }
            }

            card.addEventListener('pointerenter', function () {
                rect = card.getBoundingClientRect();
            });

            card.addEventListener('pointermove', function (event) {
                if (!rect) {
                    rect = card.getBoundingClientRect();
                }

                if (!rect.width || !rect.height) {
                    return;
                }

                var x = (event.clientX - rect.left) / rect.width;
                var y = (event.clientY - rect.top) / rect.height;

                ry = (x - 0.5) * 2 * MAX_DEG;
                rx = -(y - 0.5) * 2 * MAX_DEG;
                schedule();
            });

            card.addEventListener('pointerleave', function () {
                rect = null;
                rx = 0;
                ry = 0;
                schedule();
            });
        });
    })();

    /* ----------------------------------------------------------------------
       回到顶部
       ---------------------------------------------------------------------- */

    if (toTop) {
        var scrollTicking = false;

        function updateToTop() {
            toTop.classList.toggle('is-visible', window.scrollY > 320);
        }

        toTop.addEventListener('click', function () {
            window.scrollTo({
                top: 0,
                behavior: prefersReducedMotion ? 'auto' : 'smooth'
            });
        });

        window.addEventListener('scroll', function () {
            if (scrollTicking) {
                return;
            }

            scrollTicking = true;
            window.requestAnimationFrame(function () {
                updateToTop();
                scrollTicking = false;
            });
        }, { passive: true });

        updateToTop();
    }

    /* ----------------------------------------------------------------------
       登录 / 注册 统一弹窗（v2.4.0）
       ---------------------------------------------------------------------- */

    var authTrigger = document.getElementById('authTrigger');
    var authModal = document.getElementById('authModal');
    var authClose = document.getElementById('authClose');
    var authError = document.getElementById('authError');
    var authTabs = qsa('.auth-tab');
    var authPanels = qsa('.auth-panel');

    function showAuthError(message) {
        if (!authError) return;
        if (!message) {
            authError.hidden = true;
            authError.textContent = '';
            return;
        }
        authError.textContent = message;
        authError.hidden = false;
    }

    function switchAuthTab(tab) {
        authTabs.forEach(function (btn) {
            btn.classList.toggle('is-active', btn.getAttribute('data-tab') === tab);
        });
        authPanels.forEach(function (panel) {
            var match = (panel.id === 'authLoginForm' && tab === 'login')
                     || (panel.id === 'authRegisterForm' && tab === 'register');
            panel.classList.toggle('is-active', match);
            panel.hidden = !match;
        });
        showAuthError('');
    }

    if (authTrigger && authModal && typeof authModal.showModal === 'function') {
        authTrigger.addEventListener('click', function () {
            // 切换登录/注册 tab 默认值：URL 参数 ?mode=register 可直开注册
            var hashMode = ((window.location.search || '').match(/[?&]mode=(login|register)/) || [])[1];
            switchAuthTab(hashMode === 'register' && authTabs.some(function (t) { return t.getAttribute('data-tab') === 'register'; }) ? 'register' : 'login');
            authModal.showModal();
        });
    }

    if (authClose && authModal) {
        authClose.addEventListener('click', function () {
            if (typeof authModal.close === 'function') authModal.close();
        });
    }

    authTabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            switchAuthTab(btn.getAttribute('data-tab'));
        });
    });

    // 登录
    var authLoginSubmit = document.getElementById('authLoginSubmit');
    var authLoginForm = document.getElementById('authLoginForm');

    if (authLoginSubmit && authLoginForm) {
        authLoginForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var account = (document.getElementById('loginAccount').value || '').trim();
            var password = document.getElementById('loginPassword').value || '';

            if (!account || !password) {
                showAuthError('请填写账号与密码');
                return;
            }

            authLoginSubmit.disabled = true;
            showAuthError('');

            var body = new URLSearchParams();
            body.set('action', 'login');
            body.set('account', account);
            body.set('password', password);

            fetch('api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
                credentials: 'same-origin',
            }).then(function (resp) { return resp.json().catch(function () { return { ok: false, error: '网络响应异常' }; }); })
              .then(function (data) {
                  if (!data || !data.ok) {
                      showAuthError((data && data.error) || '登录失败，请检查账号与密码');
                      return;
                  }
                  // 登录成功：跳转 my.php（后端已在 login action 启动 session）
                  window.location.href = data.redirect || 'my.php';
              })
              .catch(function (err) { showAuthError('网络错误，请稍后重试'); })
              .then(function () { authLoginSubmit.disabled = false; });
        });
    }

    // 注册
    var authRegisterSubmit = document.getElementById('authRegisterSubmit');
    var authRegisterForm = document.getElementById('authRegisterForm');

    if (authRegisterSubmit && authRegisterForm) {
        authRegisterForm.addEventListener('submit', function (event) {
            event.preventDefault();

            var username = (document.getElementById('regUsername').value || '').trim();
            var email = (document.getElementById('regEmail').value || '').trim();
            var password = document.getElementById('regPassword').value || '';
            var passwordConfirm = document.getElementById('regPasswordConfirm').value || '';

            if (username.length < 2 || username.length > 24) { showAuthError('用户名长度需在 2-24 字符之间'); return; }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showAuthError('邮箱格式不正确'); return; }
            if (password.length < 6) { showAuthError('密码至少 6 位'); return; }
            if (password !== passwordConfirm) { showAuthError('两次输入的密码不一致'); return; }

            authRegisterSubmit.disabled = true;
            showAuthError('');

            var body = new URLSearchParams();
            body.set('action', 'register');
            body.set('username', username);
            body.set('email', email);
            body.set('password', password);
            body.set('password_confirm', passwordConfirm);

            fetch('api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
                credentials: 'same-origin',
            }).then(function (resp) { return resp.json().catch(function () { return { ok: false, error: '网络响应异常' }; }); })
              .then(function (data) {
                  if (!data || !data.ok) {
                      showAuthError((data && data.error) || '注册失败，请稍后再试');
                      return;
                  }
                  // 注册成功：后端已自动登录，直接跳转 my.php
                  window.location.href = data.redirect || 'my.php';
              })
              .catch(function (err) { showAuthError('网络错误，请稍后重试'); })
              .then(function () { authRegisterSubmit.disabled = false; });
        });
    }

    /* ----------------------------------------------------------------------
       全局搜索组件桥接（v2.5.0）
       - search.js（联想 / 历史 / 语义词典）通过此接口驱动页内过滤与分类切换
       - 支持 index.php?s=关键词 与 index.php?cat=分类ID 直达
       ---------------------------------------------------------------------- */

    window.XYNavSearch = {
        apply: function (query) {
            var q = String(query || '').trim();

            if (!searchInput) {
                return;
            }

            searchInput.value = q;

            if (searchClear) {
                searchClear.hidden = q.length === 0;
            }

            state.query = q.toLowerCase();
            applyFilters();
        },
        setCategory: function (id) {
            var target = null;

            qsa('.rail-item').forEach(function (item) {
                if (item.getAttribute('data-category') === String(id)) {
                    target = item;
                }
            });

            if (target) {
                target.click();
            }
        },
        query: function () {
            return searchInput ? searchInput.value.trim() : '';
        }
    };

    (function applyUrlParams() {
        if (typeof window.URLSearchParams === 'undefined') {
            return;
        }

        var params = new URLSearchParams(window.location.search);
        var cat = params.get('cat');
        var keyword = params.get('s');

        if (cat && /^[0-9]+$/.test(cat)) {
            var target = null;

            qsa('.rail-item').forEach(function (item) {
                if (item.getAttribute('data-category') === cat) {
                    target = item;
                }
            });

            if (target) {
                target.click();
            }
        }

        if (keyword) {
            if (searchInput) {
                searchInput.value = keyword;
            }

            if (searchClear) {
                searchClear.hidden = false;
            }

            state.query = keyword.trim().toLowerCase();
            applyFilters();
        }
    })();
})();
