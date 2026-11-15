<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/account_management_lib.php';
require_once __DIR__ . '/../api/role_migrations.php';

$demoPassword = trim((string)getenv('AUSSH_DEMO_PASSWORD'));
if ($demoPassword === '') {
    fwrite(STDERR, "Set the AUSSH_DEMO_PASSWORD environment variable before running this seeder.\n");
    exit(1);
}

$pdo = database();

ensureAccountManagementSchema($pdo);
ensureCareerAdvisorRole($pdo);
ensureWorkplaceSupervisorRole($pdo);
ensureAttachmentCoordinatorRole($pdo);

$industrialMigration = __DIR__ . '/industrial_attachment_migration.sql';
if (is_file($industrialMigration)) {
    $pdo->exec(file_get_contents($industrialMigration));
}

function demoPasswordHash(string $password): string
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Unable to hash the demo password.');
    }
    return $hash;
}

function upsertUser(PDO $pdo, string $userId, string $fullName, string $email, string $role, string $passwordHash, string $status = 'Active'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (user_id, full_name, email, role, password_hash, status)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           full_name = VALUES(full_name),
           email = VALUES(email),
           role = VALUES(role),
           password_hash = VALUES(password_hash),
           status = VALUES(status)'
    );
    $stmt->execute([$userId, $fullName, $email, $role, $passwordHash, $status]);
}

function upsertProfile(PDO $pdo, string $userId, string $college, string $programme, string $studyYear): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO portal_account_profiles (user_id, college, faculty, department, programme, study_year, student_verification_status)
         VALUES (?, ?, \'\', \'\', ?, ?, \'Verified\')
         ON DUPLICATE KEY UPDATE
           college = VALUES(college),
           faculty = VALUES(faculty),
           department = VALUES(department),
           programme = VALUES(programme),
           study_year = VALUES(study_year),
           student_verification_status = VALUES(student_verification_status)'
    );
    $stmt->execute([$userId, $college, $programme, $studyYear]);
}

function existingPortalRecord(PDO $pdo, string $recordType, string $ownerUserId, string $marker): bool
{
    $stmt = $pdo->prepare(
        'SELECT id FROM portal_records
         WHERE record_type = ?
           AND owner_user_id = ?
           AND JSON_UNQUOTE(JSON_EXTRACT(payload, "$.demo_marker")) = ?
         LIMIT 1'
    );
    $stmt->execute([$recordType, $ownerUserId, $marker]);
    return $stmt->fetchColumn() !== false;
}

function upsertPortalRecord(PDO $pdo, string $recordType, string $ownerUserId, string $ownerName, array $payload, string $status, string $assignedRole): void
{
    $marker = (string)($payload['demo_marker'] ?? '');
    if ($marker === '') {
        throw new RuntimeException('Each demo record must include a demo_marker.');
    }
    if (!existingPortalRecord($pdo, $recordType, $ownerUserId, $marker)) {
        $stmt = $pdo->prepare(
            'INSERT INTO portal_records (record_type, owner_user_id, owner_name, payload, status, assigned_role)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$recordType, $ownerUserId, $ownerName, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $status, $assignedRole]);
    }
}

function upsertNotification(PDO $pdo, string $recipientUserId, string $title, string $message, string $recordType, int $recordId, string $eventKey): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO portal_notifications (recipient_user_id, title, message, record_type, record_id, dedupe_key)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$recipientUserId, $title, $message, $recordType, $recordId, $eventKey]);
}

function upsertEngagementItem(PDO $pdo, string $itemType, string $title, string $description, string $category, string $creatorUserId, array $payload): void
{
    $stmt = $pdo->prepare(
        'SELECT id FROM portal_engagement_items
         WHERE item_type = ? AND title = ? AND creator_user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$itemType, $title, $creatorUserId]);
    if ($stmt->fetchColumn() !== false) {
        return;
    }
    $insert = $pdo->prepare(
        'INSERT INTO portal_engagement_items (item_type, title, description, category, organisation, eligibility, location, link_url, target_role, due_at, creator_user_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $itemType,
        $title,
        $description,
        $category,
        $payload['organisation'] ?? '',
        $payload['eligibility'] ?? '',
        $payload['location'] ?? '',
        $payload['link_url'] ?? '',
        $payload['target_role'] ?? 'student',
        $payload['due_at'] ?? null,
        $creatorUserId,
        $payload['status'] ?? 'Published',
    ]);
}

