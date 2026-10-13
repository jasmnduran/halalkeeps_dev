# Customer Order Integration - Deployment & Testing Guide

## Overview
This guide covers the end-to-end deployment and testing of the integrated customer dine-in ordering system with mandatory TPB survey gate, PayMongo payment processing, cashier POS workflow, and business owner revenue tracking.

---

## Pre-Deployment Checklist

### 1. Database Migration
Run the integration migration to add required tables and columns:

```bash
# Connect to MySQL
mysql -u root -p halal_system

# Run the migration
source database/order_survey_integration_migration.sql
```

**Verify migration success:**
```sql
-- Check new columns in orders table
DESCRIBE orders;
-- Should show: table_number, survey_response_id, survey_verified, ordered_at, 
--              payment_verified_at, revenue_counted, webhook_event_id, order_source, order_placed_via

-- Check new tables exist
SHOW TABLES LIKE '%cashier%';
SHOW TABLES LIKE '%survey_order%';
SHOW TABLES LIKE '%revenue_summary%';
SHOW TABLES LIKE '%status_history%';
```

### 2. Environment Configuration
Ensure your `.env` file has PayMongo credentials:

```env
PAYMONGO_SECRET_KEY=sk_test_your_key_here
PAYMONGO_PUBLIC_KEY=pk_test_your_key_here
PAYMONGO_WEBHOOK_SECRET=whsec_your_webhook_secret
```

