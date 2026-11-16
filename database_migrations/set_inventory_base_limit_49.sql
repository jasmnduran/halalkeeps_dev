-- Set the included kitchen ingredient-record capacity to 49.
-- Apply after database_migrations/add_paymongo_revenue_models.sql.
INSERT INTO `system_settings` (`setting_key`,`setting_value`,`setting_type`,`description`) VALUES
('billing_inventory_base_slots', '49', 'number', 'Included logical kitchen inventory record limit.')
ON DUPLICATE KEY UPDATE
    `setting_value` = VALUES(`setting_value`),
    `setting_type` = VALUES(`setting_type`),
    `description` = VALUES(`description`);