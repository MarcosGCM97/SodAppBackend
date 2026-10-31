-- Stock movement history (historial de stock): one row in sap_ms00 per stock
-- change of a product, written by the API in the same transaction as the change.
-- Run AFTER 005_envases_retornables.sql and a full SQL backup of the database.
-- Independent of 004 (either order works).
--
-- Existing data is not modified: the history starts empty, movements before
-- this migration are not reconstructed.
--
-- ms_tip: venta (sale line, negative), venta_eliminada (deleted sale, positive),
-- llenado (refilled empties, positive), ajuste (manual edit, new - previous
-- stock), alta (product created, initial stock). ms_stk is the stock after the
-- movement. ms_vta keeps the sale id; it has no foreign key because deleted
-- sales are removed from sap_vt00.
--
-- NOT idempotent: run it once. Before running, confirm in a fresh backup that
-- sap_ms00 does not exist yet.
-- Plain statements on purpose: on the production phpMyAdmin (Donweb, MySQL
-- 8.0), PREPARE statements built from information_schema checks fail with
-- #1044. Tables are schema-qualified so the script does not depend on the
-- database selected in phpMyAdmin.
-- For another database, replace c2761775_sodapp with its name.

CREATE TABLE `c2761775_sodapp`.`sap_ms00` (
    ms_ide INT NOT NULL AUTO_INCREMENT,
    ms_pro INT NOT NULL,
    ms_emp INT NOT NULL,
    ms_tip VARCHAR(20) NOT NULL,
    ms_can INT NOT NULL,
    ms_stk INT NOT NULL,
    ms_vta INT NULL DEFAULT NULL,
    ms_fec DATETIME NOT NULL,
    PRIMARY KEY (ms_ide),
    KEY idx_ms_pro (ms_pro, ms_ide),
    KEY idx_ms_emp (ms_emp),
    CONSTRAINT fk_ms_pro FOREIGN KEY (ms_pro) REFERENCES `c2761775_sodapp`.`sap_pr00` (pr_ide)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- After: must be 0
SELECT COUNT(*) AS movement_rows FROM `c2761775_sodapp`.`sap_ms00`;
