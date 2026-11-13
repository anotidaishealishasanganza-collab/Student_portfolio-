# Africa University Student Success Hub

A web portal where Africa University students build a **digital portfolio** and use it to apply for jobs and attachments, before or after graduation. Lecturers, career staff and alumni support them from the same site.

> **Status: demonstration build.** Sign-in, accounts, shared portfolio workflows and in-app notifications use MySQL. Use test data unless the installation has completed its institutional privacy and security review.

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
- **Workflow notifications**: achievement submissions and decisions, recommendation requests and submitted letters are saved to the shared portal and create in-app notifications; email notices use the private Gmail SMTP configuration.
- **Student Engagement Hub**: role-scoped deadlines and reminders, searchable opportunities, secure resource downloads, private staff/student messages, a consent-based activity feed and database-backed staff analytics.

### Workflow email configuration

The workflow API uses the same private mail configuration as password recovery. Keep `mail_config.php` outside the web project at `%USERPROFILE%\aussh_private\mail_config.php`; it returns an array containing `host`, `port`, `username`, `password`, `from_email`, and optionally `from_name`. The default SMTP host is Gmail with STARTTLS on port 587. Use a Gmail app password where required. Never add SMTP credentials to this repository or expose the private configuration file.

Email is sent only to active account addresses selected from MySQL: verification-capable staff for achievement submissions, the selected lecturer for recommendation requests, and the submitting student's account for decisions and issued letters. Database commits and in-app notifications happen before delivery; a delivery failure is logged and shown as a warning without undoing a saved workflow record.

### Student Engagement Hub

The hub provisions its additional MySQL tables when the authenticated engagement API is first used. It reuses `portal_records` for opportunities and achievements and `portal_notifications` for the shared notification centre. New tables are `portal_engagement_items` (deadlines, announcements and resource metadata), `portal_engagement_tracks` (student reminders, bookmarks, completions and application participation), and `portal_messages` (private message conversations). Existing notification tables receive a nullable unique deduplication key without removing notification history.

Staff permissions are checked in PHP on every write. Students/alumni can track deadlines, bookmark opportunities, mark application participation and message active university staff. Private activity-feed achievement entries appear only after verification and only when the student opted in at submission. Institutional analytics are limited to authorised management roles; other staff see workflow workload totals. When Career Advising is first used or an administrator provisions a `career_advisor`, an idempotent migration adds that role to an existing MySQL `users.role` ENUM while preserving its existing roles and default.

Resource files are limited to PDF, DOCX, XLSX, PPTX and TXT (10 MB maximum), stored under `%USERPROFILE%\aussh_private\resources` outside the web root, and streamed through the authenticated `api/resource_download.php` endpoint. Keep this directory private and backed up. Career Advising accounts use the `career_advisor` role and must be provisioned by an administrator.

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
| Shared achievements, recommendation requests, placements, support records and opportunities | MySQL (`portal_records`) |
| Deadlines, announcements, and resource metadata | MySQL (`portal_engagement_items`) |
| Reminders, saved opportunities, completion and application participation | MySQL (`portal_engagement_tracks`) |
| Private staff/student messages | MySQL (`portal_messages`) |
| In-app workflow notifications and review audit history | MySQL (`portal_notifications`, `portal_audit`) |
| Private resource file contents | `%USERPROFILE%\aussh_private\resources` (outside the web root) |
| Personal profile and academic demonstration content | Browser `localStorage` |

## Next steps before institutional deployment

- Configure production SMTP and verify institution-owned email addresses.
- Review student privacy, retention, access, and operational backup policies.
- Connect authoritative academic records and institution-approved identity and career-advising processes.
- Conduct institution-specific security and accessibility testing.

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
- To provide a secure, centralized digital platform where student achievements, recommendation letters, and placement records are recorded, verified and accessed by the right people.

## System Workflow

The Africa University Student Success Portal follows a role-based workflow that allows different users to interact with the system according to their responsibilities.

1. **Student Registration:** Students create accounts and access their personal dashboards.
2. **Portfolio Development:** Students build their digital portfolios by maintaining their achievements, skills and academic experiences.
3. **Verification Process:** Lecturers review and verify relevant student achievements to promote academic integrity.
4. **Recommendation Requests:** Students request recommendation letters from lecturers, who can review and respond to these requests.
5. **Career Support:** The Career Office supports students by providing access to employment opportunities, attachments and placement information.
6. **Alumni Engagement:** Graduates remain connected to the university community and access professional networking opportunities.
7. **Administration:** Administrators manage user accounts, roles and access to the portal.
