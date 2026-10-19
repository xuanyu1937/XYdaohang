<?php
require_once __DIR__ . '/../includes/functions.php';
$functions = new Functions();
$db = Database::getInstance()->getConnection();
$pendingApplications = $db->query("SELECT COUNT(*) as count FROM applications WHERE status = 'pending'")->fetch()['count'];
$systemConfig = $functions->getSystemConfig();

// 未读联系留言数：v2 新增表，尚未执行升级脚本时忽略
$unreadMessages = 0;

try {
    $unreadMessages = (int) $db->query("SELECT COUNT(*) as count FROM contact_messages WHERE is_read = 0")->fetch()['count'];
} catch (Throwable $error) {
    $unreadMessages = 0;
}
?>
<style>
    :root {
        --sidebar-width: 260px;
        --sidebar-collapsed-width: 72px;
        --sidebar-gradient: linear-gradient(180deg, #0b1533 0%, #101d44 52%, #16234f 100%);
        --primary-color: #4f6df5;
        --transition-smooth: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* ----------------------------------------------------------------------
       侧边栏主体：固定定位 + 纵向弹性布局，避免底部用户区遮挡菜单
       ---------------------------------------------------------------------- */
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1050;
        display: flex;
        flex-direction: column;
        width: var(--sidebar-width);
        height: 100vh;
        height: 100dvh;
        background: var(--sidebar-gradient);
        border-right: 1px solid rgba(255, 255, 255, 0.06);
        box-shadow: 10px 0 34px rgba(8, 15, 35, 0.14);
        overflow: hidden;
        transition: var(--transition-smooth);
    }

    .sidebar.collapsed {
        width: var(--sidebar-collapsed-width);
    }

    /* 品牌区 */
    .sidebar-brand {
        display: flex;
        flex: 0 0 auto;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        min-height: 76px;
        padding: 0 18px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.07);
        transition: var(--transition-smooth);
    }

    .sidebar-brand h4 {
        display: flex;
        align-items: center;
        gap: 11px;
        min-width: 0;
        margin: 0;
        color: #ffffff;
        font-size: 15px;
        font-weight: 600;
        letter-spacing: 0.01em;
    }

    .sidebar-brand h4 span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        transition: opacity 0.2s ease;
    }

    .sidebar-brand .logo-icon {
        display: flex;
        flex: 0 0 36px;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        background: linear-gradient(135deg, #4f6df5 0%, #7c5cf5 100%);
        border-radius: 10px;
        box-shadow: 0 6px 16px rgba(79, 109, 245, 0.36);
        color: #ffffff;
        font-size: 15px;
    }

    .collapse-toggle {
        display: flex;
        flex: 0 0 28px;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        padding: 0;
        background: rgba(255, 255, 255, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 8px;
        color: rgba(226, 232, 240, 0.72);
        font-size: 10px;
        cursor: pointer;
        transition: var(--transition-smooth);
    }

    .collapse-toggle:hover {
        background: rgba(255, 255, 255, 0.13);
        color: #ffffff;
    }

    .toggle-icon {
        transition: transform 0.28s ease;
    }

    /* 菜单区：独立滚动，底部用户信息始终可见 */
    .sidebar-nav {
        display: flex;
        flex: 1 1 auto;
        flex-direction: column;
        gap: 3px;
        padding: 14px 12px 20px;
        overflow-x: hidden;
        overflow-y: auto;
    }

    .nav-link {
        position: relative;
        display: flex;
        align-items: center;
        gap: 11px;
        margin: 0;
        padding: 11px 13px;
        background: transparent;
        border-radius: 10px;
        color: rgba(226, 232, 240, 0.72);
        font-size: 14px;
        font-weight: 500;
        line-height: 1.4;
        text-decoration: none;
        white-space: nowrap;
        transition: background-color 0.18s ease, color 0.18s ease;
    }

    .nav-link::before {
        position: absolute;
        top: 50%;
        left: 0;
        width: 3px;
        height: 20px;
        background: #8da2ff;
        border-radius: 0 3px 3px 0;
        content: "";
        transform: translateY(-50%) scaleY(0);
        transition: transform 0.2s ease;
    }

    .nav-link:hover {
        background: rgba(255, 255, 255, 0.065);
        color: #ffffff;
        transform: none;
    }

    .nav-link.active {
        background: linear-gradient(90deg, rgba(79, 109, 245, 0.3) 0%, rgba(79, 109, 245, 0.1) 100%);
        box-shadow: inset 0 0 0 1px rgba(141, 162, 255, 0.15);
        color: #ffffff;
        backdrop-filter: none;
    }

    .nav-link.active::before {
        transform: translateY(-50%) scaleY(1);
    }

    .nav-link i {
        flex: 0 0 18px;
        width: 18px;
        color: rgba(203, 213, 225, 0.78);
        font-size: 14px;
        text-align: center;
        transition: color 0.18s ease;
    }

    .nav-link:hover i,
    .nav-link.active i {
        color: #c7d2fe;
    }

    .nav-link span {
        flex: 1 1 auto;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: opacity 0.2s ease;
    }

    .nav-link .badge {
        display: inline-flex;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        height: 20px;
        margin-left: auto;
        padding: 0 6px;
        background: #ef4444 !important;
        border-radius: 999px;
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.14);
        color: #ffffff !important;
        font-size: 11px;
        font-weight: 600;
        line-height: 1;
        transition: var(--transition-smooth);
    }

    /* 底部用户信息 */
    .sidebar-footer {
        flex: 0 0 auto;
        padding: 16px 14px 18px;
        background: rgba(7, 13, 30, 0.35);
        border-top: 1px solid rgba(255, 255, 255, 0.07);
    }

    .sidebar .user-info {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        transition: var(--transition-smooth);
    }

    .sidebar .user-avatar {
        display: flex;
        flex: 0 0 36px;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        background: linear-gradient(135deg, #4f6df5 0%, #7c5cf5 100%);
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(79, 109, 245, 0.3);
        color: #ffffff;
        font-size: 14px;
        font-weight: 600;
        letter-spacing: 0.02em;
    }

    .sidebar .user-details {
        flex: 1 1 auto;
        min-width: 0;
    }

    .sidebar .user-name {
        margin-bottom: 2px;
        overflow: hidden;
        color: #f1f5f9;
        font-size: 13px;
        font-weight: 600;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .sidebar .user-time {
        display: flex;
        align-items: center;
        gap: 5px;
        color: rgba(203, 213, 225, 0.65);
        font-size: 11.5px;
        line-height: 1.4;
    }

    .sidebar .user-time i {
        font-size: 10px;
    }

    /* ----------------------------------------------------------------------
       折叠态
       ---------------------------------------------------------------------- */
    .sidebar.collapsed .sidebar-brand {
        flex-direction: column;
        justify-content: center;
        gap: 8px;
        min-height: 104px;
        padding: 14px 0;
    }

    .sidebar.collapsed .sidebar-brand h4 {
        gap: 0;
    }

    .sidebar.collapsed .sidebar-brand h4 span,
    .sidebar.collapsed .nav-link span:not(.badge),
    .sidebar.collapsed .user-details {
        display: none !important;
    }

    .sidebar.collapsed .collapse-toggle {
        width: 26px;
        height: 26px;
        flex-basis: 26px;
    }

    .sidebar.collapsed .sidebar-nav {
        padding: 12px 10px 16px;
    }

    .sidebar.collapsed .nav-link {
        justify-content: center;
        gap: 0;
        padding: 12px 0;
    }

    .sidebar.collapsed .nav-link i {
        flex: 0 0 auto;
        width: auto;
        font-size: 16px;
    }

    .sidebar.collapsed .nav-link .badge {
        position: absolute;
        top: 6px;
        right: 8px;
        width: 8px;
        height: 8px;
        min-width: 8px;
        min-height: 8px;
        padding: 0;
        box-shadow: 0 0 0 2px #101d44;
        font-size: 0;
    }

    .sidebar.collapsed .sidebar-footer {
        padding: 14px 0 16px;
    }

    .sidebar.collapsed .user-info {
        justify-content: center;
    }

    .sidebar.collapsed .user-avatar {
        flex-basis: 34px;
        width: 34px;
        height: 34px;
        border-radius: 9px;
        font-size: 13px;
    }

    /* ----------------------------------------------------------------------
       移动端
       ---------------------------------------------------------------------- */
    .mobile-menu-toggle {
        display: none;
    }

    .sidebar-overlay {
        display: none;
    }

    @media (max-width: 768px) {
        .sidebar {
            width: 280px;
            transform: translateX(-100%);
        }

        .sidebar.mobile-open {
            transform: translateX(0);
        }

        .sidebar-overlay {
            position: fixed;
            inset: 0;
            z-index: 1040;
            display: none;
            background: rgba(8, 15, 35, 0.48);
            backdrop-filter: blur(2px);
        }

        .sidebar-overlay.active {
            display: block;
        }

        .mobile-menu-toggle {
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 1030;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            background: var(--sidebar-gradient);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(8, 15, 35, 0.24);
            color: #ffffff;
            font-size: 16px;
            cursor: pointer;
        }
    }

    /* 侧边栏滚动条 */
    .sidebar-nav::-webkit-scrollbar {
        width: 4px;
    }

    .sidebar-nav::-webkit-scrollbar-track {
        background: transparent;
    }

    .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.22);
        border-radius: 999px;
    }

    .sidebar-nav::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.34);
    }
