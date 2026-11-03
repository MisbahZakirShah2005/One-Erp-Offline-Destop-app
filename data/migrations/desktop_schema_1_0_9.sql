-- ONEERP Offline Desktop schema patch 1.0.9
-- Target: bundled private MariaDB (saas_pos / port 3307)
-- Idempotent for MariaDB 10.4+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `live_sync_checkpoints` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sync_key` varchar(100) NOT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `last_success_at` datetime DEFAULT NULL,
  `status` enum('idle','running','success','failed') NOT NULL DEFAULT 'idle',
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_live_sync_key` (`sync_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `live_sync_checkpoints`
  (`sync_key`,`last_sync_at`,`last_success_at`,`status`)
VALUES
  ('live_to_local','2000-01-01 00:00:00',NULL,'idle')
ON DUPLICATE KEY UPDATE `sync_key` = VALUES(`sync_key`);

CREATE TABLE IF NOT EXISTS `item_delete_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `stock_qty` decimal(15,3) DEFAULT 0.000,
  `price` decimal(15,2) DEFAULT 0.00,
  `item_status` varchar(50) DEFAULT NULL,
  `item_created_at` datetime DEFAULT NULL,
  `item_updated_at` datetime DEFAULT NULL,
  `deleted_db_user` varchar(255) DEFAULT NULL,
  `deleted_connection_id` bigint(20) DEFAULT NULL,
  `deleted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_delete_client` (`client_id`),
  KEY `idx_delete_item` (`item_id`),
  KEY `idx_delete_date` (`deleted_at`),
  KEY `idx_delete_client_date` (`client_id`,`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_method_settings` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `method_code` enum('cash','card','wallet','bank_transfer','credit_customer') NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `show_in_pos` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_method_scope` (`client_id`,`branch_id`,`method_code`),
  KEY `idx_payment_method_enabled` (`client_id`,`branch_id`,`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `shopify_app_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_client_id` bigint(20) UNSIGNED DEFAULT NULL,
  `profile_name` varchar(150) NOT NULL,
  `app_type` enum('public_app','custom_app') NOT NULL,
  `credential_source` enum('server','database') NOT NULL DEFAULT 'database',
  `shopify_client_id` varchar(255) DEFAULT NULL,
  `client_secret_enc` longtext DEFAULT NULL,
  `redirect_uri` varchar(500) DEFAULT NULL,
  `scopes` text DEFAULT NULL,
  `api_version` varchar(20) NOT NULL DEFAULT '2026-07',
  `allowed_shop_domain` varchar(255) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shopify_profile_type` (`app_type`,`status`,`is_default`),
  KEY `idx_shopify_profile_owner` (`owner_client_id`,`app_type`,`status`),
  KEY `idx_shopify_profile_domain` (`allowed_shop_domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shopify_collection_map` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `shopify_store_id` bigint(20) UNSIGNED NOT NULL,
  `source_type` enum('category','subcategory') NOT NULL,
  `source_key` varchar(191) NOT NULL,
  `source_id` bigint(20) UNSIGNED DEFAULT NULL,
  `source_name` varchar(255) NOT NULL,
  `shopify_collection_gid` varchar(255) DEFAULT NULL,
  `shopify_handle` varchar(255) DEFAULT NULL,
  `sync_status` enum('pending','syncing','synced','failed') NOT NULL DEFAULT 'pending',
  `last_error` text DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shopify_collection_source` (`shopify_store_id`,`source_type`,`source_key`),
  KEY `idx_shopify_collection_client` (`client_id`,`sync_status`),
  KEY `idx_shopify_collection_client_status` (`client_id`,`sync_status`),
  KEY `idx_shopify_collection_gid` (`shopify_collection_gid`),
  KEY `idx_shopify_collection_source_id` (`client_id`,`source_type`,`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------------------------
-- Fresh-install safety:
-- Some older/partial desktop seeds can reach this migration without
-- business_settlement_accounts. Create it here before any ALTER TABLE.
-- The two 1.0.9 columns are included directly for a brand-new table.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `business_settlement_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `account_type` enum('bank','wallet','owner') NOT NULL,
  `provider_name` varchar(120) DEFAULT NULL,
  `account_name` varchar(150) NOT NULL,
  `account_number` varchar(150) DEFAULT NULL,
  `details` varchar(255) DEFAULT NULL,
  `show_in_pos` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bsa_client_branch_status` (`client_id`,`branch_id`,`status`),
  KEY `idx_bsa_type` (`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `subcategories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `parent_subcategory_id` bigint(20) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `uuid` varchar(64) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `sync_status` enum('pending','synced','failed') DEFAULT 'synced',
  `last_synced_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at_local` datetime DEFAULT NULL,
  `updated_at_local` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_subcategories_client_category` (`client_id`,`category_id`,`deleted_at`),
  KEY `idx_subcategories_uuid` (`uuid`),
  KEY `idx_subcategories_sync` (`sync_status`,`last_synced_at`),
  KEY `idx_subcategories_device` (`device_id`),
  KEY `idx_subcategories_name` (`client_id`,`category_id`,`name`),
  KEY `idx_parent_subcategory_id` (`parent_subcategory_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE IF EXISTS `business_settlement_accounts`
  ADD COLUMN IF NOT EXISTS `provider_name` varchar(120) DEFAULT NULL AFTER `account_type`;

ALTER TABLE IF EXISTS `business_settlement_accounts`
  ADD COLUMN IF NOT EXISTS `show_in_pos` tinyint(1) NOT NULL DEFAULT 1 AFTER `details`;

ALTER TABLE IF EXISTS `items`
  ADD COLUMN IF NOT EXISTS `subcategory_id` int(11) DEFAULT NULL AFTER `category`;

ALTER TABLE IF EXISTS `items`
  ADD COLUMN IF NOT EXISTS `subcategory` varchar(150) DEFAULT NULL AFTER `subcategory_id`;

ALTER TABLE IF EXISTS `shopify_oauth_states`
  ADD COLUMN IF NOT EXISTS `shopify_app_profile_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `shopify_store_id`;

ALTER TABLE IF EXISTS `shopify_stores`
  ADD COLUMN IF NOT EXISTS `shopify_app_profile_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `connect_method`;

DROP TRIGGER IF EXISTS `trg_items_before_delete_log`;
DELIMITER $$
CREATE TRIGGER `trg_items_before_delete_log`
BEFORE DELETE ON `items`
FOR EACH ROW
BEGIN
  INSERT INTO `item_delete_logs`
  (`item_id`,`client_id`,`item_name`,`sku`,`stock_qty`,`price`,`item_status`,
   `item_created_at`,`item_updated_at`,`deleted_db_user`,`deleted_connection_id`,`deleted_at`)
  VALUES
  (OLD.`id`,OLD.`client_id`,OLD.`name`,OLD.`sku`,OLD.`stock_qty`,OLD.`price`,OLD.`status`,
   OLD.`created_at`,OLD.`updated_at`,CURRENT_USER(),CONNECTION_ID(),NOW());
END$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
