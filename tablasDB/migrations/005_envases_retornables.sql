-- Returnable containers (envases): marks returnable products, counts empty
-- containers in the warehouse, records the containers returned in each sale
-- line and keeps a per-client, per-product container balance (sap_ce00).
-- Run AFTER 003_create_dias_and_cliente_dias.sql and a full SQL backup of the
-- database. Independent of 004 (either order works).
--
-- Existing data is not modified: every product starts non-returnable with 0
-- empties, every existing sale line gets vt_dev = NULL (no container effect),
-- and sap_ce00 starts empty (initial balances are loaded by hand from the app).
--
-- NOT idempotent: run it once. Before running, confirm in a fresh backup that
-- pr_ret, pr_vac, vt_dev and sap_ce00 do not exist yet.
-- Plain statements on purpose: on the production phpMyAdmin (Donweb, MySQL
-- 8.0), PREPARE statements built from information_schema checks ran against
-- information_schema and failed with #1044. Tables are schema-qualified so
-- the script does not depend on the database selected in phpMyAdmin.
-- Applied in production (c2761775_sodapp) on 2026-10-29.
-- For another database, replace c2761775_sodapp with its name.

ALTER TABLE `c2761775_sodapp`.`sap_pr00`
  ADD COLUMN pr_ret TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN pr_vac INT NOT NULL DEFAULT 0;

ALTER TABLE `c2761775_sodapp`.`sap_vt00`
  ADD COLUMN vt_dev INT NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `c2761775_sodapp`.`sap_ce00` (
    ce_cli INT NOT NULL,
    ce_pro INT NOT NULL,
    ce_can INT NOT NULL DEFAULT 0,
    ce_emp INT NOT NULL,
    PRIMARY KEY (ce_cli, ce_pro),
    KEY idx_ce_pro (ce_pro),
    KEY idx_ce_emp (ce_emp),
    CONSTRAINT fk_ce_cli FOREIGN KEY (ce_cli) REFERENCES `c2761775_sodapp`.`sap_cl00` (cl_ide),
    CONSTRAINT fk_ce_pro FOREIGN KEY (ce_pro) REFERENCES `c2761775_sodapp`.`sap_pr00` (pr_ide)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- After: all three must be 0
SELECT COUNT(*) AS returnable_products FROM `c2761775_sodapp`.`sap_pr00` WHERE pr_ret <> 0;
SELECT COUNT(*) AS sales_with_returns FROM `c2761775_sodapp`.`sap_vt00` WHERE vt_dev IS NOT NULL;
SELECT COUNT(*) AS balance_rows FROM `c2761775_sodapp`.`sap_ce00`;
