-- ONEERP Desktop Schema 1.0.15
-- Two-way sync state isolation per client/device/table.

SET @db := DATABASE();

-- Drop old device+table-only unique key if it exists.
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'desktop_sync_state'
      AND INDEX_NAME = 'uniq_desktop_sync_state'
);
SET @sql := IF(
    @idx_exists > 0,
    'ALTER TABLE `desktop_sync_state` DROP INDEX `uniq_desktop_sync_state`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add the correct tenant-safe unique key if missing.
SET @idx_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db
      AND TABLE_NAME = 'desktop_sync_state'
      AND INDEX_NAME = 'uniq_desktop_sync_state_client'
);
SET @sql := IF(
    @idx_exists = 0,
    'ALTER TABLE `desktop_sync_state` ADD UNIQUE KEY `uniq_desktop_sync_state_client` (`device_uuid`,`client_id`,`table_name`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
