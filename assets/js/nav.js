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
    var toTop = document.getElementById('toTop');
    var modeCycle = document.getElementById('modeCycle');
    var hotStrip = document.querySelector('.hot-strip');
    var userMenu = document.getElementById('userMenu');
    var skinPicker = document.getElementById('skinPicker');

    var MODES = ['grid', 'list', 'compact'];
    var SKINS = ['default', 'purple', 'orange', 'mint'];

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

    function cardMatches(card) {
        if (!state.query) {
            return true;
        }

        var haystack = [
            card.getAttribute('data-name') || '',
            card.getAttribute('data-title') || '',
            card.getAttribute('data-domain') || '',
            card.getAttribute('data-host') || '',
            card.getAttribute('data-description') || ''
        ].join(' ').toLowerCase();

        return haystack.indexOf(state.query) !== -1;
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
                if (emptyTitle) emptyTitle.textContent = '暂无网站';
                if (emptyText) emptyText.textContent = '请先在后台添加并启用网站。';
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
                commitSearch();
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

        if (!text) {
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
            setStatus(card, 'online', Math.max(1, Math.round(ended - started)));
        }).catch(function () {
            setStatus(card, 'offline');
        }).then(function () {
            clearTimeout(timer);
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
})();
