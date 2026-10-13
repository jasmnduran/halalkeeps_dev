# Cash Payment Testing Guide

## Overview
This guide provides comprehensive end-to-end testing procedures for the Cash Payment feature, which allows customers to select cash payment for dine-in orders and requires cashier confirmation before marking orders as paid.

**Created:** October 1, 2026  
**Feature:** Cash Payment with Cashier Confirmation  
**Related Files:** See Modified Files section below

---

## Prerequisites

### 1. Database Migration
Run the cash payment migration to add required columns:

```sql
-- Execute this SQL file
source database/cash_payment_migration.sql;
```

**Verify migration success:**
```sql
DESCRIBE orders;
-- Should show these new columns:
-- - cash_received (DECIMAL(10,2))
-- - change_amount (DECIMAL(10,2))
-- - payment_confirmed_by (INT(11))
-- - payment_reference (VARCHAR(50))
```

### 2. Test User Accounts
You need the following user accounts for testing:

- **Customer Account:** Regular customer who places orders
- **Business Owner Account:** Owner of a restaurant
- **Cashier Account:** Staff member with cashier role assigned to the restaurant

### 3. Test Restaurant Setup
- Restaurant must be active and have menu items
- Cashier must be assigned to the restaurant via `restaurant_staff` table
- Menu items must be approved and available

---

## Test Scenarios

## Scenario 1: Customer Places Cash Order (Dine-In)

### Test Steps:

#### 1.1 Customer Login and Navigate to Restaurant
1. Login as a **Customer**
2. Navigate to `dashboard/customer/restaurants.php`
3. Select a restaurant and view its menu
4. Add items to cart (at least 2-3 items)

**Expected:** Cart badge updates with item count

#### 1.2 Complete TPB Survey (if required)
1. Click "Checkout" button
2. If survey modal appears, complete the TPB demand survey
3. Submit survey

**Expected:** Survey redirects back to checkout modal

#### 1.3 Select Cash Payment Method
1. In checkout modal, select service type: **Dine-in**
2. Enter table number (e.g., "Table 5")
3. Select payment method: **💵 Cash**
4. Observe the payment instructions box

**Expected Results:**
- Cash option is highlighted with orange/yellow styling
- Payment instructions display: "After placing your order, please proceed to the cashier counter to complete payment of ₱XXX.XX. Your order will be confirmed once payment is received."
- Amount due is clearly displayed

#### 1.4 Place Order
1. Click "Place Order" button
2. Wait for response

**Expected Results:**
- Success message: "Order ORD-XXXXX placed (Dine-in)! Please proceed to the cashier to complete payment."
- Redirected to `dashboard/customer/orders.php`
- Order appears with status indicators

#### 1.5 Verify Customer Order View
On the customer orders page:

**Expected:**
- Order shows payment status: **Awaiting Cash** or **Pending Payment**
- Order status: **Pending Payment**
- Order displays table number
- Real-time status updates every 15 seconds

**Database Verification:**
```sql
SELECT 
    order_number, 
    payment_method, 
    payment_status, 
    order_status, 
    table_number,
    payment_reference,
    payment_verified_at
FROM orders 
WHERE order_number = 'ORD-XXXXX';

-- Expected:
-- payment_method: 'cash'
-- payment_status: 'awaiting_cash'
-- order_status: 'pending_payment'
-- payment_reference: 'CASH-ORD-XXXXX'
-- payment_verified_at: NULL
```

---

## Scenario 2: Cashier Receives Cash Payment

### Test Steps:

#### 2.1 Cashier Login and Access POS
1. Login as **Cashier** (or Business Owner)
2. Navigate to `dashboard/business_owner/pos_order_processing.php`

**Expected:** POS interface loads with order queues

#### 2.2 View Awaiting Cash Orders Section
1. Locate the "Awaiting Cash Payment" card (orange/yellow header)
2. Check the badge count

**Expected Results:**
- Section displays with icon: 💰 "Awaiting Cash Payment"
- Badge shows count of pending cash orders
- Order from Scenario 1 appears in this section
- Order shows:
  - Order number
  - Table number badge
  - Service type icon (🍽️ for dine-in)
  - Customer name
  - Item count
  - Total amount (in red, prominent)
  - "AWAITING CASH" status badge
  - "Receive Cash Payment" button (orange/yellow)

#### 2.3 Click Receive Cash Payment
1. Click the "Receive Cash Payment" button for the test order

**Expected:** Cash confirmation modal opens

#### 2.4 Verify Modal Contents
The modal should display:
- **Header:** "Receive Cash Payment" (orange gradient)
- **Order Info:** Order number in highlighted box
- **Amount Due:** Large, centered, red text showing total (e.g., ₱350.00)
- **Cash Received Input:** Large input field with ₱ symbol prefix
- **Change Display:** Hidden initially
- **Action Buttons:** Cancel (gray) and Confirm Payment (green, disabled initially)

