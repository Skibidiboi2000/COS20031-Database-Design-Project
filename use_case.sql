-- ============================================================
-- SMART FLEET MANAGEMENT DATABASE — FIXING SCRIPT (CORRECTED)
-- Applies to the EXISTING smart_fleet database, already built
-- by Main_Data.sql + Smart_fleet_dependent_tables.sql.
--
-- No CREATE DATABASE / CREATE TABLE here on purpose — schema
-- and data already exist. Running those again would either
-- build an unrelated empty DB (smart_fleetsss) or, if pointed
-- at smart_fleet, DROP/wipe everything you already loaded.
-- ============================================================

USE smart_fleet;

-- ============================================================
-- SECTION A — INDEX CORRECTION
-- Unchanged from fixing.sql logic; table/column/index names
-- all match Main_Data.sql exactly, so no rewrite needed here.
-- ============================================================

SELECT COUNT(*) FROM Drivers;              -- phải > 0
SELECT COUNT(*) FROM Vehicle_Assignments;  -- phải > 0
SELECT driver_id FROM Drivers WHERE driver_id = 5;  -- UC1/UC3/UC5 hardcode driver_id=5, phải tồn tại

-- 1. Drop redundant FK-duplicate indexes (InnoDB auto-indexes
--    FK columns already; these five add write overhead with
--    zero read benefit).
DROP INDEX idx_assignment_driver ON Vehicle_Assignments;
DROP INDEX idx_assignment_vehicle ON Vehicle_Assignments;
DROP INDEX idx_alert_vehicle ON Predictive_Alerts;
DROP INDEX idx_job_vehicle ON Maintenance_Jobs;
DROP INDEX idx_job_depot ON Maintenance_Jobs;

-- 2. Drop idx_driver_status: employment_status is a 4-value
--    ENUM with poor selectivity — optimiser will prefer a full
--    scan over this index anyway (confirmed by EXPLAIN below).
DROP INDEX idx_driver_status ON Drivers;

-- 3. Rebuild idx_vehicle_status as composite, matching the
--    Depot Fleet Report's actual JOIN + GROUP BY pattern
--    (filters/groups on current_depot_id AND operational_status
--    together, not operational_status alone).
DROP INDEX idx_vehicle_status ON Vehicles;
CREATE INDEX idx_vehicle_status
    ON Vehicles(current_depot_id, operational_status);

-- 4. New composite index for the "driver's currently open
--    assignment" lookup pattern (used by UC1 and by the
--    Telematics_Events generation in the dependent-tables
--    script). Equality column first, NULL-filter column second.
CREATE INDEX idx_driver_assignment
    ON Vehicle_Assignments(driver_id, unassigned_at);

-- 5. No index added for Maintenance_Jobs.job_id lookups —
--    it's the PRIMARY KEY, already indexed.
-- 6. No index added for Monthly_Safety_Scores(driver_id,
--    score_month) lookups — already covered by
--    uq_driver_month, defined in Main_Data.sql.

-- ------------------------------------------------------------
-- Verification — record actual `key`/`possible_keys`/`rows`
-- from EXPLAIN output, don't assume the predicted key was used.
-- ------------------------------------------------------------

-- Expected: key = idx_driver_assignment
EXPLAIN
SELECT assignment_id
FROM Vehicle_Assignments
WHERE driver_id = 5
  AND unassigned_at IS NULL;

-- Expected: key = PRIMARY
EXPLAIN
SELECT *
FROM Maintenance_Jobs
WHERE job_id = 12;

-- Expected: key = uq_driver_month
EXPLAIN
SELECT *
FROM Monthly_Safety_Scores
WHERE driver_id = 5
  AND score_month = '2026-07-01';

-- Expected: key = idx_vehicle_status, or a full scan if the
-- optimiser judges the table too small relative to depot count —
-- record whichever actually happens.
EXPLAIN
SELECT
    d.depot_name,
    v.operational_status,
    COUNT(v.vin) AS total_vehicles,
    COUNT(va.assignment_id) AS assigned_vehicles
FROM Depots d
JOIN Vehicles v ON d.depot_id = v.current_depot_id
LEFT JOIN Vehicle_Assignments va
    ON v.vin = va.vin AND va.unassigned_at IS NULL
GROUP BY d.depot_name, v.operational_status;

-- Expected: key = NULL / full scan — confirms the DROP INDEX
-- idx_driver_status decision above was correct.
EXPLAIN
SELECT driver_id
FROM Drivers
WHERE employment_status = 'Active';


