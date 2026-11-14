-- AUSSH Academic Results module.
-- The legacy users table has no published department/course mapping, so assignments
-- define departmental scope and the authenticated API checks every active role.
CREATE TABLE IF NOT EXISTS portal_result_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    coursework_max DECIMAL(5,2) NULL,
    examination_max DECIMAL(5,2) NULL,
    total_max DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    grade_scale JSON NULL,
    updated_by VARCHAR(64) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_result_settings_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO portal_result_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS portal_result_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    lecturer_user_id VARCHAR(64) NOT NULL,
    hod_user_id VARCHAR(64) NOT NULL,
    student_user_id VARCHAR(64) NOT NULL,
    student_university_id VARCHAR(64) NOT NULL,
    department_code VARCHAR(64) NOT NULL,
    course_code VARCHAR(40) NOT NULL,
    course_name VARCHAR(180) NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    semester VARCHAR(32) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_result_assignment (
        lecturer_user_id, hod_user_id, student_user_id, course_code, academic_year, semester
    ),
    KEY idx_result_assignment_lecturer (lecturer_user_id, is_active, academic_year, semester),
    KEY idx_result_assignment_hod (hod_user_id, is_active, academic_year, semester),
    KEY idx_result_assignment_student (student_user_id, course_code, academic_year, semester),
    KEY idx_result_assignment_creator (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_result_publication_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(9) NOT NULL,
    semester VARCHAR(32) NOT NULL,
    publisher_user_id VARCHAR(64) NOT NULL,
    published_at DATETIME NOT NULL,
    result_count INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_result_batch_term (academic_year, semester, published_at),
    KEY idx_result_batch_publisher (publisher_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_academic_results (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    assignment_id BIGINT UNSIGNED NOT NULL,
    student_user_id VARCHAR(64) NOT NULL,
    student_university_id VARCHAR(64) NOT NULL,
    lecturer_user_id VARCHAR(64) NOT NULL,
    hod_reviewer_id VARCHAR(64) NULL,
    course_code VARCHAR(40) NOT NULL,
    course_name VARCHAR(180) NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    semester VARCHAR(32) NOT NULL,
    coursework_mark DECIMAL(5,2) NOT NULL,
    examination_mark DECIMAL(5,2) NOT NULL,
    total_mark DECIMAL(5,2) NOT NULL,
    grade VARCHAR(12) NULL,
    workflow_status VARCHAR(24) NOT NULL DEFAULT 'Draft',
    submitted_at DATETIME NULL,
    hod_reviewed_at DATETIME NULL,
    publication_date DATETIME NULL,
    registry_publisher_id VARCHAR(64) NULL,
    publication_batch_id BIGINT UNSIGNED NULL,
    returned_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_academic_result (student_user_id, course_code, academic_year, semester),
    KEY idx_result_lecturer_status (lecturer_user_id, workflow_status, updated_at),
    KEY idx_result_hod_status (hod_reviewer_id, workflow_status, academic_year, semester),
    KEY idx_result_status_term (workflow_status, academic_year, semester),
    KEY idx_result_assignment (assignment_id),
    KEY idx_result_registry_publisher (registry_publisher_id),
    KEY idx_result_publication_batch (publication_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_result_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    result_id BIGINT UNSIGNED NOT NULL,
    actor_user_id VARCHAR(64) NOT NULL,
    action VARCHAR(32) NOT NULL,
    previous_status VARCHAR(24) NULL,
    new_status VARCHAR(24) NULL,
    reason TEXT NULL,
    before_values JSON NULL,
    after_values JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_result_review_history (result_id, created_at),
    KEY idx_result_review_actor (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_result_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    import_token CHAR(64) NOT NULL,
    lecturer_user_id VARCHAR(64) NOT NULL,
    assignment_id BIGINT UNSIGNED NOT NULL,
    course_code VARCHAR(40) NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    semester VARCHAR(32) NOT NULL,
    source_filename VARCHAR(255) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    import_status VARCHAR(24) NOT NULL DEFAULT 'Previewed',
    total_rows INT UNSIGNED NOT NULL,
    valid_rows INT UNSIGNED NOT NULL,
    invalid_rows INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_result_import_token (import_token),
    KEY idx_result_import_lecturer (lecturer_user_id, created_at),
    KEY idx_result_import_assignment (assignment_id, import_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_result_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    import_id BIGINT UNSIGNED NOT NULL,
    csv_row_number INT UNSIGNED NOT NULL,
    row_type VARCHAR(16) NOT NULL DEFAULT 'data',
    assignment_id BIGINT UNSIGNED NULL,
    student_user_id VARCHAR(64) NULL,
    student_university_id VARCHAR(64) NULL,
    course_code VARCHAR(40) NULL,
    coursework_mark DECIMAL(5,2) NULL,
    examination_mark DECIMAL(5,2) NULL,
    total_mark DECIMAL(5,2) NULL,
    validation_errors JSON NULL,
    row_status VARCHAR(16) NOT NULL,
    result_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_result_import_row (import_id, csv_row_number),
    KEY idx_result_import_rows_status (import_id, row_status),
    KEY idx_result_import_rows_result (result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
