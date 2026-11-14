CREATE TABLE IF NOT EXISTS portal_account_profiles (
    user_id VARCHAR(64) NOT NULL PRIMARY KEY,
    college VARCHAR(180) NOT NULL DEFAULT '',
    faculty VARCHAR(180) NOT NULL,
    department VARCHAR(180) NOT NULL,
    programme VARCHAR(180) NOT NULL,
    study_year VARCHAR(32) NOT NULL,
    student_verification_status ENUM('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
    verification_reason TEXT NULL,
    reviewed_by VARCHAR(64) NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_student_verification_status (student_verification_status),
    CONSTRAINT fk_account_profile_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_account_activation_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_by VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_account_activation_token (token_hash),
    INDEX idx_account_activation_user (user_id, used_at, expires_at),
    CONSTRAINT fk_account_activation_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_super_administrators (
    user_id VARCHAR(64) NOT NULL PRIMARY KEY,
    assigned_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_portal_super_admin_user FOREIGN KEY (user_id)
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
