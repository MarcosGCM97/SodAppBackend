-- Ruta Google Maps: client locality and coordinates, and the plant address per user.
-- Run AFTER 008_repartos.sql and a full SQL backup of the database.
--
-- sap_cl00: cl_loc (locality), cl_lat / cl_lng (WGS84 degrees). All nullable with no
-- backfill: the app asks for the locality the next time a client is edited.
-- sap_pl00: the plant (start and end of every route) of each user, one row per pl_emp.
-- No foreign key to sap_us00 on purpose: the route module can move to its own service.
--
-- Existing data is not modified: the new columns start NULL and sap_pl00 starts empty.
--
-- NOT idempotent: run it once. Before running, confirm in a fresh backup that sap_cl00
-- has no cl_loc column and that sap_pl00 does not exist yet.
-- Plain statements on purpose (see 008): PREPARE statements fail with #1044 on the
-- production phpMyAdmin. Tables are schema-qualified; for another database, replace
-- c2761775_sodapp with its name.

ALTER TABLE `c2761775_sodapp`.`sap_cl00`
    ADD COLUMN cl_loc VARCHAR(100) NULL,
    ADD COLUMN cl_lat DECIMAL(9,6) NULL,
    ADD COLUMN cl_lng DECIMAL(9,6) NULL;

CREATE TABLE `c2761775_sodapp`.`sap_pl00` (
    pl_emp INT NOT NULL,
    pl_dir VARCHAR(150) NOT NULL,
    pl_loc VARCHAR(100) NOT NULL,
    pl_lat DECIMAL(9,6) NULL,
    pl_lng DECIMAL(9,6) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (pl_emp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- After: every client without locality or coordinates, 0 plants
SELECT COUNT(*) AS clientes_sin_localidad FROM `c2761775_sodapp`.`sap_cl00` WHERE cl_loc IS NULL;
SELECT COUNT(*) AS plantas FROM `c2761775_sodapp`.`sap_pl00`;
