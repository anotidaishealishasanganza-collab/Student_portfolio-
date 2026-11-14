# AU Student Success Hub (AUSSH)

The Africa University Student Success Portal where students build a **digital portfolio** and access academic results, achievements, career opportunities and support. Lecturers, Registry, other university staff and alumni use the same role-based hub.

> **Status: demonstration build.** Sign-in, accounts, shared portfolio workflows and in-app notifications use MySQL. Use test data unless the installation has completed its institutional privacy and security review.

---

## Why this exists

The university needed one place where every student can create and keep a portfolio of their work, achievements and recommendation letters, and use it to apply for jobs when they finish or for attachments while still studying.

## Features

- **Sign in and sign up**: accounts are saved in MySQL, passwords are hashed (bcrypt), and sessions are kept on the server.
- **Role-based dashboards**: Student, Alumni, Lecturer, Verifier, HOD, Registry, Supervisor, Chaplaincy, Dean, Career Advisor and Administrator each have role-appropriate workspaces.
- **Student portfolio**: achievements, skills and a personal journey ("Beyond the transcript").
- **Recommendation letters**: students request them, lecturers respond.
- **Career and placement support**: opportunities, attachments and graduate placements.
- **Alumni and professional community**.
- **Academic integrity review** for lecturers.
- **Account directory and user management** for the Administrator.
- **Security basics**: prepared statements, lock-out after 5 wrong passwords, role checks on the server and designated Super Administrator controls.
- **Workflow notifications**: achievement submissions and decisions, recommendation requests and submitted letters are saved to the shared portal and create in-app notifications; email notices use the private Gmail SMTP configuration.
- **Student Engagement Hub**: role-scoped deadlines and reminders, searchable opportunities, secure resource downloads, private staff/student messages, a consent-based activity feed and database-backed staff analytics.

### Workflow email configuration

The workflow API uses the same private mail configuration as password recovery. Keep `mail_config.php` outside the web project at `%USERPROFILE%\aussh_private\mail_config.php`; it returns an array containing `host`, `port`, `username`, `password` and `from_email`. Outgoing mail uses **AU Student Success Hub (AUSSH)** as the sender display name. The default SMTP host is Gmail with STARTTLS on port 587. Use a Gmail app password where required. Never add SMTP credentials to this repository or expose the private configuration file.

Email is sent only to active account addresses selected from MySQL: verification-capable staff for achievement submissions, the selected lecturer for recommendation requests, and the submitting student's account for decisions and issued letters. Database commits and in-app notifications happen before delivery; a delivery failure is logged and shown as a warning without undoing a saved workflow record.

### User, staff and student account management

The existing University ID login and `users` table remain in place. Apply `database/account_management_migration.sql`, or let `api/register.php` / `api/accounts.php` create its idempotent tables. The migration adds student academic/verification profiles, hashed one-time staff activation tokens and Super Administrator designations; it does not rewrite existing accounts. The existing `Active` / `Disabled` user states are preserved.

Students register only as students and must select one of the five required College / School values: College of Business and Management Sciences; College of Engineering and Applied Sciences; College of Health, Agriculture and Natural Sciences; College of Social Sciences, Theology, Humanities and Education; or School of Law. They also provide their programme and study year. Their identity record remains `Pending` until Registry verifies it; registration does not assert verified academic status. Registry's **Student Verification** queue supports verification or reason-required rejection (which disables the account) and records the reviewer, date, audit event and in-app notification. Optionally set `AUSSH_UNIVERSITY_EMAIL_DOMAINS` to a comma-separated institutional email-domain allow-list; no domain is guessed by the application.

Administrators use **User Management → Account Management** to invite lecturer, verifier, HOD, Registry, attachment supervisor, Chaplaincy, Dean, Career Advisor and alumni accounts, assign one of the five College / School values, deactivate accounts, resend invitations and review account audit history. Invited staff stay `Disabled` until they use a single-use 24-hour activation link sent to their email and choose their own password. Passwords are never emailed; activation tokens are stored only as hashes and are never returned by the management API. The activation link is delivered using the existing private mail configuration and `app_url`.

The College / School value is stored as `portal_account_profiles.college`. Existing `faculty` and `department` columns and values are retained; only an exact legacy match to one of the five approved names is copied into an empty `college` field. For a manual migration, apply `database/account_management_migration.sql` first, then `database/account_college_migration.sql`. The authenticated account, workflow and results APIs also run the same additive, idempotent column migration automatically. Existing non-matching legacy values are preserved in their original columns and are not guessed or converted.

