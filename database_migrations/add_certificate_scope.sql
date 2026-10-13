-- Add scope_of_certification field to halal_certificates table if it doesn't exist
ALTER TABLE `halal_certificates` 
ADD COLUMN `scope_of_certification` TEXT DEFAULT NULL AFTER `business_type`;

-- Ensure proper indexes exist
ALTER TABLE `halal_certificates` 
ADD INDEX idx_application_id (application_id),
ADD INDEX idx_status (status);

-- Optional: Add a view for easy certificate access by approved applications
CREATE OR REPLACE VIEW v_pending_certificates AS
SELECT 
    ha.id as application_id,
    loi.company_name,
    loi.company_address,
    loi.enterprise_type,
    CASE WHEN hc.id IS NOT NULL THEN 1 ELSE 0 END as has_certificate,
    hc.id as certificate_id,
    hc.status as certificate_status
FROM hdp_applications ha
JOIN letter_of_intent loi ON ha.loi_id = loi.id
LEFT JOIN halal_certificates hc ON ha.id = hc.application_id
WHERE ha.status = 'approved'
ORDER BY ha.updated_at DESC;
