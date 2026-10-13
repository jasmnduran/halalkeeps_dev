-- Cash Payment Support Migration
-- This migration adds columns to support cash payment workflow with cashier confirmation
-- Created: 2026-10-01

-- Add cash payment tracking columns to orders table
ALTER TABLE orders 
ADD COLUMN IF NOT EXISTS cash_received DECIMAL(10,2) NULL COMMENT 'Actual cash amount received from customer',
ADD COLUMN IF NOT EXISTS change_amount DECIMAL(10,2) NULL COMMENT 'Change given to customer (cash_received - amount_due)',
ADD COLUMN IF NOT EXISTS payment_confirmed_by INT(11) NULL COMMENT 'User ID of cashier who confirmed payment receipt',
ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(50) NULL COMMENT 'Generic payment reference (PayMongo ID for online, Receipt# for cash)',
ADD INDEX idx_payment_confirmed_by (payment_confirmed_by),
ADD INDEX idx_payment_status_method (payment_status, payment_method);

-- Add foreign key for payment_confirmed_by (links to users table)
ALTER TABLE orders
ADD CONSTRAINT fk_orders_payment_confirmed_by 
FOREIGN KEY (payment_confirmed_by) REFERENCES users(id) ON DELETE SET NULL;

-- Update existing PayMongo orders to set payment_reference from webhook_event_id
UPDATE orders 
SET payment_reference = webhook_event_id 
WHERE payment_method = 'paymongo' 
  AND webhook_event_id IS NOT NULL 
  AND payment_reference IS NULL;

-- Verification queries
SELECT 
    'Orders table schema updated' AS status,
    COUNT(*) AS total_orders,
    SUM(CASE WHEN payment_method = 'cash' THEN 1 ELSE 0 END) AS cash_orders,
    SUM(CASE WHEN payment_method = 'paymongo' THEN 1 ELSE 0 END) AS paymongo_orders,
    SUM(CASE WHEN payment_status = 'awaiting_cash' THEN 1 ELSE 0 END) AS awaiting_cash_count
FROM orders;

-- Show sample of cash payment data structure
SELECT 
    id,
    payment_method,
    payment_status,
    total_amount,
    cash_received,
    change_amount,
    payment_confirmed_by,
    payment_reference,
    payment_verified_at
FROM orders 
WHERE payment_method = 'cash' 
LIMIT 5;
