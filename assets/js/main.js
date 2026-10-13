/**
 * Halal Certifying Body System - Main JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all modules
    initNavbar();
    initAlerts();
    initModals();
    initNotifications();
    initDropdowns();
    initFileUploads();
    initSidebar();
    initMobileSearch();
    initNavGroups();
    initTooltips();
});

// =====================================================
// Navbar Scroll Effect
// =====================================================
function initNavbar() {
    const navbar = document.querySelector('.navbar');
    if (!navbar) return;
    
    window.addEventListener('scroll', () => {
        if (window.scrollY > 50) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
    });
    
    // Mobile toggle
    const toggle = document.querySelector('.navbar-toggle');
    const navLinks = document.querySelector('.navbar-nav');
    if (toggle && navLinks) {
        toggle.addEventListener('click', () => {
            navLinks.classList.toggle('show');
        });
    }
}

// =====================================================
// Alerts
// =====================================================
function initAlerts() {
    document.querySelectorAll('.close-alert').forEach(btn => {
        btn.addEventListener('click', function() {
            const alert = this.closest('.alert');
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 300);
        });
    });
    
    // Auto-dismiss success alerts
    document.querySelectorAll('.alert-success').forEach(alert => {
        setTimeout(() => {
            if (alert) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(() => alert.remove(), 300);
            }
        }, 5000);
    });
}

// =====================================================
// Modals
// =====================================================
function initModals() {
    // Open modal buttons
    document.querySelectorAll('[data-modal]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const modalId = this.getAttribute('data-modal');
            openModal(modalId);
        });
    });
    
    // Close modal buttons
    document.querySelectorAll('.modal-close, [data-close-modal]').forEach(btn => {
        btn.addEventListener('click', function() {
            closeModal();
        });
    });
    
    // Close modal on overlay click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
    });
}

function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal() {
    document.querySelectorAll('.modal-overlay.active').forEach(modal => {
        modal.classList.remove('active');
    });
    document.body.style.overflow = '';
}

// =====================================================
// Notifications Panel
// =====================================================
function initNotifications() {
    const notifBtn = document.getElementById('notifToggle') || document.querySelector('.header-notification');
    const notifPanel = document.querySelector('.notification-panel');
    const closePanel = document.querySelector('.close-notif-panel');

    if (notifBtn && notifPanel) {
        notifBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            const isOpening = !notifPanel.classList.contains('open');
            notifPanel.classList.toggle('open');
            notifBtn.setAttribute('aria-expanded', String(isOpening));
            if (isOpening) loadNotifications();
        });

        if (closePanel) {
            closePanel.addEventListener('click', () => {
                notifPanel.classList.remove('open');
                notifBtn.setAttribute('aria-expanded', 'false');
            });
        }

        document.addEventListener('click', function(e) {
            if (!notifPanel.contains(e.target) && !notifBtn.contains(e.target)) {
                notifPanel.classList.remove('open');
                notifBtn.setAttribute('aria-expanded', 'false');
            }
        });

        // Escape key closes the panel
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && notifPanel.classList.contains('open')) {
                notifPanel.classList.remove('open');
                notifBtn.setAttribute('aria-expanded', 'false');
                notifBtn.focus();
            }
        });
    }
}

function loadNotifications() {
    const list = document.getElementById('notifList');
    if (!list) return;

    fetch(BASE_URL + 'api/notifications.php?action=get&limit=15')
        .then(r => r.json())
        .then(data => {
            if (!data.success || !data.notifications.length) {
                list.innerHTML = `<div class="empty-state" style="padding:30px 10px">
                    <div class="empty-icon" style="width:50px;height:50px;font-size:1.2rem"><i class="fas fa-bell-slash"></i></div>
                    <p>No new notifications</p>
                </div>`;
                return;
            }

            // Update badge
            const badge = document.querySelector('.notif-count');
            if (badge) {
                if (data.unread_count > 0) {
                    badge.textContent = data.unread_count;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }
            }

            const typeIcon = { success: 'check-circle', warning: 'exclamation-triangle', action_required: 'exclamation-circle', info: 'info-circle' };
            list.innerHTML = data.notifications.map(n => `
                <div class="notification-item ${n.is_read == 0 ? 'unread' : ''}" data-id="${n.id}" onclick="handleNotifClick(this, '${n.link || ''}')">
                    <div class="notif-icon"><i class="fas fa-${typeIcon[n.type] || 'bell'}"></i></div>
                    <div class="notif-content">
                        <div class="notif-title">${n.title}</div>
                        <div class="notif-message">${n.message}</div>
                        <div class="notif-time" style="font-size:0.75rem;color:var(--neutral-400);margin-top:2px">${n.created_at}</div>
                    </div>
                </div>`).join('');
        })
        .catch(() => {});
}

function handleNotifClick(el, link) {
    el.classList.remove('unread');
    const id = el.getAttribute('data-id');
    if (id) markNotificationRead(id);
    if (link) window.location.href = link;
}

function markNotificationRead(id) {
    const formData = new FormData();
    formData.append('action', 'mark_read');
    formData.append('notification_id', id);
    fetch(BASE_URL + 'api/notifications.php', {
        method: 'POST',
        body: formData
    }).catch(err => console.error('Error:', err));
}

// =====================================================
// Dropdowns
// =====================================================
function initDropdowns() {
    document.querySelectorAll('.dropdown').forEach(dropdown => {
        const trigger = dropdown.querySelector('.dropdown-trigger');
        const menu    = dropdown.querySelector('.dropdown-menu');

        if (trigger && menu) {
            trigger.addEventListener('click', function(e) {
                e.stopPropagation();
                // Close all other dropdowns first
                document.querySelectorAll('.dropdown-menu.show').forEach(m => {
                    if (m !== menu) {
                        m.classList.remove('show');
                        const otherTrigger = m.closest('.dropdown')?.querySelector('.dropdown-trigger');
                        if (otherTrigger) otherTrigger.setAttribute('aria-expanded', 'false');
                    }
                });
                const opening = !menu.classList.contains('show');
                menu.classList.toggle('show');
                trigger.setAttribute('aria-expanded', String(opening));
            });

            // Keyboard: open on Enter/Space, close on Escape
            trigger.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    trigger.click();
                } else if (e.key === 'Escape') {
                    menu.classList.remove('show');
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.focus();
                }
            });
        }
    });

    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
            menu.classList.remove('show');
            const trigger = menu.closest('.dropdown')?.querySelector('.dropdown-trigger');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    });
}

// =====================================================
// File Uploads
// =====================================================
function initFileUploads() {
    document.querySelectorAll('.file-upload').forEach(upload => {
        const input = upload.querySelector('input[type="file"]');
        const label = upload.querySelector('.file-label');
        
        if (input) {
            input.addEventListener('change', function() {
                if (this.files.length > 0) {
                    const names = Array.from(this.files).map(f => f.name).join(', ');
                    if (label) label.textContent = names;
                    upload.style.borderColor = 'var(--primary-400)';
                    upload.style.background = 'var(--primary-50)';
                }
            });
        }
    });
}

// =====================================================
// Sidebar Toggle — Desktop collapse + Mobile overlay

// =====================================================
// Sidebar Toggle -- Tailwind off-canvas drawer
// Mobile  (<1024px): slide-in overlay drawer
// Desktop (>=1024px): static persistent sidebar
//   + optional icon-collapse via sidebar-collapsed class
// =====================================================
function initSidebar() {
    const sidebar     = document.getElementById('sidebar');
    const overlay     = document.getElementById('sidebarOverlay');
    const hamburger   = document.getElementById('sidebarToggle');      // mobile burger
    const collapseBtn = document.getElementById('sidebarCollapseBtn'); // desktop collapse
    const closeBtn    = document.getElementById('sidebarCloseBtn');    // x inside drawer
    const layout      = document.querySelector('.dashboard-layout') || document.querySelector('.tw-flex') || document.body;

    const LG = 1024; // must match Tailwind lg breakpoint
    const isDesktop = () => window.innerWidth >= LG;

    if (!sidebar) return;

    // ---- Helpers -------------------------------------------------------
    function openMobileSidebar() {
        sidebar.classList.remove('tw--translate-x-full');
        sidebar.classList.add('tw-translate-x-0', 'tw-shadow-2xl');
        if (overlay) {
            overlay.classList.remove('tw-opacity-0', 'tw-pointer-events-none');
            overlay.classList.add('tw-opacity-100', 'tw-pointer-events-auto');
            overlay.setAttribute('aria-hidden', 'false');
        }
        document.body.style.overflow = 'hidden';
        if (hamburger) hamburger.setAttribute('aria-expanded', 'true');
        sidebar.setAttribute('aria-hidden', 'false');
        const firstLink = sidebar.querySelector('a[href], button');
        if (firstLink) firstLink.focus();
    }

    function closeMobileSidebar() {
        sidebar.classList.add('tw--translate-x-full');
        sidebar.classList.remove('tw-translate-x-0', 'tw-shadow-2xl');
        if (overlay) {
            overlay.classList.add('tw-opacity-0', 'tw-pointer-events-none');
            overlay.classList.remove('tw-opacity-100', 'tw-pointer-events-auto');
            overlay.setAttribute('aria-hidden', 'true');
        }
        document.body.style.overflow = '';
        if (hamburger) {
            hamburger.setAttribute('aria-expanded', 'false');
            hamburger.focus();
        }
        sidebar.setAttribute('aria-hidden', 'true');
    }

    function collapseDesktopSidebar() {
        layout.classList.add('sidebar-collapsed');
        if (collapseBtn) collapseBtn.setAttribute('aria-expanded', 'false');
        localStorage.setItem('sidebar_collapsed', 'true');
    }

    function expandDesktopSidebar() {
        layout.classList.remove('sidebar-collapsed');
        if (collapseBtn) collapseBtn.setAttribute('aria-expanded', 'true');
        localStorage.setItem('sidebar_collapsed', 'false');
    }

    // ---- Boot state ----------------------------------------------------
    if (isDesktop()) {
        sidebar.setAttribute('aria-hidden', 'false');
        if (localStorage.getItem('sidebar_collapsed') === 'true') {
            layout.classList.add('sidebar-collapsed');
            if (collapseBtn) collapseBtn.setAttribute('aria-expanded', 'false');
        }
    } else {
        sidebar.setAttribute('aria-hidden', 'true');
    }

    // data-label for collapsed icon tooltips
    sidebar.querySelectorAll('.nav-link:not(.nav-group-toggle)').forEach(link => {
        const span = link.querySelector('span');
        if (span) link.setAttribute('data-label', span.textContent.trim());
    });

    // ---- Hamburger opens drawer (mobile) --------------------------------
    if (hamburger) {
        hamburger.addEventListener('click', e => {
            e.stopPropagation();
            openMobileSidebar();
        });
    }

    // ---- Desktop collapse/expand toggle ---------------------------------
    if (collapseBtn) {
        collapseBtn.addEventListener('click', () => {
            layout.classList.contains('sidebar-collapsed')
                ? expandDesktopSidebar()
                : collapseDesktopSidebar();
        });
    }

    // ---- Close button inside drawer -------------------------------------
    if (closeBtn) {
        closeBtn.addEventListener('click', closeMobileSidebar);
    }

    // ---- Backdrop click closes drawer -----------------------------------
    if (overlay) {
        overlay.addEventListener('click', closeMobileSidebar);
    }

    // ---- Escape key closes drawer ---------------------------------------
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (!isDesktop() && !sidebar.classList.contains('tw--translate-x-full')) {
            closeMobileSidebar();
        }
    });

    // ---- Nav link click closes mobile drawer ----------------------------
    sidebar.querySelectorAll('.nav-link').forEach(link => {
        if (link.classList.contains('nav-group-toggle')) return;
        link.addEventListener('click', () => {
            if (!isDesktop()) closeMobileSidebar();
        });
    });

    // ---- Resize: debounced cleanup --------------------------------------
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (isDesktop()) {
                if (overlay) {
                    overlay.classList.add('tw-opacity-0', 'tw-pointer-events-none');
                    overlay.classList.remove('tw-opacity-100', 'tw-pointer-events-auto');
                    overlay.setAttribute('aria-hidden', 'true');
                }
                document.body.style.overflow = '';
                sidebar.setAttribute('aria-hidden', 'false');
                sidebar.classList.remove('tw-shadow-2xl');
                if (localStorage.getItem('sidebar_collapsed') === 'true') {
                    layout.classList.add('sidebar-collapsed');
                }
            } else {
                layout.classList.remove('sidebar-collapsed');
                if (!sidebar.classList.contains('tw-translate-x-0')) {
                    sidebar.setAttribute('aria-hidden', 'true');
                }
            }
        }, 100);
    });
}

// Global helpers for onclick attributes in header.php
function openSidebar()  { document.getElementById('sidebarToggle')?.click(); }
function closeSidebar() { document.getElementById('sidebarCloseBtn')?.click(); }

// =====================================================
// Mobile Search Bar Toggle
// =====================================================
function initMobileSearch() {
    const toggleBtn  = document.getElementById('mobileSearchToggle');
    const closeBtn   = document.getElementById('mobileSearchClose');
    const searchBar  = document.getElementById('mobileSearchBar');
    const mobileInput = document.getElementById('mobileSearchInput');
    const desktopInput = document.getElementById('globalSearch');

    if (!toggleBtn || !searchBar) return;

    function openSearch() {
        searchBar.classList.add('open');
        searchBar.setAttribute('aria-hidden', 'false');
        toggleBtn.setAttribute('aria-expanded', 'true');
        // Sync any existing desktop search value
        if (desktopInput && mobileInput) {
            mobileInput.value = desktopInput.value;
        }
        if (mobileInput) {
            mobileInput.focus();
        }
    }

    function closeSearch() {
        searchBar.classList.remove('open');
        searchBar.setAttribute('aria-hidden', 'true');
        toggleBtn.setAttribute('aria-expanded', 'false');
        toggleBtn.focus(); // return focus
    }

    toggleBtn.addEventListener('click', () => {
        searchBar.classList.contains('open') ? closeSearch() : openSearch();
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', closeSearch);
    }

    // Sync mobile input back to the hidden desktop input so any
    // existing search listeners keep working
    if (mobileInput && desktopInput) {
        mobileInput.addEventListener('input', () => {
            desktopInput.value = mobileInput.value;
            // Fire an input event so downstream listeners pick it up
            desktopInput.dispatchEvent(new Event('input', { bubbles: true }));
        });
        mobileInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                desktopInput.dispatchEvent(new Event('change', { bubbles: true }));
                desktopInput.form?.submit();
            }
        });
    }

    // Escape key closes the search bar
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && searchBar.classList.contains('open')) {
            closeSearch();
        }
    });
}

// =====================================================
// Tooltips
// =====================================================
function initTooltips() {
    document.querySelectorAll('[data-tooltip]').forEach(el => {
        el.addEventListener('mouseenter', function() {
            const text = this.getAttribute('data-tooltip');
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = text;
            document.body.appendChild(tooltip);
            
            const rect = this.getBoundingClientRect();
            tooltip.style.top = (rect.top - tooltip.offsetHeight - 8) + 'px';
            tooltip.style.left = (rect.left + rect.width / 2 - tooltip.offsetWidth / 2) + 'px';
            tooltip.style.opacity = '1';
            
            this._tooltip = tooltip;
        });
        
        el.addEventListener('mouseleave', function() {
            if (this._tooltip) {
                this._tooltip.remove();
                this._tooltip = null;
            }
        });
    });
}

// =====================================================
// Toast Notifications
// =====================================================
function showToast(message, type = 'success', duration = 4000) {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    
    const icons = {
        success: 'fas fa-check-circle',
        error: 'fas fa-times-circle',
        warning: 'fas fa-exclamation-triangle',
        info: 'fas fa-info-circle'
    };
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <i class="${icons[type] || icons.info}"></i>
        <span>${message}</span>
        <button onclick="this.parentElement.remove()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:0.6">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// =====================================================
// Form Validation
// =====================================================
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return false;
    
    let isValid = true;
    const requiredFields = form.querySelectorAll('[required]');
    
    requiredFields.forEach(field => {
        removeFieldError(field);
        
        if (!field.value.trim()) {
            showFieldError(field, 'This field is required');
            isValid = false;
        } else if (field.type === 'email' && !isValidEmail(field.value)) {
            showFieldError(field, 'Please enter a valid email');
            isValid = false;
        }
    });
    
    return isValid;
}

function showFieldError(field, message) {
    field.style.borderColor = 'var(--danger)';
    const error = document.createElement('div');
    error.className = 'field-error';
    error.style.color = 'var(--danger)';
    error.style.fontSize = '0.8rem';
    error.style.marginTop = '4px';
    error.textContent = message;
    field.parentElement.appendChild(error);
}

function removeFieldError(field) {
    field.style.borderColor = '';
    const error = field.parentElement.querySelector('.field-error');
    if (error) error.remove();
}

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

// =====================================================
// AJAX Helper
// =====================================================
async function apiCall(url, method = 'GET', data = null) {
    const options = {
        method: method,
        headers: { 'Content-Type': 'application/json' },
    };
    
    if (data && method !== 'GET') {
        options.body = JSON.stringify(data);
    }
    
    try {
        const response = await fetch(url, options);
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        showToast('An error occurred. Please try again.', 'error');
        return null;
    }
}

// =====================================================
// Confirm Dialog
// =====================================================
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

// =====================================================
// Role Card Selection
// =====================================================
function initRoleSelection() {
    document.querySelectorAll('.role-card').forEach(card => {
        card.addEventListener('click', function() {
            document.querySelectorAll('.role-card').forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
            const roleId = this.getAttribute('data-role-id');
            const roleInput = document.getElementById('selected_role');
            if (roleInput) roleInput.value = roleId;
            
            // Show/hide role-specific fields
            showRoleFields(roleId);
        });
    });
}

function showRoleFields(roleId) {
    document.querySelectorAll('.role-fields').forEach(f => f.style.display = 'none');
    const fields = document.getElementById('role-fields-' + roleId);
    if (fields) fields.style.display = 'block';
}

// =====================================================
// Collapsible Sidebar Nav Groups
// =====================================================
function initNavGroups() {
    // Nothing to do — state is set by PHP inline styles and toggled by CSS class.
}

function toggleNavGroup(groupId, toggleEl) {
    var children = document.getElementById(groupId);
    if (!children) return;

    var opening = !children.classList.contains('ng-open');
    children.classList.toggle('ng-open');

    var chevron = toggleEl.querySelector('.nav-group-chevron');
    if (chevron) chevron.classList.toggle('open', opening);
    toggleEl.setAttribute('aria-expanded', String(opening));
    toggleEl.classList.toggle('active', opening);
}

// =====================================================
// Global BASE_URL
// Primary source: window.BASE_URL injected by includes/footer.php via PHP.
// Fallback: derive it from the current page URL so the subfolder (e.g.
// /halal_final/) is preserved on localhost and any nested deploy path.
// Strategy — walk up from the current script's directory until we reach the
// known top-level folders of this app (auth, dashboard, admin, assets, api).
const BASE_URL = (function () {
    if (typeof window.BASE_URL !== 'undefined' && window.BASE_URL !== '') {
        return window.BASE_URL;
    }

    // Split the pathname into segments and find the app root by looking for
    // the first segment that matches a known top-level app directory.
    const topLevel = new Set(['auth', 'dashboard', 'admin', 'assets', 'api', 'config', 'includes', 'uploads']);
    const parts    = window.location.pathname.replace(/\\/g, '/').split('/').filter(Boolean);
    const rootParts = [];

    for (const part of parts) {
        if (topLevel.has(part.toLowerCase())) break;
        // Stop if it looks like a .php file
        if (part.includes('.')) break;
        rootParts.push(part);
    }

    const subpath = rootParts.length ? '/' + rootParts.join('/') + '/' : '/';
    return window.location.origin + subpath;
}());