</style>

<button class="mobile-menu-toggle" id="mobileMenuToggle">
    <i class="fas fa-bars"></i>
</button>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h4>
            <div class="logo-icon">
                <i class="fas fa-compass"></i>
            </div>
            <span><?php echo htmlspecialchars($systemConfig['name'] ?? '导航系统'); ?></span>
        </h4>
        <button class="collapse-toggle" id="collapseToggle" title="折叠/展开">
            <i class="fas fa-chevron-left toggle-icon"></i>
        </button>
    </div>
    
    <nav class="sidebar-nav">
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">
            <i class="fas fa-tachometer-alt"></i>
            <span>仪表盘</span>
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="settings.php">
            <i class="fas fa-cog"></i>
            <span>系统设置</span>
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'categories.php' ? 'active' : ''; ?>" href="categories.php">
            <i class="fas fa-folder"></i>
            <span>分类管理</span>
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'nav_links.php' ? 'active' : ''; ?>" href="nav_links.php">
            <i class="fas fa-link"></i>
            <span>导航管理</span>
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'applications.php' ? 'active' : ''; ?>" href="applications.php">
            <i class="fas fa-inbox"></i>
            <span>申请管理</span>
            <?php if ($pendingApplications > 0): ?>
                <span class="badge bg-danger"><?php echo $pendingApplications; ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>" href="users.php">
            <i class="fas fa-users"></i>
            <span>用户管理</span>
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'stats.php' ? 'active' : ''; ?>" href="stats.php">
            <i class="fas fa-chart-line"></i>
            <span>数据统计</span>
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : ''; ?>" href="messages.php">
            <i class="fas fa-envelope"></i>
            <span>留言管理</span>
            <?php if ($unreadMessages > 0): ?>
                <span class="badge bg-danger"><?php echo $unreadMessages; ?></span>
            <?php endif; ?>
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'tools.php' ? 'active' : ''; ?>" href="tools.php">
            <i class="fas fa-toolbox"></i>
            <span>系统工具</span>
        </a>
        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>" href="profile.php">
            <i class="fas fa-user"></i>
            <span>管理员信息</span>
        </a>
        <a class="nav-link" href="../index.php" target="_blank">
            <i class="fas fa-eye"></i>
            <span>查看前台</span>
        </a>
        <a class="nav-link" href="logout.php">
            <i class="fas fa-sign-out-alt"></i>
            <span>退出登录</span>
        </a>
    </nav>
    
    <div class="sidebar-footer">
        <div class="user-info">
            <div class="user-avatar" title="<?php echo $functions->escape($_SESSION['admin_username'] ?? ''); ?>">
                <?php echo $functions->escape(strtoupper(substr((string) ($_SESSION['admin_username'] ?? ''), 0, 1))); ?>
            </div>
            <div class="user-details">
                <div class="user-name"><?php echo $functions->escape($_SESSION['admin_username'] ?? ''); ?></div>
                <div class="user-time">
                    <i class="fas fa-clock"></i>
                    <span class="text" id="sidebarTime"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content-wrapper');
    const toggleIcon = document.querySelector('.toggle-icon');
    
    sidebar.classList.toggle('collapsed');
    
    if (mainContent) {
        mainContent.classList.toggle('expanded');
    }
    
    const isCollapsed = sidebar.classList.contains('collapsed');
    localStorage.setItem('sidebarCollapsed', isCollapsed ? 'true' : 'false');
    
    toggleIcon.style.transform = isCollapsed ? 'rotate(180deg)' : 'rotate(0deg)';
}

