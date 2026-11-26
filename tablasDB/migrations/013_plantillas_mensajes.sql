-- WhatsApp message templates (plantillas-mensajes): the owner's own text for the messages
-- the app sends to clients. Run AFTER 012_datos_empresa.sql and a full SQL backup.
--
-- sap_msj00 keeps at most one template per company and message type, keyed by
-- ms_emp = the owner's us_ide (the company id of 011) and ms_tip = deuda, envases or venta.
-- The text holds variables like {cliente} that the app replaces when it sends the message.
-- Only the owner edits it; every user of the company reads it.
-- No foreign key to sap_us00 on purpose (same choice as sap_em00 in 012).
--
-- Existing data is not modified: sap_msj00 starts empty and the app keeps sending its
-- default messages until the owner saves a template.
--
-- Deploy order: run this migration first, then upload mensajes.php. No other PHP file
-- reads this table.
--
-- NOT idempotent: run it once. Before running, confirm in a fresh backup that sap_msj00
-- does not exist yet.
-- Plain statements on purpose (see 008): PREPARE statements fail with #1044 on the
-- production phpMyAdmin. Tables are schema-qualified; for another database, replace
-- c2761775_sodapp with its name.

-- Before: no sap_msj00 table (expect an empty result)
SHOW TABLES FROM `c2761775_sodapp` LIKE 'sap_msj00';

CREATE TABLE `c2761775_sodapp`.`sap_msj00` (
    ms_emp INT NOT NULL,
    ms_tip VARCHAR(10) NOT NULL,
    ms_txt VARCHAR(1000) NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (ms_emp, ms_tip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- After: the table exists and has 0 templates
SHOW TABLES FROM `c2761775_sodapp` LIKE 'sap_msj00';
SELECT COUNT(*) AS plantillas FROM `c2761775_sodapp`.`sap_msj00`;
