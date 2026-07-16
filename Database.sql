DROP DATABASE IF EXISTS sample;
CREATE DATABASE sample;
USE sample;

CREATE TABLE Depots (
    depot_id INT AUTO_INCREMENT NOT NULL,
    depot_name VARCHAR(100) NOT NULL,
    location VARCHAR(255) NOT NULL,
    
    PRIMARY KEY (depot_id)
);

CREATE TABLE Vehicle_categories (
    category_id INT AUTO_INCREMENT NOT NULL,
    category_name VARCHAR(100) NOT NULL,
    
    PRIMARY KEY (category_id)
);

CREATE TABLE Certifications (
    cert_id INT NOT NULL AUTO_INCREMENT,
    cert_name VARCHAR(100) NOT NULL,
    
    PRIMARY KEY (cert_id)
);

CREATE TABLE Drivers (
    driver_id INT NOT NULL AUTO_INCREMENT,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    contact_information VARCHAR(255),
    emergency_contact VARCHAR(255),
    licence_type VARCHAR(50),
    licence_expiry_date DATE,
    employment_status VARCHAR(50),
    current_depot_id INT,
    
    PRIMARY KEY (driver_id),
    FOREIGN KEY (current_depot_id) REFERENCES Depots(depot_id)
);

CREATE TABLE Driver_Certifications (
    driver_id INT NOT NULL,
    cert_id INT NOT NULL,
    expiration_date DATETIME,
    
    PRIMARY KEY (driver_id, cert_id),
    FOREIGN KEY (driver_id) REFERENCES Drivers(driver_id),
    FOREIGN KEY (cert_id) REFERENCES Certifications(cert_id)
);

CREATE TABLE Vehicle_Requirement_Matrix (
    cert_id INT NOT NULL,
    category_id INT NOT NULL,
    
    PRIMARY KEY (cert_id, category_id),
    FOREIGN KEY (cert_id) REFERENCES Certifications(cert_id),
    FOREIGN KEY (category_id) REFERENCES Vehicle_categories(category_id)
);

CREATE TABLE Vehicles (
    vin VARCHAR(17) NOT NULL,
    registration_number VARCHAR(50) NOT NULL,
    category_id INT NOT NULL,
    current_depot_id INT NOT NULL,
    manufacturer VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    year_of_manufacture INT NOT NULL,
    current_odometer DOUBLE NOT NULL DEFAULT 0,
    operational_status VARCHAR(50) NOT NULL DEFAULT 'Available',
    assigned_driver_id INT DEFAULT NULL,
    
    PRIMARY KEY (vin),
    FOREIGN KEY (category_id) REFERENCES Vehicle_categories(category_id),
    FOREIGN KEY (current_depot_id) REFERENCES Depots(depot_id),
    FOREIGN KEY (assigned_driver_id) REFERENCES Drivers(driver_id)
);

CREATE TABLE Predictive_Alerts (
    alert_id INT NOT NULL AUTO_INCREMENT,
    vin VARCHAR(17) NOT NULL,
    alert_type VARCHAR(255) NOT NULL,
    generated_at DATETIME NOT NULL,
    current_status VARCHAR(255) NOT NULL,
    alert_threshold_snapshot INT NOT NULL,
    
    PRIMARY KEY (alert_id),
    FOREIGN KEY (vin) REFERENCES Vehicles(vin)
);

CREATE TABLE Vehicle_assignments (
    assignment_id INT AUTO_INCREMENT NOT NULL,
    vin VARCHAR(17) NOT NULL,
    driver_id INT NOT NULL,
    is_permanent  BOOLEAN NOT NULL DEFAULT FALSE,
    assigned_at   DATETIME NOT NULL,
    unassigned_at DATETIME DEFAULT NULL,
    
    PRIMARY KEY (assignment_id),
    FOREIGN KEY (vin) REFERENCES Vehicles(vin),
    FOREIGN KEY (driver_id) REFERENCES Drivers(driver_id)
);

CREATE TABLE Monthly_safety_scores (
    score_id INT AUTO_INCREMENT NOT NULL,
    driver_id INT NOT NULL,
    score_month DATE NOT NULL,
    base_score DOUBLE NOT NULL DEFAULT 100,
    event_penalties DOUBLE NOT NULL DEFAULT 0,
    repeat_penalties DOUBLE NOT NULL DEFAULT 0,
    total_penalty DOUBLE NOT NULL DEFAULT 0,
    final_score DOUBLE NOT NULL DEFAULT 100,
    coaching_required BOOLEAN NOT NULL DEFAULT FALSE,
    grounded BOOLEAN NOT NULL DEFAULT FALSE,
    
    PRIMARY KEY (score_id),
    FOREIGN KEY (driver_id) REFERENCES Drivers(driver_id)
);

