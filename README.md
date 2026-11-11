# Africa University Student Success Portal

A web portal where Africa University students build a **digital portfolio** and use it to apply for jobs and attachments, before or after graduation. Lecturers, career staff and alumni support them from the same site.

> **Status: demonstration build.** Sign-in and accounts use a real MySQL database. Portfolio records (achievements, letters, placements) are still sample data stored in the browser. Please do not enter real student data.

---

## Why this exists

The university needed one place where every student can create and keep a portfolio of their work, achievements and recommendation letters, and use it to apply for jobs when they finish or for attachments while still studying.

## Features

- **Sign in and sign up**: accounts are saved in MySQL, passwords are hashed (bcrypt), and sessions are kept on the server.
- **Role-based dashboards**: Student, Alumni, Lecturer, Career Office and Administrator each see their own home page.
- **Student portfolio**: achievements, skills and a personal journey ("Beyond the transcript").
- **Recommendation letters**: students request them, lecturers respond.
- **Career and placement support**: opportunities, attachments and graduate placements.
- **Alumni and professional community**.
- **Academic integrity review** for lecturers.
- **Account directory and user management** for the Administrator.
- **Security basics**: prepared statements, lock-out after 5 wrong passwords, role checks on the server, one Administrator account only.

## Built with

| Layer | Technology |
| ----- | ---------- |
| Front end | HTML, CSS and JavaScript in a single page (`index.html`) |
| Back end | PHP 8 with PDO (`api/`) |
| Database | MySQL / MariaDB (`database/`) |
| Local server | XAMPP (Apache + MySQL + phpMyAdmin) |

## Project structure

```
Student_portfolio-/
├── index.html            The whole front end (pages, styling, scripts)
├── api/
│   ├── config.php        Database settings and default passwords
│   ├── bootstrap.php     Database connection, sessions, helpers
│   ├── login.php         Sign in
│   ├── register.php      Sign up (Student / Alumni)
│   ├── me.php            Who is signed in
│   ├── logout.php        Sign out
│   └── users.php         Account directory (Admin / staff)
├── database/
│   ├── schema.sql        Creates the users and sessions tables
│   ├── setup.php         One-time setup script
│   └── seed.php          Starting accounts
└── assets/images/        Logo goes here
```

## Getting started (Windows + XAMPP)

1. Install [XAMPP](https://www.apachefriends.org/) and start **Apache** and **MySQL** in the Control Panel.
   - If Apache will not start because port 80 is busy, change its port to `8080` (Config → `httpd.conf` → `Listen 8080`) and use `http://localhost:8080/` in the links below.
2. Put this folder inside XAMPP's `htdocs` folder, for example `C:\xampp\htdocs\Student_portfolio-`. Cloning the repository straight into `htdocs` works well:
   ```bash
   git clone https://github.com/anotidaishealishasanganza-collab/Student_portfolio-.git
   ```
3. Open **http://localhost/Student_portfolio-/database/setup.php** once. It creates the `au_student_portal` database, the tables and the starting accounts.
   (Terminal alternative: `php database/setup.php`.)
4. Open **http://localhost/Student_portfolio-/** and sign in.

> Do not open `index.html` by double-clicking it. Sign-in needs PHP, so the site must be opened through `http://localhost/...`.

### Starting accounts

| ID | Role | Password |
| -- | ---- | -------- |
| `Admin` | Administrator | `admin_password` in `api/config.php` |
| `AU2201234` | Student (Tendai Moyo) | `sample_password` in `api/config.php` |
| `STAFF001` | Lecturer (Demo Lecturer) | `sample_password` in `api/config.php` |

There is exactly **one** Administrator. That account cannot be created through sign-up. Anyone can register as a Student or Alumni; the Administrator adds staff accounts from **User management**.

> **Security:** this repository is public, so the default passwords in `api/config.php` are visible to everyone. Change them before using the site anywhere other than your own computer.

### Using a MySQL password

`api/config.php` uses the XAMPP defaults (user `root`, no password). If your MySQL has a password, create `api/config.local.php`. It is git-ignored, so the password is never pushed:

```php
<?php
return ['db_pass' => 'your-mysql-password'];
```

### Viewing the data

Open **http://localhost/phpmyadmin**, then the `au_student_portal` database, then the `users` table.

## What is stored where

| Data | Where |
| ---- | ----- |
| Accounts (name, ID, role, status, password hash) and sessions | MySQL |
| Achievements, letters, placements and other portfolio records | Browser `localStorage` (sample data) |

## Roadmap

- Move portfolio records into the database with role-based permissions
- Password change and recovery
- Secure document uploads (CVs, certificates)
- Notifications for announcements and recommendation requests
- Audit logs and backups
- Better mobile experience
- Integration with existing university systems

## Hosting note

GitHub Pages only serves static files, so it cannot run the PHP/MySQL part. Use XAMPP locally, or a host that supports PHP and MySQL.

## Logo and images

The page looks for the official logo at `assets/images/africa-university-logo.png`. Until an authorised copy is added, an `AU` placeholder mark is shown. Some photographs are loaded from their original publishers, so an internet connection is needed to see them.

## Contributing

1. Create a branch: `git checkout -b my-change`
2. Commit your work: `git commit -am "Describe the change"`
3. Push it: `git push origin my-change`, then open a pull request.

## Project objectives

- Improve access to student academic and career information
- Give students one central place for support services
- Make announcements and updates easy to find
- Improve communication between students and the university
- Support a better student experience through digital services
- Support career development and personal growth
