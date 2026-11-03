-- ONEERP Offline Desktop Schema Patch 1.0.11
-- Permanent sync repair:
-- 1) Local INSERTs sync even when application explicitly supplies an ID.
-- 2) Cloud rows (device_id=cloud) are not re-queued.
-- 3) Business sessions sync before dependent sales/payments/expenses.
-- 4) Updates to records originally created on this device are queued safely.

SET NAMES utf8mb4;

DELIMITER $$

DROP TRIGGER IF EXISTS `oneerp_bi_items_sync`$$
CREATE TRIGGER `oneerp_bi_items_sync` BEFORE INSERT ON `items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_items_sync`$$
CREATE TRIGGER `oneerp_ai_items_sync` AFTER INSERT ON `items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'items', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'items'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_item_variants_sync`$$
CREATE TRIGGER `oneerp_bi_item_variants_sync` BEFORE INSERT ON `item_variants` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_item_variants_sync`$$
CREATE TRIGGER `oneerp_ai_item_variants_sync` AFTER INSERT ON `item_variants` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT 0, NULL, NEW.device_id, 'item_variants', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'item_variants'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_categories_sync`$$
CREATE TRIGGER `oneerp_bi_categories_sync` BEFORE INSERT ON `categories` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_categories_sync`$$
CREATE TRIGGER `oneerp_ai_categories_sync` AFTER INSERT ON `categories` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NULL, NEW.device_id, 'categories', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'categories'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_customers_sync`$$
CREATE TRIGGER `oneerp_bi_customers_sync` BEFORE INSERT ON `customers` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_customers_sync`$$
CREATE TRIGGER `oneerp_ai_customers_sync` AFTER INSERT ON `customers` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NULL, NEW.device_id, 'customers', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'customers'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_suppliers_sync`$$
CREATE TRIGGER `oneerp_bi_suppliers_sync` BEFORE INSERT ON `suppliers` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_suppliers_sync`$$
CREATE TRIGGER `oneerp_ai_suppliers_sync` AFTER INSERT ON `suppliers` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NULL, NEW.device_id, 'suppliers', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'suppliers'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_sales_sync`$$
CREATE TRIGGER `oneerp_bi_sales_sync` BEFORE INSERT ON `sales` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_sales_sync`$$
CREATE TRIGGER `oneerp_ai_sales_sync` AFTER INSERT ON `sales` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'sales', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'sales'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_sale_items_sync`$$
CREATE TRIGGER `oneerp_bi_sale_items_sync` BEFORE INSERT ON `sale_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.sale_uuid IS NULL OR NEW.sale_uuid = '') AND NEW.sale_id IS NOT NULL AND NEW.sale_id > 0 THEN SET NEW.sale_uuid = (SELECT uuid FROM sales WHERE id = NEW.sale_id LIMIT 1); END IF;
    IF (NEW.item_uuid IS NULL OR NEW.item_uuid = '') AND NEW.item_id IS NOT NULL AND NEW.item_id > 0 THEN SET NEW.item_uuid = (SELECT uuid FROM items WHERE id = NEW.item_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_sale_items_sync`$$
