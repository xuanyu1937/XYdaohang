<?php
/**
 * 轩宇-导航 · 后台数据统计中心
 * 全部图表与概览数字均来自 ../api/stats.php，页面本身不直接查询统计 SQL。
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once '../includes/functions.php';

$functions = new Functions();

if (!$functions->isLoggedIn()) {
    $functions->redirect('login.php');
}

// 统计范围：0 表示公共导航（与 api/stats.php 的 user_id 参数一致）
$statsUserId = 0;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据统计 - 后台管理</title>
    <link href="../assets/css/bootstrap.css" rel="stylesheet">
    <link href="../assets/css/font-awesome.min.css" rel="stylesheet" onerror="this.onerror=null;this.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';">
    <link href="../assets/css/admin.css?v=1.1.3" rel="stylesheet">
    <script src="../assets/js/chart.umd.min.js"></script>
    <script>window.Chart || document.write('<script src="https://cdn.staticfile.org/Chart.js/4.3.0/chart.umd.min.js"><\/script>');</script>
    <style>
        .chart-box {
            position: relative;
            width: 100%;
            height: 320px;
        }

        .chart-box.is-tall {
            height: 380px;
        }

        .stats-range-select {
            min-width: 160px;
        }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="main-content-wrapper" id="mainContentWrapper">

        <header class="page-header">
            <div class="page-heading">
                <div class="page-breadcrumb">
                    <span>后台管理</span>
                    <i class="fas fa-angle-right"></i>
                    <span>数据统计</span>
                </div>
                <h1 class="page-title">
                    <span class="title-icon"><i class="fas fa-chart-line"></i></span>
                    统计中心
                </h1>
                <p class="page-subtitle">查看站点收录、点击趋势、热门排行与链接健康状态。</p>
            </div>
            <div class="page-header-meta">
                <span class="meta-pill">
                    <i class="fas fa-globe"></i>
                    统计范围：公共导航
                </span>
            </div>
        </header>

        <div class="admin-page-body" id="statsPage" data-user-id="<?php echo (int) $statsUserId; ?>">
            <div class="alert alert-danger alert-dismissible fade show d-none" role="alert" id="statsAlert">
                <span id="statsAlertText"></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>

            <div class="card mb-4">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-filter text-muted"></i>
                        <label class="form-label mb-0" for="rangeSelect">时间范围</label>
                    </div>
                    <select class="form-select stats-range-select" id="rangeSelect">
                        <option value="7" selected>近 7 天</option>
                        <option value="30">近 30 天</option>
                        <option value="90">近 90 天</option>
                    </select>
                    <span class="text-muted small">
                        <i class="fas fa-circle-info me-1"></i>切换后自动重新加载统计数据
                    </span>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-6 col-md-4 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">收录站点数</h6>
                                    <h3 class="card-title mb-0" id="ovLinks">—</h3>
                                </div>
                                <div class="stat-icon stat-primary">
                                    <i class="fas fa-link"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">启用中站点数</h6>
                                    <h3 class="card-title mb-0" id="ovActiveLinks">—</h3>
                                </div>
                                <div class="stat-icon stat-success">
                                    <i class="fas fa-circle-check"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">分类数</h6>
                                    <h3 class="card-title mb-0" id="ovCategories">—</h3>
                                </div>
                                <div class="stat-icon stat-info">
                                    <i class="fas fa-folder-tree"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">用户数</h6>
                                    <h3 class="card-title mb-0" id="ovUsers">—</h3>
                                </div>
                                <div class="stat-icon stat-primary">
                                    <i class="fas fa-users"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">累计点击</h6>
                                    <h3 class="card-title mb-0" id="ovClicks">—</h3>
                                </div>
                                <div class="stat-icon stat-info">
                                    <i class="fas fa-hand-pointer"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">今日点击</h6>
                                    <h3 class="card-title mb-0" id="ovTodayClicks">—</h3>
                                </div>
                                <div class="stat-icon stat-success">
                                    <i class="fas fa-calendar-day"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-4 col-xl-3">
                    <div class="card stat-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="card-subtitle mb-2">待审核申请</h6>
                                    <h3 class="card-title mb-0" id="ovPending">—</h3>
                                </div>
                                <div class="stat-icon stat-warning">
                                    <i class="fas fa-inbox"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-xl-8">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-chart-line"></i>点击趋势</h5>
                                <span class="card-subtitle">近 <span id="trendRangeLabel">7</span> 天每日点击量</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="trendChartBox" class="chart-box">
                                <canvas id="trendChart"></canvas>
                            </div>
                            <div class="text-center py-5 text-muted d-none" id="trendUnavailable">
                                <i class="fas fa-circle-exclamation fa-3x mb-3"></i>
                                <p class="mb-1">点击趋势暂不可用</p>
                                <p class="small mb-0">
                                    数据库尚未执行 v2.0.0 升级脚本，缺少 visit_logs 表，因此暂无逐日点击数据。
                                    执行 upgrade_v2.0.0.sql 后即可查看趋势。
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-heart-pulse"></i>链接健康状态</h5>
                                <span class="card-subtitle">正常 / 较慢 / 失效 / 未检测</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-box" style="height: 300px;">
                                <canvas id="healthChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-fire"></i>热门站点 Top10</h5>
                                <span class="card-subtitle">按累计点击量排序</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="topChartBox" class="chart-box">
                                <canvas id="topChart"></canvas>
                            </div>
                            <div class="text-center py-5 text-muted d-none" id="topEmpty">
                                <i class="fas fa-chart-simple fa-3x mb-3"></i>
                                <p class="mb-0">暂无点击数据，站点被点击后这里会出现排行</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-user-plus"></i>用户增长</h5>
                                <span class="card-subtitle">近 <span id="growthRangeLabel">7</span> 天每日新增用户</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-box">
                                <canvas id="growthChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <div>
                                <h5 class="mb-0"><i class="fas fa-folder-tree"></i>分类分布 Top12</h5>
                                <span class="card-subtitle">各分类下启用中的站点数量</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="categoryChartBox" class="chart-box is-tall">
                                <canvas id="categoryChart"></canvas>
                            </div>
                            <div class="text-center py-5 text-muted d-none" id="categoryEmpty">
                                <i class="fas fa-folder-open fa-3x mb-3"></i>
                                <p class="mb-0">暂无分类站点数据</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/jquery3.7.1.js"></script>
    <script src="../assets/js/bootstrap.bundle.min.js"></script>
    <?php require_once __DIR__ . '/../includes/asset_fallbacks.php'; ?>
    <script>
    (function () {
        var page = document.getElementById('statsPage');
        var rangeSelect = document.getElementById('rangeSelect');
        var alertBox = document.getElementById('statsAlert');
        var alertText = document.getElementById('statsAlertText');

        if (!page || !rangeSelect) {
            return;
        }

        var userId = page.getAttribute('data-user-id') || '0';
        var chartTheme = {
            primary: '#3b5bdb',
            primarySoft: 'rgba(59, 91, 219, 0.12)',
            success: '#0f9f6e',
            successSoft: 'rgba(15, 159, 110, 0.12)',
            info: '#0ea5e9',
            warning: '#f59e0b',
            danger: '#dc2626',
            muted: '#94a3b8'
        };
        var charts = {};

        function setText(id, value) {
            var node = document.getElementById(id);

            if (node) {
                node.textContent = value;
            }
        }

        function numberText(value) {
            var number = Number(value);

            return isFinite(number) ? number.toLocaleString('zh-CN') : '0';
        }

        function showAlert(message) {
            if (!alertBox || !alertText) {
                return;
            }

            alertText.textContent = message;
            alertBox.classList.remove('d-none');
        }

        function toggleBox(chartBoxId, emptyId, hasData) {
            var box = document.getElementById(chartBoxId);
            var empty = document.getElementById(emptyId);

            if (box) {
                box.classList.toggle('d-none', !hasData);
            }

            if (empty) {
                empty.classList.toggle('d-none', hasData);
            }

            return hasData;
        }

        function destroyChart(key) {
            if (charts[key]) {
                charts[key].destroy();
                delete charts[key];
            }
        }

        function renderChart(key, canvasId, config) {
            var canvas = document.getElementById(canvasId);

            if (!canvas || typeof Chart === 'undefined') {
                return;
            }

            destroyChart(key);
            charts[key] = new Chart(canvas.getContext('2d'), config);
        }

        function renderOverview(overview) {
            overview = overview || {};

            setText('ovLinks', numberText(overview.links));
            setText('ovActiveLinks', numberText(overview.active_links));
            setText('ovCategories', numberText(overview.categories));
            setText('ovUsers', numberText(overview.users));
            setText('ovClicks', numberText(overview.clicks));
            setText('ovTodayClicks', numberText(overview.today_clicks));
            setText('ovPending', numberText(overview.pending_applications));
        }

        function renderTrend(trend) {
            trend = trend || {};
            var unavailable = trend.source === 'unavailable';
            var items = Array.isArray(trend.items) ? trend.items : [];

            document.getElementById('trendUnavailable').classList.toggle('d-none', !unavailable);
            document.getElementById('trendChartBox').classList.toggle('d-none', unavailable);

            if (unavailable) {
                destroyChart('trend');

                return;
            }

            renderChart('trend', 'trendChart', {
                type: 'line',
                data: {
                    labels: items.map(function (item) { return item.day; }),
                    datasets: [{
                        label: '点击数',
                        data: items.map(function (item) { return Number(item.value) || 0; }),
                        borderColor: chartTheme.primary,
                        backgroundColor: chartTheme.primarySoft,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        }

        function renderHealth(health) {
            health = health || {};

            renderChart('health', 'healthChart', {
                type: 'doughnut',
                data: {
                    labels: ['正常', '较慢', '失效', '未检测'],
                    datasets: [{
                        data: [
                            Number(health.ok) || 0,
                            Number(health.slow) || 0,
                            Number(health.down) || 0,
                            Number(health.unknown) || 0
                        ],
                        backgroundColor: [
                            chartTheme.success,
                            chartTheme.warning,
                            chartTheme.danger,
                            chartTheme.muted
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }

        function renderTop(top) {
            var items = Array.isArray(top) ? top : [];

            if (!toggleBox('topChartBox', 'topEmpty', items.length > 0)) {
                destroyChart('top');

                return;
            }

            renderChart('top', 'topChart', {
                type: 'bar',
                data: {
                    labels: items.map(function (item) { return item.name; }),
                    datasets: [{
                        label: '点击数',
                        data: items.map(function (item) { return Number(item.clicks) || 0; }),
                        backgroundColor: chartTheme.primary,
                        borderRadius: 6,
                        maxBarThickness: 22
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        }

        function renderGrowth(growth) {
            var items = Array.isArray(growth) ? growth : [];

            renderChart('growth', 'growthChart', {
                type: 'line',
                data: {
                    labels: items.map(function (item) { return item.day; }),
                    datasets: [{
                        label: '新增用户',
                        data: items.map(function (item) { return Number(item.total) || 0; }),
                        borderColor: chartTheme.success,
                        backgroundColor: chartTheme.successSoft,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        }

        function renderCategories(categories) {
            var items = Array.isArray(categories) ? categories : [];

            if (!toggleBox('categoryChartBox', 'categoryEmpty', items.length > 0)) {
                destroyChart('categories');

                return;
            }

            renderChart('categories', 'categoryChart', {
                type: 'bar',
                data: {
                    labels: items.map(function (item) { return item.name; }),
                    datasets: [{
                        label: '站点数量',
                        data: items.map(function (item) { return Number(item.total) || 0; }),
                        backgroundColor: chartTheme.info,
                        borderRadius: 6,
                        maxBarThickness: 24
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, ticks: { precision: 0 } }
                    }
                }
            });
        }

        function render(data) {
            renderOverview(data.overview);
            renderTrend(data.trend);
            renderTop(data.top);
            renderHealth(data.health);
            renderGrowth(data.growth);
            renderCategories(data.categories);

            setText('trendRangeLabel', String(data.range || ''));
            setText('growthRangeLabel', String(data.range || ''));

            if (typeof Chart === 'undefined') {
                showAlert('图表库 Chart.js 加载失败，当前仅显示概览数字，请检查网络或 CDN 可用性。');
            } else if (alertBox) {
                alertBox.classList.add('d-none');
            }
        }

        function loadStats(range) {
            if (typeof fetch !== 'function') {
                showAlert('当前浏览器不支持异步加载统计数据，请更换现代浏览器。');

                return;
            }

            fetch('../api/stats.php?range=' + encodeURIComponent(range) + '&user_id=' + encodeURIComponent(userId), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (!data || data.ok !== true) {
                        throw new Error(data && data.error ? data.error : '接口返回数据异常');
                    }

                    render(data);
                })
                .catch(function (error) {
                    var reason = error && error.message ? error.message : '网络异常';

                    showAlert('统计数据加载失败：' + reason + '。请稍后重试。');
                });
        }

        rangeSelect.addEventListener('change', function () {
            loadStats(rangeSelect.value);
        });

        loadStats(rangeSelect.value);
    })();
    </script>
</body>
</html>
