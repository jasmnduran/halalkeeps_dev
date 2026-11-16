<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../includes/RevenuePaymentService.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

if (!isLoggedIn() || (int)($_SESSION['role_id'] ?? 0) !== ROLE_BUSINESS_OWNER) { http_response_code(403); exit('Unauthorized'); }
$type=(string)($_GET['type']??''); $purchaseId=(int)($_GET['purchase_id']??0); $userId=(int)$_SESSION['user_id']; $restaurantId=getSessionRestaurantId($conn);
$purchase=(new RevenuePaymentService($conn))->authorizeReportDownload($userId,$restaurantId,$type,$purchaseId);
if (!$purchase) { http_response_code(403); exit('A verified purchase is required to download this report.'); }
if ($type !== 'pnl') { http_response_code(404); exit('Use the HaCeRa report download endpoint.'); }
$snapshot=json_decode((string)$purchase['snapshot_json'],true);
if (!is_array($snapshot)) { http_response_code(500); exit('Saved report snapshot is unavailable.'); }
$money=static fn($v) => 'PHP '.number_format((float)$v,2);
$name=htmlspecialchars((string)($snapshot['restaurant']['restaurant_name']??'Restaurant'),ENT_QUOTES,'UTF-8');
$period=htmlspecialchars(($snapshot['period_start']??'').' to '.($snapshot['period_end']??''),ENT_QUOTES,'UTF-8');
$rows=['Restaurant sales (platform fees excluded)'=>$snapshot['restaurant_sales']??0,'Approved other inflows'=>$snapshot['other_inflows']??0,'Revenue'=>$snapshot['revenue']??0,'Delivery expense'=>$snapshot['delivery_expense']??0,'Operating expenses'=>$snapshot['operating_expenses']??0,'Total expenses'=>$snapshot['expenses']??0,'Net cash flow'=>$snapshot['net_cash_flow']??0];
$html='<style>body{font-family:dejavusans,sans-serif;color:#1f2937;font-size:10pt}h1{color:#065f46;margin-bottom:2px}table{width:100%;border-collapse:collapse;margin-top:18px}th,td{border:1px solid #d1d5db;padding:8px;text-align:left}th{background:#ecfdf5}.total{font-weight:bold;background:#f3f4f6}.note{color:#6b7280;font-size:8.5pt;margin-top:18px}</style>';
$html.='<h1>Profit &amp; Loss Export</h1><p><strong>'.$name.'</strong><br>Period: '.$period.'<br>Verified purchase receipt: '.htmlspecialchars($purchase['internal_reference']).'<br>Generated from saved snapshot: '.htmlspecialchars((string)($snapshot['captured_at']??''),ENT_QUOTES,'UTF-8').'</p><table><tr><th>Metric</th><th>Amount</th></tr>';
foreach($rows as $label=>$amount) $html.='<tr'.(in_array($label,['Revenue','Total expenses','Net cash flow'],true)?' class="total"':'').'><td>'.htmlspecialchars($label).'</td><td>'.$money($amount).'</td></tr>';
$html.='</table><p class="note">'.htmlspecialchars((string)($snapshot['disclaimer']??''),ENT_QUOTES,'UTF-8').'</p>';
$tmp=dirname(__DIR__,3).'/tmp/mpdf'; if (!is_dir($tmp)) mkdir($tmp,0755,true);
$mpdf=new \Mpdf\Mpdf(['tempDir'=>$tmp,'format'=>'A4','margin_top'=>16,'margin_bottom'=>16,'margin_left'=>15,'margin_right'=>15]);
$mpdf->SetTitle('P&L Export'); $mpdf->WriteHTML($html); $mpdf->Output('PNL_'.preg_replace('/[^A-Za-z0-9]/','_',$name).'_'.date('Ymd').'.pdf','D');