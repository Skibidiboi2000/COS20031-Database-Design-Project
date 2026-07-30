-- ============================================================================
-- smart_fleet — manual INSERT script for dependent/junction tables
-- Assumes base tables already CSV-imported: Depots, Vehicle_Categories,
-- Certifications, Drivers, Mechanics, Vehicles
--
-- Real reference data confirmed from project CSVs:
--   Certifications: 1=Heavy Vehicle Licence, 2=EV Certification,
--                   3=Defensive Driving, 4=Heavy Vehicle Licence (DUPLICATE — do not use),
--                   5=Hazardous Goods Certification, 6=Standard Licence
--   Vehicle_Categories: 1=Delivery Van, 2=Refrigerated Truck, 3=Electric Van,
--                       4=Service Vehicle, 5=Heavy Transport Truck
--   Depots: 1=Ha Noi, 2=Da Nang, 3=Ho Chi Minh City, 4=Can Tho
--
-- Predictive_Alerts_DATA.csv, Maintenance_Jobs_DATA.csv and
-- Monthly_Safety_Scores_DATA.csv are corrupted Mockaroo exports (blank vin,
-- dumped enum in `status`, "error: Field 'this' not found" in computed
-- columns) — not used as a data source here. All values below are generated
-- in SQL directly, per project's "Data Generation" ranges.
-- ============================================================================

USE smart_fleet;

-- ----------------------------------------------------------------------------
-- 0. CLEANUP — delete in reverse dependency order (children before parents)
--    so the script is safely re-runnable without disabling FK checks.
-- ----------------------------------------------------------------------------
DELETE FROM Safety_Reviews;
DELETE FROM Activity_Labor_Logs;
DELETE FROM Job_Activities;
DELETE FROM Maintenance_Jobs;
DELETE FROM Telematics_Events;
DELETE FROM Predictive_Alerts;
DELETE FROM Vehicle_Assignments;
DELETE FROM Monthly_Safety_Scores;
DELETE FROM Mechanic_Certifications;
DELETE FROM Driver_Certifications;
DELETE FROM Vehicle_Requirement_Matrix;

-- Reset AUTO_INCREMENT so ids stay clean on repeated runs
ALTER TABLE Predictive_Alerts AUTO_INCREMENT = 1;
ALTER TABLE Vehicle_Assignments AUTO_INCREMENT = 1;
ALTER TABLE Monthly_Safety_Scores AUTO_INCREMENT = 1;
ALTER TABLE Maintenance_Jobs AUTO_INCREMENT = 1;
ALTER TABLE Job_Activities AUTO_INCREMENT = 1;
ALTER TABLE Telematics_Events AUTO_INCREMENT = 1;
ALTER TABLE Safety_Reviews AUTO_INCREMENT = 1;

-- ============================================================================
-- 1. Vehicle_Requirement_Matrix
--    cert_id 4 is a duplicate of cert_id 1 (both "Heavy Vehicle Licence") —
--    intentionally excluded. Mapping based on real category/cert names.
-- ============================================================================
INSERT INTO Vehicle_Requirement_Matrix (cert_id, category_id) VALUES
(6,1),                 -- Delivery Van: Standard Licence
(6,2),(3,2),            -- Refrigerated Truck: Standard Licence + Defensive Driving
(6,3),(2,3),            -- Electric Van: Standard Licence + EV Certification
(6,4),                 -- Service Vehicle: Standard Licence
(1,5),(5,5),(3,5);      -- Heavy Transport Truck: Heavy Vehicle Licence + Hazardous Goods + Defensive Driving

-- ============================================================================
-- 2. Driver_Certifications
--    Baseline: every driver holds Standard Licence (some expired, to exercise
--    the "expired cert -> cannot be assigned" rule). ~50% also hold a second,
--    specialised cert.
-- ============================================================================
INSERT INTO Driver_Certifications (driver_id, cert_id, expiration_date)
SELECT
    driver_id,
    6,
    CASE WHEN MOD(driver_id,7) = 0
         THEN DATE_SUB(CURDATE(), INTERVAL 30 DAY)   -- ~14% expired, on purpose
         ELSE DATE_ADD(CURDATE(), INTERVAL 365 DAY)
    END
FROM Drivers;

INSERT INTO Driver_Certifications (driver_id, cert_id, expiration_date)
SELECT
    driver_id,
    ELT(1 + MOD(driver_id,4), 1, 2, 3, 5),           -- rotates Heavy/EV/Defensive/Hazardous
    DATE_ADD(CURDATE(), INTERVAL (200 + MOD(driver_id*7,400)) DAY)
