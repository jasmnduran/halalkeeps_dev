<?php
// Get current page for active sidebar highlighting
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));
$role_id = $_SESSION['role_id'] ?? 0;
$role_name = $_SESSION['role_name'] ?? 'User';
$notif_count = isLoggedIn() ? getUnreadNotificationCount($conn, $_SESSION['user_id']) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Verify Halal, Anytime, Anywhere">
    <title><?= $page_title ?? 'Halal Keeps' ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Outfit:wght@300;400;500;600;700;800&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">

    <!-- Font Awesome (existing icon set — kept for all fa-* icons throughout the app) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Tailwind CSS — prefixed with "tw-" so utilities never clash with the
         existing custom CSS (style.css) that uses the same class names like
         .hidden, .flex, .btn, .card, etc. -->
    <script>
    // Tailwind config must be declared BEFORE the CDN script loads
    window.tailwind = window.tailwind || {};
    </script>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
    tailwind.config = {
        prefix: 'tw-',
        darkMode: 'class',
        theme: {
            extend: {
                screens: {
                    'xs': '480px',
                },
                fontFamily: {
                    sans: ['Inter', 'ui-sans-serif', 'system-ui'],
                    display: ['Outfit', 'Inter', 'ui-sans-serif'],
                },
                transitionProperty: {
                    'sidebar': 'transform, box-shadow',
                },
                width: {
                    'sidebar': '16rem',   /* 256px — matches --sidebar-width */
                },
                spacing: {
                    'sidebar': '16rem',
                    'header': '4rem',     /* 64px — matches --header-height */
                },
            },
        },
    };
    </script>

    <!-- Custom CSS (existing — unchanged) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="tw-bg-neutral-50 tw-text-neutral-900 tw-antialiased">