function ensurePlacement(PDO $pdo, array $placement): void
{
    $exists = $pdo->prepare(
        'SELECT id FROM portal_industrial_attachments WHERE student_user_id = ? LIMIT 1'
    );
    $exists->execute([$placement['student_user_id']]);
    $id = $exists->fetchColumn();
    if ($id !== false) {
        $pdo->prepare(
            'UPDATE portal_industrial_attachments SET academic_supervisor_user_id = ?, organisation = ?, organisation_location = ?, physical_address = ?, department = ?, position_title = ?, start_date = ?, expected_end_date = ?, workplace_supervisor_name = ?, workplace_supervisor_email = ?, workplace_supervisor_phone = ?, approval_status = ?, approval_reason = ?, created_by = ? WHERE id = ?'
        )->execute([
            $placement['academic_supervisor_user_id'],
            $placement['organisation'],
            $placement['organisation_location'],
            $placement['physical_address'],
            $placement['department'],
            $placement['position_title'],
            $placement['start_date'],
            $placement['expected_end_date'],
            $placement['workplace_supervisor_name'],
            $placement['workplace_supervisor_email'],
            $placement['workplace_supervisor_phone'],
            $placement['approval_status'],
            $placement['approval_reason'],
            $placement['created_by'],
            $id,
        ]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO portal_industrial_attachments (student_user_id, academic_supervisor_user_id, organisation, organisation_location, physical_address, department, position_title, start_date, expected_end_date, workplace_supervisor_name, workplace_supervisor_email, workplace_supervisor_phone, approval_status, approval_reason, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $placement['student_user_id'],
        $placement['academic_supervisor_user_id'],
        $placement['organisation'],
        $placement['organisation_location'],
        $placement['physical_address'],
        $placement['department'],
        $placement['position_title'],
        $placement['start_date'],
        $placement['expected_end_date'],
        $placement['workplace_supervisor_name'],
        $placement['workplace_supervisor_email'],
        $placement['workplace_supervisor_phone'],
        $placement['approval_status'],
        $placement['approval_reason'],
        $placement['created_by'],
    ]);
}

function ensureReport(PDO $pdo, int $attachmentId, int $weekNumber, array $report): void
{
    $exists = $pdo->prepare(
        'SELECT id FROM portal_industrial_attachment_reports WHERE attachment_id = ? AND week_number = ? LIMIT 1'
    );
    $exists->execute([$attachmentId, $weekNumber]);
    $id = $exists->fetchColumn();
    if ($id !== false) {
        $pdo->prepare(
            'UPDATE portal_industrial_attachment_reports SET period_start = ?, period_end = ?, activities = ?, skills_acquired = ?, challenges = ?, lessons_learnt = ?, status = ?, latest_feedback = ? WHERE id = ?'
        )->execute([
            $report['period_start'],
            $report['period_end'],
            $report['activities'],
            $report['skills_acquired'],
            $report['challenges'],
            $report['lessons_learnt'],
            $report['status'],
            $report['latest_feedback'],
            $id,
        ]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO portal_industrial_attachment_reports (attachment_id, week_number, period_start, period_end, activities, skills_acquired, challenges, lessons_learnt, status, latest_feedback)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $attachmentId,
        $weekNumber,
        $report['period_start'],
        $report['period_end'],
        $report['activities'],
        $report['skills_acquired'],
        $report['challenges'],
        $report['lessons_learnt'],
        $report['status'],
        $report['latest_feedback'],
    ]);
}

$demoPasswordHash = demoPasswordHash($demoPassword);

$demoUsers = [
    ['AU2026001', 'Aiden Mutanda', 'aiden.mutanda@africau.edu', 'student', 'College of Business and Management Sciences', 'BSc Business Information Systems', 'Year 3'],
    ['AU2026002', 'Blessing Mapuranga', 'blessing.mapuranga@africau.edu', 'student', 'College of Engineering and Applied Sciences', 'BSc Computer Science', 'Year 2'],
    ['AU2026003', 'Clara Nkomo', 'clara.nkomo@africau.edu', 'student', 'College of Health, Agriculture and Natural Sciences', 'BSc Agriculture', 'Year 4'],
    ['AU2026004', 'Daniel Tinashe', 'daniel.tinashe@africau.edu', 'student', 'College of Social Sciences, Theology, Humanities and Education', 'BA Sociology', 'Year 1'],
    ['AU2026005', 'Eunice Moyo', 'eunice.moyo@africau.edu', 'student', 'School of Law', 'LLB', 'Year 3'],
    ['AU2026006', 'Farai Chikomba', 'farai.chikomba@africau.edu', 'student', 'College of Engineering and Applied Sciences', 'BSc Electrical Engineering', 'Year 4'],
    ['AU2026007', 'Grace Sibanda', 'grace.sibanda@africau.edu', 'student', 'College of Business and Management Sciences', 'BSc Economics', 'Year 2'],
    ['AU2026008', 'Henry Mavhunga', 'henry.mavhunga@africau.edu', 'student', 'College of Health, Agriculture and Natural Sciences', 'BSc Nursing', 'Year 5'],
    ['AULECT2026A', 'Dr. Chipo Ndlovu', 'chipo.ndlovu@africau.edu', 'lecturer', 'College of Business and Management Sciences', 'Department of Information Systems', 'Academic'],
    ['AULECT2026B', 'Dr. Kudzai Moyo', 'kudzai.moyo@africau.edu', 'lecturer', 'College of Engineering and Applied Sciences', 'Department of Computer Science', 'Academic'],
    ['AULECT2026C', 'Dr. Tafadzwa Moyo', 'tafadzwa.moyo@africau.edu', 'lecturer', 'College of Health, Agriculture and Natural Sciences', 'Department of Agriculture', 'Academic'],
    ['AUADMIN2026', 'Enoch Mutezo', 'enoch.mutezo@africau.edu', 'admin', 'Administration', 'Administration', 'Administrative'],
    ['AUCARADV2026', 'Memory Moyo', 'memory.moyo@africau.edu', 'career_advisor', 'Career Office', 'Career Services', 'Professional'],
    ['AUATTCH2026', 'Rumbidzai Mupangayi', 'rumbidzai.mupangayi@africau.edu', 'attachment_coordinator', 'Office of Industrial Attachments', 'Attachment Coordination', 'Support'],
    ['AUWPSUP2026', 'Aaron Dube', 'aaron.dube@africau.edu', 'workplace_supervisor', 'Workplace Experience', 'Industry Mentorship', 'Professional'],
];

foreach ($demoUsers as $user) {
    [$userId, $fullName, $email, $role, $college, $programme, $studyYear] = $user;
    upsertUser($pdo, $userId, $fullName, $email, $role, $demoPasswordHash, 'Active');
    if ($role === 'student') {
        upsertProfile($pdo, $userId, $college, $programme, $studyYear);
    }
}

$studentProfiles = [
    'AU2026001' => ['college' => 'College of Business and Management Sciences', 'programme' => 'BSc Business Information Systems', 'year' => 'Year 3'],
    'AU2026002' => ['college' => 'College of Engineering and Applied Sciences', 'programme' => 'BSc Computer Science', 'year' => 'Year 2'],
    'AU2026003' => ['college' => 'College of Health, Agriculture and Natural Sciences', 'programme' => 'BSc Agriculture', 'year' => 'Year 4'],
    'AU2026004' => ['college' => 'College of Social Sciences, Theology, Humanities and Education', 'programme' => 'BA Sociology', 'year' => 'Year 1'],
    'AU2026005' => ['college' => 'School of Law', 'programme' => 'LLB', 'year' => 'Year 3'],
    'AU2026006' => ['college' => 'College of Engineering and Applied Sciences', 'programme' => 'BSc Electrical Engineering', 'year' => 'Year 4'],
    'AU2026007' => ['college' => 'College of Business and Management Sciences', 'programme' => 'BSc Economics', 'year' => 'Year 2'],
    'AU2026008' => ['college' => 'College of Health, Agriculture and Natural Sciences', 'programme' => 'BSc Nursing', 'year' => 'Year 5'],
];

$achievements = [
    ['student_id' => 'AU2026001', 'marker' => 'demo-achievement-ledership-verified', 'title' => 'Student Association Leadership', 'type' => 'Leadership', 'detail' => 'Led the Business Students Council and coordinated the annual innovation fair.', 'status' => 'Verified', 'reviewer' => 'AULECT2026A', 'comment' => 'Strong leadership and clear evidence of impact.'],
    ['student_id' => 'AU2026002', 'marker' => 'demo-achievement-workshop-pending', 'title' => 'AI and Robotics Workshop', 'type' => 'Workshop', 'detail' => 'Completed a university workshop on applied AI and robotics prototyping.', 'status' => 'Pending', 'reviewer' => 'AULECT2026B', 'comment' => 'Awaiting final evidence upload.'],
    ['student_id' => 'AU2026003', 'marker' => 'demo-achievement-community-rejected', 'title' => 'Community Agriculture Outreach', 'type' => 'Community Service', 'detail' => 'Facilitated a soil health awareness project for local farmers.', 'status' => 'Rejected', 'reviewer' => 'AULECT2026C', 'comment' => 'Please include dates and beneficiary evidence.'],
    ['student_id' => 'AU2026004', 'marker' => 'demo-achievement-volunteer-pending', 'title' => 'Peer Mentor Volunteer', 'type' => 'Volunteer', 'detail' => 'Mentored first-year students during orientation week and reading club sessions.', 'status' => 'Pending', 'reviewer' => 'AULECT2026A', 'comment' => 'Awaiting mentor confirmation.'],
    ['student_id' => 'AU2026005', 'marker' => 'demo-achievement-research-verified', 'title' => 'Legal Research Poster', 'type' => 'Research', 'detail' => 'Presented a research poster on digital rights and access to legal aid.', 'status' => 'Verified', 'reviewer' => 'AULECT2026C', 'comment' => 'Excellent academic presentation and evidence.'],
    ['student_id' => 'AU2026006', 'marker' => 'demo-achievement-competition-verified', 'title' => 'Smart Systems Hackathon', 'type' => 'Competition', 'detail' => 'Placed second in the campus electronics and IoT coding competition.', 'status' => 'Verified', 'reviewer' => 'AULECT2026B', 'comment' => 'Strong technical execution and teamwork.'],
];

foreach ($achievements as $achievement) {
    $payload = [
        'demo_marker' => $achievement['marker'],
        'title' => $achievement['title'],
        'type' => $achievement['type'],
        'detail' => $achievement['detail'],
        'year' => $studentProfiles[$achievement['student_id']]['year'],
        'reviewer' => $achievement['reviewer'],
        'review_comment' => $achievement['comment'],
        'evidence' => 'demo-supporting-document-' . $achievement['marker'],
        'submitted_at' => date(DATE_ATOM),
    ];
    upsertPortalRecord($pdo, 'achievement', $achievement['student_id'], $studentProfiles[$achievement['student_id']]['programme'], $payload, $achievement['status'], 'verifier');
}

$recommendations = [
    ['student_id' => 'AU2026001', 'marker' => 'demo-recommendation-pending', 'purpose' => 'Graduate traineeship application', 'status' => 'Pending', 'lecturer_id' => 'AULECT2026A', 'body' => 'Pending lecturer action.'],
    ['student_id' => 'AU2026002', 'marker' => 'demo-recommendation-under-review', 'purpose' => 'Research assistant application', 'status' => 'Pending', 'lecturer_id' => 'AULECT2026B', 'body' => 'Recommendation currently under review.'],
    ['student_id' => 'AU2026005', 'marker' => 'demo-recommendation-approved', 'purpose' => 'Public sector internship', 'status' => 'Issued', 'lecturer_id' => 'AULECT2026C', 'body' => 'Approved and issued.'],
    ['student_id' => 'AU2026007', 'marker' => 'demo-recommendation-rejected', 'purpose' => 'Youth leadership programme', 'status' => 'Rejected', 'lecturer_id' => 'AULECT2026A', 'body' => 'Letter rejected due to incomplete context.'],
];

foreach ($recommendations as $recommendation) {
    $payload = [
        'demo_marker' => $recommendation['marker'],
        'purpose' => $recommendation['purpose'],
        'lecturer_user_id' => $recommendation['lecturer_id'],
        'context' => 'Demonstration recommendation request generated by the AU demo seeder.',
        'body' => $recommendation['body'],
    ];
    upsertPortalRecord($pdo, 'recommendation', $recommendation['student_id'], $studentProfiles[$recommendation['student_id']]['programme'], $payload, $recommendation['status'], 'lecturer');
}

$opportunities = [
    ['title' => 'Software Development Internship', 'category' => 'Internship', 'organisation' => 'DemoTech Solutions', 'location' => 'Harare', 'due_at' => '2026-12-15 17:00:00', 'description' => 'Build and test internal tools for a growing digital services team.', 'eligibility' => 'Year 2 and above | Computer Science or related discipline', 'type' => 'opportunity', 'target_role' => 'student'],
    ['title' => 'Graduate Trainee Programme', 'category' => 'Graduate Programme', 'organisation' => 'Crestline Bank', 'location' => 'Bulawayo', 'due_at' => '2026-12-20 17:00:00', 'description' => 'Rotational graduate programme in digital banking and advisory support.', 'eligibility' => 'Final year students | Finance, Business or MIS', 'type' => 'opportunity', 'target_role' => 'student'],
    ['title' => 'IT Support Attachment', 'category' => 'Attachment', 'organisation' => 'Digital Systems Ltd', 'location' => 'Mutare', 'due_at' => '2026-11-30 12:00:00', 'description' => 'Hands-on support role involving helpdesk, troubleshooting and desktop support.', 'eligibility' => 'Year 2 and above | IT or Computer Science', 'type' => 'opportunity', 'target_role' => 'student'],
    ['title' => 'Research Assistant Opportunity', 'category' => 'Research', 'organisation' => 'Innovation Labs', 'location' => 'Gweru', 'due_at' => '2026-12-10 17:00:00', 'description' => 'Assist with applied research and prototyping in applied science projects.', 'eligibility' => 'Year 3 and above | Agricultural or engineering backgrounds', 'type' => 'opportunity', 'target_role' => 'student'],
];

foreach ($opportunities as $opportunity) {
    upsertEngagementItem($pdo, 'opportunity', $opportunity['title'], $opportunity['description'], $opportunity['category'], 'AUCARADV2026', [
        'organisation' => $opportunity['organisation'],
        'eligibility' => $opportunity['eligibility'],
        'location' => $opportunity['location'],
        'link_url' => 'https://example.invalid/demo/' . strtolower(str_replace(' ', '-', $opportunity['title'])),
        'target_role' => $opportunity['target_role'],
        'due_at' => $opportunity['due_at'],
        'status' => 'Published',
    ]);
}

$notifications = [
    ['recipient' => 'AU2026001', 'title' => 'Achievement verified', 'message' => 'Your leadership achievement has been verified and published to your portfolio.', 'record_type' => 'achievement', 'record_id' => 1, 'key' => 'achievement:verified:AU2026001:1'],
    ['recipient' => 'AU2026002', 'title' => 'Attachment placement is awaiting approval', 'message' => 'Your industrial attachment placement is waiting for academic approval.', 'record_type' => 'placement', 'record_id' => 1, 'key' => 'placement:pending:AU2026002:1'],
    ['recipient' => 'AU2026005', 'title' => 'Recommendation request reviewed', 'message' => 'Your recommendation request has been reviewed by a lecturer.', 'record_type' => 'recommendation', 'record_id' => 2, 'key' => 'recommendation:reviewed:AU2026005:2'],
    ['recipient' => 'AULECT2026A', 'title' => 'New achievement submitted for verification', 'message' => 'Aiden Mutanda has submitted a leadership achievement for review.', 'record_type' => 'achievement', 'record_id' => 1, 'key' => 'achievement:new:AULECT2026A:1'],
    ['recipient' => 'AUADMIN2026', 'title' => 'Pending staff account approval', 'message' => 'Two new demo staff accounts are ready for review.', 'record_type' => 'account', 'record_id' => 1, 'key' => 'account:pending:AUADMIN2026:1'],
    ['recipient' => 'AUCARADV2026', 'title' => 'New opportunity published', 'message' => 'A new internship opportunity has been published for students.', 'record_type' => 'opportunity', 'record_id' => 1, 'key' => 'opportunity:published:AUCARADV2026:1'],
];

foreach ($notifications as $notification) {
    upsertNotification($pdo, $notification['recipient'], $notification['title'], $notification['message'], $notification['record_type'], (int)$notification['record_id'], $notification['key']);
}

$placements = [
    ['student_user_id' => 'AU2026001', 'academic_supervisor_user_id' => 'AULECT2026A', 'organisation' => 'DemoTech Solutions', 'organisation_location' => 'Harare', 'physical_address' => '10 Borrowdale Road, Harare', 'department' => 'ICT', 'position_title' => 'Business Systems Intern', 'start_date' => '2026-06-01', 'expected_end_date' => '2026-08-31', 'workplace_supervisor_name' => 'Moses Moyo', 'workplace_supervisor_email' => 'moses.moyo@demotech.example', 'workplace_supervisor_phone' => '+263771111111', 'approval_status' => 'Approved', 'approval_reason' => 'Demonstration placement approved.', 'created_by' => 'AUATTCH2026'],
    ['student_user_id' => 'AU2026002', 'academic_supervisor_user_id' => 'AULECT2026B', 'organisation' => 'DemoBank Zimbabwe', 'organisation_location' => 'Bulawayo', 'physical_address' => '24 Main Street, Bulawayo', 'department' => 'Software Development', 'position_title' => 'Junior Developer', 'start_date' => '2026-07-15', 'expected_end_date' => '2026-09-15', 'workplace_supervisor_name' => 'Nothando Nyathi', 'workplace_supervisor_email' => 'nothando.nyathi@demobank.example', 'workplace_supervisor_phone' => '+263772222222', 'approval_status' => 'Pending Approval', 'approval_reason' => null, 'created_by' => 'AUATTCH2026'],
    ['student_user_id' => 'AU2026003', 'academic_supervisor_user_id' => 'AULECT2026C', 'organisation' => 'DemoHealth Services', 'organisation_location' => 'Masvingo', 'physical_address' => '18 Chikanga Road, Masvingo', 'department' => 'Agricultural Extension', 'position_title' => 'Field Research Intern', 'start_date' => '2026-05-01', 'expected_end_date' => '2026-07-31', 'workplace_supervisor_name' => 'Kudakwashe Ncube', 'workplace_supervisor_email' => 'kudakwashe.ncube@demohealth.example', 'workplace_supervisor_phone' => '+263773333333', 'approval_status' => 'Approved', 'approval_reason' => 'Placement completed successfully and approved for academic credit.', 'created_by' => 'AUATTCH2026'],
    ['student_user_id' => 'AU2026004', 'academic_supervisor_user_id' => 'AULECT2026A', 'organisation' => 'Digital Systems Ltd', 'organisation_location' => 'Mutare', 'physical_address' => '7 Nelson Mandela Road, Mutare', 'department' => 'Human Resources', 'position_title' => 'Community Engagement Intern', 'start_date' => '2026-08-01', 'expected_end_date' => '2026-10-31', 'workplace_supervisor_name' => 'Lilian Moyo', 'workplace_supervisor_email' => 'lilian.moyo@digitalsystems.example', 'workplace_supervisor_phone' => '+263774444444', 'approval_status' => 'Changes Requested', 'approval_reason' => 'Please update the start date to align with semester dates.', 'created_by' => 'AUATTCH2026'],
    ['student_user_id' => 'AU2026005', 'academic_supervisor_user_id' => 'AULECT2026C', 'organisation' => 'Innovation Labs', 'organisation_location' => 'Gweru', 'physical_address' => '32 Baines Avenue, Gweru', 'department' => 'Research Support', 'position_title' => 'Research Assistant', 'start_date' => '2026-09-01', 'expected_end_date' => '2026-11-30', 'workplace_supervisor_name' => 'Tariro Nyamukapa', 'workplace_supervisor_email' => 'tariro.nyamukapa@innovationlabs.example', 'workplace_supervisor_phone' => '+263775555555', 'approval_status' => 'Approved', 'approval_reason' => 'Placement meets academic requirements.', 'created_by' => 'AUATTCH2026'],
    ['student_user_id' => 'AU2026006', 'academic_supervisor_user_id' => 'AULECT2026B', 'organisation' => 'TechBridge Africa', 'organisation_location' => 'Kadoma', 'physical_address' => '66 Industrial Avenue, Kadoma', 'department' => 'Networking', 'position_title' => 'Network Engineer Intern', 'start_date' => '2026-08-15', 'expected_end_date' => '2026-10-30', 'workplace_supervisor_name' => 'Patson Mlambo', 'workplace_supervisor_email' => 'patson.mlambo@techbridge.example', 'workplace_supervisor_phone' => '+263776666666', 'approval_status' => 'Approved', 'approval_reason' => 'Internship is active and progressing well.', 'created_by' => 'AUATTCH2026'],
];

foreach ($placements as $placement) {
    ensurePlacement($pdo, $placement);
}

$attachmentRows = $pdo->query('SELECT id, student_user_id FROM portal_industrial_attachments')->fetchAll(PDO::FETCH_ASSOC);
foreach ($attachmentRows as $attachmentRow) {
    $weekReports = [
        ['week_number' => 1, 'period_start' => '2026-06-01', 'period_end' => '2026-06-07', 'status' => 'Approved', 'feedback' => 'Strong understanding of the department workflow.'],
        ['week_number' => 2, 'period_start' => '2026-06-08', 'period_end' => '2026-06-14', 'status' => 'Changes Requested', 'feedback' => 'Please add a reflection on problem solving.'],
    ];
    foreach ($weekReports as $reportMeta) {
        ensureReport($pdo, (int)$attachmentRow['id'], $reportMeta['week_number'], [
            'period_start' => $reportMeta['period_start'],
            'period_end' => $reportMeta['period_end'],
            'activities' => 'Performed tasks aligned to the placement plan and documented the learning outcomes.',
            'skills_acquired' => 'Teamwork, documentation, professional communication and troubleshooting.',
            'challenges' => 'Imported work volume and adaptation to the host organisation workflow.',
            'lessons_learnt' => 'The placement has improved technical confidence and communication in a professional setting.',
            'status' => $reportMeta['status'],
            'latest_feedback' => $reportMeta['feedback'],
        ]);
    }
    $pdo->prepare(
        'INSERT IGNORE INTO portal_industrial_attachment_history (attachment_id, actor_user_id, action, from_status, to_status, comment)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([(int)$attachmentRow['id'], 'AUATTCH2026', 'placement_created', null, $attachmentRow['student_user_id'] === 'AU2026003' ? 'Completed' : 'Approved', 'Demo record created for role-based testing.']);
    $pdo->prepare(
        'INSERT IGNORE INTO portal_industrial_attachment_assessments (attachment_id, assessment_type, assessor_user_id, overall_feedback, practical_skills_feedback, score)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([(int)$attachmentRow['id'], 'Academic', $attachmentRow['student_user_id'] === 'AU2026003' ? 'AULECT2026C' : 'AULECT2026A', 'Good demonstration of learning and professional conduct.', 'Applied technical knowledge appropriately and showed good initiative.', 88.5]);
}

$counts = [
    'users' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE role IN (\'student\',\'lecturer\',\'admin\',\'career_advisor\',\'attachment_coordinator\',\'workplace_supervisor\')')->fetchColumn(),
    'students' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'Active'")->fetchColumn(),
    'lecturers' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'lecturer' AND status = 'Active'")->fetchColumn(),
    'achievements' => (int)$pdo->query("SELECT COUNT(*) FROM portal_records WHERE record_type = 'achievement'")->fetchColumn(),
    'recommendations' => (int)$pdo->query("SELECT COUNT(*) FROM portal_records WHERE record_type = 'recommendation'")->fetchColumn(),
    'placements' => (int)$pdo->query('SELECT COUNT(*) FROM portal_industrial_attachments')->fetchColumn(),
    'engagement_items' => (int)$pdo->query("SELECT COUNT(*) FROM portal_engagement_items WHERE item_type = 'opportunity'")->fetchColumn(),
    'notifications' => (int)$pdo->query('SELECT COUNT(*) FROM portal_notifications')->fetchColumn(),
];

echo "Demo seed complete.\n";
foreach ($counts as $label => $count) {
    echo $label . '=' . $count . PHP_EOL;
}