FROM Drivers
WHERE MOD(driver_id,2) = 0;

-- ============================================================================
-- 3. Mechanic_Certifications
--    Note: schema conflates driver and mechanic certs into one Certifications
--    table; there is no dedicated "Standard Vehicle Mechanic Licence" or
--    "Refrigeration Systems Certification" row in the actual data. Approximated:
--    cert 6 (Standard Licence) ~ baseline mechanic licence, cert 1 (Heavy
--    Vehicle Licence) ~ heavy vehicle repair, cert 2 (EV Certification) ~ EV
--    technician. Flag this to whoever owns the schema — worth splitting.
-- ============================================================================
INSERT INTO Mechanic_Certifications (mechanic_id, cert_id, expiration_date)
SELECT mechanic_id, 6, DATE_ADD(CURDATE(), INTERVAL 365 DAY)
FROM Mechanics;

INSERT INTO Mechanic_Certifications (mechanic_id, cert_id, expiration_date)
SELECT mechanic_id, ELT(1 + MOD(mechanic_id,3), 1, 2, 5), DATE_ADD(CURDATE(), INTERVAL 300 DAY)
FROM Mechanics
WHERE MOD(mechanic_id,2) = 0;

-- ============================================================================
-- 4. Vehicle_Assignments
--    Fixed: column/value count now matches. Round-robins vehicles across
--    active drivers; rank 1 per driver is the only assignment that can be
--    "open" (unassigned_at IS NULL), guaranteeing no overlapping active
--    assignments per driver. Retired vehicles are never assigned. Vehicles
--    currently Under Maintenance / Out of Service never get an OPEN
--    assignment (their rank-1 row is force-closed).
-- ============================================================================
INSERT INTO Vehicle_Assignments (vin, driver_id, is_permanent, assigned_at, unassigned_at)
SELECT
    vin,
    driver_id,
    (assignment_rank = 1) AS is_permanent,
    DATE_SUB(CURDATE(), INTERVAL (30 * assignment_rank) DAY) AS assigned_at,
    CASE
        WHEN assignment_rank = 1 AND operational_status NOT IN ('Under Maintenance','Out of Service')
            THEN NULL                                                     -- currently active
        WHEN assignment_rank = 1
            THEN DATE_SUB(CURDATE(), INTERVAL 5 DAY)                      -- closed: vehicle unavailable
        ELSE DATE_SUB(CURDATE(), INTERVAL (30 * (assignment_rank - 1) + 5) DAY) -- historical
    END AS unassigned_at
FROM (
    SELECT rv.vin, rv.operational_status, ad.driver_id,
           ROW_NUMBER() OVER (PARTITION BY ad.driver_id ORDER BY rv.vin) AS assignment_rank
    FROM (
        SELECT vin, operational_status,
               ROW_NUMBER() OVER (ORDER BY vin) AS rn
        FROM Vehicles
        WHERE operational_status <> 'Retired'
    ) rv
    JOIN (
        SELECT driver_id,
               ROW_NUMBER() OVER (ORDER BY driver_id) AS rn,
               COUNT(*) OVER () AS total
        FROM Drivers
        WHERE employment_status = 'Active'
    ) ad ON ad.rn = ((rv.rn - 1) MOD ad.total) + 1
) mapped;

-- ============================================================================
-- 5. Predictive_Alerts
--    ~33% of vehicles get one alert (within the 20-40% spec). Deterministic
--    vehicle selection (every 3rd by vin) instead of LIMIT-without-ORDER-BY.
-- ============================================================================
INSERT INTO Predictive_Alerts (vin, alert_type, severity_level, generated_at, status, alert_threshold_snapshot)
SELECT
    vin,
    ELT(1 + MOD(rn,7), 'Brake Wear','Engine Overheating Risk','Battery Degradation',
        'Oil Quality Deterioration','Transmission Fault','Cooling System Anomaly','Tyre Pressure Irregularity'),
    ELT(1 + MOD(rn,4), 'Low','Medium','High','Critical'),
    NOW() - INTERVAL FLOOR(RAND()*180) DAY,
    ELT(1 + MOD(rn,4), 'Open','Monitoring','Resolved','Escalated'),
    60 + MOD(rn*13,40)
