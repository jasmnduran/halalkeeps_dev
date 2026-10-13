-- Add services_offered to halal_restaurants and serving_type to menu_items.
-- This migration supports restaurant service type selection and dish serving type labels.

ALTER TABLE `halal_restaurants`
  ADD COLUMN IF NOT EXISTS `services_offered` TEXT DEFAULT NULL AFTER `cuisine_type`;

ALTER TABLE `menu_items`
  ADD COLUMN IF NOT EXISTS `serving_type` VARCHAR(100) DEFAULT 'Per serving' AFTER `image_path`;
