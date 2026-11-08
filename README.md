# Africa University Student Success Portal

This repository contains the current front-end demonstration of the Africa University Student Success Portal.

## Run locally

No XAMPP, PHP or database is required just to preview this front end.

1. Open this folder in VS Code.
2. Open `index.html` in a browser, or run:
   ```bash
   python -m http.server 5500
   ```
3. Visit `http://localhost:5500`.

## Images and branding

The page uses Africa University-related campus, nursing/health-sciences and graduation photographs. Some image URLs are hosted by the original publishers, so an internet connection is required. The graduation image is from Africa University's official social-media post; the nursing white-coat image is from Africa University's official social-media post. Campus building and library images are from campus photography.

The HTML looks for the official logo at:

`assets/images/africa-university-logo.png`

Place an authorised copy of the official Africa University logo at that exact path. Until the file is added, the interface displays an `AU` fallback mark; it does not pretend that the fallback is the official logo. Obtain/use the logo in accordance with the University's brand guidance.

## Important system status

This version is a front-end demonstration. It uses browser `localStorage` and sample records. It is not yet a production university system and does not provide real authentication, shared multi-user records, secure document uploads, server-side permissions, password recovery, or institutional integrations. Do not enter real student personal or academic data.

The next development stage is the PHP/MySQL backend (or another approved backend), with authenticated accounts, role-based server permissions, audit logs, secure file storage, database migrations and backups.

## Project Objectives

The Africa University Student Success Portal aims to provide students with a convenient digital platform for accessing and managing important academic information.

The main objectives of the portal are:

- To improve access to student academic information.
- To provide a central platform for student support services.
- To make it easier for students to access important university announcements and updates.
- To improve communication between students and the university.
- To support a better student experience through digital services.

## Future Improvements

The following improvements could be considered in future development:

- Integration of a secure student login and authentication system.
- Development of a PHP/MySQL backend for managing student records.
- Introduction of notifications for important academic announcements.
- Implementation of secure document uploads.
- Development of a mobile-friendly experience across different devices.
- Integration with existing university systems where appropriate.