#### 2.5 Test Insufficient Amount Validation
1. Enter an amount **less than** the amount due (e.g., ₱300.00 for ₱350.00 order)
2. Observe validation

**Expected Results:**
- Error message appears: "Insufficient amount. Short by ₱50.00"
- Input border turns red
- Change display remains hidden
- Confirm button stays disabled (opacity 50%, grayed out)

#### 2.6 Test Exact Amount
1. Clear input and enter **exact amount** (e.g., ₱350.00)
2. Observe changes

**Expected Results:**
- Error message disappears
- Input border turns normal
- Change display shows: "₱0.00" (green background)
- Confirm button becomes enabled (full opacity, clickable)

#### 2.7 Test Amount with Change
1. Clear input and enter amount **greater than** due (e.g., ₱500.00 for ₱350.00 order)
2. Observe real-time calculation

**Expected Results:**
- Change automatically calculates and displays: "₱150.00"
- Change display shows in green box
- Confirm button is enabled

#### 2.8 Confirm Payment
1. With valid amount entered, click "Confirm Payment"
2. Wait for processing

**Expected Results:**
- Button shows loading spinner: "Processing..."
- Success alert displays:
  ```
  ✅ Payment Confirmed!
  
  Order: ORD-XXXXX
  Cash Received: ₱500.00
  Amount Due: ₱350.00
  Change: ₱150.00
  ```
- Modal closes automatically
- Both "Awaiting Cash" and "Customer Orders" sections refresh

#### 2.9 Verify Order Movement
After confirmation:

**Expected:**
- Order disappears from "Awaiting Cash Payment" section
- Order appears in "Customer Orders Queue" section (green header)
- Order status changes to "CONFIRMED"
- Badge count on "Awaiting Cash Payment" decreases
- Badge count on "Customer Orders" increases

**Database Verification:**
```sql
SELECT 
    order_number,
    payment_status,
    order_status,
    cash_received,
    change_amount,
    payment_confirmed_by,
    payment_verified_at
FROM orders 
WHERE order_number = 'ORD-XXXXX';

-- Expected:
-- payment_status: 'paid'
-- order_status: 'confirmed'
-- cash_received: 500.00
-- change_amount: 150.00
-- payment_confirmed_by: <cashier_user_id>
-- payment_verified_at: <current timestamp>
```

---

## Scenario 3: Customer Receives Notification

### Test Steps:

#### 3.1 Customer Views Order Update
1. As the **Customer**, stay on or refresh `dashboard/customer/orders.php`
2. Wait up to 15 seconds for auto-refresh

**Expected Results:**
- Order status updates to "Confirmed" or "Preparing"
- Payment status updates to "Paid"
- Notification bell may show new notification

#### 3.2 Check Notifications
1. Click the notification bell icon
2. Look for payment confirmation notification

**Expected:**
- Notification appears: "✅ Payment Confirmed: ORD-XXXXX"
- Message: "Your cash payment of ₱350.00 has been confirmed. Your order is now being prepared!"
- Success/green styling

---

## Scenario 4: Revenue Tracking Verification

### Test Steps:

#### 4.1 Business Owner Dashboard - Before Confirmation
1. Login as **Business Owner**
2. Navigate to `dashboard/business_owner/owner_dashboard.php`
3. Note the "Today's Revenue" value

**Expected:** Cash order with `awaiting_cash` status is **NOT** included in revenue

#### 4.2 Business Owner Dashboard - After Confirmation
1. Refresh the dashboard after cashier confirms payment
2. Check "Today's Revenue" card

**Expected Results:**
- Revenue increases by the order amount (e.g., +₱350.00)
- Order count increases
- Net Cash Flow updates

#### 4.3 Payment Method Breakdown
Scroll down to the "Payment Method Breakdown — Today" section

**Expected Results:**
- Section displays with card header: 💰 "Payment Method Breakdown"
- **Cash Payments** summary box shows:
  - Icon: 💵
  - Revenue: ₱350.00 (or sum of all cash orders)
  - Order count: 1 order
  - Green background
- **Online Payments** summary box shows GCash/PayMongo totals (blue background)
- Detailed table shows:
  - Payment method (Cash with 💵 icon)
  - Order count
  - Revenue amount
  - Percentage bar with visual progress indicator

**Database Verification:**
```sql
-- Revenue query (should include the order now)
SELECT 
    COALESCE(SUM(total_amount - delivery_fee), 0) AS today_revenue,
    COUNT(*) AS order_count
FROM orders 
WHERE payment_status = 'paid'
  AND DATE(payment_verified_at) = CURDATE()
  AND restaurant_id = <restaurant_id>;

-- Payment method breakdown
SELECT 
    payment_method,
    COUNT(*) AS order_count,
    SUM(total_amount - delivery_fee) AS revenue
FROM orders 
WHERE payment_status = 'paid'
  AND DATE(payment_verified_at) = CURDATE()
  AND restaurant_id = <restaurant_id>
GROUP BY payment_method;
```

