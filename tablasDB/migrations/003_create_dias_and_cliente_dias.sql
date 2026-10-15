-- Moves the delivery days out of sap_cl00 (cl_lun..cl_dom flags) into a days
-- catalog (sap_di00) and a clientes/dias join table (sap_cd00).
-- Run AFTER 002_unique_product_name_per_user.sql and a backup of sap_cl00.
--
-- The old flag columns are NOT dropped here: dias.php keeps working on them
-- until it reads sap_cd00, and they allow a rollback. They are dropped in 004.
--
-- Steps 2-4 are idempotent. NULL flags count as "no day" (same as 0).
-- Review the step 5 report: it must be empty.

SET NAMES utf8mb4;

-- 1. Before: engine (FKs require InnoDB) and clients per day flag
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sap_cl00';

SELECT SUM(cl_lun = 1) AS lun, SUM(cl_mar = 1) AS mar, SUM(cl_mie = 1) AS mie,
       SUM(cl_jue = 1) AS jue, SUM(cl_vie = 1) AS vie, SUM(cl_sab = 1) AS sab,
       SUM(cl_dom = 1) AS dom
FROM sap_cl00;

-- 2. Days catalog: fixed ids, 1 = Lunes ... 7 = Domingo. The names are the
--    dias.php wire contract (accented), so they must not change.
CREATE TABLE IF NOT EXISTS sap_di00 (
    di_ide INT NOT NULL,
    di_nom VARCHAR(20) NOT NULL,
    PRIMARY KEY (di_ide),
    UNIQUE KEY uq_di_nom (di_nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO sap_di00 (di_ide, di_nom) VALUES
    (1, 'Lunes'), (2, 'Martes'), (3, 'Miércoles'), (4, 'Jueves'),
    (5, 'Viernes'), (6, 'Sábado'), (7, 'Domingo');

-- 3. Join table: one row per (cliente, dia). The owner is derived from
--    cd_cli -> sap_cl00.cl_emp. Soft-deleted clients keep their rows.
CREATE TABLE IF NOT EXISTS sap_cd00 (
    cd_cli INT NOT NULL,
    cd_dia INT NOT NULL,
    PRIMARY KEY (cd_cli, cd_dia),
    KEY idx_cd_dia (cd_dia),
    CONSTRAINT fk_cd_cli FOREIGN KEY (cd_cli) REFERENCES sap_cl00 (cl_ide) ON DELETE CASCADE,
    CONSTRAINT fk_cd_dia FOREIGN KEY (cd_dia) REFERENCES sap_di00 (di_ide)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Backfill from the flags
INSERT IGNORE INTO sap_cd00 (cd_cli, cd_dia)
          SELECT cl_ide, 1 FROM sap_cl00 WHERE cl_lun = 1
UNION ALL SELECT cl_ide, 2 FROM sap_cl00 WHERE cl_mar = 1
UNION ALL SELECT cl_ide, 3 FROM sap_cl00 WHERE cl_mie = 1
UNION ALL SELECT cl_ide, 4 FROM sap_cl00 WHERE cl_jue = 1
UNION ALL SELECT cl_ide, 5 FROM sap_cl00 WHERE cl_vie = 1
UNION ALL SELECT cl_ide, 6 FROM sap_cl00 WHERE cl_sab = 1
UNION ALL SELECT cl_ide, 7 FROM sap_cl00 WHERE cl_dom = 1;

-- 5. Report: differences between the flags and sap_cd00 (must be empty)
WITH flags AS (
              SELECT cl_ide, 1 AS dia FROM sap_cl00 WHERE cl_lun = 1
    UNION ALL SELECT cl_ide, 2 FROM sap_cl00 WHERE cl_mar = 1
    UNION ALL SELECT cl_ide, 3 FROM sap_cl00 WHERE cl_mie = 1
    UNION ALL SELECT cl_ide, 4 FROM sap_cl00 WHERE cl_jue = 1
    UNION ALL SELECT cl_ide, 5 FROM sap_cl00 WHERE cl_vie = 1
    UNION ALL SELECT cl_ide, 6 FROM sap_cl00 WHERE cl_sab = 1
    UNION ALL SELECT cl_ide, 7 FROM sap_cl00 WHERE cl_dom = 1
)
SELECT 'missing in sap_cd00' AS problema, f.cl_ide AS cliente, f.dia
FROM flags f LEFT JOIN sap_cd00 cd ON cd.cd_cli = f.cl_ide AND cd.cd_dia = f.dia
WHERE cd.cd_cli IS NULL
UNION ALL
SELECT 'extra in sap_cd00', cd.cd_cli, cd.cd_dia
FROM sap_cd00 cd LEFT JOIN flags f ON f.cl_ide = cd.cd_cli AND f.dia = cd.cd_dia
WHERE f.cl_ide IS NULL;

-- 6. After: clients per day (compare with step 1)
SELECT d.di_ide, d.di_nom, COUNT(cd.cd_cli) AS total
FROM sap_di00 d LEFT JOIN sap_cd00 cd ON cd.cd_dia = d.di_ide
GROUP BY d.di_ide, d.di_nom
ORDER BY d.di_ide;