### 3. PayMongo Webhook Setup
Configure webhook in PayMongo Dashboard (https://dashboard.paymongo.com/developers):

**Webhook URL:** `https://yourdomain.com/api/paymongo_webhook.php`

**Events to listen:**
- `checkout_session.payment.paid`
- `payment.paid`
- `payment.failed`

Copy the webhook signing secret to your `.env` file.

---

## End-to-End Testing Workflow

### Phase 1: Customer Survey Completion

**Test Case 1.1: New Customer Without Survey**

1. **Login as Customer**
   - Navigate to: `dashboard/customer/`
   - Use customer account credentials

2. **Browse Restaurant**
   - Go to: `dashboard/customer/restaurants.php`
   - Click on any restaurant

3. **Add Items to Cart**
   - Click "Add to Cart" on 2-3 menu items
   - Verify floating cart badge shows item count

4. **Attempt Checkout**
   - Click floating cart button
   - Select "Dine-In" service type
   - Enter table number (e.g., "Table 5")
   - Select payment method (GCash or Bank Transfer)
   - Click "Place Order"

5. **Verify Survey Gate**
   - ✅ Should show alert: "Survey required: Please complete the Customer Demand Survey before placing an order."
   - ✅ Should offer redirect to survey page
   - ✅ Order should NOT be created yet

**Expected Database State:**
```sql
SELECT * FROM orders WHERE customer_id = <your_user_id> ORDER BY created_at DESC LIMIT 1;
-- Should return 0 rows (no order created)
```

---

**Test Case 1.2: Complete TPB Survey**

1. **Navigate to Survey**
   - Go to: `dashboard/customer/tpb_survey.php`
   - Or accept redirect from checkout

2. **Fill Survey Form**
   - Answer all 15 questions (ATT1-5, SN1-5, PBC1-5)
   - Use Likert scale 1-5 for each question
   - Submit survey

3. **Verify Survey Saved**
   ```sql
   SELECT id, user_id, questionnaire_set, mean_att, mean_sn, mean_pbc, norm_pi, created_at
   FROM survey_responses
   WHERE user_id = <your_user_id>
   ORDER BY created_at DESC LIMIT 1;
   ```
   - ✅ Should show survey record with calculated metrics

---

### Phase 2: Order Placement with Payment

**Test Case 2.1: Place Order After Survey**

1. **Return to Restaurant Page**
   - Navigate back to restaurant
   - Cart should still have items (localStorage persistence)

2. **Proceed to Checkout**
   - Click cart button
   - Select "Dine-In"
   - Enter table number: "Table 7"
   - Select payment method: "GCash"
   - Click "Place Order"

3. **Verify Order Created**
   - ✅ Should NOT show survey requirement message
   - ✅ Should show "Redirecting to payment..." or similar
   - ✅ Should redirect to PayMongo checkout page

**Expected Database State:**
```sql
SELECT o.id, o.order_number, o.customer_id, o.restaurant_id, o.table_number,
       o.service_type, o.payment_method, o.payment_status, o.order_status,
       o.survey_response_id, o.survey_verified, o.order_source, o.total_amount
FROM orders o
WHERE o.customer_id = <your_user_id>
ORDER BY o.created_at DESC LIMIT 1;
```

**Verify:**
- ✅ `order_status` = 'pending'
- ✅ `payment_status` = 'pending'
- ✅ `survey_response_id` = (your survey response ID)
- ✅ `survey_verified` = 1
- ✅ `order_source` = 'customer'
- ✅ `order_placed_via` = 'customer_web'
- ✅ `table_number` = 'Table 7'

**Check Survey-Order Link:**
```sql
SELECT * FROM survey_order_tracking
WHERE order_id = <order_id_from_above>;
```
- ✅ Should show link between survey and order

---

**Test Case 2.2: PayMongo Payment Flow**

**For Testing (without actual payment):**
You can simulate payment success by manually updating the order:

```sql
-- Simulate webhook payment verification
UPDATE orders
SET payment_status = 'paid',
    order_status = 'confirmed',
    payment_verified_at = NOW(),
    webhook_event_id = 'test_webhook_123'
WHERE id = <order_id>;
```

**For Production Testing:**
1. Complete PayMongo checkout with test card
2. PayMongo redirects back to callback page
3. Webhook fires and updates order

---

### Phase 3: Cashier Notification & Order Processing

**Test Case 3.1: Cashier Receives Notification**

1. **Login as Cashier**
   - Use business owner account or assigned cashier account
   - Navigate to: `dashboard/business_owner/pos_order_processing.php`

2. **Verify Customer Orders Queue**
   - ✅ "Customer Orders Queue" card should appear
   - ✅ Badge should show "1" (or count of pending orders)
   - ✅ Order should appear in list with:
     - Order number
     - Table number (Table 7)
     - Customer name
     - Service type (🍽️ dine-in)
     - Total amount
     - "PAID" badge (green checkmark)
     - Status badge (confirmed)

**Expected Database State:**
```sql
SELECT * FROM cashier_notifications
WHERE order_id = <order_id>
AND is_read = 0;
```
- ✅ Should show notification record(s)

3. **Check Auto-Refresh**
   - Wait 30 seconds
   - ✅ Page should auto-refresh customer orders
   - ✅ No console errors

---

**Test Case 3.2: Update Order Status**

1. **Click on Order in POS**
   - Click the order row
   - ✅ Modal should open showing:
     - Full order details
     - Customer name
     - Table number
     - Itemized list
     - Subtotal/Total
     - Status action buttons

2. **Progress Through Status Workflow**

   **Step 1: Start Preparing**
   - Click "Start Preparing" button
   - ✅ Status should update to "preparing"
   - ✅ Modal should close
   - ✅ Order list should refresh

   **Verify Database:**
   ```sql
   SELECT order_status FROM orders WHERE id = <order_id>;
   -- Should show: preparing
   
   SELECT * FROM order_status_history
   WHERE order_id = <order_id>
   ORDER BY changed_at DESC LIMIT 1;
   -- Should show: old_status='confirmed', new_status='preparing'
   ```

   **Step 2: Mark Ready**
   - Click order again
   - Click "Mark Ready"
   - ✅ Status updates to "ready"

   **Step 3: Complete Order**
   - Click order again
   - Click "Complete Order"
   - ✅ Status updates to "completed"
   - ✅ Order should disappear from active queue (filters completed orders)

---

### Phase 4: Customer Order Tracking

**Test Case 4.1: Real-Time Status Updates**

1. **Login as Customer (Different Browser/Incognito)**
   - Navigate to: `dashboard/customer/orders.php`

2. **Find Your Order**
   - ✅ Order should appear in list
   - ✅ Shows current status badge

3. **Monitor Real-Time Updates**
   - Keep orders page open
   - Have cashier update order status (from another browser)
   - ✅ Within 15 seconds, customer page should auto-update status
   - ✅ No page refresh needed

4. **Check Notifications**
   - Click notifications bell icon
   - ✅ Should show notifications like:
     - "Payment Confirmed!"
     - "Order Confirmed"
     - "Order Being Prepared"
     - "Order Ready!"
     - "Order Completed"

**Verify Polling:**
```javascript
// In browser console on orders.php
console.log(activeOrderIds); // Should show array of order IDs
```

---

### Phase 5: Business Owner Revenue & Analytics

**Test Case 5.1: Verify Revenue Counted**

1. **Login as Business Owner**
   - Navigate to: `dashboard/business_owner/owner_dashboard.php`

2. **Check Today's Revenue**
   - ✅ "Revenue Today" metric should include your order total
   - ✅ Should match: `total_amount - delivery_fee` from order

3. **Verify Database Calculation**
   ```sql
   -- Check today's paid orders
   SELECT 
       COUNT(*) as order_count,
       SUM(total_amount - delivery_fee) as revenue,
       SUM(CASE WHEN order_source='customer' THEN 1 ELSE 0 END) as customer_orders
   FROM orders
   WHERE restaurant_id = <restaurant_id>
     AND payment_status = 'paid'
     AND DATE(payment_verified_at) = CURDATE();
   ```
   - ✅ Should show your order in the count
   - ✅ Revenue should be calculated correctly

4. **Check Financial Report Tab**
   - Click "Financial Report" tab
   - ✅ Should show order in today's transactions
   - ✅ Net Cash Flow should update

5. **Verify Hourly Chart**
   - ✅ Hourly revenue chart should show spike at payment verification hour
   - ✅ Based on `payment_verified_at` timestamp

---

**Test Case 5.2: Payment Idempotency**

**Simulate Duplicate Webhook:**
```sql
-- Try to process same order again
UPDATE orders
SET payment_verified_at = NULL
WHERE id = <order_id>;

-- Now try to "verify" again
UPDATE orders
SET payment_status = 'paid',
    order_status = 'confirmed',
    payment_verified_at = NOW()
WHERE id = <order_id>
  AND payment_verified_at IS NULL;

-- Check affected rows
SELECT ROW_COUNT(); -- Should return 1

-- Try again (should fail due to WHERE clause)
UPDATE orders
SET payment_status = 'paid',
    order_status = 'confirmed',
    payment_verified_at = NOW()
WHERE id = <order_id>
  AND payment_verified_at IS NULL;

SELECT ROW_COUNT(); -- Should return 0 (idempotent)
```

**Check Webhook Logs:**
```sql
SELECT * FROM paymongo_webhook_logs
WHERE order_id = <order_id>
ORDER BY received_at DESC;
```
- ✅ Should show webhook processing history
- ✅ `processed` flag should be 1

---

### Phase 6: TPB Analytics Integration

**Test Case 6.1: Survey-Order Linkage**

```sql
-- Check survey effectiveness
SELECT 
    sr.id as survey_id,
    sr.questionnaire_set,
    sr.mean_att,
    sr.mean_sn,
    sr.mean_pbc,
    sr.norm_pi,
    COUNT(sot.order_id) as resulting_orders,
    SUM(sot.order_total) as total_revenue
FROM survey_responses sr
LEFT JOIN survey_order_tracking sot ON sr.id = sot.survey_response_id
WHERE sr.user_id = <user_id>
GROUP BY sr.id;
```

**Expected Results:**
- ✅ Survey record linked to order(s)
- ✅ Shows TPB metrics (ATT, SN, PBC, PI)
- ✅ Shows revenue generated from survey respondent

---

## Complete Workflow Checklist

Use this checklist to verify the entire integrated system:

### Customer Journey
- [ ] Customer browses restaurants
- [ ] Customer adds items to cart
- [ ] Customer attempts checkout
- [ ] System blocks if survey not completed
- [ ] Customer completes TPB survey (15 questions)
- [ ] Survey data saved with calculated metrics
- [ ] Customer returns to checkout
- [ ] Customer enters table number (dine-in)
- [ ] Order created with survey linkage
- [ ] Customer redirected to PayMongo payment
- [ ] Payment processed successfully
- [ ] Customer sees payment confirmation
- [ ] Customer redirected to order history
- [ ] Order appears with "confirmed" status
- [ ] Status updates in real-time as cashier processes

### Cashier Journey
- [ ] Cashier logs into POS
- [ ] Customer order appears in queue automatically
- [ ] Shows PAID badge and table number
- [ ] Cashier clicks order to see details
- [ ] Cashier updates status: confirmed → preparing
- [ ] Customer receives notification
- [ ] Cashier updates status: preparing → ready
- [ ] Cashier updates status: ready → completed
- [ ] Order moves out of active queue
- [ ] All status changes recorded in audit log

### Business Owner Journey
- [ ] Owner logs into dashboard
- [ ] Revenue metrics show customer order
- [ ] Today's revenue updated immediately after payment
- [ ] Customer vs POS order breakdown visible
- [ ] Financial report includes order
- [ ] Hourly chart shows revenue spike
- [ ] Net cash flow calculated correctly
- [ ] No duplicate revenue counting

### System Integrity
- [ ] Survey prevents order placement if incomplete
- [ ] Payment verification is idempotent
- [ ] Duplicate webhooks don't create duplicate revenue
- [ ] Status transitions follow valid workflow
- [ ] Customer can only update own orders
- [ ] Cashier can only access restaurant orders
- [ ] All database constraints enforced
- [ ] No SQL injection vulnerabilities
- [ ] Proper error handling on failures

---

## Troubleshooting

### Issue: Survey not blocking checkout
**Check:**
```sql
SELECT * FROM survey_responses WHERE user_id = <user_id>;
```
If survey exists but still blocking, check `place_order.php` line ~155 for survey verification logic.

### Issue: Payment webhook not updating order
**Check webhook logs:**
```sql
SELECT * FROM paymongo_webhook_logs ORDER BY received_at DESC LIMIT 10;
```

**Check PayMongo dashboard:**
- Webhook delivery status
- Response codes

**Verify webhook URL is accessible:**
```bash
curl -X POST https://yourdomain.com/api/paymongo_webhook.php
```

### Issue: Cashier not seeing customer orders
**Check restaurant assignment:**
```sql
SELECT * FROM restaurant_staff WHERE user_id = <cashier_user_id>;
```

**Check order filtering:**
```sql
SELECT * FROM orders 
WHERE restaurant_id = <restaurant_id>
  AND order_source = 'customer'
  AND payment_status = 'paid'
  AND order_status NOT IN ('completed', 'cancelled');
```

### Issue: Revenue not updating
**Check payment_verified_at timestamp:**
```sql
SELECT id, order_number, payment_status, payment_verified_at, total_amount
FROM orders
WHERE payment_status = 'paid'
  AND payment_verified_at IS NULL;
```

If orders are paid but `payment_verified_at` is NULL, manually set:
```sql
UPDATE orders 
SET payment_verified_at = created_at
WHERE payment_status = 'paid' 
  AND payment_verified_at IS NULL;
```

---

## Performance Optimization

### For High-Traffic Restaurants

1. **Enable Query Cache:**
```sql
SET GLOBAL query_cache_type = ON;
SET GLOBAL query_cache_size = 67108864; -- 64MB
```

2. **Add Composite Indexes:**
```sql
CREATE INDEX idx_orders_restaurant_payment 
ON orders(restaurant_id, payment_status, payment_verified_at);

CREATE INDEX idx_orders_customer_payment 
ON orders(customer_id, payment_status, order_status);
```

3. **Populate Revenue Summary Table (Daily Cron):**
```sql
INSERT INTO restaurant_revenue_summary 
(restaurant_id, summary_date, total_revenue, order_count, customer_orders, pos_orders)
SELECT 
    restaurant_id,
    DATE(payment_verified_at) as summary_date,
    SUM(total_amount - delivery_fee) as total_revenue,
    COUNT(*) as order_count,
    SUM(CASE WHEN order_source='customer' THEN 1 ELSE 0 END) as customer_orders,
    SUM(CASE WHEN order_source='pos' THEN 1 ELSE 0 END) as pos_orders
FROM orders
WHERE payment_status = 'paid'
  AND DATE(payment_verified_at) = CURDATE() - INTERVAL 1 DAY
GROUP BY restaurant_id, DATE(payment_verified_at)
ON DUPLICATE KEY UPDATE
    total_revenue = VALUES(total_revenue),
    order_count = VALUES(order_count),
    customer_orders = VALUES(customer_orders),
    pos_orders = VALUES(pos_orders);
```

---

## Security Checklist

- [ ] PayMongo webhook signature verification enabled
- [ ] All user inputs sanitized (table_number, notes, etc.)
- [ ] SQL injection prevention (prepared statements used)
- [ ] CSRF protection on order submission
- [ ] Customer can only view/track own orders
- [ ] Cashier can only access assigned restaurant
- [ ] Payment amounts recalculated server-side
- [ ] Survey completion verified server-side
- [ ] Idempotency prevents duplicate charges
- [ ] HTTPS enabled for payment redirects

---

## Success Criteria

The integration is successful when:

✅ **Customer Experience:**
- Seamless cart → survey → payment → order tracking flow
- Real-time status updates without page refresh
- Clear payment confirmation and order history

✅ **Cashier Experience:**
- Immediate notification of paid orders
- Easy status management with click interface
- Clear visibility of table numbers and customer details

✅ **Business Owner Experience:**
- Accurate revenue tracking in real-time
- Customer vs POS order breakdown
- Reliable financial reports
- No duplicate revenue from payment retries

✅ **Data Integrity:**
- Survey completion enforced before checkout
- Payment idempotency prevents double-counting
- Status workflow maintains valid transitions
- Audit trail captures all changes

✅ **System Performance:**
- Page loads under 2 seconds
- Real-time updates within 15-30 seconds
- No database deadlocks or race conditions
- Graceful error handling and recovery

---

## Rollback Plan

If issues arise in production:

1. **Disable customer ordering temporarily:**
```php
// In dashboard/customer/restaurant_view.php, line ~1
die('Customer ordering temporarily unavailable. Please check back soon.');
```

2. **Revert database changes:**
```sql
-- Backup first!
mysqldump -u root -p halal_system > backup_before_rollback.sql

-- Remove new columns if needed (not recommended if data exists)
-- ALTER TABLE orders DROP COLUMN table_number;
```

3. **Restore previous file versions:**
```bash
git checkout HEAD~1 -- dashboard/customer/api/place_order.php
git checkout HEAD~1 -- api/paymongo_webhook.php
git checkout HEAD~1 -- dashboard/business_owner/owner_dashboard.php
```

---

## Post-Deployment Monitoring

Monitor these metrics for first 7 days:

1. **Order Success Rate:**
   ```sql
   SELECT 
       COUNT(*) as total_attempts,
       SUM(CASE WHEN payment_status='paid' THEN 1 ELSE 0 END) as successful,
       (SUM(CASE WHEN payment_status='paid' THEN 1 ELSE 0 END) / COUNT(*) * 100) as success_rate
   FROM orders
   WHERE created_at >= NOW() - INTERVAL 7 DAY
     AND order_source = 'customer';
   ```

2. **Survey Completion Rate:**
   ```sql
   SELECT COUNT(DISTINCT user_id) as users_with_surveys
   FROM survey_responses
   WHERE created_at >= NOW() - INTERVAL 7 DAY;
   ```

3. **Payment Processing Time:**
   ```sql
   SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, payment_verified_at)) as avg_seconds
   FROM orders
   WHERE payment_status = 'paid'
     AND payment_verified_at IS NOT NULL
     AND created_at >= NOW() - INTERVAL 7 DAY;
   ```

4. **Revenue Accuracy:**
   ```sql
   -- Compare database totals with PayMongo dashboard
   SELECT 
       DATE(payment_verified_at) as date,
       COUNT(*) as orders,
       SUM(total_amount) as total_charged
   FROM orders
   WHERE payment_status = 'paid'
     AND DATE(payment_verified_at) >= CURDATE() - INTERVAL 7 DAY
   GROUP BY DATE(payment_verified_at);
   ```

---

## Support Contacts

**For PayMongo Issues:**
- Dashboard: https://dashboard.paymongo.com
- Support: support@paymongo.com
- Documentation: https://developers.paymongo.com

**For System Issues:**
- Check application logs: `/path/to/logs/`
- Check webhook logs in database: `paymongo_webhook_logs` table
- Review error logs: PHP error log, MySQL error log

---

## Conclusion

This integrated system provides a complete end-to-end solution for:
- Mandatory customer demand survey collection
- Seamless payment processing
- Real-time order management
- Accurate revenue tracking
- Comprehensive audit trails

Follow this guide systematically to ensure successful deployment and operation.