-- ============================================================
-- SECTION B — USE CASE QUERIES (TRANSACTION-WRAPPED)
-- Rewritten to use real values pulled from the actual loaded
-- data instead of hardcoded literals (job_id=12, VIN LIMIT 1,
-- score_month='2026-07-01') that aren't guaranteed to exist
-- or to be correct against this dataset.
-- ============================================================

-- ---- Use Case 1: Reassigning driver_id = 5 to a new vehicle ----
-- Two statements represent one real-world event. Transaction
-- ensures MySQL applies both or neither — otherwise a dropped
-- connection mid-operation could leave the driver assigned to
-- two vehicles at once.
--
-- Fix vs. original: candidate vehicle must not be Under
-- Maintenance / Out of Service / Retired (business rule), and
-- must not already be the driver's open assignment.
START TRANSACTION;

UPDATE Vehicle_Assignments
SET unassigned_at = NOW()
WHERE driver_id = 5
  AND unassigned_at IS NULL;

INSERT INTO Vehicle_Assignments (vin, driver_id, is_permanent, assigned_at)
SELECT v.vin, 5, FALSE, NOW()
FROM Vehicles v
WHERE v.operational_status NOT IN ('Under Maintenance', 'Out of Service', 'Retired')
  AND v.vin NOT IN (
      SELECT vin FROM Vehicle_Assignments WHERE unassigned_at IS NULL
  )
ORDER BY v.vin
LIMIT 1;                       -- demo picks the first eligible VIN; replace with a specific VIN in production use

COMMIT;


-- ---- Use Case 2: Closing an open maintenance job ----
-- Fix vs. original: job_id=12 was hardcoded and not guaranteed
-- to exist or be open. Pull a real open job_id first.
SET @target_job_id = (
    SELECT job_id
    FROM Maintenance_Jobs
    WHERE date_closed IS NULL
    ORDER BY job_id
    LIMIT 1
);

START TRANSACTION;

UPDATE Maintenance_Jobs
SET date_closed = NOW(),
    total_cost  = 450.00
WHERE job_id = @target_job_id;

UPDATE Vehicles v
JOIN Maintenance_Jobs m ON v.vin = m.vin
SET v.operational_status = 'Available'
WHERE m.job_id = @target_job_id;

COMMIT;


-- ---- Use Case 3: Logging a safety incident for driver_id = 5 ----
-- Fix vs. original: `JOIN Vehicles v ON TRUE LIMIT 1` was an
-- arbitrary cross join, ignoring which vehicle the driver is
-- actually assigned to. Uses the driver's open assignment,
-- falling back to a same-depot vehicle — same pattern as the
-- dependent-tables Telematics_Events generation.
--
-- Also fixed: score_month was hardcoded to '2026-07-01'; now
-- derived from the actual event timestamp, so the UPDATE always
-- targets the row that exists for the current month.
START TRANSACTION;

INSERT INTO Telematics_Events
    (depot_id, driver_id, vin, event_timestamp, event_type, severity_level, snapshot_odometer)
SELECT
    d.current_depot_id,
    d.driver_id,
    COALESCE(
        (SELECT va.vin FROM Vehicle_Assignments va
         WHERE va.driver_id = d.driver_id AND va.unassigned_at IS NULL
         LIMIT 1),
        (SELECT v.vin FROM Vehicles v
         WHERE v.current_depot_id = d.current_depot_id
         ORDER BY v.vin LIMIT 1)
    ) AS vin,
    NOW(),
    'Harsh Braking',
    'High',
    (SELECT vv.current_odometer FROM Vehicles vv WHERE vv.vin = COALESCE(
        (SELECT va.vin FROM Vehicle_Assignments va
         WHERE va.driver_id = d.driver_id AND va.unassigned_at IS NULL
         LIMIT 1),
        (SELECT v2.vin FROM Vehicles v2
         WHERE v2.current_depot_id = d.current_depot_id
         ORDER BY v2.vin LIMIT 1)
    ))
FROM Drivers d
WHERE d.driver_id = 5;

-- Ensure a Monthly_Safety_Scores row exists for this driver/month
-- before updating it (INSERT ... ON DUPLICATE KEY handles both
-- "first event this month" and "already has a row" cases).
INSERT INTO Monthly_Safety_Scores
    (driver_id, score_month, base_score, event_penalty, repeat_penalty, total_penalty, final_score,
     coaching_required, grounded)
VALUES
    (5, DATE_FORMAT(CURDATE(), '%Y-%m-01'), 100.00, 0.00, 0.00, 0.00, 100.00, FALSE, FALSE)
