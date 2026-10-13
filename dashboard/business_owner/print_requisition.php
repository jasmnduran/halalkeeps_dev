<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
requireRole([ROLE_BUSINESS_OWNER]);

$user_id = (int)$_SESSION['user_id'];
$rest_id = getSessionRestaurantId($conn);
$req_id  = (int)($_GET['req_id'] ?? 0);
if (!$req_id || !$rest_id) { http_response_code(404); echo 'Not found.'; exit; }

$req = $conn->query("
    SELECT gl.*, u.full_name AS requester_name, hr.restaurant_name
    FROM grocery_lists gl
    JOIN users u ON gl.created_by = u.id
    JOIN halal_restaurants hr ON gl.restaurant_id = hr.id
    WHERE gl.id = {$req_id} AND gl.restaurant_id = {$rest_id}
    LIMIT 1
")->fetch_assoc();
if (!$req) { http_response_code(404); echo 'Requisition not found.'; exit; }

$items = $conn->query(
    "SELECT * FROM grocery_list_items WHERE grocery_list_id = {$req_id} ORDER BY id ASC"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Grocery List Slip — GL-<?= $req_id ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 13px; color: #111; background: #fff; padding: 32px; }
  .slip { max-width: 700px; margin: 0 auto; border: 1.5px solid #ccc; border-radius: 8px; overflow: hidden; }
  .slip-header { background: #064e3b; color: #fff; padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; }
  .slip-header h1 { font-size: 1.2rem; font-weight: 800; }
  .slip-header .meta { font-size: .82rem; opacity: .85; text-align: right; }
  .slip-body { padding: 20px 24px; }
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 24px; margin-bottom: 20px; }
  .info-item label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #64748b; display: block; }
  .info-item span  { font-size: .9rem; font-weight: 600; color: #111; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  th { background: #f1f5f9; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #475569; padding: 8px 12px; text-align: left; border-bottom: 1.5px solid #e2e8f0; }
  td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; font-size: .88rem; vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .72rem; font-weight: 700; }
  .badge-pending  { background: #fef9c3; color: #854d0e; }
  .badge-ack      { background: #dbeafe; color: #1e40af; }
  .badge-fulfilled{ background: #dcfce7; color: #166534; }
  .notes-box { background: #fafafa; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 20px; font-size: .85rem; color: #475569; }
  .sig-row { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 32px; }
  .sig-block { border-top: 1.5px solid #334155; padding-top: 8px; font-size: .78rem; color: #64748b; text-align: center; }
  .print-btn { display: block; margin: 20px auto 0; padding: 10px 28px; background: #064e3b; color: #fff; border: none; border-radius: 8px; font-size: .9rem; font-weight: 700; cursor: pointer; }
  @media print { .print-btn { display: none; } body { padding: 0; } }
</style>
</head>
<body>
<div class="slip">
  <div class="slip-header">
    <div>
      <h1><i>🥩</i> Halal Keeps — Grocery List Slip</h1>
      <div style="font-size:.82rem;margin-top:4px;opacity:.8;">
        <?= htmlspecialchars($req['restaurant_name']) ?>
      </div>
    </div>
    <div class="meta">
      GL-<?= $req_id ?><br>
      <?= date('M d, Y', strtotime($req['created_at'])) ?><br>
      <span class="badge badge-<?= $req['status'] === 'pending' ? 'pending' : ($req['status'] === 'acknowledged' ? 'ack' : 'fulfilled') ?>">
        <?= ucfirst($req['status']) ?>
      </span>
    </div>
  </div>

  <div class="slip-body">
    <div class="info-grid">
      <div class="info-item">
        <label>Requested By</label>
        <span><?= htmlspecialchars($req['requester_name']) ?></span>
      </div>
      <div class="info-item">
        <label>Date Submitted</label>
        <span><?= date('F d, Y \a\t h:i A', strtotime($req['created_at'])) ?></span>
      </div>
    </div>

    <?php if (!empty($req['notes'])): ?>
    <div class="notes-box">
      <strong>Chef Notes:</strong> <?= htmlspecialchars($req['notes']) ?>
    </div>
    <?php endif; ?>

    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Ingredient / Item</th>
          <th>Qty Requested</th>
          <th>Unit</th>
          <th>Est. Cost (₱)</th>
          <th>Target Date</th>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $n => $item): ?>
        <tr>
          <td><?= $n + 1 ?></td>
          <td><strong><?= htmlspecialchars($item['ingredient_name']) ?></strong></td>
          <td><?= number_format($item['quantity'], 2) ?></td>
          <td><?= htmlspecialchars($item['unit']) ?></td>
          <td><?= $item['estimated_cost'] ? number_format($item['estimated_cost'], 2) : '—' ?></td>
          <td><?= !empty($item['target_date']) ? date('M d, Y', strtotime($item['target_date'])) : '—' ?></td>
          <td><?= htmlspecialchars($item['notes'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="sig-row">
      <div class="sig-block">Requested by — Chef / Kitchen Staff</div>
      <div class="sig-block">Approved by — Operations Manager</div>
    </div>
  </div>
</div>

<button class="print-btn" onclick="window.print()">🖨️ Print Slip</button>
</body>
</html>
