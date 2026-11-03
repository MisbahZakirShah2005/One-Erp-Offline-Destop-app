-- ONEERP Offline Desktop Schema Patch 1.0.12
-- Queue self-heal support and stale lock recovery.
-- Core INSERT/UPDATE triggers remain defined by 1.0.11 and run before this patch.

SET NAMES utf8mb4;

-- Recover queue rows left in processing by a crashed/closed worker.
UPDATE `sync_queue`
SET
  `sync_status` = 'failed',
  `locked_at` = NULL,
  `locked_by` = NULL,
  `error_message` = COALESCE(`error_message`, 'Recovered by desktop schema 1.0.12'),
  `next_retry_at` = NULL
WHERE `sync_status` = 'processing'
  AND (`locked_at` IS NULL OR `locked_at` < DATE_SUB(NOW(), INTERVAL 5 MINUTE));

-- Helpful covering index for the self-healing worker.
DROP PROCEDURE IF EXISTS `oneerp_112_add_index_if_missing`;

DELIMITER $$

CREATE PROCEDURE `oneerp_112_add_index_if_missing`(
    IN p_table_name VARCHAR(128),
    IN p_index_name VARCHAR(128),
    IN p_alter_sql TEXT
)
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
    )
    AND NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table_name
          AND INDEX_NAME = p_index_name
    ) THEN
        SET @oneerp_112_sql = p_alter_sql;
        PREPARE oneerp_112_stmt FROM @oneerp_112_sql;
        EXECUTE oneerp_112_stmt;
        DEALLOCATE PREPARE oneerp_112_stmt;
    END IF;
END$$

DELIMITER ;

CALL oneerp_112_add_index_if_missing(
    'sync_queue',
    'idx_sq_device_record_status',
    'ALTER TABLE `sync_queue` ADD INDEX `idx_sq_device_record_status` (`device_id`,`table_name`,`record_uuid`,`sync_status`)'
);

DROP PROCEDURE IF EXISTS `oneerp_112_add_index_if_missing`;