CREATE TABLE Maintenance_Jobs (
    job_id INT AUTO_INCREMENT NOT NULL,
    depot_id INT NOT NULL,
    vin VARCHAR(17) NOT NULL,
    date_opened DATETIME NOT NULL,
    date_closed DATETIME DEFAULT NULL,
    downtime_hours INT NOT NULL,
    total_cost_vnd INT NOT NULL,
    linked_alert_id INT DEFAULT NULL,
    
    PRIMARY KEY (job_id),
    FOREIGN KEY (depot_id) REFERENCES Depots(depot_id),
    FOREIGN KEY (vin) REFERENCES Vehicles(vin),
    FOREIGN KEY (linked_alert_id) REFERENCES Predictive_Alerts(alert_id)
);



CREATE TABLE Job_Activities (
    activity_id INT NOT NULL AUTO_INCREMENT,
    job_id INT NOT NULL,
    activity_type VARCHAR(255) NOT NULL,
    diagnostic_result VARCHAR(255) NOT NULL,
    repeat_fault_indicator BOOLEAN NOT NULL,
    warranty_indicator BOOLEAN NOT NULL,
    
    PRIMARY KEY (activity_id),
    FOREIGN KEY (job_id) REFERENCES Maintenance_Jobs(job_id)
);

CREATE TABLE Mechanics (
    mechanic_id INT AUTO_INCREMENT NOT NULL,
    first_name    VARCHAR(100) NOT NULL,
    last_name     VARCHAR(100) NOT NULL,
    contact_information VARCHAR(255),
    depot_id INT NOT NULL,
    specialization VARCHAR(100),
    hire_date DATE,
    
    PRIMARY KEY (mechanic_id),
    FOREIGN KEY (depot_id) REFERENCES Depots(depot_id)
);

CREATE TABLE Mechanic_Certifications (
    mechanic_id INT NOT NULL,
    cert_id INT NOT NULL,
    expiration_date DATETIME,
    
    PRIMARY KEY (mechanic_id, cert_id),
    FOREIGN KEY (mechanic_id) REFERENCES Mechanics(mechanic_id),
    FOREIGN KEY (cert_id) REFERENCES Certifications(cert_id)
);

CREATE TABLE Activity_Labor_Logs (
    job_id INT NOT NULL,
    mechanic_id INT NOT NULL,
    activity_id INT,
    labour_hours DOUBLE,
    
    PRIMARY KEY (job_id, mechanic_id),
    FOREIGN KEY (job_id) REFERENCES Maintenance_Jobs(job_id),
    FOREIGN KEY (mechanic_id) REFERENCES Mechanics(mechanic_id)
);

CREATE TABLE Telematics_Events (
    event_id INT NOT NULL AUTO_INCREMENT,
    depot_id INT NOT NULL,
    driver_id INT NOT NULL,
    vin VARCHAR(50) NOT NULL,
    times_tamp DATETIME,
    event_type VARCHAR(50),
    severity_level VARCHAR(50),
    snapshot_odometer DOUBLE,
    
    PRIMARY KEY (event_id),
    FOREIGN KEY (depot_id) REFERENCES Depots(depot_id),
    FOREIGN KEY (driver_id) REFERENCES Drivers(driver_id),
    FOREIGN KEY (vin) REFERENCES Vehicles(vin)
);
CREATE TABLE Safety_Reviews (
    review_id INT NOT NULL AUTO_INCREMENT,
    event_id INT NOT NULL,
    reviewed_by VARCHAR(100),
    review_date DATETIME,
    comments VARCHAR(255),
    recommendations VARCHAR(255),
    is_completed BOOLEAN NOT NULL,
    
    PRIMARY KEY (review_id),
    FOREIGN KEY (event_id) REFERENCES Telematics_Events(event_id)
);
SELECT * FROM certifications;
SELECT * FROM Depots;
SELECT * FROM Drivers;
SELECT * FROM Mechanics;
SELECT * FROM Vehicle_categories;
SELECT * FROM Vehicles;

SHOW TABLES;