function toggleMobileMenu() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    sidebar.classList.toggle('mobile-open');
    overlay.classList.toggle('active');
}

function updateSidebarTime() {
    const now = new Date();
    const timeStr = now.getHours().toString().padStart(2, '0') + ':' + 
                   now.getMinutes().toString().padStart(2, '0') + ':' + 
                   now.getSeconds().toString().padStart(2, '0');
    const sidebarTime = document.getElementById('sidebarTime');
    if (sidebarTime) {
        sidebarTime.textContent = timeStr;
    }
}

function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content-wrapper');
    const toggleIcon = document.querySelector('.toggle-icon');
    const savedState = localStorage.getItem('sidebarCollapsed');
    const isMobile = window.innerWidth <= 768;
    
    if (!document.querySelector('.main-content-wrapper')) {
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            const wrapper = document.createElement('div');
            wrapper.className = 'main-content-wrapper';
            mainContent.parentNode.insertBefore(wrapper, mainContent);
            wrapper.appendChild(mainContent);
        }
    }
    
    if (isMobile) {
        sidebar.classList.remove('collapsed');
        if (mainContent) {
            mainContent.classList.remove('expanded');
        }
    } else if (savedState === 'true') {
        sidebar.classList.add('collapsed');
        if (mainContent) {
            mainContent.classList.add('expanded');
        }
        toggleIcon.style.transform = 'rotate(180deg)';
    }
    
    if (isMobile) {
        document.querySelectorAll('.sidebar-nav .nav-link').forEach(link => {
            link.addEventListener('click', () => {
                toggleMobileMenu();
            });
        });
    }
}

document.addEventListener('DOMContentLoaded', function() {
    initSidebar();
    
    const collapseToggle = document.getElementById('collapseToggle');
    if (collapseToggle) {
        collapseToggle.addEventListener('click', toggleSidebar);
    }
    
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', toggleMobileMenu);
    }
    
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', toggleMobileMenu);
    }
    
    updateSidebarTime();
    setInterval(updateSidebarTime, 1000);
    
    window.addEventListener('resize', initSidebar);
});
</script>
