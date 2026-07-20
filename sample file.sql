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

SHOW TABLES;
