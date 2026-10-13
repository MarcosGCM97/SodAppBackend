-- LOCAL ONLY (sodapp_test). Do NOT run in production.
-- Creates the users that exist in production (Ariza) plus a second test user (Congre)
-- so the owner migration and the isolation tests can run locally.
-- Password for both users: test1234

INSERT INTO sap_us00 (us_ide, us_nom, us_pas)
SELECT 2, 'Ariza', '$2y$10$zly0rbHTfYEey9FNXTb5POIlnxmQm/uEKUDy3SOKpisYSPXK2xEFS'
WHERE NOT EXISTS (SELECT 1 FROM sap_us00 WHERE us_nom = 'Ariza');

INSERT INTO sap_us00 (us_ide, us_nom, us_pas)
SELECT 3, 'Congre', '$2y$10$zly0rbHTfYEey9FNXTb5POIlnxmQm/uEKUDy3SOKpisYSPXK2xEFS'
WHERE NOT EXISTS (SELECT 1 FROM sap_us00 WHERE us_nom = 'Congre');
