<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/admin_functions.php';

requireAdmin();
enforceSessionTimeout();
$admin_page_title = 'Revenue & Billing Settings';
$keys = [
    'billing_hacera_report_fee' => ['default'=>'150.00','type'=>'money','label'=>'HaCeRa report PDF price (PHP)'],
    'billing_pnl_export_fee' => ['default'=>'150.00','type'=>'money','label'=>'P&L export price (PHP)'],
    'billing_inventory_pack_fee' => ['default'=>'199.00','type'=>'money','label'=>'Inventory expansion price (PHP)'],
    'billing_inventory_pack_slots' => ['default'=>'50','type'=>'integer','label'=>'Expansion slots per pack'],
    'billing_inventory_pack_days' => ['default'=>'90','type'=>'integer','label'=>'Expansion validity (days)'],
    'billing_inventory_base_slots' => ['default'=>'100','type'=>'integer','label'=>'Included inventory slots'],
    'billing_platform_fee_enabled' => ['default'=>'0','type'=>'boolean','label'=>'Enable marketplace platform fee'],
    'billing_platform_fee_rate' => ['default'=>'0.000000','type'=>'rate','label'=>'Marketplace platform fee rate'],
    'billing_platform_fee_label' => ['default'=>'Halal Keeps platform fee','type'=>'text','label'=>'Customer-facing platform fee label'],
];
if (empty($_SESSION['billing_settings_csrf'])) $_SESSION['billing_settings_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['billing_settings_csrf'];
$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session token is invalid. Refresh the page and try again.';
    } else {
        $values = [];
        foreach (['billing_hacera_report_fee','billing_pnl_export_fee','billing_inventory_pack_fee'] as $key) {
            $raw = trim((string)($_POST[$key] ?? ''));
            if (!is_numeric($raw) || (float)$raw < 0) { $error = 'Each price must be a valid non-negative PHP amount.'; break; }
            $values[$key] = number_format((float)$raw, 2, '.', '');
        }
        foreach (['billing_inventory_pack_slots','billing_inventory_pack_days','billing_inventory_base_slots'] as $key) {
            $raw = trim((string)($_POST[$key] ?? ''));
            if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int)$raw < 1) { $error = 'Storage limits and validity must be whole numbers of at least 1.'; break; }
            $values[$key] = (string)(int)$raw;
        }
        $enabled = isset($_POST['billing_platform_fee_enabled']);
        $percent = trim((string)($_POST['billing_platform_fee_rate_percent'] ?? ''));
        if ($error === '' && $enabled && (!is_numeric($percent) || (float)$percent < 1.14 || (float)$percent > 1.20)) {
            $error = 'The enabled marketplace platform fee must be between 1.14% and 1.20%.';
        }
        if ($error === '') {
            $values['billing_platform_fee_enabled'] = $enabled ? '1' : '0';
            $values['billing_platform_fee_rate'] = $enabled ? number_format((float)$percent / 100, 6, '.', '') : '0.000000';
            $values['billing_platform_fee_label'] = sanitize($_POST['billing_platform_fee_label'] ?? '') ?: 'Halal Keeps platform fee';
            foreach ($values as $key => $value) {
                $description = $keys[$key]['label']; $type = $keys[$key]['type'];
                $stmt=$conn->prepare('INSERT INTO system_settings (setting_key,setting_value,setting_type,description) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
                $stmt->bind_param('ssss',$key,$value,$type,$description); $stmt->execute(); $stmt->close();
            }
            logAdminActivity($conn, (int)$_SESSION['user_id'], 'Updated Revenue & Billing Settings', 'billing');
            $success = 'Revenue and billing settings saved.';
        }
    }
}

$settings = array_column($keys, 'default');
$result=$conn->query("SELECT setting_key,setting_value FROM system_settings WHERE setting_key LIKE 'billing_%'");
if ($result) while ($row=$result->fetch_assoc()) if (array_key_exists($row['setting_key'],$settings)) $settings[$row['setting_key']] = (string)$row['setting_value'];
$enabled = $settings['billing_platform_fee_enabled'] === '1';
$percent = $enabled ? number_format((float)$settings['billing_platform_fee_rate'] * 100, 2, '.', '') : '1.14';
require_once __DIR__ . '/includes/admin_header.php';
?>
<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i><span><?= htmlspecialchars($success) ?></span></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i><span><?= htmlspecialchars($error) ?></span></div><?php endif; ?>
<div class="admin-card" style="max-width:760px">
  <div class="admin-card-header"><h3><i class="fas fa-file-invoice-dollar" style="color:#6366f1;margin-right:8px"></i>Revenue &amp; Billing</h3></div>
  <div class="admin-card-body">
    <p class="form-text">These server-side values apply to future checkouts. The marketplace fee is disabled by default and cannot be enabled outside the approved 1.14%–1.20% range.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <h4>Paid reports and storage</h4>
      <?php foreach (['billing_hacera_report_fee','billing_pnl_export_fee','billing_inventory_pack_fee'] as $key): ?>
        <div class="form-group"><label><?= htmlspecialchars($keys[$key]['label']) ?></label><input class="form-control" type="number" min="0" step="0.01" name="<?= $key ?>" value="<?= htmlspecialchars($settings[$key]) ?>"></div>
      <?php endforeach; ?>
      <?php foreach (['billing_inventory_pack_slots','billing_inventory_pack_days','billing_inventory_base_slots'] as $key): ?>
        <div class="form-group"><label><?= htmlspecialchars($keys[$key]['label']) ?></label><input class="form-control" type="number" min="1" step="1" name="<?= $key ?>" value="<?= htmlspecialchars($settings[$key]) ?>"></div>
      <?php endforeach; ?>
      <hr>
      <h4>Marketplace order fee</h4>
      <div class="form-group"><label><input type="checkbox" name="billing_platform_fee_enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Enable platform fee on eligible online order payments</label></div>
      <div class="form-group"><label>Platform fee percentage</label><input class="form-control" type="number" min="1.14" max="1.20" step="0.01" name="billing_platform_fee_rate_percent" value="<?= htmlspecialchars($percent) ?>"><div class="form-text">Applied only while enabled. Accepted range: 1.14%–1.20%.</div></div>
      <div class="form-group"><label>Fee label shown to customers</label><input class="form-control" type="text" maxlength="120" name="billing_platform_fee_label" value="<?= htmlspecialchars($settings['billing_platform_fee_label']) ?>"></div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Billing Settings</button>
    </form>
  </div>
</div>
<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>