-- Payment methods (formas de pago): every client payment registered through
-- deudaCliente.php PUT is stored in sap_pg00 with its method, so the app can
-- show a payment history per client and monthly totals per method.
-- Run AFTER 003_create_dias_and_cliente_dias.sql and a full SQL backup of the
-- database. Independent of 004, 005 and 006 (any order works).
--
-- Existing data is not modified: past payments were never recorded (they only
-- lowered cl_deb), so sap_pg00 starts empty. fp_ide 1 "Sin especificar" is
-- what old app versions store, since they send no method; it is not selectable
-- (fp_sel = 0). New methods are new rows, no migration needed.
--
-- NOT idempotent: run it once. Before running, confirm in a fresh backup that
-- sap_fp00 and sap_pg00 do not exist yet.
-- Plain statements on purpose: on the production phpMyAdmin (Donweb, MySQL
-- 8.0), PREPARE statements built from information_schema checks failed with
-- #1044. Tables are schema-qualified so the script does not depend on the
-- database selected in phpMyAdmin.
-- For another database, replace c2761775_sodapp with its name.

CREATE TABLE `c2761775_sodapp`.`sap_fp00` (
    fp_ide INT NOT NULL,
    fp_nom VARCHAR(40) NOT NULL,
    fp_sel TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (fp_ide),
    UNIQUE KEY uq_fp_nom (fp_nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `c2761775_sodapp`.`sap_fp00` (fp_ide, fp_nom, fp_sel) VALUES
    (1, 'Sin especificar', 0),
    (2, 'Efectivo', 1),
    (3, 'Transferencia', 1);

CREATE TABLE `c2761775_sodapp`.`sap_pg00` (
    pg_ide INT NOT NULL AUTO_INCREMENT,
    pg_cli INT NOT NULL,
    pg_mon DECIMAL(12,2) NOT NULL,
    pg_fp INT NOT NULL DEFAULT 1,
    pg_fec DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pg_emp INT NOT NULL,
    PRIMARY KEY (pg_ide),
    KEY idx_pg_cli_fec (pg_cli, pg_fec),
    KEY idx_pg_emp_fec (pg_emp, pg_fec),
    CONSTRAINT fk_pg_cli FOREIGN KEY (pg_cli) REFERENCES `c2761775_sodapp`.`sap_cl00` (cl_ide),
    CONSTRAINT fk_pg_fp FOREIGN KEY (pg_fp) REFERENCES `c2761775_sodapp`.`sap_fp00` (fp_ide)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- After: 3 methods, 0 payments
SELECT COUNT(*) AS methods FROM `c2761775_sodapp`.`sap_fp00`;
SELECT COUNT(*) AS payments FROM `c2761775_sodapp`.`sap_pg00`;
