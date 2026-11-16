<?php
declare(strict_types=1);

/** Central server-authoritative PayMongo billing and entitlement service. */
require_once __DIR__ . '/HaCeRaCalculator.php';
require_once __DIR__ . '/IngredientInventoryService.php';

final class RevenuePaymentService
{
    public const HACERA_PREMIUM_SERVICE = 'hacera_premium_report';

    private mysqli $conn;
    public function __construct(mysqli $conn) { $this->conn = $conn; }

    public static function ensureCsrfToken(): string {
        if (empty($_SESSION['revenue_payment_csrf'])) $_SESSION['revenue_payment_csrf'] = bin2hex(random_bytes(32));
        return (string)$_SESSION['revenue_payment_csrf'];
    }
    public static function validCsrf(?string $token): bool {
        return is_string($token) && !empty($_SESSION['revenue_payment_csrf']) && hash_equals((string)$_SESSION['revenue_payment_csrf'], $token);
    }
    public static function moneyMinor(float $amount): int { return max(0, (int)round($amount * 100)); }
    public static function moneyFromMinor(int $amount): float { return round($amount / 100, 2); }
    public static function boundedPlatformFeeRate(bool $enabled, float $rate): float {
        return $enabled && $rate >= 0.0114 && $rate <= 0.0120 ? $rate : 0.0;
    }

    public function schemaReady(): bool {
        foreach (['billing_payment_requests','hacera_report_purchases','pnl_export_purchases','inventory_storage_packs','halal_keeps_revenue_ledger','paymongo_webhook_events'] as $table) if (!$this->tableExists($table)) return false;
        return true;
    }
    public function schemaError(): ?string { return $this->schemaReady() ? null : 'Billing is not installed yet. Apply database_migrations/add_paymongo_revenue_models.sql first.'; }

    public function billingSettings(): array {
        $values = [
            'billing_hacera_report_fee'=>'150.00','billing_pnl_export_fee'=>'150.00','billing_inventory_pack_fee'=>'199.00',
            'billing_inventory_pack_slots'=>'50','billing_inventory_pack_days'=>'90','billing_inventory_base_slots'=>'49',
            'billing_platform_fee_enabled'=>'0','billing_platform_fee_rate'=>'0.000000','billing_platform_fee_label'=>'Halal Keeps platform fee',
        ];
        if ($this->tableExists('system_settings')) {
            $keys = array_keys($values); $marks = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $this->conn->prepare("SELECT setting_key,setting_value FROM system_settings WHERE setting_key IN ($marks)");
            if ($stmt) { $types = str_repeat('s', count($keys)); $stmt->bind_param($types, ...$keys); $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $values[$row['setting_key']] = (string)$row['setting_value']; $stmt->close(); }
        }
        $enabled = $values['billing_platform_fee_enabled'] === '1';
        return [
            'hacera_fee'=>round(max(0,(float)$values['billing_hacera_report_fee']),2),
            'pnl_fee'=>round(max(0,(float)$values['billing_pnl_export_fee']),2),
            'inventory_pack_fee'=>round(max(0,(float)$values['billing_inventory_pack_fee']),2),
            'inventory_pack_slots'=>max(1,(int)$values['billing_inventory_pack_slots']),
            'inventory_pack_days'=>max(1,(int)$values['billing_inventory_pack_days']),
            'inventory_base_slots'=>max(1,(int)$values['billing_inventory_base_slots']),
            'platform_fee_enabled'=>$enabled,
            'platform_fee_rate'=>self::boundedPlatformFeeRate($enabled,(float)$values['billing_platform_fee_rate']),
            'platform_fee_label'=>trim($values['billing_platform_fee_label']) ?: 'Halal Keeps platform fee',
        ];
    }

    public function platformFeeForOrder(float $subtotal, float $deliveryFee, string $paymentMethod): array {
        $settings = $this->billingSettings();
        $eligible = in_array($paymentMethod, ['gcash','bank_transfer','paymongo','card'], true);
        $rate = $eligible ? $settings['platform_fee_rate'] : 0.0;
        return ['amount'=>round(max(0,$subtotal + $deliveryFee) * $rate,2),'rate'=>$rate,'enabled'=>$rate>0,'label'=>$settings['platform_fee_label']];
    }

