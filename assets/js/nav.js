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
    var mobileCats = document.getElementById('mobileCats');
    var mobileCatsTrack = document.getElementById('mobileCatsTrack');
    var mobileCatsMore = document.getElementById('mobileCatsMore');
    var sectionTitle = document.getElementById('sectionTitle');
    var sectionCount = document.getElementById('sectionCount');
    var sectionAside = document.getElementById('sectionAside');
    var emptyState = document.getElementById('emptyState');
    var emptyTitle = document.getElementById('emptyTitle');
    var emptyText = document.getElementById('emptyText');
    var emptyReset = document.getElementById('emptyReset');
    var filterBar = document.getElementById('filterBar');
    var filterBarWrap = document.getElementById('filterBarWrap');
    var filterKeyword = document.getElementById('filterKeyword');
    var filterKeywordText = document.getElementById('filterKeywordText');
    var filterCategory = document.getElementById('filterCategory');
    var filterCategoryText = document.getElementById('filterCategoryText');
    var filterClearKeyword = document.getElementById('filterClearKeyword');
    var filterClearCategory = document.getElementById('filterClearCategory');
    var filterResetAll = document.getElementById('filterResetAll');
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

    // 默认搜索引擎（兜底，API 加载失败时使用）
    var SEARCH_ENGINES = {
        site:   { label: '站内', placeholder: '搜索网站或粘贴网址', url: '' },
        baidu:  { label: '百度', placeholder: '在百度中搜索', url: 'https://www.baidu.com/s?wd=' },
        bing:   { label: '必应', placeholder: '在必应中搜索', url: 'https://www.bing.com/search?q=' },
        google: { label: '谷歌', placeholder: '在 Google 中搜索', url: 'https://www.google.com/search?q=' }
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

                // 点击反馈：图标做一次旋转进场，让「已切换」这件事看得见
                themeToggle.classList.remove('is-switching');
                // 强制回流，保证连续点击也能重放动画
                void themeToggle.offsetWidth;
                themeToggle.classList.add('is-switching');
                window.setTimeout(function () {
                    themeToggle.classList.remove('is-switching');
                }, 520);
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

        /* 通知卡片交互：切换模式后收起紧凑模式里已展开的操作条 */
        document.dispatchEvent(new CustomEvent('nav:modechange', { detail: { mode: next } }));
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

    if (mobileCats) {
        mobileCats.addEventListener('click', function (event) {
            var chip = event.target.closest ? event.target.closest('.mcat') : null;

            if (!chip) {
                return;
            }

            // 再次点击当前分类时返回「全部」，方便单手快速切回。
            var target = chip.classList.contains('is-active') && chip.getAttribute('data-category') !== 'all'
                ? 'all'
                : chip.getAttribute('data-category');

            setCategory(target, true);
        });
    }

    if (mobileCatsMore) {
        mobileCatsMore.addEventListener('click', openRail);
    }

    function syncMobileChips(category) {
        if (!mobileCats) {
            return;
        }

        qsa('.mcat').forEach(function (chip) {
            var active = chip.getAttribute('data-category') === category;
            chip.classList.toggle('is-active', active);
            chip.setAttribute('aria-selected', active ? 'true' : 'false');

            if (!active || !mobileCatsTrack) {
                return;
            }

            var chipLeft = chip.offsetLeft;
            var chipRight = chipLeft + chip.offsetWidth;
            var viewLeft = mobileCatsTrack.scrollLeft;
            var viewRight = viewLeft + mobileCatsTrack.clientWidth;

            if (chipLeft < viewLeft + 8) {
                mobileCatsTrack.scrollTo({ left: Math.max(0, chipLeft - 12), behavior: 'smooth' });
            } else if (chipRight > viewRight - 8) {
                mobileCatsTrack.scrollTo({ left: chipRight - mobileCatsTrack.clientWidth + 12, behavior: 'smooth' });
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

        // 移动端横向分类条同步选中态，并把它滚动到可视区域内。
        syncMobileChips(category);

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

        /* 排序切换在筛选状态下仍然可用：用户在筛选结果里也可能想换排序方式 */

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

        syncFilterBar();
    }

    /**
     * 筛选状态条：把「当前在筛选什么、命中多少条」讲清楚，并提供一键清除入口。
     */
    function syncFilterBar() {
        if (!filterBar) {
            return;
        }

        var hasKeyword = !!state.query;
        var hasCategory = state.category !== 'all';
        var filtering = hasKeyword || hasCategory;

        /* 折叠用 Grid 0fr → 1fr 过渡（见 nav.css），这里只切 data-open，不碰 hidden，
           否则 display:none 会让过渡失效 */
        if (filterBarWrap) {
            filterBarWrap.setAttribute('data-open', filtering ? '1' : '0');
            filterBarWrap.setAttribute('aria-hidden', filtering ? 'false' : 'true');
        }

        /* 选定单一分类时，顶部大标题已经说明分类，再隐藏各分组的小标题避免重复；
           关键词筛选可能同时命中多个分组，保留分组标题仍有意义 */
        if (root) {
            root.classList.toggle('is-single-category', hasCategory);
        }

        if (filterKeyword) {
            filterKeyword.hidden = !hasKeyword;
        }

        if (filterKeywordText && hasKeyword) {
            filterKeywordText.textContent = searchInput ? searchInput.value.trim() : state.query;
        }

        var activeItem = qsa('.rail-item.is-active')[0];
        var catName = activeItem ? (activeItem.getAttribute('data-name') || '') : '';

        if (filterCategory) {
            filterCategory.hidden = !hasCategory || !catName;
        }

        if (filterCategoryText && catName) {
            filterCategoryText.textContent = catName;
        }
    }

    function clearAllFilters() {
        if (searchInput) {
            searchInput.value = '';
        }

        state.query = '';
        updateSearchClear();
        setCategory('all', true);
    }

    if (filterClearKeyword) {
        filterClearKeyword.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
                searchInput.focus();
            }

            state.query = '';
            updateSearchClear();
            applyFilters();
        });
    }

    if (filterClearCategory) {
        filterClearCategory.addEventListener('click', function () {
            setCategory('all', true);
        });
    }

    if (filterResetAll) {
        filterResetAll.addEventListener('click', clearAllFilters);
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
       搜索方式：站内 / 百度 / 必应 / Google（支持用户自定义 & 全局默认）
       ---------------------------------------------------------------------- */

    function readStoredEngine() {
        try {
            return localStorage.getItem('xy-nav-engine') || 'site';
        } catch (error) {
            return 'site';
        }
    }

    var currentEngine = SEARCH_ENGINES[readStoredEngine()] ? readStoredEngine() : 'site';

    /**
     * 渲染搜索引擎菜单
     */
    function renderEngineMenu() {
        if (!searchEngineMenu) {
            return;
        }

        // 清空现有选项
        searchEngineMenu.innerHTML = '';

        Object.keys(SEARCH_ENGINES).forEach(function (key) {
            var engine = SEARCH_ENGINES[key];
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'search-engine-option' + (key === currentEngine ? ' is-active' : '');
            btn.setAttribute('role', 'menuitemradio');
            btn.setAttribute('data-engine', key);
            btn.setAttribute('aria-checked', key === currentEngine ? 'true' : 'false');

            var nameSpan = document.createElement('span');
            nameSpan.className = 'search-engine-name';
            nameSpan.textContent = engine.label;

            var descSpan = document.createElement('span');
            descSpan.className = 'search-engine-desc';
            descSpan.textContent = engine.url ? '新标签页打开' : '筛选已收录网站';

            btn.appendChild(nameSpan);
            btn.appendChild(descSpan);
            searchEngineMenu.appendChild(btn);
        });

        // 添加"管理搜索引擎"入口（仅登录用户可见）
        if (typeof window.NAV_USER_ID !== 'undefined' && window.NAV_USER_ID > 0) {
            var manageBtn = document.createElement('button');
            manageBtn.type = 'button';
            manageBtn.className = 'search-engine-option search-engine-manage';
            manageBtn.setAttribute('role', 'menuitem');
            manageBtn.innerHTML = '<span class="search-engine-name">⚙ 管理搜索引擎</span><span class="search-engine-desc">添加、编辑、排序</span>';
            manageBtn.addEventListener('click', function () {
                closeEngineMenu();
                openSeModal();
            });
            searchEngineMenu.appendChild(manageBtn);
        }

        // 重新绑定选项点击事件
        qsa('.search-engine-option:not(.search-engine-manage)', searchEngineMenu).forEach(function (option) {
            option.addEventListener('click', function () {
                applyEngine(option.getAttribute('data-engine'));
                closeEngineMenu();

                if (searchInput) {
                    searchInput.focus();
                }
            });
        });
    }

    /**
     * 共享的搜索引擎请求：nav.js 与 search.js 都会用到同一份数据，
     * 挂在 window 上复用同一个 Promise，避免首屏重复请求同一接口。
     */
    function fetchEngines(force) {
        if (force || !window.__xyEnginesPromise) {
            window.__xyEnginesPromise = fetch('api/user_search_engines.php', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (resp) {
                    if (!resp.ok) throw new Error('HTTP ' + resp.status);
                    return resp.json();
                });
        }

        return window.__xyEnginesPromise;
    }

    /**
     * 从 API 加载搜索引擎列表（用户自定义优先，否则全局默认）
     */
    function loadSearchEngines(force) {
        fetchEngines(force === true)
            .then(function (data) {
                window.__xyEngines = data;

                if (!data.ok || !data.engines || !data.engines.length) {
                    return;
                }

                // 重建 SEARCH_ENGINES
                var newEngines = {};
                data.engines.forEach(function (e) {
                    var key = e.id;
                    newEngines[key] = {
                        label: e.name,
                        placeholder: e.url_template
                            ? '在 ' + e.name + ' 中搜索'
                            : '搜索网站或粘贴网址',
                        url: e.url_template
                    };
                });

                SEARCH_ENGINES = newEngines;

                // 校正当前选中的引擎
                if (!SEARCH_ENGINES[currentEngine]) {
                    currentEngine = 'site';
                }

                renderEngineMenu();
                applyEngine(currentEngine, false);

                if (typeof window.CustomEvent === 'function') {
                    window.dispatchEvent(new CustomEvent('xy:engines', { detail: data }));
                }
            })
            .catch(function () {
                // API 失败时清除缓存，避免后续复用这个失败态；同时回落到硬编码默认值
                window.__xyEnginesPromise = null;
                renderEngineMenu();
                applyEngine(currentEngine, false);
            });
    }

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

        // 支持 {q} 占位符，兼容旧版直接拼接 URL
        var url;
        if (engine.url.indexOf('{q}') !== -1) {
            url = engine.url.replace('{q}', encodeURIComponent(keyword));
        } else {
            url = engine.url + encodeURIComponent(keyword);
        }

        window.open(url, '_blank', 'noopener');
    }

    if (searchEngineToggle && searchEngineMenu) {
        searchEngineToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = searchEngineMenu.hidden;
            searchEngineMenu.hidden = !open;
            searchEngineToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        document.addEventListener('click', function (event) {
            if (!searchEngineMenu.hidden && searchEngine && !searchEngine.contains(event.target)) {
                closeEngineMenu();
            }
        });
    }

    // 先用默认值渲染（避免 API 加载延迟时菜单为空）
    renderEngineMenu();
    applyEngine(currentEngine, false);
    // 异步加载用户自定义 / 全局默认搜索引擎
    loadSearchEngines();

    /* ----------------------------------------------------------------------
       v2.9.2 · 「这串输入是网址而不是关键词」
       搜索框里粘贴网址时，交给 initUrlQuickSave 走收藏动线；
       此时不能再把它当关键词去过滤列表，否则筛选状态条会出现
       「关键词：https://xxx」，看着像是搜不到结果。
       ---------------------------------------------------------------------- */
    function looksLikeUrl(value) {
        var text = String(value || '').trim();

        if (!text || /\s/.test(text)) {
            return false;
        }

        return /^(?:https?:\/\/)?(?:[\w-]+\.)+[a-z]{2,}(?::\d{2,5})?(?:[/?#]\S*)?$/i.test(text);
    }

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            updateSearchClear();

            if (searchTimer) {
                clearTimeout(searchTimer);
                searchTimer = null;
            }

            // 网址态不参与站内筛选：清掉上一个关键词，把列表还原
            if (looksLikeUrl(searchInput.value)) {
                if (state.query !== '') {
                    state.query = '';
                    applyFilters();
                }

                return;
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
                // 网址态由 initUrlQuickSave 在捕获阶段接管收藏，这里不再当关键词提交
                if (looksLikeUrl(searchInput.value)) {
                    return;
                }

                event.preventDefault();

                if (currentEngine !== 'site') {
                    openExternalSearch();
                } else {
                    commitSearch();
                }
            } else if (event.key === 'Escape') {
                // 分两段：第一次 Esc 只收起联想并保留筛选结果，再按一次才清空关键词。
                // 避免用户在联想面板里按 Esc 时，结果区被一起清空而"闪回全部网站"。
                var suggestPanel = document.querySelector('.search-bar .ss-panel');

                // 联想面板由 search.js 统一渲染，状态以其可见性为准
                if (suggestPanel && !suggestPanel.hidden) {
                    event.preventDefault();
                    event.stopPropagation();
                    suggestPanel.hidden = true;
                    return;
                }

                event.preventDefault();

                if (searchInput.value !== '') {
                    event.preventDefault();
                    searchInput.value = '';
                    state.query = '';
                    updateSearchClear();
                    applyFilters();
                    return;
                }

                // 已经清空：再按一次才退出输入框
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

    /* ----------------------------------------------------------------------
       卡片排序：常用优先（服务端默认）/ 名称排序，仅影响展示顺序，不改动数据
       ---------------------------------------------------------------------- */

    var sortButtons = qsa('.section-tool[data-sort]');
    var sortMode = 'clicks';

    function naturalCompare(a, b) {
        // 名称排序按「自然序」：数字与中英文混排时更符合直觉（如 2 排在 10 前面）
        return a.localeCompare(b, 'zh-Hans-CN', { numeric: true, sensitivity: 'base' });
    }

    function applySort(mode) {
        sortMode = mode === 'name' ? 'name' : 'clicks';

        qsa('.site-grid').forEach(function (grid) {
            var cardsInGrid = toArray(grid.querySelectorAll('.site-card'));

            cardsInGrid.sort(function (a, b) {
                if (sortMode === 'name') {
                    var nameA = (a.getAttribute('data-name') || '').trim();
                    var nameB = (b.getAttribute('data-name') || '').trim();
                    var byName = naturalCompare(nameA, nameB);

                    if (byName !== 0) {
                        return byName;
                    }
                }

                var clicksA = parseInt(a.getAttribute('data-clicks') || '0', 10) || 0;
                var clicksB = parseInt(b.getAttribute('data-clicks') || '0', 10) || 0;

                return clicksB - clicksA;
            });

            cardsInGrid.forEach(function (card) {
                grid.appendChild(card);
            });
        });

        sortButtons.forEach(function (btn) {
            var active = btn.getAttribute('data-sort') === sortMode;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        try {
            localStorage.setItem('xy-nav-sort', sortMode);
        } catch (error) {
            // 隐私模式下 localStorage 可能不可写，忽略即可
        }
    }

    sortButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            applySort(btn.getAttribute('data-sort'));
        });
    });

    try {
        var savedSort = localStorage.getItem('xy-nav-sort');

        if (savedSort === 'name' || savedSort === 'clicks') {
            applySort(savedSort);
        }
    } catch (error) {
        // 忽略
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

    // 空状态里的「创建我的导航 / 登录」入口，直接拉起同一个登录注册弹窗
    qsa('[data-empty-auth]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            var triggerBtn = document.getElementById('authTrigger');

            if (!triggerBtn) {
                return;
            }

            var tab = trigger.getAttribute('data-empty-auth');
            var modal = document.getElementById('authModal');

            if (modal && (tab === 'register' || tab === 'login')) {
                modal.setAttribute('data-open-tab', tab);
            }

            triggerBtn.click();
        });
    });

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
    var failedTitleCache = new Map();

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

        if (failedTitleCache.has(url) && Date.now() - failedTitleCache.get(url) < 10 * 60 * 1000) {
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
                if (data && data.ok === false) {
                    // 服务端判定该地址被拦截或不可达时，短期记住失败结果，避免每次滚动都重复请求
                    failedTitleCache.set(url, Date.now());
                }

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

        // 抓取到的标题常是「WEB 应用防火墙」「Just a moment...」这类拦截页或营销语，
        // 直接覆盖用户自己起的名字会造成困扰。这里只把它作为「别名」参与搜索与提示，
        // 卡片主标题始终以用户填写的名称为准。
        var nameEl = card.querySelector('.site-name');
        var currentName = nameEl ? nameEl.textContent.trim() : '';

        card.setAttribute('data-title', clean);

        if (nameEl && currentName && currentName !== clean) {
            var existingAlias = card.getAttribute('data-alias') || '';
            var merged = existingAlias.toLowerCase().indexOf(clean.toLowerCase()) === -1
                ? (existingAlias ? existingAlias + ' ' + clean : clean)
                : existingAlias;

            card.setAttribute('data-alias', merged);
            nameEl.setAttribute('title', currentName + ' · ' + clean);
        } else if (nameEl && !currentName) {
            nameEl.textContent = clean;
            nameEl.setAttribute('title', clean);
        }

        var domain = card.getAttribute('data-domain') || '';

        if (domain) {
            card.setAttribute('aria-label', '访问 ' + (currentName || clean) + '（' + domain + '）');
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
            var preferred = authModal.getAttribute('data-open-tab');
            authModal.removeAttribute('data-open-tab');

            if (preferred === 'register' || preferred === 'login') {
                hashMode = preferred;
            }

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

    /* ----------------------------------------------------------------------
       v2.6.0 卡片交互：收藏星标 / 悬停操作 / 右键与长按菜单 / 快捷收藏
       ---------------------------------------------------------------------- */

    (function initCardV260() {
        var API = 'api/user_sites.php';
        var ctxMenu = document.getElementById('cardContextMenu');
        var quickAddDialog = document.getElementById('quickAddDialog');
        var qrDialog = document.getElementById('qrDialog');
        var moveDialog = document.getElementById('moveDialog');
        var editDialog = document.getElementById('editLinkDialog');
        var ctxCard = null;
        var moveId = 0;
        var toastTimer = null;
        var pressTimer = null;
        var suppressClick = false;

        function bind(id, ev, fn) {
            var el = document.getElementById(id);
            if (el) {
                el.addEventListener(ev, fn);
            }
        }

        function postForm(data) {
            return fetch(API, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: new URLSearchParams(data).toString()
            }).then(function (res) {
                return res.json();
            });
        }

        function showToast(text) {
            var toast = document.querySelector('.nav-toast');

            if (!toast) {
                toast = document.createElement('div');
                toast.className = 'nav-toast';
                toast.setAttribute('role', 'status');
                document.body.appendChild(toast);
            }

            toast.textContent = text;
            toast.classList.add('is-visible');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () {
                toast.classList.remove('is-visible');
            }, 2200);
        }

        function openDlg(dlg) {
            if (dlg && typeof dlg.showModal === 'function' && !dlg.open) {
                dlg.showModal();
            }
        }

        function closeDlg(dlg) {
            if (dlg && dlg.open) {
                dlg.close();
            }
        }

        function copyText(text, done) {
            function fallback() {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
                document.body.appendChild(ta);
                ta.select();

                try {
                    document.execCommand('copy');
                } catch (error) { /* 忽略 */ }

                document.body.removeChild(ta);

                if (done) {
                    done();
                }
            }

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(function () {
                    if (done) {
                        done();
                    }
                }, fallback);
            } else {
                fallback();
            }
        }

        function cardData(card) {
            return {
                id: card.getAttribute('data-id') || '',
                url: card.getAttribute('data-url') || card.getAttribute('href') || '',
                name: card.getAttribute('data-name') || '',
                category: card.getAttribute('data-category') || '0',
                description: card.getAttribute('data-description') || '',
                starred: card.getAttribute('data-starred') === '1',
                owner: card.getAttribute('data-owner') === '1'
            };
        }

        function updateStarIcon(card, starred) {
            card.setAttribute('data-starred', starred ? '1' : '0');

            var star = card.querySelector('.card-star');

            if (!star) {
                return;
            }

            var svg = star.querySelector('svg');

            if (svg) {
                svg.setAttribute('fill', starred ? 'currentColor' : 'none');
            }

            var label = starred ? '取消收藏' : '收藏';
            star.setAttribute('aria-label', label);
            star.setAttribute('title', label);

            /* 欠阻尼微弹：先摘类再强制重排，保证连续点按也能重启动画 */
            star.classList.remove('is-pop');
            void star.offsetWidth;
            star.classList.add('is-pop');

            window.setTimeout(function () {
                star.classList.remove('is-pop');
            }, 420);
        }

        function isGuest() {
            return !!document.getElementById('authTrigger');
        }

        function toggleStar(card) {
            var data = cardData(card);

            if (isGuest()) {
                showToast('请先登录后再收藏');

                var trigger = document.getElementById('authTrigger');

                if (trigger) {
                    trigger.click();
                }

                return;
            }

            if (data.owner) {
                postForm({ action: 'star', id: data.id, star: data.starred ? 0 : 1 }).then(function (res) {
                    if (res && res.ok) {
                        updateStarIcon(card, !data.starred);
                        showToast(!data.starred ? '已收藏' : '已取消收藏');
                    } else {
                        showToast((res && res.error) || '操作失败');
                    }
                }).catch(function () {
                    showToast('网络错误，请稍后重试');
                });
            } else {
                // 查看他人导航：快捷收藏到自己的导航并标记星标
                postForm({ action: 'quick_add', url: data.url }).then(function (res) {
                    if (res && res.ok) {
                        updateStarIcon(card, true);
                        showToast(res.duplicate ? '该网址已在你的导航中，已标记收藏' : '已收藏到你的导航');
                    } else {
                        showToast((res && res.error) || '收藏失败');
                    }
                }).catch(function () {
                    showToast('网络错误，请稍后重试');
                });
            }
        }

        function showQr(url) {
            if (!qrDialog) {
                return;
            }

            var img = document.getElementById('qrImage');
            var text = document.getElementById('qrUrl');

            if (img) {
                img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(url);
            }

            if (text) {
                text.textContent = url;
            }

            openDlg(qrDialog);
        }

        function openMove(card) {
            if (!moveDialog) {
                return;
            }

            moveId = cardData(card).id;

            var msg = document.getElementById('moveMsg');

            if (msg) {
                msg.textContent = '';
            }

            openDlg(moveDialog);
        }

        var editSaving = false;

        function openEditDialog(card) {
            if (!editDialog) {
                return;
            }

            var data = cardData(card);
            var idInput = document.getElementById('editLinkId');
            var urlInput = document.getElementById('editLinkUrl');
            var nameInput = document.getElementById('editLinkName');
            var catSelect = document.getElementById('editLinkCategory');
            var descInput = document.getElementById('editLinkDescription');
            var msg = document.getElementById('editLinkMsg');

            if (idInput) { idInput.value = data.id; }
            if (urlInput) { urlInput.value = data.url || ''; }
            if (nameInput) { nameInput.value = data.name || ''; }
            if (catSelect) { catSelect.value = data.category || '0'; }
            if (descInput) { descInput.value = data.description || ''; }
            if (msg) { msg.textContent = ''; msg.className = 'my-msg'; }

            openDlg(editDialog);

            if (urlInput) {
                setTimeout(function () { urlInput.focus(); }, 60);
            }
        }

        function closeEditDialog() {
            if (editDialog && typeof editDialog.close === 'function') {
                editDialog.close();
            } else if (editDialog) {
                editDialog.removeAttribute('open');
            }
        }

        function fetchEditMeta() {
            var urlInput = document.getElementById('editLinkUrl');
            var nameInput = document.getElementById('editLinkName');
            var descInput = document.getElementById('editLinkDescription');
            var msg = document.getElementById('editLinkMsg');
            var btn = document.getElementById('editFetchBtn');
            var url = urlInput ? urlInput.value.trim() : '';

            if (!url) {
                if (msg) { msg.textContent = '请先填写网址'; msg.className = 'my-msg is-error'; }
                return;
            }

            if (btn) { btn.disabled = true; }
            if (msg) { msg.textContent = '正在抓取网站信息…'; msg.className = 'my-msg is-success'; }

            postForm({ action: 'fetch', url: url }).then(function (res) {
                if (btn) { btn.disabled = false; }

                if (!res || !res.ok) {
                    if (msg) { msg.textContent = (res && res.error) || '抓取失败，可手动填写'; msg.className = 'my-msg is-error'; }
                    return;
                }

                var meta = res.meta || {};

                if (meta.title && nameInput && nameInput.value.trim() === '') {
                    nameInput.value = meta.title;
                }

                if (meta.description && descInput && descInput.value.trim() === '') {
                    descInput.value = meta.description;
                }

                if (urlInput && !/^https?:\/\//i.test(urlInput.value.trim())) {
                    urlInput.value = res.url || url;
                }

                if (msg) { msg.textContent = meta.title ? '已获取：' + meta.title : '未获取到标题，可手动填写'; msg.className = 'my-msg is-success'; }
            }).catch(function () {
                if (btn) { btn.disabled = false; }
                if (msg) { msg.textContent = '网络错误，请稍后重试'; msg.className = 'my-msg is-error'; }
            });
        }

        function saveEditLink() {
            if (editSaving) { return; }

            var idInput = document.getElementById('editLinkId');
            var urlInput = document.getElementById('editLinkUrl');
            var nameInput = document.getElementById('editLinkName');
            var catSelect = document.getElementById('editLinkCategory');
            var descInput = document.getElementById('editLinkDescription');
            var msg = document.getElementById('editLinkMsg');
            var saveBtn = document.getElementById('editLinkSave');

            var url = urlInput ? urlInput.value.trim() : '';

            if (!url) {
                if (msg) { msg.textContent = '请填写网址'; msg.className = 'my-msg is-error'; }
                if (urlInput) { urlInput.focus(); }
                return;
            }

            editSaving = true;
            if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = '保存中…'; }

            var payload = {
                action: 'update',
                id: idInput ? idInput.value : '',
                url: url,
                name: nameInput ? nameInput.value.trim() : '',
                category_id: catSelect ? catSelect.value : '0',
                description: descInput ? descInput.value.trim() : ''
            };

            postForm(payload).then(function (res) {
                editSaving = false;
                if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = '保存'; }

                if (!res || !res.ok) {
                    if (msg) { msg.textContent = (res && res.error) || '保存失败'; msg.className = 'my-msg is-error'; }
                    return;
                }

                if (msg) { msg.textContent = '保存成功'; msg.className = 'my-msg is-success'; }

                setTimeout(function () {
                    window.location.reload();
                }, 600);
            }).catch(function () {
                editSaving = false;
                if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = '保存'; }
                if (msg) { msg.textContent = '网络错误，请稍后重试'; msg.className = 'my-msg is-error'; }
            });
        }

        /* ------------------------------------------------------------------
           v2.9.2 · 快捷收藏后的就地收尾
           旧版靠 window.location.reload() 让新卡片出现，代价是弹窗瞬间消失、
           筛选/滚动位置全丢。这里改为手动把卡片插进第一个可见列表。
           ------------------------------------------------------------------ */
        function clearSearchAfterSave() {
            if (!searchInput) {
                return;
            }

            if (searchInput.value !== '') {
                searchInput.value = '';
            }

            state.query = '';

            if (typeof updateSearchClear === 'function') {
                updateSearchClear();
            }

            // 派发 input 事件，让 search.js 的联想面板、清空按钮等监听器一起复位
            try {
                searchInput.dispatchEvent(new Event('input', { bubbles: true }));
            } catch (error) { /* 忽略 */ }

            var hint = document.getElementById('searchUrlHint');
            var saveBtn = document.getElementById('searchSave');
            var submitBtn = document.getElementById('searchSubmit');

            if (hint) {
                hint.hidden = true;
                hint.setAttribute('data-open', '0');
            }

            if (saveBtn) { saveBtn.hidden = true; }
            if (submitBtn) { submitBtn.hidden = false; }
        }

        // 关掉弹窗后会经历「焦点回搜索框 → focus 监听弹出最近搜索」，
        // 所以收面板必须放到关窗之后的下一个任务里，否则会被重新打开。
        function dismissSearchChrome() {
            var panels = document.querySelectorAll('.search-bar .ss-panel');

            for (var i = 0; i < panels.length; i += 1) {
                panels[i].hidden = true;
            }

            if (searchInput && typeof searchInput.blur === 'function') {
                searchInput.blur();
            }
        }

        function buildNewCard(payload) {
            var url = String(payload.url || '').trim();

            if (!url) {
                return null;
            }

            var meta = payload.meta || {};
            var name = String(payload.name || '').trim();
            var host = '';
            var domain = '';

            try {
                var u = new URL(url);
                host = u.hostname;
                domain = host.replace(/^www\./i, '');
            } catch (error) {
                domain = url.replace(/^https?:\/\//i, '').split('/')[0];
                host = domain;
            }

            if (name === '') {
                name = domain;
            }

            var letter = (name || domain).charAt(0).toUpperCase();
            var icon = (meta.icons && meta.icons[0]) || '';
            var desc = String(meta.description || '').trim();

            // 结构与 index.php 渲染的 .site-card 保持一致：卡片级交互与筛选逻辑才认得出它
            var card = document.createElement('a');
            card.className = 'site-card is-new';
            card.href = url;
            card.target = '_blank';
            card.rel = 'noopener noreferrer';
            card.style.setProperty('--enter-index', '0');
            card.setAttribute('aria-label', '访问 ' + name + '（' + domain + '）');
            card.setAttribute('data-id', String(payload.id || ''));
            card.setAttribute('data-category', String(payload.category_id || 0));
            card.setAttribute('data-name', name);
            card.setAttribute('data-domain', domain);
            card.setAttribute('data-host', host);
            card.setAttribute('data-url', url);
            card.setAttribute('data-clicks', '0');
            card.setAttribute('data-description', desc);
            card.setAttribute('data-tags', '');
            card.setAttribute('data-alias', '');
            card.setAttribute('data-starred', '0');
            card.setAttribute('data-owner', '1');

            var frag = document.createElement('span');
            frag.className = 'site-card-main';

            var iconBox = document.createElement('span');
            iconBox.className = 'site-icon';
            iconBox.setAttribute('aria-hidden', 'true');
            iconBox.innerHTML = '<span class="site-icon-letter"></span>';
            iconBox.querySelector('.site-icon-letter').textContent = letter;

            if (icon !== '') {
                var img = document.createElement('img');
                img.className = 'site-favicon';
                img.alt = '';
                img.width = 44;
                img.height = 44;
                img.loading = 'lazy';
                img.decoding = 'async';
                img.referrerPolicy = 'no-referrer';
                img.onerror = function () { img.remove(); };
                img.src = icon;
                iconBox.appendChild(img);
            }

            var info = document.createElement('span');
            info.className = 'site-info';
            info.innerHTML = '<span class="site-name"></span><span class="site-desc"></span>';
            info.querySelector('.site-name').textContent = name;
            info.querySelector('.site-desc').textContent = desc;

            frag.appendChild(iconBox);
            frag.appendChild(info);

            var metaBox = document.createElement('span');
            metaBox.className = 'site-meta';
            metaBox.innerHTML = '<span class="site-domain"></span><span class="site-metrics"><span class="site-clicks">新收录</span></span>';
            metaBox.querySelector('.site-domain').textContent = domain;

            card.appendChild(frag);
            card.appendChild(metaBox);

            return card;
        }

        function refreshAfterQuickAdd(payload) {
            var card = buildNewCard(payload);

            if (!card) {
                return;
            }

            // 优先插进「最近添加/未分组」这类第一个可见网格，退化为任意可见网格
            var grids = toArray(document.querySelectorAll('.site-grid'));
            var grid = null;

            for (var i = 0; i < grids.length; i += 1) {
                if (grids[i].offsetParent !== null) {
                    grid = grids[i];
                    break;
                }
            }

            if (!grid) {
                grid = grid || grids[0];
            }

            if (!grid) {
                return;
            }

            grid.insertBefore(card, grid.firstChild);

            // 让新卡片进到 cards 集合里，后续筛选/排序/右键菜单都能认出它
            cards = toArray(document.querySelectorAll('.site-card'));

            var empty = document.getElementById('emptyState');
            if (empty) { empty.hidden = true; }

            window.setTimeout(function () {
                card.classList.remove('is-new');
            }, 1600);
        }

        /* ------------------------------------------------------------------
           收录流程（v2.10.2 重做）

           旧流程是「点收藏 → 抓取 → 直接入库」，用户没有确认机会，
           也选不了分组，抓错了只能事后去「我的导航」里改。

           新流程拆成两步：
             1. quick_preview  只抓取（标题/描述/图标/推荐分组），不入库
             2. quick_add      用户确认后，按确认过的字段落库

           对外出口 open(url, {autoStart:true}) 供搜索框「收录」按钮调用，
           绕开 <details> 里那颗触发按钮，避免菜单收起把弹窗一起带走。
           ------------------------------------------------------------------ */

        // 收录框的当前状态：preview 成功后才有 logo / categories
        var quickAddState = { logo: '', previewed: false, busy: false };

        window.XYQuickAdd = {
            open: function (url, options) {
                var opts = options || {};
                openQuickAdd(url);

                if (opts.autoStart) {
                    // 等弹窗完成 showModal 的入场动画再开抓，按钮文案才不会闪
                    window.setTimeout(function () {
                        runQuickPreview();
                    }, 140);
                }
            }
        };

        function quickAddEl(id) {
            return document.getElementById(id);
        }

        function setQuickAddBusy(busy, label) {
            var btn = quickAddEl('quickAddSubmit');
            var input = quickAddEl('quickAddUrl');

            quickAddState.busy = !!busy;

            if (btn) {
                btn.disabled = !!busy;
                btn.textContent = label;
            }

            if (input) {
                input.readOnly = !!busy;
            }
        }

        function openQuickAdd(presetUrl) {
            if (!quickAddDialog) {
                return;
            }

            var input = quickAddEl('quickAddUrl');
            var msg = quickAddEl('quickAddMsg');
            var preview = quickAddEl('quickAddPreview');
            var submit = quickAddEl('quickAddSubmit');
            var nameInput = quickAddEl('quickAddName');
            var descInput = quickAddEl('quickAddDesc');
            var category = quickAddEl('quickAddCategory');
            var hint = quickAddEl('quickAddSuggestHint');
            var status = quickAddEl('quickAddStatus');

            quickAddState.logo = '';
            quickAddState.previewed = false;
            quickAddState.busy = false;

            if (msg) {
                msg.textContent = '';
            }

            if (preview) {
                preview.hidden = true;
            }

            if (hint) {
                hint.hidden = true;
                hint.textContent = '';
            }

            if (status) {
                status.textContent = '';
            }

            if (nameInput) {
                nameInput.value = '';
            }

            if (descInput) {
                descInput.value = '';
            }

            if (category) {
                category.innerHTML = '';
            }

            // 搜索框带过来的网址不能被清空：有预设值就沿用，没有才重置
            if (input) {
                input.value = typeof presetUrl === 'string' && presetUrl !== ''
                    ? presetUrl
                    : '';
            }

            if (submit) {
                submit.disabled = false;
                submit.textContent = '识别网站信息';
            }

            openDlg(quickAddDialog);

            if (input) {
                input.focus();
                input.select();
            }
        }

        /**
         * 第一步：抓取站点信息 + 推荐分组，填入收录框。
         * 只读，不写库。
         */
        function runQuickPreview() {
            var input = quickAddEl('quickAddUrl');
            var msg = quickAddEl('quickAddMsg');
            var url = input ? input.value.trim() : '';

            if (!url) {
                if (msg) {
                    msg.textContent = '请输入网址';
                }

                if (input) {
                    input.focus();
                }

                return;
            }

            setQuickAddBusy(true, '识别中…');

            if (msg) {
                msg.textContent = '';
            }

            postForm({ action: 'quick_preview', url: url }).then(function (res) {
                if (!res || !res.ok) {
                    setQuickAddBusy(false, '重新识别');

                    if (msg) {
                        msg.textContent = (res && res.error) || '识别失败，可直接填写信息后收录';
                    }

                    if (input) {
                        input.focus();
                        input.select();
                    }

                    return;
                }

                // 已在导航里：不必再收录，直接标星并收尾
                if (res.duplicate) {
                    setQuickAddBusy(false, '已在导航中');
                    finishQuickAdd(res, true);

                    return;
                }

                fillQuickAddForm(res);
                setQuickAddBusy(false, '确认收录');
            }).catch(function () {
                setQuickAddBusy(false, '重新识别');

                if (msg) {
                    msg.textContent = '网络错误，请稍后重试';
                }
            });
        }

        /**
         * 把 preview 结果灌进表单：预览卡片 + 可编辑字段 + 分组下拉。
         */
        function fillQuickAddForm(data) {
            var preview = quickAddEl('quickAddPreview');
            var nameInput = quickAddEl('quickAddName');
            var descInput = quickAddEl('quickAddDesc');
            var category = quickAddEl('quickAddCategory');
            var hint = quickAddEl('quickAddSuggestHint');
            var status = quickAddEl('quickAddStatus');
            var msg = quickAddEl('quickAddMsg');

            var host = data.host || '';

            if (!host) {
                try {
                    host = new URL(data.url).hostname.replace(/^www\./i, '');
                } catch (error) { /* 忽略 */ }
            }

            var displayName = data.name || host || data.url || '';
            var displayDesc = data.description || '';

            quickAddState.logo = data.logo || '';
            quickAddState.previewed = true;

            if (msg) {
                msg.textContent = '';
            }

            if (preview) {
                var letter = preview.querySelector('.qa-icon-letter');
                var fav = preview.querySelector('.qa-favicon');
                var nameEl = preview.querySelector('.qa-name');
                var descEl = preview.querySelector('.qa-desc');

                if (letter) {
                    letter.textContent = (host || displayName || '?').charAt(0).toUpperCase();
                    letter.style.display = '';
                }

                if (fav) {
                    if (data.logo) {
                        fav.style.display = '';
                        // 加载完成前先不显示：否则跨域图标慢/被拦时会先露出一块白底，
                        // 下面那层首字母渐变块反而更好看
                        fav.classList.remove('is-loaded');

                        fav.onload = function () {
                            fav.classList.add('is-loaded');
                        };

                        fav.onerror = function () {
                            fav.classList.remove('is-loaded');
                            fav.style.display = 'none';
                        };

                        fav.src = data.logo;
                    } else {
                        fav.removeAttribute('src');
                        fav.classList.remove('is-loaded');
                        fav.style.display = 'none';
                    }
                }

                if (nameEl) {
                    nameEl.textContent = displayName;
                }

                if (descEl) {
                    descEl.textContent = displayDesc;
                }

                preview.hidden = false;
            }

            if (nameInput) {
                nameInput.value = displayName;
            }

            if (descInput) {
                descInput.value = displayDesc;
            }

            // 分组下拉：preview 带回当前用户可见的全部分组
            if (category) {
                category.innerHTML = '';

                var list = Array.isArray(data.categories) ? data.categories : [];

                list.forEach(function (item) {
                    var option = document.createElement('option');
                    option.value = String(item.id);
                    option.textContent = (item.is_private ? '🔒 ' : '') + item.name;
                    category.appendChild(option);
                });

                if (data.suggested_category_id) {
                    category.value = String(data.suggested_category_id);
                }
            }

            // 记下推荐值，供分组变化时判断「还是推荐的那个吗」
            quickAddState.suggestedId = data.suggested_category_id ? String(data.suggested_category_id) : '';
            quickAddState.suggestReason = data.suggest_reason || 'none';
            quickAddState.fetchOk = data.fetch_ok !== false;

            if (status) {
                var matched = data.suggest_reason === 'keyword';
                status.textContent = matched ? '已自动匹配分组' : '';
                status.className = 'qa-status' + (matched ? ' is-ok' : '');
            }

            syncQuickAddHint();

            if (category) {
                category.focus();
            }
        }

        /**
         * 同步分组的说明小字。
         *
         * 用户手动改过分组后，原先那句「自动归入「XX」」就自相矛盾了
         * （下拉显示的是新分组，提示还写着旧分组名），所以这里按当前选中值重算。
         */
        function syncQuickAddHint() {
            var hint = quickAddEl('quickAddSuggestHint');
            var category = quickAddEl('quickAddCategory');
            var status = quickAddEl('quickAddStatus');

            if (!hint) {
                return;
            }

            var pickedName = '';

            if (category && category.selectedIndex >= 0) {
                pickedName = category.options[category.selectedIndex].textContent.replace(/^🔒\s*/, '').trim();
            }

            // 抓取失败时优先说清原因 —— 用户看到的名称是域名，
            // 需要知道这是兜底结果而不是网站真名
            if (quickAddState.fetchOk === false) {
                hint.textContent = pickedName
                    ? '未能读取网站信息，将按域名收录到「' + pickedName + '」'
                    : '未能读取网站信息，已按域名填写，可手动修改';
                hint.hidden = false;

                if (status) {
                    status.textContent = '';
                    status.className = 'qa-status';
                }

                return;
            }

            // 是否仍是自动推荐的那个分组
            var stillSuggested = !!quickAddState.suggestedId
                && category
                && category.value === quickAddState.suggestedId
                && quickAddState.suggestReason === 'keyword';

            if (stillSuggested && pickedName) {
                hint.textContent = '按网站信息自动归入「' + pickedName + '」，可手动改';
                hint.hidden = false;

                if (status) {
                    status.textContent = '已自动匹配分组';
                    status.className = 'qa-status is-ok';
                }

                return;
            }

            // 用户改过：只说明结果，不再提「自动」
            if (pickedName) {
                hint.textContent = '将收录到「' + pickedName + '」';
                hint.hidden = false;

                if (status) {
                    status.textContent = '';
                    status.className = 'qa-status';
                }

                return;
            }

            hint.hidden = true;
        }

        /**
         * 第二步：按用户确认后的字段入库。
         */
        function confirmQuickAdd() {
            var input = quickAddEl('quickAddUrl');
            var nameInput = quickAddEl('quickAddName');
            var descInput = quickAddEl('quickAddDesc');
            var category = quickAddEl('quickAddCategory');
            var msg = quickAddEl('quickAddMsg');

            var url = input ? input.value.trim() : '';
            var name = nameInput ? nameInput.value.trim() : '';

            if (!url) {
                if (msg) {
                    msg.textContent = '请输入网址';
                }

                return;
            }

            if (!name) {
                if (msg) {
                    msg.textContent = '请填写网站名称';
                }

                if (nameInput) {
                    nameInput.focus();
                }

                return;
            }

            setQuickAddBusy(true, '收录中…');

            if (msg) {
                msg.textContent = '';
            }

            postForm({
                action: 'quick_add',
                url: url,
                name: name,
                description: descInput ? descInput.value.trim() : '',
                logo: quickAddState.logo,
                category_id: category ? category.value : 0
            }).then(function (res) {
                if (!res || !res.ok) {
                    setQuickAddBusy(false, '确认收录');

                    if (msg) {
                        msg.textContent = (res && res.error) || '收录失败，可稍后重试';
                    }

                    return;
                }

                finishQuickAdd(res, !!res.duplicate);
            }).catch(function () {
                setQuickAddBusy(false, '确认收录');

                if (msg) {
                    msg.textContent = '网络错误，请稍后重试';
                }
            });
        }

        /**
         * 统一收尾：提示 → 关窗 → 清搜索框 → 新卡片置顶。
         * 不做整页刷新，否则「弹窗自动消失」会被误当成 bug。
         */
        function finishQuickAdd(res, duplicate) {
            showToast(duplicate
                ? '该网址已在你的导航中，已标记收藏'
                : '已收录到「' + (res.category_name || '未分类') + '」');

            setTimeout(function () {
                clearSearchAfterSave();
                closeDlg(quickAddDialog);

                if (!duplicate) {
                    refreshAfterQuickAdd(res);
                }

                // 关窗会把焦点还给搜索框，等焦点落定后再收面板
                window.setTimeout(dismissSearchChrome, 60);
            }, duplicate ? 700 : 900);
        }

        /**
         * 主按钮：未识别时先识别，识别完再确认收录。
         */
        function doQuickAdd() {
            if (quickAddState.busy) {
                return;
            }

            if (!quickAddState.previewed) {
                runQuickPreview();

                return;
            }

            confirmQuickAdd();
        }

        function handleAction(action, card) {
            var data = cardData(card);

            switch (action) {
                case 'star':
                    toggleStar(card);
                    break;
                case 'copy':
                    copyText(data.url, function () {
                        flashCopied(card.querySelector('[data-action="copy"]'));
                        showToast('链接已复制');
                    });
                    break;
                case 'qrcode':
                    showQr(data.url);
                    break;
                case 'edit':
                    openEditDialog(card);
                    break;
                case 'move':
                    openMove(card);
                    break;
                case 'delete':
                    if (!window.confirm('确定删除「' + (data.name || data.url) + '」吗？')) {
                        return;
                    }

                    postForm({ action: 'delete', id: data.id }).then(function (res) {
                        if (res && res.ok) {
                            card.style.transition = 'opacity .25s ease';
                            card.style.opacity = '0';
                            setTimeout(function () {
                                if (card.parentNode) {
                                    card.parentNode.removeChild(card);
                                }
                            }, 260);
                            showToast('已删除');
                        } else {
                            showToast((res && res.error) || '删除失败');
                        }
                    }).catch(function () {
                        showToast('网络错误，请稍后重试');
                    });
                    break;
                case 'open':
                    window.open(data.url, '_blank', 'noopener');
                    break;
            }
        }

        function openCtxMenu(card, x, y) {
            if (!ctxMenu) {
                return;
            }

            ctxCard = card;

            var data = cardData(card);

            qsa('.ccm-owner', ctxMenu).forEach(function (item) {
                item.hidden = !data.owner;
            });

            ctxMenu.hidden = false;

            var rect = ctxMenu.getBoundingClientRect();
            var maxX = window.innerWidth - rect.width - 8;
            var maxY = window.innerHeight - rect.height - 8;

            ctxMenu.style.left = Math.max(8, Math.min(x, maxX)) + 'px';
            ctxMenu.style.top = Math.max(8, Math.min(y, maxY)) + 'px';
        }

        function closeCtxMenu() {
            if (ctxMenu) {
                ctxMenu.hidden = true;
            }

            ctxCard = null;
        }

        /* v2.10.5：紧凑模式下操作条改为「点击展开」 */
        function isCompactMode() {
            return document.documentElement.getAttribute('data-mode') === 'compact';
        }

        function closeCompactActions(except) {
            var opened = document.querySelectorAll('.site-card.is-actions-open');

            for (var i = 0; i < opened.length; i++) {
                if (opened[i] !== except) {
                    opened[i].classList.remove('is-actions-open');
                }
            }
        }

        /* 点空白处 / 切换显示模式 / 按 Esc 时收起 */
        document.addEventListener('click', function (event) {
            if (!event.target.closest || !event.target.closest('.site-card')) {
                closeCompactActions();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeCompactActions();
            }
        });

        document.addEventListener('nav:modechange', function () {
            closeCompactActions();
        });

        /* 单击委托：角标操作 + 打开方式 + Shift 后台打开 */
        document.addEventListener('click', function (event) {
            var closest = function (selector) {
                return event.target.closest ? event.target.closest(selector) : null;
            };

            var starEl = closest('.card-star');

            if (starEl) {
                var starCard = starEl.closest('.site-card');

                if (starCard) {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleStar(starCard);
                    return;
                }
            }

            var actionEl = closest('.card-action');

            if (actionEl) {
                var actionCard = actionEl.closest('.site-card');

                if (actionCard) {
                    event.preventDefault();
                    event.stopPropagation();
                    handleAction(actionEl.getAttribute('data-action'), actionCard);
                    return;
                }
            }

            if (ctxMenu && !ctxMenu.hidden && !closest('#cardContextMenu')) {
                closeCtxMenu();
            }

            var card = closest('a.site-card');

            if (!card || closest('.card-corner')) {
                return;
            }

            /* 紧凑模式（v2.10.5）：卡片只有 42px 高，操作条比卡片还宽，
               悬停浮出会直接盖住标题。改为「第一次点击先展开操作条，
               第二次点击才跳转」，触屏与鼠标行为一致。 */
            if (isCompactMode()) {
                if (card.classList.contains('is-actions-open')) {
                    card.classList.remove('is-actions-open');
                } else {
                    closeCompactActions();
                    card.classList.add('is-actions-open');
                    event.preventDefault();
                    return;
                }
            }

            if (suppressClick) {
                event.preventDefault();
                return;
            }

            var url = card.getAttribute('data-url') || card.href;

            if (event.shiftKey) {
                event.preventDefault();

                var win = window.open(url, '_blank', 'noopener');

                if (win) {
                    try {
                        win.blur();
                        window.focus();
                    } catch (error) { /* 忽略 */ }
                }

                return;
            }

            var mode = 'default';

            try {
                mode = localStorage.getItem('nav_open_mode') || 'default';
            } catch (error) { /* 忽略 */ }

            if (mode === 'self') {
                event.preventDefault();
                window.location.assign(url);
            } else if (mode === 'blank') {
                event.preventDefault();
                window.open(url, '_blank', 'noopener');
            }
        });

        /* 键盘可达性：Enter / Space 触发角标按钮 */
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }

            var el = event.target.closest ? event.target.closest('.card-star, .card-action') : null;

            if (!el) {
                return;
            }

            var card = el.closest('.site-card');

            if (!card) {
                return;
            }

            event.preventDefault();

            if (el.classList.contains('card-star')) {
                toggleStar(card);
            } else {
                handleAction(el.getAttribute('data-action'), card);
            }
        });

        /* 桌面端右键菜单 */
        document.addEventListener('contextmenu', function (event) {
            var card = event.target.closest ? event.target.closest('.site-card') : null;

            if (!card) {
                return;
            }

            event.preventDefault();
            openCtxMenu(card, event.clientX, event.clientY);
        });

        if (ctxMenu) {
            ctxMenu.addEventListener('click', function (event) {
                var item = event.target.closest ? event.target.closest('.ccm-item') : null;

                if (!item || !ctxCard) {
                    return;
                }

                var card = ctxCard;
                closeCtxMenu();
                handleAction(item.getAttribute('data-action'), card);
            });
        }

        window.addEventListener('scroll', closeCtxMenu, true);
        window.addEventListener('resize', closeCtxMenu);

        /* 移动端长按弹出操作菜单 */
        document.addEventListener('touchstart', function (event) {
            var card = event.target.closest ? event.target.closest('.site-card') : null;

            if (!card || (event.target.closest && event.target.closest('.card-corner'))) {
                return;
            }

            var touch = event.touches[0];
            var x = touch.clientX;
            var y = touch.clientY;

            clearTimeout(pressTimer);
            pressTimer = setTimeout(function () {
                suppressClick = true;
                openCtxMenu(card, x, y);
            }, 500);
        }, { passive: true });

        document.addEventListener('touchmove', function () {
            clearTimeout(pressTimer);
        }, { passive: true });

        document.addEventListener('touchend', function () {
            clearTimeout(pressTimer);

            if (suppressClick) {
                setTimeout(function () {
                    suppressClick = false;
                }, 350);
            }
        });

        /* 打开方式偏好（当前页 / 新标签 / 默认） */
        var openModeToggle = document.getElementById('openModeToggle');

        function refreshOpenMode() {
            if (!openModeToggle) {
                return;
            }

            var mode = 'default';

            try {
                mode = localStorage.getItem('nav_open_mode') || 'default';
            } catch (error) { /* 忽略 */ }

            var label = openModeToggle.querySelector('span');
            var text = mode === 'self'
                ? '打开方式：当前页'
                : (mode === 'blank' ? '打开方式：新标签' : '打开方式：默认');

            if (label) {
                label.textContent = text;
            } else {
                openModeToggle.textContent = text;
            }
        }

        if (openModeToggle) {
            refreshOpenMode();
            openModeToggle.addEventListener('click', function () {
                var mode = 'default';

                try {
                    mode = localStorage.getItem('nav_open_mode') || 'default';
                } catch (error) { /* 忽略 */ }

                var next = mode === 'default' ? 'self' : (mode === 'self' ? 'blank' : 'default');

                try {
                    localStorage.setItem('nav_open_mode', next);
                } catch (error) { /* 忽略 */ }

                refreshOpenMode();
                showToast(next === 'self'
                    ? '单击卡片将在当前页打开'
                    : (next === 'blank' ? '单击卡片将在新标签打开' : '已恢复默认打开方式'));
            });
        }

        /* 收录弹窗 */
        bind('quickAddTrigger', 'click', function () {
            var searchInput = document.getElementById('siteSearch');
            var raw = searchInput ? searchInput.value.trim() : '';
            var preset = /^(?:https?:\/\/)?(?:[\w-]+\.)+[a-z]{2,}/i.test(raw) && !/\s/.test(raw) ? raw : '';

            openQuickAdd(preset);

            // 带着网址打开就直接开始识别，省掉一次点击
            if (preset) {
                window.setTimeout(runQuickPreview, 140);
            }
        });
        bind('quickAddClose', 'click', function () { closeDlg(quickAddDialog); });
        bind('quickAddCancel', 'click', function () { closeDlg(quickAddDialog); });
        bind('quickAddSubmit', 'click', doQuickAdd);
        bind('quickAddCategory', 'change', syncQuickAddHint);
        bind('quickAddUrl', 'keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                // 网址改过就先重新识别，识别过再按确认
                doQuickAdd();
            }
        });

        // 用户改了网址，之前识别出的标题/图标/分组就不再对应了，
        // 必须退回「未识别」状态，否则会把旧站点的信息存到新网址上。
        bind('quickAddUrl', 'input', function () {
            if (!quickAddState.previewed) {
                return;
            }

            quickAddState.previewed = false;

            var preview = quickAddEl('quickAddPreview');

            if (preview) {
                preview.hidden = true;
            }

            setQuickAddBusy(false, '识别网站信息');
        });

        /* 二维码弹窗 */
        bind('qrClose', 'click', function () { closeDlg(qrDialog); });
        bind('qrClose2', 'click', function () { closeDlg(qrDialog); });
        bind('qrCopyBtn', 'click', function () {
            var text = document.getElementById('qrUrl');

            copyText(text ? text.textContent : '', function () {
                flashCopied(document.getElementById('qrCopyBtn'));
                showToast('链接已复制');
            });
        });

        /* 移动分组弹窗 */
        bind('moveClose', 'click', function () { closeDlg(moveDialog); });
        bind('moveCancel', 'click', function () { closeDlg(moveDialog); });
        bind('moveSubmit', 'click', function () {
            var sel = document.getElementById('moveCategory');
            var msg = document.getElementById('moveMsg');

            postForm({ action: 'move', id: moveId, category_id: sel ? sel.value : 0 }).then(function (res) {
                if (res && res.ok) {
                    showToast('已移动到目标分组');
                    closeDlg(moveDialog);
                    setTimeout(function () {
                        window.location.reload();
                    }, 800);
                } else if (msg) {
                    msg.textContent = (res && res.error) || '移动失败';
                }
            }).catch(function () {
                if (msg) {
                    msg.textContent = '网络错误，请稍后重试';
                }
            });
        });

        /* 编辑网址弹窗 */
        bind('editLinkClose', 'click', closeEditDialog);
        bind('editLinkCancel', 'click', closeEditDialog);
        bind('editFetchBtn', 'click', fetchEditMeta);
        bind('editLinkSave', 'click', function (event) {
            event.preventDefault();
            saveEditLink();
        });
        bind('editLinkForm', 'submit', function (event) {
            event.preventDefault();
            saveEditLink();
        });
    })();

    /* ----------------------------------------------------------------------
       v2.9.2 · 搜索框直接收藏网址
       ----------------------------------------------------------------------
       在搜索框里粘贴完整网址（协议可省略）时，把「搜索」按钮切换为「收藏」，
       并把输入框下方的联想区换成「收藏此网址」的提示条。
       这样「看到好站 → 复制链接 → 粘贴 → 回车」就是一条完整动线，
       不需要先打开菜单再点「快捷收藏」。
       ---------------------------------------------------------------------- */
    (function initUrlQuickSave() {
        var input = document.getElementById('siteSearch');
        var saveBtn = document.getElementById('searchSave');
        var submitBtn = document.getElementById('searchSubmit');
        var hint = document.getElementById('searchUrlHint');
        var hintText = document.getElementById('searchUrlHintText');
        var hintAction = document.getElementById('searchUrlHintAction');

        if (!input || !saveBtn || !hint) {
            return;
        }

        var body = document.body;
        var loggedIn = body.getAttribute('data-logged-in') === '1';
        var state = { url: '' };

        // 只认「看起来就是网址」的输入：带协议，或 主机名 + 常见域名后缀 + 可选路径
        var PATTERN = /^(?:https?:\/\/)?(?:[\w-]+\.)+[a-z]{2,}(?::\d{2,5})?(?:[/?#]\S*)?$/i;

        function normalize(raw) {
            var value = String(raw || '').trim();

            if (!value || /\s/.test(value)) {
                return '';
            }

            // 带协议的优先，避免 "a.b" 这类误判
            if (/^https?:\/\//i.test(value)) {
                return PATTERN.test(value) ? value : '';
            }

            if (PATTERN.test(value) && /\.(com|cn|net|org|io|dev|app|ai|me|co|top|xyz|info|tv|cc|gg|so|vip|site|wiki|gov|edu|la|to|fm|ly|sh|us|uk|jp|de)(?:[:/?#]|$)/i.test(value)) {
                return 'https://' + value;
            }

            return '';
        }

        function setMode(mode) {
            var isUrl = mode === 'url';
            saveBtn.hidden = !isUrl;
            submitBtn.hidden = isUrl;
            input.setAttribute('aria-label', isUrl ? '输入网址，回车收藏' : '搜索网站');

            if (!isUrl) {
                hint.hidden = true;
                hint.setAttribute('data-open', '0');
                state.url = '';
            }
        }

        function showHint(url) {
            if (!url) {
                hint.hidden = true;
                hint.setAttribute('data-open', '0');
                return;
            }

            state.url = url;
            hint.hidden = false;

            var host = url.replace(/^https?:\/\//i, '').replace(/\/$/, '');

            if (hintText) {
                hintText.innerHTML = loggedIn
                    ? '识别到网址 <strong></strong>，回车即可收藏到「我的导航」'
                    : '识别到网址 <strong></strong>，登录后即可收藏';
                var strong = hintText.querySelector('strong');
                if (strong) {
                    strong.textContent = host;
                }
            }

            if (hintAction) {
                hintAction.textContent = loggedIn ? '收藏' : '登录';
            }

            // 等一帧再展开，保证 grid 0fr → 1fr 的过渡能触发
            requestAnimationFrame(function () {
                hint.setAttribute('data-open', '1');
            });
        }

        function evaluate() {
            var url = normalize(input.value);
            setMode(url ? 'url' : 'search');

            if (url) {
                showHint(url);
            } else {
                hint.hidden = true;
                hint.setAttribute('data-open', '0');
            }

            return url;
        }

        function doSave(url) {
            if (!url) {
                url = evaluate();
            }

            if (!url) {
                return;
            }

            if (!loggedIn) {
                var modal = document.getElementById('authModal');
                var trigger = document.getElementById('authTrigger');

                if (modal) {
                    modal.setAttribute('data-open-tab', 'login');
                }

                if (trigger) {
                    trigger.click();
                }

                return;
            }

            // 交给快捷收藏弹窗完成抓取 + 收藏，保持单一逻辑出口。
            // 注意：不能走 quickAddTrigger.click()——那个按钮在 <details> 里，
            // 点击会先收起菜单，弹窗刚 showModal 就被折叠的容器带走了。
            var api = window.XYQuickAdd;

            if (api && typeof api.open === 'function') {
                api.open(url, { autoStart: true });
                return;
            }

            var dlg = document.getElementById('quickAddDialog');
            var trigger = document.getElementById('quickAddTrigger');

            if (trigger) {
                trigger.click();
            }

            var field = document.getElementById('quickAddUrl');
            var submit = document.getElementById('quickAddSubmit');

            if (field) {
                field.value = url;
            }

            if (dlg) {
                dlg.setAttribute('data-autostart', '1');
            }

            if (submit) {
                setTimeout(function () {
                    submit.click();
                }, 120);
            }
        }

        input.addEventListener('input', evaluate);
        input.addEventListener('paste', function () {
            setTimeout(evaluate, 0);
        });
        input.addEventListener('blur', function () {
            // 失焦后收起提示，但网址本身保留给按钮使用
            if (normalize(input.value)) {
                hint.setAttribute('data-open', '0');
            }
        });

        input.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }

            var url = normalize(input.value);

            if (!url) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            doSave(url);
        }, true);

        saveBtn.addEventListener('click', function () {
            doSave(evaluate());
        });

        if (hintAction) {
            hintAction.addEventListener('click', function () {
                doSave(state.url || evaluate());
            });
        }

        setMode('search');
    })();

    /* ------------------------------------------------------------------
       搜索引擎管理模态框（移至 initCardV260 外部，使 openSeModal 可被 renderEngineMenu 访问）
       ------------------------------------------------------------------ */

    var seModal = document.getElementById('seModal');
    var seList = document.getElementById('seList');
    var seAddName = document.getElementById('seAddName');
    var seAddUrl = document.getElementById('seAddUrl');
    var seAddIcon = document.getElementById('seAddIcon');
    var seAddBtn = document.getElementById('seAddBtn');
    var userEnginesCache = [];

    function openSeModal() {
        if (!seModal) return;
        seModal.hidden = false;
        document.body.style.overflow = 'hidden';
        loadSeList();
    }

    function closeSeModal() {
        if (!seModal) return;
        seModal.hidden = true;
        document.body.style.overflow = '';
    }

    function postSeForm(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch('api/user_search_engines.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd,
            headers: { 'Accept': 'application/json' }
        }).then(function (r) { return r.json(); });
    }

    function loadSeList() {
        fetch('api/user_search_engines.php', {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) return;
                // 保留所有引擎（全局默认 + 用户自定义），跳过站内搜索
                userEnginesCache = data.engines.filter(function (e) { return e.type !== 'site'; });
                renderSeList();
            })
            .catch(function () { renderSeList(); });
    }

    function renderSeList() {
        if (!seList) return;
        seList.innerHTML = '';

        if (!userEnginesCache.length) {
            var empty = document.createElement('li');
            empty.className = 'se-empty';
            empty.textContent = '暂无搜索引擎。';
            seList.appendChild(empty);
            return;
        }

        userEnginesCache.forEach(function (e, idx) {
            var li = document.createElement('li');
            li.className = 'se-item';
            li.dataset.id = e.id;
            li.dataset.type = e.type; // global 或 user

            var icon = document.createElement('span');
            icon.className = 'se-item-icon';
            icon.textContent = e.icon || '🔍';

            var info = document.createElement('div');
            info.className = 'se-item-info';
            var nameEl = document.createElement('strong');
            nameEl.textContent = e.name;
            if (e.type === 'global') {
                var tag = document.createElement('span');
                tag.className = 'se-item-global-tag';
                tag.textContent = '默认';
                nameEl.appendChild(tag);
            }
            var urlEl = document.createElement('small');
            urlEl.textContent = e.url_template;
            info.appendChild(nameEl);
            info.appendChild(document.createElement('br'));
            info.appendChild(urlEl);

            var actions = document.createElement('div');
            actions.className = 'se-item-actions';

            // 上移按钮
            var upBtn = document.createElement('button');
            upBtn.type = 'button';
            upBtn.className = 'se-btn se-btn-sm';
            upBtn.textContent = '↑';
            upBtn.title = '上移';
            upBtn.disabled = idx === 0;
            upBtn.addEventListener('click', function () { moveSeItem(idx, -1); });

            // 下移按钮
            var downBtn = document.createElement('button');
            downBtn.type = 'button';
            downBtn.className = 'se-btn se-btn-sm';
            downBtn.textContent = '↓';
            downBtn.title = '下移';
            downBtn.disabled = idx === userEnginesCache.length - 1;
            downBtn.addEventListener('click', function () { moveSeItem(idx, 1); });

            // 编辑按钮
            var editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'se-btn se-btn-sm';
            editBtn.textContent = '编辑';
            editBtn.addEventListener('click', function () { editSeItem(e); });

            // 删除按钮
            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'se-btn se-btn-sm se-btn-danger';
            delBtn.textContent = '删除';
            delBtn.addEventListener('click', function () { deleteSeItem(e); });

            actions.appendChild(upBtn);
            actions.appendChild(downBtn);
            actions.appendChild(editBtn);
            actions.appendChild(delBtn);

            li.appendChild(icon);
            li.appendChild(info);
            li.appendChild(actions);
            seList.appendChild(li);
        });
    }

    function editSeItem(e) {
        // 移除已有的编辑表单
        var existing = document.querySelector('.se-edit-form');
        if (existing) existing.remove();

        var li = seList.querySelector('[data-id="' + e.id + '"]');
        if (!li) return;

        var form = document.createElement('li');
        form.className = 'se-edit-form';

        var nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.placeholder = '名称';
        nameInput.value = e.name || '';
        nameInput.maxLength = 50;

        var urlInput = document.createElement('input');
        urlInput.type = 'text';
        urlInput.placeholder = 'URL模板，含 {q}';
        urlInput.value = e.url_template || '';

        var iconInput = document.createElement('input');
        iconInput.type = 'text';
        iconInput.placeholder = '图标 emoji 或 URL';
        iconInput.value = e.icon || '';

        var saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'se-btn';
        saveBtn.textContent = '保存';

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'se-btn';
        cancelBtn.textContent = '取消';

        form.appendChild(nameInput);
        form.appendChild(urlInput);
        form.appendChild(iconInput);
        form.appendChild(saveBtn);
        form.appendChild(cancelBtn);

        li.parentNode.insertBefore(form, li.nextSibling);
        nameInput.focus();

        function closeForm() { form.remove(); }

        cancelBtn.addEventListener('click', closeForm);

        saveBtn.addEventListener('click', function () {
            var name = nameInput.value.trim();
            var url = urlInput.value.trim();
            var icon = iconInput.value.trim();

            if (!name) { alert('请输入名称'); return; }
            if (!url || !/^https?:\/\//i.test(url) || (url.indexOf('{q}') === -1 && url.indexOf('{query}') === -1)) {
                alert('URL 必须以 http(s):// 开头且包含 {q} 占位符');
                return;
            }

            var params = { name: name, url_template: url, icon: icon };
            var action;

            if (e.type === 'global') {
                // 编辑全局默认 → 创建用户覆盖记录
                action = 'edit_global';
                params.global_id = e.global_id || e.ref_id;
            } else {
                // 编辑用户自定义
                action = 'update';
                params.id = e.ref_id || e.id.replace('user_', '');
            }

            postSeForm(action, params)
                .then(function (data) {
                    if (data.ok) {
                        closeForm();
                        loadSeList();
                        loadSearchEngines(true);
                    } else {
                        alert(data.error || '保存失败');
                    }
                });
        });
    }

    function moveSeItem(idx, dir) {
        var newIdx = idx + dir;
        if (newIdx < 0 || newIdx >= userEnginesCache.length) return;
        var tmp = userEnginesCache[idx];
        userEnginesCache[idx] = userEnginesCache[newIdx];
        userEnginesCache[newIdx] = tmp;
        saveSeSort();
    }

    function saveSeSort() {
        // 发送所有引擎的 type:id 格式
        var ids = userEnginesCache.map(function (e) {
            var type = e.type; // global 或 user
            var id = e.type === 'global' ? (e.global_id || e.ref_id) : (e.ref_id || e.id.replace('user_', ''));
            return type + ':' + id;
        });
        postSeForm('sort', { ids: ids.join(',') })
            .then(function (data) {
                if (data.ok) {
                    renderSeList();
                    loadSearchEngines(true);
                } else {
                    alert(data.error || '排序失败');
                    loadSeList(); // 重新加载恢复正确顺序
                }
            });
    }

    function deleteSeItem(e) {
        var msg = e.type === 'global'
            ? '确定从我的列表中移除该默认搜索引擎？（不影响其他用户，可在后台重新启用）'
            : '确定删除此搜索引擎？';
        if (!confirm(msg)) return;

        var params = {};
        if (e.type === 'global') {
            params.global_id = e.global_id || e.ref_id;
        } else {
            params.id = e.ref_id || e.id.replace('user_', '');
        }

        postSeForm('delete', params)
            .then(function (data) {
                if (data.ok) {
                    loadSeList();
                    loadSearchEngines(true);
                } else {
                    alert(data.error || '删除失败');
                }
            });
    }

    if (seAddBtn) {
        seAddBtn.addEventListener('click', function () {
            var name = seAddName.value.trim();
            var url = seAddUrl.value.trim();
            var icon = seAddIcon.value.trim();

            if (!name) { alert('请输入名称'); return; }
            if (!url || !/^https?:\/\//i.test(url) || (url.indexOf('{q}') === -1 && url.indexOf('{query}') === -1)) {
                alert('URL 必须以 http(s):// 开头且包含 {q} 占位符');
                return;
            }

            postSeForm('add', { name: name, url_template: url, icon: icon })
                .then(function (data) {
                    if (data.ok) {
                        seAddName.value = '';
                        seAddUrl.value = '';
                        seAddIcon.value = '';
                        loadSeList();
                        loadSearchEngines(true);
                    } else {
                        alert(data.error || '添加失败');
                    }
                });
        });
    }

    if (seModal) {
        seModal.querySelectorAll('[data-se-close]').forEach(function (el) {
            el.addEventListener('click', closeSeModal);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !seModal.hidden) closeSeModal();
        });
    }

    /* ----------------------------------------------------------------------
       复制成功反馈（铁律 10）：图标即时换成对勾并微弹，2.5s 后淡出恢复原样
       具名函数声明会被提升，卡片操作与二维码弹窗两处都能直接调用
       ---------------------------------------------------------------------- */

    var COPY_CHECK_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        + 'stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M20 6 9 17l-5-5"></path></svg>';

    function flashCopied(trigger) {
        if (!trigger) {
            return;
        }

        var HOLD = 2500;
        var FADE = 300;

        // 首次点击时记住原图标与无障碍标签，供恢复使用
        if (typeof trigger.__copyOriginal !== 'string') {
            trigger.__copyOriginal = trigger.innerHTML;
            trigger.__copyLabel = trigger.getAttribute('aria-label') || '复制链接';
        }

        if (trigger.__copyTimer) {
            window.clearTimeout(trigger.__copyTimer);
            window.clearTimeout(trigger.__copyFadeTimer);
        }

        trigger.classList.remove('is-fading');
        trigger.classList.add('is-copied');
        trigger.setAttribute('aria-label', '已复制');
        trigger.innerHTML = COPY_CHECK_SVG;

        // 到点先淡出，再换回原图标，避免对勾「啪」地变回复制
        trigger.__copyTimer = window.setTimeout(function () {
            trigger.classList.add('is-fading');

            trigger.__copyFadeTimer = window.setTimeout(function () {
                trigger.classList.remove('is-copied', 'is-fading');
                trigger.innerHTML = trigger.__copyOriginal;
                trigger.setAttribute('aria-label', trigger.__copyLabel);
                trigger.__copyTimer = null;
            }, FADE);
        }, HOLD - FADE);
    }

    /* ----------------------------------------------------------------------
       流式打字（铁律 4）：品牌名首次进站时逐字从 blur(6px) 晕开
       单字 90ms 去模糊由 CSS 关键帧控制，相邻字错开 30ms
       ---------------------------------------------------------------------- */

    function streamText(el) {
        if (!el || el.getAttribute('data-streamed') === '1') {
            return;
        }

        var text = (el.textContent || '').trim();

        // 太短没效果，太长会拖慢首屏感知
        if (text.length < 2 || text.length > 16) {
            return;
        }

        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        el.setAttribute('data-streamed', '1');

        var frag = document.createDocumentFragment();

        for (var i = 0; i < text.length; i++) {
            var span = document.createElement('span');
            span.className = 'stream-char';
            span.textContent = text.charAt(i);
            span.style.animationDelay = (i * 30) + 'ms';
            span.setAttribute('aria-hidden', 'true');
            frag.appendChild(span);
        }

        el.textContent = '';
        el.appendChild(frag);

        // 动画放完还原为纯文本，避免逐字节点影响朗读、复制与文本选中
        window.setTimeout(function () {
            el.textContent = text;
        }, text.length * 30 + 470);
    }

    var brandTitle = document.querySelector('.brand-title');
    var alreadyStreamed = false;

    try {
        alreadyStreamed = window.sessionStorage.getItem('xy-streamed') === '1';
    } catch (err) {
        alreadyStreamed = false;
    }

    if (brandTitle && !alreadyStreamed) {
        streamText(brandTitle);

        try {
            window.sessionStorage.setItem('xy-streamed', '1');
        } catch (err) {
            /* 隐私模式下无法写入，忽略即可，最多重复播放一次 */
        }
    }
})();
