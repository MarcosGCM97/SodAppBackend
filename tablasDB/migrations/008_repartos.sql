-- Repartos (armado de repartos): the deliveries of a concrete date as preloaded sales.
-- A reparto (sap_rp00) has ordered stops (sap_rs00), one per cliente, and each stop has
-- planned lines (sap_rl00), one per producto. Stock and client debt move only when a stop
-- is delivered; the delivered lines then become sap_vt00 sales (rl_vt).
-- Run AFTER 003_create_dias_and_cliente_dias.sql and a full SQL backup of the database.
-- Independent of 004-007 (any order works).
--
-- rp_fec NULL marks the weekday template of rp_dia ("todos los lunes"); dated rows are
-- repartos. rp_rep is the repartidor, NULL for now (one repartidor per user). rp_ini and
-- rp_fin are the route start and return times used by ruta-google-maps.
-- rs_est: pendiente, entregada, no_entregada. rl_cpl planned quantity, rl_cen delivered
-- quantity (NULL until delivered), rl_dev containers returned at delivery, rl_vt the sale
-- created at delivery; it has no foreign key because deleted sales are removed from sap_vt00.
--
-- Existing data is not modified: all three tables start empty.
--
-- NOT idempotent: run it once. Before running, confirm in a fresh backup that sap_rp00,
-- sap_rs00 and sap_rl00 do not exist yet.
-- Plain statements on purpose: on the production phpMyAdmin (Donweb, MySQL
-- 8.0), PREPARE statements built from information_schema checks fail with
-- #1044. Tables are schema-qualified so the script does not depend on the
-- database selected in phpMyAdmin.
-- For another database, replace c2761775_sodapp with its name.

CREATE TABLE `c2761775_sodapp`.`sap_rp00` (
    rp_ide INT NOT NULL AUTO_INCREMENT,
    rp_emp INT NOT NULL,
    rp_fec DATE NULL,
    rp_dia INT NOT NULL,
    rp_rep INT NULL,
    rp_ini DATETIME NULL,
    rp_fin DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rp_ide),
    KEY idx_rp_emp_fec (rp_emp, rp_fec),
    KEY idx_rp_emp_dia (rp_emp, rp_dia),
    CONSTRAINT fk_rp_dia FOREIGN KEY (rp_dia) REFERENCES `c2761775_sodapp`.`sap_di00` (di_ide)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `c2761775_sodapp`.`sap_rs00` (
    rs_ide INT NOT NULL AUTO_INCREMENT,
    rs_rp INT NOT NULL,
    rs_cli INT NOT NULL,
    rs_ord INT NOT NULL,
    rs_est VARCHAR(12) NOT NULL DEFAULT 'pendiente',
    PRIMARY KEY (rs_ide),
    UNIQUE KEY uq_rs_rp_cli (rs_rp, rs_cli),
    KEY idx_rs_rp_ord (rs_rp, rs_ord),
    CONSTRAINT fk_rs_rp FOREIGN KEY (rs_rp) REFERENCES `c2761775_sodapp`.`sap_rp00` (rp_ide) ON DELETE CASCADE,
    CONSTRAINT fk_rs_cli FOREIGN KEY (rs_cli) REFERENCES `c2761775_sodapp`.`sap_cl00` (cl_ide)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `c2761775_sodapp`.`sap_rl00` (
    rl_ide INT NOT NULL AUTO_INCREMENT,
    rl_rs INT NOT NULL,
    rl_pro INT NOT NULL,
    rl_cpl INT NOT NULL DEFAULT 0,
    rl_cen INT NULL,
    rl_pre DECIMAL(10,2) NOT NULL,
    rl_dev INT NULL,
    rl_vt INT NULL,
    PRIMARY KEY (rl_ide),
    UNIQUE KEY uq_rl_rs_pro (rl_rs, rl_pro),
    CONSTRAINT fk_rl_rs FOREIGN KEY (rl_rs) REFERENCES `c2761775_sodapp`.`sap_rs00` (rs_ide) ON DELETE CASCADE,
    CONSTRAINT fk_rl_pro FOREIGN KEY (rl_pro) REFERENCES `c2761775_sodapp`.`sap_pr00` (pr_ide)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- After: 0 repartos, 0 stops, 0 lines
SELECT COUNT(*) AS repartos FROM `c2761775_sodapp`.`sap_rp00`;
SELECT COUNT(*) AS paradas FROM `c2761775_sodapp`.`sap_rs00`;
SELECT COUNT(*) AS lineas FROM `c2761775_sodapp`.`sap_rl00`;
