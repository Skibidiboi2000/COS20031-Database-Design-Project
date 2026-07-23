DROP DATABASE IF EXISTS smart_fleet;
CREATE DATABASE smart_fleet;
USE smart_fleet;

CREATE TABLE Depots (
	depot_id INT AUTO_INCREMENT PRIMARY KEY,
    depot_name VARCHAR(100) NOT NULL UNIQUE,
    location_details VARCHAR(255) NOT NULL
);

CREATE TABLE Vehicle_Categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE Certifications (
    cert_id INT AUTO_INCREMENT PRIMARY KEY,
    cert_name VARCHAR(100) NOT NULL UNIQUE
);

CREATE TABLE Drivers (
    driver_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    contact_information VARCHAR(255) UNIQUE,
    emergency_contact VARCHAR(255),
    licence_type VARCHAR(50),
    licence_expiry_date DATE,
    employment_status ENUM('Active','On Leave','Suspended','Inactive') DEFAULT 'Active',
    current_depot_id INT NOT NULL,
    CONSTRAINT fk_driver_depot FOREIGN KEY(current_depot_id)
      REFERENCES Depots(depot_id)
);

CREATE TABLE Driver_Certifications (
    driver_id INT,
    cert_id INT,
    expiration_date DATE,
    PRIMARY KEY(driver_id,cert_id),
    FOREIGN KEY(driver_id) REFERENCES Drivers(driver_id),
    FOREIGN KEY(cert_id) REFERENCES Certifications(cert_id)
);

CREATE TABLE Vehicle_Requirement_Matrix (
    cert_id INT,
    category_id INT,
    PRIMARY KEY(cert_id,category_id),
    FOREIGN KEY(cert_id) REFERENCES Certifications(cert_id),
    FOREIGN KEY(category_id) REFERENCES Vehicle_Categories(category_id)
);

CREATE TABLE Vehicles (
    vin CHAR(17) PRIMARY KEY,
    registration_number VARCHAR(20) NOT NULL UNIQUE,
    category_id INT NOT NULL,
    current_depot_id INT NOT NULL,
    manufacturer VARCHAR(100),
    model VARCHAR(100),
    year_of_manufacture INT CHECK(year_of_manufacture>=1990),
    current_odometer DOUBLE DEFAULT 0 CHECK(current_odometer>=0),
    operational_status ENUM(
        'Available',
        'Active',
        'Under Maintenance',
        'Awaiting Inspection',
        'Out of Service',
        'Retired'
    ) DEFAULT 'Available',
    FOREIGN KEY(category_id) REFERENCES Vehicle_Categories(category_id),
    FOREIGN KEY(current_depot_id) REFERENCES Depots(depot_id)
);

CREATE TABLE Predictive_Alerts(

alert_id INT AUTO_INCREMENT PRIMARY KEY,
vin CHAR(17) NOT NULL,
alert_type VARCHAR(100),
severity_level ENUM
('Low','Medium','High','Critical'),
generated_at DATETIME,
status ENUM
('Open','Monitoring','Resolved','Escalated'),
alert_threshold_snapshot INT,
FOREIGN KEY(vin)
REFERENCES Vehicles(vin)
);

CREATE TABLE Vehicle_Assignments (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    vin CHAR(17) NOT NULL,
    driver_id INT NOT NULL,
    is_permanent BOOLEAN DEFAULT FALSE,
    assigned_at DATETIME NOT NULL,
    unassigned_at DATETIME NULL,
    FOREIGN KEY(vin) REFERENCES Vehicles(vin),
    FOREIGN KEY(driver_id) REFERENCES Drivers(driver_id),
    CHECK(unassigned_at IS NULL OR unassigned_at>=assigned_at)
);

CREATE TABLE Monthly_Safety_Scores (
    score_id INT AUTO_INCREMENT PRIMARY KEY,
	driver_id INT NOT NULL,
	score_month DATE NOT NULL,
    base_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    event_penalty DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    repeat_penalty DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    total_penalty DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    final_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    coaching_required BOOLEAN NOT NULL DEFAULT FALSE,
    grounded BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY(driver_id) REFERENCES Drivers(driver_id),
    UNIQUE KEY uq_driver_month (driver_id, score_month)
);