    public function getInventoryCapacity(int $restaurantId): array {
        $settings = $this->billingSettings(); $used = 0; $packs = []; $extra = 0;
        if ($this->tableExists('kitchen_inventory')) { $s=$this->conn->prepare('SELECT COUNT(*) c FROM kitchen_inventory WHERE restaurant_id=?'); $s->bind_param('i',$restaurantId); $s->execute(); $used=(int)($s->get_result()->fetch_assoc()['c']??0); $s->close(); }
        if ($this->tableExists('inventory_storage_packs')) { $s=$this->conn->prepare('SELECT id,additional_slots,activated_at,expires_at,status,created_at FROM inventory_storage_packs WHERE restaurant_id=? ORDER BY created_at DESC'); $s->bind_param('i',$restaurantId); $s->execute(); $packs=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); foreach ($packs as $pack) if ($pack['status']==='active' && !empty($pack['expires_at']) && strtotime($pack['expires_at'])>time()) $extra+=(int)$pack['additional_slots']; }
        $limit=$settings['inventory_base_slots']+$extra;
        return ['used'=>$used,'base_limit'=>$settings['inventory_base_slots'],'extra_slots'=>$extra,'limit'=>$limit,'available'=>max(0,$limit-$used),'at_capacity'=>$used>=$limit,'packs'=>$packs,'pack_price'=>$settings['inventory_pack_fee'],'pack_slots'=>$settings['inventory_pack_slots'],'pack_days'=>$settings['inventory_pack_days']];
    }
    public function assertInventorySlotAvailable(int $restaurantId): array {
        $capacity=$this->getInventoryCapacity($restaurantId);
        return $capacity['at_capacity'] ? ['success'=>false,'message'=>'Inventory storage limit reached. Ask the owner or operations manager to purchase an expansion pack.','capacity'=>$capacity] : ['success'=>true,'capacity'=>$capacity];
    }

    public function startHaceraReport(int $userId, int $restaurantId): array {
        if (!$this->isActualOwner($userId,$restaurantId)) return $this->error('Only the restaurant owner can purchase a HaCeRa report.');
        if ($error=$this->schemaError()) return $this->error($error);
        $snapshot=$this->buildHaceraSnapshot($restaurantId);
        $hash=self::haceraAssessmentVersionHash($snapshot);
        $snapshot['assessment_version_hash']=$hash;
        $snapshot['captured_at']=gmdate('c');
        $json=$this->stableJson($snapshot);
        if ($existing=$this->findReadyReport('hacera_report_purchases',$restaurantId,$hash)) return ['success'=>true,'ready'=>true,'purchase_id'=>(int)$existing['id'],'download_url'=>$this->haceraDownloadUrl((int)$existing['id'])];
        if ($pending=$this->findPendingHaceraPurchase($restaurantId,$hash)) {
            $reconciled=$this->reconcileHaceraPremiumPayment($userId,$restaurantId,(int)$pending['id']);
            if (($reconciled['success'] ?? false) && ($reconciled['ready'] ?? false)) return $reconciled;
            $checkoutUrl=$this->checkoutUrlFromRequest($pending);
            if ($checkoutUrl !== '') return ['success'=>true,'ready'=>false,'purchase_id'=>(int)$pending['id'],'payment_request_id'=>(int)$pending['payment_request_id'],'checkout_url'=>$checkoutUrl,'amount'=>(float)$pending['amount']];
        }
        $s=$this->conn->prepare("INSERT INTO hacera_report_purchases (restaurant_id,purchaser_user_id,snapshot_hash,snapshot_json,delivery_status) VALUES (?,?,?,?, 'pending_payment')"); $s->bind_param('iiss',$restaurantId,$userId,$hash,$json);
        if (!$s->execute()) return $this->error('Could not prepare the report purchase.'); $purchaseId=(int)$this->conn->insert_id; $s->close();
        $checkout=$this->createCheckout($userId,$restaurantId,null,self::HACERA_PREMIUM_SERVICE,$this->billingSettings()['hacera_fee'],'HaCeRa Premium Assessment Results',['purchase_id'=>$purchaseId,'assessment_version_hash'=>$hash]);
        if (!$checkout['success']) { $this->conn->query("UPDATE hacera_report_purchases SET delivery_status='provisioning_failed' WHERE id=$purchaseId"); return $checkout; }
        $s=$this->conn->prepare('UPDATE hacera_report_purchases SET payment_request_id=? WHERE id=?'); $s->bind_param('ii',$checkout['payment_request_id'],$purchaseId); $s->execute(); $s->close(); return $checkout+['purchase_id'=>$purchaseId];
    }

    /**
     * Fallback for an unavailable webhook receiver (for example, localhost).
     * The browser is not trusted: PayMongo is queried with the secret key and
     * the paid payment, amount, currency, checkout id, and internal reference
     * must all match the pending server-created request before access is granted.
     */
    public function reconcileHaceraPremiumPayment(int $userId,int $restaurantId,int $purchaseId=0): array {
        if (!$this->isActualOwner($userId,$restaurantId)) return $this->error('Only the restaurant owner can verify a HaCeRa payment.',403);
        if ($error=$this->schemaError()) return $this->error($error);
        $sql="SELECT p.id purchase_id,b.* FROM hacera_report_purchases p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.purchaser_user_id=? AND p.delivery_status='pending_payment' AND b.status='pending' AND b.service_type IN ('hacera_premium_report','hacera_report')";
        if ($purchaseId>0) $sql.=' AND p.id=?';
        $sql.=' ORDER BY p.id DESC LIMIT 1';
        $s=$this->conn->prepare($sql);
        if (!$s) return $this->error('Could not verify the HaCeRa payment.');
        if ($purchaseId>0) $s->bind_param('iii',$restaurantId,$userId,$purchaseId); else $s->bind_param('ii',$restaurantId,$userId);
        $s->execute(); $request=$s->get_result()->fetch_assoc(); $s->close();
        if (!$request) return ['success'=>true,'ready'=>false];
        $sessionId=(string)($request['checkout_session_id']??'');
        $reference=(string)($request['internal_reference']??'');
        if ($sessionId==='' || $reference==='') return $this->error('The pending HaCeRa payment is incomplete.');
        $provider=payMongoRequest('GET','/v1/checkout_sessions/'.rawurlencode($sessionId));
        if (!($provider['success']??false)) return $this->error('Could not verify payment with PayMongo. Please refresh and try again.',502);
        $resource=$provider['data']['data']??[]; $attributes=is_array($resource)?($resource['attributes']??[]):[];
        if (!is_array($attributes) || !hash_equals($sessionId,(string)($resource['id']??'')) || !hash_equals($reference,(string)($attributes['reference_number']??''))) return $this->error('PayMongo payment verification did not match this purchase.',409);
        $metadata=$attributes['metadata']??[];
        if (!is_array($metadata) || !hash_equals($reference,(string)($metadata['internal_reference']??''))) return $this->error('PayMongo payment metadata did not match this purchase.',409);
        $payment=$this->paidPaymentFromResource($attributes);
        if (!$payment) return ['success'=>true,'ready'=>false];
        if ((string)$payment['id']==='') return $this->error('PayMongo did not return a payment identifier.',409);
        try {
            $settled=$this->fulfillVerifiedCheckout($sessionId,$reference,$payment,'api_reconciliation:'.$sessionId);
            return $settled+['ready'=>true,'purchase_id'=>(int)$request['purchase_id']];
        } catch (Throwable $e) {
            error_log('HaCeRa PayMongo reconciliation failed: '.$e->getMessage());
            return $this->error('Payment verification could not be completed. Please refresh and try again.',500);
        }
    }
    public function startPnlExport(int $userId,int $restaurantId,string $start,string $end): array {
        if (!$this->canManageBilling($userId,$restaurantId)) return $this->error('Only the owner or active operations manager can purchase a P&L export.');
        if ($error=$this->schemaError()) return $this->error($error); if (!$this->validDateRange($start,$end)) return $this->error('Select a valid report range of no more than 366 days.');
        $snapshot=$this->buildPnlSnapshot($restaurantId,$start,$end); $json=$this->stableJson($snapshot); $hash=hash('sha256',$json);
        if ($existing=$this->findReadyReport('pnl_export_purchases',$restaurantId,$hash)) return ['success'=>true,'ready'=>true,'purchase_id'=>(int)$existing['id'],'download_url'=>BASE_URL.'dashboard/business_owner/api/revenue_report_download.php?type=pnl&purchase_id='.(int)$existing['id']];
        if ($existing=$this->findReadyPnlReportForPeriod($restaurantId,$start,$end)) return ['success'=>true,'ready'=>true,'purchase_id'=>(int)$existing['id'],'download_url'=>BASE_URL.'dashboard/business_owner/api/revenue_report_download.php?type=pnl&purchase_id='.(int)$existing['id']];
        if ($pending=$this->findPendingPnlPurchase($restaurantId,$start,$end)) {
            $reconciled=$this->reconcilePnlExportPayment($userId,$restaurantId,(int)$pending['id']);
            if (($reconciled['success'] ?? false) && ($reconciled['ready'] ?? false)) return ['success'=>true,'ready'=>true,'purchase_id'=>(int)$pending['id'],'download_url'=>BASE_URL.'dashboard/business_owner/api/revenue_report_download.php?type=pnl&purchase_id='.(int)$pending['id']];
            $checkoutUrl=$this->checkoutUrlFromRequest($pending);
            if ($checkoutUrl !== '') return ['success'=>true,'ready'=>false,'purchase_id'=>(int)$pending['id'],'payment_request_id'=>(int)$pending['payment_request_id'],'checkout_url'=>$checkoutUrl,'amount'=>(float)$pending['amount']];
        }
        $s=$this->conn->prepare("INSERT INTO pnl_export_purchases (restaurant_id,purchaser_user_id,period_start,period_end,snapshot_hash,snapshot_json,delivery_status) VALUES (?,?,?,?,?,?, 'pending_payment')"); $s->bind_param('iissss',$restaurantId,$userId,$start,$end,$hash,$json);
        if (!$s->execute()) return $this->error('Could not prepare the P&L export.'); $purchaseId=(int)$this->conn->insert_id; $s->close();
        $checkout=$this->createCheckout($userId,$restaurantId,null,'pnl_export',$this->billingSettings()['pnl_fee'],'P&L Export ('.$start.' to '.$end.')',['purchase_id'=>$purchaseId,'snapshot_hash'=>$hash]);
        if (!$checkout['success']) { $this->conn->query("UPDATE pnl_export_purchases SET delivery_status='provisioning_failed' WHERE id=$purchaseId"); return $checkout; }
        $s=$this->conn->prepare('UPDATE pnl_export_purchases SET payment_request_id=? WHERE id=?'); $s->bind_param('ii',$checkout['payment_request_id'],$purchaseId); $s->execute(); $s->close(); return $checkout+['purchase_id'=>$purchaseId];
    }

    /** Server-to-server verification fallback for P&L checkouts when webhooks cannot reach localhost. */
    public function reconcilePnlExportPayment(int $userId,int $restaurantId,int $purchaseId=0): array {
        if (!$this->canManageBilling($userId,$restaurantId)) return $this->error('Only the owner or active operations manager can verify a P&L payment.',403);
        if ($error=$this->schemaError()) return $this->error($error);
        $sql="SELECT p.id purchase_id,b.* FROM pnl_export_purchases p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.delivery_status='pending_payment' AND b.status='pending' AND b.service_type='pnl_export'";
        if ($purchaseId>0) $sql.=' AND p.id=?';
        $sql.=' ORDER BY p.id DESC LIMIT 1';
        $s=$this->conn->prepare($sql);
        if (!$s) return $this->error('Could not verify the P&L payment.');
        if ($purchaseId>0) $s->bind_param('ii',$restaurantId,$purchaseId); else $s->bind_param('i',$restaurantId);
        $s->execute(); $request=$s->get_result()->fetch_assoc(); $s->close();
        if (!$request) return ['success'=>true,'ready'=>false];
        $sessionId=(string)($request['checkout_session_id']??''); $reference=(string)($request['internal_reference']??'');
        if ($sessionId==='' || $reference==='') return $this->error('The pending P&L payment is incomplete.');
        $provider=payMongoRequest('GET','/v1/checkout_sessions/'.rawurlencode($sessionId));
        if (!($provider['success']??false)) return $this->error('Could not verify payment with PayMongo. Please refresh and try again.',502);
        $resource=$provider['data']['data']??[]; $attributes=is_array($resource)?($resource['attributes']??[]):[];
        if (!is_array($attributes) || !hash_equals($sessionId,(string)($resource['id']??'')) || !hash_equals($reference,(string)($attributes['reference_number']??''))) return $this->error('PayMongo payment verification did not match this export.',409);
        $metadata=$attributes['metadata']??[];
        if (!is_array($metadata) || !hash_equals($reference,(string)($metadata['internal_reference']??''))) return $this->error('PayMongo payment metadata did not match this export.',409);
        $payment=$this->paidPaymentFromResource($attributes);
        if (!$payment) return ['success'=>true,'ready'=>false];
        if ((string)$payment['id']==='') return $this->error('PayMongo did not return a payment identifier.',409);
        try { $settled=$this->fulfillVerifiedPnlCheckout($sessionId,$reference,$payment,'api_reconciliation:'.$sessionId); return $settled+['ready'=>true,'purchase_id'=>(int)$request['purchase_id']]; }
        catch (Throwable $e) { error_log('P&L PayMongo reconciliation failed: '.$e->getMessage()); return $this->error('Payment verification could not be completed. Please refresh and try again.',500); }
    }
    /** Reconcile pending P&L exports after a PayMongo return without trusting the browser. */
    public function reconcilePendingPnlExports(int $userId,int $restaurantId): array {
        if (!$this->canManageBilling($userId,$restaurantId) || !$this->schemaReady()) return ['success'=>false,'checked'=>0,'ready'=>0];
        $s=$this->conn->prepare("SELECT p.id FROM pnl_export_purchases p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.delivery_status='pending_payment' AND b.status='pending' AND b.service_type='pnl_export' ORDER BY p.id DESC"); $s->bind_param('i',$restaurantId); $s->execute(); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
        $ready=0; foreach ($rows as $row) { $result=$this->reconcilePnlExportPayment($userId,$restaurantId,(int)$row['id']); if (($result['success']??false) && ($result['ready']??false)) $ready++; }
        return ['success'=>true,'checked'=>count($rows),'ready'=>$ready];
    }
    public function startStoragePack(int $userId,int $restaurantId): array {
        if (!$this->canManageBilling($userId,$restaurantId)) return $this->error('Only the owner or active operations manager can purchase storage.'); if ($error=$this->schemaError()) return $this->error($error);
        if ($pending=$this->findPendingStoragePack($restaurantId,$userId)) {
            $reconciled=$this->reconcileStoragePackPayment($userId,$restaurantId,(int)$pending['id']);
            if (($reconciled['success'] ?? false) && ($reconciled['ready'] ?? false)) return $reconciled;
            $checkoutUrl=$this->checkoutUrlFromRequest($pending);
            if ($checkoutUrl !== '') return ['success'=>true,'ready'=>false,'pack_id'=>(int)$pending['id'],'payment_request_id'=>(int)$pending['payment_request_id'],'checkout_url'=>$checkoutUrl,'amount'=>(float)$pending['amount']];
        }
        $settings=$this->billingSettings(); $slots=$settings['inventory_pack_slots']; $s=$this->conn->prepare("INSERT INTO inventory_storage_packs (restaurant_id,purchaser_user_id,additional_slots,status) VALUES (?,?,?,'pending_payment')"); $s->bind_param('iii',$restaurantId,$userId,$slots);
        if (!$s->execute()) return $this->error('Could not prepare the storage pack purchase.'); $packId=(int)$this->conn->insert_id; $s->close();
        $checkout=$this->createCheckout($userId,$restaurantId,null,'inventory_storage_pack',$settings['inventory_pack_fee'],'+'.$slots.' ingredient records for '.$settings['inventory_pack_days'].' days',['pack_id'=>$packId,'slots'=>$slots,'days'=>$settings['inventory_pack_days']]);
        if (!$checkout['success']) { $this->conn->query("UPDATE inventory_storage_packs SET status='provisioning_failed' WHERE id=$packId"); return $checkout; }
        $s=$this->conn->prepare('UPDATE inventory_storage_packs SET payment_request_id=? WHERE id=?'); $s->bind_param('ii',$checkout['payment_request_id'],$packId); $s->execute(); $s->close(); return $checkout+['pack_id'=>$packId];
    }
    /** Server-to-server verification fallback for storage packs when webhooks cannot reach localhost. */
    public function reconcileStoragePackPayment(int $userId,int $restaurantId,int $packId=0): array {
        if (!$this->canManageBilling($userId,$restaurantId)) return $this->error('Only the owner or active operations manager can verify a storage payment.',403);
        if ($error=$this->schemaError()) return $this->error($error);
        $sql="SELECT p.id pack_id,b.* FROM inventory_storage_packs p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.status='pending_payment' AND b.status='pending' AND b.service_type='inventory_storage_pack'";
        if ($packId>0) $sql.=' AND p.id=?';
        $sql.=' ORDER BY p.id DESC LIMIT 1';
        $s=$this->conn->prepare($sql);
        if (!$s) return $this->error('Could not verify the storage payment.');
        if ($packId>0) $s->bind_param('ii',$restaurantId,$packId); else $s->bind_param('i',$restaurantId);
        $s->execute(); $request=$s->get_result()->fetch_assoc(); $s->close();
        if (!$request) return ['success'=>true,'ready'=>false];
        $sessionId=(string)($request['checkout_session_id']??''); $reference=(string)($request['internal_reference']??'');
        if ($sessionId==='' || $reference==='') return $this->error('The pending storage payment is incomplete.');
        $provider=payMongoRequest('GET','/v1/checkout_sessions/'.rawurlencode($sessionId));
        if (!($provider['success']??false)) return $this->error('Could not verify payment with PayMongo. Please refresh and try again.',502);
        $resource=$provider['data']['data']??[]; $attributes=is_array($resource)?($resource['attributes']??[]):[];
        if (!is_array($attributes) || !hash_equals($sessionId,(string)($resource['id']??'')) || !hash_equals($reference,(string)($attributes['reference_number']??''))) return $this->error('PayMongo payment verification did not match this storage pack.',409);
        $metadata=$attributes['metadata']??[];
        if (!is_array($metadata) || !hash_equals($reference,(string)($metadata['internal_reference']??''))) return $this->error('PayMongo payment metadata did not match this storage pack.',409);
        $payment=$this->paidPaymentFromResource($attributes);
        if (!$payment) return ['success'=>true,'ready'=>false];
        if ((string)$payment['id']==='') return $this->error('PayMongo did not return a payment identifier.',409);
        try { $settled=$this->fulfillVerifiedStorageCheckout($sessionId,$reference,$payment,'api_reconciliation:'.$sessionId); return $settled+['ready'=>true,'pack_id'=>(int)$request['pack_id']]; }
        catch (Throwable $e) { error_log('Storage-pack PayMongo reconciliation failed: '.$e->getMessage()); return $this->error('Payment verification could not be completed. Please refresh and try again.',500); }
    }
    /** Reconcile pending storage packs after a PayMongo return without trusting the browser. */
    public function reconcilePendingStoragePacks(int $userId,int $restaurantId): array {
        if (!$this->canManageBilling($userId,$restaurantId) || !$this->schemaReady()) return ['success'=>false,'checked'=>0,'ready'=>0];
        $s=$this->conn->prepare("SELECT p.id FROM inventory_storage_packs p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.status='pending_payment' AND b.status='pending' AND b.service_type='inventory_storage_pack' ORDER BY p.id DESC"); $s->bind_param('i',$restaurantId); $s->execute(); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
        $ready=0; foreach ($rows as $row) { $result=$this->reconcileStoragePackPayment($userId,$restaurantId,(int)$row['id']); if (($result['success']??false) && ($result['ready']??false)) $ready++; }
        return ['success'=>true,'checked'=>count($rows),'ready'=>$ready];
    }
    /** Store a server-authoritative cart as a checkout intent; never create an order before PayMongo verifies payment. */
    public function startCustomerOrderCheckout(int $userId, array $order): array {
        if ($error=$this->schemaError()) return $this->error($error);
        if (!$this->customerOrderCheckoutSchemaReady()) return $this->error('Customer checkout storage is not installed yet. Apply database_migrations/add_customer_order_checkout_intents.sql first.',503);
        $restaurantId=(int)($order['restaurant_id']??0); $amount=round((float)($order['total_amount']??0),2); $itemsJson=(string)($order['items_json']??'');
        if ($restaurantId<=0 || $amount<=0 || $itemsJson==='' || json_decode($itemsJson,true)===null) return $this->error('The order checkout details are invalid.');
        $s=$this->conn->prepare('SELECT restaurant_name FROM halal_restaurants WHERE id=? AND is_active=1 LIMIT 1');
        if (!$s) return $this->error('Could not validate the restaurant.');
        $s->bind_param('i',$restaurantId); $s->execute(); $restaurant=$s->get_result()->fetch_assoc(); $s->close();
        if (!$restaurant) return $this->error('Restaurant not found or inactive.');
        $subtotal=round((float)($order['subtotal']??0),2); $deliveryFee=round((float)($order['delivery_fee']??0),2); $platformFee=round((float)($order['platform_fee']??0),2); $platformRate=(float)($order['platform_fee_rate']??0); $address=(string)($order['delivery_address']??''); $serviceType=(string)($order['service_type']??'delivery'); $paymentMethod=(string)($order['payment_method']??'gcash'); $notes=(string)($order['notes']??''); $tableNumber=(string)($order['table_number']??''); $surveyId=(int)($order['survey_response_id']??0);
        $s=$this->conn->prepare("INSERT INTO customer_order_checkout_intents (customer_id,restaurant_id,items_json,subtotal,delivery_fee,platform_fee,platform_fee_rate,total_amount,delivery_address,service_type,payment_method,notes,table_number,survey_response_id,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'pending_payment')");
        if (!$s) return $this->error('Could not prepare the secure checkout.');
        $s->bind_param('iisdddddsssssi',$userId,$restaurantId,$itemsJson,$subtotal,$deliveryFee,$platformFee,$platformRate,$amount,$address,$serviceType,$paymentMethod,$notes,$tableNumber,$surveyId);
        if (!$s->execute()) { $s->close(); return $this->error('Could not prepare the secure checkout.'); }
        $intentId=(int)$this->conn->insert_id; $s->close();
        $checkout=$this->createCheckout($userId,$restaurantId,null,'order',$amount,'Order via PayMongo — '.(string)$restaurant['restaurant_name'],['checkout_intent_id'=>$intentId,'payment_method'=>$paymentMethod]);
        if (!$checkout['success']) { $s=$this->conn->prepare("UPDATE customer_order_checkout_intents SET status='provisioning_failed' WHERE id=?"); if ($s) { $s->bind_param('i',$intentId); $s->execute(); $s->close(); } return $checkout; }
        $s=$this->conn->prepare('UPDATE customer_order_checkout_intents SET payment_request_id=? WHERE id=?');
        if (!$s) return $this->error('Could not link the secure checkout.');
        $requestId=(int)$checkout['payment_request_id']; $s->bind_param('ii',$requestId,$intentId); $s->execute(); $s->close();
        return $checkout+['checkout_intent_id'=>$intentId];
    }

    /** Verify a customer order return with PayMongo; URL parameters cannot confirm or create an order. */
    public function reconcileCustomerOrderPayment(int $userId,string $reference): array {
        if ($error=$this->schemaError()) return $this->error($error);
        if (!$this->customerOrderCheckoutSchemaReady()) return $this->error('Customer checkout storage is not installed yet.',503);
        $reference=trim($reference); if ($reference==='') return $this->error('Payment reference is required.');
        $s=$this->conn->prepare("SELECT ci.id intent_id,ci.order_id intent_order_id,b.* FROM customer_order_checkout_intents ci JOIN billing_payment_requests b ON b.id=ci.payment_request_id WHERE b.internal_reference=? AND b.purchaser_user_id=? AND b.service_type='order' LIMIT 1");
        if (!$s) return $this->error('Could not verify this payment.');
        $s->bind_param('si',$reference,$userId); $s->execute(); $request=$s->get_result()->fetch_assoc(); $s->close();
        if (!$request) return $this->error('Payment checkout not found.',404);
        $orderId=(int)($request['order_id']??$request['intent_order_id']??0);
        if ($request['status']==='paid') return ['success'=>true,'ready'=>$orderId>0,'order_id'=>$orderId,'payment_request_id'=>(int)$request['id']];
        if ($request['status']!=='pending') return ['success'=>true,'ready'=>false];
        $sessionId=(string)($request['checkout_session_id']??'');
        if ($sessionId==='') return $this->error('The pending payment is incomplete.');
        $provider=payMongoRequest('GET','/v1/checkout_sessions/'.rawurlencode($sessionId));
        if (!($provider['success']??false)) return $this->error('Could not verify payment with PayMongo. Please refresh and try again.',502);
        $resource=$provider['data']['data']??[]; $attributes=is_array($resource)?($resource['attributes']??[]):[];
        if (!is_array($attributes) || !hash_equals($sessionId,(string)($resource['id']??'')) || !hash_equals($reference,(string)($attributes['reference_number']??''))) return $this->error('PayMongo payment verification did not match this checkout.',409);
        $metadata=$attributes['metadata']??[];
        if (!is_array($metadata) || !hash_equals($reference,(string)($metadata['internal_reference']??''))) return $this->error('PayMongo payment metadata did not match this checkout.',409);
        $payment=$this->paidPaymentFromResource($attributes); if (!$payment) return ['success'=>true,'ready'=>false];
        if ((string)$payment['id']==='') return $this->error('PayMongo did not return a payment identifier.',409);
        try { return $this->fulfillVerifiedCustomerOrderCheckout($sessionId,$reference,$payment,'api_reconciliation:'.$sessionId)+['ready'=>true]; }
        catch (Throwable $e) { error_log('Customer-order PayMongo reconciliation failed: '.$e->getMessage()); return $this->error('Payment verification could not be completed. Please refresh and try again.',500); }
    }
    public function startOrderCheckout(int $userId,int $orderId): array {
        if ($error=$this->schemaError()) return $this->error($error);
        $s=$this->conn->prepare('SELECT o.*,hr.restaurant_name FROM orders o JOIN halal_restaurants hr ON hr.id=o.restaurant_id WHERE o.id=? AND o.customer_id=? LIMIT 1'); $s->bind_param('ii',$orderId,$userId); $s->execute(); $order=$s->get_result()->fetch_assoc(); $s->close();
        if (!$order) return $this->error('Order not found.'); if (!in_array($order['payment_status'],['pending','failed'],true)) return $this->error('This order is no longer awaiting an online payment.');
        $amount=round((float)$order['total_amount'],2); if ($amount<=0) return $this->error('Order total is invalid.');
        return $this->createCheckout($userId,(int)$order['restaurant_id'],$orderId,'order',$amount,'Order '.$order['order_number'].' — '.$order['restaurant_name'],['order_number'=>$order['order_number']]);
    }

    public function startPosOrderCheckout(int $userId,int $restaurantId,int $orderId): array {
        if ($error=$this->schemaError()) return $this->error($error);
        if (!$this->canProcessPosCheckout($userId,$restaurantId)) return $this->error('Only an active cashier or restaurant owner can start this checkout.',403);
        $s=$this->conn->prepare('SELECT o.*,hr.restaurant_name FROM orders o JOIN halal_restaurants hr ON hr.id=o.restaurant_id WHERE o.id=? AND o.restaurant_id=? AND o.customer_id=? LIMIT 1');
        $s->bind_param('iii',$orderId,$restaurantId,$userId); $s->execute(); $order=$s->get_result()->fetch_assoc(); $s->close();
        if (!$order || !in_array($order['payment_status'],['pending','failed'],true)) return $this->error('POS order is no longer awaiting online payment.');
        return $this->createCheckout($userId,$restaurantId,$orderId,'order',round((float)$order['total_amount'],2),
            'POS order '.$order['order_number'].' — '.$order['restaurant_name'],
            ['order_number'=>$order['order_number'],'checkout_context'=>'pos']);
    }
    private function createCheckout(int $userId,?int $restaurantId,?int $orderId,string $serviceType,float $amount,string $description,array $metadata): array {
        $amount=round($amount,2); $minor=self::moneyMinor($amount); if ($minor<1) return $this->error('Payment amount must be greater than zero.'); if (empty(PAYMONGO_SECRET_KEY)) return $this->error('Online payment is not configured.');
        $reference='HK-'.strtoupper(bin2hex(random_bytes(8))); $idempotency=bin2hex(random_bytes(24)); $metadata+=['internal_reference'=>$reference,'service_type'=>$serviceType]; $metadataJson=json_encode($metadata,JSON_UNESCAPED_SLASHES);
        $s=$this->conn->prepare("INSERT INTO billing_payment_requests (internal_reference,purchaser_user_id,restaurant_id,order_id,service_type,amount,amount_minor,currency,status,idempotency_key,metadata_json) VALUES (?,?,?,?,?,?,?,'PHP','pending',?,?)");
        if (!$s) return $this->error('Could not prepare the payment request.');
        $s->bind_param('siiisdiss',$reference,$userId,$restaurantId,$orderId,$serviceType,$amount,$minor,$idempotency,$metadataJson);
        if (!$s->execute()) { $s->close(); return $this->error('Could not create the payment request.'); } $requestId=(int)$this->conn->insert_id; $s->close();
        $allowed=['card','gcash','paymaya','grab_pay','qrph','shopee_pay','billease','brankas','dob']; $methods=defined('PAYMONGO_ENABLED_METHODS')&&is_array(PAYMONGO_ENABLED_METHODS)?array_values(array_intersect(PAYMONGO_ENABLED_METHODS,$allowed)):['gcash','paymaya']; if (!$methods) $methods=['gcash'];
        $success=BASE_URL.'dashboard/business_owner/billing.php?payment=return'; $cancel=BASE_URL.'dashboard/business_owner/billing.php?payment=cancelled';
        if (in_array($serviceType,[self::HACERA_PREMIUM_SERVICE,'hacera_report'],true)) {
            $success=BASE_URL.'dashboard/business_owner/hacera.php?payment=return';
            $cancel=BASE_URL.'dashboard/business_owner/hacera.php?payment=cancelled';
        }
        if ($serviceType==='order') {
            if (($metadata['checkout_context'] ?? '') === 'pos') {
                $success=BASE_URL.'dashboard/business_owner/api/pos_payment_callback.php?payment=return&request='.rawurlencode($reference);
                $cancel=BASE_URL.'dashboard/business_owner/api/pos_payment_callback.php?payment=cancelled&request='.rawurlencode($reference);
            } else {
                $success=BASE_URL.'dashboard/customer/api/order_payment_callback.php?payment=return&request='.rawurlencode($reference);
                $cancel=BASE_URL.'dashboard/customer/api/order_payment_callback.php?payment=cancelled&request='.rawurlencode($reference);
            }
        }
        $payload=['data'=>['attributes'=>['line_items'=>[['currency'=>'PHP','amount'=>$minor,'name'=>$description,'quantity'=>1]],'payment_method_types'=>$methods,'description'=>$description,'reference_number'=>$reference,'success_url'=>$success,'cancel_url'=>$cancel,'send_email_receipt'=>true,'show_description'=>true,'show_line_items'=>true,'metadata'=>array_map('strval',$metadata)]]];
        $provider=payMongoRequest('POST','/v2/checkout_sessions',$payload);
        if (!$provider['success']) { $raw=json_encode($provider['response']??['message'=>$provider['message']]); $u=$this->conn->prepare("UPDATE billing_payment_requests SET status='provisioning_failed',provider_response=? WHERE id=?"); $u->bind_param('si',$raw,$requestId); $u->execute(); $u->close(); return $this->error('Payment gateway error: '.($provider['message']??'Could not create checkout.')); }
        $data=$provider['data']['data']??[]; $sessionId=(string)($data['id']??''); $url=(string)($data['attributes']['checkout_url']??'');
        if ($sessionId===''||$url==='') { $raw=json_encode($provider['data']); $u=$this->conn->prepare("UPDATE billing_payment_requests SET status='provisioning_failed',provider_response=? WHERE id=?"); $u->bind_param('si',$raw,$requestId); $u->execute(); $u->close(); return $this->error('Payment gateway did not return a checkout URL.'); }
        $raw=json_encode($provider['data']); $u=$this->conn->prepare('UPDATE billing_payment_requests SET checkout_session_id=?,provider_response=? WHERE id=?'); $u->bind_param('ssi',$sessionId,$raw,$requestId); $u->execute(); $u->close();
        if ($serviceType==='order' && $orderId) { $u=$this->conn->prepare('UPDATE orders SET paymongo_checkout_session_id=? WHERE id=?'); $u->bind_param('si',$sessionId,$orderId); $u->execute(); $u->close(); }
        return ['success'=>true,'ready'=>false,'payment_request_id'=>$requestId,'internal_reference'=>$reference,'checkout_session_id'=>$sessionId,'checkout_url'=>$url,'amount'=>$amount];
    }

    public function processWebhook(string $raw,string $signatureHeader): array {
        $payload=json_decode($raw,true); $event=$payload['data']??null; $attrs=is_array($event)?($event['attributes']??[]):[]; $eventId=(string)($event['id']??''); $type=(string)($attrs['type']??'');
        if ($eventId===''||$type==='') return $this->error('Invalid webhook payload.',400); $live=(bool)($attrs['livemode']??false); if (!$this->verifyWebhookSignature($raw,$signatureHeader,$live)) return $this->error('Invalid webhook signature.',401); if (!$this->schemaReady()) return $this->error('Billing schema is not installed.',503);
        $s=$this->conn->prepare("INSERT INTO paymongo_webhook_events (provider_event_id,event_type,livemode,signature_valid,processing_status,raw_payload) VALUES (?,?,?,1,'processing',?)"); $liveInt=$live?1:0; $s->bind_param('ssis',$eventId,$type,$liveInt,$raw);
        if (!$s->execute()) { if ($this->conn->errno===1062) return ['success'=>true,'idempotent'=>true]; return $this->error('Could not record webhook.',500); } $webhookId=(int)$this->conn->insert_id; $s->close();
        try { $resource=$attrs['data']??[]; $ra=is_array($resource)?($resource['attributes']??[]):[]; $sessionId=(string)($resource['id']??''); $reference=(string)($ra['reference_number']??($ra['metadata']['internal_reference']??''));
            if (in_array($type,['payment.refunded','payment_refund.succeeded'],true)) return $this->processRefund($webhookId,(string)($resource['id']??''),$sessionId,$reference);
            $payment=$this->paidPaymentFromResource($ra); if ($type!=='checkout_session.payment.paid'||!$payment||$sessionId==='') { $this->finishWebhook($webhookId,null,'ignored',null); return ['success'=>true,'ignored'=>true]; }
            return $this->fulfillPaidRequest($webhookId,$eventId,$sessionId,$reference,$payment);
        } catch (Throwable $e) { $this->finishWebhook($webhookId,null,'failed',substr($e->getMessage(),0,490)); error_log('PayMongo billing webhook failed: '.$e->getMessage()); return $this->error('Webhook processing failed.',500); }
    }

    public static function validWebhookSignature(string $raw,string $header,string $secret,bool $livemode,?int $now=null): bool {
        if ($secret===''||$header==='') return false; $parts=[];
        foreach (explode(',',$header) as $part) { [$key,$value]=array_pad(explode('=',trim($part),2),2,''); if ($key!==''&&$value!=='') $parts[$key][]=$value; }
        $timestamp=(string)($parts['t'][0]??''); $now=$now??time(); if (!ctype_digit($timestamp)||abs($now-(int)$timestamp)>300) return false;
        $expected=hash_hmac('sha256',$timestamp.'.'.$raw,$secret); foreach (($parts[$livemode?'li':'te']??[]) as $given) if (hash_equals($expected,$given)) return true; return false;
    }
    public function verifyWebhookSignature(string $raw,string $header,bool $livemode): bool {
        $secret=defined('PAYMONGO_WEBHOOK_SECRET')?(string)PAYMONGO_WEBHOOK_SECRET:(string)(getenv('PAYMONGO_WEBHOOK_SECRET')?:'');
        return self::validWebhookSignature($raw,$header,$secret,$livemode);
    }
    private function paidPaymentFromResource(array $attrs): ?array {
        $payments=$attrs['payments']??[]; if (isset($payments['data'])) $payments=$payments['data']; if (!is_array($payments)) return null;
        foreach ($payments as $payment) { $pa=$payment['attributes']??$payment; if (($pa['status']??'')!=='paid') continue; $amount=$pa['amount']??$attrs['amount']??$attrs['payment_intent']['attributes']['amount']??null; $currency=$pa['currency']??$attrs['currency']??$attrs['payment_intent']['attributes']['currency']??null; if (!is_numeric($amount)||!is_string($currency)) return null; return ['id'=>(string)($payment['id']??$pa['id']??''),'amount_minor'=>(int)$amount,'currency'=>strtoupper($currency),'fee_minor'=>(int)($pa['fee']??0)]; }
        return null;
    }
    private function fulfillPaidRequest(int $webhookId,string $eventId,string $sessionId,string $reference,array $payment): array {
        $this->conn->begin_transaction();
        try { $request=$this->requestForWebhook($sessionId,$reference); if (!$request) throw new RuntimeException('Payment request not found.');
            if ((int)$request['amount_minor'] !== (int)$payment['amount_minor'] || strtoupper((string)$request['currency']) !== strtoupper((string)$payment['currency'])) throw new RuntimeException('Paid amount or currency did not match the server-authoritative request.');
            if ($request['status']==='paid') { $this->conn->commit(); $this->finishWebhook($webhookId,(int)$request['id'],'processed',null); return ['success'=>true,'idempotent'=>true]; }
            if ($request['status']!=='pending') throw new RuntimeException('Payment request is not fulfillable.');
            $requestId=(int)$request['id']; $paymentId=(string)$payment['id']; $s=$this->conn->prepare("UPDATE billing_payment_requests SET status='paid',provider_payment_id=?,paid_at=NOW() WHERE id=?"); $s->bind_param('si',$paymentId,$requestId); $s->execute(); $s->close();
            $this->fulfillEntitlement($request,$eventId,$sessionId,$payment); $this->conn->commit(); $this->finishWebhook($webhookId,$requestId,'processed',null); return ['success'=>true,'payment_request_id'=>$requestId];
        } catch (Throwable $e) { $this->conn->rollback(); throw $e; }
    }

    /** Complete a storage entitlement only after a server-to-server PayMongo lookup. */
    private function fulfillVerifiedStorageCheckout(string $sessionId,string $reference,array $payment,string $eventId): array {
        $this->conn->begin_transaction();
        try { $request=$this->requestForWebhook($sessionId,$reference); if (!$request) throw new RuntimeException('Payment request not found.');
            if ((string)$request['service_type']!=='inventory_storage_pack') throw new RuntimeException('Payment request is not a storage entitlement.');
            if ((int)$request['amount_minor'] !== (int)$payment['amount_minor'] || strtoupper((string)$request['currency']) !== strtoupper((string)$payment['currency'])) throw new RuntimeException('Paid amount or currency did not match the server-authoritative request.');
            if ($request['status']==='paid') { $this->conn->commit(); return ['success'=>true,'idempotent'=>true,'payment_request_id'=>(int)$request['id']]; }
            if ($request['status']!=='pending') throw new RuntimeException('Payment request is not fulfillable.');
            $requestId=(int)$request['id']; $paymentId=(string)$payment['id']; $s=$this->conn->prepare("UPDATE billing_payment_requests SET status='paid',provider_payment_id=?,paid_at=NOW() WHERE id=?"); $s->bind_param('si',$paymentId,$requestId); $s->execute(); $s->close();
            $this->fulfillEntitlement($request,$eventId,$sessionId,$payment); $this->conn->commit(); return ['success'=>true,'payment_request_id'=>$requestId];
        } catch (Throwable $e) { $this->conn->rollback(); throw $e; }
    }
    /** Complete a P&L entitlement only after a server-to-server PayMongo lookup. */
    private function fulfillVerifiedPnlCheckout(string $sessionId,string $reference,array $payment,string $eventId): array {
        $this->conn->begin_transaction();
        try { $request=$this->requestForWebhook($sessionId,$reference); if (!$request) throw new RuntimeException('Payment request not found.');
            if ((string)$request['service_type']!=='pnl_export') throw new RuntimeException('Payment request is not a P&L entitlement.');
            if ((int)$request['amount_minor'] !== (int)$payment['amount_minor'] || strtoupper((string)$request['currency']) !== strtoupper((string)$payment['currency'])) throw new RuntimeException('Paid amount or currency did not match the server-authoritative request.');
            if ($request['status']==='paid') { $this->conn->commit(); return ['success'=>true,'idempotent'=>true,'payment_request_id'=>(int)$request['id']]; }
            if ($request['status']!=='pending') throw new RuntimeException('Payment request is not fulfillable.');
            $requestId=(int)$request['id']; $paymentId=(string)$payment['id']; $s=$this->conn->prepare("UPDATE billing_payment_requests SET status='paid',provider_payment_id=?,paid_at=NOW() WHERE id=?"); $s->bind_param('si',$paymentId,$requestId); $s->execute(); $s->close();
            $this->fulfillEntitlement($request,$eventId,$sessionId,$payment); $this->conn->commit(); return ['success'=>true,'payment_request_id'=>$requestId];
        } catch (Throwable $e) { $this->conn->rollback(); throw $e; }
    }
    /** Complete a customer order only after a server-to-server PayMongo lookup. */
    private function fulfillVerifiedCustomerOrderCheckout(string $sessionId,string $reference,array $payment,string $eventId): array {
        $this->conn->begin_transaction();
        try { $request=$this->requestForWebhook($sessionId,$reference); if (!$request) throw new RuntimeException('Payment request not found.');
            if ((string)$request['service_type']!=='order') throw new RuntimeException('Payment request is not an order checkout.');
            if ((int)$request['amount_minor'] !== (int)$payment['amount_minor'] || strtoupper((string)$request['currency']) !== strtoupper((string)$payment['currency'])) throw new RuntimeException('Paid amount or currency did not match the server-authoritative request.');
            if ($request['status']==='paid') { $this->conn->commit(); return ['success'=>true,'idempotent'=>true,'payment_request_id'=>(int)$request['id'],'order_id'=>(int)($request['order_id']??0)]; }
            if ($request['status']!=='pending') throw new RuntimeException('Payment request is not fulfillable.');
            $requestId=(int)$request['id']; $paymentId=(string)$payment['id']; $s=$this->conn->prepare("UPDATE billing_payment_requests SET status='paid',provider_payment_id=?,paid_at=NOW() WHERE id=?"); $s->bind_param('si',$paymentId,$requestId); $s->execute(); $s->close();
            $this->fulfillEntitlement($request,$eventId,$sessionId,$payment); $orderId=(int)($request['order_id']??0); $this->conn->commit(); return ['success'=>true,'payment_request_id'=>$requestId,'order_id'=>$orderId];
        } catch (Throwable $e) { $this->conn->rollback(); throw $e; }
    }
    /** Complete a HaCeRa entitlement only after a server-to-server PayMongo lookup. */
    private function fulfillVerifiedCheckout(string $sessionId,string $reference,array $payment,string $eventId): array {
        $this->conn->begin_transaction();
        try { $request=$this->requestForWebhook($sessionId,$reference); if (!$request) throw new RuntimeException('Payment request not found.');
            if (!in_array((string)$request['service_type'],[self::HACERA_PREMIUM_SERVICE,'hacera_report'],true)) throw new RuntimeException('Payment request is not a HaCeRa entitlement.');
            if ((int)$request['amount_minor'] !== (int)$payment['amount_minor'] || strtoupper((string)$request['currency']) !== strtoupper((string)$payment['currency'])) throw new RuntimeException('Paid amount or currency did not match the server-authoritative request.');
            if ($request['status']==='paid') { $this->conn->commit(); return ['success'=>true,'idempotent'=>true,'payment_request_id'=>(int)$request['id']]; }
            if ($request['status']!=='pending') throw new RuntimeException('Payment request is not fulfillable.');
            $requestId=(int)$request['id']; $paymentId=(string)$payment['id']; $s=$this->conn->prepare("UPDATE billing_payment_requests SET status='paid',provider_payment_id=?,paid_at=NOW() WHERE id=?"); $s->bind_param('si',$paymentId,$requestId); $s->execute(); $s->close();
            $this->fulfillEntitlement($request,$eventId,$sessionId,$payment); $this->conn->commit(); return ['success'=>true,'payment_request_id'=>$requestId];
        } catch (Throwable $e) { $this->conn->rollback(); throw $e; }
    }
    private function fulfillEntitlement(array &$request,string $eventId,string $sessionId,array $payment): void {
        $requestId=(int)$request['id']; $type=(string)$request['service_type'];
        if (in_array($type,[self::HACERA_PREMIUM_SERVICE,'hacera_report'],true)) { $s=$this->conn->prepare("UPDATE hacera_report_purchases SET delivery_status='ready' WHERE payment_request_id=?"); $s->bind_param('i',$requestId); $s->execute(); $s->close(); }
        elseif ($type==='pnl_export') { $s=$this->conn->prepare("UPDATE pnl_export_purchases SET delivery_status='ready' WHERE payment_request_id=?"); $s->bind_param('i',$requestId); $s->execute(); $s->close(); }
        elseif ($type==='inventory_storage_pack') { $meta=json_decode((string)$request['metadata_json'],true)?:[]; $days=max(1,(int)($meta['days']??$this->billingSettings()['inventory_pack_days'])); $s=$this->conn->prepare("UPDATE inventory_storage_packs SET status='active',activated_at=NOW(),expires_at=DATE_ADD(NOW(),INTERVAL ? DAY) WHERE payment_request_id=?"); $s->bind_param('ii',$days,$requestId); $s->execute(); $s->close(); }
        elseif ($type==='order') $request['order_id']=$this->fulfillOrder($request,$eventId,$sessionId);
        else throw new RuntimeException('Unsupported payment service type.');
        $this->recordRevenue($request,$payment);
    }

    private function fulfillOrder(array $request,string $eventId,string $sessionId): int {
        $orderId=(int)($request['order_id']??0);
        if ($orderId<=0) return $this->createPaidCustomerOrder($request,$eventId,$sessionId);
        $s=$this->conn->prepare('SELECT id,order_number,customer_id,restaurant_id,payment_verified_at FROM orders WHERE id=? FOR UPDATE'); $s->bind_param('i',$orderId); $s->execute(); $order=$s->get_result()->fetch_assoc(); $s->close(); if (!$order) throw new RuntimeException('Order for payment request not found.');
        if ($order['payment_verified_at']===null) { $receipt='HK-ORD-'.$order['order_number']; $s=$this->conn->prepare("UPDATE orders SET payment_status='paid',order_status='confirmed',payment_verified_at=NOW(),webhook_event_id=?,paymongo_checkout_session_id=?,receipt_number=?,receipt_issued_at=NOW() WHERE id=? AND payment_verified_at IS NULL"); $s->bind_param('sssi',$eventId,$sessionId,$receipt,$orderId); $s->execute(); $s->close();
            $legacy=$this->conn->prepare("UPDATE payments SET status='verified',paid_at=NOW() WHERE reference_id=? AND reference_type='order' AND transaction_reference=? AND status='pending'"); if ($legacy) { $legacy->bind_param('is',$orderId,$sessionId); $legacy->execute(); $legacy->close(); }
            $this->notifyPaidOrder($order);
            if (function_exists('logActivity')) logActivity($this->conn,(int)$order['customer_id'],'Payment Received','Order #'.$order['order_number'].' - signed PayMongo payment confirmed','payment');
            if ($this->tableExists('order_status_history')) { $history=$this->conn->prepare("INSERT INTO order_status_history (order_id,old_status,new_status,changed_by_role,notes,changed_at) VALUES (?,'pending','confirmed','system','Payment verified via signed PayMongo webhook',NOW())"); if ($history) { $history->bind_param('i',$orderId); $history->execute(); $history->close(); } }
        }
        return $orderId;
    }

    /** Materialize a real customer order atomically with the verified PayMongo payment. */
    private function createPaidCustomerOrder(array $request,string $eventId,string $sessionId): int {
        if (!$this->customerOrderCheckoutSchemaReady()) throw new RuntimeException('Customer checkout storage is not installed.');
        $requestId=(int)$request['id']; $s=$this->conn->prepare('SELECT * FROM customer_order_checkout_intents WHERE payment_request_id=? FOR UPDATE'); $s->bind_param('i',$requestId); $s->execute(); $intent=$s->get_result()->fetch_assoc(); $s->close();
        if (!$intent) throw new RuntimeException('Customer checkout intent was not found.');
        if (!empty($intent['order_id'])) return (int)$intent['order_id'];
        if ((int)$intent['customer_id']!==(int)$request['purchaser_user_id'] || (int)$intent['restaurant_id']!==(int)$request['restaurant_id']) throw new RuntimeException('Checkout intent did not match the payment request.');
        if (self::moneyMinor((float)$intent['total_amount']) !== (int)$request['amount_minor']) throw new RuntimeException('Checkout intent total did not match the payment request.');
        if ((string)$intent['status']!=='pending_payment') throw new RuntimeException('Customer checkout intent is not fulfillable.');
        $items=json_decode((string)$intent['items_json'],true);
        if (!is_array($items) || $items===[]) throw new RuntimeException('Checkout intent has no valid items.');
        foreach ($items as $item) if ((int)($item['id']??0)<=0 || (int)($item['qty']??0)<=0) throw new RuntimeException('Checkout intent has an invalid item.');
        $orderNumber=generateReference('ORD'); $customerId=(int)$intent['customer_id']; $restaurantId=(int)$intent['restaurant_id']; $itemsJson=(string)$intent['items_json']; $subtotal=(float)$intent['subtotal']; $deliveryFee=(float)$intent['delivery_fee']; $total=(float)$intent['total_amount']; $address=(string)($intent['delivery_address']??''); $service=(string)$intent['service_type']; $method=(string)$intent['payment_method']; $notes=(string)($intent['notes']??''); $table=(string)($intent['table_number']??''); $surveyId=(int)($intent['survey_response_id']??0); $platformFee=(float)$intent['platform_fee']; $platformRate=(float)$intent['platform_fee_rate']; $receipt='HK-ORD-'.$orderNumber;
        $insert=$this->conn->prepare("INSERT INTO orders (order_number,customer_id,restaurant_id,items_json,subtotal,delivery_fee,total_amount,delivery_address,service_type,payment_method,payment_status,order_status,notes,table_number,survey_response_id,discount_amount,platform_fee,platform_fee_rate,survey_verified,ordered_at,order_source,order_placed_via,payment_verified_at,webhook_event_id,paymongo_checkout_session_id,receipt_number,receipt_issued_at) VALUES (?,?,?,?,?,?,?,?,?,?,'paid','confirmed',?,?,?,?,0.00,?,?,1,NOW(),'customer','customer_web',NOW(),?,?,?,NOW())");
        if (!$insert) throw new RuntimeException('Could not prepare the paid order.');
        $insert->bind_param('siisdddsssssiddsss',$orderNumber,$customerId,$restaurantId,$itemsJson,$subtotal,$deliveryFee,$total,$address,$service,$method,$notes,$table,$surveyId,$platformFee,$platformRate,$eventId,$sessionId,$receipt);
        if (!$insert->execute()) { $error=$insert->error; $insert->close(); throw new RuntimeException('Could not create the paid order: '.$error); }
        $orderId=(int)$this->conn->insert_id; $insert->close();
        $inventory=new IngredientInventoryService($this->conn); $reserved=$inventory->reserveForOrder($orderId,$restaurantId,$items,$customerId,false);
        if (!$reserved['success']) throw new RuntimeException((string)$reserved['message']);
        $s=$this->conn->prepare("UPDATE customer_order_checkout_intents SET order_id=?,status='paid',paid_at=NOW() WHERE id=? AND order_id IS NULL"); $intentId=(int)$intent['id']; $s->bind_param('ii',$orderId,$intentId); $s->execute(); $s->close();
        $s=$this->conn->prepare('UPDATE billing_payment_requests SET order_id=? WHERE id=?'); $s->bind_param('ii',$orderId,$requestId); $s->execute(); $s->close();
        if ($this->tableExists('survey_order_tracking') && $surveyId>0) { $tracking=$this->conn->prepare('INSERT IGNORE INTO survey_order_tracking (survey_response_id,order_id,user_id,restaurant_id,order_total,linked_at) VALUES (?,?,?,?,?,NOW())'); if ($tracking) { $tracking->bind_param('iiiid',$surveyId,$orderId,$customerId,$restaurantId,$total); $tracking->execute(); $tracking->close(); } }
        $order=['id'=>$orderId,'order_number'=>$orderNumber,'customer_id'=>$customerId,'restaurant_id'=>$restaurantId]; $this->notifyPaidOrder($order);
        if (function_exists('logActivity')) logActivity($this->conn,$customerId,'Payment Received','Order #'.$orderNumber.' - signed PayMongo payment confirmed','payment');
        if ($this->tableExists('order_status_history')) { $history=$this->conn->prepare("INSERT INTO order_status_history (order_id,old_status,new_status,changed_by_role,notes,changed_at) VALUES (?,NULL,'confirmed','system','Order created after signed PayMongo payment verification',NOW())"); if ($history) { $history->bind_param('i',$orderId); $history->execute(); $history->close(); } }
        return $orderId;
    }

    private function notifyPaidOrder(array $order): void {
        if (!function_exists('createNotification')) return;
        createNotification($this->conn,(int)$order['customer_id'],'Payment Confirmed: '.$order['order_number'],'Your online payment was confirmed. The restaurant can now prepare your order.','success',BASE_URL.'dashboard/customer/orders.php');
        $s=$this->conn->prepare('SELECT business_owner_id FROM halal_restaurants WHERE id=?'); $s->bind_param('i',$order['restaurant_id']); $s->execute(); $owner=(int)($s->get_result()->fetch_assoc()['business_owner_id']??0); $s->close();
        if ($owner) createNotification($this->conn,$owner,'New Paid Order: '.$order['order_number'],'PayMongo payment confirmed.','success',BASE_URL.'dashboard/business_owner/manage_orders.php');
        $cashiers=$this->conn->prepare("SELECT rs.user_id FROM restaurant_staff rs JOIN restaurant_staff_roles rr ON rr.id=rs.staff_role_id WHERE rs.restaurant_id=? AND rs.is_active=1 AND rr.role_key='cashier'");
        if ($cashiers) { $cashiers->bind_param('i',$order['restaurant_id']); $cashiers->execute(); $cashierRows=$cashiers->get_result(); while ($cashier=$cashierRows->fetch_assoc()) createNotification($this->conn,(int)$cashier['user_id'],'New Paid Order: '.$order['order_number'],'Customer payment is verified. Please process the order.','action_required',BASE_URL.'dashboard/business_owner/pos_order_processing.php'); $cashiers->close(); }
    }
    private function recordRevenue(array $request,array $payment): void {
        $requestId=(int)$request['id']; $type=(string)$request['service_type']; $gross=(float)$request['amount']; $restaurantAmount=0.0; $platformRevenue=$gross; $orderId=$request['order_id']!==null?(int)$request['order_id']:null;
        if ($type==='order' && $orderId) { $s=$this->conn->prepare('SELECT platform_fee FROM orders WHERE id=?'); $s->bind_param('i',$orderId); $s->execute(); $order=$s->get_result()->fetch_assoc(); $s->close(); $platformRevenue=round((float)($order['platform_fee']??0),2); $restaurantAmount=round($gross-$platformRevenue,2); }
        $processorFee=round((int)($payment['fee_minor']??0)/100,2); $restaurantId=$request['restaurant_id']!==null?(int)$request['restaurant_id']:null;
        $s=$this->conn->prepare("INSERT INTO halal_keeps_revenue_ledger (payment_request_id,restaurant_id,order_id,entry_type,gross_amount,restaurant_amount,platform_revenue,processor_fee,status,recognized_at) VALUES (?,?,?,?,?,?,?,?,'recognized',NOW()) ON DUPLICATE KEY UPDATE updated_at=NOW()"); $s->bind_param('iiisdddd',$requestId,$restaurantId,$orderId,$type,$gross,$restaurantAmount,$platformRevenue,$processorFee); $s->execute(); $s->close();
    }

    private function processRefund(int $webhookId,string $paymentId,string $sessionId,string $reference): array {
        $this->conn->begin_transaction();
        try { $s=$this->conn->prepare('SELECT * FROM billing_payment_requests WHERE provider_payment_id=? OR checkout_session_id=? OR internal_reference=? LIMIT 1 FOR UPDATE'); $s->bind_param('sss',$paymentId,$sessionId,$reference); $s->execute(); $request=$s->get_result()->fetch_assoc(); $s->close(); if (!$request) throw new RuntimeException('Refund request not found.'); $requestId=(int)$request['id'];
            $s=$this->conn->prepare("UPDATE billing_payment_requests SET status='refunded',refunded_at=NOW() WHERE id=?"); $s->bind_param('i',$requestId); $s->execute(); $s->close(); foreach (['hacera_report_purchases','pnl_export_purchases'] as $table) $this->conn->query("UPDATE `$table` SET delivery_status='refunded' WHERE payment_request_id=$requestId"); $this->conn->query("UPDATE inventory_storage_packs SET status='refunded' WHERE payment_request_id=$requestId"); if ($request['service_type']==='order') { if ($this->customerOrderCheckoutSchemaReady()) $this->conn->query("UPDATE customer_order_checkout_intents SET status='refunded' WHERE payment_request_id=$requestId"); if ($request['order_id']) { $oid=(int)$request['order_id']; $this->conn->query("UPDATE orders SET payment_status='refunded',order_status='cancelled' WHERE id=$oid"); } }
            $s=$this->conn->prepare("UPDATE halal_keeps_revenue_ledger SET status='refunded',refunded_amount=gross_amount,refunded_at=NOW() WHERE payment_request_id=?"); $s->bind_param('i',$requestId); $s->execute(); $s->close(); $this->conn->commit(); $this->finishWebhook($webhookId,$requestId,'processed',null); return ['success'=>true,'refunded'=>true];
        } catch (Throwable $e) { $this->conn->rollback(); throw $e; }
    }

    private function requestForWebhook(string $sessionId,string $reference): ?array {
        $s=$this->conn->prepare('SELECT * FROM billing_payment_requests WHERE checkout_session_id=? OR internal_reference=? LIMIT 1 FOR UPDATE'); $s->bind_param('ss',$sessionId,$reference); $s->execute(); $row=$s->get_result()->fetch_assoc(); $s->close(); if (!$row) return null; if (!hash_equals((string)$row['checkout_session_id'],$sessionId)) return null; if ($reference!==''&&!hash_equals((string)$row['internal_reference'],$reference)) return null; return $row;
    }
    private function finishWebhook(int $webhookId,?int $requestId,string $status,?string $error): void { $s=$this->conn->prepare('UPDATE paymongo_webhook_events SET payment_request_id=?,processing_status=?,error_message=?,processed_at=NOW() WHERE id=?'); $s->bind_param('issi',$requestId,$status,$error,$webhookId); $s->execute(); $s->close(); }
    public function authorizeReportDownload(int $userId,int $restaurantId,string $type,int $purchaseId): ?array {
        $table=$type==='hacera'?'hacera_report_purchases':($type==='pnl'?'pnl_export_purchases':''); if ($table==='') return null;
        if (($type==='hacera'&&!$this->isActualOwner($userId,$restaurantId))||($type==='pnl'&&!$this->canManageBilling($userId,$restaurantId))) return null;
        $serviceCondition=$type==='hacera' ? " AND b.service_type IN ('hacera_premium_report','hacera_report')" : " AND b.service_type='pnl_export'";
        $s=$this->conn->prepare("SELECT p.*,b.internal_reference,b.amount,b.paid_at,b.status payment_status FROM `$table` p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.id=? AND p.restaurant_id=? AND p.delivery_status='ready' AND b.status='paid'{$serviceCondition} LIMIT 1"); $s->bind_param('ii',$purchaseId,$restaurantId); $s->execute(); $row=$s->get_result()->fetch_assoc(); $s->close(); return $row?:null;
    }
    public function recentPurchases(int $restaurantId,int $userId): array {
        if (!$this->canManageBilling($userId,$restaurantId)) return [];
        $sql="SELECT b.id,b.service_type,b.amount,b.status,b.internal_reference,b.paid_at,b.created_at,p.id pnl_purchase_id,p.delivery_status pnl_delivery_status FROM billing_payment_requests b LEFT JOIN pnl_export_purchases p ON p.payment_request_id=b.id WHERE b.restaurant_id=? ORDER BY b.created_at DESC LIMIT 50";
        $s=$this->conn->prepare($sql); $s->bind_param('i',$restaurantId); $s->execute(); $rows=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $rows;
    }
    /** Current version if no receipt is specified; a named paid receipt otherwise. */
    public function getHaceraPremiumAccess(int $userId,int $restaurantId,int $purchaseId=0): ?array {
        if (!$this->isActualOwner($userId,$restaurantId) || !$this->schemaReady()) return null;
        if ($purchaseId > 0) return $this->authorizeReportDownload($userId,$restaurantId,'hacera',$purchaseId);
        $snapshot=$this->buildHaceraSnapshot($restaurantId);
        $hash=self::haceraAssessmentVersionHash($snapshot);
        $s=$this->conn->prepare("SELECT p.*,b.internal_reference,b.amount,b.paid_at,b.status payment_status FROM hacera_report_purchases p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.snapshot_hash=? AND p.delivery_status='ready' AND b.status='paid' AND b.service_type IN ('hacera_premium_report','hacera_report') ORDER BY p.id DESC LIMIT 1");
        $s->bind_param('is',$restaurantId,$hash); $s->execute(); $row=$s->get_result()->fetch_assoc(); $s->close();
        return $row?:null;
    }
    public function hasPaidHaCeRaAccess(int $userId,int $restaurantId,int $purchaseId=0): bool {
        return $this->getHaceraPremiumAccess($userId,$restaurantId,$purchaseId)!==null;
    }
    /** Used by the queue worker to avoid provider calls after a refund/revocation. */
    public function hasActiveHaceraAssessmentVersion(int $restaurantId,string $assessmentVersionHash): bool {
        if (!$this->schemaReady() || !preg_match('/^[a-f0-9]{64}$/',$assessmentVersionHash)) return false;
        $s=$this->conn->prepare("SELECT 1 FROM hacera_report_purchases p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.snapshot_hash=? AND p.delivery_status='ready' AND b.status='paid' AND b.service_type IN ('hacera_premium_report','hacera_report') LIMIT 1");
        $s->bind_param('is',$restaurantId,$assessmentVersionHash); $s->execute(); $ok=$s->get_result()->num_rows>0; $s->close(); return $ok;
    }
    private function findPendingStoragePack(int $restaurantId,int $userId): ?array {
        $s=$this->conn->prepare("SELECT p.id,p.payment_request_id,b.amount,b.provider_response FROM inventory_storage_packs p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.purchaser_user_id=? AND p.status='pending_payment' AND b.status='pending' AND b.service_type='inventory_storage_pack' ORDER BY p.id DESC LIMIT 1"); $s->bind_param('ii',$restaurantId,$userId); $s->execute(); $row=$s->get_result()->fetch_assoc(); $s->close(); return $row?:null;
    }
    private function findReadyPnlReportForPeriod(int $restaurantId,string $start,string $end): ?array {
        $s=$this->conn->prepare("SELECT p.id FROM pnl_export_purchases p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.period_start=? AND p.period_end=? AND p.delivery_status='ready' AND b.status='paid' AND b.service_type='pnl_export' ORDER BY p.id DESC LIMIT 1"); $s->bind_param('iss',$restaurantId,$start,$end); $s->execute(); $row=$s->get_result()->fetch_assoc(); $s->close(); return $row?:null;
    }
    private function findPendingPnlPurchase(int $restaurantId,string $start,string $end): ?array {
        $s=$this->conn->prepare("SELECT p.id,p.payment_request_id,b.amount,b.provider_response FROM pnl_export_purchases p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.period_start=? AND p.period_end=? AND p.delivery_status='pending_payment' AND b.status='pending' AND b.service_type='pnl_export' ORDER BY p.id DESC LIMIT 1"); $s->bind_param('iss',$restaurantId,$start,$end); $s->execute(); $row=$s->get_result()->fetch_assoc(); $s->close(); return $row?:null;
    }
    private function findReadyReport(string $table,int $restaurantId,string $hash): ?array {
        $serviceCondition=$table==='hacera_report_purchases' ? " AND b.service_type IN ('hacera_premium_report','hacera_report')" : " AND b.service_type='pnl_export'";
        $s=$this->conn->prepare("SELECT p.id FROM `$table` p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.snapshot_hash=? AND p.delivery_status='ready' AND b.status='paid'{$serviceCondition} ORDER BY p.id DESC LIMIT 1"); $s->bind_param('is',$restaurantId,$hash); $s->execute(); $row=$s->get_result()->fetch_assoc(); $s->close(); return $row?:null;
    }
    private function findPendingHaceraPurchase(int $restaurantId,string $hash): ?array {
        $s=$this->conn->prepare("SELECT p.id,p.payment_request_id,b.amount,b.provider_response FROM hacera_report_purchases p JOIN billing_payment_requests b ON b.id=p.payment_request_id WHERE p.restaurant_id=? AND p.snapshot_hash=? AND p.delivery_status='pending_payment' AND b.status='pending' AND b.service_type IN ('hacera_premium_report','hacera_report') ORDER BY p.id DESC LIMIT 1");
        $s->bind_param('is',$restaurantId,$hash); $s->execute(); $row=$s->get_result()->fetch_assoc(); $s->close(); return $row?:null;
    }
    private function checkoutUrlFromRequest(array $pending): string {
        $decoded=json_decode((string)($pending['provider_response']??''),true);
        return is_array($decoded) ? (string)($decoded['data']['attributes']['checkout_url']??'') : '';
    }
    private function haceraDownloadUrl(int $purchaseId): string {
        return BASE_URL.'dashboard/business_owner/api/hacera_api.php?action=generate_pdf&purchase_id='.$purchaseId;
    }

    private function buildHaceraSnapshot(int $restaurantId): array {
        $s=$this->conn->prepare('SELECT restaurant_name,address,phone FROM halal_restaurants WHERE id=?'); $s->bind_param('i',$restaurantId); $s->execute(); $restaurant=$s->get_result()->fetch_assoc()?:[]; $s->close();
        $responses=[]; $docs=[]; $r=$this->conn->query("SELECT checklist_item_id,response_value,notes FROM business_hacera_responses WHERE restaurant_id=$restaurantId ORDER BY checklist_item_id"); if ($r) $responses=$r->fetch_all(MYSQLI_ASSOC); $r=$this->conn->query("SELECT checklist_item_id,original_name,file_path,uploaded_at FROM hacera_documents WHERE restaurant_id=$restaurantId ORDER BY checklist_item_id,uploaded_at"); if ($r) $docs=$r->fetch_all(MYSQLI_ASSOC);
        $docIds=array_values(array_unique(array_map(fn($d)=>(int)$d['checklist_item_id'],$docs))); $completed=HaCeRaCalculator::buildCompletedIds($responses,$docIds); $scores=[]; foreach (array_keys(HaCeRaCalculator::getModules()) as $moduleId) $scores[$moduleId]=HaCeRaCalculator::scoreModule($moduleId,$completed); $hrp=HaCeRaCalculator::computeHRP($scores);
        return ['kind'=>'hacera','restaurant'=>$restaurant,'responses'=>$responses,'documents'=>$docs,'completed_ids'=>$completed,'scores'=>['hrp'=>$hrp,'band'=>HaCeRaCalculator::getBand($hrp),'module_scores'=>$scores]];
    }
    /** Version only assessment answers and evidence, not display metadata. */
    public static function haceraAssessmentVersionHash(array $snapshot): string {
        $responses=[];
        foreach (($snapshot['responses']??[]) as $row) $responses[]=['checklist_item_id'=>(int)($row['checklist_item_id']??0),'response_value'=>(string)($row['response_value']??''),'notes'=>(string)($row['notes']??'')];
        usort($responses,static fn(array $a,array $b): int => [$a['checklist_item_id'],$a['response_value'],$a['notes']] <=> [$b['checklist_item_id'],$b['response_value'],$b['notes']]);
        $documents=[];
        foreach (($snapshot['documents']??[]) as $row) $documents[]=['checklist_item_id'=>(int)($row['checklist_item_id']??0),'file_path'=>(string)($row['file_path']??''),'original_name'=>(string)($row['original_name']??'')];
        usort($documents,static fn(array $a,array $b): int => [$a['checklist_item_id'],$a['file_path'],$a['original_name']] <=> [$b['checklist_item_id'],$b['file_path'],$b['original_name']]);
        $version=['kind'=>'hacera','responses'=>$responses,'documents'=>$documents];
        return hash('sha256',json_encode($version,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)?:'{}');
    }

    private function buildPnlSnapshot(int $restaurantId,string $start,string $end): array {
        $restaurant=$this->conn->query("SELECT restaurant_name,address FROM halal_restaurants WHERE id=$restaurantId")->fetch_assoc()?:[];
        $s=$this->conn->prepare("SELECT COALESCE(SUM(total_amount-delivery_fee-COALESCE(platform_fee,0)),0) restaurant_sales,COALESCE(SUM(delivery_fee),0) delivery_fees,COALESCE(SUM(platform_fee),0) platform_fees_collected,COALESCE(SUM(total_amount),0) gross_collected,COUNT(*) paid_order_count FROM orders WHERE restaurant_id=? AND payment_status='paid' AND DATE(payment_verified_at) BETWEEN ? AND ?"); $s->bind_param('iss',$restaurantId,$start,$end); $s->execute(); $orders=$s->get_result()->fetch_assoc(); $s->close();
        $expenses=0.0; $inflows=0.0;
        if ($this->tableExists('restaurant_expenses')) { $s=$this->conn->prepare('SELECT COALESCE(SUM(amount),0) total FROM restaurant_expenses WHERE restaurant_id=? AND expense_date BETWEEN ? AND ?'); $s->bind_param('iss',$restaurantId,$start,$end); $s->execute(); $expenses=(float)($s->get_result()->fetch_assoc()['total']??0); $s->close(); }
        if ($this->tableExists('restaurant_other_inflows')) { $s=$this->conn->prepare("SELECT COALESCE(SUM(amount),0) total FROM restaurant_other_inflows WHERE restaurant_id=? AND status='approved' AND inflow_date BETWEEN ? AND ?"); $s->bind_param('iss',$restaurantId,$start,$end); $s->execute(); $inflows=(float)($s->get_result()->fetch_assoc()['total']??0); $s->close(); }
        $sales=round((float)$orders['restaurant_sales'],2); $delivery=round((float)$orders['delivery_fees'],2);
        return ['kind'=>'pnl','restaurant'=>$restaurant,'period_start'=>$start,'period_end'=>$end,'paid_order_count'=>(int)$orders['paid_order_count'],'restaurant_sales'=>$sales,'other_inflows'=>round($inflows,2),'revenue'=>round($sales+$inflows,2),'delivery_expense'=>$delivery,'operating_expenses'=>round($expenses,2),'expenses'=>round($delivery+$expenses,2),'net_cash_flow'=>round($sales+$inflows-$delivery-$expenses,2),'platform_fees_excluded'=>round((float)$orders['platform_fees_collected'],2),'gross_customer_collected'=>round((float)$orders['gross_collected'],2),'is_estimated'=>true,'disclaimer'=>'This management P&L is a data-driven operating estimate, not an audited financial statement. Platform fees are excluded from restaurant revenue.','captured_at'=>gmdate('c')];
    }

    private function validDateRange(string $start,string $end): bool { $a=DateTimeImmutable::createFromFormat('Y-m-d',$start); $b=DateTimeImmutable::createFromFormat('Y-m-d',$end); return $a&&$b&&$a->format('Y-m-d')===$start&&$b->format('Y-m-d')===$end&&$a<=$b&&$a->diff($b)->days<=366; }
    private function canManageBilling(int $userId,int $restaurantId): bool {
        if ($this->isActualOwner($userId,$restaurantId)) return true; $s=$this->conn->prepare("SELECT 1 FROM restaurant_staff rs JOIN restaurant_staff_roles rr ON rr.id=rs.staff_role_id WHERE rs.restaurant_id=? AND rs.user_id=? AND rs.is_active=1 AND rr.role_key='ops_manager' LIMIT 1"); if (!$s) return false; $s->bind_param('ii',$restaurantId,$userId); $s->execute(); $ok=$s->get_result()->num_rows>0; $s->close(); return $ok;
    }
    private function canProcessPosCheckout(int $userId,int $restaurantId): bool {
        if ($this->isActualOwner($userId,$restaurantId)) return true;
        $s=$this->conn->prepare("SELECT 1 FROM restaurant_staff rs JOIN restaurant_staff_roles rr ON rr.id=rs.staff_role_id WHERE rs.restaurant_id=? AND rs.user_id=? AND rs.is_active=1 AND rr.role_key='cashier' LIMIT 1");
        if (!$s) return false; $s->bind_param('ii',$restaurantId,$userId); $s->execute(); $ok=$s->get_result()->num_rows>0; $s->close(); return $ok;
    }
    private function isActualOwner(int $userId,int $restaurantId): bool { $s=$this->conn->prepare('SELECT 1 FROM halal_restaurants WHERE id=? AND business_owner_id=? LIMIT 1'); $s->bind_param('ii',$restaurantId,$userId); $s->execute(); $ok=$s->get_result()->num_rows>0; $s->close(); return $ok; }
    private function customerOrderCheckoutSchemaReady(): bool { return $this->tableExists('customer_order_checkout_intents'); }
    private function tableExists(string $table): bool { $s=$this->conn->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'); $s->bind_param('s',$table); $s->execute(); $ok=$s->get_result()->num_rows>0; $s->close(); return $ok; }
    private function stableJson(array $data): string { return json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)?:'{}'; }
    private function error(string $message,int $status=422): array { return ['success'=>false,'message'=>$message,'http_status'=>$status]; }
}