<?php if (isset($is_dashboard) && $is_dashboard): ?>
<!-- ═══════════════════════════════════════════════════════════════════════
     DASHBOARD LAYOUT
     Mobile:  sidebar hidden off-canvas (-translate-x-full), burger visible
     Desktop: sidebar static (lg:translate-x-0), burger hidden
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="tw-flex tw-min-h-screen tw-overflow-x-hidden">

    <!-- ── Backdrop overlay (mobile only) ──────────────────────────────── -->
    <div id="sidebarOverlay"
         aria-hidden="true"
         class="tw-fixed tw-inset-0 tw-bg-black/50 tw-backdrop-blur-sm tw-z-40
                tw-opacity-0 tw-pointer-events-none
                tw-transition-opacity tw-duration-300 tw-ease-in-out
                lg:tw-hidden"></div>

    <!-- ── Off-canvas sidebar ───────────────────────────────────────────── -->
    <aside id="sidebar"
           role="navigation"
           aria-label="Main navigation"
           class="sidebar
                  tw-fixed tw-inset-y-0 tw-left-0 tw-z-50
                  tw-w-64 tw-flex tw-flex-col
                  tw-overflow-y-auto tw-overscroll-contain
                  tw--translate-x-full lg:tw-translate-x-0
                  tw-transition-transform tw-duration-300 tw-ease-in-out
                  tw-will-change-transform">

        <!-- Brand / workspace header -->
        <div class="tw-flex tw-items-center tw-justify-between tw-gap-3
                    tw-px-5 tw-py-5 tw-border-b tw-border-white/10 tw-flex-shrink-0">
            <?php
            $ws = (int)($role_id) === ROLE_BUSINESS_OWNER ? getStaffWorkspaceLabel() : null;
            ?>
            <?php if ($ws): ?>
            <div class="tw-flex tw-items-center tw-gap-3 tw-min-w-0">
                <img src="<?= BASE_URL ?>assets/images/logo.jpg"
                     alt="Halal Keeps"
                     class="tw-h-10 tw-w-10 tw-rounded-full tw-object-cover tw-flex-shrink-0
                            tw-border-2 tw-border-white/20">
                <div class="tw-min-w-0">
                    <p class="tw-text-sm tw-font-bold tw-text-white tw-leading-tight tw-truncate">
                        Halal Keeps
                    </p>
                    <span class="tw-inline-block tw-mt-0.5 tw-px-2 tw-py-0.5
                                 tw-rounded-full tw-text-[10px] tw-font-bold
                                 tw-uppercase tw-tracking-widest tw-border
                                 tw-whitespace-nowrap"
                          style="background:<?= $ws['color'] ?>22;
                                 color:<?= $ws['color'] ?>;
                                 border-color:<?= $ws['color'] ?>44;">
                        <?= htmlspecialchars($ws['sublabel']) ?>
                    </span>
                </div>
            </div>
            <?php else: ?>
            <a href="<?= BASE_URL ?>"
               class="tw-flex tw-items-center tw-gap-3 tw-min-w-0 tw-no-underline">
                <img src="<?= BASE_URL ?>assets/images/logo.jpg"
                     alt="Halal Keeps"
                     class="tw-h-10 tw-w-10 tw-rounded-full tw-object-cover tw-flex-shrink-0
                            tw-border-2 tw-border-white/20">
                <div>
                    <p class="tw-text-sm tw-font-bold tw-text-white tw-leading-tight">
                        Halal Keeps
                    </p>
                    <p class="tw-text-[11px] tw-font-medium tw-text-green-400 tw-leading-tight">
                        Halal Marketplace
                    </p>
                </div>
            </a>
            <?php endif; ?>

            <!-- Close button — mobile only -->
            <button type="button"
                    id="sidebarCloseBtn"
                    aria-label="Close navigation"
                    class="lg:tw-hidden tw-flex-shrink-0 tw-ml-auto
                           tw-w-8 tw-h-8 tw-rounded-lg
                           tw-flex tw-items-center tw-justify-center
                           tw-text-white/60 hover:tw-text-white
                           hover:tw-bg-white/10
                           tw-transition-colors tw-duration-150">
                <span class="material-symbols-outlined tw-text-xl tw-leading-none"
                      aria-hidden="true">close</span>
            </button>
        </div>

        <!-- Navigation links -->
        <nav class="sidebar-nav tw-flex-1 tw-overflow-y-auto">
            <p class="tw-px-4 tw-pt-2 tw-pb-1.5 tw-text-[10px] tw-font-bold
                      tw-uppercase tw-tracking-[.12em] tw-text-white/35">
                Main Menu
            </p>
            <?php
            $sidebar_nav_items = getSidebarMenu($role_id);
            $child_urls = [];
            foreach ($sidebar_nav_items as $item) {
                if (!empty($item['group'])) {
                    foreach ($item['children'] as $child) {
                        $child_urls[] = basename($child['url']);
                    }
                }
            }
            foreach ($sidebar_nav_items as $item):
                if (!empty($item['group'])):
                    $group_active = false;
                    foreach ($item['children'] as $child) {
                        $child_base = basename($child['url']);
                        if ($child_base !== '' && $child_base !== 'index.php'
                            && strpos($_SERVER['REQUEST_URI'], $child_base) !== false) {
                            $group_active = true; break;
                        }
                        if ($child_base === 'index.php' && $current_page === 'index.php') {
                            $group_active = true; break;
                        }
                    }
                    $group_id = 'navgrp_' . preg_replace('/[^a-z0-9]/i', '_', strtolower($item['label']));
            ?>
            <div class="nav-item nav-group-item">
                <div role="button" tabindex="0"
                     class="nav-link nav-group-toggle <?= $group_active ? 'active' : '' ?>"
                     onclick="toggleNavGroup('<?= $group_id ?>', this)"
                     onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleNavGroup('<?= $group_id ?>',this);}"
                     aria-expanded="<?= $group_active ? 'true' : 'false' ?>">
                    <i class="<?= $item['icon'] ?>" aria-hidden="true"></i>
                    <span><?= $item['label'] ?></span>
                    <i class="fas fa-chevron-right nav-group-chevron <?= $group_active ? 'open' : '' ?>"
                       aria-hidden="true"></i>
                </div>
                <div id="<?= $group_id ?>"
                     class="nav-group-children <?= $group_active ? 'ng-open' : '' ?>">
                    <?php foreach ($item['children'] as $child):
                        $child_base   = basename($child['url']);
                        $child_active = ($child_base !== '' && $child_base !== 'index.php'
                                         && strpos($_SERVER['REQUEST_URI'], $child_base) !== false);
                    ?>
                    <div class="nav-item">
                        <a href="<?= $child['url'] ?>"
                           class="nav-link nav-child-link <?= $child_active ? 'active' : '' ?>">
                            <i class="<?= $child['icon'] ?>" aria-hidden="true"></i>
                            <span><?= $child['label'] ?></span>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php
                else:
                    $item_path    = parse_url($item['url'], PHP_URL_PATH) ?? $item['url'];
                    $item_base    = basename($item_path);
                    $request_path = strtok($_SERVER['REQUEST_URI'], '?');
                    $is_active = ($item_base !== '' && strpos($request_path, $item_base) !== false)
                              || ($item['label'] === 'Dashboard' && $current_page === 'index.php' && isset($is_dashboard))
                              || ($item['label'] === 'Laboratory' && in_array($current_page,
                                     ['laboratory.php','lab_request_submit.php','lab_request_form.php',
                                      'laboratory_payment_status.php','pay_laboratory.php','lab_report_view.php'], true));
                    if ($item_base === 'operations_manager.php' || $item_base === 'chef_dashboard.php') {
                        $link_tab   = parse_url($item['url'], PHP_URL_QUERY) ?? '';
                        parse_str($link_tab, $link_params);
                        $is_active  = (($link_params['tab'] ?? '') === ($_GET['tab'] ?? ''));
                    }
                    $item_base_orig = basename($item['url']);
                    if (in_array($item_base, $child_urls, true) || in_array($item_base_orig, $child_urls, true)) $is_active = false;
            ?>
            <div class="nav-item">
                <a href="<?= $item['url'] ?>"
                   class="nav-link <?= $is_active ? 'active' : '' ?>">
                    <i class="<?= $item['icon'] ?>" aria-hidden="true"></i>
                    <span><?= $item['label'] ?></span>
                </a>
            </div>
            <?php
                endif;
            endforeach;
            unset($sidebar_nav_items, $item, $child);
            ?>

            <p class="tw-px-4 tw-pt-5 tw-pb-1.5 tw-text-[10px] tw-font-bold
                      tw-uppercase tw-tracking-[.12em] tw-text-white/35">
                Account
            </p>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>dashboard/profile.php" class="nav-link">
                    <i class="fas fa-user-cog" aria-hidden="true"></i>
                    <span>My Profile</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="<?= BASE_URL ?>auth/logout.php"
                   class="nav-link"
                   style="color:rgba(239,68,68,.75);">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>

        <!-- Sidebar footer — user card -->
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <?php if (!empty($_SESSION['avatar'])): ?>
                    <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>"
                         alt="<?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?>"
                         class="user-avatar">
                <?php else: ?>
                    <div class="user-avatar-placeholder">
                        <?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
                    </div>
                <?php endif; ?>
                <div class="user-info">
                    <div class="user-name">
                        <?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?>
                    </div>
                    <div class="user-role">
                        <?php
                        if ((int)$role_id === ROLE_BUSINESS_OWNER) {
                            $ws_footer = getStaffWorkspaceLabel();
                            echo htmlspecialchars($ws_footer['label']);
                        } else {
                            echo htmlspecialchars($role_name);
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </aside>
    <!-- /sidebar -->

    <!-- ── Main content column ─────────────────────────────────────────── -->
    <div class="tw-flex-1 tw-flex tw-flex-col tw-min-h-screen tw-min-w-0
                tw-ml-0 lg:tw-ml-64
                tw-transition-[margin-left] tw-duration-300 tw-ease-in-out">

        <!-- ── Sticky top header ─────────────────────────────────────── -->
        <header id="dashboardHeader"
                class="dashboard-header
                       tw-sticky tw-top-0 tw-z-30
                       tw-flex tw-items-center tw-justify-between
                       tw-h-16 tw-px-4 sm:tw-px-6
                       tw-bg-white/95 tw-backdrop-blur-md
                       tw-border-b tw-border-neutral-100
                       tw-shadow-sm">

            <!-- Left: hamburger + page title -->
            <div class="tw-flex tw-items-center tw-gap-3 tw-min-w-0 tw-flex-1">
                <!-- Hamburger — visible only below lg -->
                <button type="button"
                        id="sidebarToggle"
                        aria-label="Open navigation"
                        aria-expanded="false"
                        aria-controls="sidebar"
                        class="lg:tw-hidden tw-flex-shrink-0
                               tw-w-10 tw-h-10 tw-rounded-lg
                               tw-flex tw-items-center tw-justify-center
                               tw-bg-neutral-100 tw-text-neutral-600
                               hover:tw-bg-emerald-50 hover:tw-text-emerald-700
                               tw-transition-colors tw-duration-150
                               tw-border-0 tw-cursor-pointer">
                    <span class="material-symbols-outlined tw-text-xl tw-leading-none"
                          aria-hidden="true">menu</span>
                </button>

                <!-- Desktop collapse toggle -->
                <button type="button"
                        id="sidebarCollapseBtn"
                        aria-label="Toggle sidebar"
                        class="tw-hidden lg:tw-flex tw-flex-shrink-0
                               tw-w-10 tw-h-10 tw-rounded-lg
                               tw-items-center tw-justify-center
                               tw-bg-neutral-100 tw-text-neutral-600
                               hover:tw-bg-emerald-50 hover:tw-text-emerald-700
                               tw-transition-colors tw-duration-150
                               tw-border-0 tw-cursor-pointer">
                    <span class="material-symbols-outlined tw-text-xl tw-leading-none"
                          aria-hidden="true">menu</span>
                </button>

                <!-- Page heading + breadcrumb -->
                <div class="tw-min-w-0">
                    <h2 class="tw-text-base sm:tw-text-lg tw-font-bold
                               tw-text-neutral-800 tw-truncate tw-leading-tight">
                        <?= $page_heading ?? 'Dashboard' ?>
                    </h2>
                    <?php if (isset($breadcrumbs)): ?>
                    <nav aria-label="Breadcrumb"
                         class="tw-flex tw-items-center tw-gap-1
                                tw-text-xs tw-text-neutral-400 tw-mt-0.5
                                tw-hidden xs:tw-flex">
                        <a href="<?= BASE_URL ?>"
                           class="tw-text-emerald-600 hover:tw-underline">Home</a>
                        <?php foreach ($breadcrumbs as $crumb): ?>
                            <span aria-hidden="true">/</span>
                            <?php if (isset($crumb['url'])): ?>
                                <a href="<?= $crumb['url'] ?>"
                                   class="tw-text-emerald-600 hover:tw-underline tw-truncate">
                                    <?= $crumb['label'] ?>
                                </a>
                            <?php else: ?>
                                <span class="tw-truncate"><?= $crumb['label'] ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right: search + notifications + avatar -->
            <div class="tw-flex tw-items-center tw-gap-2 sm:tw-gap-3 tw-flex-shrink-0">

                <!-- Desktop search -->
                <div class="header-search tw-hidden sm:tw-flex" role="search">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="text"
                           placeholder="Search..."
                           id="globalSearch"
                           aria-label="Search"
                           autocomplete="off">
                </div>

                <!-- Notification bell -->
                <button class="header-notification tw-relative
                               tw-w-10 tw-h-10 tw-rounded-xl
                               tw-flex tw-items-center tw-justify-center
                               tw-bg-neutral-100 tw-text-neutral-600
                               hover:tw-bg-emerald-50 hover:tw-text-emerald-700
                               tw-transition-colors tw-duration-150 tw-border-0 tw-cursor-pointer"
                        id="notifToggle"
                        aria-label="Notifications<?= $notif_count > 0 ? " ($notif_count unread)" : '' ?>"
                        aria-haspopup="true"
                        aria-expanded="false">
                    <i class="fas fa-bell tw-text-base" aria-hidden="true"></i>
                    <span class="notif-count
                                 tw-absolute tw-top-1 tw-right-1
                                 tw-min-w-[18px] tw-h-[18px] tw-px-1
                                 tw-rounded-full tw-bg-red-500 tw-text-white
                                 tw-text-[10px] tw-font-bold
                                 tw-flex tw-items-center tw-justify-center"
                          aria-hidden="true"
                          style="<?= $notif_count > 0 ? '' : 'display:none' ?>"><?= $notif_count ?></span>
                </button>

                <!-- Avatar / user dropdown -->
                <div class="dropdown">
                    <?php if (!empty($_SESSION['avatar'])): ?>
                        <img src="<?= htmlspecialchars($_SESSION['avatar']) ?>"
                             alt="<?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?> avatar"
                             class="header-avatar dropdown-trigger
                                    tw-w-9 tw-h-9 sm:tw-w-10 sm:tw-h-10
                                    tw-rounded-xl tw-object-cover tw-cursor-pointer
                                    tw-border-2 tw-border-neutral-200
                                    hover:tw-border-emerald-400
                                    tw-transition-colors tw-duration-150"
                             role="button" tabindex="0"
                             aria-haspopup="true" aria-expanded="false"
                             aria-label="User menu">
                    <?php else: ?>
                        <div class="header-avatar dropdown-trigger
                                    tw-w-9 tw-h-9 sm:tw-w-10 sm:tw-h-10
                                    tw-rounded-xl tw-cursor-pointer tw-select-none
                                    tw-flex tw-items-center tw-justify-center
                                    tw-bg-emerald-100 tw-text-emerald-700
                                    tw-font-bold tw-text-sm sm:tw-text-base
                                    tw-border-2 tw-border-neutral-200
                                    hover:tw-border-emerald-400
                                    tw-transition-colors tw-duration-150"
                             role="button" tabindex="0"
                             aria-haspopup="true" aria-expanded="false"
                             aria-label="User menu">
                            <?= strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)) ?>
                        </div>
                    <?php endif; ?>

                    <div class="dropdown-menu" role="menu">
                        <a href="<?= BASE_URL ?>dashboard/profile.php" role="menuitem">
                            <i class="fas fa-user" aria-hidden="true"></i> My Profile
                        </a>
                        <a href="<?= getDashboardUrl($role_id) ?>" role="menuitem">
                            <i class="fas fa-tachometer-alt" aria-hidden="true"></i> Dashboard
                        </a>
                        <div class="divider" role="separator"></div>
                        <a href="<?= BASE_URL ?>auth/logout.php"
                           role="menuitem"
                           style="color:var(--danger);">
                            <i class="fas fa-sign-out-alt" aria-hidden="true"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>
        <!-- /header -->

        <!-- ── Notifications slide-in panel ──────────────────────────── -->
        <div class="notification-panel" id="notifPanel" role="dialog" aria-label="Notifications">
            <div class="panel-header">
                <h3>Notifications</h3>
                <button class="btn-icon close-notif-panel"
                        aria-label="Close notifications"
                        style="background:var(--neutral-100);border:none;cursor:pointer;
                               width:32px;height:32px;border-radius:8px;">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div class="panel-body" id="notifList">
                <div class="empty-state" style="padding:30px 10px">
                    <div class="empty-icon" style="width:50px;height:50px;font-size:1.2rem">
                        <i class="fas fa-bell-slash" aria-hidden="true"></i>
                    </div>
                    <p>No new notifications</p>
                </div>
            </div>
        </div>

        <!-- ── Page content ───────────────────────────────────────────── -->
        <div class="dashboard-content">
            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="alert alert-<?= $_SESSION['flash_type'] ?? 'info' ?>">
                    <i class="fas fa-<?= $_SESSION['flash_type'] === 'success'
                        ? 'check-circle' : ($_SESSION['flash_type'] === 'danger'
                        ? 'exclamation-circle' : 'info-circle') ?>"
                       aria-hidden="true"></i>
                    <span><?= htmlspecialchars($_SESSION['flash_message']) ?></span>
                    <button class="close-alert" aria-label="Dismiss">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
                <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
            <?php endif; ?>
<?php else: ?>
<!-- Public Page Layout -->
<?php endif; ?>
