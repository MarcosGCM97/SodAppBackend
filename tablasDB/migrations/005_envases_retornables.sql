-- Returnable containers (envases): marks returnable products, counts empty
-- containers in the warehouse, records the containers returned in each sale
-- line and keeps a per-client, per-product container balance (sap_ce00).
-- Run AFTER 003_create_dias_and_cliente_dias.sql and a backup of sap_pr00 and
-- sap_vt00. Independent of 004 (either order works).
--
-- Existing data is not modified: every product starts non-returnable with 0
-- empties, every existing sale line gets vt_dev = NULL (no container effect),
-- and sap_ce00 starts empty (initial balances are loaded by hand from the app).
--
-- Steps 2-4 are idempotent: ADD COLUMN runs only when the column is missing
-- (information_schema check, portable to MySQL and MariaDB).

SET NAMES utf8mb4;

-- 1. Before: engines (FKs require InnoDB) and current columns
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('sap_cl00', 'sap_pr00', 'sap_vt00');

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('sap_pr00', 'sap_vt00')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 2. sap_pr00: pr_ret = returnable product, pr_vac = empty containers in the
--    warehouse. pr_stk keeps meaning full units.
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sap_pr00' AND COLUMN_NAME = 'pr_ret') = 0,
    'ALTER TABLE sap_pr00 ADD COLUMN pr_ret TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT ''sap_pr00.pr_ret already exists'' AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sap_pr00' AND COLUMN_NAME = 'pr_vac') = 0,
    'ALTER TABLE sap_pr00 ADD COLUMN pr_vac INT NOT NULL DEFAULT 0',
    'SELECT ''sap_pr00.pr_vac already exists'' AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. sap_vt00: vt_dev = containers returned in that sale line.
--    NULL = no container effect (sale before this feature, or non-returnable).
SET @sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sap_vt00' AND COLUMN_NAME = 'vt_dev') = 0,
    'ALTER TABLE sap_vt00 ADD COLUMN vt_dev INT NULL DEFAULT NULL',
    'SELECT ''sap_vt00.vt_dev already exists'' AS skipped');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Client container balance: one row per (cliente, producto). ce_can may be
--    negative (unreliable initial data must not block deliveries).
CREATE TABLE IF NOT EXISTS sap_ce00 (
    ce_cli INT NOT NULL,
    ce_pro INT NOT NULL,
    ce_can INT NOT NULL DEFAULT 0,
    ce_emp INT NOT NULL,
    PRIMARY KEY (ce_cli, ce_pro),
    KEY idx_ce_pro (ce_pro),
    KEY idx_ce_emp (ce_emp),
    CONSTRAINT fk_ce_cli FOREIGN KEY (ce_cli) REFERENCES sap_cl00 (cl_ide),
    CONSTRAINT fk_ce_pro FOREIGN KEY (ce_pro) REFERENCES sap_pr00 (pr_ide)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. After: new columns with their defaults, untouched data, empty balance
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'sap_pr00' AND COLUMN_NAME IN ('pr_ret', 'pr_vac'))
    OR (TABLE_NAME = 'sap_vt00' AND COLUMN_NAME = 'vt_dev'));

SELECT SUM(pr_ret <> 0) AS returnable_products, SUM(pr_vac <> 0) AS products_with_empties FROM sap_pr00;
SELECT SUM(vt_dev IS NOT NULL) AS sales_with_returns FROM sap_vt00;
SELECT COUNT(*) AS balance_rows FROM sap_ce00;
