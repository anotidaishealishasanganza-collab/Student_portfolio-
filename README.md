# Africa University Student Portfolio (Rapid Prototype)

A verified student profile that follows a student from year 1 to graduation and can be shown to employers when applying for jobs. Every achievement, leadership role, activity and recommendation letter is verified by school staff before it appears on the employer view.

This is a **rapid prototype**: it demonstrates the screens and the flow, not a finished system.


## Features

- **Login by role:** Student, Staff or Admin
- **Student dashboard:** current GPA, cumulative GPA (certified by the school), verified and pending counters
- **Academic record:** five courses per semester, switchable by semester
- **Achievements, leadership and activities:** each item is marked Verified or Pending, and students can submit new items
- **Staff review queue:** lecturers verify items and add comments
- **Recommendation letters:** students request letters, staff approve and issue them
- **Job profile:** an employer view that shows only school-verified information
- **Admin overview:** counts of staff accounts, verified items and items awaiting review

## Files

| File | Purpose |
| --- | --- |
| `index.html` | The whole prototype: structure (HTML), styling (CSS) and behaviour (JavaScript) |
| `school.jpeg` | School photo used as the page background |
| `README.md` | This file |

## How to run it

1. Download or clone this repository.
2. Open `index.html` in any web browser. No installation is needed.

## How to try it out

1. Choose **Student** and click **Log in**. Any ID and password work in the prototype.
2. Open **Achievements** and submit a new item. It shows as Pending.
3. Log out, choose **Staff**, open **Review queue** and verify the item.
4. Log out, log back in as **Student**, and open **Job profile**. The verified item now appears there.


## Limitations

- There is no real login or security: any ID and password are accepted.
- Data is kept in the browser page only. Refreshing resets everything.
- All names, grades and achievements are sample data for demonstration.
- A real system would need a database, secure login and a way for the Registry to certify results.


## Tools used

Visual Studio Code, HTML, CSS, JavaScript, GitHub Pages, and Claude (AI assistant) for guidance and code support.
