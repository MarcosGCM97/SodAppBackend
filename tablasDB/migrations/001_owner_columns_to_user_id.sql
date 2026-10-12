-- Converts the owner columns (cl_emp, pr_emp, vt_emp) from user name to user id (sap_us00.us_ide).
-- Run AFTER a backup of sap_cl00, sap_pr00 and sap_vt00.
--
-- Steps 1-3 are idempotent. Review the step 4 report before running step 5:
-- step 5 fails on purpose if any owner is still not a valid user id.

-- Owner for rows with an empty / NULL owner (confirmed: legacy rows belong to Ariza).
SET @fallback_owner = (SELECT us_ide FROM sap_us00 WHERE us_nom = 'Ariza');

-- 1. Before: owner distribution
SELECT 'sap_cl00' AS tabla, cl_emp AS owner, COUNT(*) AS total FROM sap_cl00 GROUP BY cl_emp
UNION ALL SELECT 'sap_pr00', pr_emp, COUNT(*) FROM sap_pr00 GROUP BY pr_emp
UNION ALL SELECT 'sap_vt00', vt_emp, COUNT(*) FROM sap_vt00 GROUP BY vt_emp;

-- 2. Names -> ids (only values that match an existing user name)
UPDATE sap_cl00 c JOIN sap_us00 u ON u.us_nom = c.cl_emp SET c.cl_emp = u.us_ide;
UPDATE sap_pr00 p JOIN sap_us00 u ON u.us_nom = p.pr_emp SET p.pr_emp = u.us_ide;
UPDATE sap_vt00 v JOIN sap_us00 u ON u.us_nom = v.vt_emp SET v.vt_emp = u.us_ide;

-- 3. Empty / NULL owners -> fallback owner
UPDATE sap_cl00 SET cl_emp = @fallback_owner WHERE (cl_emp IS NULL OR TRIM(cl_emp) = '') AND @fallback_owner IS NOT NULL;
UPDATE sap_pr00 SET pr_emp = @fallback_owner WHERE (pr_emp IS NULL OR TRIM(pr_emp) = '') AND @fallback_owner IS NOT NULL;
UPDATE sap_vt00 SET vt_emp = @fallback_owner WHERE (vt_emp IS NULL OR TRIM(vt_emp) = '') AND @fallback_owner IS NOT NULL;

-- 4. Report: rows whose owner is not a valid user id (must be empty before step 5)
SELECT 'sap_cl00' AS tabla, cl_ide AS id, cl_emp AS owner FROM sap_cl00 WHERE cl_emp NOT IN (SELECT CAST(us_ide AS CHAR) FROM sap_us00) OR cl_emp IS NULL
UNION ALL SELECT 'sap_pr00', pr_ide, pr_emp FROM sap_pr00 WHERE pr_emp NOT IN (SELECT CAST(us_ide AS CHAR) FROM sap_us00) OR pr_emp IS NULL
UNION ALL SELECT 'sap_vt00', vt_ide, vt_emp FROM sap_vt00 WHERE vt_emp NOT IN (SELECT CAST(us_ide AS CHAR) FROM sap_us00) OR vt_emp IS NULL;

-- 5. Column types (no 'Ariza' default) and indexes
ALTER TABLE sap_cl00 MODIFY cl_emp INT NOT NULL, ADD INDEX idx_cl_emp (cl_emp);
ALTER TABLE sap_pr00 MODIFY pr_emp INT NOT NULL, ADD INDEX idx_pr_emp_nom (pr_emp, pr_nom);
ALTER TABLE sap_vt00 MODIFY vt_emp INT NOT NULL, ADD INDEX idx_vt_emp_fec (vt_emp, vt_fec);

-- 6. After: owner distribution
SELECT 'sap_cl00' AS tabla, cl_emp AS owner, COUNT(*) AS total FROM sap_cl00 GROUP BY cl_emp
UNION ALL SELECT 'sap_pr00', pr_emp, COUNT(*) FROM sap_pr00 GROUP BY pr_emp
UNION ALL SELECT 'sap_vt00', vt_emp, COUNT(*) FROM sap_vt00 GROUP BY vt_emp;
