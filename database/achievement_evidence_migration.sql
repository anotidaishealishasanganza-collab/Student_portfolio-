CREATE TABLE IF NOT EXISTS portal_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_type VARCHAR(32) NOT NULL,
    owner_user_id VARCHAR(64) NOT NULL,
    owner_name VARCHAR(120) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'Pending',
    assigned_role VARCHAR(32) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type_role_status (record_type, assigned_role, status),
    INDEX idx_owner (owner_user_id, record_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_achievement_evidence (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_id BIGINT UNSIGNED NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    storage_key CHAR(64) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    uploader_user_id VARCHAR(64) NOT NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_achievement_evidence_storage (storage_key),
    INDEX idx_achievement_evidence_record (record_id, uploaded_at, id),
    CONSTRAINT fk_achievement_evidence_record
        FOREIGN KEY (record_id) REFERENCES portal_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