Only active administrator IDs named in the server environment variable `AUSSH_SUPER_ADMIN_USER_IDS` (comma-separated University IDs) are bootstrapped as Super Administrators. Configure this securely before first account-management use. A designated Super Administrator can create/promote administrators and assign additional Super Administrator designations; regular administrators can manage only non-administrator accounts. The last active Super Administrator cannot be removed or deactivated. Do not configure this variable from a request parameter or expose it to the browser.

Run account-management integration tests only against a dedicated, non-production database:

```powershell
$env:AUSSH_ACCOUNT_TEST_DATABASE = 'your_isolated_test_database'
php tests\account_management_test.php
Remove-Item Env:AUSSH_ACCOUNT_TEST_DATABASE
```

The test uses temporary `TESTACCT_` identities and an isolated mail profile to avoid sending test invitations.

To manually verify the College / School integration in an isolated environment, register a test student with one of the five dropdown choices and confirm its exact value in the `portal_account_profiles.college` column. Try a forged API request with an unlisted value and confirm it returns HTTP 422. As an administrator, invite a test staff account with an approved value, verify that it appears in the account directory, then change its College / School and confirm the audit history records the change. Check that the selected student unit appears in Registry's pending identity queue, the student profile, achievement/recommendation reviews and the authorized academic results views.

### Student Engagement Hub

The hub provisions its additional MySQL tables when the authenticated engagement API is first used. It reuses `portal_records` for opportunities and achievements and `portal_notifications` for the shared notification centre. New tables are `portal_engagement_items` (deadlines, announcements and resource metadata), `portal_engagement_tracks` (student reminders, bookmarks, completions and application participation), and `portal_messages` (private message conversations). Existing notification tables receive a nullable unique deduplication key without removing notification history.

Staff permissions are checked in PHP on every write. Students/alumni can track deadlines, bookmark opportunities, mark application participation and message active university staff. Private activity-feed achievement entries appear only after verification and only when the student opted in at submission. Institutional analytics are limited to authorised management roles; other staff see workflow workload totals. When Career Advising is first used or an administrator provisions a `career_advisor`, an idempotent migration adds that role to an existing MySQL `users.role` ENUM while preserving its existing roles and default.

Resource files are limited to PDF, DOCX, XLSX, PPTX and TXT (10 MB maximum), stored under `%USERPROFILE%\aussh_private\resources` outside the web root, and streamed through the authenticated `api/resource_download.php` endpoint. Keep this directory private and backed up. Career Advising accounts use the `career_advisor` role and must be provisioned by an administrator.

### Achievement evidence uploads

The existing student achievement form keeps its evidence description and now accepts up to five supporting PDF, JPG/JPEG, PNG, DOC or DOCX files (10 MB maximum per file). Uploads use multipart/form-data; PHP verifies file content with Fileinfo (and validates the DOCX package), generates random storage keys, and stores the files outside the public web root in `%USERPROFILE%\aussh_private\achievement_evidence`. The new `portal_achievement_evidence` table stores each file's achievement ID, original display name, private storage key, verified MIME type, size, uploader and upload time. Existing achievement records without attachments remain valid.

Import `database/achievement_evidence_migration.sql` into the configured database, or use the authenticated workflow API, which applies the same idempotent table creation automatically. DOCX package inspection requires PHP's Zip extension (`ext-zip`). Keep the private evidence directory access-limited to the application service account and include it in protected backups. The `api/achievement_evidence_download.php` endpoint checks the active account and record ownership/authorized staff role on every request; PDF and image previews use that endpoint too. Uploads and evidence views/downloads are added to `portal_audit` (repeated previews are throttled to one audit event per user/file per hour). Evidence is append-only: after a verifier requests changes, the owning student can add files and resubmit; earlier files, descriptions, reviewer feedback and submission history remain available.

Configure PHP with `upload_max_filesize=10M`, `post_max_size=55M` (or larger), and `max_file_uploads` of at least 5 so the per-file and multi-file limits can be reached. The application rejects oversized individual files and reports when the server's total request limit prevents receiving the upload.

### Academic results workflow

