-- LOCAL ONLY (sodapp_test). Do NOT run in production.
-- Creates the users that exist in production (Ariza) plus a second test user (Congre)
-- so the owner migration and the isolation tests can run locally.
-- Password for both users: test1234
-- Run it before 011_roles_permisos.sql, which makes every existing user the owner of its
-- own company (us_emp = us_ide) and gives it a Vendedor role. The roles tests
-- (tests/roles_test.php) create their vendedor users and roles themselves.

INSERT INTO sap_us00 (us_ide, us_nom, us_pas)
SELECT 2, 'Ariza', '$2y$10$zly0rbHTfYEey9FNXTb5POIlnxmQm/uEKUDy3SOKpisYSPXK2xEFS'
WHERE NOT EXISTS (SELECT 1 FROM sap_us00 WHERE us_nom = 'Ariza');

INSERT INTO sap_us00 (us_ide, us_nom, us_pas)
SELECT 3, 'Congre', '$2y$10$zly0rbHTfYEey9FNXTb5POIlnxmQm/uEKUDy3SOKpisYSPXK2xEFS'
WHERE NOT EXISTS (SELECT 1 FROM sap_us00 WHERE us_nom = 'Congre');