CREATE TABLE Maintenance_Jobs (
    job_id INT AUTO_INCREMENT PRIMARY KEY,
    depot_id INT NOT NULL,
    vin CHAR(17) NOT NULL,
    date_opened DATETIME NOT NULL,
    date_closed DATETIME,
    downtime_hours INT DEFAULT 0 CHECK(downtime_hours>=0),
    total_cost DECIMAL(12,2) DEFAULT 0 CHECK(total_cost>=0),
    linked_alert_id INT,
    FOREIGN KEY(depot_id) REFERENCES Depots(depot_id),
    FOREIGN KEY(vin) REFERENCES Vehicles(vin),
    FOREIGN KEY(linked_alert_id) REFERENCES Predictive_Alerts(alert_id)
);

CREATE TABLE Job_Activities (
    activity_id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    activity_type VARCHAR(100),
    diagnostic_result VARCHAR(255),
    repeat_fault_indicator BOOLEAN,
    warranty_indicator BOOLEAN,
    FOREIGN KEY(job_id) REFERENCES Maintenance_Jobs(job_id)
);

CREATE TABLE Mechanics (
    mechanic_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    contact_information VARCHAR(255) UNIQUE,
    depot_id INT NOT NULL,
    hire_date DATE,
    FOREIGN KEY(depot_id) REFERENCES Depots(depot_id)
);

CREATE TABLE Mechanic_Certifications (
    mechanic_id INT,
    cert_id INT,
    expiration_date DATE,
    PRIMARY KEY(mechanic_id,cert_id),
    FOREIGN KEY(mechanic_id) REFERENCES Mechanics(mechanic_id),
    FOREIGN KEY(cert_id) REFERENCES Certifications(cert_id)
);

CREATE TABLE Activity_Labor_Logs (
    activity_id INT,
    mechanic_id INT,
    labour_hours DOUBLE,
    PRIMARY KEY(activity_id,mechanic_id),
    FOREIGN KEY(activity_id) REFERENCES Job_Activities(activity_id),
    FOREIGN KEY(mechanic_id) REFERENCES Mechanics(mechanic_id)
);

CREATE TABLE Telematics_Events (
    event_id INT AUTO_INCREMENT PRIMARY KEY,
    depot_id INT NOT NULL,
    driver_id INT NOT NULL,
    vin CHAR(17) NOT NULL,
    event_timestamp DATETIME,
    event_type VARCHAR(50),
    severity_level ENUM('Low','Medium','High','Critical'),
    snapshot_odometer DOUBLE,
    FOREIGN KEY(depot_id) REFERENCES Depots(depot_id),
    FOREIGN KEY(driver_id) REFERENCES Drivers(driver_id),
    FOREIGN KEY(vin) REFERENCES Vehicles(vin)
);

CREATE TABLE Safety_Reviews (
    review_id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    reviewed_by VARCHAR(100),
    review_date DATETIME,
    comments VARCHAR(255),
    recommendations VARCHAR(255),
    is_completed BOOLEAN DEFAULT FALSE,
    FOREIGN KEY(event_id) REFERENCES Telematics_Events(event_id)
);

CREATE INDEX idx_vehicle_depot ON Vehicles(current_depot_id);
CREATE INDEX idx_vehicle_category ON Vehicles(category_id);
CREATE INDEX idx_driver_depot ON Drivers(current_depot_id);
CREATE INDEX idx_event_time ON Telematics_Events(event_timestamp);