The Academic Results module stores results in MySQL and uses the existing authenticated session, CSRF token, `portal_notifications`, and `portal_audit` systems. Its workflow is **Lecturer → assigned HOD → Registry → student**. Students receive only results whose status is `Published`. Drafts, submissions, returns and HOD approvals remain hidden from students.

Apply `database/results_migration.sql` to the configured AUSSH database (for example, select the existing `au_student_portal` database in phpMyAdmin and import the file). The authenticated results API also applies the same idempotent `CREATE TABLE IF NOT EXISTS` migration on first use. It adds result settings, course allocations, results, publication batches and immutable review-history records; existing rows are not changed or removed. On first API use, compatible InnoDB installations also receive foreign keys to `users.user_id` and the result tables. If the legacy user column type/collation is incompatible, the API logs that it is relying on its active-account and role checks.

Before entering marks, an authorised Registry account must:

1. Open **Results Publication** and configure the coursework and examination maxima supplied by the institution. Their sum cannot exceed 100. No component weighting is assumed by the application.
2. Supply an institution-approved grade scale only if one exists, as JSON array entries such as `[{ "min": 0, "max": 100, "grade": "institution-supplied label" }]`. This example is illustrative, not an Africa University grading rule. Leave the field empty until official grade boundaries have been supplied; the grade will then display as unavailable. Marking rules are locked after the first result is entered so that marks cannot silently change meaning.
3. Create each course allocation, selecting an active student, lecturer and HOD and specifying the department/unit, course code/name, academic year and semester. HOD permissions are limited to the exact allocations assigned to that HOD. There is no existing course catalogue or department mapping to import.

Registry may publish a reviewed year/semester batch only when it contains HOD-approved results. The publication action records the publishing officer, date and batch and sends in-app student notifications. Changes after publication are available only as a Registry correction with a required reason and before/after audit history. Administrators have read-only workflow counts, publication history and audit oversight; they cannot approve or publish.

#### Lecturer class CSV imports

The lecturer's **Academic Results** page provides both individual entry and **Upload class CSV**. Select an assigned course/year/semester and download its roster template. Fill in the Coursework Mark and Examination Mark columns and upload the completed `.csv` file (UTF-8). The importer validates all four required columns, active student accounts, each student's assigned class membership, the selected allocated course, configured mark maxima, duplicate rows, existing results and missing class roster members. The complete preview is stored with its uploader, original filename, SHA-256 file fingerprint, row-level errors and audit events. Nothing is silently skipped: any invalid or missing row blocks the entire import, so the lecturer must correct the file and upload it again.

After a fully valid preview, the lecturer explicitly saves the whole class as drafts, then uses a separate confirmation to submit the batch to its assigned HOD(s). Imported drafts remain editable only through the normal lecturer draft workflow; a bulk batch cannot publish results. HOD approval and Registry publication remain required. Course-code and class-roster validation use Registry-managed `portal_result_assignments`; this project does not have a separate institution course-catalogue table.

`PhpSpreadsheet` is not present in the current Composer dependencies, so `.xlsx` files are explicitly rejected with guidance to export as CSV UTF-8. Installing PhpSpreadsheet and configuring its required PHP extensions is necessary before XLSX parsing can be enabled. CSV import uses PHP's built-in `fgetcsv` parser and adds no dependency.

#### Role-by-role verification

Perform workflow testing in a non-production database using accounts whose names and university IDs are clearly marked `TEST ONLY`. Do not use an existing student's account to create test results.

1. **Registry setup:** import the migration; sign in as Registry; configure test component maxima (for example, 40 and 60 for the test only); leave the grade scale blank unless using an institution-approved test scale; create allocations for one test student, lecturer and HOD for a test course/term.
2. **Lecturer:** sign in as the allocated lecturer; open **Academic Results**; enter marks within the configured maxima; inspect the calculated total; save as draft; verify the row is `Draft`; submit it and verify `Submitted`. Confirm a duplicate result for the same student/course/year/semester is rejected. Try Registry-only publication and verify it is denied. After a return, edit and resubmit the same row; verify an approved or published result cannot be edited.
   - To verify bulk upload, switch to **Upload class CSV**, download the full roster template, and upload a corrected CSV. Confirm all rows and any missing roster entries/errors appear in preview. An upload with one invalid row must not save any result. Import the fully valid file as drafts; confirm students cannot see the drafts; then separately confirm batch submission to HOD. Verify a repeated upload is flagged as duplicate and `.xlsx` receives the explicit CSV conversion message.
