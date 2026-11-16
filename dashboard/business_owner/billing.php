<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/RevenuePaymentService.php';
requireLogin(); requireRole([ROLE_BUSINESS_OWNER]);
$staffKey=$_SESSION['staff_role_key']??null;
if ($staffKey !== null && $staffKey !== STAFF_ROLE_OPS_MANAGER) { header('Location: '.getDashboardUrl(ROLE_BUSINESS_OWNER)); exit; }
$page_title='Reports & Billing'; $page_heading='Reports & Billing'; $is_dashboard=true; $breadcrumbs=[['label'=>'Reports & Billing']];
$userId=(int)$_SESSION['user_id']; $restaurantId=getSessionRestaurantId($conn); $billing=new RevenuePaymentService($conn); $schemaError=$billing->schemaError(); $paymentReturn=(string)($_GET['payment']??''); $pnlReconciliation=(!$schemaError && $paymentReturn==='return') ? $billing->reconcilePendingPnlExports($userId,$restaurantId) : null; $storageReconciliation=(!$schemaError && $paymentReturn==='return') ? $billing->reconcilePendingStoragePacks($userId,$restaurantId) : null; $settings=$billing->billingSettings(); $capacity=$billing->getInventoryCapacity($restaurantId); $purchases=$schemaError?[]:$billing->recentPurchases($restaurantId,$userId); $paidPnlPurchases=array_values(array_filter($purchases, static fn(array $p): bool => ($p['service_type']??'')==='pnl_export' && ($p['status']??'')==='paid' && ($p['pnl_delivery_status']??'')==='ready' && (int)($p['pnl_purchase_id']??0)>0)); $csrf=RevenuePaymentService::ensureCsrfToken(); $isOwner=$staffKey===null;
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="card" style="max-width:1050px;margin:0 auto;border-radius:16px;overflow:hidden">
  <div style="padding:24px;background:linear-gradient(135deg,#064e3b,#047857);color:#fff">
    <h2 style="margin:0 0 6px"><i class="fas fa-file-invoice-dollar"></i> Reports &amp; Billing</h2>
    <p style="margin:0;opacity:.86">Pay only for exports and capacity when needed. Payment redirects never unlock a feature; verified payment is required.</p>
  </div>
  <div style="padding:24px">
    <?php if ($schemaError): ?><div class="alert alert-warning"><?= htmlspecialchars($schemaError) ?></div><?php endif; ?>
    <div id="billingMessage" class="alert" style="display:none"></div>
    <?php if ($isOwner): ?>
    <section style="border:1px solid #d1fae5;border-radius:13px;padding:20px;margin-bottom:20px;background:#f0fdf4">
      <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap"><div><h3 style="margin:0">HaCeRa Inspection Readiness Portfolio</h3><p style="margin:5px 0 0;color:#475569">Snapshot-based PDF report. Re-downloads of the same verified snapshot are free.</p></div><button class="btn btn-primary" onclick="startBilling('start_hacera_report')" <?= $schemaError?'disabled':'' ?>><i class="fas fa-file-pdf"></i> Purchase / Download · ₱<?= number_format($settings['hacera_fee'],2) ?></button></div>
    </section>
    <?php endif; ?>
    <section style="border:1px solid #dbeafe;border-radius:13px;padding:20px;margin-bottom:20px">
      <h3 style="margin:0 0 6px">P&amp;L Export</h3><p style="margin:0 0 14px;color:#475569">A management estimate based on paid orders, approved inflows, and recorded expenses. Platform fees are excluded from restaurant revenue.</p>
      <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap"><label style="display:grid;gap:4px">From <input id="pnlStart" type="date" class="form-control" value="<?= date('Y-m-01') ?>"></label><label style="display:grid;gap:4px">To <input id="pnlEnd" type="date" class="form-control" value="<?= date('Y-m-d') ?>"></label><button class="btn btn-primary" onclick="startBilling('start_pnl_export')" <?= $schemaError?'disabled':'' ?>><i class="fas fa-file-export"></i> Export P&amp;L · ₱<?= number_format($settings['pnl_fee'],2) ?></button></div>
    </section>
    <section style="border:1px solid #fef3c7;border-radius:13px;padding:20px;margin-bottom:24px;background:#fffbeb">
      <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap"><div><h3 style="margin:0">Ingredient record storage</h3><p style="margin:5px 0 0;color:#475569"><strong><?= (int)$capacity['used'] ?>/<?= (int)$capacity['limit'] ?></strong> logical ingredient records used. <?= (int)$capacity['extra_slots'] ?> active expansion slots.</p><small>Each pack adds <?= (int)$settings['inventory_pack_slots'] ?> records for <?= (int)$settings['inventory_pack_days'] ?> days; expired packs remain visible in history.</small></div><button class="btn btn-primary" onclick="startBilling('start_storage_pack')" <?= $schemaError?'disabled':'' ?>><i class="fas fa-boxes"></i> Add <?= (int)$settings['inventory_pack_slots'] ?> records · ₱<?= number_format($settings['inventory_pack_fee'],2) ?></button></div>
    </section>
    <h3 style="margin-top:0">Transaction history</h3>
    <div class="table-responsive"><table class="table"><thead><tr><th>Reference</th><th>Service</th><th>Amount</th><th>Status</th><th>Paid</th></tr></thead><tbody><?php if (!$purchases): ?><tr><td colspan="5" style="color:#64748b">No billing transactions yet.</td></tr><?php else: foreach($purchases as $p): ?><tr><td><code><?= htmlspecialchars($p['internal_reference']) ?></code></td><td><?= htmlspecialchars(ucwords(str_replace('_',' ',$p['service_type']))) ?></td><td>₱<?= number_format((float)$p['amount'],2) ?></td><td><?= htmlspecialchars($p['status']) ?></td><td><?= htmlspecialchars($p['paid_at']??'—') ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
    <?php if ($paidPnlPurchases): ?>
    <section style="margin-top:18px;border:1px solid #86efac;background:#f0fdf4;border-radius:12px;padding:16px">
      <h3 style="margin:0 0 8px"><i class="fas fa-circle-check"></i> Verified P&amp;L exports</h3>
      <p style="margin:0 0 10px;color:#475569;font-size:.9rem">These receipts have been verified and can be downloaded again at no additional charge.</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach ($paidPnlPurchases as $p): ?>
        <a class="btn btn-primary" href="<?= BASE_URL ?>dashboard/business_owner/api/revenue_report_download.php?type=pnl&amp;purchase_id=<?= (int)$p['pnl_purchase_id'] ?>"><i class="fas fa-file-pdf"></i> Download P&amp;L &middot; <?= htmlspecialchars((string)$p['internal_reference']) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
