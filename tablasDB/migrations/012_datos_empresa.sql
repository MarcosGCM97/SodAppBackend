-- Company data (datos-empresa): the real name, phone and address of each company.
-- Run AFTER 011_roles_permisos.sql and a full SQL backup of the database.
--
-- The login name of the owner is not the business name, so the app signed its WhatsApp
-- messages with the user name (even an employee's). sap_em00 keeps one row per company,
-- keyed by em_emp = the owner's us_ide (the company id of 011). Only the owner edits it;
-- every user of the company reads it.
-- No foreign key to sap_us00 on purpose (same choice as sap_pl00 in 009).
--
-- Existing data is not modified: sap_em00 starts empty and the app falls back to the
-- user name until the owner saves the company data.
--
-- Deploy order: run this migration first, then upload empresa.php, login.php and
-- sesion.php. The old PHP files never read this table.
--
-- NOT idempotent: run it once. Before running, confirm in a fresh backup that sap_em00
-- does not exist yet.
-- Plain statements on purpose (see 008): PREPARE statements fail with #1044 on the
-- production phpMyAdmin. Tables are schema-qualified; for another database, replace
-- c2761775_sodapp with its name.

-- Before: no sap_em00 table (expect an empty result)
SHOW TABLES FROM `c2761775_sodapp` LIKE 'sap_em00';

CREATE TABLE `c2761775_sodapp`.`sap_em00` (
    em_emp INT NOT NULL,
    em_nom VARCHAR(80) NOT NULL,
    em_tel VARCHAR(30) NULL,
    em_dir VARCHAR(150) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (em_emp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- After: the table exists and has 0 companies
SHOW TABLES FROM `c2761775_sodapp` LIKE 'sap_em00';
SELECT COUNT(*) AS empresas FROM `c2761775_sodapp`.`sap_em00`;
