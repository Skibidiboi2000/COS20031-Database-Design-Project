USE sample;
INSERT INTO Vehicle_Requirement_Matrix(cert_id,category_id) VALUES
(1,1),(2,1),(3,2),(4,3),(5,5),(6,5);

INSERT IGNORE INTO Driver_Certifications(driver_id,cert_id,expiration_date)
SELECT driver_id,1+FLOOR(RAND()*6),
DATE_ADD(CURDATE(),INTERVAL FLOOR(RAND()*730) DAY)
FROM Drivers;

INSERT IGNORE INTO Mechanic_Certifications(mechanic_id,cert_id,expiration_date)
SELECT mechanic_id,1+FLOOR(RAND()*6),
DATE_ADD(CURDATE(),INTERVAL FLOOR(RAND()*730) DAY)
FROM Mechanics;

INSERT INTO Vehicle_Assignments(vin,driver_id,is_permanent,assigned_at)
SELECT v.vin,d.driver_id,RAND()<0.5,
NOW()-INTERVAL FLOOR(RAND()*365) DAY
FROM Vehicles v
JOIN Drivers d ON v.current_depot_id=d.current_depot_id
LIMIT 80;

INSERT INTO Predictive_Alerts(vin,alert_type,generated_at,status,alert_threshold_snapshot)
SELECT vin,
ELT(1+FLOOR(RAND()*4),'Engine','Brake','Battery','Tyre'),
NOW()-INTERVAL FLOOR(RAND()*365) DAY,
'Open',
70+FLOOR(RAND()*30)
FROM Vehicles
WHERE RAND()<0.35;