<script>
const BILLING_API = '<?= BASE_URL ?>dashboard/business_owner/api/revenue_payment_api.php';
const billingCsrf = <?= json_encode($csrf) ?>;
function billingMessage(text, error=false) { const el=document.getElementById('billingMessage'); el.textContent=text; el.className='alert '+(error?'alert-danger':'alert-success'); el.style.display='block'; }
async function startBilling(action) { const form=new FormData(); form.append('action',action); form.append('csrf_token',billingCsrf); if(action==='start_pnl_export'){form.append('period_start',document.getElementById('pnlStart').value);form.append('period_end',document.getElementById('pnlEnd').value);} try { const response=await fetch(BILLING_API,{method:'POST',body:form}); const data=await response.json(); if(!data.success){billingMessage(data.message||'Could not start payment.',true);return;} if(data.ready&&data.download_url){window.location.href=data.download_url;return;} if(data.ready){window.location.href='<?= BASE_URL ?>dashboard/business_owner/billing.php?payment=verified';return;} if(data.checkout_url){window.location.href=data.checkout_url;return;} billingMessage('Payment request created. Wait for verification before downloading.'); } catch(e){billingMessage('Network error. Please try again.',true);} }
<?php if ($paymentReturn === 'return'): ?>billingMessage(<?= json_encode(((($pnlReconciliation['ready'] ?? 0) + ($storageReconciliation['ready'] ?? 0)) + ($storageReconciliation['ready'] ?? 0)) > 0 ? 'Payment verified with PayMongo. Your paid service is ready.' : 'Payment return received. PayMongo verification is still pending; refresh this page in a moment.') ?>, <?= (((($pnlReconciliation['ready'] ?? 0) + ($storageReconciliation['ready'] ?? 0)) + ($storageReconciliation['ready'] ?? 0)) > 0) ? 'false' : 'true' ?>);<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>