---

## Scenario 5: Idempotency Testing (Prevent Duplicate Revenue)

### Test Steps:

#### 5.1 Attempt Duplicate Confirmation
1. As **Cashier**, find the already-confirmed order in order history
2. Manually call the confirmation API with same order_id:

```bash
curl -X POST 'http://localhost/halal_final/dashboard/business_owner/api/confirm_cash_payment.php' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'order_id=123&cash_received=500.00' \
  --cookie 'PHPSESSID=<your_session_id>'
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Payment already confirmed.",
  "already_confirmed": true,
  "order_number": "ORD-XXXXX",
  "payment_verified_at": "2026-10-01 14:30:00"
}
```

#### 5.2 Verify Revenue Not Duplicated
1. Check owner dashboard revenue
2. Verify database

**Expected:**
- Revenue amount remains unchanged
- No duplicate entry in revenue calculations
- `payment_verified_at` timestamp unchanged

**Database Verification:**
```sql
-- This query should only count the order once
SELECT 
    order_number,
    payment_verified_at,
    cash_received,
    change_amount
FROM orders 
WHERE order_number = 'ORD-XXXXX';

-- Count revenue occurrences (should be 1)
SELECT COUNT(*) as count
FROM orders 
WHERE order_number = 'ORD-XXXXX'
  AND payment_status = 'paid';
```

---

## Scenario 6: Authorization Testing

### Test Steps:

#### 6.1 Non-Cashier Access Prevention
1. Login as a regular **Customer** (not cashier or owner)
2. Attempt to access POS directly: `dashboard/business_owner/pos_order_processing.php`

**Expected:** Access denied redirect or 403 error

#### 6.2 Cashier Restricted to Own Restaurant
1. Login as **Cashier A** assigned to Restaurant X
2. Attempt to confirm cash payment for an order from Restaurant Y

**Expected:**
- API returns 403 error
- Message: "Access denied. You are not authorized to process payments for this restaurant."

#### 6.3 API Direct Access Test
Try accessing confirmation API without authentication:

```bash
curl -X POST 'http://localhost/halal_final/dashboard/business_owner/api/confirm_cash_payment.php' \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'order_id=123&cash_received=500.00'
```

**Expected Response:**
```json
{
  "success": false,
  "message": "Not authenticated."
}
```
HTTP Status: 401

---

## Scenario 7: Edge Cases and Error Handling

### Test 7.1: Invalid Cash Amount
1. Cashier opens confirmation modal
2. Enter **negative** or **zero** amount
3. Attempt to confirm

**Expected:** Button stays disabled, cannot proceed

### Test 7.2: Non-Cash Order Confirmation
1. Place an order with **GCash** payment method
2. Attempt to call confirm_cash_payment API for this order

**Expected Response:**
```json
{
  "success": false,
  "message": "This order uses gcash payment method, not cash."
}
```

### Test 7.3: Modal Close and Reopen
1. Open cash confirmation modal
2. Enter amount
3. Close modal (click backdrop or X button)
4. Reopen modal

**Expected:** Form resets, no cached values

### Test 7.4: Network Error Handling
1. Open browser DevTools → Network tab
2. Set throttling to "Offline"
3. Attempt to confirm payment

**Expected:**
- Error alert: "❌ Network error. Please try again."
- Button re-enables for retry
- Order remains in awaiting_cash status

### Test 7.5: Auto-Refresh During Confirmation
1. Cashier A opens confirmation modal for an order
2. Cashier B (on another device) confirms the same order
3. After 30 seconds, Cashier A's POS auto-refreshes

**Expected:**
- Order disappears from Cashier A's "Awaiting Cash" section
- If Cashier A tries to confirm, gets "already confirmed" message

---

## Scenario 8: Customer Orders Integration

### Test Steps:

#### 8.1 Customer Order Lifecycle
Follow an order through complete lifecycle:

1. **Customer places cash order** → payment_status: `awaiting_cash`, order_status: `pending_payment`
2. **Cashier confirms payment** → payment_status: `paid`, order_status: `confirmed`
3. **Chef starts preparing** → order_status: `preparing`
4. **Chef marks ready** → order_status: `ready`
5. **Cashier marks completed** → order_status: `completed`

**Verify at each step:**
- Customer sees real-time status updates (15s refresh)
- Notifications sent appropriately
- Revenue counted only after step 2 (cashier confirmation)

#### 8.2 Order Status History
Check `order_status_history` table:

