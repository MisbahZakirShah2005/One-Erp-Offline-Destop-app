-- ONEERP Offline Desktop Schema Patch 1.0.13
-- Startup sync-integrity repair support.
-- Critical sync triggers are verified by main.js after all migrations.
-- This patch is intentionally small/idempotent.

SET NAMES utf8mb4;

UPDATE `sync_queue`
SET
    `sync_status` = 'failed',
    `locked_at` = NULL,
    `locked_by` = NULL,
    `error_message` = COALESCE(`error_message`, 'Recovered by desktop schema 1.0.13'),
    `next_retry_at` = NULL
WHERE `sync_status` = 'processing'
  AND (`locked_at` IS NULL OR `locked_at` < DATE_SUB(NOW(), INTERVAL 5 MINUTE));
