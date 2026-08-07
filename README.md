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