```sql
SELECT 
    order_id,
    old_status,
    new_status,
    changed_by,
    notes,
    changed_at
FROM order_status_history
WHERE order_id = <order_id>
ORDER BY changed_at ASC;
```

**Expected:** Entry for payment confirmation with notes showing cash_received and change_amount

---

## Browser Compatibility Testing

Test the entire workflow in:
- ✅ Chrome/Edge (Chromium)
- ✅ Firefox
- ✅ Safari (if available)
- ✅ Mobile browsers (responsive design)

---

## Performance Testing

### Load Test Scenarios:

#### Test 1: Multiple Simultaneous Cash Orders
1. Create 10 cash orders simultaneously (use multiple browser sessions)
2. Verify all appear in "Awaiting Cash" section
3. Confirm payments rapidly

**Expected:** No race conditions, all confirmations process correctly

#### Test 2: Auto-Refresh Performance
1. Keep POS open for 5+ minutes
2. Verify auto-refresh continues working (every 30s)

**Expected:** No memory leaks, smooth refresh cycles

---

## Rollback Procedures

If issues are found during testing:

### 1. Revert Database Changes
```sql
-- Remove added columns
ALTER TABLE orders 
  DROP COLUMN cash_received,
  DROP COLUMN change_amount,
  DROP COLUMN payment_confirmed_by,
  DROP COLUMN payment_reference;
```

### 2. Revert Code Changes
Use git to revert modified files:
```bash
git checkout HEAD -- dashboard/customer/api/place_order.php
git checkout HEAD -- dashboard/customer/restaurant_view.php
git checkout HEAD -- dashboard/business_owner/pos_order_processing.php
git checkout HEAD -- dashboard/business_owner/api/confirm_cash_payment.php
git checkout HEAD -- dashboard/business_owner/api/get_cashier_orders.php
git checkout HEAD -- dashboard/business_owner/owner_dashboard.php
```

---

## Modified Files Reference

### Backend APIs:
- `dashboard/customer/api/place_order.php` - Cash payment order creation
- `dashboard/business_owner/api/confirm_cash_payment.php` - Cashier confirmation endpoint (NEW)
- `dashboard/business_owner/api/get_cashier_orders.php` - Payment status filtering

### Frontend Views:
- `dashboard/customer/restaurant_view.php` - Checkout modal with cash option
- `dashboard/business_owner/pos_order_processing.php` - Awaiting cash section + modal
- `dashboard/business_owner/owner_dashboard.php` - Revenue tracking + payment breakdown

### Database:
- `database/cash_payment_migration.sql` - Schema changes (NEW)

---

## Success Criteria Checklist

Mark each item as you verify:

- [ ] Customer can select Cash payment for dine-in orders
- [ ] Cash orders show "awaiting_cash" status correctly
- [ ] Cashier sees pending cash orders in dedicated section
- [ ] Cash confirmation modal validates amounts properly
- [ ] Change is calculated accurately
- [ ] Insufficient payment is rejected
- [ ] Payment confirmation updates order and payment status
- [ ] Customer receives notification after confirmation
- [ ] Revenue excludes awaiting_cash orders
- [ ] Revenue includes orders only after confirmation (payment_verified_at set)
- [ ] Payment method breakdown shows cash vs online split
- [ ] Idempotency prevents duplicate revenue counting
- [ ] Authorization checks prevent unauthorized access
- [ ] Auto-refresh works correctly (30s POS, 15s customer orders)
- [ ] Error handling works for edge cases
- [ ] Mobile responsive design works

---

## Known Limitations

1. **Cash Refunds:** Current implementation does not support cash refunds for cancelled orders. This is a future enhancement.
2. **Partial Payments:** System does not support split/partial payments (e.g., cash + GCash).
3. **Cash Drawer Integration:** No physical cash drawer hardware integration.
4. **Receipt Printing:** Manual receipt printing not implemented (future enhancement).

---

## Support and Troubleshooting

### Common Issues:

**Issue:** Order stuck in "awaiting_cash" status  
**Solution:** Check if cashier has proper role assignment in `restaurant_staff` table

**Issue:** Revenue not updating after confirmation  
**Solution:** Verify `payment_verified_at` is set and `payment_status='paid'`

**Issue:** Modal not opening  
**Solution:** Check browser console for JavaScript errors, verify order data structure

**Issue:** Auto-refresh not working  
**Solution:** Check `setInterval` in browser console, verify API endpoints are accessible

---

## Test Sign-off

**Tested By:** ___________________  
**Date:** ___________________  
**Environment:** ☐ Development  ☐ Staging  ☐ Production  
**Result:** ☐ Pass  ☐ Fail  ☐ Pass with Issues  

**Notes:**
_________________________________________________________________
_________________________________________________________________
_________________________________________________________________

---

**End of Testing Guide**
