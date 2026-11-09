-- Roles and permissions per user (roles-usuarios): company scope, roles, permission catalog.
-- Run AFTER 009_ruta_google_maps.sql and a full SQL backup of the database.
-- Independent of 010_cl_deb_decimal.sql (any order works).
--
-- Today a user is a company: every row is owned by the user id (cl_emp = us_ide). This adds
-- the company of each user and the roles that limit what a non-owner user can do:
-- sap_us00: us_emp (company = the owner's us_ide; an owner points to itself), us_rol (role,
--           NULL for the owner), us_act (1 active, 0 cannot log in). us_nom becomes unique
--           because login is by name. us_ide is made AUTO_INCREMENT (the admin API creates users).
-- sap_ro00: roles of a company. sap_pm00: permission catalog (actions the API enforces).
-- sap_rm00: role <-> permission (many to many).
--
-- Existing data: every existing user becomes the owner of its own company (us_emp = us_ide,
-- us_rol NULL, us_act 1), so owner tokens keep seeing exactly the same rows. Each owner gets
-- one "Vendedor" role with ventas.ver, ventas.cargar, repartos.ver, repartos.gestionar,
-- clientes.ver and clientes.editar. No data row (clientes, ventas, ...) is modified.
--
-- Deploy order: run this migration first, then upload the PHP files (auth.php, login.php,
-- sesion.php, usuarios.php, roles.php and every data endpoint). The old PHP files keep
-- working against the new columns meanwhile; the new PHP files need these tables.
--
-- NOT idempotent: run it once. Before running, confirm in a fresh backup that sap_us00 has
-- no us_emp column and that sap_ro00, sap_pm00 and sap_rm00 do not exist yet. If the
-- duplicate-name report below returns rows, rename those users and run the script again:
-- the unique index is the first change, so a failure there leaves the database untouched.
-- Plain statements on purpose (see 008): PREPARE statements fail with #1044 on the
-- production phpMyAdmin. Tables are schema-qualified; for another database, replace
-- c2761775_sodapp with its name.

-- Before: the user columns and how many users there are
SHOW COLUMNS FROM `c2761775_sodapp`.`sap_us00`;
SELECT COUNT(*) AS usuarios FROM `c2761775_sodapp`.`sap_us00`;

-- Duplicate user names: must return no rows (login is by name, the index below is unique)
SELECT us_nom, COUNT(*) AS repetidos FROM `c2761775_sodapp`.`sap_us00` GROUP BY us_nom HAVING COUNT(*) > 1;

-- First change on purpose: when a duplicate name makes it fail, nothing else has run yet
ALTER TABLE `c2761775_sodapp`.`sap_us00`
    ADD UNIQUE KEY uq_us_nom (us_nom);

-- us_ide must generate ids for the users created by the admin API (no-op when it already does)
ALTER TABLE `c2761775_sodapp`.`sap_us00`
    MODIFY us_ide INT NOT NULL AUTO_INCREMENT;

ALTER TABLE `c2761775_sodapp`.`sap_us00`
    ADD COLUMN us_emp INT NULL,
    ADD COLUMN us_rol INT NULL,
    ADD COLUMN us_act TINYINT(1) NOT NULL DEFAULT 1;

-- Every existing user is the owner of its own company
UPDATE `c2761775_sodapp`.`sap_us00` SET us_emp = us_ide WHERE us_emp IS NULL;

ALTER TABLE `c2761775_sodapp`.`sap_us00`
    MODIFY us_emp INT NOT NULL,
    ADD KEY idx_us_emp (us_emp);

CREATE TABLE `c2761775_sodapp`.`sap_ro00` (
    ro_ide INT NOT NULL AUTO_INCREMENT,
    ro_emp INT NOT NULL,
    ro_nom VARCHAR(50) NOT NULL,
    PRIMARY KEY (ro_ide),
    UNIQUE KEY uq_ro_emp_nom (ro_emp, ro_nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `c2761775_sodapp`.`sap_pm00` (
    pm_ide INT NOT NULL AUTO_INCREMENT,
    pm_cod VARCHAR(40) NOT NULL,
    pm_des VARCHAR(100) NOT NULL,
    PRIMARY KEY (pm_ide),
    UNIQUE KEY uq_pm_cod (pm_cod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `c2761775_sodapp`.`sap_rm00` (
    rm_rol INT NOT NULL,
    rm_per INT NOT NULL,
    PRIMARY KEY (rm_rol, rm_per),
    KEY idx_rm_per (rm_per),
    CONSTRAINT fk_rm_rol FOREIGN KEY (rm_rol) REFERENCES `c2761775_sodapp`.`sap_ro00` (ro_ide) ON DELETE CASCADE,
    CONSTRAINT fk_rm_per FOREIGN KEY (rm_per) REFERENCES `c2761775_sodapp`.`sap_pm00` (pm_ide)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A role with users cannot be deleted (the API answers 409 first)
ALTER TABLE `c2761775_sodapp`.`sap_us00`
    ADD CONSTRAINT fk_us_rol FOREIGN KEY (us_rol) REFERENCES `c2761775_sodapp`.`sap_ro00` (ro_ide);

INSERT INTO `c2761775_sodapp`.`sap_pm00` (pm_cod, pm_des) VALUES
    ('ventas.ver', 'Ver ventas'),
    ('ventas.cargar', 'Cargar ventas'),
    ('ventas.eliminar', 'Eliminar ventas'),
    ('repartos.ver', 'Ver repartos'),
    ('repartos.gestionar', 'Armar y entregar repartos'),
    ('clientes.ver', 'Ver clientes'),
    ('clientes.editar', 'Crear, editar y eliminar clientes'),
    ('cobros.ver', 'Ver cobros y deudas'),
    ('cobros.cargar', 'Cargar cobros'),
    ('stock.ver', 'Ver stock'),
    ('stock.editar', 'Editar productos y stock'),
    ('caja.ver', 'Ver caja');

-- One "Vendedor" role per existing owner, with the default permissions
INSERT INTO `c2761775_sodapp`.`sap_ro00` (ro_emp, ro_nom)
SELECT us_ide, 'Vendedor' FROM `c2761775_sodapp`.`sap_us00` WHERE us_emp = us_ide;

INSERT INTO `c2761775_sodapp`.`sap_rm00` (rm_rol, rm_per)
SELECT r.ro_ide, p.pm_ide
FROM `c2761775_sodapp`.`sap_ro00` r
JOIN `c2761775_sodapp`.`sap_pm00` p
  ON p.pm_cod IN ('ventas.ver', 'ventas.cargar', 'repartos.ver', 'repartos.gestionar', 'clientes.ver', 'clientes.editar')
WHERE r.ro_nom = 'Vendedor';

-- After: the same user count, every user is its own owner (us_emp = us_ide, us_rol NULL, us_act 1)
SHOW COLUMNS FROM `c2761775_sodapp`.`sap_us00`;
SELECT us_ide, us_nom, us_emp, us_rol, us_act FROM `c2761775_sodapp`.`sap_us00` ORDER BY us_ide;
-- After: 12 permissions, one Vendedor role per owner with 6 permissions each
SELECT COUNT(*) AS permisos FROM `c2761775_sodapp`.`sap_pm00`;
SELECT r.ro_ide, r.ro_emp, r.ro_nom, COUNT(m.rm_per) AS permisos
FROM `c2761775_sodapp`.`sap_ro00` r
LEFT JOIN `c2761775_sodapp`.`sap_rm00` m ON m.rm_rol = r.ro_ide
GROUP BY r.ro_ide, r.ro_emp, r.ro_nom ORDER BY r.ro_ide;