CREATE TRIGGER `oneerp_ai_sale_items_sync` AFTER INSERT ON `sale_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT 0, NULL, NEW.device_id, 'sale_items', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'sale_items'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_payments_sync`$$
CREATE TRIGGER `oneerp_bi_payments_sync` BEFORE INSERT ON `payments` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.sale_uuid IS NULL OR NEW.sale_uuid = '') AND NEW.sale_id IS NOT NULL AND NEW.sale_id > 0 THEN SET NEW.sale_uuid = (SELECT uuid FROM sales WHERE id = NEW.sale_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_payments_sync`$$
CREATE TRIGGER `oneerp_ai_payments_sync` AFTER INSERT ON `payments` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'payments', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'payments'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_expenses_sync`$$
CREATE TRIGGER `oneerp_bi_expenses_sync` BEFORE INSERT ON `expenses` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_expenses_sync`$$
CREATE TRIGGER `oneerp_ai_expenses_sync` AFTER INSERT ON `expenses` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'expenses', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'expenses'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_stock_movements_sync`$$
CREATE TRIGGER `oneerp_bi_stock_movements_sync` BEFORE INSERT ON `stock_movements` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.item_uuid IS NULL OR NEW.item_uuid = '') AND NEW.item_id IS NOT NULL AND NEW.item_id > 0 THEN SET NEW.item_uuid = (SELECT uuid FROM items WHERE id = NEW.item_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_stock_movements_sync`$$
CREATE TRIGGER `oneerp_ai_stock_movements_sync` AFTER INSERT ON `stock_movements` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'stock_movements', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'stock_movements'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_tables_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_tables_sync` BEFORE INSERT ON `restaurant_tables` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_tables_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_tables_sync` AFTER INSERT ON `restaurant_tables` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_tables', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_tables'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_waiters_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_waiters_sync` BEFORE INSERT ON `restaurant_waiters` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_waiters_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_waiters_sync` AFTER INSERT ON `restaurant_waiters` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_waiters', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_waiters'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_table_sessions_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_table_sessions_sync` BEFORE INSERT ON `restaurant_table_sessions` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.table_uuid IS NULL OR NEW.table_uuid = '') AND NEW.table_id IS NOT NULL AND NEW.table_id > 0 THEN SET NEW.table_uuid = (SELECT uuid FROM restaurant_tables WHERE id = NEW.table_id LIMIT 1); END IF;
    IF (NEW.waiter_uuid IS NULL OR NEW.waiter_uuid = '') AND NEW.waiter_id IS NOT NULL AND NEW.waiter_id > 0 THEN SET NEW.waiter_uuid = (SELECT uuid FROM restaurant_waiters WHERE id = NEW.waiter_id LIMIT 1); END IF;
    IF (NEW.customer_uuid IS NULL OR NEW.customer_uuid = '') AND NEW.customer_id IS NOT NULL AND NEW.customer_id > 0 THEN SET NEW.customer_uuid = (SELECT uuid FROM customers WHERE id = NEW.customer_id LIMIT 1); END IF;
    IF (NEW.sale_uuid IS NULL OR NEW.sale_uuid = '') AND NEW.sale_id IS NOT NULL AND NEW.sale_id > 0 THEN SET NEW.sale_uuid = (SELECT uuid FROM sales WHERE id = NEW.sale_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_table_sessions_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_table_sessions_sync` AFTER INSERT ON `restaurant_table_sessions` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_table_sessions', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_table_sessions'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_session_tables_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_session_tables_sync` BEFORE INSERT ON `restaurant_session_tables` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.session_uuid IS NULL OR NEW.session_uuid = '') AND NEW.session_id > 0 THEN SET NEW.session_uuid = (SELECT uuid FROM restaurant_table_sessions WHERE id = NEW.session_id LIMIT 1); END IF;
    IF (NEW.table_uuid IS NULL OR NEW.table_uuid = '') AND NEW.table_id > 0 THEN SET NEW.table_uuid = (SELECT uuid FROM restaurant_tables WHERE id = NEW.table_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_session_tables_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_session_tables_sync` AFTER INSERT ON `restaurant_session_tables` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_session_tables', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_session_tables'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_order_items_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_order_items_sync` BEFORE INSERT ON `restaurant_order_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.session_uuid IS NULL OR NEW.session_uuid = '') AND NEW.session_id > 0 THEN SET NEW.session_uuid = (SELECT uuid FROM restaurant_table_sessions WHERE id = NEW.session_id LIMIT 1); END IF;
    IF (NEW.item_uuid IS NULL OR NEW.item_uuid = '') AND NEW.item_id IS NOT NULL AND NEW.item_id > 0 THEN SET NEW.item_uuid = (SELECT uuid FROM items WHERE id = NEW.item_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_order_items_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_order_items_sync` AFTER INSERT ON `restaurant_order_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_order_items', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_order_items'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_kots_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_kots_sync` BEFORE INSERT ON `restaurant_kots` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.session_uuid IS NULL OR NEW.session_uuid = '') AND NEW.session_id > 0 THEN SET NEW.session_uuid = (SELECT uuid FROM restaurant_table_sessions WHERE id = NEW.session_id LIMIT 1); END IF;
    IF (NEW.table_uuid IS NULL OR NEW.table_uuid = '') AND NEW.table_id IS NOT NULL AND NEW.table_id > 0 THEN SET NEW.table_uuid = (SELECT uuid FROM restaurant_tables WHERE id = NEW.table_id LIMIT 1); END IF;
    IF (NEW.waiter_uuid IS NULL OR NEW.waiter_uuid = '') AND NEW.waiter_id IS NOT NULL AND NEW.waiter_id > 0 THEN SET NEW.waiter_uuid = (SELECT uuid FROM restaurant_waiters WHERE id = NEW.waiter_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_kots_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_kots_sync` AFTER INSERT ON `restaurant_kots` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_kots', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_kots'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_kot_items_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_kot_items_sync` BEFORE INSERT ON `restaurant_kot_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.kot_uuid IS NULL OR NEW.kot_uuid = '') AND NEW.kot_id > 0 THEN SET NEW.kot_uuid = (SELECT uuid FROM restaurant_kots WHERE id = NEW.kot_id LIMIT 1); END IF;
    IF (NEW.order_item_uuid IS NULL OR NEW.order_item_uuid = '') AND NEW.order_item_id > 0 THEN SET NEW.order_item_uuid = (SELECT uuid FROM restaurant_order_items WHERE id = NEW.order_item_id LIMIT 1); END IF;
    IF (NEW.item_uuid IS NULL OR NEW.item_uuid = '') AND NEW.item_id IS NOT NULL AND NEW.item_id > 0 THEN SET NEW.item_uuid = (SELECT uuid FROM items WHERE id = NEW.item_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_kot_items_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_kot_items_sync` AFTER INSERT ON `restaurant_kot_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT 0, NULL, NEW.device_id, 'restaurant_kot_items', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_kot_items'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_kitchen_status_logs_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_kitchen_status_logs_sync` BEFORE INSERT ON `restaurant_kitchen_status_logs` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.session_uuid IS NULL OR NEW.session_uuid = '') AND NEW.session_id IS NOT NULL AND NEW.session_id > 0 THEN SET NEW.session_uuid = (SELECT uuid FROM restaurant_table_sessions WHERE id = NEW.session_id LIMIT 1); END IF;
    IF (NEW.kot_uuid IS NULL OR NEW.kot_uuid = '') AND NEW.kot_id IS NOT NULL AND NEW.kot_id > 0 THEN SET NEW.kot_uuid = (SELECT uuid FROM restaurant_kots WHERE id = NEW.kot_id LIMIT 1); END IF;
    IF (NEW.kot_item_uuid IS NULL OR NEW.kot_item_uuid = '') AND NEW.kot_item_id IS NOT NULL AND NEW.kot_item_id > 0 THEN SET NEW.kot_item_uuid = (SELECT uuid FROM restaurant_kot_items WHERE id = NEW.kot_item_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_kitchen_status_logs_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_kitchen_status_logs_sync` AFTER INSERT ON `restaurant_kitchen_status_logs` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_kitchen_status_logs', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_kitchen_status_logs'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_activity_logs_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_activity_logs_sync` BEFORE INSERT ON `restaurant_activity_logs` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.session_uuid IS NULL OR NEW.session_uuid = '') AND NEW.session_id IS NOT NULL AND NEW.session_id > 0 THEN SET NEW.session_uuid = (SELECT uuid FROM restaurant_table_sessions WHERE id = NEW.session_id LIMIT 1); END IF;
    IF (NEW.table_uuid IS NULL OR NEW.table_uuid = '') AND NEW.table_id IS NOT NULL AND NEW.table_id > 0 THEN SET NEW.table_uuid = (SELECT uuid FROM restaurant_tables WHERE id = NEW.table_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_activity_logs_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_activity_logs_sync` AFTER INSERT ON `restaurant_activity_logs` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_activity_logs', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_activity_logs'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_split_bills_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_split_bills_sync` BEFORE INSERT ON `restaurant_split_bills` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.session_uuid IS NULL OR NEW.session_uuid = '') AND NEW.session_id > 0 THEN SET NEW.session_uuid = (SELECT uuid FROM restaurant_table_sessions WHERE id = NEW.session_id LIMIT 1); END IF;
    IF (NEW.sale_uuid IS NULL OR NEW.sale_uuid = '') AND NEW.sale_id IS NOT NULL AND NEW.sale_id > 0 THEN SET NEW.sale_uuid = (SELECT uuid FROM sales WHERE id = NEW.sale_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_split_bills_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_split_bills_sync` AFTER INSERT ON `restaurant_split_bills` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_split_bills', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_split_bills'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_split_bill_items_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_split_bill_items_sync` BEFORE INSERT ON `restaurant_split_bill_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.split_bill_uuid IS NULL OR NEW.split_bill_uuid = '') AND NEW.split_bill_id > 0 THEN SET NEW.split_bill_uuid = (SELECT uuid FROM restaurant_split_bills WHERE id = NEW.split_bill_id LIMIT 1); END IF;
    IF (NEW.order_item_uuid IS NULL OR NEW.order_item_uuid = '') AND NEW.order_item_id > 0 THEN SET NEW.order_item_uuid = (SELECT uuid FROM restaurant_order_items WHERE id = NEW.order_item_id LIMIT 1); END IF;
    IF (NEW.item_uuid IS NULL OR NEW.item_uuid = '') AND NEW.item_id IS NOT NULL AND NEW.item_id > 0 THEN SET NEW.item_uuid = (SELECT uuid FROM items WHERE id = NEW.item_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_split_bill_items_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_split_bill_items_sync` AFTER INSERT ON `restaurant_split_bill_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT 0, NULL, NEW.device_id, 'restaurant_split_bill_items', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_split_bill_items'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_table_transfers_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_table_transfers_sync` BEFORE INSERT ON `restaurant_table_transfers` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
    IF (NEW.session_uuid IS NULL OR NEW.session_uuid = '') AND NEW.session_id > 0 THEN SET NEW.session_uuid = (SELECT uuid FROM restaurant_table_sessions WHERE id = NEW.session_id LIMIT 1); END IF;
    IF (NEW.from_table_uuid IS NULL OR NEW.from_table_uuid = '') AND NEW.from_table_id IS NOT NULL AND NEW.from_table_id > 0 THEN SET NEW.from_table_uuid = (SELECT uuid FROM restaurant_tables WHERE id = NEW.from_table_id LIMIT 1); END IF;
    IF (NEW.to_table_uuid IS NULL OR NEW.to_table_uuid = '') AND NEW.to_table_id IS NOT NULL AND NEW.to_table_id > 0 THEN SET NEW.to_table_uuid = (SELECT uuid FROM restaurant_tables WHERE id = NEW.to_table_id LIMIT 1); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_table_transfers_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_table_transfers_sync` AFTER INSERT ON `restaurant_table_transfers` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_table_transfers', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_table_transfers'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_restaurant_reservations_sync`$$
CREATE TRIGGER `oneerp_bi_restaurant_reservations_sync` BEFORE INSERT ON `restaurant_reservations` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_restaurant_reservations_sync`$$
CREATE TRIGGER `oneerp_ai_restaurant_reservations_sync` AFTER INSERT ON `restaurant_reservations` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'restaurant_reservations', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'restaurant_reservations'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_table_reservations_sync`$$
CREATE TRIGGER `oneerp_bi_table_reservations_sync` BEFORE INSERT ON `table_reservations` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_table_reservations_sync`$$
CREATE TRIGGER `oneerp_ai_table_reservations_sync` AFTER INSERT ON `table_reservations` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'table_reservations', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'table_reservations'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_returns_sync`$$
CREATE TRIGGER `oneerp_bi_returns_sync` BEFORE INSERT ON `returns` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_returns_sync`$$
CREATE TRIGGER `oneerp_ai_returns_sync` AFTER INSERT ON `returns` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id, 0), NEW.branch_id, NEW.device_id, 'returns', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'returns'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_return_items_sync`$$
CREATE TRIGGER `oneerp_bi_return_items_sync` BEFORE INSERT ON `return_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    IF NEW.created_at_local IS NULL THEN SET NEW.created_at_local = NOW(); END IF;
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_return_items_sync`$$
CREATE TRIGGER `oneerp_ai_return_items_sync` AFTER INSERT ON `return_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT 0, NULL, NEW.device_id, 'return_items', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'return_items'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$


-- Business sessions are local operational parents for sales/payments/expenses.
DROP TRIGGER IF EXISTS `oneerp_bi_business_sessions_sync`$$
CREATE TRIGGER `oneerp_bi_business_sessions_sync` BEFORE INSERT ON `business_sessions` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_business_sessions_sync`$$
CREATE TRIGGER `oneerp_ai_business_sessions_sync` AFTER INSERT ON `business_sessions` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT NEW.client_id, NEW.branch_id, NEW.device_id, 'business_sessions', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'business_sessions'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bu_business_sessions_sync`$$
CREATE TRIGGER `oneerp_bu_business_sessions_sync` BEFORE UPDATE ON `business_sessions` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND OLD.device_id = @oneerp_device_id THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = OLD.uuid; END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    SET NEW.updated_at = NOW();
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_au_business_sessions_sync`$$
CREATE TRIGGER `oneerp_au_business_sessions_sync` AFTER UPDATE ON `business_sessions` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.sync_status = 'pending'
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT NEW.client_id, NEW.branch_id, NEW.device_id, 'business_sessions', NEW.uuid, 'update', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'business_sessions'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bi_business_session_cash_movements_sync`$$
CREATE TRIGGER `oneerp_bi_business_session_cash_movements_sync` BEFORE INSERT ON `business_session_cash_movements` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND (NEW.device_id IS NULL OR NEW.device_id = '' OR NEW.device_id = @oneerp_device_id) THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = REPLACE(UUID(), '-', ''); END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_ai_business_session_cash_movements_sync`$$
CREATE TRIGGER `oneerp_ai_business_session_cash_movements_sync` AFTER INSERT ON `business_session_cash_movements` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT NEW.client_id, NEW.branch_id, NEW.device_id, 'business_session_cash_movements', NEW.uuid, 'insert', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'business_session_cash_movements'
        AND record_uuid = NEW.uuid
        AND action = 'insert'
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bu_items_sync`$$
CREATE TRIGGER `oneerp_bu_items_sync` BEFORE UPDATE ON `items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND OLD.device_id = @oneerp_device_id THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = OLD.uuid; END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    SET NEW.updated_at_local = NOW();
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_au_items_sync`$$
CREATE TRIGGER `oneerp_au_items_sync` AFTER UPDATE ON `items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.sync_status = 'pending'
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT NEW.client_id, NEW.branch_id, NEW.device_id, 'items', NEW.uuid, 'update', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'items'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bu_categories_sync`$$
CREATE TRIGGER `oneerp_bu_categories_sync` BEFORE UPDATE ON `categories` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND OLD.device_id = @oneerp_device_id THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = OLD.uuid; END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    SET NEW.updated_at_local = NOW();
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_au_categories_sync`$$
CREATE TRIGGER `oneerp_au_categories_sync` AFTER UPDATE ON `categories` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.sync_status = 'pending'
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT NEW.client_id, NULL, NEW.device_id, 'categories', NEW.uuid, 'update', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'categories'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bu_customers_sync`$$
CREATE TRIGGER `oneerp_bu_customers_sync` BEFORE UPDATE ON `customers` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND OLD.device_id = @oneerp_device_id THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = OLD.uuid; END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    SET NEW.updated_at_local = NOW();
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_au_customers_sync`$$
CREATE TRIGGER `oneerp_au_customers_sync` AFTER UPDATE ON `customers` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.sync_status = 'pending'
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id,0), NULL, NEW.device_id, 'customers', NEW.uuid, 'update', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'customers'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bu_suppliers_sync`$$
CREATE TRIGGER `oneerp_bu_suppliers_sync` BEFORE UPDATE ON `suppliers` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND OLD.device_id = @oneerp_device_id THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = OLD.uuid; END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    SET NEW.updated_at_local = NOW();
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_au_suppliers_sync`$$
CREATE TRIGGER `oneerp_au_suppliers_sync` AFTER UPDATE ON `suppliers` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.sync_status = 'pending'
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT NEW.client_id, NULL, NEW.device_id, 'suppliers', NEW.uuid, 'update', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'suppliers'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bu_sales_sync`$$
CREATE TRIGGER `oneerp_bu_sales_sync` BEFORE UPDATE ON `sales` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND OLD.device_id = @oneerp_device_id THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = OLD.uuid; END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    SET NEW.updated_at_local = NOW();
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_au_sales_sync`$$
CREATE TRIGGER `oneerp_au_sales_sync` AFTER UPDATE ON `sales` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.sync_status = 'pending'
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT COALESCE(NEW.client_id,0), NEW.branch_id, NEW.device_id, 'sales', NEW.uuid, 'update', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'sales'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bu_sale_items_sync`$$
CREATE TRIGGER `oneerp_bu_sale_items_sync` BEFORE UPDATE ON `sale_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND OLD.device_id = @oneerp_device_id THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = OLD.uuid; END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    SET NEW.updated_at_local = NOW();
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_au_sale_items_sync`$$
CREATE TRIGGER `oneerp_au_sale_items_sync` AFTER UPDATE ON `sale_items` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.sync_status = 'pending'
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT 0, NULL, NEW.device_id, 'sale_items', NEW.uuid, 'update', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'sale_items'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bu_payments_sync`$$
CREATE TRIGGER `oneerp_bu_payments_sync` BEFORE UPDATE ON `payments` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND OLD.device_id = @oneerp_device_id THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = OLD.uuid; END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    SET NEW.updated_at_local = NOW();
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_au_payments_sync`$$
CREATE TRIGGER `oneerp_au_payments_sync` AFTER UPDATE ON `payments` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.sync_status = 'pending'
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT NEW.client_id, NEW.branch_id, NEW.device_id, 'payments', NEW.uuid, 'update', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'payments'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bu_expenses_sync`$$
CREATE TRIGGER `oneerp_bu_expenses_sync` BEFORE UPDATE ON `expenses` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND OLD.device_id = @oneerp_device_id THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = OLD.uuid; END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    SET NEW.updated_at_local = NOW();
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_au_expenses_sync`$$
CREATE TRIGGER `oneerp_au_expenses_sync` AFTER UPDATE ON `expenses` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.sync_status = 'pending'
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT NEW.client_id, NEW.branch_id, NEW.device_id, 'expenses', NEW.uuid, 'update', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'expenses'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_bu_stock_movements_sync`$$
CREATE TRIGGER `oneerp_bu_stock_movements_sync` BEFORE UPDATE ON `stock_movements` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND OLD.device_id = @oneerp_device_id THEN
    IF NEW.uuid IS NULL OR NEW.uuid = '' THEN SET NEW.uuid = OLD.uuid; END IF;
    SET NEW.device_id = @oneerp_device_id;
    SET NEW.sync_status = 'pending';
    SET NEW.updated_at_local = NOW();
  END IF;
END$$

DROP TRIGGER IF EXISTS `oneerp_au_stock_movements_sync`$$
CREATE TRIGGER `oneerp_au_stock_movements_sync` AFTER UPDATE ON `stock_movements` FOR EACH ROW
BEGIN
  IF COALESCE(@oneerp_sync_import, 0) = 0
     AND COALESCE(@oneerp_sync_worker, 0) = 0
     AND COALESCE(@oneerp_device_id, '') <> ''
     AND NEW.device_id = @oneerp_device_id
     AND NEW.sync_status = 'pending'
     AND NEW.uuid IS NOT NULL AND NEW.uuid <> '' THEN
    INSERT INTO sync_queue
      (client_id, branch_id, device_id, table_name, record_uuid, action, payload, sync_status, created_at)
    SELECT NEW.client_id, NEW.branch_id, NEW.device_id, 'stock_movements', NEW.uuid, 'update', '{}', 'pending', NOW()
    WHERE NOT EXISTS (
      SELECT 1 FROM sync_queue
      WHERE table_name = 'stock_movements'
        AND record_uuid = NEW.uuid
        AND action IN ('insert','update')
        AND sync_status IN ('pending','processing')
    );
  END IF;
END$$

DELIMITER ;