3. **HOD:** sign in as the assigned HOD; open **Results Approval**; confirm the student/course/term and marks; return the first submission with a reason; confirm the lecturer receives an in-app notification and the reason; after resubmission, approve it and confirm Registry receives a notification. Try publication and verify it is denied.
4. **Registry:** try publishing a returned/unapproved result and verify it is rejected. After HOD approval, review and confirm the batch for the exact year/semester; verify publisher, date and batch history. Try an audited correction on the published result with a reason and check the before/after audit entry and student notification. Confirm a correction without a reason is rejected.
5. **Student:** sign in as the allocated test student before publication and confirm no draft, submitted or HOD-approved result appears. After publication, confirm only their published course, marks, optional grade and publication date appear, grouped by year/semester. Sign in as a second test student and confirm the first student's result is absent. Confirm the result is still available when viewing a previous published semester.
6. **Administrator:** sign in as Administrator; check pending/approved/published counts, publication history and audit entries. Confirm the page offers no approval or publication action.

This sequence covers lecturer submission, HOD approval and return, Registry publication, student ownership and unpublished-result isolation, lecturer/HOD/Registry permission boundaries, duplicate prevention, notifications, and audit history. Run it with a dedicated test database so test data cannot appear in real student accounts. The automated integration suite also uses clearly marked, temporary test accounts and cleans its test rows:

```powershell
$env:AUSSH_RESULTS_TEST_DATABASE = 'your_isolated_test_database'
php tests\results_workflow_test.php
Remove-Item Env:AUSSH_RESULTS_TEST_DATABASE
```

The configured database name in `api/config.local.php` must match the explicit environment value. The suite refuses to run when the selected database already contains academic results.

For achievement evidence, use a separate non-production database and run:

```powershell
$env:AUSSH_EVIDENCE_TEST_DATABASE = 'your_isolated_test_database'
php tests\achievement_evidence_test.php
Remove-Item Env:AUSSH_EVIDENCE_TEST_DATABASE
```

The test creates and removes only clearly marked `TESTEVID_` accounts, achievement records, attachment files and related audit/notification rows. It covers PDF/JPG/DOCX multipart uploads, optional attachments, MIME and size validation, the five-file limit, CSRF, verifier and owner access, other-student and unauthenticated denial, correction resubmission, preserved history and audit events.

## Built with

| Layer | Technology |
| ----- | ---------- |
| Front end | HTML, CSS and JavaScript in a single page (`index.html`) |
| Back end | PHP 8 with PDO (`api/`) |
| Database | MySQL / MariaDB (`database/`) |
| Local server | XAMPP (Apache + MySQL + phpMyAdmin) |

## Project structure

```
AU Student Success Hub (AUSSH)/
├── index.html            The whole front end (pages, styling, scripts)
├── api/
│   ├── config.php        Database settings and default passwords
│   ├── bootstrap.php     Database connection, sessions, helpers
│   ├── login.php         Sign in
│   ├── register.php      Student-only public registration
│   ├── accounts.php      Administrator account and Registry verification API
│   ├── activate_account.php  Time-limited staff activation
│   ├── account_management_lib.php  Account migrations, audit and invitations
│   ├── me.php            Who is signed in
│   ├── logout.php        Sign out
│   └── workflows.php     Shared portfolio and verification workflows
├── database/
│   ├── schema.sql        Creates the users and sessions tables
│   ├── account_management_migration.sql
│   ├── achievement_evidence_migration.sql
│   ├── results_migration.sql
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

The AU Student Success Hub (AUSSH), the Africa University Student Success Portal, follows a role-based workflow that allows different users to interact with the system according to their responsibilities.

1. **Student Registration:** Students create accounts and access their personal dashboards.
2. **Portfolio Development:** Students build their digital portfolios by maintaining their achievements, skills and academic experiences.
3. **Verification Process:** Lecturers review and verify relevant student achievements to promote academic integrity.
4. **Recommendation Requests:** Students request recommendation letters from lecturers, who can review and respond to these requests.
5. **Career Support:** The Career Office supports students by providing access to employment opportunities, attachments and placement information.
6. **Alumni Engagement:** Graduates remain connected to the university community and access professional networking opportunities.
7. **Administration:** Administrators manage user accounts, roles and access to the portal.