FROM (
    SELECT vin, ROW_NUMBER() OVER (ORDER BY vin) AS rn FROM Vehicles
) v
WHERE MOD(rn,3) = 0;

-- ============================================================================
-- 6. Maintenance_Jobs
--    6a: ~70% of alerts escalate into a job (satisfies "alert does not always
--        result in a job"). ~20% of those stay open (date_closed NULL).
--    6b: independent scheduled-service jobs, no linked alert, for ~half the fleet.
-- ============================================================================
INSERT INTO Maintenance_Jobs (depot_id, vin, date_opened, date_closed, downtime_hours, total_cost, linked_alert_id)
SELECT
    v.current_depot_id,
    p.vin,
    p.generated_at + INTERVAL (1 + MOD(p.alert_id,3)) DAY AS date_opened,
    CASE WHEN MOD(p.alert_id,5) = 0 THEN NULL
         ELSE p.generated_at + INTERVAL (1 + MOD(p.alert_id,3)) DAY + INTERVAL (4 + MOD(p.alert_id,48)) HOUR
    END AS date_closed,
    4 + MOD(p.alert_id,48) AS downtime_hours,
    ROUND(500000 + RAND()*19500000, 2) AS total_cost,
    p.alert_id
FROM Predictive_Alerts p
JOIN Vehicles v ON v.vin = p.vin
WHERE MOD(p.alert_id,10) < 7;

INSERT INTO Maintenance_Jobs (depot_id, vin, date_opened, date_closed, downtime_hours, total_cost, linked_alert_id)
SELECT
    v.current_depot_id,
    v.vin,
    NOW() - INTERVAL FLOOR(RAND()*300) DAY AS date_opened,
    CASE WHEN MOD(v.rn,6) = 0 THEN NULL
         ELSE NOW() - INTERVAL FLOOR(RAND()*300) DAY + INTERVAL (3 + MOD(v.rn,40)) HOUR
    END AS date_closed,
    2 + MOD(v.rn,20) AS downtime_hours,
    ROUND(500000 + RAND()*19500000, 2) AS total_cost,
    NULL AS linked_alert_id
FROM (
    SELECT vin, current_depot_id, ROW_NUMBER() OVER (ORDER BY vin) AS rn FROM Vehicles
) v
WHERE MOD(v.rn,2) = 0;

-- ============================================================================
-- 7. Job_Activities — varied activity types per job, ~40% of jobs get a 2nd activity
-- ============================================================================
INSERT INTO Job_Activities (job_id, activity_type, diagnostic_result, repeat_fault_indicator, warranty_indicator)
SELECT
    job_id,
    ELT(1 + MOD(job_id,8), 'Routine Inspection','Preventative Servicing','Diagnostic Testing',
        'Emergency Repair','Component Replacement','EV Battery/Electrical Repair',
        'Refrigeration System Repair','Heavy Vehicle Repair'),
    ELT(1 + MOD(job_id,5), 'OK - no fault found','Pads worn below 3mm','Belt cracked - repeat issue',
        'Sensor fault detected','Fluid leak identified'),
    MOD(job_id,7) = 0,
    MOD(job_id,4) = 0
FROM Maintenance_Jobs;

INSERT INTO Job_Activities (job_id, activity_type, diagnostic_result, repeat_fault_indicator, warranty_indicator)
SELECT
    job_id,
    ELT(1 + MOD(job_id+3,8), 'Routine Inspection','Preventative Servicing','Diagnostic Testing',
        'Emergency Repair','Component Replacement','EV Battery/Electrical Repair',
        'Refrigeration System Repair','Heavy Vehicle Repair'),
    'Secondary check completed',
    FALSE,
    FALSE
FROM Maintenance_Jobs
WHERE MOD(job_id,5) = 0;

-- ============================================================================
-- 8. Activity_Labor_Logs
--    Mechanic must hold the cert required by the activity type; same-depot
--    mechanics are preferred but the fleet-wide match is used as fallback so
--    no activity is ever left without a valid mechanic.
-- ============================================================================
INSERT INTO Activity_Labor_Logs (activity_id, mechanic_id, labour_hours)
SELECT activity_id, mechanic_id, ROUND(0.5 + RAND()*5.5, 1)
FROM (
    SELECT an.activity_id, m.mechanic_id,
           ROW_NUMBER() OVER (
               PARTITION BY an.activity_id
               ORDER BY (m.depot_id <> an.depot_id), RAND()
           ) AS rn
    FROM (
        SELECT ja.activity_id, mj.depot_id,
               CASE ja.activity_type
                   WHEN 'EV Battery/Electrical Repair' THEN 2
                   WHEN 'Heavy Vehicle Repair' THEN 1
                   ELSE 6
               END AS required_cert_id
        FROM Job_Activities ja
        JOIN Maintenance_Jobs mj ON mj.job_id = ja.job_id
    ) an
    JOIN Mechanic_Certifications mc ON mc.cert_id = an.required_cert_id
    JOIN Mechanics m ON m.mechanic_id = mc.mechanic_id
) candidates
WHERE rn = 1;

INSERT INTO Activity_Labor_Logs (activity_id, mechanic_id, labour_hours)
SELECT activity_id, mechanic_id, ROUND(0.5 + RAND()*3, 1)
FROM (
    SELECT an.activity_id, m.mechanic_id,
           ROW_NUMBER() OVER (
               PARTITION BY an.activity_id
               ORDER BY (m.depot_id <> an.depot_id), RAND()
           ) AS rn
    FROM (
        SELECT ja.activity_id, mj.depot_id,
               CASE ja.activity_type
                   WHEN 'EV Battery/Electrical Repair' THEN 2
                   WHEN 'Heavy Vehicle Repair' THEN 1
                   ELSE 6
               END AS required_cert_id
        FROM Job_Activities ja
        JOIN Maintenance_Jobs mj ON mj.job_id = ja.job_id
    ) an
    JOIN Mechanic_Certifications mc ON mc.cert_id = an.required_cert_id
    JOIN Mechanics m ON m.mechanic_id = mc.mechanic_id
) candidates
WHERE rn = 2 AND MOD(activity_id,4) = 0;    -- ~25% of activities need a second mechanic

-- ============================================================================
-- 9. Telematics_Events
--    12 events per driver spread over the last 180 days, using either the
--    driver's currently open assignment or a same-depot vehicle as fallback.
-- ============================================================================
INSERT INTO Telematics_Events (depot_id, driver_id, vin, event_timestamp, event_type, severity_level, snapshot_odometer)
SELECT
    dv.current_depot_id,
    dv.driver_id,
    dv.vin,
    NOW() - INTERVAL FLOOR(RAND()*180) DAY - INTERVAL FLOOR(RAND()*24) HOUR,
    ELT(1 + FLOOR(RAND()*8), 'Harsh Braking','Rapid Acceleration','Excessive Speeding','Sharp Cornering',
        'Excessive Idling','Fatigue Warning','Seatbelt Violation','Phone Distraction'),
    CASE
        WHEN RAND() < 0.55 THEN 'Low'
        WHEN RAND() < 0.80 THEN 'Medium'
        WHEN RAND() < 0.93 THEN 'High'
        ELSE 'Critical'
    END,
    (SELECT v.current_odometer FROM Vehicles v WHERE v.vin = dv.vin) + ROUND(RAND()*2000)
FROM (
    SELECT
        d.driver_id,
        d.current_depot_id,
        COALESCE(
            (SELECT va.vin FROM Vehicle_Assignments va
             WHERE va.driver_id = d.driver_id AND va.unassigned_at IS NULL LIMIT 1),
            (SELECT v.vin FROM Vehicles v
             WHERE v.current_depot_id = d.current_depot_id ORDER BY v.vin LIMIT 1)
        ) AS vin
    FROM Drivers d
) dv
JOIN (
    WITH RECURSIVE seq AS (
        SELECT 1 AS n
        UNION ALL
        SELECT n + 1 FROM seq WHERE n < 12
    )
    SELECT n FROM seq
) s;

-- ============================================================================
-- 10. Safety_Reviews — only High/Critical events get a review, per business rule
-- ============================================================================
INSERT INTO Safety_Reviews (event_id, reviewed_by, review_date, comments, recommendations, is_completed)
SELECT
    te.event_id,
    ELT(1 + MOD(te.event_id,5), 'Nguyen Van Hoa','Tran Thi Mai','Le Quoc Minh','Pham Duc Long','Do Thi Lan'),
    te.event_timestamp + INTERVAL (1 + MOD(te.event_id,5)) DAY,
    CASE te.severity_level
        WHEN 'Critical' THEN 'Critical safety event reviewed - immediate action required'
        ELSE 'High severity event reviewed - monitoring driver behaviour'
    END,
    CASE WHEN te.severity_level = 'Critical' THEN 'Mandatory safety retraining' ELSE 'Coaching recommended' END,
    MOD(te.event_id,3) <> 0            -- ~67% completed
FROM Telematics_Events te
WHERE te.severity_level IN ('High','Critical');

-- Business rule enforcement: a driver with an unresolved Critical event review
-- becomes unable to be assigned until the review is completed / training done.
UPDATE Drivers d
JOIN Telematics_Events te ON te.driver_id = d.driver_id
JOIN Safety_Reviews sr ON sr.event_id = te.event_id
SET d.employment_status = 'Suspended'
WHERE te.severity_level = 'Critical' AND sr.is_completed = FALSE;

-- ============================================================================
-- 11. Monthly_Safety_Scores — calculated from Telematics_Events, not random.
--     Penalty weights (Low=1, Medium=3, High=8, Critical=20) are an assumption:
--     the brief's "Event Penalties" table did not extract cleanly from the docx.
--     Replace with the actual figures if available.
--     repeat_penalty: +10 for each High/Critical event beyond the first in the month.
-- ============================================================================
INSERT INTO Monthly_Safety_Scores
    (driver_id, score_month, base_score, event_penalty, repeat_penalty, total_penalty, final_score, coaching_required, grounded)
SELECT
    driver_id,
    month_start,
    100.00,
    event_penalty,
    repeat_penalty,
    event_penalty + repeat_penalty,
    GREATEST(0, 100 - event_penalty - repeat_penalty),
    (100 - event_penalty - repeat_penalty) <= 75,
    (100 - event_penalty - repeat_penalty) <= 50
FROM (
    SELECT
        te.driver_id,
        DATE_FORMAT(te.event_timestamp, '%Y-%m-01') AS month_start,
        SUM(CASE te.severity_level
                WHEN 'Low' THEN 1 WHEN 'Medium' THEN 3 WHEN 'High' THEN 8 WHEN 'Critical' THEN 20
            END) AS event_penalty,
        GREATEST(0, (SUM(te.severity_level IN ('High','Critical')) - 1) * 10) AS repeat_penalty
    FROM Telematics_Events te
    GROUP BY te.driver_id, DATE_FORMAT(te.event_timestamp, '%Y-%m-01')
) monthly;

-- ============================================================================
-- Recommended additional indexes (created after bulk load, per best practice)
-- ============================================================================
-- Speeds up per-driver/per-month aggregation for Monthly_Safety_Scores and
-- driver safety-history lookups (Telematics_Events.driver_id is already
-- auto-indexed by its FK, but not in combination with event_timestamp).
CREATE INDEX idx_telematics_driver_time ON Telematics_Events(driver_id, event_timestamp);

-- Speeds up sourcing Safety_Reviews and any "high-risk events" dashboard query
-- that filters directly on severity_level (not covered by any FK index).
CREATE INDEX idx_telematics_severity ON Telematics_Events(severity_level);

-- ============================================================================
-- Validation
-- ============================================================================
SELECT 'Vehicle_Requirement_Matrix', COUNT(*) FROM Vehicle_Requirement_Matrix
UNION ALL SELECT 'Driver_Certifications', COUNT(*) FROM Driver_Certifications
UNION ALL SELECT 'Mechanic_Certifications', COUNT(*) FROM Mechanic_Certifications
UNION ALL SELECT 'Vehicle_Assignments', COUNT(*) FROM Vehicle_Assignments
UNION ALL SELECT 'Predictive_Alerts', COUNT(*) FROM Predictive_Alerts
UNION ALL SELECT 'Maintenance_Jobs', COUNT(*) FROM Maintenance_Jobs
UNION ALL SELECT 'Job_Activities', COUNT(*) FROM Job_Activities
UNION ALL SELECT 'Activity_Labor_Logs', COUNT(*) FROM Activity_Labor_Logs
UNION ALL SELECT 'Telematics_Events', COUNT(*) FROM Telematics_Events
UNION ALL SELECT 'Safety_Reviews', COUNT(*) FROM Safety_Reviews
UNION ALL SELECT 'Monthly_Safety_Scores', COUNT(*) FROM Monthly_Safety_Scores;

-- Sanity check: no driver should have more than 1 OPEN assignment
SELECT driver_id, COUNT(*) AS open_assignments
FROM Vehicle_Assignments
WHERE unassigned_at IS NULL
GROUP BY driver_id
HAVING COUNT(*) > 1;   -- must return 0 rows