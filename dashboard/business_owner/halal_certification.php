<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

$page_title   = 'Halal Certification';
$page_heading = 'Halal Certification';
$is_dashboard = true;
$breadcrumbs  = [
    ['label' => 'Owner Dashboard', 'url' => BASE_URL . 'dashboard/business_owner/owner_dashboard.php'],
    ['label' => 'Halal Certification'],
];

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    .coming-soon-wrapper {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 340px;
        text-align: center;
        padding: 48px 24px 32px;
    }

    .coming-soon-icon-ring {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-100), var(--primary-50));
        border: 2px solid var(--primary-200);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 24px;
        animation: pulse-ring 2.4s ease-in-out infinite;
    }

    .coming-soon-icon-ring i {
        font-size: 2rem;
        color: var(--primary-600);
    }

    @keyframes pulse-ring {
        0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,.18); }
        50%       { box-shadow: 0 0 0 12px rgba(16,185,129,0); }
    }

    .coming-soon-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--accent-50);
        color: var(--accent-600);
        border: 1px solid var(--accent-200);
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: .07em;
        text-transform: uppercase;
        padding: 4px 14px;
        margin-bottom: 16px;
    }

    .coming-soon-title {
        font-size: 1.75rem;
        font-weight: 800;
        color: var(--neutral-800);
        margin: 0 0 14px;
        font-family: 'Outfit', sans-serif;
    }

    .coming-soon-desc {
        max-width: 560px;
        color: var(--neutral-500);
        font-size: 0.95rem;
        line-height: 1.7;
        margin: 0 auto 28px;
    }

    .coming-soon-actions {
        display: flex;
        gap: 12px;
        justify-content: center;
        flex-wrap: wrap;
    }

    /* Feature cards */
    .features-heading {
        font-size: 1.15rem;
        font-weight: 700;
        color: var(--neutral-700);
        margin-bottom: 6px;
        font-family: 'Outfit', sans-serif;
    }

    .features-subheading {
        color: var(--neutral-400);
        font-size: 0.85rem;
        margin-bottom: 24px;
    }

    .feature-cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        width: 100%;
    }

    .feature-card {
        background: var(--neutral-50);
        border: 1px solid var(--neutral-100);
        border-radius: var(--radius-lg);
        padding: 24px 20px;
        text-align: left;
        transition: box-shadow .18s, border-color .18s, transform .18s;
    }

    .feature-card:hover {
        border-color: var(--primary-200);
        box-shadow: 0 4px 16px rgba(16,185,129,.10);
        transform: translateY(-2px);
    }

    .feature-card-icon {
        width: 44px;
        height: 44px;
        border-radius: var(--radius-md);
        background: var(--primary-50);
        border: 1px solid var(--primary-100);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 14px;
    }

    .feature-card-icon i {
        font-size: 1.15rem;
        color: var(--primary-600);
    }

    .feature-card-title {
        font-size: 0.92rem;
        font-weight: 700;
        color: var(--neutral-800);
        margin-bottom: 6px;
    }

    .feature-card-desc {
        font-size: 0.82rem;
        color: var(--neutral-500);
        line-height: 1.6;
    }
</style>

<!-- Coming Soon Card -->
<div class="card" style="margin-bottom: 28px;">
    <div class="coming-soon-wrapper">
        <div class="coming-soon-icon-ring">
            <i class="fas fa-hourglass-half"></i>
        </div>

        <div class="coming-soon-badge">
            <i class="fas fa-clock" style="font-size:.65rem;"></i>
            Coming Soon
        </div>

        <h2 class="coming-soon-title">Digital Halal Certification is on its way.</h2>

        <p class="coming-soon-desc">
            We are currently refining the digital application and verification process to provide a seamless experience for your business.
            Stay tuned for tools that will help you manage audits, renew certifications, and track compliance with ease.
        </p>

        <div class="coming-soon-actions">
            <button class="btn btn-primary" onclick="notifyMe(this)">
                <i class="fas fa-bell"></i> Notify Me
            </button>
            <a href="<?= BASE_URL ?>dashboard/business_owner/owner_dashboard.php" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</div>

<!-- Features Preview -->
<div style="text-align:center; margin-bottom: 20px;">
    <div class="features-heading">Features to Expect</div>
    <div class="features-subheading">Everything you need to maintain integrity, simplified.</div>
</div>

<div class="feature-cards-grid" style="margin-bottom: 32px;">
    <div class="feature-card">
        <div class="feature-card-icon">
            <i class="fas fa-history"></i>
        </div>
        <div class="feature-card-title">Digital Audit Logs</div>
        <div class="feature-card-desc">
            Access your complete history of inspections and auditor notes instantly in one secure place.
        </div>
    </div>

    <div class="feature-card">
        <div class="feature-card-icon">
            <i class="fas fa-sync-alt"></i>
        </div>
        <div class="feature-card-title">Automated Renewals</div>
        <div class="feature-card-desc">
            Never miss a deadline. Get automated reminders and submit renewal documentation with a single click.
        </div>
    </div>

    <div class="feature-card">
        <div class="feature-card-icon">
            <i class="fas fa-tasks"></i>
        </div>
        <div class="feature-card-title">Compliance Tracking</div>
        <div class="feature-card-desc">
            Monitor your real-time compliance status against global Halal standards and local regulations.
        </div>
    </div>
</div>

<script>
function notifyMe(btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-check"></i> You\'re on the list!';
    btn.style.background = 'var(--primary-700)';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