ON DUPLICATE KEY UPDATE driver_id = driver_id;   -- no-op if row already exists

UPDATE Monthly_Safety_Scores
SET event_penalty = event_penalty + 8,           -- High severity penalty (per dependent-tables weighting: Low=1, Med=3, High=8, Critical=20)
    total_penalty  = event_penalty + 8 + repeat_penalty,
    final_score    = GREATEST(0, base_score - (event_penalty + 8 + repeat_penalty)),
    coaching_required = (base_score - (event_penalty + 8 + repeat_penalty)) <= 75,
    grounded           = (base_score - (event_penalty + 8 + repeat_penalty)) <= 50
WHERE driver_id = 5
  AND score_month = DATE_FORMAT(CURDATE(), '%Y-%m-01');

COMMIT;


-- ---- Use Case 4: Depot Operational Status Fleet Report ----
-- Read-only, no transaction needed — InnoDB gives a single
-- statement a consistent snapshot on its own. Unchanged.
SELECT
    d.depot_name,
    v.operational_status,
    COUNT(v.vin) AS total_vehicles,
    COUNT(va.assignment_id) AS assigned_vehicles
FROM Depots d
JOIN Vehicles v ON d.depot_id = v.current_depot_id
LEFT JOIN Vehicle_Assignments va
    ON v.vin = va.vin AND va.unassigned_at IS NULL
GROUP BY d.depot_name, v.operational_status;


-- ---- Use Case 5: Verifying certification before assignment ----
-- Fix vs. original: `v.vin = (SELECT vin FROM Vehicles LIMIT 1)`
-- was an arbitrary placeholder VIN unrelated to the driver being
-- checked. Uses the same VIN Use Case 1 is about to assign, so
-- this acts as an actual pre-assignment gate, not an isolated check.
SELECT COUNT(*) AS is_qualified
FROM Driver_Certifications dc
JOIN Vehicle_Requirement_Matrix vrm ON dc.cert_id = vrm.cert_id
JOIN Vehicles v ON v.category_id = vrm.category_id
WHERE dc.driver_id = 5
  AND v.vin = (
      SELECT vin
      FROM Vehicles
      WHERE operational_status NOT IN ('Under Maintenance', 'Out of Service', 'Retired')
        AND vin NOT IN (SELECT vin FROM Vehicle_Assignments WHERE unassigned_at IS NULL)
      ORDER BY vin
      LIMIT 1
  )
  AND dc.expiration_date >= CURDATE();
  
  
  
  
--  INDEX 1:
show tables;

SELECT
    te.driver_id,
    DATE_FORMAT(te.event_timestamp, '%Y-%m-01') AS month_start,
    SUM(CASE te.severity_level
            WHEN 'Low' THEN 1 WHEN 'Medium' THEN 3 WHEN 'High' THEN 8 WHEN 'Critical' THEN 20
        END) AS event_penalty
FROM Telematics_Events te
GROUP BY te.driver_id, DATE_FORMAT(te.event_timestamp, '%Y-%m-01');


CREATE INDEX idx_telematics_driver_time
ON Telematics_Events(driver_id,event_timestamp);

EXPLAIN
SELECT
    event_id,
    event_type,
    severity_level,
    event_timestamp
FROM Telematics_Events
WHERE driver_id = 5
ORDER BY event_timestamp DESC;


DROP INDEX idx_telematics_driver_time
ON Telematics_Events;

EXPLAIN
SELECT
    event_id,
    event_type,
    severity_level,
    event_timestamp
FROM Telematics_Events
WHERE driver_id = 5
ORDER BY event_timestamp DESC;


CREATE INDEX idx_telematics_driver_time
ON Telematics_Events(driver_id,event_timestamp);



-- Index 2:
CREATE INDEX idx_telematics_severity
ON Telematics_Events(severity_level);

EXPLAIN
SELECT
    event_id,
    driver_id,
    vin,
    event_timestamp,
    severity_level
FROM Telematics_Events
WHERE severity_level IN ('High','Critical');

DROP INDEX idx_telematics_severity
ON Telematics_Events;

EXPLAIN
SELECT
    event_id,
    driver_id,
    vin,
    event_timestamp,
    severity_level
FROM Telematics_Events
WHERE severity_level IN ('High','Critical');

CREATE INDEX idx_telematics_severity
ON Telematics_Events(severity_level);

SHOW INDEX
FROM Telematics_Events;


SELECT COUNT(*)
FROM Telematics_Events;


SELECT
    severity_level,
    COUNT(*) AS total
FROM Telematics_Events
GROUP BY severity_level;
