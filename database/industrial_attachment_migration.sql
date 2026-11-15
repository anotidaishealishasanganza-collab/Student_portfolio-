CREATE TABLE IF NOT EXISTS portal_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipient_user_id VARCHAR(64) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    record_type VARCHAR(32) NULL,
    record_id BIGINT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    dedupe_key VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_recipient_read (recipient_user_id, is_read, created_at),
    UNIQUE KEY uq_portal_notifications_dedupe (dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id VARCHAR(64) NOT NULL,
    action VARCHAR(60) NOT NULL,
    record_type VARCHAR(32) NOT NULL,
    record_id BIGINT UNSIGNED NOT NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_record (record_type, record_id),
    INDEX idx_actor (actor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_industrial_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_user_id VARCHAR(64) NOT NULL,
    academic_supervisor_user_id VARCHAR(64) NULL,
    organisation VARCHAR(180) NOT NULL,
    organisation_location VARCHAR(180) NOT NULL,
    physical_address VARCHAR(500) NOT NULL,
    department VARCHAR(180) NOT NULL,
    position_title VARCHAR(180) NOT NULL,
    start_date DATE NOT NULL,
    expected_end_date DATE NOT NULL,
    workplace_supervisor_name VARCHAR(120) NOT NULL,
    workplace_supervisor_email VARCHAR(254) NOT NULL,
    workplace_supervisor_phone VARCHAR(40) NOT NULL,
    approval_status ENUM('Pending Approval','Approved','Rejected','Changes Requested') NOT NULL DEFAULT 'Pending Approval',
    approval_reason TEXT NULL,
    created_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_industrial_attachment_student (student_user_id),
    INDEX idx_industrial_attachment_supervisor (academic_supervisor_user_id, approval_status),
    INDEX idx_industrial_attachment_dates (start_date, expected_end_date),
    CONSTRAINT fk_industrial_attachment_student FOREIGN KEY (student_user_id)
        REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_industrial_attachment_supervisor FOREIGN KEY (academic_supervisor_user_id)
        REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT fk_industrial_attachment_creator FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_industrial_attachment_dates CHECK (expected_end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_industrial_attachment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attachment_id BIGINT UNSIGNED NOT NULL,
    actor_user_id VARCHAR(64) NOT NULL,
    action VARCHAR(40) NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NULL,
    comment TEXT NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_industrial_attachment_history (attachment_id, created_at),
    CONSTRAINT fk_industrial_attachment_history_record FOREIGN KEY (attachment_id)
        REFERENCES portal_industrial_attachments(id) ON DELETE CASCADE,
    CONSTRAINT fk_industrial_attachment_history_actor FOREIGN KEY (actor_user_id)
        REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_industrial_attachment_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attachment_id BIGINT UNSIGNED NOT NULL,
    week_number SMALLINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    activities TEXT NOT NULL,
    skills_acquired TEXT NOT NULL,
    challenges TEXT NOT NULL,
    lessons_learnt TEXT NOT NULL,
    status ENUM('Pending Review','Approved','Changes Requested') NOT NULL DEFAULT 'Pending Review',
    latest_feedback TEXT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_industrial_report_week (attachment_id, week_number),
    INDEX idx_industrial_reports_attachment (attachment_id, submitted_at),
    INDEX idx_industrial_reports_status (status, submitted_at),
    CONSTRAINT fk_industrial_report_attachment FOREIGN KEY (attachment_id)
        REFERENCES portal_industrial_attachments(id) ON DELETE CASCADE,
    CONSTRAINT chk_industrial_report_dates CHECK (period_end >= period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_industrial_attachment_report_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    actor_user_id VARCHAR(64) NOT NULL,
    action VARCHAR(40) NOT NULL,
    status VARCHAR(40) NOT NULL,
    feedback TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_industrial_report_history (report_id, created_at),
    CONSTRAINT fk_industrial_report_history_report FOREIGN KEY (report_id)
        REFERENCES portal_industrial_attachment_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_industrial_report_history_actor FOREIGN KEY (actor_user_id)
        REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_industrial_attachment_report_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    report_id BIGINT UNSIGNED NOT NULL,
    version SMALLINT UNSIGNED NOT NULL,
    submission_data JSON NOT NULL,
    submitted_by VARCHAR(64) NOT NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_industrial_report_version (report_id, version),
    CONSTRAINT fk_industrial_submission_report FOREIGN KEY (report_id)
        REFERENCES portal_industrial_attachment_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_industrial_submission_user FOREIGN KEY (submitted_by)
        REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_industrial_attachment_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attachment_id BIGINT UNSIGNED NOT NULL,
    report_id BIGINT UNSIGNED NULL,
    document_type ENUM('Acceptance Letter','Weekly Report Supporting Document') NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    storage_key CHAR(64) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    uploader_user_id VARCHAR(64) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_industrial_document_storage (storage_key),
    INDEX idx_industrial_document_attachment (attachment_id, uploaded_at),
    INDEX idx_industrial_document_report (report_id, uploaded_at),
    CONSTRAINT fk_industrial_document_attachment FOREIGN KEY (attachment_id)
        REFERENCES portal_industrial_attachments(id) ON DELETE CASCADE,
    CONSTRAINT fk_industrial_document_report FOREIGN KEY (report_id)
        REFERENCES portal_industrial_attachment_reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_industrial_document_uploader FOREIGN KEY (uploader_user_id)
        REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_industrial_attachment_assessments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attachment_id BIGINT UNSIGNED NOT NULL,
    assessment_type ENUM('Academic','Workplace') NOT NULL,
    assessor_user_id VARCHAR(64) NOT NULL,
    overall_feedback TEXT NOT NULL,
    practical_skills_feedback TEXT NOT NULL,
    score DECIMAL(5,2) NULL,
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_industrial_assessment_type (attachment_id, assessment_type),
    CONSTRAINT fk_industrial_assessment_attachment FOREIGN KEY (attachment_id)
        REFERENCES portal_industrial_attachments(id) ON DELETE CASCADE,
    CONSTRAINT fk_industrial_assessment_assessor FOREIGN KEY (assessor_user_id)
        REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_industrial_assessment_score CHECK (score IS NULL OR (score >= 0 AND score <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_industrial_attachment_attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attachment_id BIGINT UNSIGNED NOT NULL,
    workplace_supervisor_user_id VARCHAR(64) NOT NULL,
    attendance_date DATE NOT NULL,
    attendance_status ENUM('Present','Absent','Leave') NOT NULL,
    supervisor_note VARCHAR(2000) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_industrial_attendance_day (attachment_id, attendance_date),
    INDEX idx_industrial_attendance_user (workplace_supervisor_user_id, attendance_date),
    CONSTRAINT fk_industrial_attendance_attachment FOREIGN KEY (attachment_id)
        REFERENCES portal_industrial_attachments(id) ON DELETE CASCADE,
    CONSTRAINT fk_industrial_attendance_supervisor FOREIGN KEY (workplace_supervisor_user_id)
        REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_industrial_attachment_announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attachment_id BIGINT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    created_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_industrial_announcement_scope (attachment_id, created_at),
    CONSTRAINT fk_industrial_announcement_attachment FOREIGN KEY (attachment_id)
        REFERENCES portal_industrial_attachments(id) ON DELETE CASCADE,
    CONSTRAINT fk_industrial_announcement_creator FOREIGN KEY (created_by)
        REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