SELECT * FROM certifications;
SELECT * FROM Depots;
SELECT * FROM Drivers;
SELECT * FROM Mechanics;
SELECT * FROM Vehicle_categories;
SELECT * FROM Vehicles;
------------------------------------------
INSERT INTO Vehicle_Requirement_Matrix
(cert_id,category_id)
VALUES
(1,1),
(2,1),
(2,2),
(3,2),
(3,3),
(4,4),
(5,5),
(6,5);
------------------------------------------
INSERT INTO Driver_Certifications
(driver_id,cert_id,expiration_date)
SELECT driver_id,
1+MOD(driver_id,6),
DATE_ADD(CURDATE(),
INTERVAL 365 DAY)
FROM Drivers;
SHOW TABLES;
------------------------------------------
INSERT INTO Driver_Certifications (driver_id,cert_id,expiration_date)
SELECT driver_id,
1+MOD(driver_id+2,6),
DATE_ADD(CURDATE(),
INTERVAL 600 DAY)
FROM Drivers
WHERE driver_id<=40;
------------------------------------------
INSERT INTO Mechanic_Certifications(mechanic_id,cert_id,expiration_date)
SELECT mechanic_id,
1+MOD(mechanic_id,6),
DATE_ADD(CURDATE(),
INTERVAL 500 DAY)
FROM Mechanics;
------------------------------------------
INSERT INTO Vehicle_Assignments (vin, driver_id, is_permanent, assigned_at)
SELECT v.vin, d.driver_id,
       TRUE,
       NOW() - INTERVAL MOD(d.driver_id,300) DAY
FROM Vehicles v
JOIN Drivers d ON v.current_depot_id = d.current_depot_id
WHERE v.operational_status NOT IN ('Under Maintenance','Out of Service','Retired')
  AND d.employment_status = 'Active'
LIMIT 80;
------------------------------------------
INSERT INTO Predictive_Alerts
(vin,alert_type, severity_level, generated_at, status, alert_threshold_snapshot)
SELECT vin,
'Brake Wear',
'Medium',
NOW()-INTERVAL FLOOR(RAND()*200) DAY,
'Open',
80
FROM Vehicles
LIMIT 20;
------------------------------------------
INSERT INTO Predictive_Alerts
(vin, alert_type, severity_level, generated_at, status, alert_threshold_snapshot)
SELECT
vin,
'Engine Temperature',
'High',
NOW()-INTERVAL FLOOR(RAND()*100) DAY,
'Monitoring',
90
FROM Vehicles
LIMIT 15;
------------------------------------------
INSERT INTO Maintenance_Jobs
(depot_id, vin, date_opened, date_closed, downtime_hours, total_cost, linked_alert_id)
SELECT
    v.current_depot_id,
    p.vin,
    p.generated_at,
    p.generated_at + INTERVAL 12 HOUR,
    12,
    3500000,
    p.alert_id
FROM Predictive_Alerts p
JOIN Vehicles v ON p.vin = v.vin;
------------------------------------------
INSERT INTO Job_Activities
(
job_id,
activity_type,
diagnostic_result,
repeat_fault_indicator,
warranty_indicator
)
SELECT
job_id,
'Inspection',
'Brake pads worn',
FALSE,
FALSE
FROM Maintenance_Jobs;
------------------------------------------
INSERT INTO Activity_Labor_Logs
(
activity_id,
mechanic_id,
labour_hours
)
SELECT
activity_id,
1+MOD(activity_id,25),
2.5
FROM Job_Activities;
------------------------------------------
INSERT INTO Telematics_Events
(depot_id, driver_id, vin, event_timestamp, event_type, severity_level, snapshot_odometer)
SELECT
    d.current_depot_id,
    d.driver_id,
    vn.vin,
    NOW() - INTERVAL FLOOR(RAND()*365) DAY,
    'Speeding',
    'Medium',
    25000
FROM Drivers d
JOIN (
    SELECT vin, ROW_NUMBER() OVER (ORDER BY vin) AS rn
    FROM Vehicles
) vn ON vn.rn = 1 + MOD(d.driver_id, 120);

INSERT INTO Monthly_Safety_Scores
(driver_id,score_month,base_score,event_penalty,repeat_penalty,total_penalty,final_score,coaching_required,grounded
)
SELECT
driver_id,
'2026-07-01',
100,
12,
3,
15,
85,
FALSE,
FALSE
FROM Drivers;

INSERT INTO Vehicle_Requirement_Matrix
(cert_id,category_id)
VALUES
(1,1),
(2,1),
(2,2),
(3,2),
(3,3),
(4,4),
(5,5),
(6,5);
