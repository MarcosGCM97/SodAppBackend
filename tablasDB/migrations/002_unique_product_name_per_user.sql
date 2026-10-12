-- Product names are unique per owner (A10): two users may each have a product
-- with the same name, but one user cannot have it twice.
-- Run AFTER 001_owner_columns_to_user_id.sql and a backup of sap_pr00.
--
-- Review the step 1 report before running step 2: step 2 fails (errno 1062)
-- while duplicates remain. Resolve them by hand (rename or delete), because
-- ventas reference products by name (vt_pro = pr_nom).

-- 1. Report: duplicated (pr_emp, pr_nom) pairs (must be empty before step 2)
SELECT pr_emp, pr_nom, COUNT(*) AS total, GROUP_CONCAT(pr_ide ORDER BY pr_ide) AS ids
FROM sap_pr00
GROUP BY pr_emp, pr_nom
HAVING COUNT(*) > 1;

-- 2. Unique key; it also serves the (pr_emp, pr_nom) lookups, so the
--    non-unique index from 001 is redundant and dropped in the same statement.
ALTER TABLE sap_pr00
    DROP INDEX idx_pr_emp_nom,
    ADD UNIQUE KEY uq_pr_emp_nom (pr_emp, pr_nom);

-- 3. After
SHOW INDEX FROM sap_pr00;
