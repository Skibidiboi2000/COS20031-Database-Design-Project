# COS20031-Database-Design-Project
COS20031 Database Design Project
Team name: Fantastic Four
Problem statement
We are introduced with a Transportation company - specialized in providing heavy duty trucks to deliver and transporting heavy items according to customers' needs. This company provided us a file reporting the company’s activities such as hiring candidates, trucks count and incident reports and we are tasked with providing a well structured database to help the company manage more efficient. 

Gia Khang:   Resources Manager

Yuki:   Scrum Master

Duy Anh :  Database Manager

Đào Gia Khang: Project manager


<img width="2048" height="1210" alt="SQL TABLE" src="https://github.com/user-attachments/assets/da066191-9959-46bd-85b7-acc1669e35a4" />

```
COS20031-Database-Design-Project/
├── [Database.sql](Database.sql)                    # Full MySQL schema (14 tables)
├── [README.md](README.md)                          # This file
├── [COS20031_smart_fleet_project_brief_v1_0.pdf](COS20031_smart_fleet_project_brief_v1_0.pdf)
├── [Drivers_DATA.csv](Drivers_DATA.csv)            # 80 driver records
├── [Vehicles_DATA.csv](Vehicles_DATA.csv)          # 120 vehicle records
├── [Depots_DATA.csv](Depots_DATA.csv)              # 4 depot locations
├── [Mechanics_DATA.csv](Mechanics_DATA.csv)        # 25 mechanic records
├── [Certifications_DATA.csv](Certifications_DATA.csv)        # 6 certifications
└── [Vehicle_Categories_DATA.csv](Vehicle_Categories_DATA.csv) # 5 vehicle categories
```
## Getting around our website:
The user will be met with a login screen upon accessing the website, one for the customer role, one for staff role (drivers and mechanics) and one for admin
There is also an interface where the user can create an account in order to see some public data being displayed within the website.
### Customers' interface
This role will be able to access information such as driver list, vehicle list and depot list, but limited to read-only permission
### Staffs' interface
Staffs have a separated website interface where they can see information such as driver, vehicle as well as depots. Mechanics however, can also see some reports. Limited to read-only permission
### Admins' interface
This interface where granted all permissions (Create, Read, Update and Delete). Admin can assign a driver to a vehicle, register a new vehicle or driver, add a new depot and approve or decline a report ticket. It also include a dashboard tab where the admin can see summaries of drivers, vehicles by depot and such helpful queries in the form of graphs.
### Other noticeable features
- Protected from SQL Injections
- Password hashing
- Optimized database
- Expandable dashboards on admin interface
- Personally customized greetings based on roles and names on the top of the page
- Smooth animations
## Website Link
https://professionals-strategic-calls-charges.trycloudflare.com/login.php?fbclid=IwY2xjawTi2B5wZG9mA2V4dG4DYWVtAjExAHNydGMGYXBwX2lkATAAAR4km5F_84HmdMamO5RyT4rH57vAcbUSaqcefs4n1XvVDodtlFC-vz-z8CJ8zw_aem_4ZunOnyu6Ssc-ai9QFbclA
### Admin Access
vuduyanh20012@gmail.com
huynhphanyuki@gmail.com
giakhangdao2704@gmail.com
gkhang121951@gmail.com
Password: 1
### How to use 
Follow these instructions to set up and run the Smart Fleet Management System locally using XAMPP.

1/ Requirements
Before starting, make sure you have installed:
XAMPP – includes Apache, MySQL/MariaDB, and PHP.
Project source files – download or clone this repository to your computer.

2/ Set up the project folder
Open the XAMPP installation directory. 
Navigate to the htdocs folder (usually C:\xampp\htdocs\).
Copy the project folder into htdocs.
The correct directory might look like: C:\xampp\htdocs\COS20031-Database-Design-Project\
The exact folder name can be different, but keep the original project structure intact.

3/ Start XAMPP services
Open the XAMPP Control Panel.
Start Apache and MySQL.
Confirm that both services are running.

4/ Create the database
Open your browser and visit: http://localhost/phpmyadmin/
Import the project's Database.sql file.
!Important: Use Database.sql as the main database schema for this setup. Do not import Main Data.sql, Insert tables.sql, or use_case.sql as part of this procedure. Those files were created for a different database structure and may contain incompatible table or column names.

5/ Import the initial dataset
The project includes CSV files containing the initial records for the main database tables.
Import the CSV files through phpMyAdmin, in order:
1.Depots_DATA.csv
2.Vehicle Categories_DATA.csv
3.Certifications_DATA.csv
4.Drivers_DATA.csv
5.Mechanics_DATA.csv
6.Vehicles_DATA_fixed.csv

For all CSV files:
Select the corresponding destination table in phpMyAdmin. Choose Import then select the CSV file.
Enable the option to ignore the first line if it contains column headings.

  for Mechanics_DATA.csv file:
   In the 'Column names' field, enter: mechanic_id,depot_id,first_name,last_name

  for Vehicles_DATA_fixed.csv file:
   In the 'Column names' field, enter: vin,category_id,registration_number,manufacturer,model,year_of_manufacture,current_odometer,current_depot_id,operational_status

Start the import.

6/ Create an administrator account
The administrator account is stored in the Accounts table. Passwords must be stored as secure password hashes rather than plain text.
To generate a password hash, in PHP file named Generate_hash.php containing:
<?php
echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT);
?>
Replace YOUR_PASSWORD with your chosen password.
Save the file then open it in your browser: http://localhost/YOUR_PROJECT_FOLDER/Generate_hash.php
Copy the generated hash.

In phpMyAdmin, select the smart_fleet database and execute the following SQL. Replace the example name, email, and hash with your own values.
INSERT INTO Accounts
    (first_name, last_name, email, password_hash, role, is_active)
VALUES
    ('firstname', 'lastname', 'admin@example.com',
     'PASTE_GENERATED_HASH_HERE', 'admin', 1);

After creating the account, delete Generate_hash.php for security.

7/ Configure the database connection
Open frontend/final/dbconn.php and check the database connection settings.
For a default XAMPP installation, the configuration should look similar to:
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'smart_fleet';
$DB_HOSTS = ['127.0.0.1'];

If your MySQL username, password, or database name is different, update these values accordingly.

8/ Run the application
Open your browser and navigate to: http://localhost/YOUR_PROJECT_FOLDER/frontend/final/login.php
Log in using the administrator email and password created in Step 6.
After logging in, you can access the administrator dashboard and navigate through the available system sections.

9/ Verify the local setup
Before using the system, check the following:
Apache and MySQL are running in XAMPP.
The smart_fleet database exists.
All six initial CSV datasets have been imported.
The Accounts table contains an active administrator account.
The database connection settings in dbconn.php match your local MySQL configuration.

Note: This guide describes the local XAMPP setup using the supplied Database.sql schema and initial CSV datasets. It does not represent a production deployment configuration.